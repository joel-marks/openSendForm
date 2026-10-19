<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Mail;

use OpenSendForm\Mail\DeliverabilityChecker;
use OpenSendForm\Tests\Support\FakeDnsResolver;
use PHPUnit\Framework\TestCase;

/**
 * The SPF/DKIM/DMARC checker, driven entirely through the scriptable
 * FakeDnsResolver so no lookup ever touches the network. Covers present /
 * absent / malformed states, the exact recommended-record text, and the
 * placeholder-domain short-circuit (localhost, example.*, reserved TLDs).
 *
 * A real, non-reserved sending domain (acme.co) is used throughout so the
 * checker actually produces advice; the placeholder cases use reserved names.
 */
final class DeliverabilityCheckerTest extends TestCase
{
    private FakeDnsResolver $dns;

    protected function setUp(): void
    {
        $this->dns = new FakeDnsResolver();
    }

    private function checker(): DeliverabilityChecker
    {
        return new DeliverabilityChecker($this->dns);
    }

    // --- SPF --------------------------------------------------------------

    public function testSpfPresentIsGreenAndReturnsTheRecord(): void
    {
        $this->dns->setTxt('acme.co', ['v=spf1 include:_spf.host.com ~all']);

        $spf = $this->checker()->check('hello@acme.co', 'admin@acme.co')['spf'];

        self::assertTrue($spf['ok']);
        self::assertSame('present', $spf['state']);
        self::assertSame('v=spf1 include:_spf.host.com ~all', $spf['found']);
    }

    public function testSpfAbsentIsAmberWithRecommendedRecord(): void
    {
        // No TXT at the domain at all.
        $spf = $this->checker()->check('hello@acme.co', 'admin@acme.co', 'default', 'mail.hostgator.com')['spf'];

        self::assertFalse($spf['ok']);
        self::assertSame('absent', $spf['state']);
        self::assertNull($spf['found']);
        // The SMTP host's registrable domain seeds the include hint.
        self::assertSame('v=spf1 a mx include:hostgator.com ~all', $spf['recommended']);
    }

    public function testSpfMalformedRecordCountsAsAbsent(): void
    {
        // A TXT exists but is not an SPF record (no v=spf1 prefix).
        $this->dns->setTxt('acme.co', ['google-site-verification=abc123']);

        $spf = $this->checker()->check('hello@acme.co', 'admin@acme.co')['spf'];

        self::assertFalse($spf['ok']);
        self::assertSame('absent', $spf['state']);
    }

    public function testSpfRecommendationWithoutUsableSmtpHostOmitsInclude(): void
    {
        $spf = $this->checker()->check('hello@acme.co', 'admin@acme.co', 'default', 'localhost')['spf'];
        self::assertSame('v=spf1 a mx ~all', $spf['recommended']);

        $spfNoHost = $this->checker()->check('hello@acme.co', 'admin@acme.co', 'default', '')['spf'];
        self::assertSame('v=spf1 a mx ~all', $spfNoHost['recommended']);
    }

    // --- DKIM -------------------------------------------------------------

    public function testDkimFoundAtSelector(): void
    {
        $this->dns->setTxt('default._domainkey.acme.co', ['v=DKIM1; k=rsa; p=MIGfMA0BiQ']);

        $dkim = $this->checker()->check('hello@acme.co', 'admin@acme.co', 'default')['dkim'];

        self::assertTrue($dkim['ok']);
        self::assertSame('found', $dkim['state']);
        self::assertSame('default._domainkey', $dkim['name']);
    }

    public function testDkimNotFoundForMissingSelector(): void
    {
        $this->dns->setTxt('default._domainkey.acme.co', ['v=DKIM1; k=rsa; p=key']);

        // A different selector has no record.
        $dkim = $this->checker()->check('hello@acme.co', 'admin@acme.co', 's1')['dkim'];

        self::assertFalse($dkim['ok']);
        self::assertSame('not_found', $dkim['state']);
        self::assertSame('s1._domainkey', $dkim['name']);
    }

