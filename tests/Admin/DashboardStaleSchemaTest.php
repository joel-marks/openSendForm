<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Admin;

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
 * The dashboard's red schema-staleness banner: a cheap pending-migrations
 * count, surfaced only here, with no auto-migration on any web request. This
 * closes the live incident where migration 009 shipped but an already-
 * installed database silently kept running the old schema.
 */
final class DashboardStaleSchemaTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';

    public function testBannerAbsentOnACurrentSchema(): void
    {
        $db = Database::connect('sqlite::memory:');
        (new MigrationRunner($db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $body = (string) $this->dashboard($db)->getBody();

        self::assertStringNotContainsString('Database update required', $body);
    }

    public function testBannerPresentWithAStaleFixtureDatabase(): void
    {
        // One migration behind (010 shipped in code, not yet applied here).
        $db = Database::connect('sqlite::memory:');
        $this->migrateUpToVersion($db, 9);

        $body = (string) $this->dashboard($db)->getBody();

        self::assertStringContainsString('Database update required', $body);
        self::assertStringContainsString('bin/osf migrate', $body);
        self::assertStringContainsString('1 pending migration', $body);
    }

    /**
     * Build a database with only migrations 1..$version applied, by pointing
     * a MigrationRunner at a temp directory holding copies of just those
     * numbered migration files — the real migrations still ship for the
     * dashboard's own (unrestricted) runner to see as pending.
     */
    private function migrateUpToVersion(Database $db, int $version): void
    {
        $realMigrations = dirname(__DIR__, 2) . '/migrations';
        $partial = sys_get_temp_dir() . '/osf_dash_fixture_' . bin2hex(random_bytes(6));
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

    private function dashboard(Database $db): ResponseInterface
    {
        $session = new FakeSession();
        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        $admins = new AdminRepository($db, $hasher, new RecoveryCodes($hasher));
        $admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);

        $config = Config::fromValues(['APP_ENV' => 'dev', 'APP_SECRET' => 'dashboard-schema-secret']);
        $app = AppFactory::create($config, $db, null, null, null, null, $session);

        $csrf = $this->csrfFrom($this->get($app, '/admin/login'));
        $app->handle(
            $this->request('POST', '/admin/login')->withParsedBody([
                '_csrf'    => $csrf,
                'email'    => 'boss@example.com',
                'password' => self::PASSWORD,
            ])
        );

        return $this->get($app, '/admin');
    }

    private function get(App $app, string $path): ResponseInterface
    {
        return $app->handle($this->request('GET', $path));
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path);
    }

    private function csrfFrom(ResponseInterface $response): string
    {
        $matched = preg_match('/name="_csrf" value="([a-f0-9]+)"/', (string) $response->getBody(), $m);
        self::assertSame(1, $matched, 'Expected a CSRF token in the response body.');

        return $m[1];
    }
}
