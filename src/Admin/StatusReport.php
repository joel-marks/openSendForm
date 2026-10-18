<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

use OpenSendForm\Clock\Clock;
use OpenSendForm\Config;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\Install\Requirements;
use OpenSendForm\Mail\DeliverabilityChecker;
use OpenSendForm\Monitor\MonitorRepository;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Submission\SubmissionRepository;
use OpenSendForm\Version;

/**
 * Gathers everything the Status page shows into one plain data structure, so
 * the controller stays thin and the operational logic (stale-cron detection,
 * world-writable tamper check, 2FA state) is unit-testable without HTTP.
 *
 * It reuses existing machinery wholesale rather than re-implementing it:
 * Requirements/EnvironmentProbe for the environment re-probe, MigrationRunner
 * for schema state, MonitorRepository for monitor state and DeliverabilityChecker
 * for the mail deliverability readout. Nothing here is cached — build() runs a
 * fresh probe every time it is called, which is the point of a Status page.
 */
final class StatusReport
{
    /** The recommended monitor cron cadence (hourly). */
    public const EXPECTED_CADENCE_SECONDS = 3600;

    /** Newest check older than this many cadences reads as a dead cron. */
    public const STALE_FACTOR = 2;

    /**
     * @param array<string, mixed> $admin   The viewing admin's row.
     * @param list<string>         $appDirs Directories to tamper-check (0002 bit).
     */
    public function __construct(
        private Requirements $requirements,
        private Database $db,
        private MigrationRunner $migrations,
        private MonitorRepository $monitor,
        private Config $config,
        private Clock $clock,
        private DeliverabilityChecker $checker,
        private array $admin,
        private array $appDirs,
        private FormRepository $forms,
        private SubmissionRepository $submissions,
        private string $dkimSelector = 'default'
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'environment' => $this->environment(),
            'security'    => $this->security(),
            'mail'        => $this->mail(),
            'operations'  => $this->operations(),
        ];
    }

    // --- Environment re-probe ---------------------------------------------

    /**
     * The installer's requirement matrix plus a live database-reachability row,
     * all in the same {key,label,status,remedy} shape so the page renders them
     * with the shared pass/warn/fail badges.
     *
     * @return array<int, array{key:string, label:string, status:string, remedy:string}>
     */
    private function environment(): array
    {
        $checks = $this->requirements->checks();
        $checks[] = $this->databaseRow();

        return $checks;
    }

    /**
     * @return array{key:string, label:string, status:string, remedy:string}
     */
    private function databaseRow(): array
    {
        try {
            $this->db->fetchOne('SELECT 1 AS ok');
            $driver = $this->db->driver();

            return [
                'key'    => 'database',
                'label'  => 'Database reachable (' . $driver . ')',
                'status' => Requirements::PASS,
                'remedy' => '',
            ];
        } catch (\Throwable $e) {
            return [
                'key'    => 'database',
                'label'  => 'Database reachable',
                'status' => Requirements::FAIL,
                'remedy' => 'The app could not query its database. Check the database settings in '
                    . 'var/config.php and that the database file or server is reachable.',
            ];
        }
    }

    // --- Security ---------------------------------------------------------

    /**
     * @return array{twofa_enabled: bool, twofa_setup_url: string, world_writable: list<string>}
     */
    private function security(): array
    {
        return [
            'twofa_enabled'   => (int) ($this->admin['totp_enabled'] ?? 0) === 1,
            'twofa_setup_url' => '/admin/totp/setup',
            'world_writable'  => self::worldWritableDirs($this->appDirs),
        ];
    }

    // --- Mail -------------------------------------------------------------

    /**
     * @return array{configured: bool, sending: bool, selector: string, report: array<string, mixed>}
     */
    private function mail(): array
    {
        $report = $this->checker->check(
            $this->config->mailFromAddress(),
            (string) ($this->admin['email'] ?? ''),
            $this->dkimSelector,
            $this->config->smtpHost()
        );

        return [
            'configured' => $this->config->smtpHost() !== '',
            'sending'    => $this->config->mailEnabled(),
            'selector'   => (string) $report['selector'],
            'report'     => $report,
        ];
    }

    // --- Operations -------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function operations(): array
    {
        $applied = $this->migrations->appliedVersions();
        // "Last run" is the dark canary heartbeat (migration 012 kind='canary'):
        // it writes one row every run, so it tracks the cron's liveness.
        $latest = $this->monitor->latest(MonitorRepository::KIND_CANARY);
        $lastRunAt = $latest === null ? null : (string) $latest['checked_at'];

        return [
            'app_version'        => Version::STRING,
            'schema_version'     => $applied === [] ? 0 : max($applied),
            'pending_migrations' => $this->migrations->pendingCount(),
            'monitor'            => [
                'ever_run'    => $latest !== null,
                'last_run_at' => $lastRunAt,
                'ok'          => $latest === null ? null : ((int) $latest['ok'] === 1),
                'detail'      => $latest === null ? null : ($latest['detail'] === null ? null : (string) $latest['detail']),
                'stale'       => $latest !== null && self::monitorStale($lastRunAt, $this->clock->now()),
                'forms'       => $this->formDeliveryOutcomes(),
            ],
            'cron_setup'         => $this->config->cronSetup(),
        ];
    }

    /**
     * Each active form's last REAL delivery outcome (sent/failed/dead), or null
     * when the form has had no real delivery activity yet. This is the passive
     * per-form coverage the dark-canary model relies on, shown on the Status
     * page so an operator sees every form, not just the canary.
     *
     * @return array<int, array{form_name:string, outcome:?string, at:?string}>
     */
    private function formDeliveryOutcomes(): array
    {
        $rows = [];
        foreach ($this->forms->listForms() as $form) {
            if ((int) $form['is_active'] !== 1) {
                continue;
            }
            $id = (int) $form['id'];
            $outcome = $this->submissions->latestRealDeliveryOutcomeForForm($id);
            $at = $outcome === null
                ? null
                : ((string) ($outcome['last_attempt_at'] ?? '') !== ''
                    ? (string) $outcome['last_attempt_at']
                    : (string) ($outcome['created_at'] ?? ''));

            $rows[] = [
                'form_name' => (string) $form['name'],
                'outcome'   => $outcome === null ? null : (string) $outcome['status'],
                'at'        => $at,
            ];
        }

        return $rows;
    }

    // --- Pure, directly-testable logic ------------------------------------

    /**
     * Whether the newest monitor check is old enough to mean the cron is dead:
     * older than STALE_FACTOR times the expected hourly cadence. A null (never
     * run) is not "stale" — that is a distinct "never run" state the page shows
     * separately. checked_at is the app's portable 'Y-m-d H:i:s' UTC text.
     */
    public static function monitorStale(
        ?string $lastCheckedAt,
        int $now,
        int $cadenceSeconds = self::EXPECTED_CADENCE_SECONDS,
        int $factor = self::STALE_FACTOR
    ): bool {
        if ($lastCheckedAt === null || $lastCheckedAt === '') {
            return false;
        }

        $ts = strtotime($lastCheckedAt . ' UTC');
        if ($ts === false) {
            return false;
        }

        return ($now - $ts) > ($cadenceSeconds * $factor);
    }

    /**
     * Of the given directories, those that are world-writable (the other-write
     * 0002 permission bit) — a shared-hosting tamper signal: anyone on the box
     * could drop files into the app. Non-existent paths are skipped.
     *
     * @param list<string> $dirs
     * @return list<string>
     */
    public static function worldWritableDirs(array $dirs): array
    {
        $found = [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $perms = @fileperms($dir);
            if ($perms !== false && ($perms & 0o0002) !== 0) {
                $found[] = $dir;
            }
        }

        return $found;
    }
}
