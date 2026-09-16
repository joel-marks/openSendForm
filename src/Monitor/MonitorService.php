<?php

declare(strict_types=1);

namespace OpenSendForm\Monitor;

use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Clock\Clock;
use OpenSendForm\Config;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\Install\ConfigWriter;
use OpenSendForm\Mail\MailerInterface;
use OpenSendForm\Submission\SubmissionRepository;
use Throwable;

/**
 * Synthetic monitoring: scheduled fake-but-real submissions that exercise the
 * FULL public pipeline end to end, plus alerting.
 *
 * Each check is a REAL submission through the public HTTP endpoints — fetch the
 * form token, respect the min-submit-time bot check, POST marked content — so
 * it passes every stage a real user passes. The stored row is flagged synthetic
 * by the pipeline (reserved _osf_monitor field + MONITOR_SECRET); the monitor
 * then polls that row until it reaches 'sent'. A check FAILS if any step errors
 * or the submission is not delivered within the send timeout.
 *
 * State lives entirely in monitor_checks: which forms are due, whether a form
 * is currently failing (for ok<->fail alert transitions and the dashboard
 * banner) and when it was last seen. The run also auto-purges expired synthetic
 * submissions.
 */
final class MonitorService
{
    /** The body/subject marker so recipients can recognise and filter probes. */
    public const MARKER = '[OpenSendForm monitor]';

    public function __construct(
        private HttpClient $http,
        private FormRepository $forms,
        private SubmissionRepository $submissions,
        private MonitorRepository $checks,
        private MailerInterface $mailer,
        private AdminRepository $admins,
        private Config $config,
        private ConfigWriter $configWriter,
        private Clock $clock,
        private Sleeper $sleeper
    ) {
    }

    /**
     * Run one monitoring pass: ensure the secret, purge expired synthetics,
     * check the due forms (canary always + staggered others), record each
     * result and send one alert on any ok<->fail transition.
     *
     * @return array{
     *   secret_generated: bool,
     *   purged: int,
     *   checks_ran: int,
     *   failures: int,
     *   alerts_sent: int,
     *   alert_error: ?string,
     *   results: array<int, array{form_id:int, form_name:string, ok:bool, detail:string}>
     * }
     */
    public function run(): array
    {
        $secretGenerated = $this->effectiveSecret($secret);

        $cutoff = gmdate('Y-m-d H:i:s', $this->clock->now() - ($this->config->monitorRetentionDays() * 86400));
        $purged = $this->submissions->purgeSyntheticOlderThan($cutoff);

        $active = $this->activeForms();
        $canaryId = $this->resolveCanaryId($active);
        $formIds = MonitorSchedule::due(
            $active,
            $canaryId,
            $this->checks->lastCheckedAtByForm(),
            $this->config->monitorFormIntervalHours(),
            $this->clock->now()
        );

        $results = [];
        $failures = 0;
        $alertsSent = 0;
        $alertError = null;

        foreach ($formIds as $formId) {
            $form = $this->forms->findById($formId);
            if ($form === null || (int) $form['is_active'] !== 1) {
                continue; // deactivated/deleted between planning and checking
            }

            $previous = $this->checks->lastResultForForm($formId);
            $check = $this->performCheck($form, $secret);
            $checkedAt = gmdate('Y-m-d H:i:s', $this->clock->now());
            $this->checks->record($formId, $checkedAt, $check['ok'], $check['detail']);

            if (!$check['ok']) {
                $failures++;
            }

            $alert = $this->maybeAlert($form, $previous, $check, $checkedAt);
            if ($alert === true) {
                $alertsSent++;
            } elseif (is_string($alert) && $alertError === null) {
                $alertError = $alert; // first alert-send failure wins; cron is the backstop
            }

            $results[] = [
                'form_id'   => $formId,
                'form_name' => (string) $form['name'],
                'ok'        => $check['ok'],
                'detail'    => $check['detail'],
            ];
        }

        return [
            'secret_generated' => $secretGenerated,
            'purged'           => $purged,
            'checks_ran'       => count($results),
            'failures'         => $failures,
            'alerts_sent'      => $alertsSent,
            'alert_error'      => $alertError,
            'results'          => $results,
        ];
    }

