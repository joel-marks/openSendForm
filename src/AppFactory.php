<?php

declare(strict_types=1);

namespace OpenSendForm;

use DI\Container;
use OpenSendForm\Admin\AdminRoutes;
use OpenSendForm\Admin\Flash;
use OpenSendForm\Admin\TemplateRenderer;
use OpenSendForm\Auth\AdminRepository;
use OpenSendForm\Auth\AuthService;
use OpenSendForm\Auth\Csrf;
use OpenSendForm\Auth\NativeSession;
use OpenSendForm\Auth\PasswordHasher;
use OpenSendForm\Auth\RecoveryCodes;
use OpenSendForm\Auth\SessionInterface;
use OpenSendForm\Auth\Totp;
use OpenSendForm\Clock\Clock;
use OpenSendForm\Clock\SystemClock;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\Http\ClientIpResolver;
use OpenSendForm\Install\ConfigWriter;
use OpenSendForm\Install\InstallerService;
use OpenSendForm\Install\InstallRoutes;
use OpenSendForm\Install\InstallStateMiddleware;
use OpenSendForm\Install\Paths;
use OpenSendForm\Install\PdoDbConnector;
use OpenSendForm\Mail\DeliverabilityChecker;
use OpenSendForm\Mail\DeliveryService;
use OpenSendForm\Mail\DnsResolver;
use OpenSendForm\Mail\MailerInterface;
use OpenSendForm\Mail\MessageBuilder;
use OpenSendForm\Mail\PhpMailerMailer;
use OpenSendForm\Mail\SystemDnsResolver;
use OpenSendForm\RateLimit\RateLimiter;
use OpenSendForm\Security\SubmitToken;
use OpenSendForm\Storage\Database;
use OpenSendForm\Submission\SubmissionRepository;
use OpenSendForm\Submit\SubmitPipeline;
use OpenSendForm\Turnstile\CurlTurnstileVerifier;
use OpenSendForm\Turnstile\TurnstileVerifierInterface;
use OpenSendForm\Validation\BoundedDnsChecker;
use OpenSendForm\Validation\DnsChecker;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

/**
 * Builds the Slim application.
 *
 * Kept free of any HTTP serving so tests can construct the app and drive
 * requests through it directly. public/index.php is the only place that
 * calls run(). Collaborators (database, clock, DNS checker) are injectable
 * so tests can supply an in-memory database, a fixed clock and a fake
 * resolver.
 */
final class AppFactory
{
    /**
     * Create a fully-configured Slim application.
     *
     * @param Config|null     $config Optional pre-built configuration.
     * @param Database|null   $db     Optional database; defaults to a
     *                                connection from the configured DSN.
     * @param DnsChecker|null $dns    Optional DNS checker; defaults to the
     *                                real system resolver.
     * @param Clock|null      $clock  Optional clock; defaults to the system
     *                                clock.
     * @param MailerInterface|null $mailer Optional SMTP transport. When null,
     *                                mail delivery is disabled and submissions
     *                                stop at 'received' (storage-only); the
     *                                front controller supplies a real mailer
     *                                only when SMTP is configured.
     * @param TurnstileVerifierInterface|null $turnstile Optional Turnstile
     *                                verifier; defaults to the real curl-backed
     *                                implementation. Tests inject a fake.
     * @param SessionInterface|null $session Optional session store; defaults to
     *                                the native PHP session. Tests inject an
     *                                array-backed fake to drive the admin flow.
     * @param Paths|null $installPaths Optional installer paths. When supplied,
     *                                the installed-state gate is ACTIVE: until
     *                                both the config file and lock exist every
     *                                non-install route redirects to /install,
     *                                and once installed the install routes 404.
     *                                When null the gate is disabled (the app
     *                                behaves as installed) — the default the
     *                                test suite relies on. public/index.php
     *                                passes Paths::production() to switch it on.
     * @param DnsResolver|null $mailDns Optional TXT resolver for the mail
     *                                deliverability checker; defaults to the real
     *                                system resolver. Tests inject a scriptable
     *                                fake so no lookup ever hits the network.
     */
    public static function create(
        ?Config $config = null,
        ?Database $db = null,
        ?DnsChecker $dns = null,
        ?Clock $clock = null,
        ?MailerInterface $mailer = null,
        ?TurnstileVerifierInterface $turnstile = null,
        ?SessionInterface $session = null,
        ?Paths $installPaths = null,
        ?DnsResolver $mailDns = null
    ): App {
        $config ??= Config::fromEnvironment();
        $db ??= Database::connect($config->dbDsn(), $config->dbUser(), $config->dbPass());
        $dns ??= BoundedDnsChecker::fromSystem();
        $clock ??= new SystemClock();
        $turnstile ??= new CurlTurnstileVerifier();
        $session ??= new NativeSession();
        $mailDns ??= new SystemDnsResolver();

        // Gating is opt-in: enabled only when explicit install paths are given.
        $gateInstall = $installPaths !== null;
        $installPaths ??= Paths::production();

        $container = self::buildContainer($config, $db, $dns, $clock, $mailer, $turnstile, $session, $installPaths, $mailDns);

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();

        $app->addRoutingMiddleware();

        // In production, error details are OFF: our ErrorHandler renders plain,
        // trace-free pages (frozen JSON contract for the API, minimal styled
        // HTML for browser/admin/installer). In dev the same handler stays
        // verbose (real message + trace) to aid debugging.
        $displayErrorDetails = $config->appEnv() !== 'production';
        $errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);
        $errorMiddleware->setDefaultErrorHandler(
            new \OpenSendForm\Http\ErrorHandler($app->getResponseFactory())
        );

