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
 * Zero-noise synthetic monitoring. Core doctrine: a HEALTHY system sends ZERO
 * email to anyone, ever. The only monitor-origin mail a human receives is an
 * alert (or its recovery notice) to MONITOR_ALERT_EMAIL - and that always means
 * "act now".
 *
 * A run has two parts:
 *
 *  1. THE DARK CANARY. One active form is exercised end to end against the
 *     REAL public HTTP endpoints (fetch token -> respect min-submit-time ->
 *     POST a marked submission -> store -> SMTP delivery). The SMTP transaction
 *     genuinely happens - that is the proof the pipeline works - but the probe
 *     is DARK: DeliveryService addresses a synthetic submission to the
 *     service's own From/send address, so the accepted message lands in the
 *     service mailbox and reaches no human. The stored row is flagged synthetic
 *     and auto-purged after a short retention. The canary records ok/fail in
 *     monitor_checks and alerts on each ok<->fail transition.
 *
 *  2. PASSIVE PER-FORM OBSERVATION. Real traffic already drives the delivery
 *     state machine (received -> failed -> dead, or -> sent). For each active
 *     form the run reads its last REAL delivery outcome and, on a transition of
 *     that outcome, records it (monitor_checks, kind 'form') and sends ONE
 *     alert: on the first entry into 'failed' since the last healthy delivery,
 *     one on 'dead', and a recovery notice when a later delivery succeeds. No
 *     synthetic traffic is generated for this - it is pure observation.
 *
 * All state lives in monitor_checks; nothing is kept in memory between runs.
 */
final class MonitorService
{
    /** The body/subject marker so the service mailbox can recognise probes. */
    public const MARKER = '[openSendForm monitor]';

    /**
     * Seconds added to the configured min-submit-time before a probe POSTs, so
     * the token ages just past the pipeline's bot check and no further. Small
     * and derived from config - the probe never sleeps a fixed worst case.
     */
    private const MIN_SUBMIT_MARGIN_SECONDS = 1;

