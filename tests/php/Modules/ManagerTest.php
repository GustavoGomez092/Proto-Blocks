<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\PhpDeclarations;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\RewriteHash;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\WriteException;
use ProtoBlocks\CLI\ContentModelCommands;
use ProtoBlocks\Modules\Fields\FieldsModule;
use ProtoBlocks\Modules\Fields\Read\FieldReader;
use ProtoBlocks\Modules\Fields\Sources\PhpGroups;
use ProtoBlocks\Modules\Manager;
use ProtoBlocks\Modules\PostTypes;
use ProtoBlocks\Modules\Taxonomies;

require_once __DIR__ . '/../CLI/wp-stubs.php';
require_once __DIR__ . '/../ContentModel/stubs/hooks.php';
require_once __DIR__ . '/../../../includes/content-model.php';
require_once __DIR__ . '/../../../includes/Modules/Fields/functions.php';

final class ManagerTest extends TestCase
{
    private const GLOBALS = ['pb_test_hooks', 'pb_test_registered', 'pb_test_post_types', 'pb_test_taxonomies', 'pb_test_filters', 'pb_test_tax_links', 'pb_test_actions'];

    private string $root;

    protected function setUp(): void
    {
        PhpDeclarations::reset();
        $tmp = sys_get_temp_dir() . '/pb-manager-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->root = (string) realpath($tmp);
        foreach (self::GLOBALS as $key) {
            $GLOBALS[$key] = [];
        }
        $GLOBALS['pb_test_theme'] = ['template' => $this->root, 'stylesheet' => $this->root];
        $GLOBALS['pb_test_is_admin'] = false;
        $GLOBALS['pb_test_flushes'] = 0;
        unset($GLOBALS['pb_test_options'][RewriteHash::OPTION]);
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        unset($GLOBALS['pb_test_theme'], $GLOBALS['pb_test_options'][RewriteHash::OPTION]);
        foreach (self::GLOBALS as $key) {
            $GLOBALS[$key] = [];
        }
        $GLOBALS['pb_test_is_admin'] = false;
        $GLOBALS['pb_test_flushes'] = 0;
        PhpGroups::reset();
        FieldsModule::setInstance(null);
        FieldReader::setShared(null);
        self::removeTree($this->root);
    }

    public function testDefaultsBootBothModules(): void
    {
        $manager = new Manager($this->registry());
        $manager->boot();

        $this->assertInstanceOf(PostTypes::class, $manager->postTypes());
        $this->assertInstanceOf(Taxonomies::class, $manager->taxonomies());
        $this->assertSame(Manager::DEFAULTS, $manager->states());
        $this->assertTrue($manager->isEnabled('post_types'));
        $this->assertTrue($manager->isEnabled('taxonomies'));
        $this->assertNotEmpty($GLOBALS['pb_test_hooks']['register_post_type_args'] ?? []);
        $this->assertNotEmpty($GLOBALS['pb_test_hooks']['register_taxonomy_args'] ?? []);
    }

    public function testFilterDisablesPostTypes(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_modules'][] = static fn (array $states) => ['post_types' => false] + $states;
        $manager = new Manager($this->registry());
        $manager->boot();

        $this->assertNull($manager->postTypes());
        $this->assertArrayNotHasKey('register_post_type_args', $GLOBALS['pb_test_hooks']);
        $this->assertFalse($manager->states()['post_types']);
        $this->assertFalse($manager->isEnabled('post_types'));
        $this->assertInstanceOf(Taxonomies::class, $manager->taxonomies());
        $this->assertTrue($manager->states()['taxonomies']);
    }

    public function testFilterReturningPartialArrayKeepsOtherDefaults(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_modules'][] = static fn () => ['taxonomies' => false, 'unknown' => true];
        $manager = new Manager($this->registry());
        $manager->boot();

        $this->assertSame(['post_types' => true, 'taxonomies' => false, 'fields' => true, 'options_pages' => true, 'redirects' => true, 'scripts' => true], $manager->states());
        $this->assertFalse($manager->isEnabled('unknown'));
        $this->assertNull($manager->taxonomies());
    }

