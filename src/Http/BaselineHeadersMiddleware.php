<?php

declare(strict_types=1);

namespace OpenSendForm\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Applies the one security header that every response — public API included —
 * should carry: X-Content-Type-Options: nosniff, so a browser never
 * MIME-sniffs a response into an unintended, exploitable type.
 *
 * This is the app-wide baseline. The admin and installer groups layer the
 * fuller document set (strict CSP, X-Frame-Options, Referrer-Policy, no-store)
 * on top via SecurityHeadersMiddleware; both middlewares set nosniff to the
 * same value, so the header appears exactly once. The public JSON API
 * deliberately gets ONLY nosniff — a JSON response has no framing or referrer
 * concern and carries no CSP (locked by AdminUiTest).
 */
final class BaselineHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
