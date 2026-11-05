<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Storage;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Redirects\Storage\RequestSnapshot;

final class RequestSnapshotTest extends TestCase
{
    public function testFromGlobals(): void
    {
        $snapshot = RequestSnapshot::fromGlobals([
            'REQUEST_URI' => '/a?b=1',
            'REQUEST_METHOD' => 'POST',
            'REMOTE_ADDR' => '203.0.113.1',
            'HTTP_USER_AGENT' => 'UA \\"quoted\\"',
            'HTTP_REFERER' => 'https://r.test/',
            'HTTP_X_FORWARDED_FOR' => '1.1.1.1',
            'CONTENT_TYPE' => 'text/plain',
            'SERVER_SOFTWARE' => 'nginx',
            'HTTP_ARRAY' => ['nope'],
        ]);

        self::assertSame([
            'url' => '/a?b=1',
            'method' => 'POST',
            'ip' => '203.0.113.1',
            'agent' => 'UA "quoted"',
            'referrer' => 'https://r.test/',
            'headers' => [
                'user-agent' => 'UA "quoted"',
                'referer' => 'https://r.test/',
                'x-forwarded-for' => '1.1.1.1',
                'content-type' => 'text/plain',
            ],
        ], $snapshot);
    }

    public function testFromGlobalsWithNothing(): void
    {
        self::assertSame(
            ['url' => '', 'method' => '', 'ip' => '', 'agent' => '', 'referrer' => '', 'headers' => []],
            RequestSnapshot::fromGlobals([])
        );
    }

    public function testFromArrayDefaultsAndLowerCasesHeaders(): void
    {
        self::assertSame(
            ['url' => '/x', 'method' => '', 'ip' => '', 'agent' => '', 'referrer' => '', 'headers' => ['x-a' => '1']],
            RequestSnapshot::from(['url' => '/x', 'headers' => ['X-A' => 1, 'bad' => ['no']], 'agent' => ['no']])
        );
    }

    public function testFromObject(): void
    {
        $request = new class {
            public string $uri = '/o';
            public string $method = 'GET';
            public string $remoteAddr = '::1';
            public string $userAgent = 'UA';
            public string $referrer = 'R';
            public array $headers = ['accept' => '*/*'];
        };
        self::assertSame(
            ['url' => '/o', 'method' => 'GET', 'ip' => '::1', 'agent' => 'UA', 'referrer' => 'R', 'headers' => ['accept' => '*/*']],
            RequestSnapshot::from($request)
        );
    }

    public function testToServerRoundTripsForIpResolution(): void
    {
        self::assertSame(
            ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1', 'HTTP_CF_CONNECTING_IP' => '2.2.2.2'],
            RequestSnapshot::toServer(['ip' => '10.0.0.1', 'headers' => ['x-forwarded-for' => '1.1.1.1', 'cf-connecting-ip' => '2.2.2.2']])
        );
    }
}
