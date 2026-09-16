<?php

declare(strict_types=1);

namespace OpenSendForm\Auth;

/**
 * Time-based one-time passwords, RFC 6238.
 *
 * SHA1, 6 digits, 30-second period — the profile every authenticator app
 * defaults to. verify() checks a +/- window of periods (default 1) to
 * tolerate clock skew, comparing in constant time. No dependencies beyond
 * PHP's hash_hmac and random_bytes, so it runs on stock shared hosting.
 */
final class Totp
{
    private int $digits;
    private int $period;
    private string $algorithm;

    public function __construct(int $digits = 6, int $period = 30, string $algorithm = 'sha1')
    {
        $this->digits = $digits;
        $this->period = $period;
        $this->algorithm = $algorithm;
    }

    /**
     * Generate a fresh base32 secret from CSPRNG bytes (default 160 bits,
     * the RFC-recommended SHA1 key size).
     */
    public function generateSecret(int $bytes = 20): string
    {
        return Base32::encode(random_bytes($bytes));
    }

    /**
     * The code for a given base32 secret at a given unix timestamp.
     */
    public function codeAt(string $secretBase32, int $timestamp): string
    {
        $counter = intdiv($timestamp, $this->period);

        return $this->hotp(Base32::decode($secretBase32), $counter);
    }

    /**
     * Verify a submitted code against a secret at a timestamp, scanning a
     * +/- window of periods. Rejects malformed codes up front and uses a
     * constant-time comparison for each candidate.
     */
    public function verify(string $secretBase32, string $code, int $timestamp, int $window = 1): bool
    {
        return $this->matchedCounter($secretBase32, $code, $timestamp, $window) !== null;
    }

    /**
     * As verify(), but returns WHICH counter (timestep) the code matched, or
     * null if it matched none. Callers enforcing replay prevention persist this
     * counter and refuse any later code that does not match a strictly larger
     * one. The whole window is scanned even after a hit so timing does not leak
     * which counter matched; if more than one candidate matches (degenerate,
     * effectively impossible for distinct counters) the largest is returned so
     * the replay pointer never moves backwards.
     */
    public function matchedCounter(string $secretBase32, string $code, int $timestamp, int $window = 1): ?int
    {
        $code = trim($code);
        if (preg_match('/^\d{' . $this->digits . '}$/', $code) !== 1) {
            return null;
        }

        $key = Base32::decode($secretBase32);
        if ($key === '') {
            return null;
        }

        $baseCounter = intdiv($timestamp, $this->period);
        $matched = null;
        for ($offset = -$window; $offset <= $window; $offset++) {
            $counter = $baseCounter + $offset;
            $candidate = $this->hotp($key, $counter);
            if (hash_equals($candidate, $code)) {
                $matched = $counter;
            }
        }

        return $matched;
    }

    /**
     * Build an otpauth:// provisioning URI (for a QR code / manual import).
     */
    public function otpauthUri(string $issuer, string $accountName, string $secretBase32): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);

        $params = http_build_query([
            'secret'    => $secretBase32,
            'issuer'    => $issuer,
            'algorithm' => strtoupper($this->algorithm),
            'digits'    => $this->digits,
            'period'    => $this->period,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/' . $label . '?' . $params;
    }

    /**
     * HOTP (RFC 4226) truncation for one counter value.
     */
    private function hotp(string $key, int $counter): string
    {
        // 8-byte big-endian counter.
        $binCounter = pack('J', $counter);
        $hash = hash_hmac($this->algorithm, $binCounter, $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $truncated = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        $code = $truncated % (10 ** $this->digits);

        return str_pad((string) $code, $this->digits, '0', STR_PAD_LEFT);
    }
}