        Routes::register($app);
        AdminRoutes::register($app);
        InstallRoutes::register($app);

        // Decide reachability from the installed state before any route runs.
        $app->add(new InstallStateMiddleware($installPaths, $gateInstall));

        // Outermost: the app-wide security baseline (X-Content-Type-Options:
        // nosniff on every response, including install redirects/404s and the
        // public JSON API). The admin/installer groups add the fuller document
        // header set on top via SecurityHeadersMiddleware.
        $app->add(new \OpenSendForm\Http\BaselineHeadersMiddleware());

        return $app;
    }

    private static function buildContainer(
        Config $config,
        Database $db,
        DnsChecker $dns,
        Clock $clock,
        ?MailerInterface $mailer,
        TurnstileVerifierInterface $turnstile,
        SessionInterface $session,
        Paths $installPaths,
        DnsResolver $mailDns
    ): Container {
        $container = new Container();

        $container->set(Config::class, $config);
        $container->set(Database::class, $db);
        $container->set(DnsChecker::class, $dns);
        $container->set(Clock::class, $clock);
        // Client-IP policy: REMOTE_ADDR by default; X-Forwarded-For only when a
        // trusted-proxy list is configured. Shared by the submit pipeline and
        // the admin login limiter so a logged/rate-limited IP is derived once,
        // the same way, everywhere.
        $container->set(ClientIpResolver::class, new ClientIpResolver($config->trustedProxies()));

        $forms = new FormRepository($db);
        $submissions = new SubmissionRepository($db);
        $limiter = new RateLimiter($db, $clock);
        $tokens = new SubmitToken(
            $config->appSecret(),
            $clock,
            $config->minSubmitSeconds(),
            $config->tokenMaxAgeSeconds()
        );

        // Mail delivery is wired only when a transport is supplied; otherwise
        // the delivery stage runs as a no-op and submissions stay 'received'.
        $delivery = $mailer === null ? null : new DeliveryService(
            $submissions,
            $forms,
            new MessageBuilder(),
            $mailer,
            $config,
            $clock
        );

        $container->set(FormRepository::class, $forms);
        $container->set(SubmissionRepository::class, $submissions);
        $container->set(RateLimiter::class, $limiter);
        $container->set(SubmitToken::class, $tokens);
        if ($delivery !== null) {
            $container->set(DeliveryService::class, $delivery);
        }
        $container->set(TurnstileVerifierInterface::class, $turnstile);

        // A transport for the admin mail wizard's test send. It always uses the
        // CURRENTLY SAVED config (fixed at build), independent of whether the
        // delivery pipeline is wired, so a test can be sent while sending is
        // still off. Tests inject a fake via the $mailer argument.
        $container->set(MailerInterface::class, $mailer ?? new PhpMailerMailer($config));
        // TXT resolver + deliverability checker for the SPF/DKIM/DMARC section.
        $container->set(DnsResolver::class, $mailDns);
        $container->set(DeliverabilityChecker::class, new DeliverabilityChecker($mailDns));

        $container->set(
            SubmitPipeline::class,
            SubmitPipeline::create($config, $forms, $limiter, $tokens, $dns, $submissions, $delivery, $turnstile)
        );

        // Admin authentication stack.
        $hasher = new PasswordHasher();
        $totp = new Totp();
        $recovery = new RecoveryCodes($hasher);
        $adminRepo = new AdminRepository($db, $hasher, $recovery);

        $container->set(SessionInterface::class, $session);
        $container->set(PasswordHasher::class, $hasher);
        $container->set(Totp::class, $totp);
        $container->set(RecoveryCodes::class, $recovery);
        $container->set(AdminRepository::class, $adminRepo);
        $container->set(Csrf::class, new Csrf($session));
        $container->set(Flash::class, new Flash($session));
        $container->set(
            AuthService::class,
            new AuthService(
                $adminRepo,
                $hasher,
                $totp,
                $session,
                $limiter,
                $clock,
                $config->rateLoginPerIp(),
                $config->rateLoginPerEmail(),
                $config->rateLoginWindowSeconds(),
                // Session idle/absolute lifetimes are timeouts, not rate limits;
                // keep the constructor defaults (30 min / 12 h).
                1800,
                43200,
                null,
                $config->rateTotpPerAdmin(),
                $config->rateTotpPerIp()
            )
        );
        $container->set(
            TemplateRenderer::class,
            new TemplateRenderer(dirname(__DIR__) . '/templates/admin')
        );

        // Browser installer. Paths carry where the config/lock/data/migrations
        // live; the connector opens the chosen DB (real PDO in production, a
        // fake in tests). The install renderer is a TemplateRenderer bound to
        // templates/install/ under a string key to sit beside the admin one.
        $container->set(Paths::class, $installPaths);
        $container->set(
            InstallerService::class,
            new InstallerService($installPaths, new PdoDbConnector(), $hasher, $recovery)
        );
        // The admin mail wizard writes changed settings back to the same config
        // file the installer produced, atomically (temp+rename, 0600).
        $container->set(ConfigWriter::class, new ConfigWriter($installPaths->configPath));
        $container->set(
            'install.renderer',
            new TemplateRenderer(dirname(__DIR__) . '/templates/install')
        );

        return $container;
    }
}
