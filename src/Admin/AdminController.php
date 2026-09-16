<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\AuthService;
use OpenSendForm\Auth\Csrf;
use OpenSendForm\Auth\LoginOutcome;
use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\RecoveryCodes;
use OpenSendForm\Auth\SessionInterface;
use OpenSendForm\Auth\Totp;
use OpenSendForm\Auth\TotpOutcome;
use OpenSendForm\Config;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Server-rendered admin handlers.
 *
 * Handlers pull their collaborators from the container captured at route
 * registration, mirroring the public Routes class. Responses are plain HTML
 * rendered from templates/admin/; security headers are added by the group's
 * SecurityHeadersMiddleware, and protected routes by AuthMiddleware.
 */
final class AdminController
{
    /** Session key holding an in-progress TOTP enrolment secret. */
    private const SESSION_SETUP_SECRET = 'auth.totp_setup_secret';

    /** Session flag: the per-session dismissal of the 2FA enrolment nudge. */
    private const SESSION_NUDGE_DISMISSED = 'admin.nudge_dismissed';

    /** Session flag: the per-session dismissal of the email-setup nudge. */
    private const SESSION_MAIL_NUDGE_DISMISSED = 'admin.mail_nudge_dismissed';

    // --- Login ------------------------------------------------------------

    /**
     * GET /admin/login — render the sign-in form (or bounce to the dashboard
     * when already authenticated).
     */
    public static function loginForm(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (self::auth($c)->currentAdmin() !== null) {
            return self::redirect($response, '/admin');
        }

        return self::render($c, $response, 'login', [
            'title' => 'Admin sign in',
            'csrf'  => self::csrf($c)->token(),
            'email' => '',
            'error' => '',
        ]);
    }

    /**
     * POST /admin/login — verify credentials and route to TOTP or dashboard.
     */
    public static function login(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $data = self::formData($request);

        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            return self::render($c, $response, 'login', [
                'title' => 'Admin sign in',
                'csrf'  => self::csrf($c)->token(),
                'email' => (string) ($data['email'] ?? ''),
                'error' => 'Your session expired. Please try again.',
            ], 400);
        }

        $email = (string) ($data['email'] ?? '');
        $password = (string) ($data['password'] ?? '');
        $ip = self::remoteIp($request);

        $outcome = self::auth($c)->attemptLogin($email, $password, $ip);

