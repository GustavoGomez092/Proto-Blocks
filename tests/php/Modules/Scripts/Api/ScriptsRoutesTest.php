<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Scripts\Api;

require_once __DIR__ . '/../../../API/ContentModelRoutesTestCase.php';
require_once __DIR__ . '/../../../../../includes/Modules/Scripts/functions.php';

use ProtoBlocks\API\Admin\ScriptsRoutes;
use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\Modules\Scripts\Registry;
use ProtoBlocks\Modules\Scripts\ScriptsModule;
use ProtoBlocks\Modules\Scripts\SnippetFiles;
use ProtoBlocks\Modules\Scripts\Sources\PhpDeclarations;
use ProtoBlocks\Modules\Scripts\Sources\ScriptFiles;
use ProtoBlocks\Tests\API\ContentModelRoutesTestCase;

/**
 * admin/scripts: list, detail and the four-file save; shapes, permissions
 * and the unfiltered_html gate.
 */
final class ScriptsRoutesTest extends ContentModelRoutesTestCase
{
    private ?ScriptsModule $module = null;

    protected function setUp(): void
    {
        parent::setUp();
        PhpDeclarations::reset();
        ScriptsModule::registerFileKind();
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options', 'unfiltered_html']];
        $GLOBALS['pb_test_roles'] = ['administrator' => ['name' => 'Administrator'], 'editor' => ['name' => 'Editor']];
        $this->module = null;
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        ScriptsModule::setInstance(null);
        unset($GLOBALS['pb_test_roles'], $GLOBALS['pb_test_multisite']);
        parent::tearDown();
    }

    private function module(): ScriptsModule
    {
        $root = $this->root;

        return $this->module ??= new ScriptsModule($this->registry(), new Registry(static fn (): ScriptFiles => new ScriptFiles(static fn (): array => [$root])));
    }

    private function routes(?ScriptsModule $module = null, bool $fileMods = true): ScriptsRoutes
    {
        return new ScriptsRoutes($module ?? $this->module(), $this->writer($fileMods), new SnippetFiles([$this->root], $fileMods), $this->registry());
    }

    private static function req(string $slug, ?array $body = null, array $query = []): \WP_REST_Request
    {
        return new \WP_REST_Request(['slug' => $slug] + $query, $body);
    }

    private function gtm(): void
    {
        $this->write('proto-scripts/gtm/script.jsonc', "{\n  // GTM\n  \"title\": \"GTM\",\n  \"priority\": 5,\n  \"head\": { \"file\": \"head.html\" },\n  \"bodyClose\": \"<script>close()</script>\",\n  \"where\": { \"exclude\": [\"page:privacy\"] }\n}\n");
        $this->write('proto-scripts/gtm/head.html', '<script>gtm()</script>');
    }

    public function testRegistersRoutesWithTheRightPermissions(): void
    {
        $writeCalls = 0;
        $this->routes()->registerRoutes('proto-blocks/v1', static fn (): bool => true, static function () use (&$writeCalls) {
            $writeCalls++;
            return true;
        });
        $list = self::route('admin/scripts');
        $one = self::route('admin/scripts/(?P<slug>[a-z][a-z0-9_-]{0,63})');
        self::assertNotNull($list);
        self::assertNotNull($one);
        self::assertSame(['GET'], array_column($list['args'], 'methods'));
        self::assertSame(['GET', 'POST'], array_column($one['args'], 'methods'));

        $write = $one['args'][1]['permission_callback'];
        self::assertTrue($write());
        self::assertSame(1, $writeCalls, 'the inherited write permission runs first');

        $GLOBALS['pb_test_caps'] = [1 => ['manage_options']];
        self::assertWpError('proto_blocks_unfiltered_html', 403, $write());
    }

    public function testWritePermissionKeepsTheInheritedRefusal(): void
    {
        $this->routes()->registerRoutes('proto-blocks/v1', static fn (): bool => true, static fn () => AdminRestController::writeBlockedError());
        $write = self::route('admin/scripts/(?P<slug>[a-z][a-z0-9_-]{0,63})')['args'][1]['permission_callback'];
        self::assertWpError('proto_blocks_file_mods', 403, $write());
    }

