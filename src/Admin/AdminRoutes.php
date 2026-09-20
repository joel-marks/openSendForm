<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Server-rendered admin routes under /admin.
 *
 * The whole group carries the hardening headers. The login and TOTP-entry
 * routes are public-facing (pre-authentication); the dashboard, TOTP
 * enrolment and logout routes are wrapped with AuthMiddleware so an
 * unauthenticated request is redirected to the login page.
 */
final class AdminRoutes
{
    public static function register(App $app): void
    {
        $container = $app->getContainer();
        if ($container === null) {
            throw new RuntimeException('Admin routes require a DI container.');
        }

        $auth = new AuthMiddleware($container);

        $app->group('/admin', function (RouteCollectorProxy $group) use ($container, $auth): void {
            // Pre-authentication routes.
            $group->get('/login', self::handler($container, [AdminController::class, 'loginForm']));
            $group->post('/login', self::handler($container, [AdminController::class, 'login']));
            $group->get('/totp', self::handler($container, [AdminController::class, 'totpForm']));
            $group->post('/totp', self::handler($container, [AdminController::class, 'totp']));

            // Authenticated routes.
            $group->post('/logout', self::handler($container, [AdminController::class, 'logout']))
                ->add($auth);
            $group->get('/totp/setup', self::handler($container, [AdminController::class, 'totpSetupForm']))
                ->add($auth);
            $group->post('/totp/setup', self::handler($container, [AdminController::class, 'totpSetup']))
                ->add($auth);
            $group->post(
                '/totp/recovery-codes/regenerate',
                self::handler($container, [AdminController::class, 'regenerateRecoveryCodes'])
            )->add($auth);
            $group->post('/totp/disable', self::handler($container, [AdminController::class, 'disableTotp']))
                ->add($auth);
            $group->post('/nudge/dismiss', self::handler($container, [AdminController::class, 'dismissNudge']))
                ->add($auth);
            $group->post('/nudge/mail/dismiss', self::handler($container, [AdminController::class, 'dismissMailNudge']))
                ->add($auth);

            // Account (self-service).
            $group->get('/account', self::handler($container, [AccountController::class, 'index']))
                ->add($auth);
            $group->post('/account/name', self::handler($container, [AccountController::class, 'updateName']))
                ->add($auth);
            $group->post('/account/email', self::handler($container, [AccountController::class, 'updateEmail']))
                ->add($auth);
            $group->post('/account/password', self::handler($container, [AccountController::class, 'updatePassword']))
                ->add($auth);

            // Admins management.
            $group->get('/admins', self::handler($container, [AdminsController::class, 'index']))
                ->add($auth);
            $group->post('/admins', self::handler($container, [AdminsController::class, 'create']))
                ->add($auth);
            $group->post('/admins/{id}/deactivate', self::handlerWithArgs($container, [AdminsController::class, 'deactivate']))
                ->add($auth);
            $group->post('/admins/{id}/reactivate', self::handlerWithArgs($container, [AdminsController::class, 'reactivate']))
                ->add($auth);
            $group->get('/admins/{id}/delete', self::handlerWithArgs($container, [AdminsController::class, 'deleteConfirm']))
                ->add($auth);
            $group->post('/admins/{id}/delete', self::handlerWithArgs($container, [AdminsController::class, 'delete']))
                ->add($auth);

            // Email (mail-setup wizard: SMTP + deliverability).
            $group->get('/mail', self::handler($container, [MailController::class, 'index']))
                ->add($auth);
            $group->post('/mail', self::handler($container, [MailController::class, 'save']))
                ->add($auth);
            $group->post('/mail/test', self::handler($container, [MailController::class, 'test']))
                ->add($auth);
            $group->post('/mail/enable', self::handler($container, [MailController::class, 'enable']))
                ->add($auth);

            // Deliverability (SPF/DKIM/DMARC checker for the sending domain).
            $group->get('/deliverability', self::handler($container, [DeliverabilityController::class, 'index']))
                ->add($auth);

            // Forms CRUD.
            $group->get('/forms', self::handler($container, [FormsController::class, 'index']))
                ->add($auth);
            $group->get('/forms/new', self::handler($container, [FormsController::class, 'createForm']))
                ->add($auth);
            $group->post('/forms', self::handler($container, [FormsController::class, 'create']))
                ->add($auth);
            $group->get('/forms/{id}/edit', self::handlerWithArgs($container, [FormsController::class, 'editForm']))
                ->add($auth);
            $group->post('/forms/{id}', self::handlerWithArgs($container, [FormsController::class, 'update']))
                ->add($auth);
            $group->post('/forms/{id}/enable', self::handlerWithArgs($container, [FormsController::class, 'enable']))
                ->add($auth);
            $group->post('/forms/{id}/disable', self::handlerWithArgs($container, [FormsController::class, 'disable']))
                ->add($auth);
            $group->get('/forms/{id}/delete', self::handlerWithArgs($container, [FormsController::class, 'deleteConfirm']))
                ->add($auth);
            $group->post('/forms/{id}/delete', self::handlerWithArgs($container, [FormsController::class, 'delete']))
                ->add($auth);

            // Submissions.
            $group->get('/submissions', self::handler($container, [SubmissionsController::class, 'index']))
                ->add($auth);
            $group->post('/submissions/retry-due', self::handler($container, [SubmissionsController::class, 'retryDue']))
                ->add($auth);
            // Bulk-delete (literal path) is registered before the {id} routes so
            // "delete-all" is never mistaken for a submission id.
            $group->get('/submissions/delete-all', self::handler($container, [SubmissionsController::class, 'deleteAllConfirm']))
                ->add($auth);
            $group->post('/submissions/delete-all', self::handler($container, [SubmissionsController::class, 'deleteAll']))
                ->add($auth);
            $group->post(
                '/submissions/{id}/retry',
                self::handlerWithArgs($container, [SubmissionsController::class, 'retry'])
            )->add($auth);
            $group->get(
                '/submissions/{id}/delete',
                self::handlerWithArgs($container, [SubmissionsController::class, 'deleteConfirm'])
            )->add($auth);
            $group->post(
                '/submissions/{id}/delete',
                self::handlerWithArgs($container, [SubmissionsController::class, 'delete'])
            )->add($auth);

            // Dashboard is the group root: /admin.
            $group->get('', self::handler($container, [AdminController::class, 'dashboard']))
                ->add($auth);
        })->add(new SecurityHeadersMiddleware());
    }

    /**
     * Wrap a controller method in a Closure bound over the container.
     *
     * Slim binds route Closures to the container ($this), so these must be
     * non-static closures — matching the public Routes class.
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

    /**
     * As handler(), but for routes with URL arguments ({id}), forwarding the
     * matched args as a fourth parameter.
     *
     * @param callable(ContainerInterface, ServerRequestInterface, ResponseInterface, array<string,string>): ResponseInterface $target
     */
    private static function handlerWithArgs(ContainerInterface $container, callable $target): \Closure
    {
        return function (
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $args
        ) use ($container, $target): ResponseInterface {
            return $target($container, $request, $response, $args);
        };
    }
}
