<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Install;

use OpenSendForm\AppFactory;
use OpenSendForm\Config;
use OpenSendForm\Install\Paths;
use OpenSendForm\Tests\Support\FakeMailer;
use OpenSendForm\Tests\Support\FixedClock;
use OpenSendForm\Tests\Support\FixedMailerFactory;
use OpenSendForm\Tests\Support\FakeSession;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The installer's skippable "Email sending" step (sprint feature/onboarding-v2,
 * task 2). Covers: skipping leaves MAIL_ENABLED off; saving writes the SMTP
 * settings and MAIL_ENABLED=1 through commit; the test send uses the just-typed
 * settings via the injected mailer factory; and MONITOR_BASE_URL is captured
 * from the request's scheme+host at completion. Driven against a throwaway temp
 * dir with a fake mailer so no SMTP connection is ever attempted.
 */
final class InstallerMailStepTest extends TestCase
{
    private const T0 = 1_700_000_000;
    private const ADMIN_EMAIL = 'boss@example.com';
    private const ADMIN_PASSWORD = 'a-strong-password-1';

    private string $base;
    private Paths $paths;
    private FakeSession $session;
    private FakeMailer $mailer;
    private App $app;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/osf_mailstep_' . bin2hex(random_bytes(6));
        mkdir($this->base . '/var/data', 0775, true);

        $this->paths = Paths::underBase($this->base, dirname(__DIR__, 2) . '/migrations');
        $this->session = new FakeSession();
        $this->mailer = new FakeMailer();

        $config = Config::fromValues([
            'APP_ENV'    => 'dev',
            'APP_SECRET' => 'installer-mailstep-secret',
            'DB_DSN'     => 'sqlite:' . $this->base . '/var/data/opensendform.sqlite',
        ]);

        $this->app = AppFactory::create(
            $config,
            null,
            null,
            new FixedClock(self::T0),
            $this->mailer,
            null,
            $this->session,
            $this->paths,
            null,
            new FixedMailerFactory($this->mailer)
        );

