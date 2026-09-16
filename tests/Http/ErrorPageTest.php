<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Http;

use OpenSendForm\AppFactory;
use OpenSendForm\Config;
use OpenSendForm\Http\ErrorHandler;
use OpenSendForm\Install\Paths;
use OpenSendForm\Storage\Database;
use OpenSendForm\Storage\MigrationRunner;
use OpenSendForm\Tests\Support\FakeSession;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Production error-page hardening (hardening sweep, Task 3): a 404 or a 500 in
 * production must render a plain page with NO stack trace, exception class or
 * file path — for the JSON API (frozen contract shape), admin HTML and the
 * installer. Development keeps verbose details. Also covers the /favicon.ico
 * 204 handler.
 */
final class ErrorPageTest extends TestCase
{
    /** Markers that would betray a leaked trace/class/path. */
    private const LEAK_MARKERS = ['Slim\\', 'Trace', 'Stack trace', '.php', '#0 ', 'RuntimeException', '/var/www', 'secret-boom-path'];

    // --- Unit: the handler itself ----------------------------------------

    public function testProductionJsonIsFrozenContractWithNoLeak(): void
    {
        $response = $this->handle('production', '/v1/anything', 'application/json', new RuntimeException('boom at /secret-boom-path/x.php'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $json = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($json['ok']);
        self::assertSame('server_error', $json['error']['code']);
        self::assertIsString($json['error']['message']);
        $this->assertNoLeak((string) $response->getBody());
    }

    public function testProduction404JsonUsesNotFoundCode(): void
    {
        $request = $this->request('/v1/nope', 'application/json');
        $response = $this->handle('production', '/v1/nope', 'application/json', new HttpNotFoundException($request));

        self::assertSame(404, $response->getStatusCode());
        $json = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($json['ok']);
        self::assertSame('not_found', $json['error']['code']);
    }

    public function testProductionHtmlIsStyledAndLeakFree(): void
    {
        $response = $this->handle('production', '/admin/whatever', 'text/html', new RuntimeException('boom at /secret-boom-path/x.php'));

        self::assertSame(500, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        // Styled within the design system.
        self::assertStringContainsString('/assets/tokens.css', $body);
        self::assertStringContainsString('/assets/admin.css', $body);
        self::assertStringContainsString('Something went wrong', $body);
        $this->assertNoLeak($body);
    }

    public function testDevelopmentHtmlIsVerbose(): void
    {
        $response = $this->handle('dev', '/admin/whatever', 'text/html', new RuntimeException('boom at /secret-boom-path/x.php'));

        $body = (string) $response->getBody();
        // Dev shows the real class + message + trace to aid debugging.
        self::assertStringContainsString('RuntimeException', $body);
        self::assertStringContainsString('secret-boom-path', $body);
        self::assertStringContainsString('osf-error-detail', $body);
    }

    public function testDevelopmentJsonSurfacesRealMessageButKeepsContractShape(): void
    {
        $response = $this->handle('dev', '/v1/x', 'application/json', new RuntimeException('boom detail here'));

        $json = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($json['ok']);
        self::assertSame('server_error', $json['error']['code']);
        self::assertStringContainsString('boom detail here', $json['error']['message']);
    }

    // --- Integration: through the built app -------------------------------

    public function testProduction404OnJsonApiPath(): void
    {
        $app = $this->app('production');
        $response = $app->handle($this->request('/v1/does-not-exist', 'application/json'));

        self::assertSame(404, $response->getStatusCode());
        $json = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['ok' => false, 'error' => ['code' => 'not_found', 'message' => $json['error']['message']]], $json);
        $this->assertNoLeak((string) $response->getBody());
    }

    public function testProduction404OnAdminHtmlPath(): void
    {
        $app = $this->app('production');
        $response = $app->handle($this->request('/admin/does-not-exist', 'text/html'));

        self::assertSame(404, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Page not found', $body);
        self::assertStringContainsString('/assets/admin.css', $body);
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertNoLeak($body);
    }

    public function testProduction500IsTraceFreeThroughTheApp(): void
    {
        $app = $this->app('production');
        // A route that throws, added just for this test.
        $app->get('/v1/__boom', function (): ResponseInterface {
            throw new RuntimeException('kaboom at /secret-boom-path/index.php line 1');
        });

        $response = $app->handle($this->request('/v1/__boom', 'application/json'));

        self::assertSame(500, $response->getStatusCode());
        $json = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($json['ok']);
        self::assertSame('server_error', $json['error']['code']);
        $this->assertNoLeak((string) $response->getBody());
    }

    public function testDevelopment500IsVerboseThroughTheApp(): void
    {
        $app = $this->app('dev');
        $app->get('/__boom', function (): ResponseInterface {
            throw new RuntimeException('kaboom-marker-xyz');
        });

        $response = $app->handle($this->request('/__boom', 'text/html'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('kaboom-marker-xyz', (string) $response->getBody());
    }

    public function testProductionInstallerErrorPageIsSafe(): void
    {
        // A not-installed app: the installer is reachable, and an unknown
        // /install path 404s through the ErrorHandler as a styled HTML page.
        $base = sys_get_temp_dir() . '/osf_err_' . bin2hex(random_bytes(6));
        mkdir($base . '/var/data', 0775, true);
        $paths = Paths::underBase($base, dirname(__DIR__, 2) . '/migrations');

        try {
            $config = Config::fromValues(['APP_ENV' => 'production', 'APP_SECRET' => 'installer-err-secret']);
            $db = Database::connect('sqlite:' . $base . '/var/data/opensendform.sqlite');
            $app = AppFactory::create($config, $db, null, null, null, null, new FakeSession(), $paths);

            $response = $app->handle($this->request('/install/does-not-exist', 'text/html'));

            self::assertSame(404, $response->getStatusCode());
            $body = (string) $response->getBody();
            self::assertStringContainsString('Page not found', $body);
            $this->assertNoLeak($body);
        } finally {
            $this->rrmdir($base);
        }
    }

    // --- favicon ----------------------------------------------------------

    public function testFaviconReturns204NoContent(): void
    {
        $app = $this->app('production');
        $response = $app->handle($this->request('/favicon.ico', 'image/x-icon'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    // --- Helpers ----------------------------------------------------------

    private function handle(string $env, string $path, string $accept, \Throwable $exception): ResponseInterface
    {
        $handler = new ErrorHandler(new ResponseFactory());
        $displayErrorDetails = $env !== 'production';

        return $handler(
            $this->request($path, $accept),
            $exception,
            $displayErrorDetails,
            false,
            false
        );
    }

    private function app(string $env): App
    {
        $config = Config::fromValues(['APP_ENV' => $env, 'APP_SECRET' => 'error-page-secret']);
        $db = Database::connect('sqlite::memory:');
        (new MigrationRunner($db, dirname(__DIR__, 2) . '/migrations'))->migrate();

        return AppFactory::create($config, $db, null, null, null, null, new FakeSession());
    }

    private function request(string $path, string $accept): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', $path, ['REMOTE_ADDR' => '203.0.113.5'])
            ->withHeader('Accept', $accept);
    }

    private function assertNoLeak(string $body): void
    {
        foreach (self::LEAK_MARKERS as $marker) {
            self::assertStringNotContainsString($marker, $body, "Leaked trace marker '{$marker}' in production error output");
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
