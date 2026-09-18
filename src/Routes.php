<?php

declare(strict_types=1);

namespace OpenSendForm;

use OpenSendForm\Form\FormRepository;
use OpenSendForm\Http\ApiResponse;
use OpenSendForm\Http\OriginMatcher;
use OpenSendForm\Http\SubmitHtmlPage;
use OpenSendForm\Security\SubmitToken;
use OpenSendForm\Submit\SubmitContext;
use OpenSendForm\Submit\SubmitPipeline;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\App;

/**
 * Route definitions.
 *
 * Health check, plus the versioned public API (v1): a token endpoint the
 * embed JS calls before rendering a form, CORS preflight handlers, and the
 * submission endpoint driven by the ordered SubmitPipeline. Handlers pull
 * their collaborators from the app container captured at registration.
 */
final class Routes
{
    public static function register(App $app): void
    {
        $container = $app->getContainer();
        if ($container === null) {
            throw new RuntimeException('Routes require a DI container.');
        }

        $app->get('/health', [self::class, 'health']);

        // Browsers auto-request /favicon.ico; answer with a 204 No Content so it
        // never reaches the router as an unmatched path and generates 404 noise
        // in the error log. (We ship no icon; 204 is lighter than serving one.)
        $app->get('/favicon.ico', function (ServerRequestInterface $req, ResponseInterface $res): ResponseInterface {
            return $res->withStatus(204);
        });

        // GET / has no page of its own; bounce straight to the sign-in screen.
        // (When the instance isn't installed yet, InstallStateMiddleware
        // redirects to /install before routing ever reaches this handler.)
        $app->get('/', function (ServerRequestInterface $req, ResponseInterface $res): ResponseInterface {
            return $res->withHeader('Location', '/admin/login')->withStatus(302);
        });

        // The embed client artefact, served with a long immutable cache (the
        // snippet busts it with a ?v= query). In production Apache serves the
        // static file directly; this route is the front-controller fallback and
        // the seam the header test drives.
        $app->get(
            '/embed/osf.js',
            function (ServerRequestInterface $req, ResponseInterface $res) use ($container): ResponseInterface {
                return Routes::embedScript($container, $req, $res);
            }
        );

        // A manual, human-driven test checklist for the embed states, served
        // only in the dev environment (404 otherwise) so it never ships live.
        $app->get(
            '/embed/manual.html',
            function (ServerRequestInterface $req, ResponseInterface $res) use ($container): ResponseInterface {
                return Routes::embedManual($container, $req, $res);
            }
        );

        // Non-static closures: Slim's CallableResolver binds route Closures to
        // the container ($this), which fails for static closures.
        $app->get(
            '/v1/form/{form_key}/token',
            function (ServerRequestInterface $req, ResponseInterface $res, array $args) use ($container): ResponseInterface {
                return Routes::token($container, $req, $res, $args);
            }
        );
        $app->options(
            '/v1/form/{form_key}/token',
            function (ServerRequestInterface $req, ResponseInterface $res, array $args) use ($container): ResponseInterface {
                return Routes::preflightToken($container, $req, $res, $args);
            }
        );

        // /v1/form/{form_key}/submit is mapped for the common methods so a
        // non-POST reaches our handler and returns the contract's
        // method_not_allowed body (rather than Slim's default 405). OPTIONS
        // is handled separately as a CORS preflight.
        $app->map(
            ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
            '/v1/form/{form_key}/submit',
            function (ServerRequestInterface $req, ResponseInterface $res, array $args) use ($container): ResponseInterface {
                return Routes::submit($container, $req, $res, $args);
            }
        );
        $app->options(
            '/v1/form/{form_key}/submit',
            function (ServerRequestInterface $req, ResponseInterface $res, array $args) use ($container): ResponseInterface {
                return Routes::preflightSubmit($container, $req, $res, $args);
            }
        );
    }

    public static function health(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $payload = json_encode([
            'status'  => 'ok',
            'version' => Version::STRING,
        ], JSON_THROW_ON_ERROR);

        $response->getBody()->write($payload);

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }

    /**
     * GET /v1/form/{form_key}/token — issue a submit token for an allowed
     * origin. Unknown/inactive key: 403 unknown_form. Disallowed origin:
     * 403 origin_not_allowed.
     *
     * @param array<string, string> $args
     */
    private static function token(
        ContainerInterface $container,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        /** @var FormRepository $forms */
        $forms = $container->get(FormRepository::class);
        /** @var SubmitToken $tokens */
        $tokens = $container->get(SubmitToken::class);

        $form = $forms->findByKey($args['form_key'] ?? '');
        if ($form === null) {
            return ApiResponse::error($response, 403, 'unknown_form', 'Unknown or inactive form.');
        }

        $matched = OriginMatcher::match(
            self::header($request, 'Origin'),
            self::header($request, 'Referer'),
            $form['allowed_origins']
        );
        if ($matched === null) {
            return ApiResponse::error($response, 403, 'origin_not_allowed', 'Origin is not allowed for this form.');
        }

        $token = $tokens->issue($form['form_key']);

        $extra = ['token' => $token];

        // Advertise the public sitekey when (and only when) this form has
        // Turnstile enabled, so the embed JS can render the widget. The
        // secret is never included in any response.
        $sitekey = (string) ($form['turnstile_sitekey'] ?? '');
        $secret = (string) ($form['turnstile_secret'] ?? '');
        if ($sitekey !== '' && $secret !== '') {
            $extra['turnstile'] = ['sitekey' => $sitekey];
        }

        return ApiResponse::withCors(
            ApiResponse::success($response, $extra),
            $matched
        );
    }

