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
 * The dashboard's synthetic-monitoring banner: shown, naming the form(s),
 * whenever a form's LATEST check failed; absent once a later check passes.
 */
final class DashboardMonitorBannerTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';

    private Database $db;
    private int $formId;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $form = (new FormRepository($this->db))->createForm('Careers', 'jobs@example.com', ['https://example.com']);
        $this->formId = (int) $form['id'];
    }

    public function testBannerAbsentWhenNoFailingChecks(): void
    {
        $checks = new MonitorRepository($this->db);
        $checks->record($this->formId, '2026-09-09 10:00:00', true, 'delivered');

        $body = (string) $this->dashboard()->getBody();

        self::assertStringNotContainsString('Synthetic monitoring detected a problem', $body);
    }

    public function testBannerNamesTheFailingForm(): void
    {
        $checks = new MonitorRepository($this->db);
        $checks->record($this->formId, '2026-09-09 10:00:00', false, 'submit returned HTTP 500');

        $body = (string) $this->dashboard()->getBody();

        self::assertStringContainsString('Synthetic monitoring detected a problem', $body);
        self::assertStringContainsString('Careers', $body);
    }

    public function testAPassingCheckClearsTheBanner(): void
    {
        $checks = new MonitorRepository($this->db);
        $checks->record($this->formId, '2026-09-09 10:00:00', false, 'boom');
        $checks->record($this->formId, '2026-09-09 11:00:00', true, 'delivered');

        $body = (string) $this->dashboard()->getBody();

        self::assertStringNotContainsString('Synthetic monitoring detected a problem', $body);
    }

    // --- Harness (mirrors DashboardStaleSchemaTest) -----------------------

    private function dashboard(): ResponseInterface
    {
        $session = new FakeSession();
        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        $admins = new AdminRepository($this->db, $hasher, new RecoveryCodes($hasher));
        $admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);

        $config = Config::fromValues(['APP_ENV' => 'dev', 'APP_SECRET' => 'dashboard-monitor-secret']);
        $app = AppFactory::create($config, $this->db, null, null, null, null, $session);

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
