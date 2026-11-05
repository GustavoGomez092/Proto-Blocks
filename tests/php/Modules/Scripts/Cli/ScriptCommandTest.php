<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Scripts\Cli;

use ProtoBlocks\CLI\ScriptCommand;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\Modules\Scripts\JsoncEdit;
use ProtoBlocks\Modules\Scripts\Registry;
use ProtoBlocks\Modules\Scripts\SnippetFiles;
use ProtoBlocks\Modules\Scripts\Sources\ScriptFiles;
use ProtoBlocks\Tests\Modules\Scripts\ScriptsTestCase;

require_once __DIR__ . '/../../../CLI/wp-stubs.php';
require_once __DIR__ . '/../../../CLI/wp-cli-stub.php';
require_once __DIR__ . '/../../../../../includes/Modules/Scripts/functions.php';

/**
 * `wp proto-blocks script list|create|validate|enable|disable|delete`.
 */
final class ScriptCommandTest extends ScriptsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \WP_CLI::reset();
    }

    private function command(): ScriptCommand
    {
        $root = $this->root;

        return new ScriptCommand(
            new Registry(static fn (): ScriptFiles => new ScriptFiles(static fn (): array => [$root])),
            new SchemaFileWriter([$root], true),
            new SnippetFiles([$root], true)
        );
    }

    /** Runs $fn; returns the error message when it called WP_CLI::error, else null. */
    private static function cli(callable $fn): ?string
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'WP_CLI::error: ')) {
                return substr($e->getMessage(), strlen('WP_CLI::error: '));
            }
            throw $e;
        }

        return null;
    }

    /** @return list<array<string, mixed>> rows of the last format_items call */
    private static function formatted(): array
    {
        $rows = \WP_CLI::messages('format');
        $last = (string) end($rows);

        return (array) json_decode(substr($last, strpos($last, ':') + 1), true);
    }

    public function testList(): void
    {
        $this->script('gtm', ['title' => 'GTM', 'head' => '<meta>', 'bodyClose' => '<script></script>', 'priority' => 3, 'where' => ['exclude' => ['404']]]);
        proto_script('Verify', ['head' => '<meta>', 'enabled' => false]);
        $this->command()->list([], ['format' => 'json']);
        self::assertSame([
            ['slug' => 'gtm', 'title' => 'GTM', 'enabled' => 'yes', 'priority' => 3, 'locations' => 'head,bodyClose', 'where' => 'All pages; except 404', 'origin' => 'file'],
            ['slug' => 'verify', 'title' => 'Verify', 'enabled' => 'no', 'priority' => 10, 'locations' => 'head', 'where' => 'All pages', 'origin' => 'php'],
        ], self::formatted());
    }

    public function testCreate(): void
    {
        self::assertNull(self::cli(fn () => $this->command()->create(['Google Tag Manager'], ['priority' => '5'])));
        $path = $this->root . '/proto-scripts/google-tag-manager/script.jsonc';
        self::assertFileExists($path);
        $data = Jsonc::decode((string) file_get_contents($path));
        self::assertSame('Google Tag Manager', $data['title']);
        self::assertSame(5, $data['priority']);
        self::assertSame(['Created proto-scripts/google-tag-manager/script.jsonc'], \WP_CLI::messages('success'));
    }

    public function testCreateRefusals(): void
    {
        self::assertStringContainsString('Usage', (string) self::cli(fn () => $this->command()->create([''], [])));
        self::assertStringContainsString('not a valid script slug', (string) self::cli(fn () => $this->command()->create(['X'], ['slug' => '../evil'])));
        self::assertStringContainsString('--priority', (string) self::cli(fn () => $this->command()->create(['X'], ['priority' => 'high'])));
        self::assertStringContainsString('--priority', (string) self::cli(fn () => $this->command()->create(['X'], ['priority' => '200000'])));
        $this->script('gtm', ['title' => 'GTM']);
        self::assertStringContainsString('already exists', (string) self::cli(fn () => $this->command()->create(['GTM'], [])));
    }

    public function testValidate(): void
    {
        $this->script('gtm', ['title' => 'GTM']);
        self::assertNull(self::cli(fn () => $this->command()->validate([], [])));
        self::assertSame(['1 script(s) valid.'], \WP_CLI::messages('success'));

        \WP_CLI::reset();
        $this->script('warn', ['title' => 'W', 'head' => ['file' => 'head.html']]);
        self::assertNull(self::cli(fn () => $this->command()->validate([], ['format' => 'json'])));
        self::assertSame(['snippet_file_missing'], array_column(self::formatted(), 'code'));
        self::assertSame('proto-scripts/warn/head.html', self::formatted()[0]['source']);

        \WP_CLI::reset();
        $this->script('bad', ['title' => 'B', 'bodyClose' => '<?php x(); ?>']);
        self::assertSame('1 error(s) in script declarations.', self::cli(fn () => $this->command()->validate([], ['format' => 'json'])));
    }

    public function testEnableDisableKeepComments(): void
    {
        $path = $this->put('proto-scripts/gtm/script.jsonc', "{\n  // keep me\n  \"title\": \"GTM\", /* and me */\n  \"enabled\": true\n}\n");
        self::assertNull(self::cli(fn () => $this->command()->disable(['gtm'], [])));
        self::assertSame("{\n  // keep me\n  \"title\": \"GTM\", /* and me */\n  \"enabled\": false\n}\n", file_get_contents($path));
        self::assertNull(self::cli(fn () => $this->command()->enable(['gtm'], [])));
        self::assertStringContainsString('"enabled": true', (string) file_get_contents($path));
        self::assertSame(['Disabled gtm.', 'Enabled gtm.'], \WP_CLI::messages('success'));
    }

    public function testDisableAddsTheKeyWhenMissing(): void
    {
        $path = $this->put('proto-scripts/gtm/script.jsonc', "// head\n{ \"title\": \"GTM\" }\n");
        self::assertNull(self::cli(fn () => $this->command()->disable(['gtm'], [])));
        $data = Jsonc::decode((string) file_get_contents($path));
        self::assertFalse($data['enabled']);
        self::assertStringStartsWith('// head', (string) file_get_contents($path));
    }

    public function testEnableRefusals(): void
    {
        proto_script('Verify', ['head' => '<meta>']);
        self::assertStringContainsString('declared in PHP', (string) self::cli(fn () => $this->command()->enable(['verify'], [])));
        self::assertStringContainsString('There is no', (string) self::cli(fn () => $this->command()->enable(['nope'], [])));
        self::assertStringContainsString('not a valid', (string) self::cli(fn () => $this->command()->enable(['../x'], [])));
    }

    public function testDelete(): void
    {
        $this->script('gtm', ['title' => 'GTM', 'head' => ['file' => 'head.html']], ['head.html' => '<script></script>']);
        self::assertStringContainsString('--confirm', (string) self::cli(fn () => $this->command()->delete(['gtm'], [])));
        self::assertStringContainsString('--confirm', (string) self::cli(fn () => $this->command()->delete(['gtm'], ['confirm' => 'other'])));
        self::assertFileExists($this->root . '/proto-scripts/gtm/script.jsonc');

        self::assertNull(self::cli(fn () => $this->command()->delete(['gtm'], ['confirm' => 'gtm'])));
        self::assertDirectoryDoesNotExist($this->root . '/proto-scripts/gtm');
        self::assertSame(['Deleted proto-scripts/gtm/script.jsonc'], \WP_CLI::messages('success'));
    }

    public function testDeleteKeepsUnknownFiles(): void
    {
        $this->script('gtm', ['title' => 'GTM'], ['head.html' => 'x', 'README.md' => 'mine']);
        self::assertNull(self::cli(fn () => $this->command()->delete(['gtm'], ['confirm' => 'gtm'])));
        self::assertFileExists($this->root . '/proto-scripts/gtm/README.md');
        self::assertFileExists($this->root . '/proto-scripts/gtm/head.html');
        self::assertSame(['Kept in proto-scripts/gtm: README.md, head.html'], \WP_CLI::messages('warning'));
    }

    public function testJsoncEditEdges(): void
    {
        self::assertSame("{\n  \"enabled\": false\n}", JsoncEdit::setTopLevel('{}', 'enabled', false));
        self::assertSame('{"where":{"enabled":true},"enabled":false}', JsoncEdit::setTopLevel('{"where":{"enabled":true},"enabled":true}', 'enabled', false), 'nested keys are not touched');
        self::assertSame('{"title":"has \"enabled\": true","enabled":false}', JsoncEdit::setTopLevel('{"title":"has \"enabled\": true","enabled":true}', 'enabled', false));
        self::assertSame("{\"a\": [1, {\"b\": 2}], // \"enabled\": true\n\"enabled\": false}", JsoncEdit::setTopLevel("{\"a\": [1, {\"b\": 2}], // \"enabled\": true\n\"enabled\": true}", 'enabled', false));
        $this->expectException(\InvalidArgumentException::class);
        JsoncEdit::setTopLevel('[1]', 'enabled', false);
    }
}
