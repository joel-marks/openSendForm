<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Admin;

use OpenSendForm\AppFactory;
use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\RecoveryCodes;
use OpenSendForm\Auth\Totp;
use OpenSendForm\Config;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Tests\Support\FakeSession;
use OpenSendForm\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * End-to-end coverage of the admin auth routes, driven through the app
 * factory against an in-memory database with an array-backed session and a
 * fixed clock. No native session, wall-clock or network is touched.
 */
final class AdminHttpTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';
    private const T0 = 1_700_000_000;

    private Database $db;
    private FakeSession $session;
    private FixedClock $clock;
    private App $app;
    private AdminRepository $admins;
    private Totp $totp;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $this->session = new FakeSession();
        $this->clock = new FixedClock(self::T0);
        $this->totp = new Totp();

        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        $this->admins = new AdminRepository($this->db, $hasher, new RecoveryCodes($hasher));

        $config = Config::fromValues(['APP_ENV' => 'dev', 'APP_SECRET' => 'admin-http-secret']);
        $this->app = AppFactory::create($config, $this->db, null, $this->clock, null, null, $this->session);
    }

    // --- Login page & auth ------------------------------------------------

    public function testLoginPageRenders(): void
    {
        $response = $this->get('/admin/login');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<form method="post" action="/admin/login">', (string) $response->getBody());
    }

    public function testProtectedRouteRedirectsWhenLoggedOut(): void
    {
        $response = $this->get('/admin');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/login', $response->getHeaderLine('Location'));
    }

    public function testSecurityHeadersPresentOnAdminResponses(): void
    {
        $response = $this->get('/admin/login');

        // The full document header set, locked here.
        self::assertStringContainsString("frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testWrongCredentialsShowGenericError(): void
    {
        $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        $csrf = $this->csrfFrom($this->get('/admin/login'));

        $response = $this->post('/admin/login', [
            '_csrf'    => $csrf,
            'email'    => 'boss@example.com',
            'password' => 'wrong-password',
        ]);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('Invalid email or password.', (string) $response->getBody());
        self::assertFalse($this->session->has('auth.admin_id'));
    }

    public function testGoodCredentialsWithoutTotpReachDashboard(): void
    {
        $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        $csrf = $this->csrfFrom($this->get('/admin/login'));

        $login = $this->post('/admin/login', [
            '_csrf'    => $csrf,
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(302, $login->getStatusCode());
        self::assertSame('/admin', $login->getHeaderLine('Location'));

        $dashboard = $this->get('/admin');
        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('The Boss', (string) $dashboard->getBody());
    }

    public function testGoodCredentialsWithTotpGateThenDashboard(): void
    {
        $admin = $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        $secret = $this->totp->generateSecret();
        $this->admins->setTotp($admin['id'], $secret);
        $this->admins->enableTotp($admin['id']);

        $csrf = $this->csrfFrom($this->get('/admin/login'));
        $login = $this->post('/admin/login', [
            '_csrf'    => $csrf,
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]);

        // Password accepted but not yet on the dashboard.
        self::assertSame(302, $login->getStatusCode());
        self::assertSame('/admin/totp', $login->getHeaderLine('Location'));
        self::assertSame(302, $this->get('/admin')->getStatusCode());

        $totpPage = $this->get('/admin/totp');
        self::assertSame(200, $totpPage->getStatusCode());

        $verify = $this->post('/admin/totp', [
            '_csrf' => $this->csrfFrom($totpPage),
            'code'  => $this->totp->codeAt($secret, self::T0),
        ]);
        self::assertSame(302, $verify->getStatusCode());
        self::assertSame('/admin', $verify->getHeaderLine('Location'));

        self::assertSame(200, $this->get('/admin')->getStatusCode());
    }

    public function testLoginPerIpRateLimitIsConfigDriven(): void
    {
        // A dedicated app whose per-IP login cap is overridden low (2), proving
        // the RATE_LOGIN_PER_IP knob takes effect end to end: the default is 10.
        $db = Database::connect('sqlite::memory:');
        (new MigrationRunner($db, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        (new AdminRepository($db, $hasher, new RecoveryCodes($hasher)))
            ->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);

        $config = Config::fromValues([
            'APP_ENV'           => 'dev',
            'APP_SECRET'        => 'rate-config-secret',
            'RATE_LOGIN_PER_IP' => '2',
        ]);
        $session = new FakeSession();
        $app = AppFactory::create($config, $db, null, $this->clock, null, null, $session);

        $attempt = static function (App $app, string $csrf): ResponseInterface {
            return $app->handle(
                (new ServerRequestFactory())
                    ->createServerRequest('POST', '/admin/login', ['REMOTE_ADDR' => '203.0.113.5'])
                    ->withParsedBody(['_csrf' => $csrf, 'email' => 'boss@example.com', 'password' => 'wrong'])
            );
        };

        $csrf = $this->csrfFrom($app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/login', ['REMOTE_ADDR' => '203.0.113.5'])
        ));

        // Two attempts are allowed (401), the third is rate-limited (429) — far
        // below the default cap of 10.
        self::assertSame(401, $attempt($app, $csrf)->getStatusCode());
        self::assertSame(401, $attempt($app, $csrf)->getStatusCode());
        self::assertSame(429, $attempt($app, $csrf)->getStatusCode());
    }

    public function testTotpRateLimitedReturns429(): void
    {
        $admin = $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        $secret = $this->totp->generateSecret();
        $this->admins->setTotp($admin['id'], $secret);
        $this->admins->enableTotp($admin['id']);

        $csrf = $this->csrfFrom($this->get('/admin/login'));
        $this->post('/admin/login', [
            '_csrf'    => $csrf,
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]);

        // Default cap is 5 wrong attempts per admin per 900s window.
        for ($i = 0; $i < 5; $i++) {
            $totpPage = $this->get('/admin/totp');
            $response = $this->post('/admin/totp', [
                '_csrf' => $this->csrfFrom($totpPage),
                'code'  => '000000',
            ]);
            self::assertSame(401, $response->getStatusCode(), "attempt {$i}");
        }

        $totpPage = $this->get('/admin/totp');
        $response = $this->post('/admin/totp', [
            '_csrf' => $this->csrfFrom($totpPage),
            'code'  => '000000',
        ]);
        self::assertSame(429, $response->getStatusCode());
        self::assertStringContainsString('Too many attempts. Please try again later.', (string) $response->getBody());
    }

    public function testInvalidTotpCodeShowsAlertRegion(): void
    {
        $admin = $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        $secret = $this->totp->generateSecret();
        $this->admins->setTotp($admin['id'], $secret);
        $this->admins->enableTotp($admin['id']);

        $csrf = $this->csrfFrom($this->get('/admin/login'));
        $this->post('/admin/login', [
            '_csrf'    => $csrf,
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]);

        $totpPage = $this->get('/admin/totp');
        $response = $this->post('/admin/totp', [
            '_csrf' => $this->csrfFrom($totpPage),
            'code'  => '000000',
        ]);

        self::assertSame(401, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString(
            '<p class="osf-flash osf-flash--error" role="alert"><strong>Invalid code.</strong></p>',
            $body
        );

        // The segmented-box enhancement re-initialises fresh on this re-render:
        // the carrier input has no leftover value for JS to redistribute.
        self::assertMatchesRegularExpression('/id="code"[^>]*data-totp-code/', $body);
        self::assertDoesNotMatchRegularExpression('/id="code"[^>]*value="000000"/', $body);
    }

    public function testTotpRecoveryCodeFallbackMarkupAndSubmission(): void
    {
        $admin = $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        $secret = $this->totp->generateSecret();
        $this->admins->setTotp($admin['id'], $secret);
        $this->admins->enableTotp($admin['id']);

        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        $recovery = new RecoveryCodes($hasher);
        $batch = $recovery->generate();
        $this->admins->setRecoveryCodes($admin['id'], $batch['hashes']);
        $recoveryCode = $batch['plain'][0];

        $csrf = $this->csrfFrom($this->get('/admin/login'));
        $this->post('/admin/login', [
            '_csrf'    => $csrf,
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]);

        $totpPage = $this->get('/admin/totp');
        $body = (string) $totpPage->getBody();

        // The recovery-code toggle is reachable (JS swaps to this same plain
        // field) and the field carries no length/pattern restriction that
        // would block a 10-character alphanumeric recovery code — with JS
        // off, this one field must accept either kind of code unchanged.
        self::assertStringContainsString('data-totp-recovery-toggle', $body);
        self::assertStringNotContainsString('maxlength="6"', $body);
        self::assertStringNotContainsString('pattern="[0-9]*"', $body);
        self::assertSame(1, substr_count($body, '<form method="post" action="/admin/totp">'));

        // Submitting a recovery code through that same "code" field succeeds
        // at the HTTP level, exactly as the plain no-JS fallback would.
        $verify = $this->post('/admin/totp', [
            '_csrf' => $this->csrfFrom($totpPage),
            'code'  => $recoveryCode,
        ]);
        self::assertSame(302, $verify->getStatusCode());
        self::assertSame('/admin', $verify->getHeaderLine('Location'));
    }

    public function testExpiredPendingTotpRedirectsToLogin(): void
    {
        $admin = $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        $secret = $this->totp->generateSecret();
        $this->admins->setTotp($admin['id'], $secret);
        $this->admins->enableTotp($admin['id']);

        $csrf = $this->csrfFrom($this->get('/admin/login'));
        $this->post('/admin/login', [
            '_csrf'    => $csrf,
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(200, $this->get('/admin/totp')->getStatusCode());

        $this->clock->advance(300 + 1);

        $response = $this->get('/admin/totp');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/login', $response->getHeaderLine('Location'));
    }

    public function testLogoutDestroysSession(): void
    {
        $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        $csrf = $this->csrfFrom($this->get('/admin/login'));
        $this->post('/admin/login', [
            '_csrf'    => $csrf,
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]);
        self::assertSame(200, $this->get('/admin')->getStatusCode());

        $logout = $this->post('/admin/logout', ['_csrf' => $this->csrfFrom($this->get('/admin'))]);
        self::assertSame(302, $logout->getStatusCode());
        self::assertSame('/admin/login', $logout->getHeaderLine('Location'));
        self::assertGreaterThan(0, $this->session->destroyCount());

        // Protected route bounces again after logout.
        self::assertSame(302, $this->get('/admin')->getStatusCode());
    }

    // --- XSS ----------------------------------------------------------------

    public function testLoginEscapesSubmittedEmailInErrorResponse(): void
    {
        $csrf = $this->csrfFrom($this->get('/admin/login'));
        $payload = '"/><script>alert(1)</script>';

        $response = $this->post('/admin/login', [
            '_csrf'    => $csrf,
            'email'    => $payload,
            'password' => 'wrong-password',
        ]);

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString(htmlspecialchars($payload, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $body);
    }

    // --- CSRF -------------------------------------------------------------

    public function testLoginRejectsMissingCsrfToken(): void
    {
        $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        // Prime the session token, then omit it from the POST.
        $this->get('/admin/login');

        $response = $this->post('/admin/login', [
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($this->session->has('auth.admin_id'));
    }

    public function testLoginRejectsWrongCsrfToken(): void
    {
        $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        $this->get('/admin/login');

        $response = $this->post('/admin/login', [
            '_csrf'    => 'a-forged-token',
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($this->session->has('auth.admin_id'));
    }

    // --- Helpers ----------------------------------------------------------

    private function get(string $path): ResponseInterface
    {
        return $this->app->handle($this->request('GET', $path));
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
        $matched = preg_match(
            '/name="_csrf" value="([a-f0-9]+)"/',
            (string) $response->getBody(),
            $m
        );
        self::assertSame(1, $matched, 'Expected a CSRF token in the response body.');

        return $m[1];
    }
}
