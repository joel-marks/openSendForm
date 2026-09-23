<?php

declare(strict_types=1);

namespace OpenSendForm\Http;

use OpenSendForm\Submit\SubmitOutcome;
use OpenSendForm\Version;
use Psr\Http\Message\ResponseInterface;

/**
 * The no-JavaScript fallback pages for the submission endpoint.
 *
 * Progressive enhancement is absolute: a plain <form> whose action is the
 * submit URL still works with JS absent or failed. Such a request is a
 * top-level browser navigation that prefers text/html, so instead of the JSON
 * contract the endpoint returns one of these pages — styled within the design
 * system and wearing the one shared header (chrome-only appbar, Task 1) —
 * echoing success or the failure message with a link back to the page the
 * submission came from.
 *
 * The JSON contract is unchanged; content negotiation in Routes::submit picks
 * this renderer only when the client explicitly prefers HTML.
 */
final class SubmitHtmlPage
{
    /**
     * Render the outcome as an HTML page. Success mirrors the 200 of the JSON
     * contract; a failure keeps the outcome's own HTTP status.
     *
     * @param string|null $backUrl A validated http(s) URL to link back to, or null.
     */
    public static function render(
        ResponseInterface $response,
        SubmitOutcome $outcome,
        ?string $backUrl
    ): ResponseInterface {
        if ($outcome->isSuccess()) {
            $body = self::page(
                'Message sent',
                'Message sent',
                'Thanks — your message has been sent.',
                $backUrl
            );
            $status = 200;
        } else {
            $body = self::page(
                'Something went wrong',
                'Something went wrong',
                $outcome->message(),
                $backUrl
            );
            $status = $outcome->status();
        }

        $response->getBody()->write($body);

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withStatus($status);
    }

    private static function page(string $title, string $heading, string $message, ?string $backUrl): string
    {
        $t = self::esc($title);
        $h = self::esc($heading);
        $m = self::esc($message);

        $back = '';
        if ($backUrl !== null) {
            $back = '<p class="osf-actions"><a href="' . self::esc($backUrl) . '">&larr; Back to the form</a></p>';
        }

        $tokens = self::esc('/assets/tokens.css?v=' . Version::STRING);
        $css = self::esc('/assets/admin.css?v=' . Version::STRING);
        $themeInit = self::esc('/assets/theme-init.js?v=' . Version::STRING);
        $adminJs = self::esc('/assets/admin.js?v=' . Version::STRING);

        // The one shared header (chrome-only variant) — same appbar as every
        // other page in the app.
        require_once dirname(__DIR__) . '/Admin/helpers.php';
        require_once dirname(__DIR__) . '/Admin/icons.php';
        require_once dirname(__DIR__) . '/Admin/appbar.php';
        $appbar = \OpenSendForm\Admin\appbar(['variant' => 'chrome-only']);

        return <<<HTML
<!DOCTYPE html>
<html lang="en" data-palette="github">
<head>
<script src="{$themeInit}"></script>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>osf - {$t}</title>
<link rel="stylesheet" href="{$tokens}">
<link rel="stylesheet" href="{$css}">
</head>
<body>
{$appbar}
<main class="container">
<section class="osf-error">
<h1>{$h}</h1>
<p>{$m}</p>
{$back}
</section>
</main>
<script src="{$adminJs}" defer></script>
</body>
</html>
HTML;
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
