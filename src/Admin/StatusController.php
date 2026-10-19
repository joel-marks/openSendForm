<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

use OpenSendForm\Auth\AuthService;
use OpenSendForm\Clock\Clock;
use OpenSendForm\Config;
use OpenSendForm\Install\Paths;
use OpenSendForm\Install\Requirements;
use OpenSendForm\Install\SystemProbe;
use OpenSendForm\Mail\DeliverabilityChecker;
use OpenSendForm\Monitor\MonitorRepository;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The Status tab (/admin/status): a live, uncached operational readout for the
 * viewing operator. It re-probes the environment on every view (reusing the
 * installer's Requirements machinery), flags security concerns (the viewing
 * admin's 2FA state and world-writable app directories), shows the full mail
 * deliverability component (shared with the Deliverability tab, not forked) and
 * the operational state (versions, pending migrations, monitor last-run and
 * cron setup).
 *
 * Read-only: it probes and shapes, and writes nothing.
 */
final class StatusController
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

        /** @var Database $db */
        $db = $c->get(Database::class);
        /** @var Config $config */
        $config = $c->get(Config::class);
        /** @var Clock $clock */
        $clock = $c->get(Clock::class);
        /** @var Paths $paths */
        $paths = $c->get(Paths::class);
        /** @var DeliverabilityChecker $checker */
        $checker = $c->get(DeliverabilityChecker::class);

        $report = new StatusReport(
            new Requirements(new SystemProbe($request), $paths),
            $db,
            new MigrationRunner($db, $paths->migrationsPath),
            new MonitorRepository($db),
            $config,
            $clock,
            $checker,
            $admin,
            self::appDirs($paths),
            new \OpenSendForm\Form\FormRepository($db),
            new \OpenSendForm\Submission\SubmissionRepository($db),
            self::selectorFrom($request->getQueryParams())
        );

        $status = $report->build();

        return AdminView::renderPage($c, $response, 'status', [
            'title'    => 'Status',
            'status'   => $status,
            'selector' => $status['mail']['selector'],
        ], 'status');
    }

    /**
     * The directories to tamper-check for world-writability: the shipped code
     * trees plus the writable runtime dirs. The code root is derived from this
     * file's location so it is correct both in-repo and inside the release zip.
     *
     * @return list<string>
     */
    private static function appDirs(Paths $paths): array
    {
        $root = dirname(__DIR__, 2);

        return [
            $root,
            $root . '/src',
            $root . '/public',
            $root . '/templates',
            $root . '/bin',
            $paths->varDir,
            $paths->dataDir,
        ];
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

    private static function redirect(ResponseInterface $response, string $location): ResponseInterface
    {
        return $response->withHeader('Location', $location)->withStatus(302);
    }
}
