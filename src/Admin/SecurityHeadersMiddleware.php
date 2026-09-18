<?php

declare(strict_types=1);

namespace OpenSendForm\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Applies hardening headers to every admin response.
 *
 * DENY framing, block MIME sniffing, send a trimmed referrer
 * (strict-origin-when-cross-origin: full URL same-origin, bare origin
 * cross-origin, nothing on downgrade), and never cache admin pages (they are
 * authenticated and may contain one-time secrets such as freshly generated
 * recovery codes).
 *
 * A strict Content-Security-Policy locks every source to our own origin
 * (with data: images for QR/inline assets). All admin CSS and JS are
 * self-hosted under public/assets/, and no template emits an inline script,
 * inline style or inline event handler, so the policy passes untouched. This
 * middleware wraps only the /admin group — the public JSON API is left
 * without a CSP (it serves no HTML).
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** The admin Content-Security-Policy; see the class docblock. */
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; "
        . "img-src 'self' data:; frame-ancestors 'none'";

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        return $response
            ->withHeader('Content-Security-Policy', self::CSP)
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Cache-Control', 'no-store');
    }
}
