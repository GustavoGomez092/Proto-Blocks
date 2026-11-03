<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\DoubleDeclarationNotice;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Registry;

require_once __DIR__ . '/stubs/hooks.php';

final class DoubleDeclarationNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pb_test_screen'] = (object) ['id' => 'toplevel_page_proto-blocks'];
        $GLOBALS['pb_test_current_user'] = 7;
        $GLOBALS['pb_test_caps'][7] = ['manage_options'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pb_test_screen'], $GLOBALS['pb_test_current_user'], $GLOBALS['pb_test_caps'][7]);
    }

    public function testNamesBothLocations(): void
    {
        $output = $this->render($this->registryWithDouble());

        $this->assertStringContainsString('"product" is declared twice', $output);
        $this->assertStringContainsString('proto-types/product/type.jsonc', $output);
        $this->assertStringContainsString('functions.php:12', $output);
        $this->assertStringContainsString('Delete the PHP line when ready.', $output);
        $this->assertStringContainsString('notice-warning', $output);
    }

    public function testPathsAreThemeRelativeNeverAbsolute(): void
    {
        $tmp = sys_get_temp_dir() . '/pb-notice-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/themes/acme', 0777, true);
        $theme = (string) realpath($tmp . '/themes/acme');
        $previous = $GLOBALS['pb_test_theme'] ?? null;
        $GLOBALS['pb_test_theme'] = ['template' => $theme, 'stylesheet' => $theme];

        try {
            $output = $this->render($this->registryWith([[
                'kind' => 'type',
                'slug' => 'product',
                'file' => $theme . '/proto-types/product/type.jsonc',
                'php' => $theme . '/functions.php:12',
            ]]));
        } finally {
            $GLOBALS['pb_test_theme'] = $previous;
            rmdir($theme);
            rmdir($tmp . '/themes');
            rmdir($tmp);
        }

        $this->assertStringContainsString('<code>acme/proto-types/product/type.jsonc</code>', $output);
        $this->assertStringContainsString('<code>acme/functions.php:12</code>', $output);
        $this->assertStringNotContainsString($tmp, $output);
    }

    public function testOtherScreensShowNothing(): void
    {
        $GLOBALS['pb_test_screen'] = (object) ['id' => 'edit-post'];

        $this->assertSame('', $this->render($this->registryWithDouble()));
    }

    public function testNoScreenShowsNothing(): void
    {
        $GLOBALS['pb_test_screen'] = null;

        $this->assertSame('', $this->render($this->registryWithDouble()));
    }

    public function testUserWithoutManageOptionsSeesNothing(): void
    {
        $GLOBALS['pb_test_caps'][7] = ['edit_posts'];

        $this->assertSame('', $this->render($this->registryWithDouble()));
    }

    public function testNoDoublesShowsNothing(): void
    {
        $this->assertSame('', $this->render($this->registryWith([])));
    }

    public function testOutputIsEscaped(): void
    {
        $registry = $this->registryWith([['kind' => 'type', 'slug' => 'product', 'file' => '/t/<b>/type.jsonc', 'php' => '/t/functions.php:12']]);

        $output = $this->render($registry);

        $this->assertStringNotContainsString('<b>', $output);
        $this->assertStringContainsString('&lt;b&gt;', $output);
    }

    private function registryWithDouble(): Registry
    {
        return $this->registryWith([['kind' => 'type', 'slug' => 'product', 'file' => '/t/proto-types/product/type.jsonc', 'php' => '/t/functions.php:12']]);
    }

    /** @param list<array{kind: string, slug: string, file: string, php: string}> $doubles */
    private function registryWith(array $doubles): Registry
    {
        $registry = new Registry(new FileLoader(), static fn () => []);
        $state = new \ReflectionProperty(Registry::class, 'state');
        $state->setValue($registry, ['declarations' => ['type' => [], 'taxonomy' => []], 'doubles' => $doubles, 'issues' => []]);

        return $registry;
    }

    private function render(Registry $registry): string
    {
        ob_start();
        (new DoubleDeclarationNotice($registry))->render();

        return (string) ob_get_clean();
    }
}
