<?php

declare(strict_types=1);

namespace OpenSendForm\Monitor;

use OpenSendForm\Storage\Database;

/**
 * Data access for the monitor_checks table (migration 010).
 *
 * One row per synthetic check per form. This table is the ONLY source of
 * monitor state: which forms are due, whether a form is currently failing
 * (for alert transitions and the dashboard banner) and when it was last seen.
 * All access is through prepared statements; comparisons use the portable
 * lexicographically-sortable 'Y-m-d H:i:s' UTC text the rest of the app uses.
 */
final class MonitorRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Record the outcome of one check.
     */
    public function record(int $formId, string $checkedAt, bool $ok, ?string $detail): void
    {
        $this->db->execute(
            'INSERT INTO monitor_checks (form_id, checked_at, ok, detail)
             VALUES (:form_id, :checked_at, :ok, :detail)',
            [
                'form_id'    => $formId,
                'checked_at' => $checkedAt,
                'ok'         => $ok ? 1 : 0,
                'detail'     => $detail,
            ]
        );
    }

    /**
     * The most recent check for a form, or null when it has never been checked.
     * Ordered by id (monotonic) so ties on checked_at are broken deterministically.
     *
     * @return array<string, mixed>|null
     */
    public function latestForForm(int $formId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM monitor_checks WHERE form_id = :form_id ORDER BY id DESC LIMIT 1',
            ['form_id' => $formId]
        );
    }

    /**
     * The last result for a form as a bool, or null when never checked. Used
     * to compute ok->fail / fail->ok transitions before recording a new check.
     */
    public function lastResultForForm(int $formId): ?bool
    {
        $row = $this->latestForForm($formId);

        return $row === null ? null : ((int) $row['ok'] === 1);
    }

    /**
     * The checked_at of a form's latest check, or null when never checked.
     */
    public function lastCheckedAtForForm(int $formId): ?string
    {
        $row = $this->latestForForm($formId);

        return $row === null ? null : (string) $row['checked_at'];
    }

    /**
     * The latest checked_at per form, keyed by form id. Feeds the schedule
     * planner (see MonitorSchedule) so it can decide which forms are due.
     *
     * @return array<int, string> form_id => checked_at
     */
    public function lastCheckedAtByForm(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT form_id, MAX(checked_at) AS checked_at FROM monitor_checks GROUP BY form_id'
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['form_id']] = (string) $row['checked_at'];
        }

        return $map;
    }

    /**
     * Forms whose LATEST check failed, with the form name and failure detail —
     * the dashboard banner's data. A later passing check clears a form from
     * this list (the banner is visible only until then). A form deleted after
     * its check simply drops out (INNER JOIN on forms).
     *
     * "Latest per form" is expressed portably as the max id per form.
     *
     * @return array<int, array{form_id:int, form_name:string, detail:string, checked_at:string}>
     */
    public function failingForms(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT mc.form_id, f.name AS form_name, mc.detail, mc.checked_at
               FROM monitor_checks mc
               JOIN forms f ON f.id = mc.form_id
              WHERE mc.id IN (SELECT MAX(id) FROM monitor_checks GROUP BY form_id)
                AND mc.ok = 0
              ORDER BY mc.form_id'
        );

        return array_map(static fn (array $r): array => [
            'form_id'    => (int) $r['form_id'],
            'form_name'  => (string) $r['form_name'],
            'detail'     => (string) ($r['detail'] ?? ''),
            'checked_at' => (string) $r['checked_at'],
        ], $rows);
    }
}