        $this->reachMailStep();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
    }

    public function testSkipLeavesEmailOffAndReachesFinish(): void
    {
        $mailPage = $this->get('/install/mail');
        $skip = $this->post('/install/mail', ['_csrf' => $this->csrfFrom($mailPage), 'action' => 'skip']);
        self::assertSame(302, $skip->getStatusCode());
        self::assertSame('/install/scheduled', $skip->getHeaderLine('Location'));

        $this->commit();
        $loaded = Config::fromFile($this->paths->configPath);
        self::assertFalse($loaded->mailEnabled());
        // No SMTP settings were written; the shipped defaults remain.
        self::assertSame('', $loaded->smtpUser());
    }

    public function testSaveWritesSettingsAndEnablesSending(): void
    {
        $mailPage = $this->get('/install/mail');
        $save = $this->post('/install/mail', [
            '_csrf'             => $this->csrfFrom($mailPage),
            'action'            => 'save',
            'smtp_host'         => 'mail.example.com',
            'smtp_port'         => '465',
            'smtp_encryption'   => 'smtps',
            'smtp_user'         => 'sender@example.com',
            'smtp_pass'         => 'hunter2hunter2',
            'mail_from_address' => 'hello@example.com',
            'mail_from_name'    => 'Example Forms',
        ]);
        self::assertSame(302, $save->getStatusCode());
        self::assertSame('/install/scheduled', $save->getHeaderLine('Location'));

        $this->commit();
        $text = (string) file_get_contents($this->paths->configPath);
        self::assertStringContainsString("'SMTP_HOST' => 'mail.example.com'", $text);
        self::assertStringContainsString("'SMTP_ENCRYPTION' => 'smtps'", $text);
        self::assertStringContainsString("'SMTP_PASS' => 'hunter2hunter2'", $text);
        self::assertStringContainsString("'MAIL_ENABLED' => '1'", $text);

        $loaded = Config::fromFile($this->paths->configPath);
        self::assertTrue($loaded->mailEnabled());
    }

    public function testSaveRejectsMissingHost(): void
    {
        $mailPage = $this->get('/install/mail');
        $response = $this->post('/install/mail', [
            '_csrf'             => $this->csrfFrom($mailPage),
            'action'            => 'save',
            'smtp_host'         => '',
            'smtp_port'         => '465',
            'smtp_encryption'   => 'smtps',
            'mail_from_address' => 'hello@example.com',
            'mail_from_name'    => 'Example',
        ]);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('SMTP host', (string) $response->getBody());
    }

    public function testTestSendUsesEnteredSettingsAndStaysOnStep(): void
    {
        $mailPage = $this->get('/install/mail');
        $response = $this->post('/install/mail', [
            '_csrf'             => $this->csrfFrom($mailPage),
            'action'            => 'test',
            'smtp_host'         => 'mail.example.com',
            'smtp_port'         => '465',
            'smtp_encryption'   => 'smtps',
            'smtp_user'         => 'sender@example.com',
            'smtp_pass'         => 'hunter2hunter2',
            'mail_from_address' => 'hello@example.com',
            'mail_from_name'    => 'Example',
            'test_recipient'    => 'me@example.com',
        ]);
        // Re-renders the step (200), does not advance or commit.
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->mailer->callCount());
        self::assertSame('me@example.com', $this->mailer->lastCall()['to']);
        self::assertStringContainsString('Test email sent to me@example.com', (string) $response->getBody());
        self::assertFalse($this->paths->isInstalled());
    }

    public function testMonitorBaseUrlCapturedFromRequest(): void
    {
        $mailPage = $this->get('/install/mail');
        $this->post('/install/mail', ['_csrf' => $this->csrfFrom($mailPage), 'action' => 'skip']);

        // Advance through the scheduled-tasks and bot-protection steps, then
        // commit via a request that carries a real scheme + host.
        $sched = $this->get('/install/scheduled');
        $this->post('/install/scheduled', ['_csrf' => $this->csrfFrom($sched), 'action' => 'later']);
        $botPage = $this->get('/install/bot-protection');
        $csrf = $this->csrfFrom($botPage);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://forms.example.com/install/finish', ['REMOTE_ADDR' => '203.0.113.5'])
            ->withParsedBody(['_csrf' => $csrf]);
        $this->app->handle($request);

        self::assertTrue($this->paths->isInstalled());
        $text = (string) file_get_contents($this->paths->configPath);
        self::assertStringContainsString("'MONITOR_BASE_URL' => 'https://forms.example.com'", $text);
    }

    // --- Harness ----------------------------------------------------------

    private function reachMailStep(): void
    {
        $dbPage = $this->get('/install/database');
        $this->post('/install/database', ['_csrf' => $this->csrfFrom($dbPage), 'db_driver' => 'sqlite']);
        $adminPage = $this->get('/install/admin');
        $this->post('/install/admin', [
            '_csrf'            => $this->csrfFrom($adminPage),
            'name'             => 'The Boss',
            'email'            => self::ADMIN_EMAIL,
            'password'         => self::ADMIN_PASSWORD,
            'password_confirm' => self::ADMIN_PASSWORD,
        ]);
    }

    private function commit(): void
    {
        // From the email step: record the scheduled-tasks choice, pass the
        // bot-protection signpost, then commit at the terminal Finish action.
        $sched = $this->get('/install/scheduled');
        $this->post('/install/scheduled', ['_csrf' => $this->csrfFrom($sched), 'action' => 'later']);
        $botPage = $this->get('/install/bot-protection');
        $this->post('/install/finish', ['_csrf' => $this->csrfFrom($botPage)]);
    }

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
        $matched = preg_match('/name="_csrf" value="([a-f0-9]+)"/', (string) $response->getBody(), $m);
        self::assertSame(1, $matched, 'Expected a CSRF token in the response body.');

        return $m[1];
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