    /**
     * OPTIONS /v1/form/{form_key}/token — CORS preflight for the token
     * endpoint (GET).
     *
     * @param array<string, string> $args
     */
    private static function preflightToken(
        ContainerInterface $container,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        /** @var FormRepository $forms */
        $forms = $container->get(FormRepository::class);

        $allowed = null;
        $form = $forms->findByKey($args['form_key'] ?? '');
        if ($form !== null) {
            $allowed = OriginMatcher::match(
                self::header($request, 'Origin'),
                self::header($request, 'Referer'),
                $form['allowed_origins']
            );
        }

        return self::preflight($response, 'GET', $allowed);
    }

    /**
     * OPTIONS /v1/form/{form_key}/submit — CORS preflight for the
     * submission endpoint (POST). The form key is in the URL, so the
     * preflight resolves the specific form and echoes the origin only if
     * that form's allowlist matches. Unknown/inactive key or a non-allowed
     * origin: no ACAO header.
     *
     * @param array<string, string> $args
     */
    private static function preflightSubmit(
        ContainerInterface $container,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        /** @var FormRepository $forms */
        $forms = $container->get(FormRepository::class);

        $allowed = null;
        $form = $forms->findByKey($args['form_key'] ?? '');
        if ($form !== null) {
            $allowed = OriginMatcher::match(
                self::header($request, 'Origin'),
                self::header($request, 'Referer'),
                $form['allowed_origins']
            );
        }

        return self::preflight($response, 'POST', $allowed);
    }

    /**
     * POST /v1/form/{form_key}/submit — run the submission pipeline and
     * render its outcome.
     *
     * @param array<string, string> $args
     */
    private static function submit(
        ContainerInterface $container,
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        /** @var SubmitPipeline $pipeline */
        $pipeline = $container->get(SubmitPipeline::class);

        /** @var \OpenSendForm\Http\ClientIpResolver $ipResolver */
        $ipResolver = $container->get(\OpenSendForm\Http\ClientIpResolver::class);
        $context = new SubmitContext($request, $args['form_key'] ?? null, $ipResolver);
        $outcome = $pipeline->run($context);

        // Content negotiation: a native (no-JS) browser form POST is a top-level
        // navigation that prefers text/html, so it gets a readable HTML page.
        // Every API/fetch client (the embed JS sends Accept: application/json,
        // programmatic callers send */* or nothing) keeps the frozen JSON
        // contract. $context->prefersHtml was computed from this same request
        // and already drove TokenStage's no-JS policy.
        if ($context->prefersHtml) {
            $back = self::safeBackUrl(self::header($request, 'Referer'));
            $result = SubmitHtmlPage::render($response, $outcome, $back);
        } else {
            $result = $outcome->isSuccess()
                ? ApiResponse::success($response)
                : ApiResponse::error($response, $outcome->status(), $outcome->code(), $outcome->message());
        }

        // CORS headers ride on responses only once the origin has been
        // validated (matchedOrigin set by the origin stage).
        return ApiResponse::withCors($result, $context->matchedOrigin);
    }

    /**
     * GET /embed/osf.js — serve the embed client artefact with a long,
     * immutable cache. The snippet references it with a ?v= query, so the
     * bytes at a given URL never change.
     */
    private static function embedScript(
        ContainerInterface $container,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $file = dirname(__DIR__) . '/public/embed/osf.js';
        if (!is_file($file)) {
            return $response->withStatus(404);
        }

        $response->getBody()->write((string) file_get_contents($file));

        return $response
            ->withHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->withStatus(200);
    }

    /**
     * GET /embed/manual.html — the manual embed-testing checklist, served only
     * when APP_ENV is 'dev'. In every other environment it 404s so it can never
     * be reached on a real installation.
     */
    private static function embedManual(
        ContainerInterface $container,
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        /** @var Config $config */
        $config = $container->get(Config::class);
        $file = dirname(__DIR__) . '/tests/embed-manual.html';

        if ($config->appEnv() !== 'dev' || !is_file($file)) {
            return $response->withStatus(404);
        }

        $response->getBody()->write((string) file_get_contents($file));

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withStatus(200);
    }

    /**
     * Validate a Referer for use as the "back to the form" link: only absolute
     * http(s) URLs are echoed, so nothing else (javascript:, data:, relative)
     * can be reflected into the page.
     */
    private static function safeBackUrl(?string $referer): ?string
    {
        if ($referer === null) {
            return null;
        }

        $scheme = strtolower((string) parse_url($referer, PHP_URL_SCHEME));
        if (($scheme === 'http' || $scheme === 'https') && parse_url($referer, PHP_URL_HOST) !== null) {
            return $referer;
        }

        return null;
    }

    /**
     * Build a 204 CORS preflight response.
     */
    private static function preflight(
        ResponseInterface $response,
        string $methods,
        ?string $allowedOrigin
    ): ResponseInterface {
        $response = $response
            ->withStatus(204)
            ->withHeader('Access-Control-Allow-Methods', $methods)
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type')
            ->withHeader('Vary', 'Origin');

        if ($allowedOrigin !== null) {
            $response = $response->withHeader('Access-Control-Allow-Origin', $allowedOrigin);
        }

        return $response;
    }

    private static function header(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getHeaderLine($name);

        return $value === '' ? null : $value;
    }
}
