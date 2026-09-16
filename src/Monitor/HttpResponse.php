<?php

declare(strict_types=1);

namespace OpenSendForm\Monitor;

/**
 * An HTTP response the monitor received: status code plus raw body.
 *
 * Deliberately tiny — the monitor only needs the status (to tell an ok
 * submission from an honest error) and the body (to parse the token JSON).
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body
    ) {
    }

    /**
     * A 2xx response.
     */
    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Decode the JSON body to an array, or null when it is not a JSON object.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
