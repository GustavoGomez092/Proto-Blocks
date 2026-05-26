<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Tailwind;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Tailwind\BinaryManager;

final class BinaryManagerTest extends TestCase
{
    private string $binDir;

    protected function setUp(): void
    {
        $this->binDir = sys_get_temp_dir() . '/pb-bin-' . uniqid() . '/';
        mkdir($this->binDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $bin = $this->binDir . 'tailwindcss';
        if (file_exists($bin)) {
            @unlink($bin);
        }
        if (is_dir($this->binDir)) {
            @rmdir($this->binDir);
        }
    }

    private function writeBinary(string $contents): void
    {
        $path = $this->binDir . 'tailwindcss';
        file_put_contents($path, $contents);
        chmod($path, 0755);
    }

    public function test_missing_binary_is_neither_installed_nor_functional(): void
    {
        $bm = new BinaryManager($this->binDir);

        $this->assertFalse($bm->isInstalled());
        $this->assertNull($bm->getVersion());
        $this->assertFalse($bm->isFunctional());
    }

    public function test_runnable_binary_is_installed_and_functional(): void
    {
        $this->writeBinary("#!/bin/sh\necho \"tailwindcss v4.1.0\"\n");
        $bm = new BinaryManager($this->binDir);

        $this->assertTrue($bm->isInstalled());
        $this->assertSame('4.1.0', $bm->getVersion());
        $this->assertTrue($bm->isFunctional());
    }

    public function test_present_but_unrunnable_binary_is_installed_but_not_functional(): void
    {
        // Exists with the executable bit, but exits non-zero with no version
        // output — reproduces the "green check + v?" broken state.
        $this->writeBinary("#!/bin/sh\nexit 1\n");
        $bm = new BinaryManager($this->binDir);

        $this->assertTrue($bm->isInstalled());   // file present + executable bit
        $this->assertNull($bm->getVersion());    // cannot read a version
        $this->assertFalse($bm->isFunctional()); // therefore not usable
    }

    public function test_shell_is_available_when_exec_works(): void
    {
        // This asserts the happy path on environments where exec() runs. Skip
        // (don't fail) where exec is disabled — that is exactly the managed-host
        // scenario this feature handles, and CI containers may forbid exec.
        $output = [];
        $exitCode = 1;
        if (!function_exists('exec')) {
            $this->markTestSkipped('exec() is not available in this environment.');
        }
        @exec('echo proto', $output, $exitCode);
        if ($exitCode !== 0) {
            $this->markTestSkipped('exec() is disabled in this environment.');
        }

        $bm = new BinaryManager($this->binDir);
        $this->assertTrue($bm->isShellAvailable());
    }
}
