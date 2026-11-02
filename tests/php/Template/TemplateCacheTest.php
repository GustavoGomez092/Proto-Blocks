<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Template;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Template\Cache;

if (!defined('PROTO_BLOCKS_CACHE_ENABLED')) {
    define('PROTO_BLOCKS_CACHE_ENABLED', true);
}
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/pb-wp-content');
}

/**
 * Template\Cache: JSON-only storage, legacy .php entries never included,
 * salted file names, guard files, atomic writes, block.json invalidation.
 */
final class TemplateCacheTest extends TestCase
{
    private string $root;
    private string $cacheDir;
    private string $blockDir;
    private string $templatePath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pb-cache-' . uniqid('', true);
        $this->cacheDir = $this->root . '/uploads/proto-blocks/templates';
        $this->blockDir = $this->root . '/theme/proto-blocks/hero';
        $this->templatePath = $this->blockDir . '/template.php';

        mkdir($this->blockDir, 0777, true);
        file_put_contents($this->templatePath, '<section data-proto-field="title"></section>');
        file_put_contents($this->blockDir . '/block.json', '{"name":"proto-blocks/hero"}');

        // Make the sources older than anything the cache writes during the test.
        touch($this->templatePath, time() - 100);
        touch($this->blockDir . '/block.json', time() - 100);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    private function makeCache(): Cache
    {
        return new Cache($this->cacheDir);
    }

    private function sampleData(): array
    {
        return [
            'fields' => [
                'title' => ['type' => 'text', 'default' => 'Hello'],
                'items' => ['type' => 'repeater', 'fields' => ['label' => ['type' => 'text']]],
            ],
            'html' => '<section>unused</section>',
        ];
    }

    /** @return string[] */
    private function cacheFiles(string $pattern = '*'): array
    {
        $files = glob($this->cacheDir . '/' . $pattern) ?: [];
        return array_map('basename', $files);
    }

    private function legacyPath(): string
    {
        return $this->cacheDir . '/hero-' . md5($this->templatePath) . '.php';
    }

    public function testConstructorCreatesGuardFiles(): void
    {
        $this->assertDirectoryDoesNotExist($this->cacheDir);

        $this->makeCache();

        $this->assertFileExists($this->cacheDir . '/index.php');
        $this->assertFileExists($this->cacheDir . '/.htaccess');

        $this->assertStringContainsString('Silence is golden', (string) file_get_contents($this->cacheDir . '/index.php'));

        $htaccess = (string) file_get_contents($this->cacheDir . '/.htaccess');
        $this->assertStringContainsString('<IfModule mod_authz_core.c>', $htaccess);
        $this->assertStringContainsString('Require all denied', $htaccess);
        $this->assertStringContainsString('<IfModule !mod_authz_core.c>', $htaccess);
        $this->assertStringContainsString('Deny from all', $htaccess);
    }

    public function testProtectDirectoryIsIdempotentAndKeepsExistingGuards(): void
    {
        $dir = $this->root . '/other-cache';
        Cache::protectDirectory($dir);
        $this->assertFileExists($dir . '/index.php');
        $this->assertFileExists($dir . '/.htaccess');

        file_put_contents($dir . '/.htaccess', '# custom');
        Cache::protectDirectory($dir . '/');

        $this->assertSame('# custom', file_get_contents($dir . '/.htaccess'));
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        sort($entries);
        $this->assertSame(['.htaccess', 'index.php'], $entries, 'no temp or extra files left in the directory');
    }

