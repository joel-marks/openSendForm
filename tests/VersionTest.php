<?php

declare(strict_types=1);

namespace OpenSendForm\Tests;

use OpenSendForm\Version;
use PHPUnit\Framework\TestCase;

/**
 * The version string is the single source of truth the release build reads to
 * name the zip and stamp the install lock, so its shape must stay predictable.
 */
final class VersionTest extends TestCase
{
    public function testVersionIsSemver(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            Version::STRING,
            'Version::STRING must be a bare MAJOR.MINOR.PATCH semver.'
        );
    }

    public function testCurrentPackagedVersion(): void
    {
        // 0.2.1 is the dark-canary monitoring release. This guards against
        // an accidental revert of the version bump.
        self::assertSame('0.2.1', Version::STRING);
    }
}