    public function testListShape(): void
    {
        $this->gtm();
        proto_script('Verify', ['head' => '<meta>']);
        $line = __LINE__ - 1;

        $list = $this->routes()->index();
        self::assertTrue($list['moduleEnabled']);
        self::assertTrue($list['canWrite']);
        self::assertNull($list['writeReason']);
        self::assertSame([['slug' => 'administrator', 'name' => 'Administrator'], ['slug' => 'editor', 'name' => 'Editor']], $list['roles']);
        self::assertTrue($list['createTarget']['writable']);
        self::assertSame(['gtm', 'verify'], array_column($list['items'], 'slug'));

        $gtm = $list['items'][0];
        self::assertSame('GTM', $gtm['title']);
        self::assertTrue($gtm['enabled']);
        self::assertSame(5, $gtm['priority']);
        self::assertSame(['head', 'bodyClose'], $gtm['locations']);
        self::assertSame(['head' => 'head.html', 'bodyOpen' => null, 'bodyClose' => null], $gtm['files']);
        self::assertSame(['page:privacy'], $gtm['where']['exclude']);
        self::assertSame('All pages; except page:privacy', $gtm['whereSummary']);
        self::assertSame('file', $gtm['origin']);
        self::assertTrue($gtm['editable']);
        self::assertNull($gtm['readOnlyReason']);
        self::assertNull($gtm['double']);

        $verify = $list['items'][1];
        self::assertSame('php', $verify['origin']);
        self::assertFalse($verify['editable']);
        self::assertSame('php', $verify['readOnlyReason']);
        self::assertStringEndsWith('ScriptsRoutesTest.php:' . $line, $verify['source']);
        $this->assertNoAbsolutePaths($list);
    }

    public function testListShowsDoubleAndIssues(): void
    {
        $this->gtm();
        proto_script('GTM', ['head' => '<meta>']);
        $this->write('proto-scripts/broken/script.jsonc', '{ nope');

        $list = $this->routes()->index();
        self::assertSame(['gtm'], array_column($list['items'], 'slug'));
        self::assertNotNull($list['items'][0]['double']);
        self::assertSame(['double_declaration'], array_column($list['items'][0]['issues'], 'code'));
        self::assertEqualsCanonicalizing(['double_declaration', 'invalid_jsonc'], array_column($list['issues'], 'code'));
        $this->assertNoAbsolutePaths($list);
    }

    public function testListWithoutUnfilteredHtml(): void
    {
        $this->gtm();
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options']];
        $list = $this->routes()->index();
        self::assertFalse($list['canWrite']);
        self::assertSame('unfiltered_html', $list['writeReason']);
        self::assertFalse($list['items'][0]['editable']);
        self::assertSame('unfiltered_html', $list['items'][0]['readOnlyReason']);
    }

    public function testListOnMultisiteForASubSiteAdmin(): void
    {
        $GLOBALS['pb_test_multisite'] = true;
        self::assertSame('multisite', $this->routes()->index()['writeReason']);
    }

    public function testListWhenTheModuleIsOff(): void
    {
        ScriptsModule::setInstance(null);
        $routes = new ScriptsRoutes(null, $this->writer(), null, $this->registry());
        $list = $routes->index();
        self::assertFalse($list['moduleEnabled']);
        self::assertSame([], $list['items']);
        self::assertWpError('proto_blocks_module_disabled', 404, $routes->show(self::req('gtm')));
    }

    public function testShowExistingFile(): void
    {
        $this->gtm();
        $one = $this->routes()->show(self::req('gtm'));
        self::assertTrue($one['exists']);
        self::assertStringContainsString('// GTM', $one['text']);
        self::assertSame(SchemaFileWriter::hash($one['text']), $one['hash']);
        self::assertSame(['head' => '<script>gtm()</script>', 'bodyOpen' => '', 'bodyClose' => '<script>close()</script>'], $one['snippets']);
        self::assertSame('gtm', $one['item']['slug']);
        self::assertTrue($one['canWrite']);
        $this->assertNoAbsolutePaths($one);
    }

