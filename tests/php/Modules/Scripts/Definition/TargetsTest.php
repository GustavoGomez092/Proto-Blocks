<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Scripts\Definition;

use PHPUnit\Framework\Attributes\DataProvider;
use ProtoBlocks\Modules\Scripts\Definition\Targets;
use ProtoBlocks\Modules\Scripts\RequestContext;
use ProtoBlocks\Tests\Modules\Scripts\ScriptsTestCase;

/**
 * Spec §2: every placement target, include/exclude precedence, login and
 * role rules, and the url: glob.
 */
final class TargetsTest extends ScriptsTestCase
{
    private static function ctx(string $path = '/', bool $loggedIn = false, array $roles = []): RequestContext
    {
        return new RequestContext($path, $loggedIn, $roles);
    }

    private static function where(array $where): array
    {
        return Targets::normalize($where);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: bool}> target, pb_test_query, expected */
    public static function targets(): array
    {
        return [
            'all' => ['all', [], true],
            'front_page yes' => ['front_page', ['is_front_page' => true], true],
            'front_page no' => ['front_page', ['is_home' => true], false],
            'home' => ['home', ['is_home' => true], true],
            'search' => ['search', ['is_search' => true], true],
            '404' => ['404', ['is_404' => true], true],
            '404 no' => ['404', [], false],
            'archive' => ['archive', ['is_archive' => true], true],
            'singular' => ['singular', ['is_singular' => ['post']], true],
            'singular:type yes' => ['singular:product', ['is_singular' => ['product']], true],
            'singular:type no' => ['singular:product', ['is_singular' => ['post']], false],
            'post_type singular' => ['post_type:product', ['is_singular' => ['product']], true],
            'post_type archive' => ['post_type:product', ['is_post_type_archive' => ['product']], true],
            'post_type other' => ['post_type:product', ['is_post_type_archive' => ['event']], false],
            'page by slug' => ['page:privacy-policy', ['is_page' => ['privacy-policy']], true],
            'page by id' => ['page:42', ['is_page' => ['42']], true],
            'page other' => ['page:about', ['is_page' => ['privacy-policy']], false],
            'page id 0 never' => ['page:0', ['is_page' => true], false],
            'post by slug' => ['post:hello-world', ['is_single' => ['hello-world']], true],
            'post by id' => ['post:7', ['is_single' => ['7']], true],
            'taxonomy custom' => ['taxonomy:genre', ['is_tax' => ['genre']], true],
            'taxonomy category' => ['taxonomy:category', ['is_category' => true], true],
            'taxonomy post_tag' => ['taxonomy:post_tag', ['is_tag' => true], true],
            'taxonomy category not tax' => ['taxonomy:category', ['is_tax' => ['category']], false],
            'term archive custom' => ['term:genre:jazz', ['is_tax' => ['genre'], 'is_tax_term' => ['jazz']], true],
            'term archive other term' => ['term:genre:jazz', ['is_tax' => ['genre'], 'is_tax_term' => ['rock']], false],
            'term category archive' => ['term:category:news', ['is_category' => ['news']], true],
            'term tag archive' => ['term:post_tag:red', ['is_tag' => ['red']], true],
            'term on singular post' => ['term:category:news', ['is_singular' => true, 'has_term' => ['category' => ['news']]], true],
            'term not on singular post' => ['term:category:news', ['is_singular' => true, 'has_term' => ['category' => ['sport']]], false],
            'term needs singular' => ['term:category:news', ['has_term' => ['category' => ['news']]], false],
            'unknown target never matches' => ['everything', ['is_front_page' => true], false],
        ];
    }

    #[DataProvider('targets')]
    public function testEveryTarget(string $target, array $query, bool $expected): void
    {
        $GLOBALS['pb_test_query'] = $query;
        self::assertSame($expected, Targets::matches($target, self::ctx()));
    }

    public function testValidGrammar(): void
    {
        foreach (['all', 'front_page', 'home', 'search', '404', 'archive', 'singular', 'singular:product', 'post_type:event',
            'page:about', 'page:parent/child', 'page:12', 'post:hello', 'taxonomy:genre', 'term:genre:jazz', 'term:category:caf%C3%A9',
            'url:/', 'url:/landing/*', 'url:/a*b/c'] as $ok) {
            self::assertTrue(Targets::valid($ok), $ok);
        }
        foreach (['', 'All', 'pages', 'front-page', 'singular:', 'page:', 'page:a b', 'url:landing', 'url:/a b', 'url:/<x>',
            'term:genre', 'taxonomy:Genre', "all\n", 'url:/' . str_repeat('a', 200), 'page:x:y'] as $bad) {
            self::assertFalse(Targets::valid($bad), $bad);
        }
    }

    public function testDefaultsIncludeAll(): void
    {
        $where = self::where([]);
        self::assertSame(['all'], $where['include']);
        self::assertSame([], $where['exclude']);
        self::assertNull($where['loggedIn']);
        self::assertTrue(Targets::inScope($where, self::ctx()));
    }

    public function testExplicitEmptyIncludeMatchesNothing(): void
    {
        self::assertFalse(Targets::inScope(self::where(['include' => []]), self::ctx()));
    }

    public function testExcludeWinsOverInclude(): void
    {
        $GLOBALS['pb_test_query'] = ['is_page' => ['privacy-policy'], 'is_singular' => ['page']];
        $where = self::where(['include' => ['singular:page'], 'exclude' => ['page:privacy-policy']]);
        self::assertFalse(Targets::inScope($where, self::ctx()));

        $GLOBALS['pb_test_query'] = ['is_page' => ['about'], 'is_singular' => ['page']];
        self::assertTrue(Targets::inScope($where, self::ctx()));
    }

