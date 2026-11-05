<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Redirects\Storage\IpResolver;

final class IpResolverTest extends TestCase
{
    private const TRUSTED = ['trustedProxies' => ['10.0.0.1'], 'ipHeaders' => ['X-Forwarded-For']];

    public function testUntrustedRemoteAddrIgnoresForwardedFor(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1'];
        self::assertSame('203.0.113.9', IpResolver::resolve($server, self::TRUSTED));
    }

    public function testNoTrustedProxiesMeansHeadersAreNeverRead(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1'];
        self::assertSame('10.0.0.1', IpResolver::resolve($server, ['ipHeaders' => ['X-Forwarded-For']]));
    }

    public function testTrustedProxyTakesLastNonEmptyItem(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7, , '];
        self::assertSame('198.51.100.7', IpResolver::resolve($server, self::TRUSTED));
    }

    public function testTrustedProxyWithInvalidHeaderFallsBackToRemoteAddr(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'not-an-ip'];
        self::assertSame('10.0.0.1', IpResolver::resolve($server, self::TRUSTED));
    }

    public function testLastItemInvalidIsNotRescuedByAnEarlierOne(): void
    {
        // Only the item the trusted proxy appended counts; earlier ones are client-supplied.
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, garbage'];
        self::assertSame('10.0.0.1', IpResolver::resolve($server, self::TRUSTED));
    }

    public function testInvalidRemoteAddrResolvesToNull(): void
    {
        self::assertNull(IpResolver::resolve(['REMOTE_ADDR' => '999.1.1.1'], []));
        self::assertNull(IpResolver::resolve([], []));
        self::assertNull(IpResolver::resolve(['REMOTE_ADDR' => ['1.2.3.4']], []));
    }

    public function testConfiguredOrderDecidesPrecedence(): void
    {
        $server = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.2',
        ];
        $cfFirst = ['trustedProxies' => ['10.0.0.1'], 'ipHeaders' => ['CF-Connecting-IP', 'X-Forwarded-For']];
        $xffFirst = ['trustedProxies' => ['10.0.0.1'], 'ipHeaders' => ['X-Forwarded-For', 'CF-Connecting-IP']];
        self::assertSame('198.51.100.2', IpResolver::resolve($server, $cfFirst));
        self::assertSame('198.51.100.1', IpResolver::resolve($server, $xffFirst));
    }

    public function testFirstHeaderInvalidMovesToTheNext(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CF_CONNECTING_IP' => 'x', 'HTTP_X_REAL_IP' => '198.51.100.3'];
        $settings = ['trustedProxies' => ['10.0.0.1'], 'ipHeaders' => ['CF-Connecting-IP', 'X-Real-IP']];
        self::assertSame('198.51.100.3', IpResolver::resolve($server, $settings));
    }

    public function testHeaderOutsideTheAllowListIsNeverRead(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_EVIL' => '198.51.100.3', 'HTTP_REMOTE_ADDR' => '198.51.100.4'];
        $settings = ['trustedProxies' => ['10.0.0.1'], 'ipHeaders' => ['X-Evil', 'REMOTE_ADDR', 'Remote-Addr']];
        self::assertSame('10.0.0.1', IpResolver::resolve($server, $settings));
    }

    public function testHeaderNamesAreCaseInsensitive(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_TRUE_CLIENT_IP' => '198.51.100.4'];
        $settings = ['trustedProxies' => ['10.0.0.1'], 'ipHeaders' => ['true-client-ip']];
        self::assertSame('198.51.100.4', IpResolver::resolve($server, $settings));
    }

    public function testClientIpHeader(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_CLIENT_IP' => '198.51.100.9'];
        $settings = ['trustedProxies' => ['10.0.0.1'], 'ipHeaders' => ['Client-IP']];
        self::assertSame('198.51.100.9', IpResolver::resolve($server, $settings));
    }

    public function testTrustedProxyMayBeACidrRange(): void
    {
        $server = ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '198.51.100.5'];
        $settings = ['trustedProxies' => ['10.0.0.0/8'], 'ipHeaders' => ['X-Forwarded-For']];
        self::assertSame('198.51.100.5', IpResolver::resolve($server, $settings));
    }

    public function testPortsAndBracketsAreStrippedFromHeaderItems(): void
    {
        $settings = ['trustedProxies' => ['10.0.0.1'], 'ipHeaders' => ['X-Forwarded-For']];
        self::assertSame('198.51.100.6', IpResolver::resolve(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.6:8080'], $settings));
        self::assertSame('2001:db8::1', IpResolver::resolve(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '[2001:db8::1]:443'], $settings));
        self::assertSame('2001:db8::2', IpResolver::resolve(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '2001:db8::2'], $settings));
    }

    public function testIpv6RemoteAddrAsTrustedProxy(): void
    {
        $server = ['REMOTE_ADDR' => '::1', 'HTTP_X_REAL_IP' => '198.51.100.8'];
        $settings = ['trustedProxies' => ['::1'], 'ipHeaders' => ['X-Real-IP']];
        self::assertSame('198.51.100.8', IpResolver::resolve($server, $settings));
    }

    public function testMaskIpv4ZeroesTheLastOctet(): void
    {
        self::assertSame('203.0.113.0', IpResolver::mask('203.0.113.77'));
    }

    public function testMaskIpv6ZeroesTheLower64Bits(): void
    {
        self::assertSame('2001:db8:85a3:8d3::', IpResolver::mask('2001:0db8:85a3:08d3:1319:8a2e:0370:7344'));
        self::assertSame('::', IpResolver::mask('::1'));
    }

    public function testMaskInvalidIsEmpty(): void
    {
        self::assertSame('', IpResolver::mask('nope'));
    }

    public function testForStorageHonoursThePolicy(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.77'];
        self::assertNull(IpResolver::forStorage($server, []));
        self::assertNull(IpResolver::forStorage($server, ['ip' => 'none']));
        self::assertSame('203.0.113.0', IpResolver::forStorage($server, ['ip' => 'masked']));
        self::assertSame('203.0.113.77', IpResolver::forStorage($server, ['ip' => 'full']));
        self::assertNull(IpResolver::forStorage($server, ['ip' => 'bogus']));
        self::assertNull(IpResolver::forStorage([], ['ip' => 'full']));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function cidrCases(): iterable
    {
        yield 'v4 /0 matches anything v4' => ['8.8.8.8', '0.0.0.0/0', true];
        yield 'v4 /32 exact' => ['192.0.2.1', '192.0.2.1/32', true];
        yield 'v4 /32 neighbour' => ['192.0.2.2', '192.0.2.1/32', false];
        yield 'v4 /24 inside' => ['192.0.2.255', '192.0.2.0/24', true];
        yield 'v4 /24 outside' => ['192.0.3.0', '192.0.2.0/24', false];
        yield 'v4 /25 boundary' => ['192.0.2.128', '192.0.2.0/25', false];
        yield 'v4 /31 pair' => ['192.0.2.1', '192.0.2.0/31', true];
        yield 'v4 bare address' => ['192.0.2.1', '192.0.2.1', true];
        yield 'v4 bare other' => ['192.0.2.9', '192.0.2.1', false];
        yield 'v6 /0' => ['2001:db8::1', '::/0', true];
        yield 'v6 /128 exact' => ['2001:db8::1', '2001:db8::1/128', true];
        yield 'v6 /128 neighbour' => ['2001:db8::2', '2001:db8::1/128', false];
        yield 'v6 /64 inside' => ['2001:db8:0:0:ffff::1', '2001:db8::/64', true];
        yield 'v6 /64 outside' => ['2001:db8:0:1::1', '2001:db8::/64', false];
        yield 'v6 /65 boundary' => ['2001:db8::8000:0:0:1', '2001:db8::/65', false];
        yield 'v6 /33 boundary bit' => ['2001:db8:8000::1', '2001:db8::/33', false];
        yield 'v6 bare compressed vs expanded' => ['2001:0db8:0000:0000:0000:0000:0000:0001', '2001:db8::1', true];
        yield 'family mismatch v4 in v6' => ['192.0.2.1', '::/0', false];
        yield 'family mismatch v6 in v4' => ['::1', '0.0.0.0/0', false];
        yield 'invalid ip' => ['nope', '0.0.0.0/0', false];
        yield 'invalid range' => ['192.0.2.1', 'nope/8', false];
        yield 'v4 prefix too long' => ['192.0.2.1', '192.0.2.1/33', false];
        yield 'v6 prefix too long' => ['::1', '::1/129', false];
        yield 'negative prefix' => ['192.0.2.1', '192.0.2.1/-1', false];
        yield 'non-numeric prefix' => ['192.0.2.1', '192.0.2.1/abc', false];
        yield 'empty prefix' => ['192.0.2.1', '192.0.2.1/', false];
        yield 'two slashes' => ['192.0.2.1', '192.0.2.1/24/8', false];
        yield 'whitespace around' => [' 192.0.2.1 ', ' 192.0.2.0/24 ', true];
        yield 'v4-mapped address in a v4 range' => ['::ffff:192.0.2.5', '192.0.2.0/24', true];
        yield 'v4-mapped address outside a v4 range' => ['::ffff:192.0.3.5', '192.0.2.0/24', false];
        yield 'v4 address in a v4-mapped range' => ['192.0.2.5', '::ffff:192.0.2.0/120', true];
        yield 'v4 address outside a v4-mapped range' => ['192.0.3.5', '::ffff:192.0.2.0/120', false];
        yield 'v4-mapped range shorter than /96 never matches v4' => ['192.0.2.5', '::ffff:0:0/80', false];
        yield 'v4-mapped address in a v6 mapped range' => ['::ffff:192.0.2.5', '::ffff:0:0/96', true];
        yield 'other v6 never matches v4' => ['2001:db8::c000:205', '192.0.2.0/24', false];
    }

    #[DataProvider('cidrCases')]
    public function testInCidr(string $ip, string $cidr, bool $expected): void
    {
        self::assertSame($expected, IpResolver::inCidr($ip, $cidr));
    }
}
