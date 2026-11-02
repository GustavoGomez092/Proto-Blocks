<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Tailwind;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Tailwind\Cache;

/**
 * Cache paths are fixed and live under uploads; a pre-planted symlink must
 * never be followed, and writes must not expose a half-written file.
 */
final class CacheAtomicWriteTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pb-atomic-' . uniqid() . '/';
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f) || is_link($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->dir . '.work');
        @rmdir($this->dir);
    }

    public function test_atomic_write_refuses_to_write_through_a_symlink(): void
    {
        $target = $this->dir . 'victim.txt';
        file_put_contents($target, 'original');
        $link = $this->dir . 'tailwind.css';
        symlink($target, $link);

        $this->assertFalse(Cache::atomicWrite($link, 'overwritten'));
        $this->assertSame('original', file_get_contents($target));
        $this->assertTrue(is_link($link), 'the planted link is left alone, not replaced');
    }

    public function test_atomic_write_leaves_no_temp_file_behind(): void
    {
        $path = $this->dir . 'tailwind.css';

        $this->assertTrue(Cache::atomicWrite($path, 'a{b:c}'));
        $this->assertSame('a{b:c}', file_get_contents($path));

        $leftovers = array_filter(
            scandir($this->dir) ?: [],
            static fn(string $f): bool => str_ends_with($f, '.tmp')
        );
        $this->assertSame([], array_values($leftovers));
    }

    public function test_save_content_uses_atomic_write(): void
    {
        $cache = new Cache($this->dir);
        $target = $this->dir . 'victim.txt';
        file_put_contents($target, 'original');
        symlink($target, $cache->getCssPath());

        $this->assertFalse($cache->saveContent('.a{}'));
        $this->assertSame('original', file_get_contents($target));
    }

    public function test_inputs_live_in_a_separate_guarded_work_directory(): void
    {
        $cache = new Cache($this->dir);

        $this->assertSame($this->dir . '.work/', $cache->getWorkDir());
        $this->assertStringStartsWith($cache->getWorkDir(), $cache->getContentPath());
        $this->assertStringStartsWith($cache->getWorkDir(), $cache->getInputPath());
        // The public stylesheet stays in the output directory.
        $this->assertSame($this->dir . 'tailwind.css', $cache->getCssPath());

        $this->assertTrue($cache->saveInput('@import "tailwindcss";'));
        $this->assertFileExists($cache->getWorkDir() . '.htaccess');
        $this->assertFileExists($cache->getWorkDir() . 'index.php');
        $this->assertStringNotContainsString('granted', (string) file_get_contents($cache->getWorkDir() . '.htaccess'));

        $cache->removeCompileInputs();
        $this->assertFileDoesNotExist($cache->getInputPath());
        @unlink($cache->getWorkDir() . '.htaccess');
        @unlink($cache->getWorkDir() . 'index.php');
    }
}
