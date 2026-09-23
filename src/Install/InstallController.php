<?php

declare(strict_types=1);

namespace OpenSendForm\Install;

use OpenSendForm\Admin\TemplateRenderer;
use OpenSendForm\Auth\Csrf;
use OpenSendForm\Auth\SessionInterface;
use OpenSendForm\Config;
use OpenSendForm\Mail\MailerFactory;
use OpenSendForm\Mail\MailSettingsForm;
use OpenSendForm\Version;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * The browser installer, written for a non-technical person who has just
 * uploaded and extracted the zip on shared hosting. One concern per screen,
 * plain language, a single clear next action.
 *
 * State between steps lives in the session; every POST is CSRF-protected and
 * every step re-validates server-side no matter what an earlier step did.
 * Order is enforced (database → admin → finish); jumping ahead bounces back to
 * the earliest incomplete step. No secret (DB password, admin password) is ever
 * echoed back into a field.
 *
 * The controller also HARD-refuses to run once installed — not merely via
 * routing — so a stale form or crafted request cannot re-drive the installer.
 * Container key 'install.renderer' is a TemplateRenderer bound to
 * templates/install/.
 */
final class InstallController
{
    /** Session key: the validated database config chosen at the DB step. */
    private const S_DB = 'install.db';
    /** Session key: the first admin has been created. */
    private const S_ADMIN = 'install.admin_created';
    /** Session key: the SMTP settings chosen at the (skippable) email step. */
    private const S_MAIL = 'install.mail';
    /** Session key: the scheduled-tasks (cron) choice — 'done' or 'later'. */
    private const S_CRON = 'install.cron';
    /** Session key: the install committed (config + lock written). */
    private const S_DONE = 'install.completed';

    /** The number of configurable steps the flow has (Finish is terminal,
     *  outside the count — see the Finish page). Shown as "Step N of 7". */
    private const STEP_COUNT = 7;

    // --- Step 1: welcome --------------------------------------------------

    public static function welcome(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (($guard = self::guardNotInstalled($c, $response)) !== null) {
            return $guard;
        }

        return self::renderStep($c, $response, 'welcome', 1, 'Welcome', [
            'title' => 'Welcome',
        ]);
    }

    // --- Step 2: requirements (hosting check) -----------------------------

    public static function requirementsForm(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (($guard = self::guardNotInstalled($c, $response)) !== null) {
            return $guard;
        }

        $requirements = self::requirements($c, $request);

        return self::renderStep($c, $response, 'requirements', 2, 'Requirements', [
            'title'       => 'Requirements',
            'checks'      => $requirements->checks(),
            'hasFailures' => $requirements->hasFailures(),
        ]);
    }

    // --- Step 3: database -------------------------------------------------

    public static function databaseForm(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (($guard = self::guardNotInstalled($c, $response)) !== null) {
            return $guard;
        }
        if (self::requirements($c, $request)->hasFailures()) {
            return self::redirect($response, '/install/requirements');
        }

        return self::renderDatabase($c, $response);
    }

    public static function database(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (($guard = self::guardNotInstalled($c, $response)) !== null) {
            return $guard;
        }
        if (self::requirements($c, $request)->hasFailures()) {
            return self::redirect($response, '/install/requirements');
        }

        $data = self::formData($request);
        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            return self::renderDatabase($c, $response, $data, 'Your session expired. Please try again.', 400);
        }

        $installer = self::installer($c);
        try {
            $dbConfig = $installer->prepareDatabase($data);
            // Live connection test (real check for MySQL) + apply the schema.
            $db = $installer->connect($dbConfig);
            $installer->migrate($db);
        } catch (InstallerException $e) {
            return self::renderDatabase($c, $response, $data, $e->getMessage(), 422);
        }

        self::session($c)->set(self::S_DB, $dbConfig);
        // A changed database invalidates any earlier admin step.
        self::session($c)->remove(self::S_ADMIN);

