<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Support;

use OpenSendForm\Monitor\HttpClient;
use OpenSendForm\Monitor\HttpResponse;
use OpenSendForm\Monitor\HttpTransportException;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * A monitor HttpClient that dispatches straight into an in-process Slim app
 * instead of opening a socket. This lets the monitor tests exercise the FULL
 * public pipeline (token endpoint, submit endpoint, store, delivery) end to end
 * with no live server — the synthetic submission is genuinely handled by the
 * same routes and stages a real request would hit.
 *
 * Set $failTransport to simulate an unreachable endpoint (connection refused).
 */
final class InProcessHttpClient implements HttpClient
{
    public bool $failTransport = false;

    /** Every dispatched request as [method, path]. */
    public array $requests = [];

    public function __construct(private App $app, private string $baseUrl)
    {
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->dispatch('GET', $url, null, $headers);
    }

    public function post(string $url, string $body, array $headers = []): HttpResponse
    {
        return $this->dispatch('POST', $url, $body, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    private function dispatch(string $method, string $url, ?string $body, array $headers): HttpResponse
    {
        if ($this->failTransport) {
            throw new HttpTransportException('simulated transport failure for ' . $url);
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $this->requests[] = [$method, $path];

        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => '127.0.0.1']);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request->getBody()->write($body);
            $request->getBody()->rewind();
        }

        $response = $this->app->handle($request);

        return new HttpResponse($response->getStatusCode(), (string) $response->getBody());
    }
}
