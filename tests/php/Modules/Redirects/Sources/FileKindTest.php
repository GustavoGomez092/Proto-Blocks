<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Sources;

require_once dirname(__DIR__, 3) . '/API/ContentModelRoutesTestCase.php';
require_once dirname(__DIR__) . '/RedirectsTestCase.php';

use ProtoBlocks\API\Admin\SchemaFilesRoutes;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\WriteException;
use ProtoBlocks\Modules\Redirects\Definition\GroupValidator;
use ProtoBlocks\Modules\Redirects\Definition\SettingsValidator;
use ProtoBlocks\Modules\Redirects\RedirectsModule;
use ProtoBlocks\Tests\API\ContentModelRoutesTestCase;

/**
 * The `redirects` and `redirect-settings` kinds inside the content-model
 * machinery: folders, slug rules, schema, writes through SchemaFileWriter,
 * the schema-files route, templates and the declaration-errors filter.
 */
final class FileKindTest extends ContentModelRoutesTestCase
{
    private const GROUP = <<<'JSONC'
{
  // Legacy URLs
  "title": "Legacy site",
  "rules": [
    { "id": "r_8f3a2c1d", "from": "/about-us", "to": "/about" }
  ]
}
JSONC;

    protected function setUp(): void
    {
        parent::setUp();
        RedirectsModule::registerFileKinds();
        $GLOBALS['pb_test_home_url'] = 'https://example.test';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pb_test_home_url']);
        RedirectsModule::setInstance(null);
        parent::tearDown();
    }

    private function routes(): SchemaFilesRoutes
    {
        return new SchemaFilesRoutes($this->registry(), null, $this->writer());
    }

    public function testKindsAreRegisteredOutsideTheRegistry(): void
    {
        self::assertSame(['redirects', 'redirect-settings'], SchemaFileWriter::extendedKinds());
        self::assertSame('proto-redirects', SchemaFileWriter::extendedFolder('redirects'));
        self::assertSame('proto-redirects', SchemaFileWriter::extendedFolder('redirect-settings'));
        self::assertArrayNotHasKey('redirects', FileLoader::FOLDERS);
        RedirectsModule::registerFileKinds();
        self::assertSame(['redirects', 'redirect-settings'], SchemaFileWriter::extendedKinds(), 'idempotent');
    }

    public function testSlugRules(): void
    {
        self::assertNull(SchemaFileWriter::slugError('redirects', 'legacy-site'));
        self::assertNull(SchemaFileWriter::slugError('redirects', str_repeat('a', 60)));
        foreach (['settings', str_repeat('a', 61), 'Legacy', '../x', "a\n", '1a', ''] as $bad) {
            self::assertSame('pattern', SchemaFileWriter::slugError('redirects', $bad), $bad);
        }
        self::assertNull(SchemaFileWriter::slugError('redirect-settings', 'settings'));
        self::assertSame('pattern', SchemaFileWriter::slugError('redirect-settings', 'other'));
    }

