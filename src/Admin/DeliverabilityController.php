<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

use OpenSendForm\Auth\AuthService;
use OpenSendForm\Config;
use OpenSendForm\Mail\DeliverabilityChecker;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The Deliverability tab (/admin/deliverability): a live SPF / DKIM / DMARC
 * check of the SENDING domain — the domain of the configured From address.
 *
 * Split out of the Email page so the two concerns read separately: Email is
 * "how do I send", Deliverability is "how do I stop my mail landing in spam".
 * Every result names its source (a live DNS lookup of a specific record for a
 * specific domain) and the page states plainly that these records concern the
 * sending domain only — recipients are unlimited and need no DNS changes.
 *
 * Read-only: it makes DNS lookups and shapes results, and writes nothing.
 */
final class DeliverabilityController
{
    public static function index(
        ContainerInterface $c,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $admin = self::auth($c)->currentAdmin();
        if ($admin === null) {
            return self::redirect($response, '/admin/login');
        }

        $config = self::config($c);
        $adminEmail = (string) ($admin['email'] ?? '');
        $selector = self::selectorFrom($request->getQueryParams());

        $report = self::checker($c)->check(
            $config->mailFromAddress(),
            $adminEmail,
            $selector,
            $config->smtpHost()
        );

        return AdminView::renderPage($c, $response, 'deliverability', [
            'title'    => 'Deliverability',
            'report'   => $report,
            'selector' => $report['selector'],
        ], 'deliverability');
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function selectorFrom(array $query): string
    {
        $selector = trim((string) ($query['dkim_selector'] ?? ''));

        return $selector === '' ? 'default' : $selector;
    }

    private static function auth(ContainerInterface $c): AuthService
    {
        /** @var AuthService $s */
        $s = $c->get(AuthService::class);

        return $s;
    }

    private static function config(ContainerInterface $c): Config
    {
        /** @var Config $s */
        $s = $c->get(Config::class);

        return $s;
    }

    private static function checker(ContainerInterface $c): DeliverabilityChecker
    {
        /** @var DeliverabilityChecker $s */
        $s = $c->get(DeliverabilityChecker::class);

        return $s;
    }

    private static function redirect(ResponseInterface $response, string $location): ResponseInterface
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }
}
