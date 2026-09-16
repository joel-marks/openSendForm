<?php

declare(strict_types=1);

namespace OpenSendForm\Tests\Http;

use OpenSendForm\Http\ClientIpResolver;
use PHPUnit\Framework\TestCase;

/**
 * The trusted-proxy client-IP ruling (hardening sweep, Task 8): by default the
 * client IP is REMOTE_ADDR and X-Forwarded-For is ignored; only a request whose
 * direct peer is a configured trusted proxy derives the client from the
 * rightmost forwarded entry that is not itself trusted.
 */
final class ClientIpResolverTest extends TestCase
{
    // --- Default: XFF ignored --------------------------------------------

    public function testDefaultIgnoresForwardedForEntirely(): void
    {
        $resolver = ClientIpResolver::none();

        self::assertSame(
            '203.0.113.5',
            $resolver->resolve('203.0.113.5', '198.51.100.9, 10.0.0.1')
        );
    }

    public function testUntrustedPeerIgnoresForwardedForEvenWhenAListExists(): void
    {
        // The peer is not in the trusted list, so a spoofed header is ignored.
        $resolver = new ClientIpResolver('192.0.2.1');

        self::assertSame(
            '203.0.113.5',
            $resolver->resolve('203.0.113.5', '9.9.9.9')
        );
    }

    // --- Opted-in derivation ---------------------------------------------

    public function testTrustedPeerDerivesClientFromForwardedFor(): void
    {
        $resolver = new ClientIpResolver('192.0.2.1');

        self::assertSame(
            '203.0.113.77',
            $resolver->resolve('192.0.2.1', '203.0.113.77')
        );
    }

    public function testTrustedChainReturnsRightmostUntrustedEntry(): void
    {
        // client -> proxyA(203.0.113 is the client) ... the trusted proxies are
        // the two 10.0.0.x hops; the rightmost non-trusted entry is the client.
        $resolver = new ClientIpResolver('10.0.0.0/8, 192.0.2.1');

        self::assertSame(
            '203.0.113.77',
            $resolver->resolve('192.0.2.1', '203.0.113.77, 10.0.0.2, 10.0.0.3')
        );
    }

    public function testSpoofedLeadingEntryNeverWins(): void
    {
        // An attacker at 203.0.113.77 prepends a fake "1.1.1.1"; the trusted
        // proxy still appends the attacker's real address, which is the
        // rightmost untrusted entry — so the spoof is discarded, not trusted.
        $resolver = new ClientIpResolver('192.0.2.1');

        self::assertSame(
            '203.0.113.77',
            $resolver->resolve('192.0.2.1', '1.1.1.1, 203.0.113.77')
        );
    }

    public function testFallsBackToPeerWhenEveryForwardedHopIsTrusted(): void
    {
        $resolver = new ClientIpResolver('10.0.0.0/8');

        self::assertSame(
            '10.0.0.9',
            $resolver->resolve('10.0.0.9', '10.0.0.1, 10.0.0.2')
        );
    }

    public function testFallsBackToPeerWhenForwardedForAbsent(): void
    {
        $resolver = new ClientIpResolver('192.0.2.1');

        self::assertSame('192.0.2.1', $resolver->resolve('192.0.2.1', null));
        self::assertSame('192.0.2.1', $resolver->resolve('192.0.2.1', ''));
    }

    // --- Parsing robustness ----------------------------------------------

    public function testStripsIpv4PortAndIpv6Brackets(): void
    {
        $resolver = new ClientIpResolver('192.0.2.1');

        self::assertSame(
            '203.0.113.77',
            $resolver->resolve('192.0.2.1', '203.0.113.77:54321')
        );
        self::assertSame(
            '2001:db8::42',
            $resolver->resolve('192.0.2.1', '[2001:db8::42]:443')
        );
    }

    public function testSkipsMalformedForwardedEntries(): void
    {
        $resolver = new ClientIpResolver('192.0.2.1');

        // "not-an-ip" is skipped; the next valid untrusted entry wins.
        self::assertSame(
            '203.0.113.77',
            $resolver->resolve('192.0.2.1', '203.0.113.77, not-an-ip')
        );
    }

    // --- IPv6 trust ------------------------------------------------------

    public function testIpv6CidrTrust(): void
    {
        $resolver = new ClientIpResolver('2001:db8::/32');

        self::assertSame(
            '203.0.113.77',
            $resolver->resolve('2001:db8::1', '203.0.113.77')
        );
    }

    public function testExactIpv6PeerTrust(): void
    {
        // Compressed vs expanded forms of the same address still match.
        $resolver = new ClientIpResolver('2001:db8:0:0:0:0:0:1');

        self::assertSame(
            '203.0.113.77',
            $resolver->resolve('2001:db8::1', '203.0.113.77')
        );
    }
}