        switch ($outcome) {
            case LoginOutcome::Success:
                return self::redirect($response, '/admin');
            case LoginOutcome::NeedsTotp:
                return self::redirect($response, '/admin/totp');
            case LoginOutcome::RateLimited:
                return self::render($c, $response, 'login', [
                    'title' => 'Admin sign in',
                    'csrf'  => self::csrf($c)->token(),
                    'email' => $email,
                    'error' => 'Too many attempts. Please try again later.',
                ], 429);
            case LoginOutcome::Invalid:
            default:
                // Same message whether the email is unknown or the password
                // is wrong — no user enumeration.
                return self::render($c, $response, 'login', [
                    'title' => 'Admin sign in',
                    'csrf'  => self::csrf($c)->token(),
                    'email' => $email,
                    'error' => 'Invalid email or password.',
                ], 401);
        }
    }

    // --- TOTP second factor ----------------------------------------------

    /**
     * GET /admin/totp — code entry (only when a password step is pending).
     */
    public static function totpForm(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $auth = self::auth($c);
        if ($auth->currentAdmin() !== null) {
            return self::redirect($response, '/admin');
        }
        if ($auth->pendingTotpAdminId() === null) {
            return self::redirect($response, '/admin/login');
        }

        return self::render($c, $response, 'totp', [
            'title' => 'Two-factor authentication',
            'csrf'  => self::csrf($c)->token(),
            'error' => '',
        ]);
    }

    /**
     * POST /admin/totp — verify a TOTP or recovery code.
     */
    public static function totp(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $auth = self::auth($c);
        $data = self::formData($request);

        if ($auth->pendingTotpAdminId() === null) {
            return self::redirect($response, '/admin/login');
        }

        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            return self::render($c, $response, 'totp', [
                'title' => 'Two-factor authentication',
                'csrf'  => self::csrf($c)->token(),
                'error' => 'Your session expired. Please try again.',
            ], 400);
        }

        $outcome = $auth->verifyTotp((string) ($data['code'] ?? ''), self::remoteIp($request));

        switch ($outcome) {
            case TotpOutcome::Success:
                return self::redirect($response, '/admin');
            case TotpOutcome::RateLimited:
                return self::render($c, $response, 'totp', [
                    'title' => 'Two-factor authentication',
                    'csrf'  => self::csrf($c)->token(),
                    'error' => 'Too many attempts. Please try again later.',
                ], 429);
            case TotpOutcome::Invalid:
            default:
                return self::render($c, $response, 'totp', [
                    'title' => 'Two-factor authentication',
                    'csrf'  => self::csrf($c)->token(),
                    'error' => 'Invalid code.',
                ], 401);
        }
    }

    // --- Logout -----------------------------------------------------------

    /**
     * POST /admin/logout — destroy the session.
     */
    public static function logout(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $data = self::formData($request);

        // A forged logout is low-impact, but still require the token; on a
        // bad token do nothing and return to the dashboard.
        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            return self::redirect($response, '/admin');
        }

        self::auth($c)->logout();

        return self::redirect($response, '/admin/login');
    }

    // --- Dashboard --------------------------------------------------------

    /**
     * GET /admin — dashboard: at-a-glance counts plus the most recent
     * failed/dead submissions (metadata only), each linking through to the
     * filtered submissions view.
     */
    public static function dashboard(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $admin = self::auth($c)->currentAdmin();
        // AuthMiddleware guarantees a logged-in admin; guard defensively.
        if ($admin === null) {
            return self::redirect($response, '/admin/login');
        }

        /** @var \OpenSendForm\Form\FormRepository $forms */
        $forms = $c->get(\OpenSendForm\Form\FormRepository::class);
        /** @var \OpenSendForm\Submission\SubmissionRepository $submissions */
        $submissions = $c->get(\OpenSendForm\Submission\SubmissionRepository::class);

        $todayStart = gmdate('Y-m-d', self::clockNow($c)) . ' 00:00:00';

        $totpEnabled = (int) $admin['totp_enabled'] === 1;
        $nudgeDismissed = self::session($c)->get(self::SESSION_NUDGE_DISMISSED) === true;

        $mailEnabled = self::config($c)->mailEnabled();
        $mailNudgeDismissed = self::session($c)->get(self::SESSION_MAIL_NUDGE_DISMISSED) === true;

        // Cheap schema-staleness check: a directory listing plus one SELECT, no
        // schema changes. Never auto-migrates — just tells the admin to run
        // `bin/osf migrate` when code shipped a migration this database hasn't
        // seen yet (the only way to apply one on an already-installed instance).
        $db = $c->get(\OpenSendForm\Storage\Database::class);
        $paths = $c->get(\OpenSendForm\Install\Paths::class);
        $pendingMigrations = (new \OpenSendForm\Storage\MigrationRunner($db, $paths->migrationsPath))->pendingCount();

        // The dashboard must render even mid-upgrade so the "run bin/osf migrate"
        // banner is visible. A stale schema may lack columns/tables this code
        // reads (e.g. submissions.is_synthetic and monitor_checks from migration
        // 010), so the schema-dependent queries run ONLY when the schema is
        // current; while a migration is pending the counts default to zero and
        // the banner does the talking. Once migrated, the real figures return.
        $schemaCurrent = $pendingMigrations === 0;

        // Synthetic-monitoring banner: forms whose LATEST check failed. Shown
        // until a later passing check clears them (state lives in monitor_checks).
        $monitorFailures = $schemaCurrent
            ? (new \OpenSendForm\Monitor\MonitorRepository($db))->failingForms()
            : [];

        return AdminView::renderPage($c, $response, 'dashboard', [
            'title'        => 'Dashboard',
            'totpEnabled'  => $totpEnabled,
            'monitorFailures' => $monitorFailures,
            // Nudge shows only when 2FA is off and the admin has not dismissed
            'showNudge'    => !$totpEnabled && !$nudgeDismissed,
            // Mirror the 2FA nudge for email: shown while sending is off and the
            // admin has not dismissed it this session.
            'showMailNudge' => !$mailEnabled && !$mailNudgeDismissed,
            'pendingMigrations' => $pendingMigrations,
            'activeForms'  => $forms->countActive(),
            'todayCount'   => $schemaCurrent ? $submissions->countSince($todayStart) : 0,
            'failedCount'  => $schemaCurrent ? $submissions->countByStatus('failed') : 0,
            'deadCount'    => $schemaCurrent ? $submissions->countByStatus('dead') : 0,
            'recent'       => $schemaCurrent ? $submissions->recentByStatuses(['failed', 'dead'], 10) : [],
        ], 'dashboard');
    }

    /**
     * POST /admin/nudge/dismiss — dismiss the 2FA enrolment nudge for the rest
     * of this session. A no-op with a bad CSRF token; either way returns to the
     * dashboard.
     */
    public static function dismissNudge(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $data = self::formData($request);
        if (self::csrf($c)->validate($data['_csrf'] ?? null)) {
            self::session($c)->set(self::SESSION_NUDGE_DISMISSED, true);
        }

        return self::redirect($response, '/admin');
    }

    /**
     * POST /admin/nudge/mail/dismiss — dismiss the email-setup nudge for the
     * rest of this session (mirrors dismissNudge). A no-op on a bad CSRF token;
     * either way returns to the dashboard.
     */
    public static function dismissMailNudge(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $data = self::formData($request);
        if (self::csrf($c)->validate($data['_csrf'] ?? null)) {
            self::session($c)->set(self::SESSION_MAIL_NUDGE_DISMISSED, true);
        }

        return self::redirect($response, '/admin');
    }

    // --- TOTP enrolment ---------------------------------------------------

    /**
     * GET /admin/totp/setup — enrolment (when TOTP is off) or recovery-code
     * regeneration (when it is already on).
     */
    public static function totpSetupForm(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $admin = self::auth($c)->currentAdmin();
        if ($admin === null) {
            return self::redirect($response, '/admin/login');
        }

        if ((int) $admin['totp_enabled'] === 1) {
            return self::renderTotpSetup($c, $response, [
                'title'   => 'Two-factor authentication',
                'enabled' => true,
                'error'   => '',
            ]);
        }

        $secret = self::currentOrNewSetupSecret($c);

        return self::renderTotpSetup($c, $response, self::enrolmentVars($c, $admin, $secret));
    }

    /**
     * POST /admin/totp/setup — confirm and enable TOTP, then show the newly
     * generated recovery codes once.
     */
    public static function totpSetup(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $admin = self::auth($c)->currentAdmin();
        if ($admin === null) {
            return self::redirect($response, '/admin/login');
        }
        if ((int) $admin['totp_enabled'] === 1) {
            return self::redirect($response, '/admin/totp/setup');
        }

        $data = self::formData($request);
        $session = self::session($c);
        $secret = $session->get(self::SESSION_SETUP_SECRET);
        if (!is_string($secret) || $secret === '') {
            return self::redirect($response, '/admin/totp/setup');
        }

        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            return self::renderTotpSetup($c, $response, self::enrolmentVars(
                $c,
                $admin,
                $secret,
                'Your session expired. Please try again.'
            ), 400);
        }

        $totp = self::totpService($c);
        if (!$totp->verify($secret, (string) ($data['code'] ?? ''), self::clockNow($c))) {
            return self::renderTotpSetup($c, $response, self::enrolmentVars(
                $c,
                $admin,
                $secret,
                'That code did not match. Try again.'
            ), 401);
        }

        $admins = self::admins($c);
        $admins->setTotp($admin['id'], $secret);
        $admins->enableTotp($admin['id']);
        $session->remove(self::SESSION_SETUP_SECRET);

        return self::issueRecoveryCodes($c, $response, $admin['id']);
    }

    /**
     * POST /admin/totp/recovery-codes/regenerate — replace recovery codes,
     * gated on a current TOTP code.
     */
    public static function regenerateRecoveryCodes(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $admin = self::auth($c)->currentAdmin();
        if ($admin === null) {
            return self::redirect($response, '/admin/login');
        }
        if ((int) $admin['totp_enabled'] !== 1 || $admin['totp_secret'] === null) {
            return self::redirect($response, '/admin/totp/setup');
        }

        $data = self::formData($request);

        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            return self::renderTotpSetup($c, $response, [
                'title'   => 'Two-factor authentication',
                'enabled' => true,
                'error'   => 'Your session expired. Please try again.',
            ], 400);
        }

        $totp = self::totpService($c);
        if (!$totp->verify($admin['totp_secret'], (string) ($data['code'] ?? ''), self::clockNow($c))) {
            return self::renderTotpSetup($c, $response, [
                'title'   => 'Two-factor authentication',
                'enabled' => true,
                'error'   => 'That code did not match. Try again.',
            ], 401);
        }

        return self::issueRecoveryCodes($c, $response, $admin['id']);
    }

    /**
     * POST /admin/totp/disable — turn two-factor authentication off.
     *
     * A sensitive change, so it is doubly re-authenticated: the CURRENT
     * password AND a CURRENT TOTP code are both required. On success the TOTP
     * secret, the enabled flag and the recovery codes are all cleared, and the
     * dashboard nudge is un-dismissed so the enrolment prompt returns.
     */
    public static function disableTotp(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $admin = self::auth($c)->currentAdmin();
        if ($admin === null) {
            return self::redirect($response, '/admin/login');
        }
        if ((int) $admin['totp_enabled'] !== 1 || $admin['totp_secret'] === null) {
            return self::redirect($response, '/admin/totp/setup');
        }

        $data = self::formData($request);

        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            return self::renderTotpSetup($c, $response, [
                'title'        => 'Two-factor authentication',
                'enabled'      => true,
                'error'        => '',
                'disableError' => 'Your session expired. Please try again.',
            ], 400);
        }

        $password = (string) ($data['current_password'] ?? '');
        if (!self::hasher($c)->verify($password, (string) $admin['password_hash'])) {
            return self::renderTotpSetup($c, $response, [
                'title'        => 'Two-factor authentication',
                'enabled'      => true,
                'error'        => '',
                'disableError' => 'That is not your current password.',
            ], 401);
        }

        $totp = self::totpService($c);
        if (!$totp->verify($admin['totp_secret'], (string) ($data['code'] ?? ''), self::clockNow($c))) {
            return self::renderTotpSetup($c, $response, [
                'title'        => 'Two-factor authentication',
                'enabled'      => true,
                'error'        => '',
                'disableError' => 'That authentication code did not match.',
            ], 401);
        }

        self::admins($c)->disableTotp($admin['id']);
        // Re-arm the dashboard nudge: 2FA is off again, so prompt for it.
        self::session($c)->remove(self::SESSION_NUDGE_DISMISSED);
        self::flash($c)->success('Two-factor authentication has been disabled.');

        return self::redirect($response, '/admin/totp/setup');
    }

    // --- Shared helpers ---------------------------------------------------

    /**
     * Generate, persist and display a fresh set of recovery codes.
     */
    private static function issueRecoveryCodes(
        ContainerInterface $c,
        ResponseInterface $response,
        int $adminId
    ): ResponseInterface {
        /** @var RecoveryCodes $recovery */
        $recovery = $c->get(RecoveryCodes::class);
        $batch = $recovery->generate();
        self::admins($c)->setRecoveryCodes($adminId, $batch['hashes']);

        return AdminView::renderPage($c, $response, 'recovery_codes', [
            'title' => 'Your recovery codes',
            'codes' => $batch['plain'],
        ], '');
    }

    /**
     * Template vars for the enrolment view.
     *
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    private static function enrolmentVars(
        ContainerInterface $c,
        array $admin,
        string $secret,
        string $error = ''
    ): array {
        $totp = self::totpService($c);
        $issuer = self::config($c)->mailFromName();

        return [
            'title'      => 'Two-factor authentication',
            'csrf'       => self::csrf($c)->token(),
            'enabled'    => false,
            'error'      => $error,
            'otpauthUri' => $totp->otpauthUri($issuer, (string) $admin['email'], $secret),
            'manualKey'  => $secret,
        ];
    }

    /**
     * Reuse the in-progress enrolment secret if present, else mint one and
     * stash it in the session so GET and POST agree on the same key.
     */
    private static function currentOrNewSetupSecret(ContainerInterface $c): string
    {
        $session = self::session($c);
        $secret = $session->get(self::SESSION_SETUP_SECRET);
        if (is_string($secret) && $secret !== '') {
            return $secret;
        }

        $secret = self::totpService($c)->generateSecret();
        $session->set(self::SESSION_SETUP_SECRET, $secret);

        return $secret;
    }

    /**
     * Render the TOTP setup view with the authenticated chrome, loading the
     * vendored QR generator so the enrolment QR can be drawn client-side.
     *
     * @param array<string, mixed> $vars
     */
    private static function renderTotpSetup(
        ContainerInterface $c,
        ResponseInterface $response,
        array $vars,
        int $status = 200
    ): ResponseInterface {
        return AdminView::renderPage(
            $c,
            $response,
            'totp_setup',
            $vars,
            '',
            $status,
            ['/assets/vendor/qrcode.js']
        );
    }

    private static function render(
        ContainerInterface $c,
        ResponseInterface $response,
        string $view,
        array $vars,
        int $status = 200
    ): ResponseInterface {
        /** @var TemplateRenderer $renderer */
        $renderer = $c->get(TemplateRenderer::class);
        $response->getBody()->write($renderer->render($view, $vars));

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withStatus($status);
    }

    private static function redirect(ResponseInterface $response, string $location): ResponseInterface
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }

    /**
     * Parsed form fields: prefer the PSR-7 parsed body (tests set it directly)
     * and fall back to parsing a urlencoded raw body (production, where no
     * body-parsing middleware runs so the public pipeline can read raw).
     *
     * @return array<string, mixed>
     */
    private static function formData(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }

        parse_str((string) $request->getBody(), $data);

        return $data;
    }

    private static function remoteIp(ServerRequestInterface $request): string
    {
        return (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
    }

    private static function auth(ContainerInterface $c): AuthService
    {
        /** @var AuthService $s */
        $s = $c->get(AuthService::class);

        return $s;
    }

    private static function csrf(ContainerInterface $c): Csrf
    {
        /** @var Csrf $s */
        $s = $c->get(Csrf::class);

        return $s;
    }

    private static function session(ContainerInterface $c): SessionInterface
    {
        /** @var SessionInterface $s */
        $s = $c->get(SessionInterface::class);

        return $s;
    }

    private static function admins(ContainerInterface $c): AdminRepository
    {
        /** @var AdminRepository $s */
        $s = $c->get(AdminRepository::class);

        return $s;
    }

    private static function totpService(ContainerInterface $c): Totp
    {
        /** @var Totp $s */
        $s = $c->get(Totp::class);

        return $s;
    }

    private static function hasher(ContainerInterface $c): PasswordHasher
    {
        /** @var PasswordHasher $s */
        $s = $c->get(PasswordHasher::class);

        return $s;
    }

    private static function flash(ContainerInterface $c): Flash
    {
        /** @var Flash $f */
        $f = $c->get(Flash::class);

        return $f;
    }

    private static function config(ContainerInterface $c): Config
    {
        /** @var Config $s */
        $s = $c->get(Config::class);

        return $s;
    }

    private static function clockNow(ContainerInterface $c): int
    {
        /** @var \OpenSendForm\Clock\Clock $clock */
        $clock = $c->get(\OpenSendForm\Clock\Clock::class);

        return $clock->now();
    }
}
