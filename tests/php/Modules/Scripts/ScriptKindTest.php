<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Scripts;

require_once __DIR__ . '/../../API/ContentModelRoutesTestCase.php';

use ProtoBlocks\API\Admin\SchemaFilesRoutes;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\WriteException;
use ProtoBlocks\Modules\Scripts\Definition\ScriptValidator;
use ProtoBlocks\Modules\Scripts\Registry;
use ProtoBlocks\Modules\Scripts\ScriptsModule;
use ProtoBlocks\Modules\Scripts\ScriptTemplate;
use ProtoBlocks\Modules\Scripts\Sources\PhpDeclarations;
use ProtoBlocks\Tests\API\ContentModelRoutesTestCase;

/**
 * The `script` kind inside the content-model machinery: extendKind, slug
 * grammar, the template, SchemaFileWriter writes and the schema-files
 * routes, plus the module's trust guard and delete cleanup around them.
 */
final class ScriptKindTest extends ContentModelRoutesTestCase
{
    private const ROUTE = '/proto-blocks/v1/admin/schema-files/script/';

    protected function setUp(): void
    {
        parent::setUp();
        PhpDeclarations::reset();
        ScriptsModule::registerFileKind();
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options', 'unfiltered_html']];
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        parent::tearDown();
    }

    private function routes(): SchemaFilesRoutes
    {
        return new SchemaFilesRoutes($this->registry(), null, $this->writer(), $this->guardrails(), $this->scaffolder());
    }

    private function module(): ScriptsModule
    {
        return new ScriptsModule($this->registry(), new Registry());
    }

    /**
     * Runs a schema-files call through the module's two REST filters, as WordPress would.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @return mixed
     */
    private function dispatch(string $method, string $slug, array $body = [], array $query = [])
    {
        $request = new \WP_REST_Request(array_merge(['kind' => 'script', 'slug' => $slug], $query), $body === [] ? null : $body);
        $request->set_method($method);
        $request->set_route(self::ROUTE . $slug);
        $module = $this->module();

        $response = $module->guardSchemaFiles(null, [], $request);
        if (!is_wp_error($response)) {
            $routes = $this->routes();
            $response = match ($method) {
                'GET' => $routes->get($request),
                'DELETE' => $routes->delete($request),
                default => $routes->save($request),
            };
        }

        return $module->afterSchemaFiles($response, [], $request);
    }

    public function testKindIsRegistered(): void
    {
        self::assertContains('script', SchemaFileWriter::extendedKinds());
        self::assertSame('proto-scripts', SchemaFileWriter::extendedFolder('script'));
        ScriptsModule::registerFileKind();
        self::assertSame(1, count(array_filter(SchemaFileWriter::extendedKinds(), static fn (string $k): bool => $k === 'script')), 'idempotent');
    }

    public function testSlugGrammar(): void
    {
        self::assertNull(SchemaFileWriter::slugError('script', 'gtm'));
        self::assertNull(SchemaFileWriter::slugError('script', 'google_tag-manager2'));
        foreach (['Gtm', '1gtm', '-gtm', '../x', "gtm\n", str_repeat('a', 33), ''] as $bad) {
            self::assertSame('pattern', SchemaFileWriter::slugError('script', $bad), json_encode($bad));
        }
    }

    public function testRoutePatternAcceptsTheKind(): void
    {
        $this->routes()->registerRoutes('proto-blocks/v1', static fn (): bool => true, static fn (): bool => true);
        $pattern = null;
        foreach ($GLOBALS['pb_test_routes'] as $entry) {
            if (str_starts_with(ltrim((string) $entry['route'], '/'), 'admin/schema-files/')) {
                $pattern = ltrim((string) $entry['route'], '/');
            }
        }
        self::assertNotNull($pattern);
        self::assertSame(1, preg_match('@^' . $pattern . '$@i', 'admin/schema-files/script/gtm'));
        self::assertSame(0, preg_match('@^' . $pattern . '$@i', 'admin/schema-files/script/../x'));
    }

    public function testTemplateIsValidAndServedForANewFile(): void
    {
        $text = ScriptTemplate::render('Google <Tag> Manager');
        $data = Jsonc::decode($text);
        self::assertSame([], ScriptValidator::validate($data)['errors']);
        self::assertSame('Google Tag Manager', $data['title']);

        $file = $this->dispatch('GET', 'gtm', [], ['name' => 'Google Tag Manager']);
        self::assertFalse($file['exists']);
        self::assertNull($file['hash']);
        self::assertStringContainsString('"title": "Google Tag Manager"', $file['text']);
        self::assertSame('proto-scripts/gtm/script.jsonc', substr($file['path'], -strlen('proto-scripts/gtm/script.jsonc')));
    }

    public function testWriterValidatesScriptFiles(): void
    {
        $writer = $this->writer();
        $ok = $writer->write('script', 'gtm', '{"title":"GTM","head":"<meta>"}', null, null);
        self::assertSame($this->root . '/proto-scripts/gtm/script.jsonc', $ok['path']);

        foreach ([
            '{"title":"x","head":"<?php echo 1; ?>"}',
            '{"title":"x","head":{"file":"../../functions.php"}}',
            '{"title":"x","register_meta_box_cb":"evil"}',
            '{"title":"<b>x</b>"}',
            '{"title":"x","where":{"include":["everywhere"]}}',
        ] as $text) {
            try {
                $writer->write('script', 'other', $text, null, null);
                self::fail('accepted ' . $text);
            } catch (WriteException $e) {
                self::assertSame('schema_invalid', $e->errorCode, $text);
            }
        }
        self::assertDirectoryDoesNotExist($this->root . '/proto-scripts/other');
    }