    public function testAFlatKindMayShareAFolderOnlyWithAFolderKind(): void
    {
        $validate = static fn(array $data): array => [];
        try {
            SchemaFileWriter::extendKind('other-flat', 'proto-redirects', 'settings', $validate, ['flat' => true]);
            self::fail('two flat kinds in one folder');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        try {
            SchemaFileWriter::extendKind('other-tree', 'proto-redirects', 'x', $validate);
            self::fail('two folder kinds in one folder');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testGroupWriteGoesThroughTheWriter(): void
    {
        $writer = $this->writer();
        $result = $writer->write('redirects', 'legacy-site', self::GROUP, null, null);

        self::assertSame($this->root . '/proto-redirects/legacy-site/redirects.jsonc', $result['path']);
        self::assertTrue($result['created']);
        self::assertSame(self::GROUP, file_get_contents($result['path']));
        self::assertNotNull($writer->existing('redirects', 'legacy-site'));

        try {
            $writer->write('redirects', 'legacy-site', self::GROUP, null, $writer->existing('redirects', 'legacy-site'));
            self::fail('stale hash accepted');
        } catch (WriteException $e) {
            self::assertSame('conflict', $e->errorCode);
        }
    }

    public function testGroupWriteRefusesValidatorErrors(): void
    {
        foreach ([
            '{"rules": [{"from": "^/(", "regex": true, "to": "/x"}]}',
            '{"rules": [{"from": "/a", "to": "javascript:alert(1)"}]}',
            '{"rules": [{"from": "/a", "to": "/a"}]}',
            '{"rules": [{"from": "/a", "to": "/b"}, {"from": "/a", "to": "/c"}]}',
            '{"rules": [{"from": "https://other.test/a", "to": "/b"}]}',
            '{"colour": "red"}',
        ] as $text) {
            try {
                $this->writer()->write('redirects', 'g', $text, null, null);
                self::fail('accepted ' . $text);
            } catch (WriteException $e) {
                self::assertSame(400, $e->status, $text);
            }
        }
        self::assertDirectoryDoesNotExist($this->root . '/proto-redirects/g');
    }

    public function testWriterRefusesFilesOver256Kb(): void
    {
        $text = '{"rules": []}' . str_repeat(' ', FileLoader::MAX_BYTES);
        try {
            $this->writer()->write('redirects', 'big', $text, null, null);
            self::fail('accepted an oversized file');
        } catch (WriteException $e) {
            self::assertSame(413, $e->status);
        }
    }

    public function testSettingsFileIsFlat(): void
    {
        $writer = $this->writer();
        $this->write('proto-redirects/legacy/redirects.jsonc', '{}');
        $result = $writer->write('redirect-settings', 'settings', "{\n  \"cache\": 24\n}\n", null, null);

        self::assertSame($this->root . '/proto-redirects/settings.jsonc', $result['path']);
        $existing = $writer->existing('redirect-settings', 'settings');
        self::assertNotNull($existing);
        self::assertSame($result['path'], $existing->source);
        self::assertTrue($writer->target('redirect-settings', 'settings', $existing)['writable']);

        try {
            $writer->write('redirect-settings', 'settings', '{"cache": 48}', SchemaFileWriter::hash("{\n  \"cache\": 24\n}\n"), $existing);
            self::fail('accepted cache 48');
        } catch (WriteException $e) {
            self::assertSame(400, $e->status);
        }

        $deleted = $writer->delete('redirect-settings', 'settings', $existing);
        self::assertSame($result['path'], $deleted['deleted']);
        self::assertFileDoesNotExist($result['path']);
        self::assertFileExists($this->root . '/proto-redirects/legacy/redirects.jsonc', 'the group folders stay');
    }

    public function testSettingsWriteRefusesTheReservedSlugAsAGroup(): void
    {
        try {
            $this->writer()->write('redirects', 'settings', '{}', null, null);
            self::fail('a group named settings');
        } catch (WriteException $e) {
            self::assertSame(400, $e->status);
        }
    }

    public function testTemplatesAreValidDeclarations(): void
    {
        $group = SchemaFileWriter::extendedTemplate('redirects', 'legacy-site', 'Legacy <b>site</b>');
        self::assertNotNull($group);
        $data = Jsonc::decode($group);
        self::assertSame('Legacy bsite/b', $data['title']);
        self::assertSame([], GroupValidator::validate($data)['errors']);
        self::assertStringStartsWith('// Managed by Proto-Blocks.', $group);

        $settings = SchemaFileWriter::extendedTemplate('redirect-settings', 'settings', '');
        self::assertNotNull($settings);
        self::assertSame([], Jsonc::decode($settings));
        self::assertSame([], SettingsValidator::validate(Jsonc::decode($settings))['errors']);
        // Every commented example is itself valid once uncommented.
        $uncommented = Jsonc::decode((string) preg_replace('~^  // ~m', '  ', $settings));
        self::assertSame([], SettingsValidator::validate($uncommented)['errors']);
    }

    public function testSchemaFilesRouteServesAndSavesBothKinds(): void
    {
        $routes = $this->routes();

        $new = $routes->get(new \WP_REST_Request(['kind' => 'redirect-settings', 'slug' => 'settings']));
        self::assertIsArray($new);
        self::assertFalse($new['exists']);
        self::assertStringContainsString('redirect settings', $new['text']);

        $saved = $routes->save(new \WP_REST_Request(['kind' => 'redirect-settings', 'slug' => 'settings'], ['text' => '{"coexist": true}', 'hash' => null, 'acknowledge' => []]));
        self::assertIsArray($saved, json_encode($saved));
        self::assertFileExists($this->root . '/proto-redirects/settings.jsonc');

        $group = $routes->save(new \WP_REST_Request(['kind' => 'redirects', 'slug' => 'legacy'], ['text' => self::GROUP, 'hash' => null, 'acknowledge' => []]));
        self::assertIsArray($group, json_encode($group));
        self::assertWpError('proto_blocks_schema_invalid', 400, $routes->save(new \WP_REST_Request(['kind' => 'redirects', 'slug' => 'other'], ['text' => '{"rules": [{"from": "/a"}]}', 'hash' => null, 'acknowledge' => []])));
        self::assertWpError('proto_blocks_invalid_slug', 400, $routes->get(new \WP_REST_Request(['kind' => 'redirects', 'slug' => 'settings'])));
    }

    public function testDeclarationErrorsFilterAddsWarningsWithCodes(): void
    {
        $this->write('proto-redirects/other/redirects.jsonc', '{"rules": [{"from": "/b", "to": "/c"}]}');
        $module = new RedirectsModule($this->registry());
        $GLOBALS['pb_test_filters']['proto_blocks_declaration_errors'][] = [$module, 'declarationWarnings'];

        $findings = SchemaFileWriter::declarationFindings('redirects', ['rules' => [
            ['from' => '/a', 'to' => '/b'],
            ['from' => 'old', 'regex' => true, 'to' => '/x'],
        ]], 'legacy');

        $codes = array_column($findings, 'code');
        sort($codes);
        self::assertSame(['redirect_chain', 'unanchored_regex'], $codes);
        foreach ($findings as $finding) {
            self::assertSame('warning', $finding['level']);
        }
        self::assertSame([], SchemaFileWriter::declarationErrors('redirects', ['rules' => [['from' => 'old', 'regex' => true, 'to' => '/x']]], 'legacy'), 'warnings never refuse a write');
        // Other kinds pass through untouched.
        self::assertSame([], SchemaFileWriter::declarationFindings('type', ['name' => 'X'], 'x'));
    }
}
