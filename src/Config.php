<?php

declare(strict_types=1);

namespace OpenSendForm;

/**
 * Application configuration.
 *
 * Loads hard-coded defaults and allows a small, explicit set of
 * environment variables to override them. No secrets live in code;
 * anything sensitive is supplied through the environment at runtime.
 */
final class Config
{
    /** @var array<string,string> */
    private array $values;

    /**
     * @param array<string,string> $values Fully-resolved configuration values.
     */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * Build configuration from defaults, overridden by the given environment.
     *
     * @param array<string,string|false|null> $env Environment map; defaults to
     *                                              the process environment.
     */
    public static function fromEnvironment(?array $env = null): self
    {
        $env ??= getenv();

        $values = self::layerEnvironment(self::defaults(), $env);

        return self::finalise($values);
    }

    /**
     * Build configuration from a written config file: a PHP script returning
     * an associative array of key => value. Only recognised keys are honoured;
     * an unrecognised key is ignored. Unlike the environment parser, a value
     * supplied by the file IS honoured even when empty (the file is trusted).
     *
     * The environment is NOT consulted here; see load() for the merged factory
     * that layers env overrides on top of a file.
     */
    public static function fromFile(string $path): self
    {
        return self::finalise(self::layerFile(self::defaults(), $path));
    }

    /**
     * The merged factory used by the front controller.
     *
     * Precedence (lowest to highest): shipped defaults < config file (when it
     * exists) < environment. So a written config file supplies the installed
     * values, but an environment variable always wins — the seam the dev
     * container relies on. With no file present this behaves exactly like
     * fromEnvironment(), so the devcontainer (pure-env) is unchanged.
     *
     * @param string|null                     $filePath Config file to read, if any.
     * @param array<string,string|false|null> $env      Environment; defaults to the
     *                                                   process environment.
     */
    public static function load(?string $filePath = null, ?array $env = null): self
    {
        $env ??= getenv();

        $values = self::defaults();
        if ($filePath !== null && is_file($filePath)) {
            $values = self::layerFile($values, $filePath);
        }
        $values = self::layerEnvironment($values, $env);

        return self::finalise($values);
    }

    /**
     * Overlay environment values onto a base map. A key is overridden only when
     * present and non-empty (false/null/'' leave the base untouched), so blank
     * env vars never clobber a shipped or file-supplied value.
     *
     * @param array<string,string>            $base
     * @param array<string,string|false|null> $env
     * @return array<string,string>
     */
    private static function layerEnvironment(array $base, array $env): array
    {
        foreach (array_keys($base) as $key) {
            if (array_key_exists($key, $env)) {
                $value = $env[$key];
                if ($value !== false && $value !== null && $value !== '') {
                    $base[$key] = (string) $value;
                }
            }
        }

        return $base;
    }

    /**
     * Overlay a config file's values onto a base map. The file must return an
     * array; only recognised keys are copied, and (the file being trusted) even
     * an explicitly empty value is honoured.
     *
     * @param array<string,string> $base
     * @return array<string,string>
     */
    private static function layerFile(array $base, string $path): array
    {
        /** @psalm-suppress UnresolvableInclude */
        $fileValues = require $path;
        if (!is_array($fileValues)) {
            throw new \RuntimeException("Config file did not return an array: {$path}");
        }

        foreach ($fileValues as $key => $value) {
            if (is_string($key) && array_key_exists($key, $base)) {
                $base[$key] = (string) $value;
            }
        }

        return $base;
    }

    /**
     * Build configuration from an explicit, fully-trusted override map.
     *
     * Unlike fromEnvironment(), an explicitly supplied empty string IS
     * honoured (it does not fall back to the default). This is the seam
     * tests and programmatic callers use to express states the environment
     * parser deliberately protects against — e.g. an empty SMTP_HOST to
     * request storage-only operation.
     *
     * @param array<string,string> $overrides
     */
    public static function fromValues(array $overrides): self
    {
        $values = self::defaults();

        foreach ($overrides as $key => $value) {
            if (array_key_exists($key, $values)) {
                $values[$key] = (string) $value;
            }
        }

        return self::finalise($values);
    }

    /**
     * Apply cross-cutting post-processing and construct.
     *
     * @param array<string,string> $values
     */
    private static function finalise(array $values): self
    {
        // APP_SECRET keys the submit-token HMAC and must be a real secret in
        // production. We ship no secret in code; the browser installer will
        // generate a strong one and write it to the environment on install.
        // The only automatic value is a throwaway used in local development
        // so the endpoint works out of the box; every other environment
        // (including 'production') gets an empty secret until one is supplied.
        if ($values['APP_SECRET'] === '' && $values['APP_ENV'] === 'dev') {
            $values['APP_SECRET'] = 'dev-secret-do-not-use';
        }

        return new self($values);
    }

