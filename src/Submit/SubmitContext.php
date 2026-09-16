<?php

declare(strict_types=1);

namespace OpenSendForm\Submit;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Mutable state threaded through the submission pipeline.
 *
 * Constructed once per request from the incoming ServerRequest; each stage
 * reads what earlier stages have populated and fills in its own results.
 */
final class SubmitContext
{
    /** Reserved field names carried alongside the user's form data. */
    public const FIELD_TOKEN = '_osf_token';
    public const FIELD_HONEYPOT = '_osf_hp';
    public const FIELD_TURNSTILE = '_osf_cf';
    /**
     * The synthetic-monitor marker: the monitor sends its shared secret here.
     * Like every reserved field it is stripped from stored content and never
     * bypasses a stage; the store stage compares it to MONITOR_SECRET and, on
     * an exact match, records the submission as synthetic.
     */
    public const FIELD_MONITOR = '_osf_monitor';

    public ServerRequestInterface $request;

    /** Raw request body, read once for size checks and parsing. */
    public string $rawBody = '';

    /** Lower-cased content-type without parameters (e.g. "application/json"). */
    public string $contentType = '';

    /**
     * All submitted fields keyed by name. The method/body stage populates
     * this with the raw parsed values (which may be non-scalar); the field
     * hygiene stage then narrows it to validated strings.
     *
     * @var array<string, mixed>
     */
    public array $fields = [];

    /**
     * User fields only — reserved _osf_* keys removed (set by the field
     * hygiene stage). This is what gets serialised and stored.
     *
     * @var array<string, string>
     */
    public array $userFields = [];

    /** The form key from the request URL (/v1/form/{form_key}/submit). */
    public ?string $formKey;

    public ?string $token = null;
    public ?string $honeypot = null;

    /** The Turnstile client token (reserved field _osf_cf), if supplied. */
    public ?string $turnstileToken = null;

    /**
     * The value of the reserved _osf_monitor field, if supplied. The store
     * stage compares it to the configured MONITOR_SECRET to decide whether the
     * stored row is synthetic; it never affects any earlier stage.
     */
    public ?string $monitorSecret = null;

    /**
     * The looked-up, active form (set by the form-lookup stage).
     *
     * @var array<string, mixed>|null
     */
    public ?array $form = null;

    /**
     * The request origin once validated against the form allowlist (set by
     * the origin stage). Drives CORS headers on the response.
     */
    public ?string $matchedOrigin = null;

    /**
     * The id of the stored submission (set by the store stage). Read by the
     * delivery stage to know which row to attempt sending.
     */
    public ?int $submissionId = null;

    public string $remoteIp = '';
    public ?string $userAgent = null;
    public ?string $originHeader = null;
    public ?string $refererHeader = null;

    /**
     * Whether this request negotiated an HTML response (a native, top-level
     * browser form POST) rather than the JSON contract. Set once here from
     * the same Accept-header logic Routes::submit uses to pick the renderer,
     * so stages (TokenStage) can see it without re-parsing headers.
     */
    public bool $prefersHtml = false;

    public function __construct(ServerRequestInterface $request, ?string $formKey = null)
    {
        $this->request = $request;
        $this->formKey = $formKey === '' ? null : $formKey;

        $server = $request->getServerParams();
        // REMOTE_ADDR only. Trusting X-Forwarded-For behind a proxy is a
        // deliberate future config concern, not a default.
        $this->remoteIp = (string) ($server['REMOTE_ADDR'] ?? '');

        $ua = $request->getHeaderLine('User-Agent');
        $this->userAgent = $ua === '' ? null : $ua;

        $origin = $request->getHeaderLine('Origin');
        $this->originHeader = $origin === '' ? null : $origin;

        $referer = $request->getHeaderLine('Referer');
        $this->refererHeader = $referer === '' ? null : $referer;

        $this->prefersHtml = self::computePrefersHtml($request);
    }

    /**
     * True only for a request that positively wants text/html and does not
     * ask for JSON — i.e. a native browser navigation. The absence of an
     * Accept header (typical of API/curl callers) keeps the JSON default.
     */
    public static function computePrefersHtml(ServerRequestInterface $request): bool
    {
        $accept = strtolower($request->getHeaderLine('Accept'));
        if ($accept === '' || strpos($accept, 'application/json') !== false) {
            return false;
        }

        return strpos($accept, 'text/html') !== false;
    }
}
