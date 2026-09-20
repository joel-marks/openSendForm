<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Admin;

use OpenSendForm\AppFactory;
use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\RecoveryCodes;
use OpenSendForm\Config;
use OpenSendForm\Install\Paths;
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
 * HTTP-level coverage for the Deliverability tab (SPF/DKIM/DMARC checker for the
 * sending domain), split out of the Email page this sprint. Driven through the
 * app factory against an in-memory DB, an array-backed session and a scriptable
 * fake DNS resolver so no lookup ever hits the network.
 */
final class DeliverabilityHttpTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';
    private const ADMIN_EMAIL = 'boss@example.com';

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
        $this->base = sys_get_temp_dir() . '/osf-deliver-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/var', 0775, true);
        $this->configPath = $this->base . '/var/config.php';
        file_put_contents($this->base . '/var/install.lock', '{"installed_at":"2026-08-25 00:00:00","version":"x"}');
        $this->writeConfig([
            'APP_ENV'           => 'dev',
            'APP_SECRET'        => str_repeat('a', 64),
            'SMTP_HOST'         => 'mail.example.com',
            'MAIL_FROM_ADDRESS' => 'hello@example.com',
            'MAIL_FROM_NAME'    => 'Example',
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

    public function testNavIncludesDeliverabilityLink(): void
    {
        $this->login();
        $body = (string) $this->get('/admin')->getBody();
        self::assertStringContainsString('href="/admin/deliverability"', $body);
        self::assertStringContainsString('Deliverability', $body);
    }

    public function testRendersStatesAndRecommendations(): void
    {
        // SPF present; DKIM (default selector) and DMARC absent.
        $this->dns->setTxt('example.com', ['v=spf1 include:_spf.example.com ~all']);
        $this->login();

        $body = (string) $this->get('/admin/deliverability')->getBody();
        self::assertStringContainsString('SPF', $body);
        self::assertStringContainsString('Published', $body);
        self::assertStringContainsString('Not found', $body);
        // DMARC recommended starter seeded with the admin email, with a copy button.
        self::assertStringContainsString('v=DMARC1; p=none; rua=mailto:' . self::ADMIN_EMAIL, $body);
        self::assertStringContainsString('data-copy="v=DMARC1; p=none; rua=mailto:' . self::ADMIN_EMAIL . '"', $body);
    }

    public function testEachResultNamesItsSource(): void
    {
        $this->login();
        $body = (string) $this->get('/admin/deliverability')->getBody();
        // Every check states it is a live DNS lookup of a named record.
        self::assertStringContainsString('Source: live DNS lookup of the', $body);
        self::assertStringContainsString('_dmarc.example.com', $body);
    }

    public function testStatesRecordsConcernSendingDomainOnly(): void
    {
        $this->login();
        $body = (string) $this->get('/admin/deliverability')->getBody();
        self::assertStringContainsString('sending domain only', $body);
        self::assertStringContainsString('Recipients are unlimited', $body);
    }

    public function testRecheckHonoursSelector(): void
    {
        $this->dns->setTxt('s1._domainkey.example.com', ['v=DKIM1; k=rsa; p=abc']);
        $this->login();

        $body = (string) $this->get('/admin/deliverability', ['dkim_selector' => 's1'])->getBody();
        self::assertStringContainsString('value="s1"', $body);
        self::assertStringContainsString('v=DKIM1; k=rsa; p=abc', $body);
    }

    public function testInvalidFromAddressShowsPrompt(): void
    {
        $this->writeConfig(['APP_SECRET' => 'x', 'MAIL_FROM_ADDRESS' => 'noreply@localhost', 'MAIL_FROM_NAME' => 'E']);
        $this->buildApp();
        $this->login();

        $body = (string) $this->get('/admin/deliverability')->getBody();
        self::assertStringContainsString('set a valid From address', $body);
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
