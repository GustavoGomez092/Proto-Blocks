<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Tailwind;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Tailwind\BinaryManager;
use ProtoBlocks\Tailwind\Cache;
use ProtoBlocks\Tailwind\Compiler;
use ProtoBlocks\Tailwind\ConfigEditor;
use ProtoBlocks\Tailwind\Scanner;
use ProtoBlocks\Tailwind\Scoper;

/**
 * A CLI compile dumps the raw PHP source of every block template into
 * content.html so Tailwind can scan it. That file (and input.css) must live
 * in a guarded work directory and must be gone once the compile finishes,
 * whether or not it succeeded.
 *
 * The "binary" is a shell script standing in for the Tailwind CLI, so no
 * network and no real Tailwind are involved.
 */
final class CompilerInputsCleanupTest extends TestCase
{
    private string $root;
    private string $binDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        if (!function_exists('proc_open') && !function_exists('exec')) {
            $this->markTestSkipped('Neither proc_open() nor exec() is available.');
        }

        $this->root = sys_get_temp_dir() . '/pb-compile-' . uniqid() . '/';
        $this->binDir = $this->root . 'bin/';
        $this->cacheDir = $this->root . 'tailwind/';
        mkdir($this->binDir, 0777, true);
        mkdir($this->cacheDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    private function removeTree(string $dir): void
    {
        $dir = rtrim($dir, DIRECTORY_SEPARATOR);
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) && !is_link($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Fake tailwindcss: reports whether the inputs exist while it runs, then
     * either writes a stylesheet to --output or fails.
     */
    private function writeFakeBinary(bool $succeed): BinaryManager
    {
        $script = "#!/bin/sh\n"
            . "in=''; out=''\n"
            . "while [ \$# -gt 0 ]; do\n"
            . "  case \"\$1\" in\n"
            . "    --input) in=\"\$2\"; shift ;;\n"
            . "    --output) out=\"\$2\"; shift ;;\n"
            . "    --cwd) shift ;;\n"
            . "  esac\n"
            . "  shift\n"
            . "done\n"
            . "[ -f \"\$in\" ] && echo input-present\n"
            . "[ -f \"\$(dirname \"\$in\")/content.html\" ] && echo content-present\n"
            . "[ -f \"\$(dirname \"\$in\")/.htaccess\" ] && echo work-guarded\n"
            . ($succeed
                ? "printf '.flex{display:flex}' > \"\$out\"\nexit 0\n"
                : "echo 'Error: CSS syntax error' >&2\nexit 1\n");

        $path = $this->binDir . 'tailwindcss';
        file_put_contents($path, $script);
        chmod($path, 0755);

        return new BinaryManager($this->binDir, (string) hash_file('sha256', $path));
    }

    private function makeCompiler(BinaryManager $bm, Cache $cache): Compiler
    {
        $scanner = $this->createMock(Scanner::class);
        $scanner->method('writeContentFile')->willReturnCallback(
            static fn(string $p): bool => Cache::atomicWrite($p, "<div class=\"flex\"><?php echo \$secret; ?></div>")
        );
        $scanner->method('getContentHash')->willReturn('abc123');

        $config = $this->createMock(ConfigEditor::class);
        $config->method('generateInputCss')->willReturn("@import \"tailwindcss\";\n@source \"content.html\";\n");

        return new Compiler($bm, $scanner, $cache, new Scoper(), $config);
    }

    public function test_inputs_exist_during_compile_and_are_removed_after_success(): void
    {
        $cache = new Cache($this->cacheDir);
        $compiler = $this->makeCompiler($this->writeFakeBinary(true), $cache);

        $result = $compiler->compile();

        $this->assertTrue($result['success'], $result['message']);
        // The binary saw both inputs, inside a guarded directory...
        $this->assertStringContainsString('input-present', $result['output']);
        $this->assertStringContainsString('content-present', $result['output']);
        $this->assertStringContainsString('work-guarded', $result['output']);
        // ...and they are gone afterwards.
        $this->assertFileDoesNotExist($cache->getContentPath());
        $this->assertFileDoesNotExist($cache->getInputPath());
        // Output is in place, scoped, and the hash was recorded.
        $css = (string) file_get_contents($cache->getCssPath());
        $this->assertStringContainsString('.' . Scoper::SCOPE_CLASS, $css);
        $this->assertStringContainsString('display:flex', $css);
        $this->assertSame('abc123', $cache->getStoredHash());
        // No template source leaked into the output directory.
        $this->assertStringNotContainsString('$secret', $css);
    }

    public function test_inputs_are_removed_after_a_failed_compile(): void
    {
        $cache = new Cache($this->cacheDir);
        $compiler = $this->makeCompiler($this->writeFakeBinary(false), $cache);

        $result = $compiler->compile();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('exit code 1', $result['message']);
        $this->assertFileDoesNotExist($cache->getContentPath());
        $this->assertFileDoesNotExist($cache->getInputPath());
        $this->assertFileDoesNotExist($cache->getCssPath());
    }

    public function test_output_and_work_directories_are_guarded(): void
    {
        $cache = new Cache($this->cacheDir);
        $this->makeCompiler($this->writeFakeBinary(true), $cache)->compile();

        // Output dir: deny everything except *.css (tailwind.css must stay enqueueable).
        $this->assertFileExists($this->cacheDir . 'index.php');
        $out = (string) file_get_contents($this->cacheDir . '.htaccess');
        $this->assertStringContainsString('Require all denied', $out);
        $this->assertStringContainsString('<FilesMatch "\.css$">', $out);
        $this->assertStringContainsString('Require all granted', $out);

        // Work dir: deny all.
        $this->assertFileExists($cache->getWorkDir() . 'index.php');
        $work = (string) file_get_contents($cache->getWorkDir() . '.htaccess');
        $this->assertStringContainsString('Require all denied', $work);
        $this->assertStringNotContainsString('granted', $work);

        // The enqueued URL still points at the public stylesheet.
        $this->assertStringEndsWith('/proto-blocks/tailwind/tailwind.css', (new Cache())->getUrl());
    }

    public function test_untrusted_binary_is_never_executed(): void
    {
        $cache = new Cache($this->cacheDir);
        $this->writeFakeBinary(true);
        $marker = $this->binDir . 'ran';
        file_put_contents($this->binDir . 'tailwindcss', "#!/bin/sh\ntouch " . escapeshellarg($marker) . "\nexit 0\n");

        // Hash recorded for the ORIGINAL script, binary since replaced.
        $bm = new BinaryManager($this->binDir, hash('sha256', 'something-else'));
        $result = $this->makeCompiler($bm, $cache)->compile();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('checksum', $result['message']);
        $this->assertFileDoesNotExist($marker);
        $this->assertFileDoesNotExist($cache->getContentPath());
    }

    // --- npx fallback gate --------------------------------------------------

    public function test_npx_fallback_is_off_by_default(): void
    {
        $this->assertFalse(defined('PROTO_BLOCKS_TAILWIND_ALLOW_NPX'), 'test suite must not define the opt-in constant');
        $this->assertFalse(Compiler::isNpxFallbackAllowed());
        // Even a clearly unrunnable binary must not reach npx without the opt-in.
        $this->assertFalse(Compiler::shouldTryNpxFallback(126, 'cannot execute binary file'));
        $this->assertFalse(Compiler::shouldTryNpxFallback(127, ''));
    }

    public function test_only_an_unrunnable_binary_qualifies_for_fallback(): void
    {
        $this->assertTrue(Compiler::looksLikeUnrunnableBinary(126, ''));
        $this->assertTrue(Compiler::looksLikeUnrunnableBinary(127, ''));
        $this->assertTrue(Compiler::looksLikeUnrunnableBinary(1, 'sh: ./tailwindcss: Exec format error'));
        $this->assertTrue(Compiler::looksLikeUnrunnableBinary(1, 'Bad CPU type in executable'));

        // A Tailwind/CSS error is NOT a reason to install a package from npm.
        $this->assertFalse(Compiler::looksLikeUnrunnableBinary(1, 'Error: Unexpected token at line 12'));
        $this->assertFalse(Compiler::looksLikeUnrunnableBinary(1, 'CssSyntaxError: Unclosed block'));
    }

    public function test_npx_package_is_pinned_to_an_exact_version(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Compiler::NPX_CLI_VERSION);
    }
}
