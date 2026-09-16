<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Monitor;

use OpenSendForm\Monitor\MonitorSchedule;
use PHPUnit\Framework\TestCase;

/**
 * The pure scheduling policy: canary every run, other forms at most once per
 * interval, never-checked forms introduced one per run (the stagger).
 */
final class MonitorScheduleTest extends TestCase
{
    private const NOW = 1_700_000_000;

    public function testCanaryIsAlwaysCheckedAndListedFirst(): void
    {
        $forms = $this->forms(1, 2, 3);
        // Canary (2) was just checked, yet it is still scheduled this run.
        $last = [2 => $this->ago(0)];

        $due = MonitorSchedule::due($forms, 2, $last, 24, self::NOW);

        self::assertSame(2, $due[0]);
    }

    public function testNeverCheckedFormsAreIntroducedOnePerRun(): void
    {
        // Forms 2,3,4 are non-canary and never checked. Only ONE (lowest id)
        // joins the canary this run — the stagger.
        $due = MonitorSchedule::due($this->forms(1, 2, 3, 4), 1, [], 24, self::NOW);

        self::assertSame([1, 2], $due);
    }

    public function testRechecksAreDueOnlyPastTheInterval(): void
    {
        $forms = $this->forms(1, 2, 3);
        $last = [
            1 => $this->ago(0),               // canary — always
            2 => $this->ago(25 * 3600),       // 25h ago, interval 24h -> due
            3 => $this->ago(1 * 3600),        // 1h ago -> not due
        ];

        $due = MonitorSchedule::due($forms, 1, $last, 24, self::NOW);

        self::assertContains(2, $due);
        self::assertNotContains(3, $due);
    }

    public function testMostOverdueRecheckComesBeforeNewerOnes(): void
    {
        $forms = $this->forms(1, 2, 3);
        $last = [
            2 => $this->ago(48 * 3600), // very overdue
            3 => $this->ago(30 * 3600), // overdue, but less so
        ];

        $due = MonitorSchedule::due($forms, 1, $last, 24, self::NOW);

        // Canary 1 first, then the most-overdue recheck (2) before 3.
        self::assertSame([1, 2, 3], $due);
    }

    public function testNoCanaryWhenNoneResolves(): void
    {
        $due = MonitorSchedule::due($this->forms(5, 6), null, [], 24, self::NOW);

        // No canary; still introduce one never-checked form (lowest id).
        self::assertSame([5], $due);
    }

    /**
     * @return array<int, array<string, int>>
     */
    private function forms(int ...$ids): array
    {
        return array_map(static fn (int $id): array => ['id' => $id], $ids);
    }

    private function ago(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', self::NOW - $seconds);
    }
}
