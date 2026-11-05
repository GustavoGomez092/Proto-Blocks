<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use ProtoBlocks\Modules\Redirects\Engine\Request;

final class RequestTest extends EngineTestCase
{
    /** @return array<string, array{string, string, string}> uri, expected path, expected raw query */
    public static function normalisation(): array
    {
        return [
            'plain' => ['/about', '/about', ''],
            'encoded slash decodes to a slash' => ['/a%2Fb', '/a/b', ''],
            'double slashes collapse' => ['//double//slash', '/double/slash', ''],
            'triple slashes collapse' => ['/a///b', '/a/b', ''],
            'root keeps its slash' => ['/', '/', ''],
            'trailing slash kept' => ['/about/', '/about/', ''],
            'empty uri is root' => ['', '/', ''],
            'missing leading slash added' => ['about', '/about', ''],
            'query split at the first ?' => ['/a?x=1?y=2', '/a', 'x=1?y=2'],
            'encoded ? stays in the path' => ['/what%3F', '/what?', ''],
            'fragment dropped' => ['/a?x=1#frag', '/a', 'x=1'],
            'unicode raw' => ['/café', '/café', ''],
            'unicode encoded' => ['/caf%C3%A9', '/café', ''],
            'control chars stripped raw' => ["/a\x01b\x7F", '/ab', ''],
            'encoded control chars stripped after decode' => ['/a%00b%0D%0Ac', '/abc', ''],
            'invalid utf-8 raw stripped' => ["/a\xFFb\xC3", '/ab', ''],
            'invalid utf-8 encoded stripped' => ['/a%FFb', '/ab', ''],
            'query kept raw' => ['/a?q=a%20b&x=%2F', '/a', 'q=a%20b&x=%2F'],
            'case kept' => ['/About', '/About', ''],
        ];
    }

    #[DataProvider('normalisation')]
    public function testNormalisation(string $uri, string $path, string $query): void
    {
        $r = Request::fromUrl($uri, ['HTTP_HOST' => 'example.test']);
        self::assertSame($path, $r->path);
        self::assertSame($query, $r->rawQuery);
    }

    public function testQueryParsedIncludingArrays(): void
    {
        $r = self::req('/a?a[]=1&a[]=2&b=x');
        self::assertSame(['a' => ['1', '2'], 'b' => 'x'], $r->query);
    }

    public function testDuplicateQueryKeyLastWins(): void
    {
        self::assertSame(['a' => '2'], self::req('/a?a=1&a=2')->query);
    }

    public function testHostLowercasedWithoutPort(): void
    {
        $r = Request::fromUrl('/a', ['HTTP_HOST' => 'Example.TEST:8080']);
        self::assertSame('example.test', $r->host);
        self::assertSame(8080, $r->port);
    }

    public function testIpv6HostWithPort(): void
    {
        $r = Request::fromUrl('/a', ['HTTP_HOST' => '[::1]:10003']);
        self::assertSame('[::1]', $r->host);
        self::assertSame(10003, $r->port);
    }

    public function testIdnHostKeptLowercase(): void
    {
        self::assertSame('xn--bcher-kva.example', Request::fromUrl('/a', ['HTTP_HOST' => 'XN--BCHER-KVA.example'])->host);
    }

    public function testAbsoluteUrlGivesHostAndScheme(): void
    {
        $r = Request::fromUrl('https://user@Shop.Example:8443/p?x=1');
        self::assertSame('shop.example', $r->host);
        self::assertSame('https', $r->scheme);
        self::assertSame(8443, $r->port);
        self::assertSame('/p', $r->path);
        self::assertSame('/p?x=1', $r->uri);
    }

    public function testSchemeFromHttpsServerVar(): void
    {
        self::assertSame('https', Request::fromUrl('/a', ['HTTPS' => 'on'])->scheme);
        self::assertSame('http', Request::fromUrl('/a', ['HTTPS' => 'off'])->scheme);
    }

    public function testMethodsUppercasedAndInvalidFallsBackToGet(): void
    {
        self::assertSame('HEAD', self::req('/a', ['REQUEST_METHOD' => 'head'])->method);
        self::assertSame('POST', self::req('/a', ['REQUEST_METHOD' => 'POST'])->method);
        self::assertSame('GET', self::req('/a', ['REQUEST_METHOD' => "PO\nST"])->method);
        self::assertSame('GET', self::req('/a')->method);
    }

