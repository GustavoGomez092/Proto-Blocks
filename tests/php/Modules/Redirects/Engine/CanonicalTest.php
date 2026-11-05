<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Engine;

use ProtoBlocks\Modules\Redirects\Engine\Canonical;
use ProtoBlocks\Modules\Redirects\Engine\Request;

final class CanonicalTest extends EngineTestCase
{
    private static function canon(string $url, array $canonical): ?string
    {
        return Canonical::target(Request::fromUrl($url), self::settings(['canonical' => $canonical]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['pb_test_home_url'] = 'https://example.test';
    }

    public function testNothingConfigured(): void
    {
        self::assertNull(self::canon('http://example.test/a', []));
    }

    public function testHttpsUpgradeKeepsPathAndQuery(): void
    {
        self::assertSame('https://example.test/a/b?x=1&y=%2F', self::canon('http://example.test/a/b?x=1&y=%2F', ['https' => true]));
        self::assertNull(self::canon('https://example.test/a', ['https' => true]));
    }

    public function testHttpsUpgradeDropsPlainPort(): void
    {
        self::assertSame('https://example.test/a', self::canon('http://example.test:8080/a', ['https' => true]));
    }

    public function testWww(): void
    {
        self::assertSame('https://www.example.test/a', self::canon('https://example.test/a', ['www' => 'www']));
        self::assertNull(self::canon('https://www.example.test/a', ['www' => 'www']));
    }

    public function testNoWww(): void
    {
        self::assertSame('https://example.test/a', self::canon('https://www.example.test/a', ['www' => 'nowww']));
        self::assertNull(self::canon('https://example.test/a', ['www' => 'nowww']));
    }

    public function testWwwOnlyForTheSiteHost(): void
    {
        self::assertNull(self::canon('https://other.test/a', ['www' => 'www']));
        self::assertNull(self::canon('https://203.0.113.5/a', ['www' => 'www']));
    }

    public function testWwwWhenHomeHasWww(): void
    {
        $GLOBALS['pb_test_home_url'] = 'https://www.example.test';
        self::assertSame('https://example.test/a', self::canon('https://www.example.test/a', ['www' => 'nowww']));
    }

    public function testWwwKeepsDevPort(): void
    {
        self::assertSame('http://www.example.test:10003/a', self::canon('http://example.test:10003/a', ['www' => 'www']));
    }

    public function testAliasesGoToSiteHost(): void
    {
        $c = ['aliases' => ['old.example', 'OTHER.example']];
        self::assertSame('https://example.test/p?q=1', self::canon('https://old.example/p?q=1', $c));
        self::assertSame('http://example.test/p', self::canon('http://other.example/p', $c));
        self::assertNull(self::canon('https://unlisted.example/p', $c));
    }

    public function testAliasPlusHttpsPlusWwwIsOneHop(): void
    {
        $c = ['aliases' => ['old.example'], 'https' => true, 'www' => 'www'];
        self::assertSame('https://www.example.test/p', self::canon('http://old.example/p', $c));
    }

    public function testRelocate(): void
    {
        $c = ['relocate' => 'https://new.example/'];
        self::assertSame('https://new.example/a?b=1', self::canon('https://example.test/a?b=1', $c));
        self::assertSame('https://new.example/', self::canon('https://example.test/', $c));
    }

    public function testRelocateKeepsProtectedPaths(): void
    {
        $c = ['relocate' => 'https://new.example'];
        foreach (['/wp-admin/', '/wp-login.php', '/wp-json/wp/v2/posts', '/?rest_route=/x'] as $path) {
            self::assertNull(self::canon('https://example.test' . $path, $c), $path);
        }
    }

    public function testRelocateToItselfIsNull(): void
    {
        self::assertNull(self::canon('https://example.test/a', ['relocate' => 'https://example.test']));
    }

    public function testRelocateWithSubpathAndQueryInSetting(): void
    {
        self::assertSame('https://new.example/site/a', self::canon('https://example.test/a', ['relocate' => 'https://new.example/site/?junk=1']));
    }

    public function testRelocateInvalidIgnored(): void
    {
        self::assertNull(self::canon('https://example.test/a', ['relocate' => 'javascript:alert(1)']));
    }

    public function testRelocateWinsOverHttps(): void
    {
        self::assertSame('https://new.example/a', self::canon('http://example.test/a', ['relocate' => 'https://new.example', 'https' => true]));
    }

    public function testControlCharsStrippedAndLeadingSlashesCollapsed(): void
    {
        self::assertSame('https://example.test/evil.com/x', self::canon("http://example.test//evil.com/x\r\n", ['https' => true]));
    }

    public function testHttpsUpgradeNeverEchoesAForeignHostHeader(): void
    {
        $request = Request::fromUrl('/x?a=1', ['HTTP_HOST' => 'evil.test']);
        self::assertSame('https://example.test/x?a=1', Canonical::target($request, self::settings(['canonical' => ['https' => true]])));
    }

    public function testForeignHostWithPortGoesToTheSiteHostAndPort(): void
    {
        $GLOBALS['pb_test_home_url'] = 'http://example.test:10003';
        self::assertSame('https://example.test/x', self::canon('http://evil.test:8080/x', ['https' => true]));
        self::assertSame('https://www.example.test/x', self::canon('http://evil.test/x', ['https' => true, 'www' => 'www']));
    }

    public function testForeignHostIsLeftAloneWhenNoStepFires(): void
    {
        self::assertNull(self::canon('https://evil.test/x', ['https' => true]));
        self::assertNull(self::canon('http://evil.test/x', ['www' => 'nowww']));
        self::assertNull(self::canon('http://evil.test/x', ['aliases' => ['old.example']]));
    }

    public function testForeignHostWithoutAHomeUrlIsNull(): void
    {
        $GLOBALS['pb_test_home_url'] = '';
        self::assertNull(self::canon('http://evil.test/x', ['https' => true]));
    }

    public function testNoHostIsNull(): void
    {
        self::assertNull(Canonical::target(Request::fromUrl('/a'), self::settings(['canonical' => ['https' => true]])));
    }

    public function testFilterCanChangeOrCancel(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_redirects_canonical_target'][] = static fn($t, Request $r) => $r->path === '/keep' ? null : $t;
        self::assertNull(self::canon('http://example.test/keep', ['https' => true]));
        self::assertSame('https://example.test/go', self::canon('http://example.test/go', ['https' => true]));
    }
}
