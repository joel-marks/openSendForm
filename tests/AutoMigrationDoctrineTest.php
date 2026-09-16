<?php

declare(strict_types=1);

namespace OpenSendForm\Tests;

use OpenSendForm\AppFactory;
use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\RecoveryCodes;
use OpenSendForm\Config;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Tests\Support\FakeSession;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Locks the migration doctrine (hardening sweep, Task 5):
 *
 *  - A WEB request never applies a migration. Against a database one version
 *    behind the shipped code, every web request still serves; the dashboard
 *    shows the "run bin/osf migrate" banner; and the schema is left UNCHANGED
 *    (the pending migration is still pending afterwards). Only the installer
 *    and `bin/osf migrate` are allowed to migrate on the web/explicit side.
 *
 *  - A CLI command DOES auto-migrate at boot (except install:status, migrate
 *    and version, which are handled before the boot migrate). This is the
 *    intended standing behaviour, so it is confirmed and locked here rather
 *    than changed: a plain command against a stale database advances the
 *    schema to current as a side effect of booting.
 */
final class AutoMigrationDoctrineTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';

    // --- Web side: never migrates ----------------------------------------

    public function testWebRequestsServeButNeverMigrate(): void
    {
        // A database migrated only up to version 10 — migration 011 has shipped
        // in code but has NOT been applied here.
        $db = Database::connect('sqlite::memory:');
        $this->migrateUpToVersion($db, 10);
        self::assertSame(10, $this->schemaVersion($db), 'fixture starts one version behind');

        $session = new FakeSession();
        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        $admins = new AdminRepository($db, $hasher, new RecoveryCodes($hasher));
        $admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);

        $config = Config::fromValues(['APP_ENV' => 'dev', 'APP_SECRET' => 'auto-migrate-doctrine']);
        $app = AppFactory::create($config, $db, null, null, null, null, $session);

        // An unauthenticated public API request serves without migrating.
        $health = $this->get($app, '/health');
        self::assertSame(200, $health->getStatusCode());
        self::assertSame(10, $this->schemaVersion($db), 'public request must not migrate');

        // Log in and load the dashboard — the one web surface that inspects the
        // pending count. It serves, shows the banner, and still does not migrate.
        $csrf = $this->csrfFrom($this->get($app, '/admin/login'));
        $app->handle($this->request('POST', '/admin/login')->withParsedBody([
            '_csrf'    => $csrf,
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]));

        $dashboard = $this->get($app, '/admin');
        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('Database update required', (string) $dashboard->getBody());
        self::assertStringContainsString('bin/osf migrate', (string) $dashboard->getBody());

        // The decisive assertion: after every one of those web requests the
        // schema is untouched — migration 011 is still pending.
        self::assertSame(10, $this->schemaVersion($db), 'dashboard must not migrate');
        $columns = array_column($db->fetchAll('PRAGMA table_info(admins)'), 'name');
        self::assertNotContains('totp_last_timestep', $columns, 'migration 011 must not have run');
    }

    // --- CLI side: auto-migrates at boot ---------------------------------

    public function testCliCommandAutoMigratesAStaleDatabaseAtBoot(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'osf_automig_');
        self::assertNotFalse($dbPath);

        try {
            $db = Database::connect('sqlite:' . $dbPath);
            $this->migrateUpToVersion($db, 10);
            self::assertSame(10, $this->schemaVersion($db), 'fixture starts one version behind');
            unset($db);

            // A plain, non-migrate command booting against the stale database.
            $result = $this->osf(['form:list'], $dbPath);
            self::assertSame(0, $result['code'], $result['stderr']);

            // Booting applied the pending migration: the schema is now current.
            $after = Database::connect('sqlite:' . $dbPath);
            self::assertSame(11, $this->schemaVersion($after), 'CLI boot must auto-migrate');
            $columns = array_column($after->fetchAll('PRAGMA table_info(admins)'), 'name');
            self::assertContains('totp_last_timestep', $columns, 'migration 011 ran at CLI boot');
        } finally {
            @unlink($dbPath);
        }
    }

    // --- Helpers ----------------------------------------------------------

    private function schemaVersion(Database $db): int
    {
        $applied = (new MigrationRunner($db, dirname(__DIR__) . '/migrations'))->appliedVersions();

        return $applied === [] ? 0 : max($applied);
    }

    /**
     * Apply only migrations 1..$version by copying just those numbered files to
     * a temp directory and running a MigrationRunner over it. The real
     * migrations still ship, so the app's own (unrestricted) runner sees the
     * later ones as pending — the stale-install condition under test.
     */
    private function migrateUpToVersion(Database $db, int $version): void
    {
        $realMigrations = dirname(__DIR__) . '/migrations';
        $partial = sys_get_temp_dir() . '/osf_automig_fixture_' . bin2hex(random_bytes(6));
        mkdir($partial, 0775, true);

        try {
            foreach (glob($realMigrations . '/*.sql') ?: [] as $file) {
                $name = basename($file);
                if (preg_match('/^(\d+)/', $name, $m) && (int) $m[1] <= $version) {
                    copy($file, $partial . '/' . $name);
                }
            }

            (new MigrationRunner($db, $partial))->migrate();
        } finally {
            foreach (glob($partial . '/*.sql') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($partial);
        }
    }

    private function get(App $app, string $path): ResponseInterface
    {
        return $app->handle($this->request('GET', $path));
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest(
            $method,
            $path,
            ['REMOTE_ADDR' => '203.0.113.5']
        );
    }

    private function csrfFrom(ResponseInterface $response): string
    {
        $matched = preg_match('/name="_csrf" value="([a-f0-9]+)"/', (string) $response->getBody(), $m);
        self::assertSame(1, $matched, 'Expected a CSRF token in the response body.');

        return $m[1];
    }

    /**
     * Shell out to bin/osf with a DB_DSN pointing at the given SQLite file.
     *
     * @param array<int, string> $args
     * @return array{code:int, stdout:string, stderr:string}
     */
    private function osf(array $args, string $dbPath): array
    {
        $osf = dirname(__DIR__) . '/bin/osf';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($osf);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = getenv();
        $env['DB_DSN'] = 'sqlite:' . $dbPath;
        $env['XDEBUG_MODE'] = 'off';

        $process = proc_open($cmd, $descriptors, $pipes, null, $env);
        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
