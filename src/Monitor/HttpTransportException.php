<?php

declare(strict_types=1);

namespace OpenSendForm\Monitor;

use RuntimeException;

/**
 * Thrown by an HttpClient when a request could not complete at the transport
 * level at all — connection refused, DNS failure, timeout — i.e. there is no
 * HTTP response to inspect.
 *
 * This is distinct from an HTTP response carrying an error status (a 4xx/5xx
 * body): that still resolves to an HttpResponse. A transport failure means the
 * monitor never reached the endpoint, which the monitor treats as a failed
 * check (the site is unreachable — exactly what the operator must hear about).
 */
final class HttpTransportException extends RuntimeException
{
}
