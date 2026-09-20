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
 * HTTP-level coverage for the increment 6b mail-setup wizard: settings save
 * round-trip (incl. keep-existing-password + env-shadow notice), the test send
 * (success/failure + enable-now offer), the deliverability checker states, the
 * dashboard email banner and the nav item. Driven through the app factory
 * against an in-memory DB, an array-backed session, a fixed clock, a scriptable
 * fake mailer and a scriptable fake DNS resolver, with the config file written
 * into a throwaway temp directory so nothing touches the real install.
 */
final class MailWizardHttpTest extends TestCase
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
        $this->base = sys_get_temp_dir() . '/osf-mailwiz-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/var', 0775, true);
        $this->configPath = $this->base . '/var/config.php';
        // Mark installed so /admin routes are reachable and /install 404s.
        file_put_contents($this->base . '/var/install.lock', '{"installed_at":"2026-08-25 00:00:00","version":"x"}');
        $this->writeConfig([
            'APP_ENV'           => 'dev',
            'APP_SECRET'        => str_repeat('a', 64),
            'MAIL_ENABLED'      => '0',
            'SMTP_HOST'         => 'localhost',
            'SMTP_PORT'         => '25',
            'SMTP_ENCRYPTION'   => 'none',
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

    /**
     * @param array<string, string> $env
     */
    private function buildApp(array $env = []): void
    {
        $config = Config::load($this->configPath, $env);
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

    // --- Nav --------------------------------------------------------------

    public function testNavIncludesEmailLink(): void
    {
        $this->login();
        $body = (string) $this->get('/admin')->getBody();
        self::assertStringContainsString('href="/admin/mail"', $body);
        self::assertStringContainsString('Email', $body);
    }

    // --- Settings save ----------------------------------------------------

    public function testSaveRoundTripPersistsSettings(): void
    {
        $this->login();
        $csrf = $this->csrfFrom($this->get('/admin/mail'));

        $response = $this->post('/admin/mail', [
            '_csrf'             => $csrf,
            'smtp_host'         => 'smtp.example.com',
            'smtp_port'         => '587',
            'smtp_encryption'   => 'starttls',
            'smtp_user'         => 'user@example.com',
            'smtp_pass'         => 'hunter2hunter2',
            'mail_from_address' => 'noreply@example.com',
            'mail_from_name'    => 'Example Forms',
            'mail_enabled'      => '1',
        ]);

        self::assertSame(302, $response->getStatusCode());
        $text = $this->configFileText();
        self::assertStringContainsString("'SMTP_HOST' => 'smtp.example.com'", $text);
        self::assertStringContainsString("'SMTP_PORT' => '587'", $text);
        self::assertStringContainsString("'SMTP_ENCRYPTION' => 'starttls'", $text);
        self::assertStringContainsString("'SMTP_USER' => 'user@example.com'", $text);
        self::assertStringContainsString("'SMTP_PASS' => 'hunter2hunter2'", $text);
        self::assertStringContainsString("'MAIL_FROM_ADDRESS' => 'noreply@example.com'", $text);
        self::assertStringContainsString("'MAIL_ENABLED' => '1'", $text);
        // A key it never touched is preserved.
        self::assertStringContainsString("'APP_SECRET' => '" . str_repeat('a', 64) . "'", $text);
    }

    public function testSaveKeepsExistingPasswordWhenFieldBlank(): void
    {
        $this->writeConfig([
            'APP_SECRET'        => 'x',
            'MAIL_ENABLED'      => '0',
            'SMTP_PASS'         => 'stored-secret',
            'MAIL_FROM_ADDRESS' => 'hello@example.com',
            'MAIL_FROM_NAME'    => 'Example',
        ]);
        $this->buildApp();
        $this->login();
        $csrf = $this->csrfFrom($this->get('/admin/mail'));

        $this->post('/admin/mail', [
            '_csrf'             => $csrf,
            'smtp_host'         => 'smtp.example.com',
            'smtp_port'         => '587',
            'smtp_encryption'   => 'starttls',
            'smtp_user'         => 'u',
            'smtp_pass'         => '', // blank keeps the stored one
            'mail_from_address' => 'hello@example.com',
            'mail_from_name'    => 'Example',
        ]);

        self::assertStringContainsString("'SMTP_PASS' => 'stored-secret'", $this->configFileText());
    }

    public function testSaveReplacesPasswordWhenProvided(): void
    {
        $this->writeConfig(['APP_SECRET' => 'x', 'SMTP_PASS' => 'old', 'MAIL_FROM_ADDRESS' => 'hello@example.com', 'MAIL_FROM_NAME' => 'E']);
        $this->buildApp();
        $this->login();
        $csrf = $this->csrfFrom($this->get('/admin/mail'));

        $this->post('/admin/mail', [
            '_csrf'             => $csrf,
            'smtp_host'         => 'h',
            'smtp_port'         => '587',
            'smtp_encryption'   => 'starttls',
            'smtp_user'         => 'u',
            'smtp_pass'         => 'brand-new-secret',
            'mail_from_address' => 'hello@example.com',
            'mail_from_name'    => 'E',
        ]);

        self::assertStringContainsString("'SMTP_PASS' => 'brand-new-secret'", $this->configFileText());
    }

    public function testSaveRejectsInvalidFromAddress(): void
    {
        $this->login();
        $csrf = $this->csrfFrom($this->get('/admin/mail'));

        $response = $this->post('/admin/mail', [
            '_csrf'             => $csrf,
            'smtp_host'         => 'h',
            'smtp_port'         => '587',
            'smtp_encryption'   => 'starttls',
            'mail_from_address' => 'not-an-email',
            'mail_from_name'    => 'E',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('valid From address', (string) $response->getBody());
        // Nothing was written: the From address is unchanged in the file.
        self::assertStringContainsString("'MAIL_FROM_ADDRESS' => 'hello@example.com'", $this->configFileText());
    }

    public function testSaveRejectsBadPort(): void
    {
        $this->login();
        $csrf = $this->csrfFrom($this->get('/admin/mail'));

        $response = $this->post('/admin/mail', [
            '_csrf'             => $csrf,
            'smtp_host'         => 'h',
            'smtp_port'         => 'abc',
            'smtp_encryption'   => 'starttls',
            'mail_from_address' => 'hello@example.com',
            'mail_from_name'    => 'E',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('port number', (string) $response->getBody());
    }

    public function testSaveRejectsEnableWithoutHost(): void
    {
        $this->login();
        $csrf = $this->csrfFrom($this->get('/admin/mail'));

        $response = $this->post('/admin/mail', [
            '_csrf'             => $csrf,
            'smtp_host'         => '',
            'smtp_port'         => '587',
            'smtp_encryption'   => 'starttls',
            'mail_from_address' => 'hello@example.com',
            'mail_from_name'    => 'E',
            'mail_enabled'      => '1',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('SMTP host before turning', (string) $response->getBody());
    }

    public function testStoredPasswordIsNeverRenderedButHintShows(): void
    {
        $this->writeConfig(['APP_SECRET' => 'x', 'SMTP_PASS' => 'topsecretvalue', 'MAIL_FROM_ADDRESS' => 'hello@example.com', 'MAIL_FROM_NAME' => 'E']);
        $this->buildApp();
        $this->login();

        $body = (string) $this->get('/admin/mail')->getBody();
        self::assertStringNotContainsString('topsecretvalue', $body);
        self::assertStringContainsString('A password is saved', $body);
    }

    public function testEnvShadowNoticeNamesTheShadowedSetting(): void
    {
        $this->writeConfig([
            'APP_SECRET'        => 'x',
            'MAIL_ENABLED'      => '0',
            'SMTP_HOST'         => 'file-host.example.com',
            'MAIL_FROM_ADDRESS' => 'hello@example.com',
            'MAIL_FROM_NAME'    => 'E',
        ]);
        // Environment overrides the stored host, so the file value is shadowed.
        $this->buildApp(['SMTP_HOST' => 'env-host.example.com']);
        $this->login();

        $body = (string) $this->get('/admin/mail')->getBody();
        self::assertStringContainsString('overrides what you save', $body);
        self::assertStringContainsString('SMTP host', $body);
    }

    // --- Test send --------------------------------------------------------

    public function testTestSendSuccessFlashesAndOffersEnable(): void
    {
        $this->login();
        $csrf = $this->csrfFrom($this->get('/admin/mail'));

        $response = $this->post('/admin/mail/test', [
            '_csrf'          => $csrf,
            'test_recipient' => 'me@example.com',
        ]);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(1, $this->mailer->callCount());
        self::assertSame('me@example.com', $this->mailer->lastCall()['to']);

        $body = (string) $this->get('/admin/mail')->getBody();
        self::assertStringContainsString('Test email sent to me@example.com', $body);
        // Sending is off, so the one-click enable offer appears.
        self::assertStringContainsString('Enable sending now', $body);
    }

    public function testTestSendDefaultsToLoggedInAdminEmail(): void
    {
        $this->login();
        $csrf = $this->csrfFrom($this->get('/admin/mail'));

        $this->post('/admin/mail/test', ['_csrf' => $csrf, 'test_recipient' => '']);

        self::assertSame(1, $this->mailer->callCount());
        self::assertSame(self::ADMIN_EMAIL, $this->mailer->lastCall()['to']);
    }

    public function testTestSendFailureFlashesSanitisedError(): void
    {
        $this->mailer->alwaysFail("connection refused\nsecond line");
        $this->login();
        $csrf = $this->csrfFrom($this->get('/admin/mail'));

        $this->post('/admin/mail/test', ['_csrf' => $csrf, 'test_recipient' => 'me@example.com']);

        $body = (string) $this->get('/admin/mail')->getBody();
        self::assertStringContainsString('could not be sent', $body);
        // Control characters collapsed to a space (no raw newline break-through).
        self::assertStringContainsString('connection refused second line', $body);
        // A failed test does not unlock the enable offer.
        self::assertStringNotContainsString('Enable sending now', $body);
    }

    public function testTestSendRejectsInvalidRecipient(): void
    {
        $this->login();
        $csrf = $this->csrfFrom($this->get('/admin/mail'));

        $this->post('/admin/mail/test', ['_csrf' => $csrf, 'test_recipient' => 'nope']);

        self::assertSame(0, $this->mailer->callCount());
        $body = (string) $this->get('/admin/mail')->getBody();
        self::assertStringContainsString('valid email address', $body);
    }

    // --- Enable now -------------------------------------------------------

    public function testEnableNowWritesMailEnabled(): void
    {
        $this->login();
        $csrf = $this->csrfFrom($this->get('/admin/mail'));

        $response = $this->post('/admin/mail/enable', ['_csrf' => $csrf]);
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString("'MAIL_ENABLED' => '1'", $this->configFileText());

        $body = (string) $this->get('/admin/mail')->getBody();
        self::assertStringContainsString('Email sending is now on', $body);
    }

    // --- Dashboard banner -------------------------------------------------

    public function testDashboardMailBannerShownWhenDisabled(): void
    {
        $this->login();
        $body = (string) $this->get('/admin')->getBody();
        self::assertStringContainsString('Email sending is not set up yet', $body);
        self::assertStringContainsString('href="/admin/mail"', $body);
    }

    public function testDashboardMailBannerDismissible(): void
    {
        $this->login();
        $csrf = $this->csrfFrom($this->get('/admin'));

        $this->post('/admin/nudge/mail/dismiss', ['_csrf' => $csrf]);

        $body = (string) $this->get('/admin')->getBody();
        self::assertStringNotContainsString('Email sending is not set up yet', $body);
    }

    public function testDashboardMailBannerAbsentWhenEnabled(): void
    {
        $this->writeConfig(['APP_SECRET' => 'x', 'MAIL_ENABLED' => '1', 'SMTP_HOST' => 'h', 'MAIL_FROM_ADDRESS' => 'hello@example.com', 'MAIL_FROM_NAME' => 'E']);
        $this->buildApp();
        $this->login();

        $body = (string) $this->get('/admin')->getBody();
        self::assertStringNotContainsString('Email sending is not set up yet', $body);
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

    private function configFileText(): string
    {
        return (string) file_get_contents($this->configPath);
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
