<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Support;

use OpenSendForm\Monitor\Sleeper;

/**
 * A test Sleeper that never really sleeps: it advances a FixedClock instead, so
 * the monitor's min-submit-time wait and delivery poll are exercised
 * deterministically. Advancing the shared clock is exactly what a real sleep
 * does to wall time, so token-age and send-timeout logic behaves as in production.
 */
final class FakeSleeper implements Sleeper
{
    /** Every requested sleep in seconds, in order. */
    public array $sleeps = [];

    public function __construct(private FixedClock $clock)
    {
    }

    public function sleep(int $seconds): void
    {
        $this->sleeps[] = $seconds;
        if ($seconds > 0) {
            $this->clock->advance($seconds);
        }
    }
}