    public function testShowNewSlugServesTheTemplate(): void
    {
        $one = $this->routes()->show(self::req('analytics', null, ['name' => 'Analytics']));
        self::assertFalse($one['exists']);
        self::assertNull($one['hash']);
        self::assertStringContainsString('"title": "Analytics"', $one['text']);
        self::assertNull($one['item']);
        self::assertSame(['head' => '', 'bodyOpen' => '', 'bodyClose' => ''], $one['snippets']);
    }

    public function testShowOrphanSnippetFileIsReturned(): void
    {
        $this->write('proto-scripts/gtm/script.jsonc', '{"title":"GTM"}');
        $this->write('proto-scripts/gtm/body-open.html', '<noscript>orphan</noscript>');
        self::assertSame('<noscript>orphan</noscript>', $this->routes()->show(self::req('gtm'))['snippets']['bodyOpen']);
    }

    public function testShowPhpOnlyScript(): void
    {
        proto_script('Verify', ['head' => '<meta>']);
        $one = $this->routes()->show(self::req('verify'));
        self::assertFalse($one['exists']);
        self::assertSame('<meta>', $one['snippets']['head']);
        self::assertSame('php', $one['item']['origin']);
    }

    public function testShowRejectsBadSlugs(): void
    {
        foreach (['GTM', '../x', '1a', str_repeat('a', 33)] as $slug) {
            self::assertWpError('proto_blocks_invalid_slug', 400, $this->routes()->show(self::req($slug)));
        }
    }

    public function testSaveWritesTheFourFiles(): void
    {
        $text = "{\n  // mine\n  \"title\": \"GTM\",\n  \"head\": { \"file\": \"head.html\" },\n  \"bodyOpen\": { \"file\": \"body-open.html\" },\n  \"bodyClose\": { \"file\": \"body-close.html\" }\n}\n";
        $saved = $this->routes()->save(self::req('gtm', [
            'text' => $text,
            'hash' => null,
            'snippets' => ['head' => '<script>h()</script>', 'bodyOpen' => '<noscript>o</noscript>', 'bodyClose' => '<script>c()</script>'],
        ]));
        self::assertIsArray($saved, json_encode($saved));
        self::assertTrue($saved['created']);
        self::assertSame(SchemaFileWriter::hash($text), $saved['hash']);
        self::assertSame([], $saved['warnings']);
        $dir = $this->root . '/proto-scripts/gtm';
        self::assertSame($text, file_get_contents($dir . '/script.jsonc'));
        self::assertSame('<script>h()</script>', file_get_contents($dir . '/head.html'));
        self::assertSame('<noscript>o</noscript>', file_get_contents($dir . '/body-open.html'));
        self::assertSame('<script>c()</script>', file_get_contents($dir . '/body-close.html'));
        $this->assertNoAbsolutePaths($saved);

        // Second save with the returned hash; an empty snippet removes its file.
        $text2 = str_replace(",\n  \"bodyClose\": { \"file\": \"body-close.html\" }", '', $text);
        $again = $this->routes()->save(self::req('gtm', ['text' => $text2, 'hash' => $saved['hash'], 'snippets' => ['bodyClose' => '']]));
        self::assertFalse($again['created']);
        self::assertFileDoesNotExist($dir . '/body-close.html');
        self::assertFileExists($dir . '/head.html', 'snippets not posted are left alone');
    }

    public function testSaveConflictsOnAStaleHash(): void
    {
        $this->gtm();
        $error = $this->routes()->save(self::req('gtm', ['text' => '{"title":"x"}', 'hash' => 'stale', 'snippets' => ['head' => 'new']]));
        self::assertWpError('proto_blocks_conflict', 409, $error);
        self::assertSame('<script>gtm()</script>', file_get_contents($this->root . '/proto-scripts/gtm/head.html'), 'nothing written');
    }

    public function testSaveChecksEverySnippetBeforeWriting(): void
    {
        $error = $this->routes()->save(self::req('gtm', ['text' => '{"title":"GTM"}', 'hash' => null, 'snippets' => ['head' => '<meta>', 'bodyClose' => '<?php evil(); ?>']]));
        self::assertWpError('proto_blocks_php_in_snippet', 400, $error);
        self::assertSame('bodyClose', $error->get_error_data()['location']);
        self::assertDirectoryDoesNotExist($this->root . '/proto-scripts/gtm');

        $error = $this->routes()->save(self::req('gtm', ['text' => '{"title":"GTM"}', 'hash' => null, 'snippets' => ['head' => str_repeat('a', 262145)]]));
        self::assertWpError('proto_blocks_file_too_large', 413, $error);
        self::assertDirectoryDoesNotExist($this->root . '/proto-scripts/gtm');
    }