        return self::redirect($response, '/install/admin');
    }

    // --- Step 3: first admin ----------------------------------------------

    public static function adminForm(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (($guard = self::guardNotInstalled($c, $response)) !== null) {
            return $guard;
        }
        if (($jump = self::enforceOrder($c, $response, needsDb: true, needsAdmin: false)) !== null) {
            return $jump;
        }

        return self::renderAdmin($c, $response);
    }

    public static function admin(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (($guard = self::guardNotInstalled($c, $response)) !== null) {
            return $guard;
        }
        if (($jump = self::enforceOrder($c, $response, needsDb: true, needsAdmin: false)) !== null) {
            return $jump;
        }

        $data = self::formData($request);
        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            return self::renderAdmin($c, $response, $data, 'Your session expired. Please try again.', 400);
        }

        $installer = self::installer($c);
        /** @var array{driver:string,dsn:string,user:string,pass:string,summary:string} $dbConfig */
        $dbConfig = self::session($c)->get(self::S_DB);

        try {
            $db = $installer->connect($dbConfig);
            $installer->createAdmin(
                $db,
                (string) ($data['email'] ?? ''),
                (string) ($data['name'] ?? ''),
                (string) ($data['password'] ?? ''),
                (string) ($data['password_confirm'] ?? '')
            );
        } catch (InstallerException $e) {
            return self::renderAdmin($c, $response, $data, $e->getMessage(), 422);
        }

        self::session($c)->set(self::S_ADMIN, true);

        return self::redirect($response, '/install/mail');
    }

    // --- Step 4: email sending (skippable) --------------------------------

    public static function mailForm(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (($guard = self::guardNotInstalled($c, $response)) !== null) {
            return $guard;
        }
        if (($jump = self::enforceOrder($c, $response, needsDb: true, needsAdmin: true)) !== null) {
            return $jump;
        }

        return self::renderMailStep($c, $response);
    }

    public static function mail(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (($guard = self::guardNotInstalled($c, $response)) !== null) {
            return $guard;
        }
        if (($jump = self::enforceOrder($c, $response, needsDb: true, needsAdmin: true)) !== null) {
            return $jump;
        }

        $data = self::formData($request);
        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            return self::renderMailStep($c, $response, $data, 'Your session expired. Please try again.', 400);
        }

        $action = (string) ($data['action'] ?? 'save');

        // Skip: leave email off. Submissions are stored but not emailed until
        // it is set up later from the admin panel.
        if ($action === 'skip') {
            self::session($c)->remove(self::S_MAIL);

            return self::redirect($response, '/install/scheduled');
        }

        // Validate the SMTP details (same rules as the admin Email page).
        $host = trim((string) ($data['smtp_host'] ?? ''));
        $port = trim((string) ($data['smtp_port'] ?? ''));
        $encryption = MailSettingsForm::normaliseEncryption((string) ($data['smtp_encryption'] ?? ''));
        $user = trim((string) ($data['smtp_user'] ?? ''));
        $password = (string) ($data['smtp_pass'] ?? '');
        $fromAddress = trim((string) ($data['mail_from_address'] ?? ''));
        $fromName = trim((string) ($data['mail_from_name'] ?? ''));

        if ($host === '') {
            return self::renderMailStep($c, $response, $data, 'Enter your SMTP host, or choose “Skip for now”.', 422);
        }
        if ($port === '' || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            return self::renderMailStep($c, $response, $data, 'Please enter a port number between 1 and 65535 (usually 587 or 465).', 422);
        }
        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            return self::renderMailStep($c, $response, $data, 'Please enter a valid From address (e.g. hello@yourdomain.com).', 422);
        }
        if ($fromName === '') {
            return self::renderMailStep($c, $response, $data, 'Please enter a From name (what recipients see as the sender).', 422);
        }

        $changes = [
            'SMTP_HOST'         => $host,
            'SMTP_PORT'         => $port,
            'SMTP_ENCRYPTION'   => $encryption,
            'SMTP_USER'         => $user,
            'MAIL_FROM_ADDRESS' => $fromAddress,
            'MAIL_FROM_NAME'    => $fromName,
            'MAIL_ENABLED'      => '1',
        ];
        if ($password !== '') {
            $changes['SMTP_PASS'] = $password;
        }

        // A test send: build a mailer from the just-typed settings and send one
        // message, without leaving the step (the settings are kept so the
        // fields survive the round trip).
        if ($action === 'test') {
            self::sendInstallTest($c, $changes, $data);

            return self::renderMailStep($c, $response, $data);
        }

        self::session($c)->set(self::S_MAIL, $changes);

        return self::redirect($response, '/install/scheduled');
    }

    // --- Step 6: scheduled tasks (cron) -----------------------------------

    public static function scheduledForm(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (($guard = self::guardNotInstalled($c, $response)) !== null) {
            return $guard;
        }
        if (($jump = self::enforceOrder($c, $response, needsDb: true, needsAdmin: true)) !== null) {
            return $jump;
        }

        return self::renderScheduled($c, $response);
    }

    public static function scheduled(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (($guard = self::guardNotInstalled($c, $response)) !== null) {
            return $guard;
        }
        if (($jump = self::enforceOrder($c, $response, needsDb: true, needsAdmin: true)) !== null) {
            return $jump;
        }

        $data = self::formData($request);
        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            return self::renderScheduled($c, $response, 'Your session expired. Please try again.', 400);
        }

        // Record the operator's choice so Finish can report it and the dashboard
        // can nudge while it is still 'later'. No verification pretence — the app
        // cannot see cron from a request; we only record what they told us.
        $choice = (string) ($data['action'] ?? 'later') === 'done' ? 'done' : 'later';
        self::session($c)->set(self::S_CRON, $choice);

        return self::redirect($response, '/install/bot-protection');
    }

    // --- Step 7: bot protection (Turnstile signpost) ----------------------

    public static function botProtectionForm(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (($guard = self::guardNotInstalled($c, $response)) !== null) {
            return $guard;
        }
        if (($jump = self::enforceOrder($c, $response, needsDb: true, needsAdmin: true)) !== null) {
            return $jump;
        }

        return self::renderStep($c, $response, 'botprotection', 7, 'Bot protection', [
            'title' => 'Bot protection',
            'csrf'  => self::csrf($c)->token(),
        ]);
    }

    // --- Terminal: commit + Finish ----------------------------------------

    public static function finish(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (($guard = self::guardNotInstalled($c, $response)) !== null) {
            return $guard;
        }
        if (($jump = self::enforceOrder($c, $response, needsDb: true, needsAdmin: true)) !== null) {
            return $jump;
        }

        $data = self::formData($request);
        if (!self::csrf($c)->validate($data['_csrf'] ?? null)) {
            self::flash($c, 'Your session expired. Please try again.');

            return self::redirect($response, '/install/bot-protection');
        }

        /** @var array{driver:string,dsn:string,user:string,pass:string,summary:string} $dbConfig */
        $dbConfig = self::session($c)->get(self::S_DB);

        // Gather the wizard's extra settings: the email step's SMTP details (when
        // it wasn't skipped) plus MONITOR_BASE_URL captured from THIS request's
        // scheme+host, so the synthetic monitor's cron works with no manual
        // config edit. An environment variable still overrides it at load time.
        $extra = [];
        $mail = self::session($c)->get(self::S_MAIL);
        if (is_array($mail)) {
            $extra = $mail;
        }
        $baseUrl = self::requestBaseUrl($request);
        if ($baseUrl !== '') {
            $extra['MONITOR_BASE_URL'] = $baseUrl;
        }
        // Persist the scheduled-tasks choice (default 'later' if the step was
        // somehow bypassed) so Finish and the dashboard can report/act on it.
        $cron = self::session($c)->get(self::S_CRON);
        $extra['CRON_SETUP'] = $cron === 'done' ? 'done' : 'later';

        try {
            self::installer($c)->commit($dbConfig, $extra);
        } catch (InstallerException $e) {
            self::flash($c, $e->getMessage());

            return self::redirect($response, '/install/bot-protection');
        }

        // Installed now. Destroy the pre-install session in full — its id AND
        // all its data — so nothing can carry from the browser that ran the
        // installer into the freshly installed app: not the installer's own
        // working state (S_DB/S_ADMIN), not a stale admin login or CSRF token
        // that happened to share this PHP session id (a real risk on shared
        // cPanel hosting where an unrelated site may have seeded a session
        // cookie for the same host). A brand-new, empty session then carries
        // only the one-time completion flag, so the done screen still renders
        // exactly once for this browser.
        $session = self::session($c);
        $session->destroy();
        $session->set(self::S_DONE, true);

        return self::redirect($response, '/install/done');
    }

    // --- Terminal (outside the step count): Finish ------------------------

    public static function done(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        // Reachable even once installed, but only just after finishing: the
        // session flag proves this browser completed the install.
        if (self::session($c)->get(self::S_DONE) !== true) {
            $target = self::paths($c)->isInstalled() ? '/admin/login' : '/install';

            return self::redirect($response, $target);
        }

        // Report the outcome from what was actually PERSISTED at commit (the
        // pre-install session is gone by now), so the checklist is truthful:
        // whether email was set up, and whether the operator said they set up
        // the cron jobs.
        $config = Config::fromFile(self::paths($c)->configPath);

        return self::render($c, $response, 'done', [
            'title'       => 'Finish',
            'version'     => Version::STRING,
            'mailEnabled' => $config->mailEnabled(),
            'cronDone'    => $config->cronSetup() === 'done',
        ]);
    }

    // --- Rendering helpers ------------------------------------------------

    /**
     * The Scheduled tasks step (6): plain-language purpose of both cron jobs,
     * the two commands verbatim with runtime-derived absolute paths, and the
     * cPanel recipe. No verification pretence — the choice is recorded, not checked.
     */
    private static function renderScheduled(
        ContainerInterface $c,
        ResponseInterface $response,
        string $error = '',
        int $status = 200
    ): ResponseInterface {
        return self::renderStep($c, $response, 'scheduled', 6, 'Scheduled tasks', [
            'title'      => 'Scheduled tasks',
            'csrf'       => self::csrf($c)->token(),
            'error'      => $error,
            'phpBinary'  => CronCommands::phpBinary(),
            'monitorCmd' => CronCommands::monitorCommand(),
            'retryCmd'   => CronCommands::retryCommand(),
        ], $status);
    }

    /**
     * Render an installer step with the shared onboarding-shell variables: the
     * step number and label drive the "Step N of 7" progress indicator and the
     * chrome-only appbar's row-2 label.
     *
     * @param array<string, mixed> $vars
     */
    private static function renderStep(
        ContainerInterface $c,
        ResponseInterface $response,
        string $view,
        int $stepNo,
        string $stepLabel,
        array $vars,
        int $status = 200
    ): ResponseInterface {
        $vars += ['stepNo' => $stepNo, 'stepCount' => self::STEP_COUNT, 'stepLabel' => $stepLabel];

        return self::render($c, $response, $view, $vars, $status);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function renderDatabase(
        ContainerInterface $c,
        ResponseInterface $response,
        array $data = [],
        string $error = '',
        int $status = 200
    ): ResponseInterface {
        return self::renderStep($c, $response, 'database', 3, 'Database', [
            'title'   => 'Database',
            'csrf'    => self::csrf($c)->token(),
            'error'   => $error,
            // Preserve the choice and non-secret MySQL fields; NEVER the password.
            'driver'  => (string) ($data['db_driver'] ?? 'sqlite'),
            'dbHost'  => (string) ($data['db_host'] ?? ''),
            'dbPort'  => (string) ($data['db_port'] ?? ''),
            'dbName'  => (string) ($data['db_name'] ?? ''),
            'dbUser'  => (string) ($data['db_user'] ?? ''),
        ], $status);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function renderAdmin(
        ContainerInterface $c,
        ResponseInterface $response,
        array $data = [],
        string $error = '',
        int $status = 200
    ): ResponseInterface {
        return self::renderStep($c, $response, 'admin', 4, 'Admin account', [
            'title'             => 'Admin account',
            'csrf'              => self::csrf($c)->token(),
            'error'             => $error,
            'minPasswordLength' => 12,
            // Preserve email + name on a re-render; NEVER the password.
            'email'             => (string) ($data['email'] ?? ''),
            'name'              => (string) ($data['name'] ?? ''),
        ], $status);
    }

    /**
     * The skippable email step. A fresh setup leads with SSL/TLS on port 465
     * (the friendliest common cPanel choice); submitted values are preserved on
     * a re-render, and the password is never echoed back.
     *
     * @param array<string, mixed> $data
     */
    private static function renderMailStep(
        ContainerInterface $c,
        ResponseInterface $response,
        array $data = [],
        string $error = '',
        int $status = 200
    ): ResponseInterface {
        $useSubmitted = $data !== [];
        $encryption = $useSubmitted
            ? MailSettingsForm::normaliseEncryption((string) ($data['smtp_encryption'] ?? ''))
            : MailSettingsForm::DEFAULT_ENCRYPTION;
        $port = $useSubmitted
            ? (string) ($data['smtp_port'] ?? '')
            : MailSettingsForm::defaultPortFor($encryption);

        return self::renderStep($c, $response, 'mail', 5, 'Email sending', [
            'title'          => 'Email sending',
            'csrf'           => self::csrf($c)->token(),
            'error'          => $error,
            // Shared SMTP-fields partial variables (never echo the password).
            'smtpHost'       => (string) ($data['smtp_host'] ?? ''),
            'smtpPort'       => $port,
            'smtpEncryption' => $encryption,
            'smtpUser'       => (string) ($data['smtp_user'] ?? ''),
            'passwordSet'    => false,
            'fromAddress'    => (string) ($data['mail_from_address'] ?? ''),
            'fromName'       => (string) ($data['mail_from_name'] ?? ''),
            'passwordError'  => $error !== '',
            'testRecipient'  => (string) ($data['test_recipient'] ?? ($data['mail_from_address'] ?? '')),
        ], $status);
    }

    /**
     * Send a test email using the just-typed SMTP settings, without persisting
     * anything. Queues a plain-language flash for the re-rendered step. Any
     * failure is caught and surfaced as a friendly, sanitised notice.
     *
     * @param array<string, string> $changes The assembled SMTP config changes.
     * @param array<string, mixed>  $data    The raw posted data (for the recipient).
     */
    private static function sendInstallTest(ContainerInterface $c, array $changes, array $data): void
    {
        $to = trim((string) ($data['test_recipient'] ?? ''));
        if ($to === '') {
            $to = (string) ($changes['MAIL_FROM_ADDRESS'] ?? '');
        }
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            self::flash($c, 'Enter a valid email address to send the test to.');

            return;
        }

        // Build a config from the entered settings (plus the password just
        // typed, which is not in $changes when blank), then a mailer for it.
        $values = $changes;
        $values['SMTP_PASS'] = (string) ($data['smtp_pass'] ?? '');
        $config = Config::fromValues($values);

        try {
            self::mailerFactory($c)->make($config)->send(
                $to,
                null,
                'OpenSendForm test email',
                "This is a test email from OpenSendForm.\n\n"
                . "If it reached you, your email settings are working and submissions "
                . "will be delivered."
            );
        } catch (Throwable $e) {
            $message = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $e->getMessage()));
            self::flash($c, 'The test email could not be sent: ' . ($message === '' ? 'unknown error.' : $message));

            return;
        }

        self::flash($c, 'Test email sent to ' . $to . '. Check that inbox to confirm it arrived.');
    }

    /**
     * The request's scheme + host (+ non-default port), with no trailing path —
     * the base URL the synthetic monitor drives its submissions against. Empty
     * when the request carries no host.
     */
    private static function requestBaseUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $host = $uri->getHost();
        if ($host === '') {
            return '';
        }

        $scheme = $uri->getScheme() !== '' ? $uri->getScheme() : 'https';
        $authority = $host;
        $port = $uri->getPort();
        $isDefault = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        if ($port !== null && !$isDefault) {
            $authority .= ':' . $port;
        }

        return $scheme . '://' . $authority;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private static function render(
        ContainerInterface $c,
        ResponseInterface $response,
        string $view,
        array $vars,
        int $status = 200
    ): ResponseInterface {
        /** @var TemplateRenderer $renderer */
        $renderer = $c->get('install.renderer');
        $vars += ['flashes' => self::flashes($c)];
        $response->getBody()->write($renderer->render($view, $vars));

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withStatus($status);
    }

    // --- Guards & order ---------------------------------------------------

    /**
     * Hard installed-state check: refuse to run any installer step once the app
     * is installed (belt-and-braces with the routing middleware). Returns a 404
     * response to short-circuit, or null to proceed.
     */
    private static function guardNotInstalled(ContainerInterface $c, ResponseInterface $response): ?ResponseInterface
    {
        if (self::paths($c)->isInstalled()) {
            return $response->withStatus(404);
        }

        return null;
    }

    /**
     * Enforce step order: if a prerequisite step's session state is missing,
     * redirect to the earliest incomplete step. Returns a redirect or null.
     */
    private static function enforceOrder(
        ContainerInterface $c,
        ResponseInterface $response,
        bool $needsDb,
        bool $needsAdmin
    ): ?ResponseInterface {
        $session = self::session($c);
        if ($needsDb && $session->get(self::S_DB) === null) {
            return self::redirect($response, '/install/database');
        }
        if ($needsAdmin && $session->get(self::S_ADMIN) !== true) {
            return self::redirect($response, '/install/admin');
        }

        return null;
    }

    private static function requirements(ContainerInterface $c, ServerRequestInterface $request): Requirements
    {
        // Request-aware probe so HTTPS detection reflects the live request.
        return new Requirements(new SystemProbe($request), self::paths($c));
    }

    // --- Container accessors ----------------------------------------------

    private static function installer(ContainerInterface $c): InstallerService
    {
        /** @var InstallerService $s */
        $s = $c->get(InstallerService::class);

        return $s;
    }

    private static function paths(ContainerInterface $c): Paths
    {
        /** @var Paths $p */
        $p = $c->get(Paths::class);

        return $p;
    }

    private static function csrf(ContainerInterface $c): Csrf
    {
        /** @var Csrf $s */
        $s = $c->get(Csrf::class);

        return $s;
    }

    private static function mailerFactory(ContainerInterface $c): MailerFactory
    {
        /** @var MailerFactory $f */
        $f = $c->get(MailerFactory::class);

        return $f;
    }

    private static function session(ContainerInterface $c): SessionInterface
    {
        /** @var SessionInterface $s */
        $s = $c->get(SessionInterface::class);

        return $s;
    }

    /**
     * Queue a one-time notice for the next install page, backed by the session
     * (the installer's own tiny flash, independent of the admin Flash service).
     */
    private static function flash(ContainerInterface $c, string $message): void
    {
        $session = self::session($c);
        $queue = $session->get('install.flash');
        $queue = is_array($queue) ? $queue : [];
        $queue[] = $message;
        $session->set('install.flash', $queue);
    }

    /**
     * @return array<int, string>
     */
    private static function flashes(ContainerInterface $c): array
    {
        $session = self::session($c);
        $queue = $session->get('install.flash');
        $session->remove('install.flash');

        return is_array($queue) ? $queue : [];
    }

    /**
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

    private static function redirect(ResponseInterface $response, string $location): ResponseInterface
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }
}
