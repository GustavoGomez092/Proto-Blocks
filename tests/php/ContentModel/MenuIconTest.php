<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\MenuIcon;

require_once __DIR__ . '/../Security/wp-stubs.php';

final class MenuIconTest extends TestCase
{
    private const URL = 'https://example.test/wp-content/themes/t';

    private string $root;
    private string $theme;

    protected function setUp(): void
    {
        $this->root  = sys_get_temp_dir() . '/pb-menuicon-' . bin2hex(random_bytes(4));
        $this->theme = $this->root . '/theme';
        mkdir($this->theme . '/icons', 0777, true);
        foreach (glob(__DIR__ . '/../fixtures/content-model/icons/*') as $f) {
            copy($f, $this->theme . '/icons/' . basename($f));
        }
        file_put_contents($this->root . '/outside.svg', '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>');
        symlink($this->root . '/outside.svg', $this->theme . '/icons/link.svg');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->theme . '/icons/*') as $f) {
            unlink($f);
        }
        rmdir($this->theme . '/icons');
        rmdir($this->theme);
        unlink($this->root . '/outside.svg');
        rmdir($this->root);
    }

    private function resolve(string $v): array
    {
        return MenuIcon::resolve($v, $this->theme, self::URL);
    }

    private function assertDefault(string $v): void
    {
        $r = $this->resolve($v);
        $this->assertSame(MenuIcon::DEFAULT_ICON, $r['icon'], $v);
        $this->assertSame('icon_invalid', $r['warning'], $v);
    }

    public function testEmptyIsDefaultWithoutWarning(): void
    {
        $this->assertSame(['icon' => MenuIcon::DEFAULT_ICON, 'warning' => null], $this->resolve(''));
    }

    public function testDefaultIconIsBlackGlyphDataUri(): void
    {
        $this->assertStringStartsWith('data:image/svg+xml;base64,', MenuIcon::DEFAULT_ICON);
        $svg = base64_decode(substr(MenuIcon::DEFAULT_ICON, strlen('data:image/svg+xml;base64,')));
        $this->assertStringContainsString('fill="black"', $svg);
    }

    public function testDashicon(): void
    {
        $this->assertSame(['icon' => 'dashicons-portfolio', 'warning' => null], $this->resolve('dashicons-portfolio'));
    }

    public function testHttpsPassthrough(): void
    {
        $this->assertSame(['icon' => 'https://cdn.test/i.png', 'warning' => null], $this->resolve('https://cdn.test/i.png'));
    }

    public function testCleanSvgInlined(): void
    {
        $r = $this->resolve('icons/clean.svg');
        $this->assertNull($r['warning']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $r['icon']);
        $this->assertStringContainsString('<path', base64_decode(substr($r['icon'], 26)));
    }

    public function testHostileSvgSanitised(): void
    {
        $r = $this->resolve('icons/hostile.svg');
        $this->assertNull($r['warning']);
        $decoded = base64_decode(substr($r['icon'], 26));
        $this->assertStringNotContainsString('<script', $decoded);
        $this->assertStringNotContainsString('onload', $decoded);
        $this->assertStringNotContainsString('javascript', $decoded);
    }

    public function testRasterResolvedToThemeUrl(): void
    {
        $this->assertSame(
            ['icon' => self::URL . '/icons/photo.png', 'warning' => null],
            MenuIcon::resolve('icons/photo.png', $this->theme, self::URL . '/')
        );
    }

    public function testInvalidValuesFallBack(): void
    {
        foreach ([
            '../outside.svg',
            'icons/../../outside.svg',
            'http://x.test/i.png',
            'icons/missing.svg',
            'icons/missing.png',
            'data:image/svg+xml;base64,AAA',
            'icons/link.svg',
            '/etc/passwd.svg',
            'icons/clean.gif',
            'dashicons-Bad',
        ] as $v) {
            $this->assertDefault($v);
        }
    }

    public function testSvgThatSanitisesToNothingFallsBack(): void
    {
        file_put_contents($this->theme . '/icons/bad.svg', '<html/>');
        $this->assertDefault('icons/bad.svg');
        unlink($this->theme . '/icons/bad.svg');
    }
}