    public function testBootTwiceHooksOnce(): void
    {
        $manager = new Manager($this->registry());
        $manager->boot();
        $manager->boot();

        $this->assertCount(1, $GLOBALS['pb_test_hooks']['register_post_type_args']);
    }

    public function testNoticeHookedOnlyInAdmin(): void
    {
        (new Manager($this->registry()))->boot();
        $this->assertArrayNotHasKey('admin_notices', $GLOBALS['pb_test_hooks']);

        $GLOBALS['pb_test_hooks'] = [];
        $GLOBALS['pb_test_is_admin'] = true;
        (new Manager($this->registry()))->boot();
        $this->assertArrayHasKey('admin_notices', $GLOBALS['pb_test_hooks']);
        $this->assertArrayHasKey('registered_post_type', $GLOBALS['pb_test_hooks']);
    }

    public function testOriginTrackerNotHookedOnFrontEnd(): void
    {
        (new Manager($this->registry()))->boot();

        $this->assertArrayNotHasKey('registered_post_type', $GLOBALS['pb_test_hooks']);
        $this->assertArrayNotHasKey('registered_taxonomy', $GLOBALS['pb_test_hooks']);
    }

    public function testFlushOnceAfterRegistration(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product", "taxonomies": [{"name": "Brand"}]}');
        $manager = new Manager($this->registry());
        $manager->boot();
        $this->assertSame(20, $this->priorityOf('wp_loaded', [$manager, 'maybeFlush']));

        $manager->postTypes()->register();
        $manager->taxonomies()->register();
        $manager->maybeFlush();
        $manager->maybeFlush();

        $this->assertSame(1, $GLOBALS['pb_test_flushes']);
    }

    public function testIssuesAreDeduplicated(): void
    {
        proto_post_type('Book', ['taxonomies' => [['name' => 'Genre', 'hierarchical' => 'yes']]]);
        $manager = new Manager($this->registry());
        $manager->boot();

        $manager->postTypes()->register();
        $manager->taxonomies()->register();
        $manager->postTypes()->filterArgs([], 'book');

        $schema = array_values(array_filter($manager->issues(), static fn (Issue $i) => $i->code === 'schema_invalid'));
        $this->assertCount(1, $schema);
        $this->assertStringContainsString('/taxonomies/0', $schema[0]->message);
    }

    // ------------------------------------------------------------------ fields module

    public function testFieldsModuleIsOnByDefault(): void
    {
        $this->assertTrue(Manager::DEFAULTS['fields']);
        $manager = new Manager($this->registry());
        $manager->boot();

        $fields = $manager->fields();
        $this->assertInstanceOf(FieldsModule::class, $fields);
        $this->assertSame($fields, FieldsModule::instance());
        $this->assertSame($fields->reader(), FieldReader::shared());
        $this->assertSame(20, $this->priorityOf('init', [$fields, 'registerMeta']));
    }