    public function testRoundTripStoresJsonAndDropsUnusedHtmlKey(): void
    {
        $cache = $this->makeCache();
        $cache->set($this->templatePath, $this->sampleData());

        $json = $this->cacheFiles('*.json');
        $this->assertCount(1, $json, 'exactly one .json cache entry is written');
        $this->assertSame([], array_diff($this->cacheFiles('*.php'), ['index.php']), 'no executable cache entry is written');
        $this->assertSame([], $this->cacheFiles('.*.tmp'), 'no temp file left behind');

        $raw = (string) file_get_contents($this->cacheDir . '/' . $json[0]);
        $this->assertStringNotContainsString('<?php', $raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('fields', $decoded);
        $this->assertArrayNotHasKey('html', $decoded);

        // Same process: memory cache.
        $fromMemory = $cache->get($this->templatePath);
        $this->assertSame($this->sampleData()['fields'], $fromMemory['fields']);
        $this->assertArrayNotHasKey('html', $fromMemory);

        // Fresh instance: file cache.
        $fromDisk = $this->makeCache()->get($this->templatePath);
        $this->assertSame($this->sampleData()['fields'], $fromDisk['fields']);
        $this->assertArrayNotHasKey('html', $fromDisk);
    }

    public function testFileNameIsNotTheBareMd5OfThePath(): void
    {
        $this->makeCache()->set($this->templatePath, $this->sampleData());

        $json = $this->cacheFiles('*.json');
        $this->assertCount(1, $json);
        $this->assertStringStartsWith('hero-', $json[0]);
        $this->assertStringNotContainsString(md5($this->templatePath), $json[0]);
        $this->assertSame('hero-' . hash_hmac('sha256', $this->templatePath, '') . '.json', $json[0]);
    }

    public function testLegacyPhpCacheFileIsDeletedAndNeverIncluded(): void
    {
        $cache = $this->makeCache();

        file_put_contents($this->legacyPath(), '<?php return ["pwned" => true];');
        touch($this->legacyPath(), time() + 100);
        $this->assertFileExists($this->legacyPath());

        $result = $cache->get($this->templatePath);

        $this->assertNull($result, 'legacy file must not be served');
        $this->assertFileDoesNotExist($this->legacyPath(), 'legacy file is removed');

        // Regeneration writes JSON and subsequent reads come from it.
        $cache->set($this->templatePath, $this->sampleData());
        $again = $this->makeCache()->get($this->templatePath);
        $this->assertIsArray($again);
        $this->assertArrayNotHasKey('pwned', $again);
        $this->assertCount(1, $this->cacheFiles('*.json'));
    }

    public function testLegacyPhpFileIsRemovedOnSetAndClear(): void
    {
        $cache = $this->makeCache();

        file_put_contents($this->legacyPath(), '<?php return [];');
        $cache->set($this->templatePath, $this->sampleData());
        $this->assertFileDoesNotExist($this->legacyPath());

        file_put_contents($this->cacheDir . '/other-' . md5('x') . '.php', '<?php return [];');
        $removed = $cache->clear();
        $this->assertSame(2, $removed, 'one json entry + one stray legacy php entry');
        $this->assertSame(['index.php'], $this->cacheFiles('*.php'), 'guard index.php survives clear()');
        $this->assertSame([], $this->cacheFiles('*.json'));
    }

    public function testCorruptOrMisshapenJsonIsRejectedAndDeleted(): void
    {
        $cache = $this->makeCache();
        $cache->set($this->templatePath, $this->sampleData());
        $file = $this->cacheDir . '/' . $this->cacheFiles('*.json')[0];

        file_put_contents($file, '{not json');
        $this->assertNull($this->makeCache()->get($this->templatePath));
        $this->assertFileDoesNotExist($file);

        $cache->set($this->templatePath, $this->sampleData());
        file_put_contents($file, json_encode(['nope' => 1]));
        $this->assertNull($this->makeCache()->get($this->templatePath));
        $this->assertFileDoesNotExist($file);
    }

    public function testBlockJsonTouchInvalidatesEntry(): void
    {
        $cache = $this->makeCache();
        $cache->set($this->templatePath, $this->sampleData());

        $this->assertTrue($cache->isValid($this->templatePath));
        $this->assertIsArray($this->makeCache()->get($this->templatePath));

        touch($this->blockDir . '/block.json', time() + 10);

        $this->assertFalse($cache->isValid($this->templatePath));
        $this->assertNull($this->makeCache()->get($this->templatePath));
    }

    public function testTemplateJsonAndTemplateTouchStillInvalidate(): void
    {
        $cache = $this->makeCache();
        $cache->set($this->templatePath, $this->sampleData());
        $this->assertTrue($cache->isValid($this->templatePath));

        file_put_contents($this->blockDir . '/template.json', '{}');
        touch($this->blockDir . '/template.json', time() + 10);
        $this->assertFalse($cache->isValid($this->templatePath));

        unlink($this->blockDir . '/template.json');
        $cache->set($this->templatePath, $this->sampleData());
        $this->assertTrue($cache->isValid($this->templatePath));

        touch($this->templatePath, time() + 10);
        $this->assertFalse($cache->isValid($this->templatePath));
    }

    public function testRefusesToWriteThroughSymlinkTarget(): void
    {
        $cache = $this->makeCache();
        $victim = $this->root . '/victim.txt';
        file_put_contents($victim, 'original');

        $target = $this->cacheDir . '/hero-' . hash_hmac('sha256', $this->templatePath, '') . '.json';
        symlink($victim, $target);

        $cache->set($this->templatePath, $this->sampleData());

        $this->assertSame('original', file_get_contents($victim), 'symlink target untouched');
        $this->assertTrue(is_link($target), 'symlink left in place rather than replaced');
        $this->assertSame([], $this->cacheFiles('.*.tmp'));

        // A symlinked entry is also never read back as valid.
        $this->assertNull($this->makeCache()->get($this->templatePath));
    }

    public function testStatsCountJsonEntriesOnly(): void
    {
        $cache = $this->makeCache();
        $cache->set($this->templatePath, $this->sampleData());
        file_put_contents($this->legacyPath(), '<?php return [];');

        $stats = $cache->getStats();
        $this->assertSame(1, $stats['file_count']);
        $this->assertGreaterThan(0, $stats['total_size']);
        $this->assertSame(1, $stats['memory_count']);
        $this->assertTrue($stats['enabled']);
        $this->assertSame(rtrim($this->cacheDir, '/') . '/', $stats['directory']);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } else {
                $this->removeTree($path);
            }
        }
        rmdir($dir);
    }
}
