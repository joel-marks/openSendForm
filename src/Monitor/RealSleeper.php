<?php

declare(strict_types=1);

namespace OpenSendForm\Monitor;

/**
 * The production Sleeper: pauses the process for real, which advances the
 * server clock that the token-age and delivery checks read.
 */
final class RealSleeper implements Sleeper
{
    public function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }
}
