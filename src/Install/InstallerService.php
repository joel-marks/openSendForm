<?php

declare(strict_types=1);

namespace OpenSendForm\Install;

use InvalidArgumentException;
use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\RecoveryCodes;
use OpenSendForm\Config;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Version;
use Throwable;

/**
 * Orchestrates the mechanics of installation, free of any HTTP or session
 * concern (the controller owns those). Each public method is one step the
 * wizard drives:
 *
 *   prepareDatabase() → validate the DB choice into a normalised config
 *   connect()         → open it (the live MySQL test), throwing friendly on failure
 *   migrate()         → apply the schema against the chosen DB
 *   createAdmin()     → create the first administrator via the existing repo
 *   commit()          → atomically write var/config.php then var/install.lock
 *
 * The commit is the single point that makes the install "installed": config
 * and lock are written only there, and if the lock write fails the just-written
 * config is removed, so a failure never leaves a half-installed state. A DB
 * that has been migrated (or an admin row created) before an abandoned install
 * is harmless, idempotent residue.
 *
 * Every failure surfaces as an InstallerException carrying a plain-language,
 * safe-to-display message — never a raw driver string.
 */
final class InstallerService
{
    private const MIN_PASSWORD_LENGTH = 12;

    public function __construct(
        private Paths $paths,
        private DbConnector $connector,
        private PasswordHasher $hasher,
        private RecoveryCodes $recovery
    ) {
    }

    /**
     * Validate and normalise the database choice into a config array:
     *   ['driver', 'dsn', 'user', 'pass', 'summary'].
     *
     * SQLite: a fixed file under var/data/, whose folder must be writable.
     * MySQL: host / port / dbname / user / password, assembled into a DSN.
     * No connection is opened here — call connect() for the live test.
     *
     * @param array<string, mixed> $input
     * @return array{driver:string, dsn:string, user:string, pass:string, summary:string}
     *
     * @throws InstallerException on an invalid or incomplete choice.
     */
    public function prepareDatabase(array $input): array
    {
        $driver = (string) ($input['db_driver'] ?? 'sqlite');

        if ($driver === 'sqlite') {
            return $this->prepareSqlite();
        }
        if ($driver === 'mysql') {
            return $this->prepareMysql($input);
        }

        throw new InstallerException('Please choose either the built-in SQLite database or MySQL.');
    }

    /**
     * @return array{driver:string, dsn:string, user:string, pass:string, summary:string}
     */
    private function prepareSqlite(): array
    {
        $dir = $this->paths->dataDir;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new InstallerException(
                'The database folder (var/data) is not writable, so the built-in database '
                . 'cannot be created. Set its permissions to 0755 (or 0775) and try again.'
            );
        }

        $dsn = 'sqlite:' . $dir . '/opensendform.sqlite';

        return [
            'driver'  => 'sqlite',
            'dsn'     => $dsn,
            'user'    => '',
            'pass'    => '',
            'summary' => 'Built-in database (SQLite) — nothing else to set up.',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{driver:string, dsn:string, user:string, pass:string, summary:string}
     */
    private function prepareMysql(array $input): array
    {
        $host = trim((string) ($input['db_host'] ?? ''));
        $portRaw = trim((string) ($input['db_port'] ?? ''));
        $name = trim((string) ($input['db_name'] ?? ''));
        $user = trim((string) ($input['db_user'] ?? ''));
        $pass = (string) ($input['db_pass'] ?? '');

        if ($host === '' || $name === '' || $user === '') {
            throw new InstallerException(
                'Please fill in the MySQL host, database name and username. Your host '
                . 'gives you these when you create a database in cPanel.'
            );
        }

        $port = $portRaw === '' ? 3306 : (int) $portRaw;
        if ($portRaw !== '' && (!ctype_digit($portRaw) || $port < 1 || $port > 65535)) {
            throw new InstallerException('The MySQL port must be a number (the usual value is 3306).');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $name
        );

        return [
            'driver'  => 'mysql',
            'dsn'     => $dsn,
            'user'    => $user,
            'pass'    => $pass,
            'summary' => 'MySQL database "' . $name . '" on ' . $host . '.',
        ];
    }

    /**
     * Open the chosen database — the live connection test. For MySQL this is
     * the real check that the credentials work; for SQLite it creates/opens the
     * file. Any failure becomes a friendly InstallerException.
     *
     * @param array{driver:string, dsn:string, user:string, pass:string, summary:string} $dbConfig
     *
     * @throws InstallerException when the connection cannot be opened.
     */
    public function connect(array $dbConfig): Database
    {
        try {
            return $this->connector->connect(
                $dbConfig['dsn'],
                $dbConfig['user'] === '' ? null : $dbConfig['user'],
                $dbConfig['pass'] === '' ? null : $dbConfig['pass']
            );
        } catch (Throwable $e) {
            if ($dbConfig['driver'] === 'mysql') {
                throw new InstallerException(
                    'Could not connect to the MySQL database. Please double-check the host, '
                    . 'port, database name, username and password, then try again.'
                );
            }

            throw new InstallerException(
                'Could not open the built-in database file. Check that the var/data folder '
                . 'is writable and try again.'
            );
        }
    }

    /**
     * Apply all pending migrations against the chosen database. Idempotent:
     * re-running on an already-migrated database does nothing.
     */
    public function migrate(Database $db): void
    {
        (new MigrationRunner($db, $this->paths->migrationsPath))->migrate();
    }

    /**
     * Create the first administrator via the existing repository. Validates a
     * usable email, a non-empty display name and a password of at least 12
     * characters entered twice.
     *
     * @return array<string, mixed> The created admin.
     *
     * @throws InstallerException on any validation problem or a duplicate email.
     */
    public function createAdmin(
        Database $db,
        string $email,
        string $displayName,
        string $password,
        string $passwordConfirm
    ): array {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InstallerException('Please enter a valid email address for the administrator.');
        }
        if (trim($displayName) === '') {
            throw new InstallerException('Please enter a name for the administrator.');
        }
        if ($password !== $passwordConfirm) {
            throw new InstallerException('The two passwords do not match. Please type the same password twice.');
        }
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InstallerException(
                'The administrator password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters long.'
            );
        }

