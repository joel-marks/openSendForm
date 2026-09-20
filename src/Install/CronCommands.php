<?php

declare(strict_types=1);

namespace OpenSendForm\Install;

/**
 * The two scheduled-task (cron) commands, built with real absolute paths
 * derived at runtime from this install's own location — the single source for
 * the copy-paste command block shown both by the installer's Scheduled tasks
 * step and by the admin Email tab post-install.
 *
 * The PHP CLI binary is PHP_BINARY when the SAPI gives us one, else the common
 * cPanel CLI path; callers note the fallback in case the baked value is a web
 * SAPI binary. bin/osf is resolved from the project root two levels up from
 * this file, so the paths point at wherever the app was extracted with no
 * manual entry.
 */
final class CronCommands
{
    /** The PHP CLI binary to bake into the cron commands. */
    public static function phpBinary(): string
    {
        $binary = defined('PHP_BINARY') ? PHP_BINARY : '';

        return $binary !== '' ? $binary : '/usr/local/bin/php';
    }

    /** The installation's root directory (where the app was extracted). */
    public static function installRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Absolute path to this install's bin/osf CLI. */
    public static function osfPath(): string
    {
        return self::installRoot() . '/bin/osf';
    }

    /** The synthetic-monitoring cron command, verbatim and copy-paste ready. */
    public static function monitorCommand(): string
    {
        return self::phpBinary() . ' ' . self::osfPath() . ' monitor:run';
    }

    /** The mail-retry cron command, verbatim and copy-paste ready. */
    public static function retryCommand(): string
    {
        return self::phpBinary() . ' ' . self::osfPath() . ' mail:retry';
    }
}
