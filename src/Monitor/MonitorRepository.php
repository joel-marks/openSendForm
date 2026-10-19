<?php

declare(strict_types=1);

namespace OpenSendForm\Monitor;

use OpenSendForm\Storage\Database;

/**
 * Data access for the monitor_checks table (migrations 010 + 012).
 *
 * This table is the ONLY source of monitor state. Since the dark-canary
 * redesign it holds two kinds of row (see migration 012):
 *
 *   'canary' - the hourly dark canary heartbeat (one row per run). Feeds the
 *              Status page's "last run" readout, the stale-cron detection and
 *              the canary's own ok<->fail alert transitions.
 *   'form'   - PASSIVE per-form delivery health, written only on a transition
 *              of a form's last real delivery outcome (ok/failed/dead). detail
 *              carries the state word so the next run can read it back.
 *
 * All access is through prepared statements; comparisons use the portable
 * lexicographically-sortable 'Y-m-d H:i:s' UTC text the rest of the app uses.
 */
final class MonitorRepository
{
    public const KIND_CANARY = 'canary';
    public const KIND_FORM = 'form';

    public function __construct(private Database $db)
    {
    }

    /**
     * Record the outcome of one check. $kind is 'canary' (the heartbeat) or
     * 'form' (a passive per-form transition); it defaults to 'canary' so
     * pre-redesign callers and historical rows keep their meaning.
     */
    public function record(int $formId, string $checkedAt, bool $ok, ?string $detail, string $kind = self::KIND_CANARY): void
    {
        $this->db->execute(
            'INSERT INTO monitor_checks (form_id, checked_at, ok, detail, kind)
             VALUES (:form_id, :checked_at, :ok, :detail, :kind)',
            [
                'form_id'    => $formId,
                'checked_at' => $checkedAt,
                'ok'         => $ok ? 1 : 0,
                'detail'     => $detail,
                'kind'       => $kind,
            ]
        );
    }

    /**
     * Whether ANY check has ever been recorded - evidence that the monitor cron
     * has actually run at least once. Used to auto-clear the dashboard's
     * "scheduled tasks not set up" reminder. Any kind counts (the canary writes
     * one row every run).
     */
    public function hasAnyCheck(): bool
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) AS c FROM monitor_checks');

        return (int) ($row['c'] ?? 0) > 0;
    }

    /**
     * The single most recent CANARY check, or null when none exist. Ordered by
     * id (monotonic) so ties on checked_at break deterministically. Feeds the
     * Status page's "monitor last ran" readout and its stale-cron detection (a
     * newest canary far older than the hourly cadence = dead cron).
     *
     * @return array<string, mixed>|null
     */
    public function latest(string $kind = self::KIND_CANARY): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM monitor_checks WHERE kind = :kind ORDER BY id DESC LIMIT 1',
            ['kind' => $kind]
        );
    }

    /**
     * The most recent check of the given kind for a form, or null. Ordered by
     * id (monotonic) so ties on checked_at break deterministically.
     *
     * @return array<string, mixed>|null
     */
    public function latestForForm(int $formId, string $kind = self::KIND_CANARY): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM monitor_checks WHERE form_id = :form_id AND kind = :kind ORDER BY id DESC LIMIT 1',
            ['form_id' => $formId, 'kind' => $kind]
        );
    }

    /**
     * The last CANARY result for a form as a bool, or null when never checked.
     * Used to compute the canary's ok->fail / fail->ok transitions before
     * recording a new check.
     */
    public function lastResultForForm(int $formId, string $kind = self::KIND_CANARY): ?bool
    {
        $row = $this->latestForForm($formId, $kind);

        return $row === null ? null : ((int) $row['ok'] === 1);
    }

    /**
     * The last PASSIVE per-form state word: 'ok', 'failed' or 'dead'. Defaults
     * to 'ok' when the form has no recorded passive transition yet (a form with
     * no delivery problem is healthy), so the first real failure reads as a
     * genuine ok->failed transition and alerts exactly once.
     */
    public function lastPassiveState(int $formId): string
    {
        $row = $this->latestForForm($formId, self::KIND_FORM);
        if ($row === null) {
            return 'ok';
        }

        $detail = (string) ($row['detail'] ?? '');

        return in_array($detail, ['failed', 'dead'], true) ? $detail : 'ok';
    }

    /**
     * Forms whose latest PASSIVE per-form state is unhealthy (failed/dead),
     * with the form name - the dashboard banner's data. A later recovery row
     * (state 'ok') clears a form from this list. A form deleted after its check
     * drops out (INNER JOIN on forms). "Latest per form" is the max id among
     * that form's 'form'-kind rows.
     *
     * @return array<int, array{form_id:int, form_name:string, detail:string, checked_at:string}>
     */
    public function failingForms(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT mc.form_id, f.name AS form_name, mc.detail, mc.checked_at
               FROM monitor_checks mc
               JOIN forms f ON f.id = mc.form_id
              WHERE mc.kind = 'form'
                AND mc.id IN (SELECT MAX(id) FROM monitor_checks WHERE kind = 'form' GROUP BY form_id)
                AND mc.ok = 0
              ORDER BY mc.form_id"
        );

        return array_map(static fn (array $r): array => [
            'form_id'    => (int) $r['form_id'],
            'form_name'  => (string) $r['form_name'],
            'detail'     => (string) ($r['detail'] ?? ''),
            'checked_at' => (string) $r['checked_at'],
        ], $rows);
    }
}
