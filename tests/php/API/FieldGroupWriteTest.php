<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\API;

use ProtoBlocks\API\Admin\SchemaFilesRoutes;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\WriteException;
use ProtoBlocks\Modules\Fields\FieldsModule;
use ProtoBlocks\Tests\Modules\Fields\FieldsTestCase;

require_once __DIR__ . '/../Modules/Fields/FieldsTestCase.php';
require_once __DIR__ . '/../Admin/wp-stubs.php';
require_once __DIR__ . '/../CLI/wp-stubs.php';
require_once __DIR__ . '/../ContentModel/stubs/hooks.php';
require_once __DIR__ . '/content-model-stubs.php';

/**
 * The `field-group` kind (C5 extendKind): builder writes go through the
 * Phase 1 writer and the schema-files routes with every Phase 1 rule.
 */
final class FieldGroupWriteTest extends FieldsTestCase
{
    private const GROUP = <<<'JSONC'
{
  // Speaker details
  "key": "group_speaker",
  "title": "Speaker",
  "fields": [
    { "key": "field_company", "name": "company", "label": "Company", "type": "text" }
  ],
  "location": [[{ "param": "post_type", "operator": "==", "value": "speaker" }]]
}
JSONC;

    private string $root = '';
    private string $outside = '';

    protected function setUp(): void
    {
        parent::setUp();
        SchemaFileWriter::resetKinds();
        FieldsModule::registerFileKind();
        $this->root = self::tempDir('pb-fg-root');
        $this->outside = self::tempDir('pb-fg-outside');
        $GLOBALS['pb_test_theme'] = [
            'template' => $this->root,
            'stylesheet' => $this->root,
            'template_uri' => 'http://example.test/t',
            'stylesheet_uri' => 'http://example.test/t',
        ];
        $GLOBALS['pb_test_current_user'] = 1;
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options']];
    }

    protected function tearDown(): void
    {
        SchemaFileWriter::resetKinds();
        self::removeTree($this->root);
        self::removeTree($this->outside);
        parent::tearDown();
    }