    public function testHeadersLowercaseDashed(): void
    {
        $r = self::req('/a', [
            'HTTP_X_CUSTOM_THING' => 'v',
            'HTTP_USER_AGENT' => 'UA',
            'HTTP_REFERER' => 'https://google.com/',
            'CONTENT_TYPE' => 'text/plain',
        ]);
        self::assertSame('v', $r->headers['x-custom-thing']);
        self::assertSame('UA', $r->userAgent);
        self::assertSame('https://google.com/', $r->referrer);
        self::assertSame('text/plain', $r->headers['content-type']);
    }

    public function testHeaderValuesStripControlChars(): void
    {
        self::assertSame('ab', self::req('/a', ['HTTP_X_A' => "a\r\nb"])->headers['x-a']);
    }

    public function testRemoteAddrValidated(): void
    {
        self::assertSame('203.0.113.9', self::req('/a', ['REMOTE_ADDR' => '203.0.113.9'])->remoteAddr);
        self::assertSame('', self::req('/a', ['REMOTE_ADDR' => 'not-an-ip'])->remoteAddr);
    }

    public function testCookiesScalarOnly(): void
    {
        $r = self::req('/a', [], ['a' => 'b', 'arr' => ['x'], 'n' => 5]);
        self::assertSame(['a' => 'b', 'n' => '5'], $r->cookies);
    }

    public function testFromGlobalsReadsServerAndUnslashes(): void
    {
        $_SERVER['REQUEST_URI'] = '/x%2Fy?q=1';
        $_SERVER['HTTP_HOST'] = 'Example.test';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_REFERER'] = "it\\'s";
        $GLOBALS['pb_test_ssl'] = true;
        $r = Request::fromGlobals();
        self::assertSame('/x/y', $r->path);
        self::assertSame('q=1', $r->rawQuery);
        self::assertSame('https', $r->scheme);
        self::assertSame('POST', $r->method);
        self::assertSame("it's", $r->referrer);
        self::assertSame('/x%2Fy?q=1', $r->uri);
    }

    public function testPathAndQuery(): void
    {
        self::assertSame('/a?b=1', self::req('/a?b=1')->pathAndQuery());
        self::assertSame('/a', self::req('/a')->pathAndQuery());
    }

    /** @return array<string, array{string, bool}> */
    public static function exclusions(): array
    {
        return [
            'rest prefix' => ['/wp-json/x', true],
            'rest prefix bare' => ['/wp-json', true],
            'rest_route query' => ['/?rest_route=/wp/v2/posts', true],
            'wp-admin' => ['/wp-admin/', true],
            'wp-admin bare' => ['/wp-admin', true],
            'wp-admin page' => ['/wp-admin/options.php', true],
            'wp-admin upper case' => ['/WP-ADMIN/', true],
            'wp-login' => ['/wp-login.php', true],
            'wp-cron' => ['/wp-cron.php', true],
            'xmlrpc' => ['/xmlrpc.php', true],
            'wp-json lookalike' => ['/wp-jsonx', false],
            'wp-admin lookalike' => ['/wp-administrator', false],
            'normal page' => ['/about', false],
            'double slash wp-admin' => ['//wp-admin//', true],
            'encoded wp-admin' => ['/wp%2Dadmin/', true],
        ];
    }

    #[DataProvider('exclusions')]
    public function testIsExcluded(string $uri, bool $excluded): void
    {
        self::assertSame($excluded, self::req($uri)->isExcluded());
    }

    public function testCustomRestPrefix(): void
    {
        $GLOBALS['pb_test_rest_prefix'] = 'api';
        self::assertTrue(self::req('/api/v1')->isExcluded());
        self::assertFalse(self::req('/wp-json/x')->isExcluded());
    }

    public function testSubdirectoryInstallExclusions(): void
    {
        $GLOBALS['pb_test_home_url'] = 'http://example.test/blog';
        self::assertTrue(self::req('/blog/wp-admin/')->isExcluded());
        self::assertTrue(self::req('/blog/wp-json/x')->isExcluded());
        self::assertFalse(self::req('/blog/post')->isExcluded());
    }

    public function testExclusionFilter(): void
    {
        $seen = null;
        $GLOBALS['pb_test_filters']['proto_blocks_redirects_excluded'][] = static function (bool $excluded, Request $r) use (&$seen) {
            $seen = $r->path;
            return $excluded || str_starts_with($r->path, '/private');
        };
        self::assertTrue(self::req('/private/x')->isExcluded());
        self::assertSame('/private/x', $seen);
        self::assertFalse(self::req('/public')->isExcluded());
    }
}
