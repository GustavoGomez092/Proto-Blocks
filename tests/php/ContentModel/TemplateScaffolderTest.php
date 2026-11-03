<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\TemplateScaffolder;
use ProtoBlocks\ContentModel\WriteException;

require_once __DIR__ . '/SafeFileTest.php';

final class TemplateScaffolderTest extends TestCase
{
    private string $tmp;
    private string $child;
    private string $parent;
    private string $outside;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/pb-scaffold-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->tmp = (string) realpath($tmp);
        $this->child = $this->tmp . '/child';
        $this->parent = $this->tmp . '/parent';
        $this->outside = $this->tmp . '/outside';
        foreach ([$this->child, $this->parent, $this->outside, $this->child . '/templates', $this->parent . '/templates'] as $d) {
            mkdir($d);
        }
    }

    protected function tearDown(): void
    {
        SafeFileTest::removeTree($this->tmp);
    }

    private function scaffolder(bool $block = true, bool $mods = true): TemplateScaffolder
    {
        return new TemplateScaffolder($this->child, $this->parent, $block, $mods);
    }

    public function testWritesSingleAndArchive(): void
    {
        $r = $this->scaffolder()->scaffold('product', true, [], false);
        $this->assertSame(['single-product.html', 'archive-product.html'], $r['written']);
        $this->assertSame([], $r['skipped']);
        $this->assertStringContainsString('wp:post-content', (string) file_get_contents($this->child . '/templates/single-product.html'));
    }

    public function testNoArchiveWhenHasArchiveFalse(): void
    {
        $r = $this->scaffolder()->scaffold('product', false, [], false);
        $this->assertSame(['single-product.html'], $r['written']);
    }

    public function testTaxonomiesOnlyOnRequest(): void
    {
        $r = $this->scaffolder()->scaffold('product', false, ['genre'], false);
        $this->assertFileDoesNotExist($this->child . '/templates/taxonomy-genre.html');
        $this->assertSame(['single-product.html'], $r['written']);

        $r = $this->scaffolder()->scaffold('product', false, ['genre'], true);
        $this->assertSame(['taxonomy-genre.html'], $r['written']);
        $this->assertSame(['single-product.html'], $r['skipped']);
        $this->assertSame(['single-product.html', 'taxonomy-genre.html'], $this->scaffolder()->plan('product', false, ['genre'], true));
    }

    public function testExistingNeverOverwritten(): void
    {
        file_put_contents($this->child . '/templates/single-product.html', 'KEEP');
        $r = $this->scaffolder()->scaffold('product', true, [], false);
        $this->assertSame('KEEP', file_get_contents($this->child . '/templates/single-product.html'));
        $this->assertSame(['single-product.html'], $r['skipped']);
        $this->assertSame(['archive-product.html'], $r['written']);
    }

    public function testExistingInParentCountsAsExisting(): void
    {
        file_put_contents($this->parent . '/templates/single-product.html', 'PARENT');
        $r = $this->scaffolder()->scaffold('product', false, [], false);
        $this->assertSame(['single-product.html'], $r['skipped']);
        $this->assertFileDoesNotExist($this->child . '/templates/single-product.html');
    }

    public function testSeedsFromThemeSingle(): void
    {
        file_put_contents($this->parent . '/templates/single.html', '<!-- seed -->');
        $this->scaffolder()->scaffold('product', false, [], false);
        $this->assertStringContainsString('<!-- seed -->', (string) file_get_contents($this->child . '/templates/single-product.html'));
    }

    public function testClassicThemeRefuses(): void
    {
        try {
            $this->scaffolder(false)->scaffold('product', true, [], false);
            $this->fail('expected exception');
        } catch (WriteException $e) {
            $this->assertSame('classic_theme', $e->errorCode);
            $this->assertSame(409, $e->status);
        }
        $this->assertSame([], glob($this->child . '/templates/*'));
    }

    public function testFileModsDisabledRefused(): void
    {
        try {
            $this->scaffolder(true, false)->scaffold('product', true, [], false);
            $this->fail('expected exception');
        } catch (WriteException $e) {
            $this->assertSame('file_mods', $e->errorCode);
            $this->assertSame(403, $e->status);
        }
        $this->assertSame([], glob($this->child . '/templates/*'));
    }

    public function testNoSeedUsesMinimalTemplate(): void
    {
        file_put_contents($this->child . '/templates/index.html', '<!-- index-marker -->');
        $this->scaffolder()->scaffold('product', true, [], false);
        $archive = (string) file_get_contents($this->child . '/templates/archive-product.html');
        $this->assertStringContainsString('wp:query', $archive);
        $this->assertStringContainsString('wp:post-template', $archive);
        $this->assertStringNotContainsString('index-marker', $archive);
    }

    public function testSymlinkedTemplatesDirRefused(): void
    {
        SafeFileTest::removeTree($this->child . '/templates');
        symlink($this->outside, $this->child . '/templates');
        try {
            $this->scaffolder()->scaffold('product', true, [], false);
            $this->fail('expected exception');
        } catch (WriteException $e) {
            $this->assertSame('path_outside_root', $e->errorCode);
            $this->assertSame(403, $e->status);
        }
        $this->assertSame([], glob($this->outside . '/*'));
    }

    public function testInvalidSlugRefused(): void
    {
        $this->expectException(WriteException::class);
        $this->scaffolder()->scaffold('../evil', true, [], false);
    }
}
