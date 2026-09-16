<?php

declare(strict_types=1);

namespace OpenSendForm\Tests;

use OpenSendForm\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testUsesDefaultsWhenEnvironmentEmpty(): void
    {
        $config = Config::fromEnvironment([]);

        self::assertSame('production', $config->appEnv());
        self::assertSame('localhost', $config->smtpHost());
        self::assertSame(25, $config->smtpPort());
        self::assertStringStartsWith('sqlite:', $config->dbDsn());
        self::assertStringContainsString(
            'var/data/opensendform.sqlite',
            $config->dbDsn()
        );
    }

    public function testEnvironmentOverridesDefaults(): void
    {
        $config = Config::fromEnvironment([
            'APP_ENV'   => 'testing',
            'SMTP_HOST' => 'mailpit',
            'SMTP_PORT' => '1025',
            'DB_DSN'    => 'mysql:host=db;dbname=osf',
        ]);

        self::assertSame('testing', $config->appEnv());
        self::assertSame('mailpit', $config->smtpHost());
        self::assertSame(1025, $config->smtpPort());
        self::assertSame('mysql:host=db;dbname=osf', $config->dbDsn());
    }

    public function testBlankAndFalseValuesFallBackToDefaults(): void
    {
        $config = Config::fromEnvironment([
            'SMTP_HOST' => '',
            'SMTP_PORT' => false,
        ]);

        // Empty string and false do not override the shipped defaults.
        self::assertSame('localhost', $config->smtpHost());
        self::assertSame(25, $config->smtpPort());
    }

    public function testUnknownKeyThrows(): void
    {
        $config = Config::fromEnvironment([]);

        $this->expectException(\InvalidArgumentException::class);
        $config->get('NOPE');
    }

    public function testSubmissionDefaults(): void
    {
        $config = Config::fromEnvironment([]);

        self::assertSame(65536, $config->maxBodyBytes());
        self::assertSame(50, $config->maxFields());
        self::assertSame(100, $config->maxFieldNameBytes());
        self::assertSame(10240, $config->maxFieldValueBytes());
        self::assertSame(3, $config->minSubmitSeconds());
        self::assertSame(3600, $config->tokenMaxAgeSeconds());
        self::assertSame(5, $config->rateIpPerMinute());
        self::assertSame(60, $config->rateFormPerHour());

        // Login-limiter defaults reproduce the previously hard-coded values.
        self::assertSame(10, $config->rateLoginPerIp());
        self::assertSame(5, $config->rateLoginPerEmail());
        self::assertSame(900, $config->rateLoginWindowSeconds());
        self::assertSame(5, $config->rateTotpPerAdmin());
        self::assertSame(10, $config->rateTotpPerIp());
    }

    public function testSubmissionValuesAreEnvOverridable(): void
    {
        $config = Config::fromEnvironment([
            'MAX_BODY_BYTES'    => '1024',
            'RATE_IP_PER_MINUTE' => '9',
        ]);

        self::assertSame(1024, $config->maxBodyBytes());
        self::assertSame(9, $config->rateIpPerMinute());
    }

    public function testLoginLimiterValuesAreEnvOverridable(): void
    {
        $config = Config::fromEnvironment([
            'RATE_LOGIN_PER_IP'         => '3',
            'RATE_LOGIN_PER_EMAIL'      => '2',
            'RATE_LOGIN_WINDOW_SECONDS' => '120',
            'RATE_TOTP_PER_ADMIN'       => '4',
            'RATE_TOTP_PER_IP'          => '7',
        ]);

        self::assertSame(3, $config->rateLoginPerIp());
        self::assertSame(2, $config->rateLoginPerEmail());
        self::assertSame(120, $config->rateLoginWindowSeconds());
        self::assertSame(4, $config->rateTotpPerAdmin());
        self::assertSame(7, $config->rateTotpPerIp());
    }

    public function testLoginLimiterValuesClampToAtLeastOne(): void
    {
        // A misconfigured zero must never lock everyone out permanently.
        $config = Config::fromEnvironment([
            'RATE_LOGIN_PER_IP'    => '0',
            'RATE_LOGIN_PER_EMAIL' => '0',
        ]);

        self::assertSame(1, $config->rateLoginPerIp());
        self::assertSame(1, $config->rateLoginPerEmail());
    }

    public function testAppSecretEmptyByDefaultInProduction(): void
    {
        $config = Config::fromEnvironment([]);

        self::assertSame('', $config->appSecret());
    }

    public function testAppSecretHasDevFallbackOnlyInDevEnv(): void
    {
        $dev = Config::fromEnvironment(['APP_ENV' => 'dev']);
        self::assertSame('dev-secret-do-not-use', $dev->appSecret());

        // Any other environment keeps the empty secret until one is supplied.
        $testing = Config::fromEnvironment(['APP_ENV' => 'testing']);
        self::assertSame('', $testing->appSecret());
    }

    public function testExplicitAppSecretOverridesDevFallback(): void
    {
        $config = Config::fromEnvironment([
            'APP_ENV'    => 'dev',
            'APP_SECRET' => 'a-real-secret',
        ]);

        self::assertSame('a-real-secret', $config->appSecret());
    }
}
