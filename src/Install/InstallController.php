<?php

declare(strict_types=1);

namespace OpenSendForm\Install;

use OpenSendForm\Admin\TemplateRenderer;
use OpenSendForm\Auth\Csrf;
use OpenSendForm\Auth\SessionInterface;
use OpenSendForm\Version;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

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
    /** Session key: the install committed (config + lock written). */
    private const S_DONE = 'install.completed';

    // --- Step 1: welcome + requirements -----------------------------------

    public static function welcome(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (($guard = self::guardNotInstalled($c, $response)) !== null) {
            return $guard;
        }

        $requirements = self::requirements($c, $request);

        return self::render($c, $response, 'welcome', [
            'title'       => 'Install OpenSendForm',
            'checks'      => $requirements->checks(),
            'hasFailures' => $requirements->hasFailures(),
        ]);
    }

    // --- Step 2: database -------------------------------------------------

    public static function databaseForm(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (($guard = self::guardNotInstalled($c, $response)) !== null) {
            return $guard;
        }
        if (self::requirements($c, $request)->hasFailures()) {
            return self::redirect($response, '/install');
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
            return self::redirect($response, '/install');
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

        return self::redirect($response, '/install/finish');
    }

    // --- Step 4: finish (review + commit) ---------------------------------

    public static function finishForm(
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

        /** @var array{summary:string} $dbConfig */
        $dbConfig = self::session($c)->get(self::S_DB);

        return self::render($c, $response, 'finish', [
            'title'      => 'Finish setup',
            'dbSummary'  => (string) ($dbConfig['summary'] ?? ''),
            'csrf'       => self::csrf($c)->token(),
        ]);
    }

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

            return self::redirect($response, '/install/finish');
        }

        /** @var array{driver:string,dsn:string,user:string,pass:string,summary:string} $dbConfig */
        $dbConfig = self::session($c)->get(self::S_DB);

        try {
            self::installer($c)->commit($dbConfig);
        } catch (InstallerException $e) {
            self::flash($c, $e->getMessage());

            return self::redirect($response, '/install/finish');
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

    // --- Step 5: done -----------------------------------------------------

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

        return self::render($c, $response, 'done', [
            'title'   => 'OpenSendForm is installed',
            'version' => Version::STRING,
        ]);
    }

    // --- Rendering helpers ------------------------------------------------

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
        return self::render($c, $response, 'database', [
            'title'   => 'Choose a database',
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
        return self::render($c, $response, 'admin', [
            'title'             => 'Create your admin account',
            'csrf'              => self::csrf($c)->token(),
            'error'             => $error,
            'minPasswordLength' => 12,
            // Preserve email + name on a re-render; NEVER the password.
            'email'             => (string) ($data['email'] ?? ''),
            'name'              => (string) ($data['name'] ?? ''),
        ], $status);
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
