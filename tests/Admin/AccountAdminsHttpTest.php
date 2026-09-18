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
use OpenSendForm\Tests\Support\AdminUiFieldWrapperAssertions;
use OpenSendForm\Tests\Support\FakeSession;
use OpenSendForm\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * HTTP-level coverage of increment 5c: the self-service account screen, the
 * admins management screen (with the last-active guard), inactive-admin login
 * refusal and live-session invalidation, the dashboard 2FA nudge, the 2FA
 * disable flow, and the recovery-screen copy. Driven through the app factory
 * against an in-memory database with a fixed clock and array-backed session.
 */
final class AccountAdminsHttpTest extends TestCase
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

        $config = Config::fromValues(['APP_ENV' => 'dev', 'APP_SECRET' => 'account-admins-secret']);
        $this->app = AppFactory::create($config, $this->db, null, $this->clock, null, null, $this->session);
    }

    // --- Account: display name -------------------------------------------

    public function testAccountScreenRendersAndNavHasAccountLink(): void
    {
        $this->createAndLogin('boss@example.com', 'The Boss');

        $body = (string) $this->get('/admin/account')->getBody();
        self::assertStringContainsString('Your account', $body);
        self::assertStringContainsString('boss@example.com', $body);
        // Nav shows the admin name as the account dropdown's trigger, and the
        // dropdown panel links to the account screen.
        self::assertMatchesRegularExpression('/<summary class="osf-nav-link osf-admin-name">\s*The Boss/', $body);
        self::assertStringContainsString('<a class="osf-account-item" href="/admin/account">Your account</a>', $body);
    }

    public function testChangeDisplayName(): void
    {
        $admin = $this->createAndLogin('boss@example.com', 'The Boss');

        $csrf = $this->csrfFrom($this->get('/admin/account'));
        $response = $this->post('/admin/account/name', ['_csrf' => $csrf, 'display_name' => 'Renamed Boss']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('Renamed Boss', $this->admins->findById($admin['id'])['display_name']);
        self::assertStringContainsString('Display name updated.', (string) $this->get('/admin/account')->getBody());
    }

    public function testChangeDisplayNameRejectsEmpty(): void
    {
        $admin = $this->createAndLogin('boss@example.com', 'The Boss');

        $csrf = $this->csrfFrom($this->get('/admin/account'));
        $this->post('/admin/account/name', ['_csrf' => $csrf, 'display_name' => '   ']);

        self::assertSame('The Boss', $this->admins->findById($admin['id'])['display_name']);
    }

    // --- Account: email ---------------------------------------------------

    public function testChangeEmailRequiresCorrectCurrentPassword(): void
    {
        $admin = $this->createAndLogin('boss@example.com', 'The Boss');

        $csrf = $this->csrfFrom($this->get('/admin/account'));
        $this->post('/admin/account/email', [
            '_csrf'            => $csrf,
            'email'            => 'new@example.com',
            'current_password' => 'wrong-password',
        ]);

        self::assertSame('boss@example.com', $this->admins->findById($admin['id'])['email']);
        self::assertStringContainsString(
            'That is not your current password.',
            (string) $this->get('/admin/account')->getBody()
        );
    }

    public function testChangeEmailHappyPath(): void
    {
        $admin = $this->createAndLogin('boss@example.com', 'The Boss');

        $csrf = $this->csrfFrom($this->get('/admin/account'));
        $response = $this->post('/admin/account/email', [
            '_csrf'            => $csrf,
            'email'            => 'NewBoss@Example.com',
            'current_password' => self::PASSWORD,
        ]);

        self::assertSame(302, $response->getStatusCode());
        // Normalised to lower case.
        self::assertSame('newboss@example.com', $this->admins->findById($admin['id'])['email']);
    }

    public function testChangeEmailRejectsDuplicate(): void
    {
        $this->admins->createAdmin('other@example.com', 'Other', self::PASSWORD);
        $admin = $this->createAndLogin('boss@example.com', 'The Boss');

        $csrf = $this->csrfFrom($this->get('/admin/account'));
        $this->post('/admin/account/email', [
            '_csrf'            => $csrf,
            'email'            => 'other@example.com',
            'current_password' => self::PASSWORD,
        ]);

        self::assertSame('boss@example.com', $this->admins->findById($admin['id'])['email']);
        self::assertStringContainsString(
            'already in use',
            (string) $this->get('/admin/account')->getBody()
        );
    }

    public function testChangeEmailRejectsInvalid(): void
    {
        $admin = $this->createAndLogin('boss@example.com', 'The Boss');

        $csrf = $this->csrfFrom($this->get('/admin/account'));
        $this->post('/admin/account/email', [
            '_csrf'            => $csrf,
            'email'            => 'not-an-email',
            'current_password' => self::PASSWORD,
        ]);

        self::assertSame('boss@example.com', $this->admins->findById($admin['id'])['email']);
    }

    // --- Account: password ------------------------------------------------

    public function testChangePasswordRequiresCorrectCurrentPassword(): void
    {
        $admin = $this->createAndLogin('boss@example.com', 'The Boss');

        $csrf = $this->csrfFrom($this->get('/admin/account'));
        $this->post('/admin/account/password', [
            '_csrf'            => $csrf,
            'current_password' => 'wrong-password',
            'new_password'     => 'a-brand-new-strong-password',
            'confirm_password' => 'a-brand-new-strong-password',
        ]);

        // The old password still verifies — unchanged.
        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        self::assertTrue($hasher->verify(self::PASSWORD, $this->admins->findById($admin['id'])['password_hash']));
    }

    public function testChangePasswordRejectsMismatch(): void
    {
        $admin = $this->createAndLogin('boss@example.com', 'The Boss');

        $csrf = $this->csrfFrom($this->get('/admin/account'));
        $this->post('/admin/account/password', [
            '_csrf'            => $csrf,
            'current_password' => self::PASSWORD,
            'new_password'     => 'a-brand-new-strong-password',
            'confirm_password' => 'different-strong-password!',
        ]);

        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        self::assertTrue($hasher->verify(self::PASSWORD, $this->admins->findById($admin['id'])['password_hash']));
    }

    public function testChangePasswordRejectsTooShort(): void
    {
        $admin = $this->createAndLogin('boss@example.com', 'The Boss');

        $csrf = $this->csrfFrom($this->get('/admin/account'));
        $this->post('/admin/account/password', [
            '_csrf'            => $csrf,
            'current_password' => self::PASSWORD,
            'new_password'     => 'short',
            'confirm_password' => 'short',
        ]);

        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        self::assertTrue($hasher->verify(self::PASSWORD, $this->admins->findById($admin['id'])['password_hash']));
    }

    public function testChangePasswordSuccessRegeneratesSession(): void
    {
        $admin = $this->createAndLogin('boss@example.com', 'The Boss');
        $regenBefore = $this->session->regenerateCount();

        $csrf = $this->csrfFrom($this->get('/admin/account'));
        $response = $this->post('/admin/account/password', [
            '_csrf'            => $csrf,
            'current_password' => self::PASSWORD,
            'new_password'     => 'a-brand-new-strong-password',
            'confirm_password' => 'a-brand-new-strong-password',
        ]);

        self::assertSame(302, $response->getStatusCode());
        $hasher = new PasswordHasher(PASSWORD_BCRYPT);
        self::assertTrue($hasher->verify('a-brand-new-strong-password', $this->admins->findById($admin['id'])['password_hash']));
        self::assertGreaterThan($regenBefore, $this->session->regenerateCount(), 'session id must rotate');
    }

    // --- Admins list / create --------------------------------------------

    public function testAdminsListShowsColumnsAndBadges(): void
    {
        $this->admins->createAdmin('second@example.com', 'Second Admin', self::PASSWORD);
        $this->createAndLogin('boss@example.com', 'The Boss');

        $body = (string) $this->get('/admin/admins')->getBody();
        self::assertStringContainsString('second@example.com', $body);
        self::assertStringContainsString('Second Admin', $body);
        self::assertStringContainsString('The Boss', $body);
        // 2FA off badge present; Status is a switch, not a badge (both admins
        // are active, so an "on" switch is shown for each).
        self::assertStringContainsString('osf-badge--muted', $body);
        self::assertSame(2, substr_count($body, 'class="osf-switch"'));
        self::assertSame(2, substr_count($body, 'aria-pressed="true"'));
        // Last login shows "never" for the second (never signed in) admin.
        self::assertStringContainsString('never', $body);
    }

    public function testCreateAdmin(): void
    {
        $this->createAndLogin('boss@example.com', 'The Boss');

        $csrf = $this->csrfFrom($this->get('/admin/admins'));
        $response = $this->post('/admin/admins', [
            '_csrf'    => $csrf,
            'email'    => 'New@Example.com',
            'name'     => 'New Admin',
            'password' => 'a-strong-initial-pw',
        ]);

        self::assertSame(302, $response->getStatusCode());
        $created = $this->admins->findByEmail('new@example.com');
        self::assertNotNull($created);
        self::assertSame('New Admin', $created['display_name']);
        self::assertSame(1, $created['is_active']);
    }

    public function testCreateAdminRejectsShortPassword(): void
    {
        $this->createAndLogin('boss@example.com', 'The Boss');

        $csrf = $this->csrfFrom($this->get('/admin/admins'));
        $response = $this->post('/admin/admins', [
            '_csrf'    => $csrf,
            'email'    => 'weak@example.com',
            'name'     => 'Weak',
            'password' => 'short',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('at least 12 characters', (string) $response->getBody());
        self::assertNull($this->admins->findByEmail('weak@example.com'));
        // Input preserved on re-render.
        self::assertStringContainsString('value="weak@example.com"', (string) $response->getBody());
    }

    public function testCreateAdminRejectsDuplicateEmail(): void
    {
        $this->createAndLogin('boss@example.com', 'The Boss');

        $csrf = $this->csrfFrom($this->get('/admin/admins'));
        $response = $this->post('/admin/admins', [
            '_csrf'    => $csrf,
            'email'    => 'boss@example.com',
            'name'     => 'Impostor',
            'password' => 'a-strong-initial-pw',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('already exists', (string) $response->getBody());
    }

    // --- Deactivate / reactivate + last-active guard ---------------------

    public function testDeactivateAndReactivateAnotherAdmin(): void
    {
        $second = $this->admins->createAdmin('second@example.com', 'Second', self::PASSWORD);
        $this->createAndLogin('boss@example.com', 'The Boss');

        $csrf = $this->csrfFrom($this->get('/admin/admins'));
        $this->post('/admin/admins/' . $second['id'] . '/deactivate', ['_csrf' => $csrf]);
        self::assertSame(0, $this->admins->findById($second['id'])['is_active']);

        $csrf = $this->csrfFrom($this->get('/admin/admins'));
        $this->post('/admin/admins/' . $second['id'] . '/reactivate', ['_csrf' => $csrf]);
        self::assertSame(1, $this->admins->findById($second['id'])['is_active']);
    }

    public function testLastActiveAdminCannotBeDeactivatedServerSide(): void
    {
        $admin = $this->createAndLogin('boss@example.com', 'The Boss');

        // Switch is disabled in the UI, with a title explaining why…
        $body = (string) $this->get('/admin/admins')->getBody();
        self::assertStringNotContainsString('/admin/admins/' . $admin['id'] . '/deactivate', $body);
        self::assertStringContainsString('last active admin', $body);
        self::assertMatchesRegularExpression(
            '/<button type="button" class="osf-switch" role="switch" aria-pressed="true" disabled\s+title="The last active admin cannot be deactivated\."/',
            $body
        );

        // …and the action is refused server-side even if forced.
        $csrf = $this->csrfFrom($this->get('/admin/admins'));
        $this->post('/admin/admins/' . $admin['id'] . '/deactivate', ['_csrf' => $csrf]);
        self::assertSame(1, $this->admins->findById($admin['id'])['is_active'], 'still active');
        self::assertStringContainsString(
            'cannot deactivate the last active admin',
            (string) $this->get('/admin/admins')->getBody()
        );
    }

    public function testSelfDeactivationAllowedWhenNotLastActive(): void
    {
        // A second active admin exists, so deactivating yourself is allowed.
        $this->admins->createAdmin('second@example.com', 'Second', self::PASSWORD);
        $admin = $this->createAndLogin('boss@example.com', 'The Boss');

        $csrf = $this->csrfFrom($this->get('/admin/admins'));
        $this->post('/admin/admins/' . $admin['id'] . '/deactivate', ['_csrf' => $csrf]);

        self::assertSame(0, $this->admins->findById($admin['id'])['is_active']);
        // The now-inactive admin's live session is invalidated on next request.
        self::assertSame(302, $this->get('/admin')->getStatusCode());
    }

    // --- Delete: guard matrix ---------------------------------------------

    public function testDeleteButtonHiddenForSelfAndShownForOthers(): void
    {
        $second = $this->admins->createAdmin('second@example.com', 'Second', self::PASSWORD);
        $admin = $this->createAndLogin('boss@example.com', 'The Boss');

        $body = (string) $this->get('/admin/admins')->getBody();
        self::assertStringNotContainsString('/admin/admins/' . $admin['id'] . '/delete', $body);
        self::assertStringContainsString('/admin/admins/' . $second['id'] . '/delete', $body);
    }

    public function testSelfDeleteRefusedEvenByForgedPost(): void
    {
        $this->admins->createAdmin('second@example.com', 'Second', self::PASSWORD);
        $admin = $this->createAndLogin('boss@example.com', 'The Boss');

        $csrf = $this->csrfFrom($this->get('/admin/admins'));
        $this->post('/admin/admins/' . $admin['id'] . '/delete', [
            '_csrf'            => $csrf,
            'current_password' => self::PASSWORD,
        ]);

        self::assertNotNull($this->admins->findById($admin['id']));
        self::assertStringContainsString(
            'cannot delete your own account',
            (string) $this->get('/admin/admins')->getBody()
        );
    }

    public function testLastActiveAdminCannotBeDeletedServerSide(): void
    {
        $admin = $this->createAndLogin('boss@example.com', 'The Boss');

        // Button is hidden in the UI…
        $body = (string) $this->get('/admin/admins')->getBody();
        self::assertStringNotContainsString('/admin/admins/' . $admin['id'] . '/delete', $body);

        // …and the action is refused server-side even if forced, with the
        // availability-guard message (not the generic self-delete one).
        $csrf = $this->csrfFrom($this->get('/admin/admins'));
        $this->post('/admin/admins/' . $admin['id'] . '/delete', [
            '_csrf'            => $csrf,
            'current_password' => self::PASSWORD,
        ]);
        self::assertNotNull($this->admins->findById($admin['id']));
        self::assertStringContainsString(
            'cannot delete the last active admin',
            (string) $this->get('/admin/admins')->getBody()
        );
    }

    public function testInactiveAdminIsAlwaysDeletable(): void
    {
        $second = $this->admins->createAdmin('second@example.com', 'Second', self::PASSWORD);
        $this->admins->setActive($second['id'], false);
        $this->createAndLogin('boss@example.com', 'The Boss');

        $csrf = $this->csrfFrom($this->get('/admin/admins'));
        $response = $this->post('/admin/admins/' . $second['id'] . '/delete', [
            '_csrf'            => $csrf,
            'current_password' => self::PASSWORD,
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertNull($this->admins->findById($second['id']));
    }

    public function testDeleteRejectsWrongPassword(): void
    {
        $second = $this->admins->createAdmin('second@example.com', 'Second', self::PASSWORD);
        $this->createAndLogin('boss@example.com', 'The Boss');

        $csrf = $this->csrfFrom($this->get('/admin/admins/' . $second['id'] . '/delete'));
        $response = $this->post('/admin/admins/' . $second['id'] . '/delete', [
            '_csrf'            => $csrf,
            'current_password' => 'wrong-password',
        ]);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('That is not your current password.', (string) $response->getBody());
        self::assertNotNull($this->admins->findById($second['id']));
    }

    public function testDeleteConfirmationScreenShowsTargetEmailAndPermanenceNotice(): void
    {
        $second = $this->admins->createAdmin('second@example.com', 'Second', self::PASSWORD);
        $this->createAndLogin('boss@example.com', 'The Boss');

        $body = (string) $this->get('/admin/admins/' . $second['id'] . '/delete')->getBody();
        self::assertStringContainsString('second@example.com', $body);
        self::assertStringContainsString('cannot be undone', $body);
        self::assertStringContainsString('current_password', $body);
    }

    public function testDeleteSuccessRemovesRowAndFlashes(): void
    {
        $second = $this->admins->createAdmin('second@example.com', 'Second', self::PASSWORD);
        $this->createAndLogin('boss@example.com', 'The Boss');

        $csrf = $this->csrfFrom($this->get('/admin/admins/' . $second['id'] . '/delete'));
        $response = $this->post('/admin/admins/' . $second['id'] . '/delete', [
            '_csrf'            => $csrf,
            'current_password' => self::PASSWORD,
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/admins', $response->getHeaderLine('Location'));
        self::assertNull($this->admins->findById($second['id']));
        $body = (string) $this->get('/admin/admins')->getBody();
        self::assertStringContainsString('Deleted', $body);
        self::assertStringContainsString('second@example.com', $body);
        self::assertStringContainsString('This cannot be undone.', $body);
    }

    // --- Inactive admin login & live-session invalidation ----------------

    public function testInactiveAdminCannotLogInGenerically(): void
    {
        $admin = $this->admins->createAdmin('ghost@example.com', 'Ghost', self::PASSWORD);
        $this->admins->setActive($admin['id'], false);

        $csrf = $this->csrfFrom($this->get('/admin/login'));
        $response = $this->post('/admin/login', [
            '_csrf'    => $csrf,
            'email'    => 'ghost@example.com',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(401, $response->getStatusCode());
        // Same generic message as a wrong password — no status disclosure.
        self::assertStringContainsString('Invalid email or password.', (string) $response->getBody());
        self::assertFalse($this->session->has('auth.admin_id'));
    }

    public function testLiveSessionInvalidatedWhenAdminDeactivated(): void
    {
        $admin = $this->createAndLogin('boss@example.com', 'The Boss');
        self::assertSame(200, $this->get('/admin')->getStatusCode());

        // Another operator deactivates this admin (repo bypasses the guard).
        $this->admins->setActive($admin['id'], false);

        self::assertSame(302, $this->get('/admin')->getStatusCode());
        self::assertSame('/admin/login', $this->get('/admin')->getHeaderLine('Location'));
    }

    // --- 2FA nudge banner -------------------------------------------------

    public function testNudgeShownWhenTotpDisabledAndHiddenAfterDismiss(): void
    {
        $this->createAndLogin('boss@example.com', 'The Boss');

        $body = (string) $this->get('/admin')->getBody();
        self::assertStringContainsString('Two-factor authentication is not enabled', $body);
        self::assertStringContainsString('/admin/nudge/dismiss', $body);

        $csrf = $this->csrfFrom($this->get('/admin'));
        $this->post('/admin/nudge/dismiss', ['_csrf' => $csrf]);

        self::assertStringNotContainsString(
            'Two-factor authentication is not enabled',
            (string) $this->get('/admin')->getBody()
        );
    }

    public function testNudgeAbsentWhenTotpEnabled(): void
    {
        $admin = $this->createAndLogin('boss@example.com', 'The Boss');
        // Enable TOTP directly; currentAdmin re-reads it on the next request.
        $this->admins->setTotp($admin['id'], $this->totp->generateSecret());
        $this->admins->enableTotp($admin['id']);

        self::assertStringNotContainsString(
            'Two-factor authentication is not enabled',
            (string) $this->get('/admin')->getBody()
        );
    }

    // --- 2FA disable gating matrix ---------------------------------------

    public function testDisableTotpRejectsWrongPassword(): void
    {
        [$admin, $secret] = $this->loginWithTotp();

        $csrf = $this->csrfFrom($this->get('/admin/totp/setup'));
        $response = $this->post('/admin/totp/disable', [
            '_csrf'            => $csrf,
            'current_password' => 'wrong-password',
            'code'             => $this->totp->codeAt($secret, $this->clock->now()),
        ]);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(1, $this->admins->findById($admin['id'])['totp_enabled'], 'still enabled');
    }

    public function testDisableTotpRejectsWrongCode(): void
    {
        [$admin] = $this->loginWithTotp();

        $csrf = $this->csrfFrom($this->get('/admin/totp/setup'));
        $response = $this->post('/admin/totp/disable', [
            '_csrf'            => $csrf,
            'current_password' => self::PASSWORD,
            'code'             => '000000',
        ]);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(1, $this->admins->findById($admin['id'])['totp_enabled'], 'still enabled');
    }

    public function testDisableTotpSuccessClearsStateAndReArmsNudge(): void
    {
        [$admin, $secret] = $this->loginWithTotp();

        $csrf = $this->csrfFrom($this->get('/admin/totp/setup'));
        $response = $this->post('/admin/totp/disable', [
            '_csrf'            => $csrf,
            'current_password' => self::PASSWORD,
            'code'             => $this->totp->codeAt($secret, $this->clock->now()),
        ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/admin/totp/setup', $response->getHeaderLine('Location'));

        $reloaded = $this->admins->findById($admin['id']);
        self::assertSame(0, $reloaded['totp_enabled']);
        self::assertNull($reloaded['totp_secret']);
        self::assertNull($reloaded['recovery_codes']);

        // The dashboard nudge returns now that 2FA is off again.
        self::assertStringContainsString(
            'Two-factor authentication is not enabled',
            (string) $this->get('/admin')->getBody()
        );
    }

    public function testDisableTotpRefusesTheAlreadyUsedLoginCode(): void
    {
        [$admin, $secret] = $this->loginWithTotp();

        // loginWithTotp consumed the code at T0 (recording its timestep) and
        // advanced the clock one period. That same login code is still inside
        // its +/- skew window, but re-using it for a sensitive action is a
        // replay across the shared per-admin counter, so it must be refused —
        // even with the correct password.
        $csrf = $this->csrfFrom($this->get('/admin/totp/setup'));
        $response = $this->post('/admin/totp/disable', [
            '_csrf'            => $csrf,
            'current_password' => self::PASSWORD,
            'code'             => $this->totp->codeAt($secret, self::T0),
        ]);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(1, $this->admins->findById($admin['id'])['totp_enabled'], 'still enabled — replay refused');
    }

    // --- Recovery-screen copy --------------------------------------------

    public function testLoginTotpScreenStatesRecoveryCodeGuidance(): void
    {
        $this->pendingTotp();

        $body = (string) $this->get('/admin/totp')->getBody();
        self::assertStringContainsString('Enter ONE of your recovery codes', $body);
        self::assertStringContainsString('10 characters', $body);
    }

    public function testRecoveryCodesScreenIsMonospaceBlockStatingSingleUse(): void
    {
        [$admin, $secret] = $this->loginWithTotp();

        // Regenerating recovery codes renders the display screen.
        $csrf = $this->csrfFrom($this->get('/admin/totp/setup'));
        $response = $this->post('/admin/totp/recovery-codes/regenerate', [
            '_csrf' => $csrf,
            'code'  => $this->totp->codeAt($secret, $this->clock->now()),
        ]);

        $body = (string) $response->getBody();
        self::assertStringContainsString('osf-recovery-block', $body);
        self::assertStringContainsString('exactly once', $body);
        self::assertStringContainsString('data-recovery-code', $body);
    }

    public function testRegenerateConfirmUsesSegmentedSixBoxComponent(): void
    {
        $this->loginWithTotp();

        $body = (string) $this->get('/admin/totp/setup')->getBody();
        // Same segmented enhancement hook as the login screen.
        self::assertStringContainsString('data-totp-code', $body);
    }

    // --- Field-spacing audit: no orphan controls ---------------------------

    public function testTotpAndDeleteScreensWrapControlsInTheFieldPattern(): void
    {
        // Pending TOTP login screen.
        [$admin, $secret] = $this->pendingTotp();
        AdminUiFieldWrapperAssertions::assertNoOrphanControls(
            (string) $this->get('/admin/totp')->getBody(),
            'totp (pending login)'
        );
        $this->post('/admin/totp', ['_csrf' => $this->csrfFrom($this->get('/admin/totp')), 'code' => $this->totp->codeAt($secret, $this->clock->now())]);

        // TOTP setup, "enabled" branch (regenerate + disable forms).
        AdminUiFieldWrapperAssertions::assertNoOrphanControls(
            (string) $this->get('/admin/totp/setup')->getBody(),
            'totp setup (enabled)'
        );

        // Advance a TOTP period so the regenerate re-auth uses a fresh code —
        // the login code just consumed can no longer be replayed.
        $this->clock->advance(30);

        // Recovery codes display screen (regenerate response body).
        $regenerate = $this->post('/admin/totp/recovery-codes/regenerate', [
            '_csrf' => $this->csrfFrom($this->get('/admin/totp/setup')),
            'code'  => $this->totp->codeAt($secret, $this->clock->now()),
        ]);
        AdminUiFieldWrapperAssertions::assertNoOrphanControls(
            (string) $regenerate->getBody(),
            'recovery codes'
        );

        // Admin delete confirmation screen.
        $second = $this->admins->createAdmin('second@example.com', 'Second', self::PASSWORD);
        AdminUiFieldWrapperAssertions::assertNoOrphanControls(
            (string) $this->get('/admin/admins/' . $second['id'] . '/delete')->getBody(),
            'admin delete confirm'
        );
    }

    public function testTotpSetupDisabledBranchWrapsControlsInTheFieldPattern(): void
    {
        $this->createAndLogin('boss@example.com', 'The Boss');
        AdminUiFieldWrapperAssertions::assertNoOrphanControls(
            (string) $this->get('/admin/totp/setup')->getBody(),
            'totp setup (disabled)'
        );
    }

    // --- Helpers ----------------------------------------------------------

    /**
     * @return array<string, mixed> the created, logged-in admin
     */
    private function createAndLogin(string $email, string $name): array
    {
        $admin = $this->admins->createAdmin($email, $name, self::PASSWORD);
        $csrf = $this->csrfFrom($this->get('/admin/login'));
        $this->post('/admin/login', ['_csrf' => $csrf, 'email' => $email, 'password' => self::PASSWORD]);

        return $admin;
    }

    /**
     * Create an admin with TOTP enabled + recovery codes, and park a pending
     * TOTP login (password step done, second factor outstanding).
     *
     * @return array{0: array<string, mixed>, 1: string} [admin, secret]
     */
    private function pendingTotp(): array
    {
        $admin = $this->admins->createAdmin('boss@example.com', 'The Boss', self::PASSWORD);
        $secret = $this->totp->generateSecret();
        $this->admins->setTotp($admin['id'], $secret);
        $this->admins->enableTotp($admin['id']);
        $recovery = new RecoveryCodes(new PasswordHasher(PASSWORD_BCRYPT));
        $this->admins->setRecoveryCodes($admin['id'], $recovery->generate()['hashes']);

        $csrf = $this->csrfFrom($this->get('/admin/login'));
        $this->post('/admin/login', ['_csrf' => $csrf, 'email' => 'boss@example.com', 'password' => self::PASSWORD]);

        return [$admin, $secret];
    }

    /**
     * As pendingTotp(), then complete the second factor so we are fully in.
     *
     * @return array{0: array<string, mixed>, 1: string} [admin, secret]
     */
    private function loginWithTotp(): array
    {
        [$admin, $secret] = $this->pendingTotp();
        $totpPage = $this->get('/admin/totp');
        $this->post('/admin/totp', [
            '_csrf' => $this->csrfFrom($totpPage),
            'code'  => $this->totp->codeAt($secret, $this->clock->now()),
        ]);

        // Replay protection records the timestep just consumed at login. Advance
        // one TOTP period so a later sensitive-action re-auth uses a fresh code,
        // exactly as a real admin would 30s on — reusing the login code is now
        // refused. Well within the idle timeout, so the session stays valid.
        $this->clock->advance(30);

        return [$admin, $secret];
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
}