    /**
     * Per-form status for `monitor:status`: last check, result and next due.
     *
     * @return array<int, array{
     *   form_id:int, form_name:string, is_canary:bool,
     *   last_checked_at:?string, last_ok:?bool, next_due:string
     * }>
     */
    public function status(): array
    {
        $active = $this->activeForms();
        $canaryId = $this->resolveCanaryId($active);
        $interval = $this->config->monitorFormIntervalHours();
        $now = $this->clock->now();

        $rows = [];
        foreach ($active as $form) {
            $id = (int) $form['id'];
            $latest = $this->checks->latestForForm($id);
            $lastCheckedAt = $latest === null ? null : (string) $latest['checked_at'];
            $lastOk = $latest === null ? null : ((int) $latest['ok'] === 1);
            $isCanary = ($id === $canaryId);

            $rows[] = [
                'form_id'         => $id,
                'form_name'       => (string) $form['name'],
                'is_canary'       => $isCanary,
                'last_checked_at' => $lastCheckedAt,
                'last_ok'         => $lastOk,
                'next_due'        => $this->nextDue($isCanary, $lastCheckedAt, $interval, $now),
            ];
        }

        return $rows;
    }

    // --- Secret ------------------------------------------------------------

    /**
     * Resolve the effective MONITOR_SECRET, generating and writing one back on
     * first run when it is still empty. The value is returned via $secret.
     *
     * @return bool True when a fresh secret was generated (and written).
     */
    private function effectiveSecret(?string &$secret): bool
    {
        $secret = $this->config->monitorSecret();
        if ($secret !== '') {
            return false;
        }

        $secret = Config::generateSecret();
        $this->configWriter->save(['MONITOR_SECRET' => $secret]);

        return true;
    }

    // --- One check ---------------------------------------------------------

    /**
     * Perform one synthetic check for a form: token -> min-time -> POST ->
     * poll for 'sent'. Never throws — every failure mode is caught and
     * converted to a failed result with a human-readable detail.
     *
     * @param array<string, mixed> $form
     * @return array{ok:bool, detail:string}
     */
    private function performCheck(array $form, string $secret): array
    {
        $formKey = (string) $form['form_key'];
        $origins = is_array($form['allowed_origins'] ?? null) ? $form['allowed_origins'] : [];
        $origin = $origins[0] ?? null;
        if (!is_string($origin) || $origin === '') {
            return $this->fail('form has no allowed origin to submit from');
        }

        $base = $this->config->monitorBaseUrl();

        // 1. Fetch a token.
        try {
            $tokenResponse = $this->http->get($base . '/v1/form/' . rawurlencode($formKey) . '/token', [
                'Origin' => $origin,
                'Accept' => 'application/json',
            ]);
        } catch (HttpTransportException $e) {
            return $this->fail('token fetch failed: ' . $e->getMessage());
        }

        if (!$tokenResponse->isOk()) {
            return $this->fail('token endpoint returned HTTP ' . $tokenResponse->status);
        }

        $tokenJson = $tokenResponse->json();
        $token = is_array($tokenJson) && isset($tokenJson['token']) ? (string) $tokenJson['token'] : '';
        if ($token === '') {
            return $this->fail('token missing from token-endpoint response');
        }

        // 2. Respect the min-submit-time bot check (token must age past it).
        $this->sleeper->sleep($this->config->minSubmitSeconds() + 1);

        // 3. POST the marked submission.
        $baselineId = $this->submissions->maxSyntheticId((int) $form['id']);
        $fields = [
            \OpenSendForm\Submit\SubmitContext::FIELD_TOKEN   => $token,
            \OpenSendForm\Submit\SubmitContext::FIELD_MONITOR => $secret,
            'email'   => (string) $form['recipient_email'],
            'message' => self::MARKER . ' Synthetic end-to-end delivery check at '
                . gmdate('Y-m-d H:i:s', $this->clock->now()) . ' UTC. Automated; safe to ignore.',
        ];

        try {
            $submitResponse = $this->http->post(
                $base . '/v1/form/' . rawurlencode($formKey) . '/submit',
                http_build_query($fields),
                [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Origin'       => $origin,
                    'Accept'       => 'application/json',
                ]
            );
        } catch (HttpTransportException $e) {
            return $this->fail('submit POST failed: ' . $e->getMessage());
        }

        $submitJson = $submitResponse->json();
        $accepted = $submitResponse->isOk()
            && is_array($submitJson)
            && ($submitJson['ok'] ?? null) === true;
        if (!$accepted) {
            return $this->fail('submit returned ' . $this->describeResponse($submitResponse, $submitJson));
        }

        // 4. Find the exact row this POST created and poll it to 'sent'.
        $row = $this->submissions->newestSyntheticForForm((int) $form['id'], $baselineId);
        if ($row === null) {
            return $this->fail('submission was accepted but not stored as synthetic '
                . '(marker/secret not recognised, or silently discarded)');
        }

        return $this->awaitDelivery((int) $row['id'], (string) $row['status']);
    }

