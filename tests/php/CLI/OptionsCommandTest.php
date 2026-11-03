<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\CLI;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\CLI\OptionsCommand;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\PhpDeclarations;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\OptionsPages\OptionsPagesRegistry;
use ProtoBlocks\Tests\ContentModel\SafeFileTest;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/wp-cli-stub.php';
require_once __DIR__ . '/../ContentModel/SafeFileTest.php';
require_once __DIR__ . '/../../../includes/content-model.php';

/**
 * `wp proto-blocks options list|create` over a temporary stylesheet directory.
 */
final class OptionsCommandTest extends TestCase
{
    private string $root;

    private string $outside;

    protected function setUp(): void
    {
        PhpDeclarations::reset();
        \WP_CLI::reset();
        $this->root = (string) realpath(self::mkTmp('pb-cli-opt-'));
        $this->outside = (string) realpath(self::mkTmp('pb-cli-opt-out-'));
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        SafeFileTest::removeTree($this->root);
        SafeFileTest::removeTree($this->outside);
    }

    private static function mkTmp(string $prefix): string
    {
        $tmp = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($tmp);

        return $tmp;
    }

    private function command(): OptionsCommand
    {
        $declarations = new Registry(new FileLoader(), fn(): array => [$this->root]);

        return new OptionsCommand(new OptionsPagesRegistry($declarations), new SchemaFileWriter([$this->root], true));
    }

    private function put(string $relative, string $contents): string
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);

        return $path;
    }

    /** @return list<string> every file under $dir */
    private static function files(string $dir): array
    {
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }

    private function assertCreateError(array $args, array $assoc, string $needle): void
    {
        try {
            $this->command()->create($args, $assoc);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
            $this->assertSame([], self::files($this->root), 'nothing is written');
            $this->assertSame([], self::files($this->outside));
            return;
        }
        $this->fail('Expected WP_CLI::error');
    }

    public function testValidateSlug(): void
    {
        $this->assertStringContainsString('reserved', (string) OptionsCommand::validateSlug('proto-blocks', []));
        $this->assertNotNull(OptionsCommand::validateSlug('Foo', []));
        $this->assertNotNull(OptionsCommand::validateSlug('../evil', []));
        $this->assertNotNull(OptionsCommand::validateSlug("foo\n", []));
        $this->assertStringContainsString('already exists', (string) OptionsCommand::validateSlug('shop', ['shop']));
        $this->assertNull(OptionsCommand::validateSlug('shop-notices', ['other']));
    }

    public function testCreateNeverOverwrites(): void
    {
        $existing = '// mine' . "\n" . '{ "title": "Shop Notices", "fields": [] }' . "\n";
        $path = $this->put('proto-options/shop-notices/page.jsonc', $existing);

        try {
            $this->command()->create(['Shop Notices'], []);
            $this->fail('Expected WP_CLI::error');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('proto-options/shop-notices/page.jsonc already exists', $e->getMessage());
        }
        $this->assertSame($existing, file_get_contents($path));
    }

    public function testCreateNeverOverwritesAnInvalidFile(): void
    {
        $path = $this->put('proto-options/shop-notices/page.jsonc', '{ broken');

        try {
            $this->command()->create(['Shop Notices'], []);
            $this->fail('Expected WP_CLI::error');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }
        $this->assertSame('{ broken', file_get_contents($path));
    }

    public function testCreateWritesConfinedFile(): void
    {
        $this->assertCreateError(['Evil'], ['slug' => '../evil'], 'must start with a lowercase letter');

        $this->command()->create(['Shop Notices'], ['menu' => 'woocommerce']);

        $file = $this->root . '/proto-options/shop-notices/page.jsonc';
        $this->assertFileExists($file);
        $this->assertSame([$file], self::files($this->root));
        $this->assertStringStartsWith($this->root, (string) realpath($file));
        $this->assertSame([], self::files($this->outside));
        $this->assertStringContainsString('"menu": "woocommerce"', (string) file_get_contents($file));
        $this->assertSame('Created proto-options/shop-notices/page.jsonc', \WP_CLI::messages('success')[0], 'theme-relative, no absolute path');
    }

    public function testCreatedPageListsAfterwards(): void
    {
        $this->command()->create(['Shop Notices'], ['storage-option' => 'shop_notices']);

        $rows = $this->command()->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(
            ['slug' => 'shop-notices', 'title' => 'Shop Notices', 'menu' => 'top', 'capability' => 'manage_options', 'storage' => 'array:shop_notices', 'origin' => 'file'],
            $rows[0]
        );
    }

    public function testCreateRefusesReservedSlug(): void
    {
        $this->assertCreateError(['Options'], [], 'reserved');
    }

    public function testCreateRefusesProtectedStorageOption(): void
    {
        $this->assertCreateError(['Shop Notices'], ['storage-option' => 'siteurl'], 'siteurl');
    }

    public function testCreateRejectsBadCapability(): void
    {
        $this->assertCreateError(['Shop Notices'], ['capability' => 'manage options'], 'capability');
    }

    public function testCreateRefusesWhenFileModsAreDisabled(): void
    {
        $declarations = new Registry(new FileLoader(), fn(): array => [$this->root]);
        $command = new OptionsCommand(new OptionsPagesRegistry($declarations), new SchemaFileWriter([$this->root], false));

        try {
            $command->create(['Shop Notices'], []);
            $this->fail('Expected WP_CLI::error');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('File modifications are disabled', $e->getMessage());
        }
        $this->assertSame([], self::files($this->root));
    }

    public function testCreateRequiresATitle(): void
    {
        $this->assertCreateError([], [], 'Usage');
    }
}
