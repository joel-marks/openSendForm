<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Admin;

use OpenSendForm\AppFactory;
use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\RecoveryCodes;
use OpenSendForm\Config;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\Monitor\MonitorRepository;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Tests\Support\FakeSession;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The dashboard's scheduled-tasks (cron) reminder banner (Task 3d): shown while
 * the installer choice is still "later" AND no monitor run has been observed;
 * cleared once a monitor check exists (evidence the cron ran) or the operator
 * marks the tasks done (CRON_SETUP=done). Legacy installs (no choice recorded)
 * are never nagged.
 */
final class DashboardCronBannerTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';
    private const MARKER = 'Scheduled tasks aren’t set up yet';

    private Database $db;
    private int $formId;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $form = (new FormRepository($this->db))->createForm('Contact', 'c@example.com', ['https://example.com']);
        $this->formId = (int) $form['id'];
    }

    public function testBannerShownWhileDeferredAndNoMonitorRun(): void
    {
        $body = (string) $this->dashboard('later')->getBody();
        self::assertStringContainsString(self::MARKER, $body);
        self::assertStringContainsString('/admin/mail#cron', $body);
    }

    public function testBannerClearedOnceAMonitorCheckExists(): void
    {
        // A recorded check is evidence the monitor cron actually ran.
        (new MonitorRepository($this->db))->record($this->formId, '2026-09-20 10:00:00', true, 'delivered');

        $body = (string) $this->dashboard('later')->getBody();
        self::assertStringNotContainsString(self::MARKER, $body);
    }

    public function testBannerAbsentWhenMarkedDone(): void
    {
        $body = (string) $this->dashboard('done')->getBody();
        self::assertStringNotContainsString(self::MARKER, $body);
    }

    public function testBannerAbsentForLegacyInstallWithNoChoice(): void
    {
        $body = (string) $this->dashboard('')->getBody();
        self::assertStringNotContainsString(self::MARKER, $body);
    }

    // --- Harness -----------------------------------------------------------

    private function dashboard(string $cronSetup): ResponseInterface
    {
        $session = new FakeSession();
        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        (new AdminRepository($this->db, $hasher, new RecoveryCodes($hasher)))
            ->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);

        $config = Config::fromValues([
            'APP_ENV'    => 'dev',
            'APP_SECRET' => 'dashboard-cron-secret',
            'CRON_SETUP' => $cronSetup,
        ]);
        $app = AppFactory::create($config, $this->db, null, null, null, null, $session);

        $csrf = $this->csrfFrom($this->get($app, '/admin/login'));
        $app->handle($this->request('POST', '/admin/login')->withParsedBody([
            '_csrf'    => $csrf,
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]));

        return $this->get($app, '/admin');
    }

    private function get(App $app, string $path): ResponseInterface
    {
        return $app->handle($this->request('GET', $path));
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '203.0.113.5']);
    }

    private function csrfFrom(ResponseInterface $response): string
    {
        $matched = preg_match('/name="_csrf" value="([a-f0-9]+)"/', (string) $response->getBody(), $m);
        self::assertSame(1, $matched, 'Expected a CSRF token in the response body.');

        return $m[1];
    }
}