    public function testDkimSelectorIsNormalisedAndDefaulted(): void
    {
        $report = $this->checker()->check('hello@acme.co', 'admin@acme.co', 'BAD SELECTOR!');
        // Invalid selectors fall back to 'default'.
        self::assertSame('default', $report['selector']);
    }

    // --- DMARC ------------------------------------------------------------

    public function testDmarcPresentIsGreen(): void
    {
        $this->dns->setTxt('_dmarc.acme.co', ['v=DMARC1; p=reject; rua=mailto:dmarc@acme.co']);

        $dmarc = $this->checker()->check('hello@acme.co', 'admin@acme.co')['dmarc'];

        self::assertTrue($dmarc['ok']);
        self::assertSame('present', $dmarc['state']);
    }

    public function testDmarcAbsentRecommendsStarterWithPostmasterRua(): void
    {
        $dmarc = $this->checker()->check('hello@acme.co', 'boss@acme.co')['dmarc'];

        self::assertFalse($dmarc['ok']);
        self::assertSame('_dmarc', $dmarc['name']);
        // The rua points at the From domain's own postmaster — a real,
        // owner-controlled mailbox — not a fabricated or borrowed address.
        self::assertSame('v=DMARC1; p=none; rua=mailto:postmaster@acme.co', $dmarc['recommended']);
    }

    public function testDmarcRuaUsesFromDomainNotTheAdminEmail(): void
    {
        // The admin email is at a different domain (and here even invalid): it
        // must not leak into the rua, which always references the From domain.
        $dmarc = $this->checker()->check('hello@acme.co', 'not-an-email')['dmarc'];
        self::assertSame('v=DMARC1; p=none; rua=mailto:postmaster@acme.co', $dmarc['recommended']);
    }

    public function testDmarcFallsBackToOrganizationalDomainWhenSubdomainHasNone(): void
    {
        // No record on the exact subdomain, but the organizational domain has
        // one - exactly the Outlook-vs-our-checker case (app.opensendform.com).
        $this->dns->setTxt('_dmarc.opensendform.com', ['v=DMARC1; p=none; rua=mailto:dmarc@opensendform.com']);

        $dmarc = $this->checker()->check('noreply@app.opensendform.com')['dmarc'];

        self::assertTrue($dmarc['ok']);
        self::assertSame('present', $dmarc['state']);
        // The readout labels the name the record was actually found on.
        self::assertSame('_dmarc.opensendform.com', $dmarc['fqdn']);
        self::assertStringContainsString('organizational domain', (string) $dmarc['note']);
        self::assertStringContainsString('_dmarc.app.opensendform.com', (string) $dmarc['note']);
    }

    public function testDmarcFallsBackUnderTwoPartSuffix(): void
    {
        // A ccTLD with a known second level (co.uk): the org domain is three
        // labels, not two.
        $this->dns->setTxt('_dmarc.example.co.uk', ['v=DMARC1; p=quarantine']);

        $dmarc = $this->checker()->check('hello@mail.example.co.uk')['dmarc'];

        self::assertTrue($dmarc['ok']);
        self::assertSame('_dmarc.example.co.uk', $dmarc['fqdn']);
    }

    public function testDmarcAbsentOnBothSubdomainAndOrgIsNotFound(): void
    {
        // Neither the subdomain nor its organizational domain publishes DMARC.
        $dmarc = $this->checker()->check('noreply@app.opensendform.com')['dmarc'];

        self::assertFalse($dmarc['ok']);
        self::assertSame('absent', $dmarc['state']);
        // With nothing found, the readout points back at the From (sub)domain.
        self::assertSame('_dmarc.app.opensendform.com', $dmarc['fqdn']);
        self::assertSame('', (string) $dmarc['note']);
    }

    public function testDmarcOnExactSubdomainDoesNotNoteFallback(): void
    {
        // A record on the exact name is found directly: no fallback, no note.
        $this->dns->setTxt('_dmarc.app.opensendform.com', ['v=DMARC1; p=none']);

        $dmarc = $this->checker()->check('noreply@app.opensendform.com')['dmarc'];

        self::assertTrue($dmarc['ok']);
        self::assertSame('_dmarc.app.opensendform.com', $dmarc['fqdn']);
        self::assertSame('', (string) $dmarc['note']);
    }

