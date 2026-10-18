<?php

declare(strict_types=1);

namespace OpenSendForm\Mail;

/**
 * Inspects a sending domain's email-authentication DNS records and, for each
 * of SPF, DKIM and DMARC, reports whether it is published and — when it is not
 * — the exact record a non-technical owner should add and where to add it.
 *
 * All three live in TXT records, so the whole check runs through the injected
 * DnsResolver (a scriptable fake in tests, the system resolver in production).
 * The checker is pure: it makes lookups and shapes results, holding no state
 * and never writing anything.
 *
 * Each check result is an array with a stable shape:
 *   key         'spf' | 'dkim' | 'dmarc'
 *   label       human title
 *   type        always 'TXT'
 *   name        the record host to create (e.g. '_dmarc', 'default._domainkey')
 *   fqdn        the fully-qualified name that was looked up
 *   state       'present'/'absent' (spf, dmarc) or 'found'/'not_found' (dkim)
 *   ok          bool — true = green (published), false = amber (action needed)
 *   found       the published record string, or null
 *   recommended the copy-paste value to add (empty for DKIM: the host supplies it)
 *   explain     one plain-language sentence on what this record does
 *   where       one sentence on where to add it
 */
final class DeliverabilityChecker
{
    /** Where every record is added — the same for all three. */
    private const WHERE =
        'Add this in your domain\'s DNS manager - at your domain registrar, or in '
        . 'cPanel under "Zone Editor".';

    public function __construct(private DnsResolver $resolver)
    {
    }

    /**
     * Run all three checks for the domain of the given From address.
     *
     * @param string $fromAddress  The configured From address; its domain is checked.
     * @param string $adminEmail   Accepted for backward compatibility; no longer
     *                             used (the DMARC rua now references the From
     *                             domain's postmaster, see recommendedDmarc()).
     * @param string $dkimSelector The DKIM selector to look up (default 'default').
     * @param string $smtpHost     The configured SMTP host; seeds the SPF include hint.
     *
     * @return array{
     *     valid: bool,
     *     placeholder: bool,
     *     domain: string,
     *     selector: string,
     *     spf: array<string, mixed>,
     *     dkim: array<string, mixed>,
     *     dmarc: array<string, mixed>
     * }
     */
    public function check(
        string $fromAddress,
        string $adminEmail = '',
        string $dkimSelector = 'default',
        string $smtpHost = ''
    ): array {
        $domain = self::domainOf($fromAddress);
        $selector = self::normaliseSelector($dkimSelector);
        $placeholder = $domain !== '' && self::isPlaceholderDomain($domain);

        // A placeholder domain (localhost, example.*, a reserved test TLD, …)
        // never has real DNS and never will, so we run no lookups and offer no
        // records to add; the UI explains that checks apply to real domains.
        // 'valid' means "a real From domain we can give advice for".
        $lookupDomain = $placeholder ? '' : $domain;

        return [
            'valid'       => $domain !== '' && !$placeholder,
            'placeholder' => $placeholder,
            'domain'      => $domain,
            'selector'    => $selector,
            'spf'         => $this->checkSpf($lookupDomain, $smtpHost),
            'dkim'        => $this->checkDkim($lookupDomain, $selector),
            'dmarc'       => $this->checkDmarc($lookupDomain),
        ];
    }

