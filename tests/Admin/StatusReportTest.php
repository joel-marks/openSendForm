<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Admin;

use OpenSendForm\Admin\StatusReport;
use PHPUnit\Framework\TestCase;

/**
 * The pure, host-independent logic of the Status report: stale-cron detection
 * and the world-writable tamper probe. The full report is exercised end-to-end
 * through HTTP in StatusHttpTest; here we pin the decision rules directly.
 */
final class StatusReportTest extends TestCase
{
    // --- Stale-monitor logic ---------------------------------------------

    public function testNeverRunIsNotStale(): void
    {
        self::assertFalse(StatusReport::monitorStale(null, 1_700_000_000));
        self::assertFalse(StatusReport::monitorStale('', 1_700_000_000));
    }

    public function testRecentCheckIsNotStale(): void
    {
        $now = 1_700_000_000;
        // One hour ago — well within 2x the hourly cadence.
        $checkedAt = gmdate('Y-m-d H:i:s', $now - 3600);
        self::assertFalse(StatusReport::monitorStale($checkedAt, $now));
    }

    public function testCheckOlderThanTwiceCadenceIsStale(): void
    {
        $now = 1_700_000_000;
        // Just over 2 hours ago (2x hourly cadence) — the cron looks dead.
        $checkedAt = gmdate('Y-m-d H:i:s', $now - (7200 + 1));
        self::assertTrue(StatusReport::monitorStale($checkedAt, $now));
    }

    public function testExactlyTwiceCadenceIsNotYetStale(): void
    {
        $now = 1_700_000_000;
        $checkedAt = gmdate('Y-m-d H:i:s', $now - 7200);
        self::assertFalse(StatusReport::monitorStale($checkedAt, $now));
    }

    public function testStaleThresholdIsConfigurable(): void
    {
        $now = 1_700_000_000;
        $checkedAt = gmdate('Y-m-d H:i:s', $now - 1000);
        // With a tiny cadence the same check reads as stale.
        self::assertTrue(StatusReport::monitorStale($checkedAt, $now, 100, 2));
    }

    // --- World-writable tamper probe -------------------------------------

    public function testWorldWritableDirsFindsOnlyTheOtherWritableOnes(): void
    {
        $base = sys_get_temp_dir() . '/osf-ww-' . bin2hex(random_bytes(6));
        $tight = $base . '/tight';
        $loose = $base . '/loose';
        mkdir($tight, 0o755, true);
        mkdir($loose, 0o755, true);
        chmod($loose, 0o777);

        try {
            $found = StatusReport::worldWritableDirs([
                $tight,
                $loose,
                $base . '/does-not-exist',
            ]);

            self::assertSame([$loose], $found);
        } finally {
            @chmod($loose, 0o755);
            @rmdir($tight);
            @rmdir($loose);
            @rmdir($base);
        }
    }

    public function testWorldWritableDirsEmptyWhenAllTight(): void
    {
        $base = sys_get_temp_dir() . '/osf-ww-' . bin2hex(random_bytes(6));
        mkdir($base, 0o755, true);

        try {
            self::assertSame([], StatusReport::worldWritableDirs([$base]));
        } finally {
            @rmdir($base);
        }
    }
}
