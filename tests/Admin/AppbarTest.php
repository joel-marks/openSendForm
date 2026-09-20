<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Admin;

use OpenSendForm\AppFactory;
use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\RecoveryCodes;
use OpenSendForm\Config;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Tests\Support\FakeMailer;
use OpenSendForm\Tests\Support\FakeSession;
use OpenSendForm\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The structural guard for the single header component (Task 1).
 *
 * EVERY rendered HTML page in the app must contain EXACTLY ONE .osf-appbar,
 * and that appbar must contain BOTH rows (the brand <header> and the tab
 * <nav>). Signed-in admin pages get the full variant (tabs + account menu);
 * the pre-auth login, the HTML submit page and the HTML error pages get the
 * chrome-only variant (no account menu, no tabs) — same component, no
 * chrome-free page anywhere. Browser titles are all "osf - {page name}".
 *
 * The installer's own step walk is guarded in InstallerHttpTest, which owns
 * the installer harness.
 */
final class AppbarTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';
    private const T0 = 1_700_000_000;

    private Database $db;
    private FakeSession $session;
    private FormRepository $forms;
    private App $app;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $this->session = new FakeSession();
        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        (new AdminRepository($this->db, $hasher, new RecoveryCodes($hasher)))
            ->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        $this->forms = new FormRepository($this->db);

        $config = Config::fromValues(['APP_ENV' => 'dev', 'APP_SECRET' => 'appbar-secret']);
        $this->app = AppFactory::create(
            $config,
            $this->db,
            null,
            new FixedClock(self::T0),
            new FakeMailer(),
            null,
            $this->session
        );
    }

    // --- Signed-in admin pages: full variant, exactly one appbar -----------

    public function testEveryAdminPageHasExactlyOneFullAppbarWithBothRowsAndTitle(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $this->login();

        $expectedTitles = [
            '/admin'                              => 'Dashboard',
            '/admin/forms'                        => 'Forms',
            '/admin/forms/new'                    => 'New form',
            '/admin/forms/' . $form['id'] . '/edit' => 'Edit form',
            '/admin/submissions'                  => 'Submissions',
            '/admin/mail'                         => 'Email',
            '/admin/deliverability'               => 'Deliverability',
            '/admin/account'                      => 'Your account',
            '/admin/admins'                       => 'Admins',
            '/admin/totp/setup'                   => 'Two-factor authentication',
        ];

        foreach ($expectedTitles as $route => $pageName) {
            $body = (string) $this->get($route)->getBody();
            $this->assertSingleAppbar($body, true, $route);
            self::assertStringContainsString(
                '<title>osf - ' . $pageName . '</title>',
                $body,
                "{$route} title must be 'osf - {$pageName}'"
            );
        }
    }

    // --- Pre-auth + no-session pages: chrome-only variant ------------------

    public function testLoginRendersOneChromeOnlyAppbar(): void
    {
        $body = (string) $this->get('/admin/login')->getBody();
        $this->assertSingleAppbar($body, false, '/admin/login');
        self::assertStringContainsString('<title>osf - Sign in</title>', $body);
    }

    public function testHtmlErrorPageRendersOneChromeOnlyAppbar(): void
    {
        // An unknown admin path 404s through the ErrorHandler as an HTML page.
        $body = (string) $this->get('/admin/nope', 'text/html')->getBody();
        $this->assertSingleAppbar($body, false, '/admin/nope (404)');
        self::assertStringContainsString('<title>osf - Page not found</title>', $body);
    }

    public function testHtmlSubmitPageRendersOneChromeOnlyAppbar(): void
    {
        // A native (text/html) POST to an unknown form key renders the HTML
        // submit fallback page (403), which wears the chrome-only appbar.
        $request = $this->request('POST', '/v1/form/osf_unknownkey/submit')
            ->withHeader('Accept', 'text/html')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded');
        $request->getBody()->write('name=Ada');
        $request->getBody()->rewind();

        $response = $this->app->handle($request);
        $body = (string) $response->getBody();
        $this->assertSingleAppbar($body, false, 'submit HTML page');
        self::assertStringContainsString('<title>osf - Something went wrong</title>', $body);
    }

    // --- Shared assertion --------------------------------------------------

    private function assertSingleAppbar(string $body, bool $full, string $where): void
    {
        self::assertSame(
            1,
            substr_count($body, 'class="osf-appbar"'),
            "{$where} must contain EXACTLY ONE .osf-appbar"
        );
        // Both rows live inside it.
        self::assertStringContainsString('<header class="osf-header"', $body, "{$where}: missing brand row");
        self::assertStringContainsString('<nav class="osf-tabnav"', $body, "{$where}: missing tab row");

        if ($full) {
            self::assertStringContainsString('osf-tab-link', $body, "{$where}: full appbar must carry the tab strip");
            self::assertStringContainsString('osf-account-menu', $body, "{$where}: full appbar must carry the account menu");
        } else {
            self::assertStringNotContainsString('osf-tab-link', $body, "{$where}: chrome-only appbar must have no tabs");
            self::assertStringNotContainsString('osf-account-menu', $body, "{$where}: chrome-only appbar must have no account menu");
        }
    }

    // --- Harness -----------------------------------------------------------

    private function login(): void
    {
        $csrf = $this->csrfFrom($this->get('/admin/login'));
        $this->app->handle($this->request('POST', '/admin/login')->withParsedBody([
            '_csrf'    => $csrf,
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]));
    }

    private function get(string $path, string $accept = ''): ResponseInterface
    {
        $request = $this->request('GET', $path);
        if ($accept !== '') {
            $request = $request->withHeader('Accept', $accept);
        }

        return $this->app->handle($request);
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
