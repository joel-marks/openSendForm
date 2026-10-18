<?php

declare(strict_types=1);

namespace OpenSendForm\Tests;

use OpenSendForm\Config;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the Increment 3 mail configuration: defaults, env overrides,
 * the encryption/backoff parsing, MAIL_ENABLED, and the explicit
 * fromValues() constructor (which, unlike fromEnvironment(), honours an
 * intentionally empty value).
 */
final class ConfigMailTest extends TestCase
{
    public function testMailDefaults(): void
    {
        $config = Config::fromEnvironment([]);

        self::assertTrue($config->mailEnabled());
        self::assertSame('', $config->smtpUser());
        self::assertSame('', $config->smtpPass());
        self::assertSame('none', $config->smtpEncryption());
        self::assertSame('noreply@localhost', $config->mailFromAddress());
        self::assertSame('openSendForm', $config->mailFromName());
        self::assertSame(5, $config->mailMaxAttempts());
        self::assertSame([1, 5, 30, 120], $config->mailRetryBackoffMinutes());
    }

    public function testMailValuesAreEnvOverridable(): void
    {
        $config = Config::fromEnvironment([
            'SMTP_USER'                  => 'relay',
            'SMTP_PASS'                  => 'secret',
            'SMTP_ENCRYPTION'            => 'starttls',
            'MAIL_FROM_ADDRESS'          => 'forms@example.com',
            'MAIL_FROM_NAME'             => 'Example Forms',
            'MAIL_MAX_ATTEMPTS'          => '8',
            'MAIL_RETRY_BACKOFF_MINUTES' => '2, 10, 60',
        ]);

        self::assertSame('relay', $config->smtpUser());
        self::assertSame('secret', $config->smtpPass());
        self::assertSame('starttls', $config->smtpEncryption());
        self::assertSame('forms@example.com', $config->mailFromAddress());
        self::assertSame('Example Forms', $config->mailFromName());
        self::assertSame(8, $config->mailMaxAttempts());
        self::assertSame([2, 10, 60], $config->mailRetryBackoffMinutes());
    }

    public function testUnknownEncryptionFallsBackToNone(): void
    {
        $config = Config::fromEnvironment(['SMTP_ENCRYPTION' => 'wat']);

        self::assertSame('none', $config->smtpEncryption());
    }

    public function testMalformedBackoffFallsBackToSingleMinute(): void
    {
        $config = Config::fromEnvironment(['MAIL_RETRY_BACKOFF_MINUTES' => 'x, -3, 0, ,']);

        self::assertSame([1], $config->mailRetryBackoffMinutes());
    }

    public function testMaxAttemptsIsAtLeastOne(): void
    {
        $config = Config::fromEnvironment(['MAIL_MAX_ATTEMPTS' => '0']);

        self::assertSame(1, $config->mailMaxAttempts());
    }

    public function testFromValuesHonoursExplicitEmptyHost(): void
    {
        // fromEnvironment() protects defaults from blank env; fromValues()
        // is the explicit seam that lets an empty SMTP_HOST through.
        self::assertSame('localhost', Config::fromEnvironment(['SMTP_HOST' => ''])->smtpHost());
        self::assertSame('', Config::fromValues(['SMTP_HOST' => ''])->smtpHost());
    }

    public function testFromValuesStillAppliesDevSecretFallback(): void
    {
        $config = Config::fromValues(['APP_ENV' => 'dev']);

        self::assertSame('dev-secret-do-not-use', $config->appSecret());
    }

    public function testMailEnabledCanBeDisabledViaEnvironment(): void
    {
        self::assertFalse(Config::fromEnvironment(['MAIL_ENABLED' => '0'])->mailEnabled());
    }

    public function testMailEnabledExplicitEmptyViaFromValuesIsFalsy(): void
    {
        self::assertFalse(Config::fromValues(['MAIL_ENABLED' => ''])->mailEnabled());
    }
}
