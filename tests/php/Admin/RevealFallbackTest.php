<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Admin;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Admin\Assets;

/**
 * The reveal runtime's head output: the `proto-js` class on <html>, and a
 * no-JS fallback keyed to its absence instead of a <noscript>.
 *
 * A <noscript> fallback leaked: Taxi parses fetched pages with scripting
 * disabled, so its contents became real elements and a page swap could put
 * the !important reveal into the live document.
 */
final class RevealFallbackTest extends TestCase
{
    private function render(): string
    {
        $assets = (new \ReflectionClass(Assets::class))->newInstanceWithoutConstructor();

        ob_start();
        $assets->printRevealFallback();

        return (string) ob_get_clean();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pb_test_is_admin']);
    }

    public function testAddsTheProtoJsClassBeforeTheFallback(): void
    {
        $html = $this->render();

        $script = strpos($html, "document.documentElement.classList.add('proto-js')");
        $style  = strpos($html, '<style id="proto-blocks-reveal-fallback">');

        self::assertNotFalse($script);
        self::assertNotFalse($style);
        self::assertLessThan($style, $script);
    }

    public function testFallbackAppliesOnlyWithoutTheClassAndNeverInsideNoscript(): void
    {
        $html = $this->render();

        self::assertStringNotContainsString('<noscript', $html);

        preg_match('#<style id="proto-blocks-reveal-fallback">(.*?)</style>#s', $html, $m);
        $css = $m[1] ?? '';
        $selectors = explode(',', substr($css, 0, (int) strpos($css, '{')));

        self::assertCount(4, $selectors);

        foreach ($selectors as $selector) {
            self::assertStringStartsWith('html:not(.proto-js) ', $selector);
        }

        self::assertStringContainsString('opacity:1!important', $css);
        self::assertStringContainsString('visibility:visible!important', $css);
    }

    public function testPrintsNothingInTheAdmin(): void
    {
        $GLOBALS['pb_test_is_admin'] = true;

        self::assertSame('', $this->render());
    }
}
