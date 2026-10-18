<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Admin;

use OpenSendForm\AppFactory;
use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\RecoveryCodes;
use OpenSendForm\Config;
use OpenSendForm\Install\Paths;
use OpenSendForm\Monitor\MonitorRepository;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Tests\Support\FakeDnsResolver;
use OpenSendForm\Tests\Support\FakeMailer;
use OpenSendForm\Tests\Support\FakeSession;
use OpenSendForm\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * HTTP-level coverage for the Status tab: the live environment re-probe, the
 * security section (2FA + world-writable dirs), the shared mail deliverability
 * component and the operational readout (monitor last-run / stale-cron). Driven
 * through the app factory against an in-memory DB, an array-backed session and
 * a scriptable fake resolver so no lookup ever hits the network.
 */
final class StatusHttpTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';
    private const ADMIN_EMAIL = 'boss@acme.co';

    private string $base;
    private string $configPath;
    private Database $db;
    private FakeSession $session;
    private FixedClock $clock;
    private FakeMailer $mailer;
    private FakeDnsResolver $dns;
    private Paths $paths;
    private App $app;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/osf-status-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/var', 0o775, true);
        $this->configPath = $this->base . '/var/config.php';
        file_put_contents($this->base . '/var/install.lock', '{"installed_at":"2026-08-25 00:00:00","version":"x"}');
        $this->writeConfig([
            'APP_ENV'           => 'dev',
            'APP_SECRET'        => str_repeat('a', 64),
            'SMTP_HOST'         => 'mail.acme.co',
            'MAIL_FROM_ADDRESS' => 'hello@acme.co',
            'MAIL_FROM_NAME'    => 'Acme',
            'MAIL_ENABLED'      => '1',
        ]);
        $this->paths = Paths::underBase($this->base);

        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $this->session = new FakeSession();
        $this->clock = new FixedClock(1_700_000_000);
        $this->mailer = new FakeMailer();
        $this->dns = new FakeDnsResolver();

        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        (new AdminRepository($this->db, $hasher, new RecoveryCodes($hasher)))
            ->createAdmin(self::ADMIN_EMAIL, 'The Boss', self::PASSWORD);

        $this->buildApp();
    }

    protected function tearDown(): void
    {
        @chmod($this->base . '/var', 0o775);
        @unlink($this->configPath);
        @unlink($this->base . '/var/install.lock');
        @rmdir($this->base . '/var');
        @rmdir($this->base);
    }

    private function buildApp(): void
    {
        $config = Config::load($this->configPath, []);
        $this->app = AppFactory::create(
            $config,
            $this->db,
            null,
            $this->clock,
            $this->mailer,
            null,
            $this->session,
            $this->paths,
            $this->dns
        );
    }

    public function testNavIncludesStatusLinkRightmost(): void
    {
        $this->login();
        $body = (string) $this->get('/admin')->getBody();
        self::assertStringContainsString('href="/admin/status"', $body);
        // The Status tab is the last tab in the strip.
        $statusPos = strpos($body, 'href="/admin/status"');
        $adminsPos = strpos($body, 'href="/admin/admins"');
        self::assertIsInt($statusPos);
        self::assertIsInt($adminsPos);
        self::assertGreaterThan($adminsPos, $statusPos);
    }

    public function testEnvironmentProbeRowsRender(): void
    {
        $this->login();
        $body = (string) $this->get('/admin/status')->getBody();
        // The reused Requirements matrix and the extra DB-reachability row.
        self::assertStringContainsString('need 8.1.0 or newer', $body);
        self::assertStringContainsString('Database reachable (sqlite)', $body);
        self::assertStringContainsString('osf-badge--ok', $body);
    }

    public function testTwoFactorOffShowsRedWarningWithSetupLink(): void
    {
        $this->login();
        $body = (string) $this->get('/admin/status')->getBody();
        self::assertStringContainsString('osf-flash--error', $body);
        self::assertStringContainsString('Two-factor authentication is OFF', $body);
        self::assertStringContainsString('href="/admin/totp/setup"', $body);
    }

    public function testTwoFactorOnShowsNoWarning(): void
    {
        // Sign in first (the TOTP gate is at login), then mark 2FA on so the
        // live re-probe sees an enrolled account on the next view.
        $this->login();
        $this->db->execute('UPDATE admins SET totp_enabled = 1 WHERE email = :e', ['e' => self::ADMIN_EMAIL]);
        $body = (string) $this->get('/admin/status')->getBody();
        self::assertStringContainsString('Two-factor authentication is on', $body);
        self::assertStringNotContainsString('Two-factor authentication is OFF', $body);
    }

    public function testWorldWritableDirectoryRendersRed(): void
    {
        // Make a probed app directory world-writable; the probe must flag it red.
        chmod($this->base . '/var', 0o777);
        $this->login();
        $body = (string) $this->get('/admin/status')->getBody();
        self::assertStringContainsString('World-writable application directories found', $body);
        self::assertStringContainsString($this->base . '/var', $body);
    }

    public function testStaleMonitorRendersRed(): void
    {
        // A check far older than 2x the hourly cadence relative to the fixed clock.
        $old = gmdate('Y-m-d H:i:s', 1_700_000_000 - 100_000);
        (new MonitorRepository($this->db))->record(1, $old, true, 'ok');
        $this->login();

        $body = (string) $this->get('/admin/status')->getBody();
        self::assertStringContainsString('Monitoring has not run recently', $body);
        self::assertStringContainsString($old, $body);
    }

    public function testFreshMonitorIsNotStale(): void
    {
        $fresh = gmdate('Y-m-d H:i:s', 1_700_000_000 - 60);
        (new MonitorRepository($this->db))->record(1, $fresh, true, 'ok');
        $this->login();

        $body = (string) $this->get('/admin/status')->getBody();
        self::assertStringNotContainsString('Monitoring has not run recently', $body);
        self::assertStringContainsString('Passed', $body);
    }

    public function testMailSectionSharesDeliverabilityComponent(): void
    {
        $this->dns->setTxt('acme.co', ['v=spf1 include:_spf.acme.co ~all']);
        $this->login();
        $body = (string) $this->get('/admin/status')->getBody();
        // The same deliverability readout the Deliverability tab renders.
        self::assertStringContainsString('Source: live DNS lookup of the', $body);
        self::assertStringContainsString('v=DMARC1; p=none; rua=mailto:postmaster@acme.co', $body);
        // Mail configured / sending summary.
        self::assertStringContainsString('SMTP configured', $body);
        self::assertStringContainsString('Sending', $body);
    }

    public function testDeliverabilityMarkupHasASingleSource(): void
    {
        // The results markup lives only in the shared partial; both pages include it.
        $dir = dirname(__DIR__, 2) . '/templates/admin';
        $partial = (string) file_get_contents($dir . '/_deliverability.php');
        $tab = (string) file_get_contents($dir . '/deliverability.php');
        $status = (string) file_get_contents($dir . '/status.php');

        $marker = 'Source: live DNS lookup of the';
        self::assertStringContainsString($marker, $partial, 'The partial owns the results markup.');
        self::assertStringNotContainsString($marker, $tab, 'The tab must not duplicate the markup.');
        self::assertStringNotContainsString($marker, $status, 'The Status page must not duplicate the markup.');

        self::assertStringContainsString("_deliverability.php", $tab, 'The tab includes the shared partial.');
        self::assertStringContainsString("_deliverability.php", $status, 'The Status page includes the shared partial.');
    }

    // --- Harness ----------------------------------------------------------

    /**
     * @param array<string, string> $values
     */
    private function writeConfig(array $values): void
    {
        $lines = '';
        foreach ($values as $k => $v) {
            $lines .= '    ' . var_export($k, true) . ' => ' . var_export((string) $v, true) . ",\n";
        }
        file_put_contents($this->configPath, "<?php\nreturn [\n{$lines}];\n");
    }

    private function login(): void
    {
        $csrf = $this->csrfFrom($this->get('/admin/login'));
        $this->post('/admin/login', [
            '_csrf'    => $csrf,
            'email'    => self::ADMIN_EMAIL,
            'password' => self::PASSWORD,
        ]);
    }

    /**
     * @param array<string, string> $query
     */
    private function get(string $path, array $query = []): ResponseInterface
    {
        $request = $this->request('GET', $path);
        if ($query !== []) {
            $request = $request->withQueryParams($query);
        }

        return $this->app->handle($request);
    }

    /**
     * @param array<string, string> $body
     */
    private function post(string $path, array $body): ResponseInterface
    {
        return $this->app->handle($this->request('POST', $path)->withParsedBody($body));
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
}