    /**
     * Poll a stored submission until it reaches 'sent' or the send timeout
     * elapses. Delivery is synchronous-first, so the row is usually already
     * 'sent' on the first read; the poll covers the async/failed cases.
     *
     * @return array{ok:bool, detail:string}
     */
    private function awaitDelivery(int $submissionId, string $status): array
    {
        if ($status === 'sent') {
            return $this->ok('delivered (submission #' . $submissionId . ')');
        }

        $deadline = $this->clock->now() + $this->config->monitorSendTimeoutSeconds();
        while ($this->clock->now() < $deadline) {
            $this->sleeper->sleep(1);
            $row = $this->submissions->findById($submissionId);
            $status = $row === null ? 'missing' : (string) $row['status'];
            if ($status === 'sent') {
                return $this->ok('delivered (submission #' . $submissionId . ')');
            }
        }

        return $this->fail(sprintf(
            "submission #%d did not reach 'sent' within %ds (last status: %s)",
            $submissionId,
            $this->config->monitorSendTimeoutSeconds(),
            $status
        ));
    }

    // --- Alerting ----------------------------------------------------------

    /**
     * Send at most one alert on an ok<->fail transition. Returns true when an
     * alert (or recovery) email was sent, a string with the error when a
     * needed email could not be sent, or null when no transition occurred.
     *
     * A form with no prior state is treated as previously ok: a first-ever
     * failing check alerts (so the operator hears about it), a first-ever
     * passing check is silent.
     *
     * @param array<string, mixed> $form
     * @param array{ok:bool, detail:string} $check
     * @return bool|string|null True when an email was sent, a string error when
     *                          a needed email could not be sent, null otherwise.
     */
    private function maybeAlert(array $form, ?bool $previous, array $check, string $checkedAt): bool|string|null
    {
        $wasOk = $previous ?? true;

        if ($wasOk && !$check['ok']) {
            return $this->trySend($form, $checkedAt, $check['detail'], true);
        }

        if ($previous === false && $check['ok']) {
            return $this->trySend($form, $checkedAt, $check['detail'], false);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $form
     * @return bool|string True when the email was sent, else the error string.
     */
    private function trySend(array $form, string $checkedAt, string $detail, bool $failing): bool|string
    {
        try {
            $this->sendAlert($form, $checkedAt, $detail, $failing);

            return true;
        } catch (Throwable $e) {
            return 'could not send ' . ($failing ? 'alert' : 'recovery')
                . ' email for form "' . (string) $form['name'] . '": ' . $e->getMessage();
        }
    }

    /**
     * @param array<string, mixed> $form
     *
     * @throws \RuntimeException when there is no recipient, or the mailer throws.
     */
    private function sendAlert(array $form, string $checkedAt, string $detail, bool $failing): void
    {
        $to = $this->alertRecipient();
        if ($to === '') {
            throw new \RuntimeException('no MONITOR_ALERT_EMAIL configured and no admin to fall back to');
        }

        $name = $this->collapse((string) $form['name']);
        $state = $failing ? 'FAILURE' : 'RECOVERED';
        $subject = self::MARKER . ' ' . $state . ': ' . $name;

        if ($failing) {
            $body = "Synthetic monitoring detected a problem with a form.\n\n"
                . 'Form:   ' . $name . ' (#' . (int) $form['id'] . ")\n"
                . 'Time:   ' . $checkedAt . " UTC\n"
                . 'Detail: ' . $detail . "\n\n"
                . "Visitors may be unable to submit this form, or their submissions may not be\n"
                . "reaching you. This is an automated alert from OpenSendForm synthetic monitoring.";
        } else {
            $body = "A form that was previously failing is working again.\n\n"
                . 'Form:   ' . $name . ' (#' . (int) $form['id'] . ")\n"
                . 'Time:   ' . $checkedAt . " UTC\n"
                . 'Detail: ' . $detail . "\n\n"
                . "This is an automated recovery notice from OpenSendForm synthetic monitoring.";
        }

        $this->mailer->send($to, null, $subject, $body);
    }

    /**
     * The alert recipient: the configured MONITOR_ALERT_EMAIL, else the first
     * admin's email, else '' (no recipient — sendAlert then throws).
     */
    private function alertRecipient(): string
    {
        $configured = $this->config->monitorAlertEmail();
        if ($configured !== '') {
            return $configured;
        }

        $admins = $this->admins->listAll();

        return $admins === [] ? '' : (string) $admins[0]['email'];
    }

    // --- Helpers -----------------------------------------------------------

    /**
     * Active forms, ascending by id (so "lowest-id active" is the first).
     *
     * @return array<int, array<string, mixed>>
     */
    private function activeForms(): array
    {
        $active = array_values(array_filter(
            $this->forms->listForms(),
            static fn (array $f): bool => (int) $f['is_active'] === 1
        ));

        usort($active, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return $active;
    }

    /**
     * The canary form id: the configured one when it is active, else the
     * lowest-id active form, else null (no active forms).
     *
     * @param array<int, array<string, mixed>> $active Ascending by id.
     */
    private function resolveCanaryId(array $active): ?int
    {
        $configured = $this->config->monitorCanaryFormId();
        if ($configured !== null) {
            foreach ($active as $form) {
                if ((int) $form['id'] === $configured) {
                    return $configured;
                }
            }
        }

        return $active === [] ? null : (int) $active[0]['id'];
    }

    /**
     * A human "next due" description for the status command.
     */
    private function nextDue(bool $isCanary, ?string $lastCheckedAt, int $intervalHours, int $now): string
    {
        if ($isCanary) {
            return 'every run';
        }
        if ($lastCheckedAt === null) {
            return 'due now (first check)';
        }

        $checkedUnix = strtotime($lastCheckedAt . ' UTC');
        if ($checkedUnix === false) {
            return 'due now';
        }

        $dueUnix = $checkedUnix + ($intervalHours * 3600);

        return $dueUnix <= $now ? 'due now' : gmdate('Y-m-d H:i:s', $dueUnix) . ' UTC';
    }

    /**
     * Describe a non-accepted submit response for the failure detail.
     *
     * @param array<string, mixed>|null $json
     */
    private function describeResponse(HttpResponse $response, ?array $json): string
    {
        $summary = 'HTTP ' . $response->status;
        if (is_array($json) && isset($json['error']) && is_array($json['error'])) {
            $code = (string) ($json['error']['code'] ?? '');
            if ($code !== '') {
                $summary .= ' (' . $code . ')';
            }
        }

        return $summary;
    }

    /**
     * Collapse control characters (newlines included) out of an operator-set
     * string before it reaches an email header. Defence in depth beside the
     * mailer's own protections.
     */
    private function collapse(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * @return array{ok:bool, detail:string}
     */
    private function ok(string $detail): array
    {
        return ['ok' => true, 'detail' => $detail];
    }

    /**
     * @return array{ok:bool, detail:string}
     */
    private function fail(string $detail): array
    {
        return ['ok' => false, 'detail' => $detail];
    }
}