    // --- Individual checks ------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function checkSpf(string $domain, string $smtpHost): array
    {
        $found = $domain === '' ? null : $this->firstMatching($domain, '/^v=spf1\b/i');

        return [
            'key'         => 'spf',
            'label'       => 'SPF',
            'type'        => 'TXT',
            'name'        => '@',
            'fqdn'        => $domain,
            'state'       => $found !== null ? 'present' : 'absent',
            'ok'          => $found !== null,
            'found'       => $found,
            'recommended' => self::recommendedSpf($smtpHost),
            'explain'     => 'SPF lists the servers allowed to send email using your domain, '
                . 'so receivers can tell a real message from a forgery.',
            'where'       => self::WHERE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDkim(string $domain, string $selector): array
    {
        $fqdn = $domain === '' ? '' : $selector . '._domainkey.' . $domain;
        // A DKIM record carries a public key: it begins v=DKIM1 or at least
        // publishes a p= key. Any TXT that does neither is treated as not a
        // usable DKIM record (so a stray/malformed entry reads as "not found").
        $found = $fqdn === '' ? null : $this->firstMatching($fqdn, '/(^v=DKIM1\b)|(\bp=)/i');

        return [
            'key'         => 'dkim',
            'label'       => 'DKIM',
            'type'        => 'TXT',
            'name'        => $selector . '._domainkey',
            'fqdn'        => $fqdn,
            'state'       => $found !== null ? 'found' : 'not_found',
            'ok'          => $found !== null,
            'found'       => $found,
            // The key value is generated by your host, not by us — so there is
            // no ready-made record to paste; the name to look for is shown.
            'recommended' => '',
            'explain'     => 'DKIM adds a digital signature to every message so receivers can '
                . 'confirm it really came from your domain and was not altered.',
            'where'       => 'Turn DKIM on in your hosting provider\'s email settings (in cPanel, '
                . 'under "Email Deliverability"); it publishes this record for you.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDmarc(string $domain): array
    {
        $fqdn = $domain === '' ? '' : '_dmarc.' . $domain;
        $found = $fqdn === '' ? null : $this->firstMatching($fqdn, '/^v=DMARC1\b/i');

        return [
            'key'         => 'dmarc',
            'label'       => 'DMARC',
            'type'        => 'TXT',
            'name'        => '_dmarc',
            'fqdn'        => $fqdn,
            'state'       => $found !== null ? 'present' : 'absent',
            'ok'          => $found !== null,
            'found'       => $found,
            'recommended' => self::recommendedDmarc($domain),
            'explain'     => 'DMARC tells receivers what to do with messages that fail SPF and '
                . 'DKIM, and can email you a report; start in monitor-only mode (p=none).',
            'where'       => self::WHERE,
        ];
    }

    // --- Lookup helpers ---------------------------------------------------

    /**
     * The first TXT record at $name matching $pattern, or null when none do
     * (including when the name has no records at all / the lookup failed).
     */
    private function firstMatching(string $name, string $pattern): ?string
    {
        foreach ($this->resolver->txtRecords($name) as $txt) {
            if (preg_match($pattern, trim($txt)) === 1) {
                return trim($txt);
            }
        }

        return null;
    }

    // --- Recommendation builders ------------------------------------------

    private static function recommendedSpf(string $smtpHost): string
    {
        $sender = self::registrableDomain($smtpHost);
        if ($sender !== '') {
            return 'v=spf1 a mx include:' . $sender . ' ~all';
        }

        return 'v=spf1 a mx ~all';
    }

    /**
     * The starter DMARC record for a real sending domain. Aggregate reports go
     * to that domain's own postmaster mailbox — an address RFC 5321 expects
     * every domain to accept — so we point at a real, owner-controlled mailbox
     * rather than fabricating one or borrowing an address at another domain.
     */
    private static function recommendedDmarc(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain !== '') {
            return 'v=DMARC1; p=none; rua=mailto:postmaster@' . $domain;
        }

        return 'v=DMARC1; p=none';
    }

    /**
     * Whether a domain is a placeholder/reserved name that has no public DNS and
     * never will — so deliverability advice (records to publish) makes no sense
     * for it. Covers localhost and anything under it, the fake "localhost.com"
     * family, the RFC 2606 example.* domains, and the reserved test/dev TLDs
     * (RFC 6761 / RFC 2606: .test, .example, .invalid, .local, .localhost).
     */
    public static function isPlaceholderDomain(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return false;
        }

        if ($domain === 'localhost' || $domain === 'localhost.com') {
            return true;
        }

        if (str_starts_with($domain, 'example.')) {
            return true;
        }

        foreach (['.localhost', '.localhost.com', '.test', '.example', '.invalid', '.local'] as $suffix) {
            if (str_ends_with($domain, $suffix)) {
                return true;
            }
        }

        return false;
    }

    // --- Small string helpers ---------------------------------------------

    /**
     * The domain part of an email address, lower-cased, or '' when the address
     * is not usable.
     */
    public static function domainOf(string $email): string
    {
        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return '';
        }

        $at = strrpos($email, '@');

        return $at === false ? '' : strtolower(substr($email, $at + 1));
    }

    /**
     * A best-effort registrable domain for an SMTP host, used only to seed the
     * SPF include hint. Takes the final two labels (mail.hostgator.com →
     * hostgator.com); returns '' for an empty host, 'localhost', an IP, or a
     * single-label host where an include makes no sense.
     */
    private static function registrableDomain(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return '';
        }

        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return '';
        }

        return implode('.', array_slice($labels, -2));
    }

    private static function normaliseSelector(string $selector): string
    {
        $selector = strtolower(trim($selector));
        // Keep it to the characters a DNS label allows; fall back to 'default'.
        if ($selector === '' || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $selector) !== 1) {
            return 'default';
        }

        return $selector;
    }
}