    /**
     * How many times a probe re-reads a not-yet-'sent' row after the immediate
     * check. Delivery is synchronous in-request, so the immediate read almost
     * always sees 'sent'; these are a short grace window. The poll interval is
     * derived from the send timeout divided by this, so it tightens as the
     * timeout shrinks.
     */
    private const DELIVERY_POLL_ATTEMPTS = 5;

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
     * run the dark canary (and alert on its ok<->fail transition), then observe
     * every active form's real delivery outcome passively (and alert on its
     * transitions). Returns a summary; sends email ONLY on a transition.
     *
     * @return array{
     *   secret_generated: bool,
     *   purged: int,
     *   canary_ran: bool,
     *   canary_ok: ?bool,
     *   canary_detail: ?string,
     *   canary_form: ?string,
     *   passive_transitions: int,
     *   alerts_sent: int,
     *   alert_error: ?string,
     *   elapsed_seconds: int,
     *   results: array<int, array{scope:string, form_id:int, form_name:string, ok:bool, detail:string}>
     * }
     */
    public function run(): array
    {
        $startedAt = $this->clock->now();
        $secretGenerated = $this->effectiveSecret($secret);

        $cutoff = gmdate('Y-m-d H:i:s', $this->clock->now() - ($this->config->monitorRetentionDays() * 86400));
        $purged = $this->submissions->purgeSyntheticOlderThan($cutoff);

        $active = $this->activeForms();
        $canaryId = $this->resolveCanaryId($active);

        $results = [];
        $alertsSent = 0;
        $alertError = null;

        // --- 1. The dark canary ------------------------------------------
        $canaryRan = false;
        $canaryOk = null;
        $canaryDetail = null;
        $canaryForm = null;

        $canaryFormRow = $canaryId === null ? null : $this->forms->findById($canaryId);
        if ($canaryFormRow !== null && (int) $canaryFormRow['is_active'] === 1) {
            $canaryRan = true;
            $canaryForm = (string) $canaryFormRow['name'];

            $previous = $this->checks->lastResultForForm($canaryId, MonitorRepository::KIND_CANARY);
            $check = $this->performCheck($canaryFormRow, $secret);
            $canaryOk = $check['ok'];
            $canaryDetail = $check['detail'];

            $checkedAt = gmdate('Y-m-d H:i:s', $this->clock->now());
            $this->checks->record($canaryId, $checkedAt, $check['ok'], $check['detail'], MonitorRepository::KIND_CANARY);

            $event = $this->canaryTransition($previous, $check['ok']);
            if ($event !== null) {
                $this->tallyAlert($this->trySend($canaryFormRow, $checkedAt, $check['detail'], $event), $alertsSent, $alertError);
            }

            $results[] = $this->resultRow('canary', $canaryId, $canaryForm, $check['ok'], $check['detail']);
        }

        // --- 2. Passive per-form observation -----------------------------
        $passiveTransitions = 0;
        foreach ($active as $form) {
            $formId = (int) $form['id'];
            $outcome = $this->submissions->latestRealDeliveryOutcomeForForm($formId);
            if ($outcome === null) {
                continue; // no real delivery activity yet - nothing to observe
            }

            $current = self::stateFromStatus((string) $outcome['status']);
            $previous = $this->checks->lastPassiveState($formId);
            if ($current === $previous) {
                continue; // no transition since the last run
            }

            $passiveTransitions++;
            $checkedAt = gmdate('Y-m-d H:i:s', $this->clock->now());
            $detail = $this->passiveDetail($current, $outcome);
            // detail column stores the state WORD so the next run reads it back.
            $this->checks->record($formId, $checkedAt, $current === 'ok', $current, MonitorRepository::KIND_FORM);

            $event = match ($current) {
                'failed' => 'failing',
                'dead'   => 'dead',
                default  => 'recovered',
            };
            $this->tallyAlert($this->trySend($form, $checkedAt, $detail, $event), $alertsSent, $alertError);

            $results[] = $this->resultRow('form', $formId, (string) $form['name'], $current === 'ok', $event . ': ' . $detail);
        }

        return [
            'secret_generated'    => $secretGenerated,
            'purged'              => $purged,
            'canary_ran'          => $canaryRan,
            'canary_ok'           => $canaryOk,
            'canary_detail'       => $canaryDetail,
            'canary_form'         => $canaryForm,
            'passive_transitions' => $passiveTransitions,
            'alerts_sent'         => $alertsSent,
            'alert_error'         => $alertError,
            'elapsed_seconds'     => $this->clock->now() - $startedAt,
            'results'             => $results,
        ];
    }

    /**
     * Read-only status for `monitor:status` and the Status tab: the canary's
     * last run/result and each active form's last REAL delivery outcome. There
     * is no "next due" - checks are not scheduled per form any more.
     *
     * @return array{
     *   canary: ?array{form_id:int, form_name:string, last_run_at:?string, last_ok:?bool, detail:?string},
     *   forms: array<int, array{form_id:int, form_name:string, is_canary:bool, outcome:?string, at:?string}>
     * }
     */
    public function status(): array
    {
        $active = $this->activeForms();
        $canaryId = $this->resolveCanaryId($active);

        $canary = null;
        if ($canaryId !== null) {
            $row = $this->checks->latestForForm($canaryId, MonitorRepository::KIND_CANARY);
            $canary = [
                'form_id'     => $canaryId,
                'form_name'   => $this->formName($active, $canaryId),
                'last_run_at' => $row === null ? null : (string) $row['checked_at'],
                'last_ok'     => $row === null ? null : ((int) $row['ok'] === 1),
                'detail'      => $row === null ? null : ($row['detail'] === null ? null : (string) $row['detail']),
            ];
        }

        $forms = [];
        foreach ($active as $form) {
            $id = (int) $form['id'];
            $outcome = $this->submissions->latestRealDeliveryOutcomeForForm($id);
            $forms[] = [
                'form_id'   => $id,
                'form_name' => (string) $form['name'],
                'is_canary' => $id === $canaryId,
                'outcome'   => $outcome === null ? null : (string) $outcome['status'],
                'at'        => $outcome === null ? null : self::outcomeWhen($outcome),
            ];
        }

        return ['canary' => $canary, 'forms' => $forms];
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

    // --- The canary check --------------------------------------------------

    /**
     * Perform one synthetic check for a form: token -> min-time -> POST ->
     * poll for 'sent'. Never throws - every failure mode is caught and
     * converted to a failed result with a human-readable detail. The delivery
     * it triggers is dark (DeliveryService routes synthetic submissions to the
     * service's own address), so this never emails the form's real recipient.
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
        // The wait is derived from the configured min-submit-time plus a small
        // margin, so the probe sleeps no longer than the pipeline requires.
        $this->sleeper->sleep($this->config->minSubmitSeconds() + self::MIN_SUBMIT_MARGIN_SECONDS);

        // 3. POST the marked submission.
        $baselineId = $this->submissions->maxSyntheticId((int) $form['id']);
        $fields = [
            \OpenSendForm\Submit\SubmitContext::FIELD_TOKEN   => $token,
            \OpenSendForm\Submit\SubmitContext::FIELD_MONITOR => $secret,
            'email'   => (string) $form['recipient_email'],
            'message' => self::MARKER . ' Synthetic end-to-end delivery check at '
                . gmdate('Y-m-d H:i:s', $this->clock->now()) . ' UTC. Delivered to the service '
                . 'mailbox only; no recipient is contacted. Automated; safe to ignore.',
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
     * 'sent' on the first (immediate) read; the short re-checks that follow
     * only cover the degenerate case where the row is still 'received' - which
     * at timeout is an honest failure (delivery did not happen in-request).
     *
     * @return array{ok:bool, detail:string}
     */
    private function awaitDelivery(int $submissionId, string $status): array
    {
        if ($status === 'sent') {
            return $this->ok('delivered (submission #' . $submissionId . ')');
        }

        $timeout = $this->config->monitorSendTimeoutSeconds();
        // Derive the poll interval from the timeout so the number of re-checks
        // stays small and the interval tightens as the timeout shrinks.
        $interval = max(1, intdiv($timeout, self::DELIVERY_POLL_ATTEMPTS));
        $deadline = $this->clock->now() + $timeout;
        while ($this->clock->now() < $deadline) {
            $this->sleeper->sleep($interval);
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

    // --- Transitions -------------------------------------------------------

    /**
     * The canary's ok<->fail transition, or null when nothing changed. A form
     * with no prior canary state is treated as previously ok: a first-ever
     * failing check alerts, a first-ever passing check is silent.
     *
     * @return 'failing'|'recovered'|null
     */
    private function canaryTransition(?bool $previous, bool $ok): ?string
    {
        $wasOk = $previous ?? true;
        if ($wasOk && !$ok) {
            return 'failing';
        }
        if ($previous === false && $ok) {
            return 'recovered';
        }

        return null;
    }

    /**
     * Map a delivery status to a passive per-form state word.
     */
    private static function stateFromStatus(string $status): string
    {
        return match ($status) {
            'failed' => 'failed',
            'dead'   => 'dead',
            default  => 'ok', // 'sent'
        };
    }

    /**
     * A human sentence for a passive transition, shown in run output.
     *
     * @param array<string, mixed> $outcome
     */
    private function passiveDetail(string $state, array $outcome): string
    {
        $when = self::outcomeWhen($outcome);
        $suffix = $when === '' ? '' : ' (' . $when . ' UTC)';

        return match ($state) {
            'failed' => 'a real submission failed to deliver and is being retried' . $suffix,
            'dead'   => 'a real submission could not be delivered after every retry' . $suffix,
            default  => 'real submissions are delivering again' . $suffix,
        };
    }

    // --- Alerting ----------------------------------------------------------

    /**
     * Fold one trySend() result into the running alert tallies.
     */
    private function tallyAlert(bool|string $result, int &$alertsSent, ?string &$alertError): void
    {
        if ($result === true) {
            $alertsSent++;
        } elseif (is_string($result) && $alertError === null) {
            $alertError = $result; // first alert-send failure wins; cron is the backstop
        }
    }

    /**
     * @param array<string, mixed> $form
     * @param 'failing'|'dead'|'recovered' $event
     * @return bool|string True when the email was sent, else the error string.
     */
    private function trySend(array $form, string $checkedAt, string $detail, string $event): bool|string
    {
        try {
            $this->sendAlert($form, $checkedAt, $detail, $event);

            return true;
        } catch (Throwable $e) {
            $label = $event === 'recovered' ? 'recovery' : 'alert';

            return 'could not send ' . $label . ' email for form "'
                . (string) $form['name'] . '": ' . $e->getMessage();
        }
    }

    /**
     * @param array<string, mixed> $form
     * @param 'failing'|'dead'|'recovered' $event
     *
     * @throws \RuntimeException when there is no recipient, or the mailer throws.
     */
    private function sendAlert(array $form, string $checkedAt, string $detail, string $event): void
    {
        $to = $this->alertRecipient();
        if ($to === '') {
            throw new \RuntimeException('no MONITOR_ALERT_EMAIL configured and no admin to fall back to');
        }

        $name = $this->collapse((string) $form['name']);

        [$state, $lead, $tail] = match ($event) {
            'failing' => [
                'FAILURE',
                'openSendForm detected a problem with a form.',
                "Visitors may be unable to submit this form, or their submissions may not be\n"
                    . 'reaching you. This is an automated alert from openSendForm monitoring.',
            ],
            'dead' => [
                'UNDELIVERABLE',
                'openSendForm gave up delivering a real submission for a form.',
                "The submission could not be delivered after every retry. It is kept so you can\n"
                    . 'recover it, but this needs your attention now. This is an automated alert '
                    . 'from openSendForm monitoring.',
            ],
            'recovered' => [
                'RECOVERED',
                'A form that was previously failing is working again.',
                'No action needed; this is the all-clear from openSendForm monitoring.',
            ],
        };

        $subject = self::MARKER . ' ' . $state . ': ' . $name;
        $body = $lead . "\n\n"
            . 'Form:   ' . $name . ' (#' . (int) $form['id'] . ")\n"
            . 'Time:   ' . $checkedAt . " UTC\n"
            . 'Detail: ' . $detail . "\n\n"
            . $tail;

        $this->mailer->send($to, null, $subject, $body);
    }

    /**
     * The alert recipient: the configured MONITOR_ALERT_EMAIL, else the first
     * admin's email, else '' (no recipient - sendAlert then throws).
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
     * The name of an active form by id, or '' when not present.
     *
     * @param array<int, array<string, mixed>> $active
     */
    private function formName(array $active, int $id): string
    {
        foreach ($active as $form) {
            if ((int) $form['id'] === $id) {
                return (string) $form['name'];
            }
        }

        return '';
    }

    /**
     * The best timestamp to describe a delivery outcome: the last attempt time,
     * falling back to creation time.
     *
     * @param array<string, mixed> $outcome
     */
    private static function outcomeWhen(array $outcome): string
    {
        $at = (string) ($outcome['last_attempt_at'] ?? '');
        if ($at !== '') {
            return $at;
        }

        return (string) ($outcome['created_at'] ?? '');
    }

    /**
     * @return array{scope:string, form_id:int, form_name:string, ok:bool, detail:string}
     */
    private function resultRow(string $scope, int $formId, string $formName, bool $ok, string $detail): array
    {
        return [
            'scope'     => $scope,
            'form_id'   => $formId,
            'form_name' => $formName,
            'ok'        => $ok,
            'detail'    => $detail,
        ];
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
