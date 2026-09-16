<?php

declare(strict_types=1);

namespace OpenSendForm\Monitor;

/**
 * A pausing seam so the monitor can honour the pipeline's min-submit-time bot
 * check (a token must age past MIN_SUBMIT_SECONDS before it is accepted) and
 * poll for delivery, without the test suite ever sleeping in wall-clock time.
 *
 * The production RealSleeper sleeps for real; the test double advances a fixed
 * clock instead, so token-age and send-timeout logic is exercised deterministically.
 */
interface Sleeper
{
    public function sleep(int $seconds): void;
}
