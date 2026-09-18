<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Submit;

use OpenSendForm\AppFactory;
use OpenSendForm\Config;
use OpenSendForm\Form\FormRepository;
use OpenSendForm\Security\SubmitToken;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Submit\SubmitContext;
use OpenSendForm\Tests\Support\FakeDnsChecker;
use OpenSendForm\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * End-to-end coverage of the /v1 submission and token endpoints, driven
 * through the app factory against an in-memory database with a fixed clock
 * and a fake DNS resolver. No real network or wall-clock time is touched.
 */
final class SubmitEndpointTest extends TestCase
{
    private const SECRET = 'endpoint-test-secret';
    private const T0 = 1_700_000_000;
    private const ORIGIN = 'https://example.com';

    private Database $db;
    private FixedClock $clock;
    private FakeDnsChecker $dns;
    private FormRepository $forms;
    /** @var array<string, mixed> */
    private array $form;

    protected function setUp(): void
    {
        $this->db = Database::connect('sqlite::memory:');
        (new MigrationRunner($this->db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        $this->clock = new FixedClock(self::T0);
        $this->dns = new FakeDnsChecker(true);
        $this->forms = new FormRepository($this->db);
        $this->form = $this->forms->createForm('Contact', 'owner@example.com', [self::ORIGIN]);
    }

    // --- Happy path -------------------------------------------------------

    public function testHappyPathStoresSubmissionAndReturnsOk(): void
    {
        $token = $this->freshToken();
        $this->clock->advance(3); // token now old enough to be VALID

        $response = $this->handle($this->submitRequest([
            SubmitContext::FIELD_TOKEN => $token,
            'name'                     => 'Ada',
            'message'                  => 'Hello there',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], $this->json($response));
        self::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame(1, $this->submissionCount());

        $stored = $this->lastSubmission();
        self::assertSame('received', $stored['status']);
        self::assertSame('203.0.113.7', $stored['remote_ip']);
        self::assertSame(self::ORIGIN, $stored['origin']);
    }

    public function testJsonBodyIsAccepted(): void
    {
        $token = $this->freshToken();
        $this->clock->advance(3);

        $response = $this->handle($this->submitRequest([
            SubmitContext::FIELD_TOKEN => $token,
            'name'                     => 'Ada',
        ], ['content_type' => 'application/json']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->submissionCount());
    }

    public function testContentIsAlwaysStoredAtSubmitTimeRegardlessOfStoreContentToggle(): void
    {
        $token = $this->freshToken();
        $this->clock->advance(3);

        $this->handle($this->submitRequest([
            SubmitContext::FIELD_TOKEN => $token,
            'message'                  => 'secret content',
        ]));

        // store_content = 0 (default) only governs retention after a
        // successful delivery; content is always the in-flight payload.
        $decoded = json_decode((string) $this->lastSubmission()['content'], true);
        self::assertSame(['message' => 'secret content'], $decoded);
    }

    public function testStoreContentOnPersistsUserFieldsAsJson(): void
    {
        $this->db->execute(
            'UPDATE forms SET store_content = 1 WHERE id = :id',
            ['id' => $this->form['id']]
        );

        $token = $this->freshToken();
        $this->clock->advance(3);

        $this->handle($this->submitRequest([
            SubmitContext::FIELD_TOKEN => $token,
            'name'                     => 'Ada',
            'message'                  => 'Hello',
        ]));

        $decoded = json_decode((string) $this->lastSubmission()['content'], true);
        // Reserved _osf_* fields are excluded from stored content.
        self::assertSame(['name' => 'Ada', 'message' => 'Hello'], $decoded);
    }

    // --- Silent (bot-facing) discards ------------------------------------

    public function testFilledHoneypotReturnsFakeSuccessAndStoresNothing(): void
    {
        $token = $this->freshToken();
        $this->clock->advance(3);

        $response = $this->handle($this->submitRequest([
            SubmitContext::FIELD_TOKEN    => $token,
            SubmitContext::FIELD_HONEYPOT => 'i am a bot',
            'name'                        => 'Ada',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], $this->json($response));
        self::assertSame(0, $this->submissionCount());
    }

    public function testMissingTokenReturnsFakeSuccessAndStoresNothing(): void
    {
        $response = $this->handle($this->submitRequest([
            'name' => 'Ada',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], $this->json($response));
        self::assertSame(0, $this->submissionCount());
    }

    public function testForgedTokenReturnsFakeSuccessAndStoresNothing(): void
    {
        $forged = self::T0 . '.' . str_repeat('0', 64);
        $this->clock->advance(3);

        $response = $this->handle($this->submitRequest([
            SubmitContext::FIELD_TOKEN => $forged,
            'name'                     => 'Ada',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], $this->json($response));
        self::assertSame(0, $this->submissionCount());
    }

    public function testTooYoungTokenReturnsFakeSuccessAndStoresNothing(): void
    {
        $token = $this->freshToken();
        // No clock advance: token age is 0, younger than MIN_SUBMIT_SECONDS.

        $response = $this->handle($this->submitRequest([
            SubmitContext::FIELD_TOKEN => $token,
            'name'                     => 'Ada',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], $this->json($response));
        self::assertSame(0, $this->submissionCount());
    }

    public function testExpiredTokenReturnsHonestError(): void
    {
        $token = $this->freshToken();
        $this->clock->advance(3601); // older than TOKEN_MAX_AGE_SECONDS

        $response = $this->handle($this->submitRequest([
            SubmitContext::FIELD_TOKEN => $token,
            'name'                     => 'Ada',
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('token_expired', $this->json($response)['error']['code']);
        self::assertSame(0, $this->submissionCount());
    }

    // --- Honest rejections -----------------------------------------------

    public function testNonPostReturnsMethodNotAllowed(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/v1/form/' . $this->form['form_key'] . '/submit', ['REMOTE_ADDR' => '203.0.113.7']);

        $response = $this->handle($request);

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('method_not_allowed', $this->json($response)['error']['code']);
    }

    public function testDeclaredContentLengthOverCapReturns413(): void
    {
        $request = $this->submitRequest([])
            ->withHeader('Content-Length', (string) (65536 + 1));

        $response = $this->handle($request);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('payload_too_large', $this->json($response)['error']['code']);
    }

    public function testOversizedBodyReturns413(): void
    {
        $big = str_repeat('a', 65536 + 10);
        $request = $this->submitRequest([
            'message' => $big,
        ]);

        $response = $this->handle($request);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('payload_too_large', $this->json($response)['error']['code']);
    }

    public function testTooManyFieldsReturnsInvalidFields(): void
    {
        $fields = [];
        for ($i = 0; $i < 60; $i++) {
            $fields['f' . $i] = 'x';
        }

        $response = $this->handle($this->submitRequest($fields));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_fields', $this->json($response)['error']['code']);
    }

    public function testOversizedFieldValueReturnsInvalidFields(): void
    {
        $response = $this->handle($this->submitRequest([
            'message' => str_repeat('a', 10240 + 1),
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_fields', $this->json($response)['error']['code']);
    }

    public function testUnknownFormKeyReturns403(): void
    {
        $response = $this->handle($this->submitRequest([
            'name' => 'Ada',
        ], ['form_key' => 'osf_deadbeef']));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('unknown_form', $this->json($response)['error']['code']);
    }

    public function testDeletedFormKeyNoLongerResolves(): void
    {
        // A deleted form's key must fall through to the same unknown-form
        // behaviour as any unrecognised key — the embed snippet dies naturally.
        $key = $this->form['form_key'];
        $this->forms->deleteForm($this->form['id']);

        $response = $this->handle($this->submitRequest([
            'name' => 'Ada',
        ], ['form_key' => $key]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('unknown_form', $this->json($response)['error']['code']);
    }

    public function testInactiveFormKeyReturns403(): void
    {
        $this->forms->setActive($this->form['id'], false);

        $response = $this->handle($this->submitRequest([
            'name' => 'Ada',
        ]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('unknown_form', $this->json($response)['error']['code']);
    }

    public function testDisallowedOriginReturns403WithoutCors(): void
    {
        $response = $this->handle($this->submitRequest([
            'name' => 'Ada',
        ], ['origin' => 'https://evil.com']));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('origin_not_allowed', $this->json($response)['error']['code']);
        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testInvalidEmailReturns400(): void
    {
        $token = $this->freshToken();
        $this->clock->advance(3);

        $response = $this->handle($this->submitRequest([
            SubmitContext::FIELD_TOKEN => $token,
            'email'                    => 'not-an-email',
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_email', $this->json($response)['error']['code']);
        self::assertSame(0, $this->submissionCount());
    }

    public function testEmailDomainWithoutMailReturns400(): void
    {
        $this->dns->setDomain('nomail.example', false);

        $token = $this->freshToken();
        $this->clock->advance(3);

        $response = $this->handle($this->submitRequest([
            SubmitContext::FIELD_TOKEN => $token,
            'email'                    => 'user@nomail.example',
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('email_domain_invalid', $this->json($response)['error']['code']);
        self::assertSame(0, $this->submissionCount());
    }

    // --- Rate limiting ----------------------------------------------------

    public function testPerIpRateLimitReturns429(): void
    {
        // RATE_IP_PER_MINUTE = 3 for this test; token not required to reach
        // the rate-limit stage (it runs before the token stage).
        $env = ['RATE_IP_PER_MINUTE' => '3'];

        for ($i = 0; $i < 3; $i++) {
            $ok = $this->handle($this->submitRequest([]), $env);
            self::assertSame(200, $ok->getStatusCode());
        }

        $blocked = $this->handle($this->submitRequest([]), $env);

        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame('rate_limited', $this->json($blocked)['error']['code']);
    }

    public function testPerIpWindowRollsOverWithClock(): void
    {
        $env = ['RATE_IP_PER_MINUTE' => '1'];

        $first = $this->handle($this->submitRequest([]), $env);
        self::assertSame(200, $first->getStatusCode());

        $blocked = $this->handle($this->submitRequest([]), $env);
        self::assertSame(429, $blocked->getStatusCode());

        // Advance into the next minute window: allowed again.
        $this->clock->advance(60);
        $allowed = $this->handle($this->submitRequest([]), $env);
        self::assertSame(200, $allowed->getStatusCode());
    }

    public function testPerFormRateLimitReturns429(): void
    {
        // Generous per-IP so the per-form limit is what trips; vary the IP so
        // the per-IP counter never fills.
        $env = ['RATE_IP_PER_MINUTE' => '1000', 'RATE_FORM_PER_HOUR' => '2'];

        $ip1 = $this->handle($this->submitRequest([], ['remote_ip' => '198.51.100.1']), $env);
        $ip2 = $this->handle($this->submitRequest([], ['remote_ip' => '198.51.100.2']), $env);
        self::assertSame(200, $ip1->getStatusCode());
        self::assertSame(200, $ip2->getStatusCode());

        $ip3 = $this->handle($this->submitRequest([], ['remote_ip' => '198.51.100.3']), $env);
        self::assertSame(429, $ip3->getStatusCode());
        self::assertSame('rate_limited', $this->json($ip3)['error']['code']);
    }

    // --- Pipeline ordering ------------------------------------------------

    public function testOriginCheckedBeforeHoneypot(): void
    {
        // Bad origin AND a filled honeypot: the origin error must win,
        // proving stage (d) runs before stage (f).
        $response = $this->handle($this->submitRequest([
            SubmitContext::FIELD_HONEYPOT => 'bot',
        ], ['origin' => 'https://evil.com']));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('origin_not_allowed', $this->json($response)['error']['code']);
        self::assertSame(0, $this->submissionCount());
    }

    // --- Trusted-proxy client IP -----------------------------------------

    public function testDefaultStoresRemoteAddrAndIgnoresForwardedFor(): void
    {
        $token = $this->freshToken();
        $this->clock->advance(3);

        $request = $this->submitRequest(
            [SubmitContext::FIELD_TOKEN => $token, 'name' => 'Ada'],
            ['remote_ip' => '203.0.113.7']
        )->withHeader('X-Forwarded-For', '10.9.9.9');

        // No TRUSTED_PROXIES configured: the forwarded header is ignored.
        self::assertSame(200, $this->handle($request)->getStatusCode());
        self::assertSame('203.0.113.7', $this->lastSubmission()['remote_ip']);
    }

    public function testTrustedProxyDerivesClientIpForStoredSubmission(): void
    {
        $token = $this->freshToken();
        $this->clock->advance(3);

        $request = $this->submitRequest(
            [SubmitContext::FIELD_TOKEN => $token, 'name' => 'Ada'],
            ['remote_ip' => '192.0.2.10'] // the trusted proxy
        )->withHeader('X-Forwarded-For', '203.0.113.77');

        $response = $this->handle($request, ['TRUSTED_PROXIES' => '192.0.2.10']);

        self::assertSame(200, $response->getStatusCode());
        // The logged IP is the real client, derived from X-Forwarded-For.
        self::assertSame('203.0.113.77', $this->lastSubmission()['remote_ip']);
    }

    public function testSpoofedForwardedForFromUntrustedPeerNeverWins(): void
    {
        $token = $this->freshToken();
        $this->clock->advance(3);

        // The direct peer (203.0.113.7) is NOT the trusted proxy, so its
        // attacker-supplied X-Forwarded-For is ignored outright.
        $request = $this->submitRequest(
            [SubmitContext::FIELD_TOKEN => $token, 'name' => 'Ada'],
            ['remote_ip' => '203.0.113.7']
        )->withHeader('X-Forwarded-For', '10.9.9.9');

        $response = $this->handle($request, ['TRUSTED_PROXIES' => '192.0.2.10']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('203.0.113.7', $this->lastSubmission()['remote_ip']);
    }

    public function testRateLimitKeysOnDerivedClientIpBehindTrustedProxy(): void
    {
        // Two distinct clients behind the same trusted proxy get independent
        // per-IP buckets; the proxy address is never the rate-limit key. The
        // rate-limit stage runs before the token stage, so no token is needed —
        // an under-limit request returns the frozen fake-success, an over-limit
        // one returns rate_limited.
        $env = ['TRUSTED_PROXIES' => '192.0.2.10', 'RATE_IP_PER_MINUTE' => '1'];

        $req = function (string $client): ServerRequestInterface {
            return $this->submitRequest(['name' => 'x'], ['remote_ip' => '192.0.2.10'])
                ->withHeader('X-Forwarded-For', $client);
        };

        // First client, first hit — allowed (its bucket now holds 1).
        self::assertNotSame(429, $this->handle($req('203.0.113.1'), $env)->getStatusCode());
        // A DIFFERENT client behind the same proxy — its own bucket, allowed.
        self::assertNotSame(429, $this->handle($req('203.0.113.2'), $env)->getStatusCode());
        // The FIRST client again — its bucket is full (limit 1), so blocked.
        $blocked = $this->handle($req('203.0.113.1'), $env);
        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame('rate_limited', $this->json($blocked)['error']['code']);
    }

    // --- Token endpoint ---------------------------------------------------

    public function testTokenEndpointIssuesTokenForAllowedOrigin(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/v1/form/' . $this->form['form_key'] . '/token')
            ->withHeader('Origin', self::ORIGIN);

        $response = $this->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $json = $this->json($response);
        self::assertTrue($json['ok']);
        self::assertMatchesRegularExpression('/^\d+\.[0-9a-f]{64}$/', $json['token']);
        self::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testTokenEndpointRejectsUnknownForm(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/v1/form/osf_nope/token')
            ->withHeader('Origin', self::ORIGIN);

        $response = $this->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('unknown_form', $this->json($response)['error']['code']);
    }

    public function testTokenEndpointRejectsDisallowedOrigin(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/v1/form/' . $this->form['form_key'] . '/token')
            ->withHeader('Origin', 'https://evil.com');

        $response = $this->handle($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('origin_not_allowed', $this->json($response)['error']['code']);
    }

    // --- CORS preflight ---------------------------------------------------

    public function testSubmitPreflightEchoesAllowedOrigin(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', '/v1/form/' . $this->form['form_key'] . '/submit')
            ->withHeader('Origin', self::ORIGIN);

        $response = $this->handle($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testSubmitPreflightWithholdsOriginWhenDisallowed(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', '/v1/form/' . $this->form['form_key'] . '/submit')
            ->withHeader('Origin', 'https://evil.com');

        $response = $this->handle($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testSubmitPreflightWithholdsOriginForUnknownFormKey(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', '/v1/form/osf_nope/submit')
            ->withHeader('Origin', self::ORIGIN);

        $response = $this->handle($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testSubmitPreflightDoesNotLeakAnotherFormsOrigin(): void
    {
        // Exact per-form matching: a second form's allowed origin must not
        // be echoed for the first form's preflight (no cross-form
        // enumeration of registered origins).
        $this->forms->createForm('Other', 'owner2@example.com', ['https://other.example']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', '/v1/form/' . $this->form['form_key'] . '/submit')
            ->withHeader('Origin', 'https://other.example');

        $response = $this->handle($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testTokenPreflightEchoesAllowedOriginWithGetMethod(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', '/v1/form/' . $this->form['form_key'] . '/token')
            ->withHeader('Origin', self::ORIGIN);

        $response = $this->handle($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('GET', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    // --- Harness ----------------------------------------------------------

    /**
     * @param array<string, mixed> $env Config env overrides for this request.
     */
    private function app(array $env = []): App
    {
        $config = Config::fromEnvironment(array_merge(
            ['APP_ENV' => 'testing', 'APP_SECRET' => self::SECRET],
            $env
        ));

        return AppFactory::create($config, $this->db, $this->dns, $this->clock);
    }

    /**
     * @param array<string, mixed> $env
     */
    private function handle(ServerRequestInterface $request, array $env = []): ResponseInterface
    {
        return $this->app($env)->handle($request);
    }

    private function freshToken(): string
    {
        return (new SubmitToken(self::SECRET, $this->clock, 3, 3600))->issue($this->form['form_key']);
    }

    /**
     * Build a POST /v1/form/{form_key}/submit request carrying the given
     * fields.
     *
     * @param array<string, mixed> $fields
     * @param array{origin?: ?string, content_type?: string, remote_ip?: string, form_key?: string} $opts
     */
    private function submitRequest(array $fields, array $opts = []): ServerRequestInterface
    {
        $origin = array_key_exists('origin', $opts) ? $opts['origin'] : self::ORIGIN;
        $contentType = $opts['content_type'] ?? 'application/x-www-form-urlencoded';
        $remoteIp = $opts['remote_ip'] ?? '203.0.113.7';
        $formKey = $opts['form_key'] ?? $this->form['form_key'];

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/v1/form/' . $formKey . '/submit', ['REMOTE_ADDR' => $remoteIp])
            ->withHeader('Content-Type', $contentType);

        if ($origin !== null) {
            $request = $request->withHeader('Origin', $origin);
        }

        $raw = $contentType === 'application/json'
            ? (string) json_encode($fields)
            : http_build_query($fields);

        $request->getBody()->write($raw);
        $request->getBody()->rewind();

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function submissionCount(): int
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) AS c FROM submissions');

        return (int) $row['c'];
    }

    /**
     * @return array<string, mixed>
     */
    private function lastSubmission(): array
    {
        $row = $this->db->fetchOne('SELECT * FROM submissions ORDER BY id DESC LIMIT 1');
        self::assertNotNull($row);

        return $row;
    }
}