    /**
     * Default configuration values.
     *
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        return [
            'APP_ENV'                => 'production',
            // Primary delivery switch: mail is attempted only when this is
            // truthy AND SMTP_HOST is non-empty (the host check remains a
            // guard, not the primary switch).
            'MAIL_ENABLED'           => '1',
            'SMTP_HOST'              => 'localhost',
            'SMTP_PORT'              => '25',
            'DB_DSN'                 => 'sqlite:' . self::defaultDatabasePath(),
            // Database credentials, used by non-sqlite drivers (e.g. MySQL).
            // Empty for the default file-backed SQLite, which needs none.
            'DB_USER'                => '',
            'DB_PASS'                => '',

            // Submit-token signing secret. Empty by default; see the
            // dev-only fallback applied in fromEnvironment().
            'APP_SECRET'             => '',

            // Request/payload caps (bytes / counts).
            'MAX_BODY_BYTES'         => '65536',
            'MAX_FIELDS'             => '50',
            'MAX_FIELD_NAME_BYTES'   => '100',
            'MAX_FIELD_VALUE_BYTES'  => '10240',

            // Abuse-filter timing (seconds).
            'MIN_SUBMIT_SECONDS'     => '3',
            'TOKEN_MAX_AGE_SECONDS'  => '3600',

            // Fixed-window rate limits.
            'RATE_IP_PER_MINUTE'     => '5',
            'RATE_FORM_PER_HOUR'     => '60',

            // SMTP authentication (empty = no auth, as with Mailpit in dev).
            'SMTP_USER'              => '',
            'SMTP_PASS'              => '',
            // Transport security: 'none' (Mailpit/dev), 'starttls' or 'smtps'.
            'SMTP_ENCRYPTION'        => 'none',

            // Envelope/header identity. From: is ALWAYS this address; the
            // submitter never controls it.
            'MAIL_FROM_ADDRESS'      => 'noreply@localhost',
            'MAIL_FROM_NAME'         => 'OpenSendForm',

            // Retry policy. A submission is attempted at most MAIL_MAX_ATTEMPTS
            // times; the backoff list gives the wait (minutes) after each
            // failure, the last value repeating if failures exceed the list.
            'MAIL_MAX_ATTEMPTS'      => '5',
            'MAIL_RETRY_BACKOFF_MINUTES' => '1,5,30,120',

            // Synthetic monitoring (bin/osf monitor:run). All optional; the
            // monitor generates and writes back a MONITOR_SECRET on first run
            // when it is still empty. The secret marks a synthetic submission
            // AFTER the pipeline stores it (reserved field _osf_monitor); a
            // wrong/absent secret is an ordinary submission.
            'MONITOR_SECRET'             => '',
            // Days a synthetic submission is kept before the monitor purges it.
            'MONITOR_RETENTION_DAYS'     => '2',
            // The form checked on every run. Empty = the lowest-ID active form.
            'MONITOR_CANARY_FORM_ID'     => '',
            // Every OTHER active form is checked at most once per this many
            // hours, its due times spread across forms (staggered).
            'MONITOR_FORM_INTERVAL_HOURS' => '24',
            // How long a check waits for the stored submission to reach 'sent'.
            'MONITOR_SEND_TIMEOUT_SECONDS' => '30',
            // Where alert/recovery emails go. Empty = the first admin's email.
            'MONITOR_ALERT_EMAIL'        => '',
            // The base URL the monitor drives its real HTTP submissions against.
            'MONITOR_BASE_URL'           => 'http://localhost:8080',
        ];
    }

    /**
     * Absolute path to the default SQLite database file.
     */
    public static function defaultDatabasePath(): string
    {
        return dirname(__DIR__) . '/var/data/opensendform.sqlite';
    }

    /**
     * Absolute path to the written config file the installer produces. It sits
     * under var/ (outside public/), so it is never web-served even if reached.
     */
    public static function defaultConfigFilePath(): string
    {
        return dirname(__DIR__) . '/var/config.php';
    }

    /**
     * Generate a strong APP_SECRET: 32 random bytes rendered as 64 hex chars.
     * Used by the installer to seed the submit-token HMAC key on install.
     */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function get(string $key): string
    {
        if (!array_key_exists($key, $this->values)) {
            throw new \InvalidArgumentException("Unknown config key: {$key}");
        }

        return $this->values[$key];
    }

    public function appEnv(): string
    {
        return $this->get('APP_ENV');
    }

    /**
     * Primary mail delivery switch. Delivery is attempted only when this is
     * true AND smtpHost() is non-empty; the host stays a secondary guard.
     */
    public function mailEnabled(): bool
    {
        return in_array(strtolower(trim($this->get('MAIL_ENABLED'))), ['1', 'true', 'yes', 'on'], true);
    }

    public function smtpHost(): string
    {
        return $this->get('SMTP_HOST');
    }

    public function smtpPort(): int
    {
        return (int) $this->get('SMTP_PORT');
    }

    public function dbDsn(): string
    {
        return $this->get('DB_DSN');
    }

    /**
     * Database username, or null when none is configured (the SQLite default).
     * Returned as null rather than '' so it is passed straight to PDO.
     */
    public function dbUser(): ?string
    {
        $user = $this->get('DB_USER');

        return $user === '' ? null : $user;
    }