    public function testAnyIncludeEntryIsEnough(): void
    {
        $GLOBALS['pb_test_query'] = ['is_search' => true];
        self::assertTrue(Targets::inScope(self::where(['include' => ['front_page', 'search']]), self::ctx()));
        self::assertFalse(Targets::inScope(self::where(['include' => ['front_page', '404']]), self::ctx()));
    }

    public function testUrlExcludeWithAllInclude(): void
    {
        $where = self::where(['exclude' => ['url:/landing/*']]);
        self::assertFalse(Targets::inScope($where, self::ctx('/landing/spring')));
        self::assertTrue(Targets::inScope($where, self::ctx('/blog/post')));
    }

    public function testLoggedInRules(): void
    {
        self::assertTrue(Targets::inScope(self::where(['loggedIn' => true]), self::ctx('/', true)));
        self::assertFalse(Targets::inScope(self::where(['loggedIn' => true]), self::ctx('/', false)));
        self::assertTrue(Targets::inScope(self::where(['loggedIn' => false]), self::ctx('/', false)));
        self::assertFalse(Targets::inScope(self::where(['loggedIn' => false]), self::ctx('/', true)));
        self::assertTrue(Targets::inScope(self::where(['loggedIn' => null]), self::ctx('/', true)));
    }

    public function testRolesImplyLoggedIn(): void
    {
        $where = self::where(['roles' => ['editor', 'author']]);
        self::assertTrue(Targets::inScope($where, self::ctx('/', true, ['author'])));
        self::assertFalse(Targets::inScope($where, self::ctx('/', true, ['subscriber'])));
        self::assertFalse(Targets::inScope($where, self::ctx('/', false)));
    }

    public function testExcludeRoles(): void
    {
        $where = self::where(['excludeRoles' => ['administrator']]);
        self::assertFalse(Targets::inScope($where, self::ctx('/', true, ['administrator'])));
        self::assertTrue(Targets::inScope($where, self::ctx('/', true, ['editor'])));
        self::assertTrue(Targets::inScope($where, self::ctx('/', false)), 'excludeRoles never excludes visitors');
    }

    public function testRolesAndExcludeRolesTogether(): void
    {
        $where = self::where(['roles' => ['editor'], 'excludeRoles' => ['administrator']]);
        self::assertFalse(Targets::inScope($where, self::ctx('/', true, ['editor', 'administrator'])));
        self::assertTrue(Targets::inScope($where, self::ctx('/', true, ['editor'])));
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function globs(): array
    {
        return [
            'exact' => ['/landing', '/landing', true],
            'star across segments' => ['/landing/*', '/landing/a/b/c', true],
            'star matches empty (folder itself)' => ['/landing/*', '/landing', true],
            'star in the middle' => ['/blog/*/comments', '/blog/2026/11/comments', true],
            'star middle no' => ['/blog/*/comments', '/blog/2026/11', false],
            'trailing slash on path' => ['/landing', '/landing/', true],
            'trailing slash on pattern' => ['/landing/', '/landing', true],
            'prefix is not a match' => ['/landing', '/landing-page', false],
            'case insensitive' => ['/Landing/*', '/landing/x', true],
            'root only' => ['/', '/', true],
            'root does not match deeper' => ['/', '/a', false],
            'star everything' => ['/*', '/anything/at/all', true],
            'dot is literal' => ['/a.b', '/axb', false],
            'regex chars literal' => ['/a(b)+', '/a(b)+', true],
            'encoded pattern' => ['/caf%C3%A9/*', '/café/menu', true],
            'decoded pattern' => ['/café/*', '/café/menu', true],
            'double slash collapsed' => ['/a/b', '/a//b', true],
        ];
    }

    #[DataProvider('globs')]
    public function testGlob(string $pattern, string $path, bool $expected): void
    {
        self::assertSame($expected, Targets::globMatches($pattern, $path));
    }

    public function testUrlTargetUsesContextPath(): void
    {
        self::assertTrue(Targets::matches('url:/shop/*', self::ctx('/shop/cart')));
        self::assertFalse(Targets::matches('url:/shop/*', self::ctx('/blog')));
    }

    public function testPathFromIgnoresQueryAndDecodes(): void
    {
        self::assertSame('/landing/x', RequestContext::pathFrom('/landing/x?utm=1#top'));
        self::assertSame('/café', RequestContext::pathFrom('/caf%C3%A9'));
        self::assertSame('/a/b', RequestContext::pathFrom('//a//b'));
        self::assertSame('/', RequestContext::pathFrom(''));
        self::assertSame('/landing', RequestContext::pathFrom('https://example.test/landing?x'));
    }

    public function testPathFromStripsHomeSubfolder(): void
    {
        self::assertSame('/landing', RequestContext::pathFrom('/wp/landing', '/wp/'));
        self::assertSame('/', RequestContext::pathFrom('/wp', '/wp/'));
        self::assertSame('/wpx/landing', RequestContext::pathFrom('/wpx/landing', '/wp/'));
    }

    public function testCurrentContextReadsGlobals(): void
    {
        $_SERVER['REQUEST_URI'] = '/sub/landing/?q=1';
        $GLOBALS['pb_test_home_url'] = 'http://example.test/sub';
        $this->loginAs(5, ['editor']);
        $ctx = RequestContext::current();
        self::assertSame('/landing/', $ctx->path);
        self::assertTrue($ctx->loggedIn);
        self::assertSame(['editor'], $ctx->roles);
    }

    public function testSummary(): void
    {
        self::assertSame('All pages', Targets::summary(self::where([])));
        self::assertSame('front_page, search; except page:privacy; logged in; not roles: administrator', Targets::summary(self::where([
            'include' => ['front_page', 'search'], 'exclude' => ['page:privacy'], 'loggedIn' => true, 'excludeRoles' => ['administrator'],
        ])));
    }
}
