<?php

declare(strict_types=1);

namespace OpenSendForm\Install;

use OpenSendForm\Admin\SecurityHeadersMiddleware;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * The browser-installer routes under /install.
 *
 * The group carries the same hardening headers (and strict CSP) as the admin
 * area, so the installer's plain-PHP screens obey the no-inline-script rule
 * too. Access is gated by InstallStateMiddleware (app-level): these routes are
 * reachable only until the app is installed. There is no auth — the installer
 * runs before any admin exists.
 */
final class InstallRoutes
{
    public static function register(App $app): void
    {
        $container = $app->getContainer();
        if ($container === null) {
            throw new RuntimeException('Install routes require a DI container.');
        }

        $app->group('/install', function (RouteCollectorProxy $group) use ($container): void {
            $group->get('', self::handler($container, [InstallController::class, 'welcome']));
            $group->get('/requirements', self::handler($container, [InstallController::class, 'requirementsForm']));
            $group->get('/database', self::handler($container, [InstallController::class, 'databaseForm']));
            $group->post('/database', self::handler($container, [InstallController::class, 'database']));
            $group->get('/admin', self::handler($container, [InstallController::class, 'adminForm']));
            $group->post('/admin', self::handler($container, [InstallController::class, 'admin']));
            $group->get('/mail', self::handler($container, [InstallController::class, 'mailForm']));
            $group->post('/mail', self::handler($container, [InstallController::class, 'mail']));
            $group->get('/scheduled', self::handler($container, [InstallController::class, 'scheduledForm']));
            $group->post('/scheduled', self::handler($container, [InstallController::class, 'scheduled']));
            $group->get('/bot-protection', self::handler($container, [InstallController::class, 'botProtectionForm']));
            // The commit action: the Bot protection step posts here. There is no
            // GET /finish — the terminal Finish checklist lives at /done.
            $group->post('/finish', self::handler($container, [InstallController::class, 'finish']));
            $group->get('/done', self::handler($container, [InstallController::class, 'done']));
        })->add(new SecurityHeadersMiddleware());
    }

    /**
     * Wrap a controller method in a Closure bound over the container, matching
     * the admin/public route style (Slim binds route Closures to the container).
     *
     * @param callable(ContainerInterface, ServerRequestInterface, ResponseInterface): ResponseInterface $target
     */
    private static function handler(ContainerInterface $container, callable $target): \Closure
    {
        return function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($container, $target): ResponseInterface {
            return $target($container, $request, $response);
        };
    }
}