    /**
     * Database password, or null when none is configured.
     */
    public function dbPass(): ?string
    {
        $pass = $this->get('DB_PASS');

        return $pass === '' ? null : $pass;
    }

    public function appSecret(): string
    {
        return $this->get('APP_SECRET');
    }

    public function maxBodyBytes(): int
    {
        return (int) $this->get('MAX_BODY_BYTES');
    }

    public function maxFields(): int
    {
        return (int) $this->get('MAX_FIELDS');
    }

    public function maxFieldNameBytes(): int
    {
        return (int) $this->get('MAX_FIELD_NAME_BYTES');
    }

    public function maxFieldValueBytes(): int
    {
        return (int) $this->get('MAX_FIELD_VALUE_BYTES');
    }

    public function minSubmitSeconds(): int
    {
        return (int) $this->get('MIN_SUBMIT_SECONDS');
    }

    public function tokenMaxAgeSeconds(): int
    {
        return (int) $this->get('TOKEN_MAX_AGE_SECONDS');
    }

    public function rateIpPerMinute(): int
    {
        return (int) $this->get('RATE_IP_PER_MINUTE');
    }

    public function rateFormPerHour(): int
    {
        return (int) $this->get('RATE_FORM_PER_HOUR');
    }

    public function smtpUser(): string
    {
        return $this->get('SMTP_USER');
    }

    public function smtpPass(): string
    {
        return $this->get('SMTP_PASS');
    }

    /**
     * SMTP transport security: one of 'none', 'starttls', 'smtps'.
     * Anything unrecognised is treated as 'none'.
     */
    public function smtpEncryption(): string
    {
        $value = strtolower(trim($this->get('SMTP_ENCRYPTION')));

        return in_array($value, ['none', 'starttls', 'smtps'], true) ? $value : 'none';
    }

    public function mailFromAddress(): string
    {
        return $this->get('MAIL_FROM_ADDRESS');
    }

    public function mailFromName(): string
    {
        return $this->get('MAIL_FROM_NAME');
    }

    public function mailMaxAttempts(): int
    {
        return max(1, (int) $this->get('MAIL_MAX_ATTEMPTS'));
    }

    /**
     * Retry backoff schedule in minutes, parsed from the comma list. Only
     * positive integers are kept; an empty or malformed list falls back to
     * a single one-minute wait so retry can never schedule a zero/negative
     * delay.
     *
     * @return array<int,int> One or more positive minute values.
     */
    public function mailRetryBackoffMinutes(): array
    {
        $parts = explode(',', $this->get('MAIL_RETRY_BACKOFF_MINUTES'));

        $minutes = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part) && (int) $part > 0) {
                $minutes[] = (int) $part;
            }
        }

        return $minutes === [] ? [1] : $minutes;
    }

    // --- Synthetic monitoring ---------------------------------------------

    /**
     * The shared secret that marks a submission synthetic. Empty until the
     * monitor generates one on first run (written back via ConfigWriter).
     * When empty, no submission is ever treated as synthetic.
     */
    public function monitorSecret(): string
    {
        return $this->get('MONITOR_SECRET');
    }

    /**
     * Days a synthetic submission is retained before the monitor purges it.
     * Clamped to at least 1 so a zero/negative value never purges everything
     * the instant it is written.
     */
    public function monitorRetentionDays(): int
    {
        return max(1, (int) $this->get('MONITOR_RETENTION_DAYS'));
    }

    /**
     * The configured canary form id, or null to let the monitor default to the
     * lowest-ID active form. A blank or non-positive value means "unset".
     */
    public function monitorCanaryFormId(): ?int
    {
        $value = trim($this->get('MONITOR_CANARY_FORM_ID'));

        return ($value !== '' && ctype_digit($value) && (int) $value > 0) ? (int) $value : null;
    }

    /**
     * Minimum hours between checks of a non-canary form. At least 1.
     */
    public function monitorFormIntervalHours(): int
    {
        return max(1, (int) $this->get('MONITOR_FORM_INTERVAL_HOURS'));
    }

    /**
     * Seconds a check waits for its stored submission to reach 'sent'. At
     * least 1 so the poll always runs at least once.
     */
    public function monitorSendTimeoutSeconds(): int
    {
        return max(1, (int) $this->get('MONITOR_SEND_TIMEOUT_SECONDS'));
    }

    /**
     * The alert recipient, or '' to fall back to the first admin's email.
     */
    public function monitorAlertEmail(): string
    {
        return trim($this->get('MONITOR_ALERT_EMAIL'));
    }

    /**
     * The base URL the monitor drives its synthetic submissions against, with
     * any trailing slash removed.
     */
    public function monitorBaseUrl(): string
    {
        return rtrim(trim($this->get('MONITOR_BASE_URL')), '/');
    }

    /**
     * @return array<string,string>
     */
    public function all(): array
    {
        return $this->values;
    }
}