    public function testFieldsModuleOff(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_modules'][] = static fn (array $states) => ['fields' => false] + $states;
        $manager = new Manager($this->registry());
        $manager->boot();

        $this->assertNull($manager->fields());
        $this->assertFalse($manager->isEnabled('fields'));
        $this->assertNull(FieldReader::shared());
        $this->assertNull(proto_field('anything', 1), 'the getter returns null without notices');
        foreach ($GLOBALS['pb_test_hooks'] as $hook => $entries) {
            foreach ($entries as $entry) {
                $this->assertFalse(is_array($entry['callback']) && $entry['callback'][0] instanceof FieldsModule, "no Fields hook on $hook (no meta registration, no aliases)");
            }
        }
        $this->assertSame([], $GLOBALS['pb_test_bindings'] ?? [], 'no bindings source');
        foreach ($GLOBALS['pb_test_hooks']['proto_blocks_declaration_errors'] ?? [] as $entry) {
            $this->assertInstanceOf(\ProtoBlocks\Modules\OptionsPages::class, $entry['callback'][0], 'only the options pages module checks declarations');
        }
        $this->assertArrayNotHasKey('after_setup_theme', $GLOBALS['pb_test_hooks']);
        $this->assertInstanceOf(PostTypes::class, $manager->postTypes(), 'post types still boot');

        // instance() hands out an inert stand-in, not a live wired module.
        $this->write('proto-fields/a/group.json', '{"key": "group_a", "title": "A", "location": [[{"param": "post_type", "operator": "==", "value": "post"}]], "fields": [{"key": "f", "name": "a", "type": "text"}]}');
        $GLOBALS['pb_test_actions']['init'][] = [];
        $standIn = FieldsModule::instance();
        $this->assertFalse($standIn->isEnabled());
        $this->assertSame([], $standIn->index()->all());
        $hooksBefore = $GLOBALS['pb_test_hooks'];
        $standIn->boot();
        $standIn->registerMeta();
        $this->assertSame($hooksBefore, $GLOBALS['pb_test_hooks'], 'boot() and registerMeta() hook nothing');
        $this->assertSame([], $GLOBALS['pb_test_meta_reg'] ?? []);
        $this->assertNull(FieldReader::shared());
    }

    public function testFieldsSourcesAreWiredToTheContentModel(): void
    {
        $this->write('proto-fields/speaker/group.jsonc', "// Speaker\n{ \"key\": \"group_speaker\", \"title\": \"Speaker\", \"location\": [[{\"param\": \"post_type\", \"operator\": \"==\", \"value\": \"speaker\"}]], \"fields\": [{\"key\": \"f_c\", \"name\": \"company\", \"type\": \"text\"},] }");
        $this->write('proto-fields/export/group.json', '[{"key": "group_export", "title": "Export", "location": [[{"param": "post_type", "operator": "==", "value": "post"}]], "fields": [{"key": "f_e", "name": "e", "type": "text"}]}]');
        $this->write('proto-types/event/type.jsonc', '{ "name": "Event", "fields": [ { "key": "group_event", "title": "Event", "fields": [ { "key": "f_s", "name": "subtitle", "type": "text" } ] } ] }');
        $this->write('proto-taxonomies/genre/taxonomy.jsonc', '{ "name": "Genre", "post_types": ["event"], "fields": [ { "key": "group_genre", "title": "Genre", "fields": [ { "key": "f_g", "name": "colour", "type": "color_picker" } ] } ] }');
        $manager = new Manager($this->registry());
        $manager->boot();
        $GLOBALS['pb_test_actions']['init'][] = [];

        $keys = array_map(static fn ($g) => $g->key, $manager->fields()->index()->all());
        $this->assertSame(['group_event', 'group_genre', 'group_export', 'group_speaker'], $keys);
    }

    public function testFieldsIssuesReachTheManager(): void
    {
        mkdir($this->root . '/proto-fields/Bad Name', 0777, true);
        $manager = new Manager($this->registry());
        $manager->boot();
        $GLOBALS['pb_test_actions']['init'][] = [];

        $codes = array_map(static fn (Issue $i) => $i->code, $manager->issues());
        $this->assertContains('invalid_slug', $codes);
    }

    public function testWriterRefusesATypeWhoseFieldGroupLacksAKey(): void
    {
        $module = new FieldsModule();
        $GLOBALS['pb_test_filters']['proto_blocks_declaration_errors'][] = [$module, 'declarationErrors'];
        $writer = new SchemaFileWriter([$this->root], true);
        $text = '{ "name": "Event", "fields": [ { "title": "No key", "fields": [ { "key": "f", "name": "x", "type": "text" } ] } ] }';

        try {
            $writer->write('type', 'event', $text, null, null);
            $this->fail('Expected the write to be refused.');
        } catch (WriteException $e) {
            $this->assertSame('schema_invalid', $e->errorCode);
            $this->assertSame('/fields/0/key', $e->data['errors'][0]['path']);
        }
        $this->assertFileDoesNotExist($this->root . '/proto-types/event/type.jsonc');
    }