    private static function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(6));
        mkdir($dir);

        return (string) realpath($dir);
    }

    private static function removeTree(string $path): void
    {
        if ($path === '') {
            return;
        }
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    private function writer(bool $fileMods = true): SchemaFileWriter
    {
        return new SchemaFileWriter([$this->root], $fileMods);
    }

    private function routes(bool $fileMods = true): SchemaFilesRoutes
    {
        $registry = new Registry(new FileLoader(), fn(): array => [$this->root]);

        return new SchemaFilesRoutes($registry, null, $this->writer($fileMods));
    }

    private function refused(callable $write): WriteException
    {
        try {
            $write();
        } catch (WriteException $e) {
            return $e;
        }
        self::fail('expected a WriteException');
    }

    /** @param mixed $result */
    private static function assertWpError(string $code, int $status, $result): \WP_Error
    {
        self::assertInstanceOf(\WP_Error::class, $result, 'expected ' . $code . ', got ' . json_encode($result));
        self::assertSame($code, $result->get_error_code(), $result->get_error_message());
        self::assertSame($status, $result->get_error_data()['status']);

        return $result;
    }

    public function testValidGroupIsWrittenAtomically(): void
    {
        $result = $this->writer()->write('field-group', 'speaker', self::GROUP, null, null);

        $path = $this->root . '/proto-fields/speaker/group.jsonc';
        self::assertSame($path, $result['path']);
        self::assertTrue($result['created']);
        self::assertSame(self::GROUP, file_get_contents($path));
        self::assertSame(['group.jsonc'], array_values(array_diff(scandir(dirname($path)) ?: [], ['.', '..'])), 'no temporary file left behind');
        self::assertSame('group_speaker', $result['data']['key']);
    }

    public function testInvalidSlugsWriteNothing(): void
    {
        foreach (['../x', 'a/b', 'Speaker', "speaker\n", '', '1abc', str_repeat('a', 33)] as $slug) {
            $e = $this->refused(fn() => $this->writer()->write('field-group', $slug, self::GROUP, null, null));
            self::assertSame('invalid_slug', $e->errorCode, json_encode($slug));
            self::assertSame(400, $e->status);
        }
        self::assertDirectoryDoesNotExist($this->root . '/proto-fields');
    }

    public function testGroupValidatorRunsBeforeTheWrite(): void
    {
        $text = str_replace('"name": "company"', '"name": "_x"', self::GROUP);
        $e = $this->refused(fn() => $this->writer()->write('field-group', 'speaker', $text, null, null));

        self::assertSame('schema_invalid', $e->errorCode);
        self::assertSame(400, $e->status);
        self::assertSame('fields[0].name', $e->data['errors'][0]['path']);
        self::assertDirectoryDoesNotExist($this->root . '/proto-fields');
    }

    public function testFileModsOff(): void
    {
        $e = $this->refused(fn() => $this->writer(false)->write('field-group', 'speaker', self::GROUP, null, null));
        self::assertSame('file_mods', $e->errorCode);
        self::assertSame(403, $e->status);
    }

    public function testSymlinkedFolderOutsideTheRoot(): void
    {
        mkdir($this->root . '/proto-fields');
        symlink($this->outside, $this->root . '/proto-fields/x');

        $e = $this->refused(fn() => $this->writer()->write('field-group', 'x', self::GROUP, null, null));
        self::assertSame('path_outside_root', $e->errorCode);
        self::assertSame(403, $e->status);
        self::assertSame([], array_values(array_diff(scandir($this->outside) ?: [], ['.', '..'])));
    }

    public function testOversizeBody(): void
    {
        $text = '{"key": "g", "title": "' . str_repeat('a', 300 * 1024) . '", "fields": []}';
        $e = $this->refused(fn() => $this->writer()->write('field-group', 'speaker', $text, null, null));
        self::assertSame('file_too_large', $e->errorCode);
        self::assertSame(413, $e->status);
    }

    public function testHashConflict(): void
    {
        $writer = $this->writer();
        $first = $writer->write('field-group', 'speaker', self::GROUP, null, null);
        $existing = $writer->existing('field-group', 'speaker');
        self::assertNotNull($existing);

        $e = $this->refused(fn() => $writer->write('field-group', 'speaker', self::GROUP, 'stale', $existing));
        self::assertSame('conflict', $e->errorCode);
        self::assertSame(409, $e->status);

        $second = $writer->write('field-group', 'speaker', self::GROUP . "\n", $first['hash'], $existing);
        self::assertFalse($second['created']);
    }

    public function testAcfExportListIsWritable(): void
    {
        $text = (string) file_get_contents(__DIR__ . '/../../fixtures/fields/acf-export-realistic.json');
        $result = $this->writer()->write('field-group', 'events', $text, null, null);

        self::assertSame($this->root . '/proto-fields/events/group.jsonc', $result['path']);
        self::assertSame('group_6543a1b2c3d4e', $result['data'][0]['key']);
    }

    /**
     * Ruling: declarations are files edited by manage_options users; the
     * per-user write trust (unfiltered_html) applies to field VALUES, not
     * to declaring a `code` field.
     */
    public function testCodeFieldDeclarationNeedsNoUnfilteredHtml(): void
    {
        $text = str_replace('"type": "text"', '"type": "code"', self::GROUP);
        $result = $this->writer()->write('field-group', 'speaker', $text, null, null);

        self::assertSame('code', $result['data']['fields'][0]['type']);
    }

    public function testExtendKindRefusesUnsafeNames(): void
    {
        $validate = static fn(array $data): array => [];
        foreach ([['type', 'x', 'y'], ['../x', 'x', 'y'], ['other', 'proto-types', 'y'], ['other', '../up', 'y'], ['other', 'proto-fields', 'y'], ['other', 'z', 'a/b']] as [$kind, $folder, $basename]) {
            try {
                SchemaFileWriter::extendKind($kind, $folder, $basename, $validate);
                self::fail("accepted $kind $folder $basename");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(['field-group'], SchemaFileWriter::extendedKinds());
        self::assertFalse(isset(FileLoader::FOLDERS['field-group']), 'not a Registry kind');
    }

    public function testRouteRegexIncludesTheExtendedKind(): void
    {
        $GLOBALS['pb_test_routes'] = [];
        $this->routes()->registerRoutes('proto-blocks/v1', static fn(): bool => true, static fn(): bool => true);

        $route = null;
        foreach ($GLOBALS['pb_test_routes'] as $entry) {
            if (str_contains((string) $entry['route'], 'schema-files')) {
                $route = ltrim((string) $entry['route'], '/');
            }
        }
        self::assertNotNull($route);
        $match = static fn(string $path): bool => preg_match('@^' . $route . '$@i', $path) === 1;

        self::assertTrue($match('admin/schema-files/field-group/speaker'));
        self::assertTrue($match('admin/schema-files/type/product'));
        foreach (['admin/schema-files/field-group/../x', 'admin/schema-files/field-group/a/b', 'admin/schema-files/field-groupx/a', 'admin/schema-files/field-group/'] as $path) {
            self::assertFalse($match($path), $path);
        }
    }

    public function testRoutesRefuseBadSlugsBeforeAnyPath(): void
    {
        $routes = $this->routes();
        foreach (['Speaker', "speaker\n", 'a..b', str_repeat('a', 33)] as $slug) {
            $params = ['kind' => 'field-group', 'slug' => $slug];
            self::assertWpError('proto_blocks_invalid_slug', 400, $routes->get(new \WP_REST_Request($params)));
            self::assertWpError('proto_blocks_invalid_slug', 400, $routes->save(new \WP_REST_Request($params, ['text' => self::GROUP, 'hash' => null])));
            self::assertWpError('proto_blocks_invalid_slug', 400, $routes->delete(new \WP_REST_Request($params)));
        }
        self::assertWpError('proto_blocks_invalid_slug', 400, $routes->get(new \WP_REST_Request(['kind' => 'FIELD-GROUP', 'slug' => 'speaker'])));
        self::assertDirectoryDoesNotExist($this->root . '/proto-fields');
    }

    public function testRoutesReadSaveAndDelete(): void
    {
        $routes = $this->routes();
        $params = ['kind' => 'field-group', 'slug' => 'speaker'];

        $new = $routes->get(new \WP_REST_Request($params));
        self::assertFalse($new['exists']);
        self::assertNull($new['hash']);
        self::assertTrue($new['writable']);
        self::assertStringEndsWith('proto-fields/speaker/group.jsonc', $new['path']);
        self::assertStringNotContainsString($this->root, $new['path']);
        self::assertSame('group_speaker', $new['data']['key'], 'the template is a valid group');

        $saved = $routes->save(new \WP_REST_Request($params, ['text' => self::GROUP, 'hash' => null, 'acknowledge' => []]));
        self::assertIsArray($saved, json_encode($saved));
        self::assertTrue($saved['created']);
        self::assertStringNotContainsString($this->root, $saved['path']);

        $stale = $routes->save(new \WP_REST_Request($params, ['text' => self::GROUP, 'hash' => null, 'acknowledge' => []]));
        self::assertWpError('proto_blocks_conflict', 409, $stale);

        $loaded = $routes->get(new \WP_REST_Request($params));
        self::assertTrue($loaded['exists']);
        self::assertSame(self::GROUP, $loaded['text']);
        self::assertSame($saved['hash'], $loaded['hash']);

        $invalid = $routes->save(new \WP_REST_Request($params, ['text' => '{"key": "g"}', 'hash' => $saved['hash']]));
        self::assertWpError('proto_blocks_schema_invalid', 400, $invalid);

        // Delete always asks for the typed slug: stored values become orphaned.
        $unconfirmed = $routes->delete(new \WP_REST_Request($params));
        self::assertWpError('proto_blocks_confirm_required', 409, $unconfirmed);
        self::assertFileExists($this->root . '/proto-fields/speaker/group.jsonc');

        $deleted = $routes->delete(new \WP_REST_Request($params + ['confirm' => 'speaker']));
        self::assertIsArray($deleted, json_encode($deleted));
        self::assertStringEndsWith('proto-fields/speaker/group.jsonc', $deleted['deleted']);
        self::assertDirectoryDoesNotExist($this->root . '/proto-fields/speaker');

        self::assertWpError('proto_blocks_not_found', 404, $routes->delete(new \WP_REST_Request($params + ['confirm' => 'speaker'])));
    }

    public function testRoutesRespectFileMods(): void
    {
        $routes = $this->routes(false);
        $params = ['kind' => 'field-group', 'slug' => 'speaker'];

        self::assertFalse($routes->get(new \WP_REST_Request($params))['writable']);
        self::assertWpError('proto_blocks_file_mods', 403, $routes->save(new \WP_REST_Request($params, ['text' => self::GROUP, 'hash' => null])));
        self::assertWpError('proto_blocks_file_mods', 403, $routes->delete(new \WP_REST_Request($params + ['confirm' => 'speaker'])));
        self::assertDirectoryDoesNotExist($this->root . '/proto-fields');
    }
}