    public function testSchemaFilesSaveNeedsUnfilteredHtml(): void
    {
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options']];
        $error = $this->dispatch('POST', 'gtm', ['text' => '{"title":"GTM"}', 'hash' => null]);
        self::assertWpError('proto_blocks_unfiltered_html', 403, $error);
        self::assertFileDoesNotExist($this->root . '/proto-scripts/gtm/script.jsonc');

        $GLOBALS['pb_test_caps'] = [1 => ['manage_options', 'unfiltered_html']];
        $saved = $this->dispatch('POST', 'gtm', ['text' => '{"title":"GTM"}', 'hash' => null]);
        self::assertIsArray($saved);
        self::assertTrue($saved['created']);
    }

    public function testReadsDoNotNeedUnfilteredHtml(): void
    {
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options']];
        $file = $this->dispatch('GET', 'gtm');
        self::assertIsArray($file);
    }

    public function testSchemaFilesDeleteNeedsUnfilteredHtml(): void
    {
        $this->write('proto-scripts/gtm/script.jsonc', '{"title":"GTM"}');
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options']];
        self::assertWpError('proto_blocks_unfiltered_html', 403, $this->dispatch('DELETE', 'gtm', [], ['confirm' => 'gtm']));
        self::assertFileExists($this->root . '/proto-scripts/gtm/script.jsonc');
    }

    public function testDeleteRemovesSnippetsAndFolderWhenOnlyKnownFiles(): void
    {
        $this->write('proto-scripts/gtm/script.jsonc', '{"title":"GTM","head":{"file":"head.html"}}');
        $this->write('proto-scripts/gtm/head.html', '<script>1</script>');
        $this->write('proto-scripts/gtm/body-close.html', '<script>2</script>');

        $result = $this->dispatch('DELETE', 'gtm', [], ['confirm' => 'gtm']);
        self::assertIsArray($result);
        self::assertSame([], $result['kept']);
        self::assertDirectoryDoesNotExist($this->root . '/proto-scripts/gtm');
    }

    public function testDeleteKeepsSnippetsWhenOtherFilesAreThere(): void
    {
        $this->write('proto-scripts/gtm/script.jsonc', '{"title":"GTM"}');
        $this->write('proto-scripts/gtm/head.html', '<script>1</script>');
        $this->write('proto-scripts/gtm/notes.txt', 'mine');

        $result = $this->dispatch('DELETE', 'gtm', [], ['confirm' => 'gtm']);
        self::assertSame(['head.html', 'notes.txt'], $result['kept']);
        self::assertFileDoesNotExist($this->root . '/proto-scripts/gtm/script.jsonc');
        self::assertFileExists($this->root . '/proto-scripts/gtm/head.html');
        self::assertFileExists($this->root . '/proto-scripts/gtm/notes.txt');
    }

    public function testDeleteOfAnInvalidScriptKeepsTheSnippets(): void
    {
        $this->write('proto-scripts/gtm/script.jsonc', '{ broken');
        $this->write('proto-scripts/gtm/head.html', '<script>1</script>');

        $result = $this->dispatch('DELETE', 'gtm', [], ['confirm' => 'gtm']);
        self::assertSame(['head.html'], $result['kept']);
        self::assertFileExists($this->root . '/proto-scripts/gtm/head.html');
    }

    public function testUnconfirmedDeleteTouchesNothing(): void
    {
        $this->write('proto-scripts/gtm/script.jsonc', '{"title":"GTM"}');
        $this->write('proto-scripts/gtm/head.html', '<script>1</script>');
        self::assertWpError('proto_blocks_confirm_required', 409, $this->dispatch('DELETE', 'gtm'));
        self::assertFileExists($this->root . '/proto-scripts/gtm/script.jsonc');
        self::assertFileExists($this->root . '/proto-scripts/gtm/head.html');
    }

    public function testGuardIgnoresOtherRoutes(): void
    {
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options']];
        $request = new \WP_REST_Request('POST', '/proto-blocks/v1/admin/schema-files/type/product');
        self::assertNull($this->module()->guardSchemaFiles(null, [], $request));
        $request = new \WP_REST_Request('POST', '/proto-blocks/v1/admin/schema-files/scripts/x');
        self::assertNull($this->module()->guardSchemaFiles(null, [], $request));
        $request = new \WP_REST_Request('POST', '/proto-blocks/v1/admin/SCHEMA-FILES/SCRIPT/gtm');
        self::assertWpError('proto_blocks_unfiltered_html', 403, $this->module()->guardSchemaFiles(null, [], $request), 'routes match case-insensitively');
    }

    public function testGuardKeepsAnEarlierError(): void
    {
        $error = new \WP_Error('x', 'y', ['status' => 400]);
        $request = new \WP_REST_Request('POST', self::ROUTE . 'gtm');
        self::assertSame($error, $this->module()->guardSchemaFiles($error, [], $request));
    }
}