    public function testWriterAcceptsAFieldGroupWarning(): void
    {
        $module = new FieldsModule();
        $GLOBALS['pb_test_filters']['proto_blocks_declaration_errors'][] = [$module, 'declarationErrors'];
        $writer = new SchemaFileWriter([$this->root], true);
        // location is implied in a type file: an own `location` is only a warning.
        $text = '{ "name": "Event", "fields": [ { "key": "group_e", "title": "E", "location": [[{"param": "post_type", "operator": "==", "value": "x"}]], "fields": [ { "key": "f", "name": "x", "type": "text" } ] } ] }';

        $result = $writer->write('type', 'event', $text, null, null);
        $this->assertTrue($result['created']);
        $this->assertSame([], SchemaFileWriter::declarationErrors('type', $result['data'], 'event'));
    }

    public function testValidateReportsFieldFindingsByLevel(): void
    {
        $this->write('proto-types/event/type.jsonc', '{ "name": "Event", "fields": [ { "title": "No key", "fields": [] }, { "key": "g", "title": "G", "location": [[{"param": "post_type", "operator": "==", "value": "x"}]], "fields": [ { "key": "f", "name": "x", "type": "text" } ] } ] }');
        $module = new FieldsModule();
        $GLOBALS['pb_test_filters']['proto_blocks_declaration_errors'][] = [$module, 'declarationErrors'];

        $rows = ContentModelCommands::validationRows($this->registry(), []);
        $byCode = array_column($rows, null, 'code');
        $this->assertSame('error', $byCode['declaration_invalid']['level']);
        $this->assertStringStartsWith('/fields/0/key: ', $byCode['declaration_invalid']['message']);
        $this->assertSame('warning', $byCode['location_ignored']['level']);
        $this->assertStringStartsWith('/fields/1/location: ', $byCode['location_ignored']['message']);
    }

    public function testValidateShowsAFieldFindingOnceWithModuleIssues(): void
    {
        $this->write('proto-types/event/type.jsonc', '{ "name": "Event", "fields": [ { "title": "No key", "fields": [] } ] }');
        $manager = new Manager($this->registry());
        $manager->boot();
        $GLOBALS['pb_test_filters']['proto_blocks_declaration_errors'][] = [$manager->fields(), 'declarationErrors'];
        $GLOBALS['pb_test_actions']['init'][] = [];

        $rows = ContentModelCommands::validationRows($this->registry(), $manager->issues());
        $invalid = array_values(array_filter($rows, static fn (array $r): bool => $r['code'] === 'declaration_invalid'));
        $this->assertCount(1, $invalid, json_encode($rows) ?: '');
        $this->assertSame('type', $invalid[0]['kind']);
        $this->assertSame('event', $invalid[0]['slug']);
    }

    public function testPluginBootsManagerAfterSetupTheme(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../includes/Core/Plugin.php');

        $this->assertStringContainsString("\$this->services['modules'] = new \\ProtoBlocks\\Modules\\Manager(\$this->getContentModel());", $source);
        $this->assertStringContainsString("add_action('after_setup_theme', [\$this->services['modules'], 'boot'], 1);", $source);
        $this->assertStringContainsString('public function getContentModel(): ', $source);
        $this->assertStringContainsString('public function getModules(): ', $source);
    }

    // ------------------------------------------------------------------ helpers

    private function registry(): Registry
    {
        $root = $this->root;
        return new Registry(new FileLoader(), static fn () => [$root]);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function priorityOf(string $hook, callable $callback): ?int
    {
        foreach ($GLOBALS['pb_test_hooks'][$hook] ?? [] as $entry) {
            if (is_array($entry) && array_key_exists('callback', $entry) && $entry['callback'] === $callback) {
                return $entry['priority'];
            }
        }

        return null;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
