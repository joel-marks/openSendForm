<?php

declare(strict_types=1);

namespace OpenSendForm\Http;

/**
 * Resolves the effective client IP under an explicit trusted-proxy policy.
 *
 * The ruling (hardening sweep, Task 8): by default the client IP is
 * REMOTE_ADDR, full stop — X-Forwarded-For and friends are IGNORED, so a
 * client cannot spoof its address by sending a header. Opting in is explicit:
 * the TRUSTED_PROXIES config lists the proxy addresses (plain IPs or CIDR
 * ranges) that are allowed to speak for their upstream. Only when REMOTE_ADDR
 * is itself in that list is X-Forwarded-For consulted, and then the client is
 * the RIGHTMOST forwarded entry that is NOT a trusted proxy — i.e. the closest
 * address the trusted chain actually received the request from. A spoofed
 * header injected by an untrusted client therefore never wins: either
 * REMOTE_ADDR is untrusted (the header is ignored outright) or the attacker's
 * own untrusted address is exactly the rightmost non-trusted entry returned.
 *
 * The resolved value feeds the rate limiter and the stored/logged remote_ip.
 */
final class ClientIpResolver
{
    /** @var array<int, string> Trusted-proxy specs: plain IPs or CIDR ranges. */
    private array $trusted;

    public function __construct(string $trustedProxiesCsv)
    {
        $this->trusted = array_values(array_filter(
            array_map('trim', explode(',', $trustedProxiesCsv)),
            static fn (string $s): bool => $s !== ''
        ));
    }

    /**
     * A resolver that trusts no proxy — always returns REMOTE_ADDR. The default
     * used when no config is wired (e.g. a SubmitContext built without one).
     */
    public static function none(): self
    {
        return new self('');
    }

    /**
     * Resolve the client IP from the direct peer (REMOTE_ADDR) and the raw
     * X-Forwarded-For header value (comma-separated, may be null/empty).
     */
    public function resolve(string $remoteAddr, ?string $forwardedFor): string
    {
        // Default and untrusted-peer path: the header is ignored entirely.
        if ($this->trusted === [] || !$this->isTrusted($remoteAddr)) {
            return $remoteAddr;
        }

        if ($forwardedFor === null || trim($forwardedFor) === '') {
            return $remoteAddr;
        }

        // Walk right-to-left: the first entry that is a valid IP and is not
        // itself a trusted proxy is the real client as the trusted chain saw it.
        $parts = explode(',', $forwardedFor);
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $candidate = self::normalise($parts[$i]);
            if ($candidate === '') {
                continue;
            }
            if (!$this->isTrusted($candidate)) {
                return $candidate;
            }
        }

        // Every forwarded hop was itself trusted (or none was a valid address):
        // fall back to the direct peer rather than trust nothing.
        return $remoteAddr;
    }

    /**
     * Whether an address falls within any trusted-proxy spec (exact IP or CIDR).
     */
    private function isTrusted(string $ip): bool
    {
        foreach ($this->trusted as $spec) {
            if (str_contains($spec, '/')) {
                if (self::inCidr($ip, $spec)) {
                    return true;
                }
            } elseif (self::sameIp($ip, $spec)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Trim a forwarded entry to a bare, valid IP, stripping IPv6 brackets and a
     * trailing IPv4 :port. Returns '' when the entry is not a valid IP.
     */
    private static function normalise(string $entry): string
    {
        $entry = trim($entry);
        if ($entry === '') {
            return '';
        }

        // Bracketed IPv6, optionally with a port: [::1]:443 or [::1].
        if ($entry[0] === '[') {
            $close = strpos($entry, ']');
            if ($close !== false) {
                $entry = substr($entry, 1, $close - 1);
            }
        } elseif (substr_count($entry, ':') === 1) {
            // A single colon means IPv4:port (an IPv6 address has several).
            $entry = substr($entry, 0, strpos($entry, ':'));
        }

        return filter_var($entry, FILTER_VALIDATE_IP) === false ? '' : $entry;
    }

    private static function sameIp(string $a, string $b): bool
    {
        $pa = @inet_pton($a);
        $pb = @inet_pton($b);

        return $pa !== false && $pb !== false && $pa === $pb;
    }

    /**
     * Whether $ip is inside the CIDR range $cidr (e.g. "10.0.0.0/8",
     * "2001:db8::/32"). Both must be the same address family.
     */
    private static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bitsRaw] = explode('/', $cidr, 2);

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        if (!ctype_digit($bitsRaw)) {
            return false;
        }
        $bits = (int) $bitsRaw;
        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if ($fullBytes > 0 && strncmp($ipBin, $subnetBin, $fullBytes) !== 0) {
            return false;
        }

        $remainingBits = $bits % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }
}
