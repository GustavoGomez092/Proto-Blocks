<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\RewriteHash;

require_once __DIR__ . '/stubs/hooks.php';

final class RewriteHashTest extends TestCase
{
    protected function setUp(): void
    {
        unset($GLOBALS['pb_test_options'][RewriteHash::OPTION]);
        $GLOBALS['pb_test_flushes'] = 0;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pb_test_options'][RewriteHash::OPTION]);
        $GLOBALS['pb_test_flushes'] = 0;
    }

    public function testStableUnderKeyOrder(): void
    {
        $a = RewriteHash::compute(
            [
                'product' => ['public' => true, 'rewrite' => ['slug' => 'product', 'with_front' => false], 'has_archive' => true],
                'event' => ['public' => true],
            ],
            ['brand' => ['hierarchical' => false, 'rewrite' => ['slug' => 'brand']]]
        );
        $b = RewriteHash::compute(
            [
                'event' => ['public' => true],
                'product' => ['has_archive' => true, 'rewrite' => ['with_front' => false, 'slug' => 'product'], 'public' => true],
            ],
            ['brand' => ['rewrite' => ['slug' => 'brand'], 'hierarchical' => false]]
        );

        $this->assertSame($a, $b);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}\z/D', $a);
    }

    public function testChangesWhenRewriteSlugChanges(): void
    {
        $a = RewriteHash::compute(['product' => ['rewrite' => ['slug' => 'product']]], []);
        $b = RewriteHash::compute(['product' => ['rewrite' => ['slug' => 'shop']]], []);

        $this->assertNotSame($a, $b);
    }

    public function testIgnoresArgsThatDoNotAffectRewrites(): void
    {
        $a = RewriteHash::compute(['product' => ['rewrite' => true, 'labels' => ['name' => 'Products'], 'menu_position' => 5]], []);
        $b = RewriteHash::compute(['product' => ['rewrite' => true, 'labels' => ['name' => 'Goods'], 'menu_position' => 9]], []);

        $this->assertSame($a, $b);
    }

    public function testTypesAndTaxonomiesAreDistinct(): void
    {
        $this->assertNotSame(
            RewriteHash::compute(['genre' => ['public' => true]], []),
            RewriteHash::compute([], ['genre' => ['public' => true]])
        );
    }

    public function testMaybeFlushFlushesOnceForTheSameHash(): void
    {
        $this->assertTrue(RewriteHash::maybeFlush('abc', 'themeA'));
        $this->assertFalse(RewriteHash::maybeFlush('abc', 'themeA'));
        $this->assertSame(1, $GLOBALS['pb_test_flushes']);

        $this->assertTrue(RewriteHash::maybeFlush('def', 'themeA'));
        $this->assertSame(2, $GLOBALS['pb_test_flushes']);
    }

    public function testKeyedPerTheme(): void
    {
        RewriteHash::maybeFlush('abc', 'themeA');

        $this->assertTrue(RewriteHash::maybeFlush('abc', 'themeB'));
        $this->assertSame(2, $GLOBALS['pb_test_flushes']);
        $this->assertSame(['themeB' => 'abc'], $GLOBALS['pb_test_options'][RewriteHash::OPTION]);
    }
}
