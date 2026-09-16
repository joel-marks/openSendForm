<?php

declare(strict_types=1);

namespace OpenSendForm\Monitor;

/**
 * Pure scheduling policy: given the active forms, the resolved canary id, the
 * per-form last-check times and the interval, decide which forms to check this
 * run. No I/O, so it is unit-tested directly with no network or database.
 *
 * The policy:
 *  - The CANARY form is checked on EVERY run (it is the always-on heartbeat).
 *  - Every OTHER active form is checked at most once per interval, and its due
 *    times are STAGGERED rather than firing all at once:
 *      * a form whose last check is older than the interval is due (a recheck);
 *      * never-checked forms are introduced at most ONE per run (lowest id
 *        first), so a fleet of freshly-added forms is spread across successive
 *        runs instead of hammering the endpoint in a single run. Because each
 *        form's recheck cadence is anchored to when it was first checked, the
 *        staggered introductions keep the rechecks staggered too.
 */
final class MonitorSchedule
{
    /**
     * The ordered list of form ids to check this run (canary first).
     *
     * @param array<int, array<string, mixed>> $activeForms Active form rows (need 'id').
     * @param int|null                         $canaryId    Resolved canary id, or null.
     * @param array<int, string>               $lastCheckedAt form_id => 'Y-m-d H:i:s' UTC.
     * @param int                              $intervalHours Recheck interval for non-canary forms.
     * @param int                              $nowUnix     Current time (unix seconds).
     * @return array<int, int> Form ids, in check order.
     */
    public static function due(
        array $activeForms,
        ?int $canaryId,
        array $lastCheckedAt,
        int $intervalHours,
        int $nowUnix
    ): array {
        $ids = array_map(static fn (array $f): int => (int) $f['id'], $activeForms);
        sort($ids);

        $result = [];
        if ($canaryId !== null && in_array($canaryId, $ids, true)) {
            $result[] = $canaryId;
        }

        $intervalSeconds = $intervalHours * 3600;

        $dueRechecks = [];
        $neverChecked = [];
        foreach ($ids as $id) {
            if ($id === $canaryId) {
                continue; // already scheduled as the canary
            }

            if (!array_key_exists($id, $lastCheckedAt)) {
                $neverChecked[] = $id;
                continue;
            }

            $checkedUnix = self::toUnix($lastCheckedAt[$id]);
            if ($checkedUnix === null || ($nowUnix - $checkedUnix) >= $intervalSeconds) {
                $dueRechecks[$id] = $checkedUnix ?? 0;
            }
        }

        // Most-overdue rechecks first (oldest last-check), stable on id.
        asort($dueRechecks);
        foreach (array_keys($dueRechecks) as $id) {
            $result[] = $id;
        }

        // Introduce at most one never-checked form per run — the stagger.
        if ($neverChecked !== []) {
            $result[] = $neverChecked[0];
        }

        return $result;
    }

    /**
     * Parse a portable 'Y-m-d H:i:s' UTC timestamp to unix seconds, or null.
     */
    private static function toUnix(string $timestamp): ?int
    {
        $unix = strtotime($timestamp . ' UTC');

        return $unix === false ? null : $unix;
    }
}