        $repo = new AdminRepository($db, $this->hasher, $this->recovery);
        if ($repo->findByEmail($email) !== null) {
            throw new InstallerException('An administrator with that email address already exists.');
        }

        try {
            return $repo->createAdmin($email, trim($displayName), $password);
        } catch (InvalidArgumentException $e) {
            // Repository-level validation as a backstop; keep the message friendly.
            throw new InstallerException('Please check the administrator details and try again.');
        }
    }

    /**
     * The final commit: write var/config.php then var/install.lock, both
     * atomically. If the lock write fails, the just-written config is removed
     * so no partial install remains. After this returns, Paths::isInstalled()
     * is true.
     *
     * @param array{driver:string, dsn:string, user:string, pass:string, summary:string} $dbConfig
     * @param array<string, string> $extra Additional config key => value pairs
     *        gathered by the wizard: the SMTP settings + MAIL_ENABLED from the
     *        email step (absent when it was skipped) and MONITOR_BASE_URL
     *        captured from the request. These override the base defaults.
     *
     * @throws InstallerException if either file cannot be written.
     */
    public function commit(array $dbConfig, array $extra = []): void
    {
        $configWritten = false;
        try {
            $this->atomicWrite($this->paths->configPath, $this->renderConfig($dbConfig, $extra));
            $configWritten = true;
            $this->atomicWrite($this->paths->lockPath, $this->renderLock());
        } catch (Throwable $e) {
            if ($configWritten && is_file($this->paths->configPath)) {
                @unlink($this->paths->configPath);
            }
            if ($e instanceof InstallerException) {
                throw $e;
            }
            throw new InstallerException('Could not save the configuration. Check that the var folder is writable.');
        }
    }

    // --- File rendering ---------------------------------------------------

    /**
     * @param array{driver:string, dsn:string, user:string, pass:string, summary:string} $dbConfig
     * @param array<string, string> $extra Wizard-gathered overrides (mail settings,
     *        MONITOR_BASE_URL) layered over the base values.
     */
    private function renderConfig(array $dbConfig, array $extra = []): string
    {
        $values = [
            'APP_ENV'      => 'production',
            'APP_SECRET'   => Config::generateSecret(),
            'DB_DSN'       => $dbConfig['dsn'],
            'DB_USER'      => $dbConfig['user'],
            'DB_PASS'      => $dbConfig['pass'],
            // Email sending stays off unless the email step configured it; the
            // step (or the admin panel later) sets MAIL_ENABLED via $extra.
            'MAIL_ENABLED' => '0',
        ];

        // Only recognised config keys are honoured, so a stray posted key can
        // never leak into the file.
        $allowed = array_keys(Config::defaults());
        foreach ($extra as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $values[$key] = (string) $value;
            }
        }

        $lines = '';
        foreach ($values as $key => $value) {
            $lines .= '    ' . var_export($key, true) . ' => ' . var_export((string) $value, true) . ",\n";
        }

        return "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "/*\n"
            . " * OpenSendForm configuration — written by the browser installer.\n"
            . " *\n"
            . " * This file returns the installed settings. It lives under var/ (outside the\n"
            . " * web root) and is never served, but treat it as private regardless.\n"
            . " *\n"
            . " * Environment variables OVERRIDE every value here (see Config::load()), which\n"
            . " * is how the development container supplies its own settings. To change a\n"
            . " * value on a normal install, edit it below.\n"
            . " *\n"
            . " * When MAIL_ENABLED is 0 submissions are stored but no email is sent; turn\n"
            . " * sending on from the admin panel (Email) to start delivering.\n"
            . " */\n\n"
            . "return [\n"
            . $lines
            . "];\n";
    }

    private function renderLock(): string
    {
        return json_encode(
            [
                'installed_at' => gmdate('Y-m-d H:i:s'),
                'version'      => Version::STRING,
            ],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    /**
     * Write a file atomically: a temp file in the same directory, permissions
     * tightened to 0600 where the host honours it, then renamed into place so a
     * reader never sees a half-written file.
     *
     * @throws InstallerException when the target directory is not writable.
     */
    private function atomicWrite(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new InstallerException('The folder "' . $dir . '" is not writable.');
        }

        $tmp = @tempnam($dir, '.osf-');
        // tempnam falls back to the system temp dir on failure; reject anything
        // not landing in our target directory so the rename stays atomic.
        if ($tmp === false || dirname($tmp) !== rtrim($dir, '/')) {
            if (is_string($tmp) && is_file($tmp)) {
                @unlink($tmp);
            }
            throw new InstallerException('Could not create a temporary file in "' . $dir . '".');
        }

        if (@file_put_contents($tmp, $content) === false) {
            @unlink($tmp);
            throw new InstallerException('Could not write to "' . $path . '".');
        }

        @chmod($tmp, 0600);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new InstallerException('Could not save "' . $path . '".');
        }
    }
}
