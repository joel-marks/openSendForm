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
use OpenSendForm\Submission\SubmissionRepository;
use OpenSendForm\Tests\Support\AdminUiFieldWrapperAssertions;
use OpenSendForm\Tests\Support\FakeMailer;
use OpenSendForm\Tests\Support\FakeSession;
use OpenSendForm\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * HTTP-level coverage for the increment 5b admin screens (dashboard, forms
 * CRUD, submissions, retry) plus the design-system contract (CSP, self-hosted
 * assets, no inline handlers). Driven through the app factory against an
 * in-memory database with an array-backed session, a fixed clock and a
 * scriptable fake mailer — no native session, wall-clock or network.
 */
final class AdminUiTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';
    /** 2023-11-14 22:13:20 UTC. */
    private const T0 = 1_700_000_000;
    private const TODAY = '2023-11-14';

    private Database $db;
    private FakeSession $session;
    private FixedClock $clock;
    private FakeMailer $mailer;
    private App $app;
    private AdminRepository $admins;
    private FormRepository $forms;
    private SubmissionRepository $submissions;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $this->session = new FakeSession();
        $this->clock = new FixedClock(self::T0);
        $this->mailer = new FakeMailer();

        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        $this->admins = new AdminRepository($this->db, $hasher, new RecoveryCodes($hasher));
        $this->forms = new FormRepository($this->db);
        $this->submissions = new SubmissionRepository($this->db);

        $config = Config::fromValues(['APP_ENV' => 'dev', 'APP_SECRET' => 'admin-ui-secret']);
        $this->app = AppFactory::create(
            $config,
            $this->db,
            null,
            $this->clock,
            $this->mailer,
            null,
            $this->session
        );
    }

    // --- Dashboard --------------------------------------------------------

    public function testDashboardShowsCounts(): void
    {
        $this->forms->createForm('Alpha', 'a@example.com', ['https://a.com']);            // active
        $this->forms->createForm('Beta', 'b@example.com', ['https://b.com']);             // active
        $this->forms->createForm('Gamma', 'g@example.com', ['https://g.com'], false, 30, false); // disabled

        $form = $this->forms->createForm('Contact', 'c@example.com', ['https://c.com']);
        $id = (int) $form['id'];
        // Three "today" (>= 2023-11-14): 1 sent, 1 failed, 1 dead.
        $this->insertSubmission($id, self::TODAY . ' 08:00:00', 'sent');
        $this->insertSubmission($id, self::TODAY . ' 09:00:00', 'failed', 1, 'smtp boom');
        $this->insertSubmission($id, self::TODAY . ' 10:00:00', 'dead', 5, 'gave up here');
        // Not today, and an older failed one contributing to the failed total.
        $this->insertSubmission($id, '2023-11-13 09:00:00', 'failed', 2, 'yesterday fail');
        $this->insertSubmission($id, '2023-11-10 09:00:00', 'sent');

        $this->login();
        $body = (string) $this->get('/admin')->getBody();

        // Active forms = 4 (Alpha, Beta, Contact active; Gamma disabled).
        self::assertStringContainsString('<div class="osf-stat-value">3</div>', $body);
        // Today = 3, failed total = 2, dead total = 1 all appear as stat values.
        self::assertSame(1, substr_count($body, '<div class="osf-stat-value">2</div>'), 'failed total = 2');
        self::assertSame(1, substr_count($body, '<div class="osf-stat-value">1</div>'), 'dead total = 1');

        // Recent problem list shows failed/dead errors, links to filtered view.
        self::assertStringContainsString('gave up here', $body);
        self::assertStringContainsString('yesterday fail', $body);
        self::assertStringContainsString('/admin/submissions?status=dead&amp;form=' . $id, $body);
        // A delivered submission's status is never in the problem list body.
    }

    public function testAllZeroStatCardsUseTheInfoTone(): void
    {
        // A fresh install with no forms and no submissions: every stat value is
        // zero, so EVERY card takes the info/blue tone regardless of what it
        // measures (the zero=info rule).
        $this->login();
        $body = (string) $this->get('/admin')->getBody();

        foreach (['Active forms', 'Submissions today', 'Failed \(retrying\)', 'Dead \(gave up\)'] as $label) {
            self::assertMatchesRegularExpression(
                '/<a href="[^"]*" class="osf-stat osf-stat--info">\s*<div class="osf-stat-value">0<\/div>\s*<div class="osf-stat-label">' . $label . '/s',
                $body,
                "Zero-valued '{$label}' card must use the info tone"
            );
        }
        // No non-zero tones appear when everything is zero.
        self::assertStringNotContainsString('osf-stat--success', $body);
        self::assertStringNotContainsString('osf-stat--danger', $body);
    }

    public function testNonZeroStatCardsUseSuccessExceptFailureStatsWhichUseDanger(): void
    {
        // One active form + submissions across states so all four counts are
        // non-zero: Active forms + Submissions today -> success; Failed + Dead
        // (failure-measuring) -> danger.
        $form = $this->forms->createForm('Contact', 'c@example.com', ['https://c.com']);
        $id = (int) $form['id'];
        $this->insertSubmission($id, self::TODAY . ' 08:00:00', 'sent');
        $this->insertSubmission($id, self::TODAY . ' 09:00:00', 'failed', 1, 'boom');
        $this->insertSubmission($id, self::TODAY . ' 10:00:00', 'dead', 5, 'gave up');

        $this->login();
        $body = (string) $this->get('/admin')->getBody();

        self::assertMatchesRegularExpression(
            '/<a href="\/admin\/forms" class="osf-stat osf-stat--success">.*?Active forms/s',
            $body
        );
        self::assertMatchesRegularExpression(
            '/<a href="\/admin\/submissions" class="osf-stat osf-stat--success">.*?Submissions today/s',
            $body
        );
        self::assertMatchesRegularExpression(
            '/<a href="\/admin\/submissions\?status=failed" class="osf-stat osf-stat--danger">.*?Failed \(retrying\)/s',
            $body
        );
        self::assertMatchesRegularExpression(
            '/<a href="\/admin\/submissions\?status=dead" class="osf-stat osf-stat--danger">.*?Dead \(gave up\)/s',
            $body
        );
        // The retired heavy tones never appear.
        self::assertStringNotContainsString('osf-stat--accent', $body);
        self::assertStringNotContainsString('osf-stat--warning', $body);
    }

    public function testActiveFormsCountExcludesDisabled(): void
    {
        $this->forms->createForm('One', 'o@example.com', ['https://o.com']);
        $this->forms->createForm('Two', 't@example.com', ['https://t.com'], false, 30, false);

        $this->login();
        $body = (string) $this->get('/admin')->getBody();
        // 1 active form.
        self::assertMatchesRegularExpression(
            '/<div class="osf-stat-value">1<\/div>\s*<div class="osf-stat-label">Active forms/',
            $body
        );
    }

    // --- Forms list / create / edit --------------------------------------

    public function testFormsListShowsKeyRecipientAndBadges(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $this->login();

        $body = (string) $this->get('/admin/forms')->getBody();
        self::assertStringContainsString((string) $form['form_key'], $body);
        self::assertStringContainsString('owner@example.com', $body);
        self::assertStringContainsString('data-copy="' . $form['form_key'] . '"', $body);
        self::assertStringContainsString('osf-badge--ok', $body); // active badge
    }

    public function testCreateFormHappyPath(): void
    {
        $this->login();
        $page = $this->get('/admin/forms/new');
        self::assertSame(200, $page->getStatusCode());

        $response = $this->post('/admin/forms', [
            '_csrf'          => $this->csrfFrom($page),
            'name'           => 'Newsletter',
            'recipient'      => 'news@example.com',
            'origins'        => "https://example.com\nhttps://www.example.com",
            'retention_days' => '45',
            'is_active'      => '1',
            'store_content'  => '1',
        ]);

        self::assertSame(302, $response->getStatusCode());
        // Lands on the new form's own page, scrolled to its embed-code panel.
        self::assertSame(
            '/admin/forms/' . (int) $this->forms->listForms()[0]['id'] . '/edit#embed',
            $response->getHeaderLine('Location')
        );

        $forms = $this->forms->listForms();
        self::assertCount(1, $forms);
        self::assertSame('Newsletter', $forms[0]['name']);
        self::assertSame(['https://example.com', 'https://www.example.com'], $forms[0]['allowed_origins']);
        self::assertSame(45, $forms[0]['retention_days']);
        self::assertSame(1, $forms[0]['store_content']);
    }

    public function testCreateFormRejectsBadEmailAndPreservesInput(): void
    {
        $this->login();
        $page = $this->get('/admin/forms/new');

        $response = $this->post('/admin/forms', [
            '_csrf'          => $this->csrfFrom($page),
            'name'           => 'My Form',
            'recipient'      => 'not-an-email',
            'origins'        => 'https://example.com',
            'retention_days' => '30',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Invalid recipient email', $body);
        self::assertStringContainsString('value="My Form"', $body);            // name preserved
        self::assertStringContainsString('https://example.com', $body);         // origins preserved
        self::assertCount(0, $this->forms->listForms());                        // nothing written
    }

    public function testCreateFormRejectsBadOrigin(): void
    {
        $this->login();
        $page = $this->get('/admin/forms/new');

        $response = $this->post('/admin/forms', [
            '_csrf'          => $this->csrfFrom($page),
            'name'           => 'My Form',
            'recipient'      => 'ok@example.com',
            'origins'        => 'ftp://example.com/path',
            'retention_days' => '30',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Invalid origin', (string) $response->getBody());
        self::assertCount(0, $this->forms->listForms());
    }

    public function testCreateFormRejectsTurnstileOneKeyOnly(): void
    {
        $this->login();
        $page = $this->get('/admin/forms/new');

        $response = $this->post('/admin/forms', [
            '_csrf'             => $this->csrfFrom($page),
            'name'              => 'My Form',
            'recipient'         => 'ok@example.com',
            'origins'           => 'https://example.com',
            'retention_days'    => '30',
            'turnstile_sitekey' => 'site-key-only',
            // no secret
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Turnstile needs both', (string) $response->getBody());
        self::assertCount(0, $this->forms->listForms());
    }

    public function testEditFormShowsKeyAndUpdates(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $id = (int) $form['id'];
        $this->login();

        $edit = $this->get("/admin/forms/{$id}/edit");
        self::assertSame(200, $edit->getStatusCode());
        self::assertStringContainsString((string) $form['form_key'], (string) $edit->getBody());

        $response = $this->post("/admin/forms/{$id}", [
            '_csrf'          => $this->csrfFrom($edit),
            'name'           => 'Contact Renamed',
            'recipient'      => 'newowner@example.com',
            'origins'        => 'https://example.com',
            'retention_days' => '10',
            'is_active'      => '1',
        ]);

        self::assertSame(302, $response->getStatusCode());
        $reloaded = $this->forms->findById($id);
        self::assertSame('Contact Renamed', $reloaded['name']);
        self::assertSame('newowner@example.com', $reloaded['recipient_email']);
        self::assertSame(10, $reloaded['retention_days']);
    }

    public function testAllowNojsCheckboxRoundTrips(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $id = (int) $form['id'];
        self::assertSame(0, $this->forms->findById($id)['allow_nojs'], 'defaults off');
        $this->login();

        $edit = $this->get("/admin/forms/{$id}/edit");
        self::assertStringContainsString('Allow submissions without JavaScript', (string) $edit->getBody());

        $this->post("/admin/forms/{$id}", [
            '_csrf'          => $this->csrfFrom($edit),
            'name'           => 'Contact',
            'recipient'      => 'owner@example.com',
            'origins'        => 'https://example.com',
            'retention_days' => '30',
            'is_active'      => '1',
            'allow_nojs'     => '1',
        ]);
        self::assertSame(1, $this->forms->findById($id)['allow_nojs'], 'checkbox on -> flag set');

        $edit2 = $this->get("/admin/forms/{$id}/edit");
        self::assertMatchesRegularExpression(
            '/id="allow_nojs" name="allow_nojs" value="1"\s+checked/',
            (string) $edit2->getBody()
        );

        // Omitting the field (unchecked checkbox) clears it back off.
        $this->post("/admin/forms/{$id}", [
            '_csrf'          => $this->csrfFrom($edit2),
            'name'           => 'Contact',
            'recipient'      => 'owner@example.com',
            'origins'        => 'https://example.com',
            'retention_days' => '30',
            'is_active'      => '1',
        ]);
        self::assertSame(0, $this->forms->findById($id)['allow_nojs'], 'checkbox off -> flag cleared');
    }

    // --- Turnstile secret handling ---------------------------------------

    public function testTurnstileSecretIsNeverEchoedBack(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $id = (int) $form['id'];
        $this->forms->setTurnstile($id, 'the-site-key', 'the-super-secret-value');
        $this->login();

        $body = (string) $this->get("/admin/forms/{$id}/edit")->getBody();
        self::assertStringNotContainsString('the-super-secret-value', $body);
        self::assertStringContainsString('the-site-key', $body);       // sitekey is public
        self::assertStringContainsString('currently', $body);          // set/not-set hint
    }

    public function testBlankSecretOnEditKeepsExistingSecret(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $id = (int) $form['id'];
        $this->forms->setTurnstile($id, 'site-key', 'keep-me-secret');
        $this->login();

        $edit = $this->get("/admin/forms/{$id}/edit");
        $this->post("/admin/forms/{$id}", [
            '_csrf'             => $this->csrfFrom($edit),
            'name'              => 'Contact',
            'recipient'         => 'owner@example.com',
            'origins'           => 'https://example.com',
            'retention_days'    => '30',
            'is_active'         => '1',
            'turnstile_sitekey' => 'site-key',
            'turnstile_secret'  => '',   // left blank → keep existing
        ]);

        self::assertSame('keep-me-secret', $this->forms->findById($id)['turnstile_secret']);
    }

    public function testClearingSitekeyDisablesTurnstile(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $id = (int) $form['id'];
        $this->forms->setTurnstile($id, 'site-key', 'a-secret');
        $this->login();

        $edit = $this->get("/admin/forms/{$id}/edit");
        $this->post("/admin/forms/{$id}", [
            '_csrf'             => $this->csrfFrom($edit),
            'name'              => 'Contact',
            'recipient'         => 'owner@example.com',
            'origins'           => 'https://example.com',
            'retention_days'    => '30',
            'is_active'         => '1',
            'turnstile_sitekey' => '',
            'turnstile_secret'  => '',
        ]);

        $reloaded = $this->forms->findById($id);
        self::assertNull($reloaded['turnstile_sitekey']);
        self::assertNull($reloaded['turnstile_secret']);
    }

    // --- Enable / disable -------------------------------------------------

    public function testEnableDisableForm(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $id = (int) $form['id'];
        $this->login();

        $csrf = $this->csrfFrom($this->get('/admin/forms'));
        $disable = $this->post("/admin/forms/{$id}/disable", ['_csrf' => $csrf]);
        self::assertSame(302, $disable->getStatusCode());
        self::assertSame(0, $this->forms->findById($id)['is_active']);

        $csrf = $this->csrfFrom($this->get('/admin/forms'));
        $enable = $this->post("/admin/forms/{$id}/enable", ['_csrf' => $csrf]);
        self::assertSame(302, $enable->getStatusCode());
        self::assertSame(1, $this->forms->findById($id)['is_active']);
    }

    public function testDisableRejectsBadCsrf(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $id = (int) $form['id'];
        $this->login();

        $this->post("/admin/forms/{$id}/disable", ['_csrf' => 'forged']);
        self::assertSame(1, $this->forms->findById($id)['is_active'], 'form must stay active');
    }

    // --- Submissions: pagination + filters -------------------------------

    public function testSubmissionsPagination(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $id = (int) $form['id'];
        for ($i = 0; $i < 55; $i++) {
            $this->insertSubmission($id, self::TODAY . ' 08:00:00', 'sent');
        }
        $this->login();

        $page1 = (string) $this->get('/admin/submissions')->getBody();
        self::assertSame(50, substr_count($page1, '<tr>') - 1, 'page 1 has 50 data rows'); // minus header row
        self::assertStringContainsString('Page 1 of 2', $page1);

        $page2 = (string) $this->get('/admin/submissions', ['page' => '2'])->getBody();
        self::assertSame(5, substr_count($page2, '<tr>') - 1, 'page 2 has 5 data rows');
        self::assertStringContainsString('Page 2 of 2', $page2);
    }

    public function testSubmissionsFilterByStatusAndForm(): void
    {
        $a = (int) $this->forms->createForm('Alpha', 'a@example.com', ['https://a.com'])['id'];
        $b = (int) $this->forms->createForm('Beta', 'b@example.com', ['https://b.com'])['id'];

        $this->insertSubmission($a, self::TODAY . ' 08:00:00', 'sent');
        $this->insertSubmission($a, self::TODAY . ' 09:00:00', 'failed', 1, 'a-failed-error');
        $this->insertSubmission($b, self::TODAY . ' 10:00:00', 'failed', 1, 'b-failed-error');
        $this->login();

        $byStatus = (string) $this->get('/admin/submissions', ['status' => 'failed'])->getBody();
        self::assertStringContainsString('a-failed-error', $byStatus);
        self::assertStringContainsString('b-failed-error', $byStatus);
        self::assertStringContainsString('2 submission(s) match', $byStatus);

        $byForm = (string) $this->get('/admin/submissions', ['form' => (string) $a])->getBody();
        self::assertStringContainsString('a-failed-error', $byForm);
        self::assertStringNotContainsString('b-failed-error', $byForm);
        self::assertStringContainsString('2 submission(s) match', $byForm); // Alpha has 2 rows

        $both = (string) $this->get('/admin/submissions', ['status' => 'failed', 'form' => (string) $b])->getBody();
        self::assertStringContainsString('b-failed-error', $both);
        self::assertStringNotContainsString('a-failed-error', $both);
        self::assertStringContainsString('1 submission(s) match', $both);
    }

    public function testSubmissionsNeverShowContent(): void
    {
        $id = (int) $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com'])['id'];
        $secret = 'topsecret-message-body-xyz';
        $this->insertSubmission(
            $id,
            self::TODAY . ' 08:00:00',
            'failed',
            1,
            'err',
            json_encode(['message' => $secret])
        );
        $this->login();

        $body = (string) $this->get('/admin/submissions')->getBody();
        self::assertStringNotContainsString($secret, $body);
    }

    // --- Retry ------------------------------------------------------------

    public function testRetrySucceedsAndTransitionsToSent(): void
    {
        $id = (int) $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com'])['id'];
        $sid = $this->insertSubmission(
            $id,
            self::TODAY . ' 08:00:00',
            'failed',
            1,
            'smtp down',
            json_encode(['email' => 'visitor@example.com'])
        );
        $this->login();

        // Default FakeMailer succeeds.
        $csrf = $this->csrfFrom($this->get('/admin/submissions'));
        $response = $this->post("/admin/submissions/{$sid}/retry", [
            '_csrf'  => $csrf,
            'status' => 'failed',
            'form'   => (string) $id,
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('sent', $this->submissions->findById($sid)['status']);
        self::assertSame(1, $this->mailer->callCount());
    }

    public function testRetryRepeatFailureStaysFailed(): void
    {
        $id = (int) $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com'])['id'];
        $sid = $this->insertSubmission(
            $id,
            self::TODAY . ' 08:00:00',
            'failed',
            1,
            'smtp down',
            json_encode(['email' => 'visitor@example.com'])
        );
        $this->mailer->alwaysFail('still broken');
        $this->login();

        $csrf = $this->csrfFrom($this->get('/admin/submissions'));
        $response = $this->post("/admin/submissions/{$sid}/retry", ['_csrf' => $csrf]);

        self::assertSame(302, $response->getStatusCode());
        $row = $this->submissions->findById($sid);
        self::assertSame('failed', $row['status']);
        self::assertSame(2, (int) $row['attempts'], 'attempt counter advanced');
    }

    public function testRetryRejectsNonFailedSubmission(): void
    {
        $id = (int) $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com'])['id'];
        $sid = $this->insertSubmission($id, self::TODAY . ' 08:00:00', 'sent');
        $this->login();

        $csrf = $this->csrfFrom($this->get('/admin/submissions'));
        $this->post("/admin/submissions/{$sid}/retry", ['_csrf' => $csrf]);

        self::assertSame(0, $this->mailer->callCount(), 'a sent submission is not retried');
    }

    public function testBulkRetryDue(): void
    {
        $id = (int) $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com'])['id'];
        // Two due failed submissions (next_attempt_at in the past) and one sent.
        $s1 = $this->insertSubmission($id, self::TODAY . ' 08:00:00', 'failed', 1, 'e1', '{}', self::TODAY . ' 00:00:00');
        $s2 = $this->insertSubmission($id, self::TODAY . ' 08:00:00', 'failed', 1, 'e2', '{}', self::TODAY . ' 00:00:00');
        $this->insertSubmission($id, self::TODAY . ' 08:00:00', 'sent');
        $this->login();

        $csrf = $this->csrfFrom($this->get('/admin/submissions'));
        $response = $this->post('/admin/submissions/retry-due', ['_csrf' => $csrf]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('sent', $this->submissions->findById($s1)['status']);
        self::assertSame('sent', $this->submissions->findById($s2)['status']);
        self::assertSame(2, $this->mailer->callCount());
    }

    // --- CSP & assets -----------------------------------------------------

    public function testCspPresentOnAdminResponses(): void
    {
        $this->login();
        $response = $this->get('/admin');

        $csp = $response->getHeaderLine('Content-Security-Policy');
        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringContainsString("style-src 'self'", $csp);
        self::assertStringContainsString("img-src 'self' data:", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function testCspPresentOnLoginPage(): void
    {
        $response = $this->get('/admin/login');
        self::assertNotSame('', $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testCspAbsentOnPublicApiResponses(): void
    {
        $health = $this->get('/health');
        self::assertSame('', $health->getHeaderLine('Content-Security-Policy'));

        // Token endpoint (public API) likewise carries no CSP.
        $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $token = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/v1/form/x/token', ['REMOTE_ADDR' => '203.0.113.5'])
        );
        self::assertSame('', $token->getHeaderLine('Content-Security-Policy'));
    }

    public function testPublicApiResponsesCarryNosniffButNoFramingHeaders(): void
    {
        // The public JSON API carries the app-wide baseline (nosniff) but not
        // the document-only framing/referrer/cache headers.
        $health = $this->get('/health');
        self::assertSame('nosniff', $health->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('', $health->getHeaderLine('X-Frame-Options'));
        self::assertSame('', $health->getHeaderLine('Content-Security-Policy'));

        $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $token = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('GET', '/v1/form/x/token', ['REMOTE_ADDR' => '203.0.113.5'])
        );
        self::assertSame('nosniff', $token->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('', $token->getHeaderLine('X-Frame-Options'));
    }

    public function testVendoredAndEnhancementAssetsExist(): void
    {
        $assets = dirname(__DIR__, 2) . '/public/assets';
        // The design-system contract: a single token file, the bespoke
        // stylesheet, the blocking theme bootstrap and the deferred enhancer.
        self::assertFileExists($assets . '/tokens.css');
        self::assertFileExists($assets . '/admin.css');
        self::assertFileExists($assets . '/theme-init.js');
        self::assertFileExists($assets . '/admin.js');
        self::assertFileExists($assets . '/vendor/qrcode.js');

        // Pico.css was retired in 5d; the old theme.js apply path is gone.
        self::assertFileDoesNotExist($assets . '/vendor/pico.min.css');
        self::assertFileDoesNotExist($assets . '/theme.js');

        // qrcode.js keeps its pinned version + licence provenance.
        self::assertStringContainsString('1.4.4', file_get_contents($assets . '/vendor/qrcode.js'));
        self::assertStringContainsString('MIT', file_get_contents($assets . '/vendor/qrcode.js'));

        // tokens.css records the Primer provenance it was vendored from.
        $tokens = (string) file_get_contents($assets . '/tokens.css');
        self::assertStringContainsString('primer', strtolower($tokens));
    }

    public function testEveryAdminScreenReferencesTheDesignSystem(): void
    {
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $this->login();

        $routes = [
            'dashboard'     => '/admin',
            'forms list'    => '/admin/forms',
            'form create'   => '/admin/forms/new',
            'form edit'     => '/admin/forms/' . $form['id'] . '/edit',
            'submissions'   => '/admin/submissions',
            'totp setup'    => '/admin/totp/setup',
            'account'       => '/admin/account',
            'admins'        => '/admin/admins',
        ];

        foreach ($routes as $label => $route) {
            $response = $this->get($route);
            self::assertSame(200, $response->getStatusCode(), "{$label} ({$route}) did not return 200");
            $body = (string) $response->getBody();
            foreach (['/assets/tokens.css', '/assets/admin.css', '/assets/theme-init.js'] as $asset) {
                self::assertStringContainsString(
                    $asset,
                    $body,
                    "{$label} ({$route}) is missing the shared layout's {$asset} reference"
                );
            }
            self::assertStringNotContainsString('/assets/vendor/pico.min.css', $body, "{$label} still references pico");
        }
    }

    public function testEveryAdminAssetUrlCarriesTheCurrentVersion(): void
    {
        // Structural cache-busting: every <link>/<script> pointing at
        // public/assets/ must carry ?v=<Version::STRING> so a released CSS/JS
        // change is never served stale from a browser cache. No unversioned
        // /assets/ URL may appear on any rendered admin screen.
        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $this->login();

        $version = \OpenSendForm\Version::STRING;
        $routes = [
            'dashboard'   => '/admin',
            'forms list'  => '/admin/forms',
            'form edit'   => '/admin/forms/' . $form['id'] . '/edit',
            'submissions' => '/admin/submissions',
            'totp setup'  => '/admin/totp/setup', // exercises the extraScripts (qrcode.js) path
            'account'     => '/admin/account',
            'admins'      => '/admin/admins',
        ];

        foreach ($routes as $label => $route) {
            $body = (string) $this->get($route)->getBody();
            preg_match_all('/(?:src|href)="(\/assets\/[^"]*)"/', $body, $m);
            self::assertNotEmpty($m[1], "{$label} ({$route}) emitted no /assets/ URL at all");
            foreach ($m[1] as $url) {
                self::assertStringContainsString(
                    '?v=' . $version,
                    $url,
                    "{$label} ({$route}) has an UNVERSIONED asset URL: {$url}"
                );
            }
        }
    }

    public function testLayoutReferencesSelfHostedAssets(): void
    {
        // The login page now wears the same chrome-only appbar as every other
        // page (the chrome-free login was reversed). Its ASSETS must still all
        // be self-hosted and versioned; the only external reference permitted is
        // the header's Docs link (an <a>, never a stylesheet/script).
        $body = (string) $this->get('/admin/login')->getBody();
        $version = \OpenSendForm\Version::STRING;
        self::assertStringContainsString('/assets/tokens.css?v=' . $version, $body);
        self::assertStringContainsString('/assets/admin.css?v=' . $version, $body);
        self::assertStringContainsString('/assets/theme-init.js?v=' . $version, $body);
        self::assertStringContainsString('/assets/admin.js?v=' . $version, $body);
        self::assertStringNotContainsString('/assets/vendor/pico.min.css', $body);

        // No external stylesheet or script: every <link>/<script> asset URL is
        // a same-origin /assets/ path, never an off-host http(s) URL.
        preg_match_all('/<(?:link|script)\b[^>]*\b(?:href|src)="([^"]*)"/i', $body, $m);
        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $url) {
            self::assertStringStartsWith('/assets/', $url, "Non-self-hosted asset URL: {$url}");
        }

        // The chrome-only header carries the brand + Docs but NO account menu.
        self::assertStringContainsString('class="osf-appbar"', $body);
        self::assertStringNotContainsString('osf-account-menu', $body);
    }

    public function testTopNavRendersWithActiveLinkAndDocsLink(): void
    {
        $this->login();
        $body = (string) $this->get('/admin')->getBody();

        // Header bar, not a sidebar/docs layout.
        self::assertStringContainsString('class="osf-header"', $body);
        self::assertStringNotContainsString('<aside', $body);

        // The header carries only brand + right-aligned account controls —
        // the primary destinations live in the tab bar beneath it (the
        // second of the two header rows).
        self::assertStringContainsString('class="osf-tabnav"', $body);
        self::assertMatchesRegularExpression(
            '/<a class="osf-tab-link" href="\/admin" aria-current="page"><svg[^>]*>.*?<\/svg> Dashboard<\/a>/s',
            $body
        );
        // Every tab carries its Lucide icon before the label.
        foreach (['Dashboard', 'Forms', 'Submissions', 'Email', 'Admins'] as $label) {
            self::assertMatchesRegularExpression(
                '/<a class="osf-tab-link"[^>]*><svg[^>]*>.*?<\/svg> ' . $label . '<\/a>/s',
                $body,
                "{$label} tab is missing its icon"
            );
        }
        // Account is a header control (a dropdown trigger), not a tab.
        self::assertStringNotContainsString('>Account<', $body);

        // The admin name is a <details>/<summary> dropdown trigger (no JS
        // required to open it, CSP-safe) carrying a chevron-down caret.
        self::assertStringContainsString('<details class="osf-account-menu">', $body);
        self::assertMatchesRegularExpression(
            '/<summary class="osf-nav-link osf-admin-name">\s*The Boss/',
            $body
        );
        self::assertStringContainsString('osf-account-caret', $body);

        // The menu panel holds "Your account" and the logout form (styled as
        // a menu item); there is no standalone logout button outside it.
        self::assertMatchesRegularExpression(
            '/<a class="osf-account-item" href="\/admin\/account">Your account<\/a>/',
            $body
        );
        self::assertMatchesRegularExpression(
            '#<form method="post" action="/admin/logout" class="osf-inline-form">.*?osf-account-item--danger.*?Log out.*?</form>#s',
            $body
        );
        self::assertSame(
            1,
            substr_count($body, 'action="/admin/logout"'),
            'Exactly one logout form — no standalone logout button remains'
        );

        // External Docs link: new tab + noopener, and a rendered inline icon.
        self::assertStringContainsString(
            'href="https://opensendform.com"',
            $body
        );
        self::assertStringContainsString('target="_blank"', $body);
        self::assertStringContainsString('rel="noopener"', $body);
        self::assertStringContainsString('<svg', $body); // Lucide icons render inline

        // Theme toggle present with an accessible label.
        self::assertStringContainsString('data-theme-toggle', $body);
    }

    public function testAdminTemplatesContainNoInlineHandlersOrScripts(): void
    {
        $dir = dirname(__DIR__, 2) . '/templates/admin';
        foreach (glob($dir . '/*.php') as $file) {
            $html = (string) file_get_contents($file);

            self::assertDoesNotMatchRegularExpression(
                '/\son[a-z]+\s*=\s*["\']/i',
                $html,
                "Inline event handler in {$file}"
            );
            self::assertStringNotContainsString('javascript:', $html, "javascript: URI in {$file}");

            // Every <script> must be an external src (no inline script bodies).
            if (preg_match_all('/<script\b[^>]*>/i', $html, $tags)) {
                foreach ($tags[0] as $tag) {
                    self::assertStringContainsString('src=', $tag, "Inline <script> in {$file}: {$tag}");
                }
            }
        }
    }

    // --- Field-spacing audit: no orphan controls ---------------------------

    public function testEveryAdminScreenWrapsControlsInTheFieldPattern(): void
    {
        // Checked unauthenticated, before login changes the redirect target.
        AdminUiFieldWrapperAssertions::assertNoOrphanControls(
            (string) $this->get('/admin/login')->getBody(),
            'login'
        );

        $form = $this->forms->createForm('Contact', 'owner@example.com', ['https://example.com']);
        $this->login();

        $routes = [
            'dashboard'     => '/admin',
            'forms list'    => '/admin/forms',
            'form create'   => '/admin/forms/new',
            'form edit'     => '/admin/forms/' . $form['id'] . '/edit',
            'submissions'   => '/admin/submissions',
            'mail'          => '/admin/mail',
            'account'       => '/admin/account',
            'admins'        => '/admin/admins',
        ];

        foreach ($routes as $label => $route) {
            $body = (string) $this->get($route)->getBody();
            AdminUiFieldWrapperAssertions::assertNoOrphanControls($body, $label);
        }
    }

    // --- Helpers ----------------------------------------------------------

    private function login(): void
    {
        $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        $csrf = $this->csrfFrom($this->get('/admin/login'));
        $this->post('/admin/login', [
            '_csrf'    => $csrf,
            'email'    => 'boss@example.com',
            'password' => self::PASSWORD,
        ]);
    }

    /**
     * Insert a submission with a controlled created_at/status for the tests.
     */
    private function insertSubmission(
        int $formId,
        string $createdAt,
        string $status,
        int $attempts = 0,
        ?string $lastError = null,
        ?string $content = null,
        ?string $nextAttemptAt = null
    ): int {
        $this->db->execute(
            'INSERT INTO submissions
                (form_id, created_at, remote_ip, origin, user_agent, status, content,
                 attempts, last_error, next_attempt_at)
             VALUES
                (:form_id, :created_at, :remote_ip, :origin, :user_agent, :status, :content,
                 :attempts, :last_error, :next_attempt_at)',
            [
                'form_id'         => $formId,
                'created_at'      => $createdAt,
                'remote_ip'       => '198.51.100.7',
                'origin'          => 'https://example.com',
                'user_agent'      => 'Test/1.0',
                'status'          => $status,
                'content'         => $content,
                'attempts'        => $attempts,
                'last_error'      => $lastError,
                'next_attempt_at' => $nextAttemptAt,
            ]
        );

        return (int) $this->db->pdo()->lastInsertId();
    }

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