    public function testSaveRefusesBadMetadata(): void
    {
        self::assertWpError('proto_blocks_schema_invalid', 400, $this->routes()->save(self::req('gtm', ['text' => '{"title":"x","head":"<?= 1 ?>"}', 'hash' => null])));
        self::assertWpError('proto_blocks_invalid_jsonc', 400, $this->routes()->save(self::req('gtm', ['text' => '{ nope', 'hash' => null])));
        self::assertDirectoryDoesNotExist($this->root . '/proto-scripts/gtm');
    }

    public function testSaveRefusesMalformedRequests(): void
    {
        foreach ([
            ['hash' => null],
            ['text' => 1, 'hash' => null],
            ['text' => '{"title":"x"}', 'hash' => 5],
            ['text' => '{"title":"x"}', 'hash' => null, 'snippets' => 'x'],
            ['text' => '{"title":"x"}', 'hash' => null, 'snippets' => ['footer' => 'x']],
            ['text' => '{"title":"x"}', 'hash' => null, 'snippets' => ['head' => 1]],
            ['text' => '{"title":"x"}', 'hash' => null, 'snippets' => ['x']],
        ] as $body) {
            self::assertWpError('proto_blocks_invalid_request', 400, $this->routes()->save(self::req('gtm', $body)), json_encode($body));
        }
    }

    public function testSaveNeedsUnfilteredHtmlEvenWhenCalledDirectly(): void
    {
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options']];
        self::assertWpError('proto_blocks_unfiltered_html', 403, $this->routes()->save(self::req('gtm', ['text' => '{"title":"x"}', 'hash' => null])));
    }

    public function testSaveWithFileModsOff(): void
    {
        self::assertWpError('proto_blocks_file_mods', 403, $this->routes(null, false)->save(self::req('gtm', ['text' => '{"title":"x"}', 'hash' => null])));
    }

    public function testSaveReturnsWarnings(): void
    {
        $saved = $this->routes()->save(self::req('gtm', ['text' => '{"title":"x","head":{"file":"head.html"},"where":{"include":[]}}', 'hash' => null]));
        self::assertEqualsCanonicalizing(['snippet_file_missing', 'never_matches'], array_values(array_unique(array_column($saved['warnings'], 'code'))));
        $this->assertNoAbsolutePaths($saved);
    }

    public function testSaveIgnoresABodySlug(): void
    {
        $request = new \WP_REST_Request(['slug' => 'gtm'], ['slug' => 'other', 'text' => '{"title":"x"}', 'hash' => null]);
        $saved = $this->routes()->save($request);
        self::assertIsArray($saved);
        self::assertFileExists($this->root . '/proto-scripts/gtm/script.jsonc');
        self::assertDirectoryDoesNotExist($this->root . '/proto-scripts/other');
    }

    public function testSnippetFailureAfterTheMetadataSaveReturnsTheNewHash(): void
    {
        $this->gtm();
        $outside = sys_get_temp_dir() . '/pb-scripts-victim-' . bin2hex(random_bytes(4));
        file_put_contents($outside, 'keep');
        symlink($outside, $this->root . '/proto-scripts/gtm/body-open.html');
        try {
            $text = (string) file_get_contents($this->root . '/proto-scripts/gtm/script.jsonc');
            $error = $this->routes()->save(self::req('gtm', ['text' => $text . ' ', 'hash' => SchemaFileWriter::hash($text), 'snippets' => ['bodyOpen' => '<noscript></noscript>']]));
            self::assertWpError('proto_blocks_path_outside_root', 403, $error);
            self::assertSame(SchemaFileWriter::hash($text . ' '), $error->get_error_data()['hash']);
            self::assertSame('bodyOpen', $error->get_error_data()['location']);
            self::assertSame('keep', file_get_contents($outside), 'the link target is untouched');
        } finally {
            @unlink($outside);
        }
    }
}