    /**
     * @dataProvider organizationalDomains
     */
    public function testOrganizationalDomainHeuristic(string $domain, string $expected): void
    {
        self::assertSame($expected, DeliverabilityChecker::organizationalDomain($domain));
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function organizationalDomains(): array
    {
        return [
            'already org'        => ['opensendform.com', 'opensendform.com'],
            'one subdomain'      => ['app.opensendform.com', 'opensendform.com'],
            'deep subdomain'     => ['a.b.c.opensendform.com', 'opensendform.com'],
            'co.uk subdomain'    => ['mail.example.co.uk', 'example.co.uk'],
            'bare co.uk'         => ['example.co.uk', 'example.co.uk'],
            'com.au subdomain'   => ['mail.acme.com.au', 'acme.com.au'],
            'single label'       => ['localhost', 'localhost'],
            'empty'              => ['', ''],
        ];
    }

    // --- Placeholder / invalid domains ------------------------------------

    public function testPlaceholderDomainFlagsPlaceholderAndOffersNoLookup(): void
    {
        // example.com is an RFC 2606 placeholder: real-looking email, no real DNS.
        $this->dns->setTxt('example.com', ['v=spf1 ~all']); // would be a false positive if looked up
        $report = $this->checker()->check('hello@example.com', 'admin@acme.co');

        self::assertTrue($report['placeholder']);
        self::assertFalse($report['valid']);
        self::assertSame('example.com', $report['domain']);
        // No lookup is performed, so nothing reads as published despite the TXT above.
        self::assertFalse($report['spf']['ok']);
        self::assertFalse($report['dkim']['ok']);
        self::assertFalse($report['dmarc']['ok']);
    }

    /**
     * @dataProvider placeholderDomains
     */
    public function testIsPlaceholderDomainClassifiesReservedNames(string $domain, bool $expected): void
    {
        self::assertSame($expected, DeliverabilityChecker::isPlaceholderDomain($domain));
    }

    /**
     * @return array<string, array{0:string, 1:bool}>
     */
    public static function placeholderDomains(): array
    {
        return [
            'bare localhost'        => ['localhost', true],
            'sub of localhost'      => ['mail.localhost', true],
            'localhost.com'         => ['localhost.com', true],
            'sub of localhost.com'  => ['app.localhost.com', true],
            'example.com'           => ['example.com', true],
            'example.org'           => ['example.org', true],
            'example.anything'      => ['example.io', true],
            'reserved test TLD'     => ['acme.test', true],
            'reserved invalid TLD'  => ['acme.invalid', true],
            'reserved local TLD'    => ['printer.local', true],
            'real .co'              => ['acme.co', false],
            'real multi-label'      => ['sub.example.co.uk', false],
            'empty'                 => ['', false],
        ];
    }

    // --- Domain handling --------------------------------------------------

    public function testInvalidFromAddressMakesReportInvalidAndSkipsLookups(): void
    {
        $report = $this->checker()->check('not-an-email', 'admin@acme.co');

        self::assertFalse($report['valid']);
        self::assertFalse($report['placeholder']);
        self::assertSame('', $report['domain']);
        // Absent everywhere, but no crash and no record text needing a domain.
        self::assertFalse($report['spf']['ok']);
        self::assertFalse($report['dkim']['ok']);
        self::assertFalse($report['dmarc']['ok']);
    }

    public function testDomainOfExtractsAndLowercases(): void
    {
        self::assertSame('acme.co', DeliverabilityChecker::domainOf('Hello@Acme.CO'));
        self::assertSame('', DeliverabilityChecker::domainOf('garbage'));
        self::assertSame('', DeliverabilityChecker::domainOf(''));
    }

    public function testDomainUsedForLookupsIsTheFromDomain(): void
    {
        $this->dns->setTxt('sub.example.co.uk', ['v=spf1 ~all']);

        $spf = $this->checker()->check('hi@sub.example.co.uk', 'admin@x.com')['spf'];
        self::assertTrue($spf['ok']);
    }
}
