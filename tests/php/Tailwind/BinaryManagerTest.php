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
        $this->removeTree($this->binDir);
    }

    private function removeTree(string $dir): void
    {
        $dir = rtrim($dir, DIRECTORY_SEPARATOR);

        if (!is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
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

    public function test_version_check_does_not_pass_the_unsupported_version_flag(): void
    {
        // Tailwind v4 has no `--version` flag. Passing it makes the real CLI fall
        // through to a full build that scans the working directory for class
        // names — which is how a "version check" once pulled tens of gigabytes
        // into memory and wedged a machine. This stub refuses every flag except
        // --help, so the old `--version` implementation reads no version at all.
        $this->writeBinary(
            "#!/bin/sh\n"
            . "case \"$1\" in\n"
            . "  --help) echo \"~ tailwindcss v4.3.3\"; exit 0 ;;\n"
            . "  *) echo \"error: unexpected flag $1\" >&2; exit 1 ;;\n"
            . "esac\n"
        );

        $bm = new BinaryManager($this->binDir);

        $this->assertSame('4.3.3', $bm->getVersion());
    }

    public function test_version_check_runs_from_an_empty_scratch_directory(): void
    {
        // The CLI must never run in the caller's working directory: Tailwind
        // scans wherever it is invoked, so a check from a site root or a home
        // directory walks the entire disk. Record the cwd the binary sees.
        $marker = $this->binDir . 'cwd.txt';

        $this->writeBinary(
            "#!/bin/sh\n"
            . "pwd > " . escapeshellarg($marker) . "\n"
            . "echo \"~ tailwindcss v4.3.3\"\n"
        );

        $bm = new BinaryManager($this->binDir);
        $this->assertSame('4.3.3', $bm->getVersion());

        $seenCwd = trim((string) file_get_contents($marker));

        $this->assertNotSame(
            realpath(getcwd() ?: '.'),
            realpath($seenCwd),
            'the version check must not run in the calling working directory'
        );

        $this->assertSame(
            [],
            array_values(array_diff(scandir($seenCwd) ?: [], ['.', '..'])),
            'the scratch directory must be empty so a stray scan finds nothing'
        );
    }
}
