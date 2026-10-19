<?php

declare(strict_types=1);

namespace OpenSendForm\Mail;

use OpenSendForm\Clock\Clock;
use OpenSendForm\Config;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\Submission\SubmissionRepository;

/**
 * Drives one delivery attempt per call and, separately, the operator's
 * retry sweep.
 *
 * Synchronous-first: the submit pipeline makes exactly one in-request
 * attempt. Whatever happens, the submission is already stored — a failure
 * only schedules a retry and never affects the submitter's response. The
 * retry machinery is nothing more than re-running attemptDelivery() for
 * rows whose next_attempt_at has come due (see retryDue()); there is no
 * queue beyond the submissions table.
 */
final class DeliveryService
{
    public const RESULT_SENT = 'sent';
    public const RESULT_FAILED = 'failed';
    public const RESULT_DEAD = 'dead';
    public const RESULT_SKIPPED = 'skipped';

    /** Cap for the stored last_error string. */
    private const ERROR_CAP = 1000;

    private SubmissionRepository $submissions;
    private FormRepository $forms;
    private MessageBuilder $builder;
    private MailerInterface $mailer;
    private Config $config;
    private Clock $clock;

    public function __construct(
        SubmissionRepository $submissions,
        FormRepository $forms,
        MessageBuilder $builder,
        MailerInterface $mailer,
        Config $config,
        Clock $clock
    ) {
        $this->submissions = $submissions;
        $this->forms = $forms;
        $this->builder = $builder;
        $this->mailer = $mailer;
        $this->config = $config;
        $this->clock = $clock;
    }

    /**
     * Attempt to deliver a single stored submission.
     *
     * On success: status 'sent', attempts incremented, last_attempt_at set;
     * the stored content is then cleared unless the form's store_content is
     * on (the toggle means "retain content after successful delivery").
     * On failure: attempts incremented; if that reaches MAIL_MAX_ATTEMPTS the
     * submission is marked 'dead', otherwise 'failed' with next_attempt_at
     * scheduled per the backoff list. Content is left untouched on both
     * failure paths so a retry — or operator recovery — still has it.
     *
     * @return string One of the RESULT_* constants.
     */
    public function attemptDelivery(int $submissionId): string
    {
        $submission = $this->submissions->findById($submissionId);
        if ($submission === null) {
            return self::RESULT_SKIPPED;
        }

        // A never-attempted 'received' submission is only swept for delivery
        // once mail is actually enabled. With mail off we skip it untouched —
        // it stays 'received' with attempts = 0 and no backoff, exactly as the
        // in-request DeliveryStage left it, so a disabled sweep never burns an
        // attempt or schedules a retry it cannot make.
        if ((string) $submission['status'] === 'received' && !$this->canDeliver()) {
            return self::RESULT_SKIPPED;
        }

        $form = $this->forms->findById((int) $submission['form_id']);
        if ($form === null) {
            // No recipient to deliver to; nothing sensible to retry.
            return self::RESULT_SKIPPED;
        }

        $fields = $this->decodeFields($submission['content'] ?? null);
        $message = $this->builder->build($form, $fields);

        $nowUnix = $this->clock->now();
        $attemptedAt = gmdate('Y-m-d H:i:s', $nowUnix);
        $attempts = (int) $submission['attempts'] + 1;

        // The dark canary: a synthetic monitor probe genuinely runs the SMTP
        // transaction (that is the proof the pipeline works), but it is
        // addressed to the service's own From/send address, never the form's
        // real recipient - so the accepted message lands in the service mailbox
        // and reaches no human. Real submissions always go to the form owner.
        $recipient = (int) ($submission['is_synthetic'] ?? 0) === 1
            ? $this->config->mailFromAddress()
            : (string) $form['recipient_email'];

        try {
            $this->mailer->send(
                $recipient,
                $message->replyTo(),
                $message->subject(),
                $message->textBody()
            );
        } catch (\Throwable $e) {
            return $this->recordFailure($submissionId, $attempts, $e, $nowUnix, $attemptedAt);
        }

        $this->submissions->markSent($submissionId, $attempts, $attemptedAt);
        if ((int) $form['store_content'] !== 1) {
            $this->submissions->clearContent($submissionId);
        }

        return self::RESULT_SENT;
    }

    /**
     * Re-attempt every submission the sweep finds due: 'failed' rows whose
     * backoff has elapsed AND never-attempted 'received' rows (see
     * SubmissionRepository::findDueForRetry). With mail disabled the received
     * rows are skipped untouched; enabling mail later delivers them.
     *
     * @param string|null $now A 'Y-m-d H:i:s' UTC cutoff; defaults to the
     *                         clock's current time.
     * @return array<string,int> Summary counts keyed by outcome plus 'attempted'.
     */
    public function retryDue(?string $now = null): array
    {
        $now ??= gmdate('Y-m-d H:i:s', $this->clock->now());

        $summary = [
            'attempted'         => 0,
            self::RESULT_SENT   => 0,
            self::RESULT_FAILED => 0,
            self::RESULT_DEAD   => 0,
            self::RESULT_SKIPPED => 0,
        ];

        foreach ($this->submissions->findDueForRetry($now) as $row) {
            $result = $this->attemptDelivery((int) $row['id']);
            $summary['attempted']++;
            $summary[$result] = ($summary[$result] ?? 0) + 1;
        }

        return $summary;
    }

    /**
     * Whether mail can actually be sent right now. Mirrors DeliveryStage's
     * gate: MAIL_ENABLED is the primary switch, a non-empty SMTP_HOST the
     * secondary guard. Used to decide whether a never-attempted 'received'
     * submission should be swept or left for later.
     */
    private function canDeliver(): bool
    {
        return $this->config->mailEnabled() && trim($this->config->smtpHost()) !== '';
    }

    /**
     * Record a failed attempt, escalating to 'dead' at the attempt ceiling.
     */
    private function recordFailure(
        int $submissionId,
        int $attempts,
        \Throwable $error,
        int $nowUnix,
        string $attemptedAt
    ): string {
        $reason = $this->truncateError($error->getMessage());

        if ($attempts >= $this->config->mailMaxAttempts()) {
            $this->submissions->markDead($submissionId, $attempts, $reason, $attemptedAt);

            return self::RESULT_DEAD;
        }

        $waitMinutes = $this->backoffMinutes($attempts);
        $nextAttemptAt = gmdate('Y-m-d H:i:s', $nowUnix + ($waitMinutes * 60));
        $this->submissions->markFailed($submissionId, $attempts, $reason, $attemptedAt, $nextAttemptAt);

        return self::RESULT_FAILED;
    }

    /**
     * Backoff wait (minutes) after failure number $attempts. The list is
     * 1-indexed by attempt; the last value repeats once attempts run past
     * the list length.
     */
    private function backoffMinutes(int $attempts): int
    {
        $schedule = $this->config->mailRetryBackoffMinutes();
        $index = $attempts - 1;

        if ($index < count($schedule)) {
            return $schedule[$index];
        }

        return $schedule[count($schedule) - 1];
    }

    /**
     * Decode the stored content JSON to a fields map. Missing/invalid content
     * (e.g. a form with store_content off) yields an empty map.
     *
     * @return array<string,mixed>
     */
    private function decodeFields(mixed $content): array
    {
        if (!is_string($content) || $content === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function truncateError(string $message): string
    {
        $message = trim($message);
        if (mb_strlen($message) <= self::ERROR_CAP) {
            return $message;
        }

        return mb_substr($message, 0, self::ERROR_CAP) . '…';
    }
}
