<?php

declare(strict_types=1);

namespace OpenSendForm\Install;

/**
 * The pre-flight environment check shown on the installer's welcome screen.
 *
 * Produces a plain-language row per requirement — a status (pass / warn /
 * fail) and, when not a pass, a one-line remedy the non-technical installer
 * can act on. A single failing row blocks the install (the Continue button is
 * disabled); warnings are advisory and let the install proceed.
 *
 * Everything it inspects comes through the injected EnvironmentProbe, so the
 * whole matrix is exercised with a fake in tests without touching the real
 * host.
 */
final class Requirements
{
    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /** Minimum supported PHP version (see CLAUDE.md hard constraints). */
    public const MIN_PHP = '8.1.0';

    public function __construct(
        private EnvironmentProbe $probe,
        private Paths $paths
    ) {
    }

    /**
     * The full requirements matrix, in display order.
     *
     * @return array<int, array{key:string, label:string, status:string, remedy:string}>
     */
    public function checks(): array
    {
        return [
            $this->phpVersion(),
            $this->pdoSqlite(),
            $this->pdoMysql(),
            $this->openssl(),
            $this->curl(),
            $this->writable('var', $this->paths->varDir),
            $this->writable('var/data', $this->paths->dataDir),
            $this->https(),
        ];
    }

    /**
     * True when any requirement is a hard failure. The welcome screen disables
     * Continue on this, and the database step re-checks it server-side.
     */
    public function hasFailures(): bool
    {
        foreach ($this->checks() as $check) {
            if ($check['status'] === self::FAIL) {
                return true;
            }
        }

        return false;
    }

    // --- Individual checks ------------------------------------------------

    /**
     * @return array{key:string, label:string, status:string, remedy:string}
     */
    private function phpVersion(): array
    {
        $version = $this->probe->phpVersion();
        $ok = version_compare($version, self::MIN_PHP, '>=');

        return $this->row(
            'php_version',
            'PHP ' . $version . ' (need ' . self::MIN_PHP . ' or newer)',
            $ok ? self::PASS : self::FAIL,
            $ok ? '' : 'Your hosting is running an older PHP. In cPanel open "Select PHP Version" '
                . 'and switch to PHP 8.1 or newer, then reload this page.'
        );
    }

    /**
     * @return array{key:string, label:string, status:string, remedy:string}
     */
    private function pdoSqlite(): array
    {
        if ($this->probe->hasExtension('pdo_sqlite')) {
            return $this->row('pdo_sqlite', 'SQLite database support (pdo_sqlite)', self::PASS, '');
        }

        // Absent SQLite is only fatal when MySQL support is also missing: with
        // neither driver there is no database at all. With MySQL present you
        // can still install by choosing MySQL on the next step.
        $mysqlPresent = $this->probe->hasExtension('pdo_mysql');

        return $this->row(
            'pdo_sqlite',
            'SQLite database support (pdo_sqlite)',
            $mysqlPresent ? self::WARN : self::FAIL,
            $mysqlPresent
                ? 'SQLite is not available, so the simple built-in database cannot be used. '
                    . 'You can still install by choosing MySQL on the next step.'
                : 'No database support was found at all. In cPanel enable the "pdo_sqlite" '
                    . 'PHP extension (or "pdo_mysql" if you plan to use MySQL), then reload this page.'
        );
    }

    /**
     * @return array{key:string, label:string, status:string, remedy:string}
     */
    private function pdoMysql(): array
    {
        $ok = $this->probe->hasExtension('pdo_mysql');

        return $this->row(
            'pdo_mysql',
            'MySQL database support (pdo_mysql)',
            $ok ? self::PASS : self::WARN,
            $ok ? '' : 'MySQL support is not available. That is fine if you use the built-in '
                . 'SQLite database; only enable the "pdo_mysql" extension in cPanel if you '
                . 'want to store data in a MySQL database instead.'
        );
    }

    /**
     * @return array{key:string, label:string, status:string, remedy:string}
     */
    private function openssl(): array
    {
        $ok = $this->probe->hasExtension('openssl');

        return $this->row(
            'openssl',
            'Encryption support (openssl)',
            $ok ? self::PASS : self::FAIL,
            $ok ? '' : 'The "openssl" PHP extension is required for secure passwords and email. '
                . 'Enable it in cPanel\'s PHP extensions, then reload this page.'
        );
    }

    /**
     * @return array{key:string, label:string, status:string, remedy:string}
     */
    private function curl(): array
    {
        $ok = $this->probe->hasExtension('curl');

        return $this->row(
            'curl',
            'Web requests support (curl)',
            $ok ? self::PASS : self::WARN,
            $ok ? '' : 'The "curl" extension is missing. openSendForm still works, but the optional '
                . 'Cloudflare Turnstile spam check cannot be verified without it.'
        );
    }

    /**
     * @return array{key:string, label:string, status:string, remedy:string}
     */
    private function writable(string $label, string $path): array
    {
        $ok = $this->probe->isWritable($path);

        return $this->row(
            'writable_' . str_replace('/', '_', $label),
            'Writable "' . $label . '" folder',
            $ok ? self::PASS : self::FAIL,
            $ok ? '' : 'The "' . $label . '" folder must be writable so the app can store its '
                . 'database and settings. In your file manager set its permissions to 0755 '
                . '(or 0775), then reload this page.'
        );
    }

    /**
     * @return array{key:string, label:string, status:string, remedy:string}
     */
    private function https(): array
    {
        $ok = $this->probe->isHttps();

        return $this->row(
            'https',
            'Secure connection (HTTPS)',
            $ok ? self::PASS : self::WARN,
            $ok ? '' : 'You are not on a secure (https://) connection. You can still install, but '
                . 'sign in and set up over HTTPS so your admin password is never sent in the '
                . 'clear - most cPanel hosts offer a free "Let\'s Encrypt" certificate.'
        );
    }

    /**
     * @return array{key:string, label:string, status:string, remedy:string}
     */
    private function row(string $key, string $label, string $status, string $remedy): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'remedy' => $remedy];
    }
}
