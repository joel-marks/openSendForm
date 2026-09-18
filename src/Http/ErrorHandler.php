<?php

declare(strict_types=1);

namespace OpenSendForm\Http;

use OpenSendForm\Version;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Throwable;

/**
 * The application's error handler, registered on Slim's ErrorMiddleware for
 * every uncaught error — 404 (no route), 405 (wrong method) and 500 (any
 * unexpected Throwable).
 *
 * Two response shapes, chosen the same way the submit endpoint negotiates:
 *
 *  - API / fetch callers (anything under /v1, or a request that does not
 *    positively prefer HTML) get the FROZEN JSON contract shape,
 *    {"ok":false,"error":{"code","message"}}, so a 404/500 looks like every
 *    other API error to the embed JS.
 *  - Browser navigations (admin, installer, any text/html request) get a
 *    minimal HTML page styled within the design system (tokens.css + admin.css).
 *
 * PRODUCTION SAFETY: when error details are off (APP_ENV=production) neither
 * shape ever leaks a stack trace, exception class or file path — only a stable
 * code and a generic human message. In development ($displayErrorDetails true)
 * the real message and trace are shown to aid debugging.
 *
 * The signature matches Slim's error-handler contract, so this is registered
 * via ErrorMiddleware::setDefaultErrorHandler().
 */
final class ErrorHandler
{
    public function __construct(private ResponseFactoryInterface $responseFactory)
    {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ): ResponseInterface {
        $status = $this->statusFor($exception);
        $response = $this->responseFactory->createResponse($status);

        if ($this->wantsHtml($request)) {
            return $this->renderHtml($response, $status, $exception, $displayErrorDetails);
        }

        return $this->renderJson($response, $status, $exception, $displayErrorDetails);
    }

    /**
     * The HTTP status to serve: an HttpException carries its own (404/405/…);
     * anything else is an unexpected server error (500).
     */
    private function statusFor(Throwable $exception): int
    {
        if ($exception instanceof HttpException) {
            $code = $exception->getCode();
            if ($code >= 400 && $code <= 599) {
                return $code;
            }
        }

        return 500;
    }

    /**
     * Negotiate HTML vs the JSON contract. Anything under /v1 is always API
     * (JSON); otherwise a request that positively prefers text/html (a browser
     * navigation) gets the HTML page, and everything else keeps JSON — the same
     * rule the submit endpoint uses.
     */
    private function wantsHtml(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/v1/')) {
            return false;
        }

        $accept = strtolower($request->getHeaderLine('Accept'));
        if ($accept === '' || str_contains($accept, 'application/json')) {
            return false;
        }

        return str_contains($accept, 'text/html');
    }

    private function renderJson(
        ResponseInterface $response,
        int $status,
        Throwable $exception,
        bool $displayErrorDetails
    ): ResponseInterface {
        $code = $this->errorCode($status);
        $message = $this->genericMessage($status);

        // Dev only: surface the real message in the (mutable) message field,
        // keeping the frozen contract SHAPE intact. Production stays generic.
        if ($displayErrorDetails && $exception->getMessage() !== '') {
            $message = $exception->getMessage();
        }

        return ApiResponse::error($response, $status, $code, $message);
    }

    private function renderHtml(
        ResponseInterface $response,
        int $status,
        Throwable $exception,
        bool $displayErrorDetails
    ): ResponseInterface {
        $heading = $this->genericHeading($status);
        $message = $this->genericMessage($status);

        $detail = '';
        if ($displayErrorDetails) {
            // Development aid only — never rendered when details are off.
            $detail = "\n            <pre class=\"osf-error-detail\">"
                . self::e(get_class($exception) . ': ' . $exception->getMessage())
                . "\n" . self::e($exception->getFile() . ':' . $exception->getLine())
                . "\n\n" . self::e($exception->getTraceAsString())
                . "</pre>";
        }

        $tokens = self::e('/assets/tokens.css?v=' . Version::STRING);
        $css = self::e('/assets/admin.css?v=' . Version::STRING);
        $themeInit = self::e('/assets/theme-init.js?v=' . Version::STRING);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en" data-palette="github">
<head>
    <script src="{$themeInit}"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{$heading} — OpenSendForm</title>
    <link rel="stylesheet" href="{$tokens}">
    <link rel="stylesheet" href="{$css}">
</head>
<body>
<main class="container">
    <section class="osf-error">
        <h1>{$heading}</h1>
        <p>{$message}</p>{$detail}
    </section>
</main>
</body>
</html>

HTML;

        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** Stable machine code for the JSON contract. */
    private function errorCode(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            default => $status >= 500 ? 'server_error' : 'error',
        };
    }

    private function genericMessage(int $status): string
    {
        return match ($status) {
            404 => 'The page or resource you requested could not be found.',
            405 => 'That method is not allowed for this resource.',
            403 => 'You do not have access to that resource.',
            default => $status >= 500
                ? 'Something went wrong on our end. Please try again later.'
                : 'The request could not be completed.',
        };
    }

    private function genericHeading(int $status): string
    {
        return match ($status) {
            404 => 'Page not found',
            405 => 'Method not allowed',
            403 => 'Access denied',
            default => $status >= 500 ? 'Something went wrong' : 'Request error',
        };
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
