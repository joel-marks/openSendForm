<?php

declare(strict_types=1);

namespace OpenSendForm\Monitor;

/**
 * The production HttpClient, backed by PHP's curl extension — already a
 * dependency of Turnstile verification, so this adds no new requirement.
 *
 * Every request is bounded by a total timeout so a hung endpoint can never
 * wedge the cron job. A curl-level failure (no response at all) becomes an
 * HttpTransportException; any completed response, whatever its status, becomes
 * an HttpResponse for the caller to judge.
 */
final class CurlHttpClient implements HttpClient
{
    public function __construct(private int $timeoutSeconds = 15)
    {
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->send('GET', $url, null, $headers);
    }

    public function post(string $url, string $body, array $headers = []): HttpResponse
    {
        return $this->send('POST', $url, $body, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(string $method, string $url, ?string $body, array $headers): HttpResponse
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new HttpTransportException('Could not initialise curl.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt($handle, CURLOPT_URL, $url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, $this->timeoutSeconds);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headerLines);
        // Follow no redirects: the endpoints answer directly, and a redirect
        // would mask a misconfiguration we would rather see as a failed check.
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);

        if ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body ?? '');
        }

        $response = curl_exec($handle);
        if ($response === false) {
            $error = curl_error($handle);
            curl_close($handle);

            throw new HttpTransportException(
                'HTTP ' . $method . ' to ' . $url . ' failed: ' . ($error !== '' ? $error : 'unknown transport error')
            );
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new HttpResponse($status, is_string($response) ? $response : '');
    }
}
