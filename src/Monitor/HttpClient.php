<?php

declare(strict_types=1);

namespace OpenSendForm\Monitor;

/**
 * The monitor's single network seam.
 *
 * A synthetic check is a REAL submission through the public HTTP endpoints, so
 * the monitor speaks plain HTTP: a GET for the form token and a POST for the
 * submission. Binding both behind this interface lets the test suite drive the
 * whole monitor against an in-process app with no live server or open socket.
 *
 * Implementations MUST throw HttpTransportException when a request cannot
 * complete at the transport level (connection refused, DNS failure, timeout).
 * A completed request with any HTTP status — including 4xx/5xx — MUST return an
 * HttpResponse instead.
 */
interface HttpClient
{
    /**
     * Perform a GET.
     *
     * @param array<string, string> $headers Header name => value.
     *
     * @throws HttpTransportException on a transport-level failure.
     */
    public function get(string $url, array $headers = []): HttpResponse;

    /**
     * Perform a POST with a raw request body.
     *
     * @param array<string, string> $headers Header name => value.
     *
     * @throws HttpTransportException on a transport-level failure.
     */
    public function post(string $url, string $body, array $headers = []): HttpResponse;
}
