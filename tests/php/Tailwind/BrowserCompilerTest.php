<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Tailwind;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Tailwind\BrowserCompiler;
use ProtoBlocks\Tailwind\Scoper;
use ProtoBlocks\Tailwind\Cache;

final class BrowserCompilerTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/pb-cache-' . uniqid() . '/';
        mkdir($this->cacheDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cacheDir);
    }

    public function test_store_scopes_and_writes_css(): void
    {
        $cache = new Cache($this->cacheDir);
        $compiler = new BrowserCompiler(new Scoper(), $cache);

        $ok = $compiler->store('.flex{display:flex}');

        $this->assertTrue($ok);
        $saved = file_get_contents($this->cacheDir . 'tailwind.css');
        $this->assertNotFalse($saved);
        // Scoper prefixes selectors with the scope class...
        $this->assertStringContainsString('.' . Scoper::SCOPE_CLASS, $saved);
        // ...and the declaration survives scoping (guards against a scoper that
        // drops the rule entirely while still emitting the scope class).
        $this->assertStringContainsString('display:flex', $saved);
    }

    public function test_store_returns_false_for_empty_css(): void
    {
        $cache = new Cache($this->cacheDir);
        $compiler = new BrowserCompiler(new Scoper(), $cache);

        $this->assertFalse($compiler->store('   '));
    }
}
