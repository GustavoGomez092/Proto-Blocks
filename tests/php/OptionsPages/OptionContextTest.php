<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\OptionsPages;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../Modules/Fields/wp-stubs.php';
require_once __DIR__ . '/../ContentModel/stubs/hooks.php';
require_once __DIR__ . '/../../../includes/OptionsPages/functions.php';

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\PhpDeclarations;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\SchemaPaths;
use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\FieldsModule;
use ProtoBlocks\Modules\Fields\Read\ContextResolver;
use ProtoBlocks\Modules\Fields\Read\FieldReader;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\Modules\Fields\Sources\PhpGroups;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use ProtoBlocks\Modules\Fields\Storage\OptionStore;
use ProtoBlocks\Modules\Fields\Storage\Store;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;
use ProtoBlocks\OptionsPages\FieldsBridge;
use ProtoBlocks\OptionsPages\OptionsFieldStore;
use ProtoBlocks\OptionsPages\OptionsPagesRegistry;
use ProtoBlocks\OptionsPages\OptionsStore;

/**
 * `proto_field('x', 'option:<page>')`: settings-page values through Phase 2's
 * reader (OptionsFieldStore + FieldsBridge).
 */
final class OptionContextTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/options-pages';
    private const CADCO = 'cadco-display-settings';
    private const CTX = 'option:cadco-display-settings';
    private const OPTION = 'cadco_display_settings';

    private string $theme = '';

    protected function setUp(): void
    {
        PhpDeclarations::reset();
        PhpGroups::reset();
        FieldsModule::setInstance(null);
        foreach (['pb_test_filters', 'pb_test_options', 'pb_test_autoload', 'pb_test_actions', 'pb_test_hooks'] as $g) {
            $GLOBALS[$g] = [];
        }
        $tmp = sys_get_temp_dir() . '/pb-option-context-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->theme = (string) realpath($tmp);
        foreach ([self::CADCO, 'theme-settings'] as $slug) {
            $this->place($slug, (string) file_get_contents(self::FIXTURES . '/' . $slug . '/page.jsonc'));
        }
        $GLOBALS['pb_test_theme'] = ['template' => $this->theme, 'stylesheet' => $this->theme];
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        PhpGroups::reset();
        FieldsModule::setInstance(null);
        FieldReader::setShared(null);
        foreach (['pb_test_filters', 'pb_test_options', 'pb_test_autoload', 'pb_test_actions', 'pb_test_hooks'] as $g) {
            $GLOBALS[$g] = [];
        }
        unset($GLOBALS['pb_test_theme']);
        self::removeTree($this->theme);
    }

    // ------------------------------------------------------------ helpers

    private function place(string $slug, string $text): void
    {
        $dir = $this->theme . '/proto-options/' . $slug;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/page.jsonc', $text);
    }

    private function registry(): OptionsPagesRegistry
    {
        return new OptionsPagesRegistry(new Registry(new FileLoader(), static fn (): array => SchemaPaths::roots()));
    }

    private function bridge(?OptionsPagesRegistry $registry = null): FieldsBridge
    {
        $registry ??= $this->registry();

        return new FieldsBridge($registry, new OptionsFieldStore($registry, new OptionsStore()));
    }

    /** @param list<FieldGroup> $groups extra groups ahead of the bridge's */
    private function reader(array $groups = [], ?FieldsBridge $bridge = null): FieldReader
    {
        $bridge ??= $this->bridge();

        return new FieldReader(
            new GroupIndex($bridge->filterGroups($groups)),
            TypeRegistry::withCoreTypes(),
            $bridge->filterStores(['option' => new OptionStore()])
        );
    }

    /** @param list<array<string, mixed>> $fields */
    private static function group(string $key, array $location, array $fields): FieldGroup
    {
        $result = GroupNormalizer::normalize(['key' => $key, 'title' => 'G', 'fields' => $fields, 'location' => $location], 'php');
        self::assertNotNull($result['group'], (string) json_encode($result['report']->errors));

        return $result['group'];
    }

    private static function field(string $name, string $type = 'text'): Field
    {
        $group = self::group('group_tmp_' . $name, [[['param' => 'options_page', 'operator' => '==', 'value' => 'x']]], [
            ['key' => 'field_tmp_' . $name, 'name' => $name, 'type' => $type],
        ]);

        return $group->fields[0];
    }

    private static function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    // ------------------------------------------------------------ brief

    public function testContextResolves(): void
    {
        $ref = ContextResolver::resolve(self::CTX);
        self::assertInstanceOf(ObjectRef::class, $ref);
        self::assertSame('option', $ref->kind);
        self::assertSame(self::CADCO, $ref->page);
    }

    public function testReadsArrayOption(): void
    {
        $GLOBALS['pb_test_options'][self::OPTION] = ['children_heading' => 'More & more', 'hide_children' => '1'];
        $reader = $this->reader();

        self::assertSame('More &amp; more', $reader->get('children_heading', self::CTX));
        self::assertTrue($reader->get('hide_children', self::CTX));
    }

    public function testOptionContextEscapesByDefault(): void
    {
        $GLOBALS['pb_test_options'][self::OPTION] = ['children_heading' => '<b>x</b>'];
        $reader = $this->reader();

        self::assertSame('&lt;b&gt;x&lt;/b&gt;', $reader->get('children_heading', self::CTX));
        self::assertSame('<b>x</b>', $reader->get('children_heading', self::CTX, true));
    }

    public function testDefaultWhenUnset(): void
    {
        self::assertSame('related', $this->reader()->get('children_placement', self::CTX));
    }

    public function testPerFieldPageAndChildContext(): void
    {
        $GLOBALS['pb_test_options']['theme-settings-footer_copyright'] = '© ACME';
        $GLOBALS['pb_test_options']['theme-settings_phone'] = '555';
        $reader = $this->reader();

        self::assertSame('© ACME', $reader->get('copyright', 'option:theme-settings-footer'));
        self::assertSame('555', $reader->get('phone', 'option:theme-settings'));
        self::assertNull($reader->get('copyright', 'option:theme-settings'), 'a child field is not the parent\'s');
    }

    public function testAllReturnsDeclaredFields(): void
    {
        $GLOBALS['pb_test_options'][self::OPTION] = ['legacy_sort' => 'asc'];
        $all = $this->reader()->all(self::CTX);

        self::assertEqualsCanonicalizing(['hide_children', 'children_placement', 'children_heading'], array_keys($all));
    }

    public function testUnknownPageReturnsNull(): void
    {
        self::assertNull($this->reader()->get('x', 'option:nope'));
    }

    public function testUndeclaredFieldReturnsNull(): void
    {
        $GLOBALS['pb_test_options'][self::OPTION] = ['legacy_sort' => 'asc'];

        self::assertNull($this->reader()->get('legacy_sort', self::CTX));
    }

    public function testFieldsModuleAppliesBridgeFilters(): void
    {
        $bridge = $this->bridge();
        $GLOBALS['pb_test_filters']['proto_blocks_field_groups'][] = [$bridge, 'filterGroups'];
        $GLOBALS['pb_test_filters']['proto_blocks_field_stores'][] = [$bridge, 'filterStores'];
        $GLOBALS['pb_test_options'][self::OPTION] = ['children_heading' => 'Kids'];
        $module = new FieldsModule();
        do_action('init');

        self::assertNotNull($module->index()->fieldFor(ObjectRef::option(self::CADCO), 'children_heading'));
        self::assertInstanceOf(OptionsFieldStore::class, $module->stores()['option']);
        self::assertSame('Kids', $module->reader()->get('children_heading', self::CTX));
    }

    // ------------------------------------------------------------ security and edges

    public function testProtectedNameIsNeverReadEvenWhenDeclared(): void
    {
        $GLOBALS['pb_test_options']['siteurl'] = 'https://example.test';
        $reader = $this->reader();
        self::assertNull($reader->get('siteurl', 'option:site'), 'undeclared');

        // A standalone group that would read a protected option through the fallback store.
        $GLOBALS['pb_test_options']['blog_public'] = '1';
        $group = self::group('group_blog', [[['param' => 'options_page', 'operator' => '==', 'value' => 'blog']]], [
            ['key' => 'field_blog_public', 'name' => 'public', 'type' => 'text'],
        ]);
        $reader = $this->reader([$group]);
        self::assertSame('', $reader->get('public', 'option:blog'), 'the stored protected value is never returned (unset: empty default)');
        self::assertNull((new OptionsFieldStore($this->registry(), new OptionsStore()))->get(ObjectRef::option('blog'), $group->fields[0]));
        self::assertFalse((new OptionsFieldStore($this->registry(), new OptionsStore()))->has(ObjectRef::option('blog'), $group->fields[0]));
    }

    public function testProtectedNameIsNeverWritten(): void
    {
        $store = new OptionsFieldStore($this->registry(), new OptionsStore());
        $this->expectException(\DomainException::class);
        try {
            $store->set(ObjectRef::option('blog'), self::field('public'), '0');
        } finally {
            self::assertArrayNotHasKey('blog_public', $GLOBALS['pb_test_options']);
        }
    }

    public function testProtectedAfterLoadIsSkippedOnADeclaredPage(): void
    {
        $GLOBALS['pb_test_options']['theme-settings_phone'] = '555';
        $reader = $this->reader();
        $GLOBALS['pb_test_filters']['proto_blocks_protected_options'][] = static fn (array $names): array => [...$names, 'theme-settings_phone'];

        self::assertSame('', $reader->get('phone', 'option:theme-settings'));
    }

    public function testArrayModeOptionHoldingAScalar(): void
    {
        $GLOBALS['pb_test_options'][self::OPTION] = 'legacy-string';
        $reader = $this->reader();

        self::assertSame('related', $reader->get('children_placement', self::CTX));
        self::assertSame('', $reader->get('children_heading', self::CTX, true), 'unset: the empty value, never the scalar');

        $registry = $this->registry();
        $store = new OptionsFieldStore($registry, new OptionsStore());
        $field = $reader->field(ObjectRef::option(self::CADCO), 'children_heading');
        self::assertNotNull($field);
        self::assertFalse($store->has(ObjectRef::option(self::CADCO), $field));
        try {
            $store->set(ObjectRef::option(self::CADCO), $field, 'x');
            self::fail('a scalar array option is never overwritten');
        } catch (\DomainException) {
        }
        self::assertSame('legacy-string', $GLOBALS['pb_test_options'][self::OPTION]);
    }

    public function testHasUsesExistenceNotTruthiness(): void
    {
        $GLOBALS['pb_test_options'][self::OPTION] = ['children_placement' => ''];
        $GLOBALS['pb_test_options']['theme-settings_phone'] = '';
        $registry = $this->registry();
        $store = new OptionsFieldStore($registry, new OptionsStore());
        $reader = $this->reader();

        $placement = $reader->field(ObjectRef::option(self::CADCO), 'children_placement');
        $phone = $reader->field(ObjectRef::option('theme-settings'), 'phone');
        self::assertNotNull($placement);
        self::assertNotNull($phone);
        self::assertTrue($store->has(ObjectRef::option(self::CADCO), $placement));
        self::assertTrue($store->has(ObjectRef::option('theme-settings'), $phone));
        self::assertSame('', $reader->get('children_placement', self::CTX), 'a stored empty value is not replaced by the default');
    }

    public function testSetWritesThroughOptionsStore(): void
    {
        $GLOBALS['pb_test_options'][self::OPTION] = ['hide_children' => 'yes', 'legacy_sort' => 'asc'];
        $registry = $this->registry();
        $store = new OptionsFieldStore($registry, new OptionsStore());
        $reader = $this->reader();
        $ref = ObjectRef::option(self::CADCO);

        $store->set($ref, $reader->field($ref, 'hide_children'), false);
        $store->set($ref, $reader->field($ref, 'children_heading'), 'New');
        self::assertSame(['hide_children' => 'no', 'legacy_sort' => 'asc', 'children_heading' => 'New'], $GLOBALS['pb_test_options'][self::OPTION]);

        $footer = ObjectRef::option('theme-settings-footer');
        $store->set($footer, $reader->field($footer, 'copyright'), '© Me');
        self::assertSame('© Me', $GLOBALS['pb_test_options']['theme-settings-footer_copyright']);
        self::assertFalse($GLOBALS['pb_test_autoload']['theme-settings-footer_copyright'] ?? null, 'child inherits autoload false');
    }

    public function testUndeclaredPageFallsBackToPhase2OptionStore(): void
    {
        $group = self::group('group_ext', [[['param' => 'options_page', 'operator' => '==', 'value' => 'acf-site']]], [
            ['key' => 'field_ext_tagline', 'name' => 'tagline', 'type' => 'text'],
        ]);
        $GLOBALS['pb_test_options']['acf-site_tagline'] = 'Hi & bye';

        self::assertSame('Hi &amp; bye', $this->reader([$group])->get('tagline', 'option:acf-site'));

        $seen = [];
        $fallback = new class ($seen) implements Store {
            public function __construct(private array &$seen)
            {
            }
            public function get(ObjectRef $ref, Field $f): mixed
            {
                $this->seen[] = 'get';
                return 'from-fallback';
            }
            public function set(ObjectRef $ref, Field $f, mixed $value): void
            {
                $this->seen[] = 'set';
            }
            public function has(ObjectRef $ref, Field $f): bool
            {
                $this->seen[] = 'has';
                return true;
            }
        };
        $stores = $this->bridge()->filterStores(['option' => $fallback, 'post' => $fallback]);
        self::assertSame(['option', 'post'], array_keys($stores));
        self::assertSame($fallback, $stores['post']);
        self::assertSame('from-fallback', $stores['option']->get(ObjectRef::option('acf-site'), $group->fields[0]));
        self::assertSame(['get'], $seen, 'the replaced option store serves pages Proto-Blocks does not declare');
    }

    public function testRefusesOtherKinds(): void
    {
        $store = new OptionsFieldStore($this->registry(), new OptionsStore());
        $this->expectException(\InvalidArgumentException::class);
        $store->get(ObjectRef::user(1), self::field('x'));
    }

    public function testBridgePinsLocationToTheOptionsPage(): void
    {
        $groups = $this->bridge()->filterGroups([]);
        self::assertNotSame([], $groups);
        foreach ($groups as $group) {
            self::assertCount(1, $group->location->or);
            self::assertCount(1, $group->location->or[0]);
            self::assertSame('options_page', $group->location->or[0][0]['param']);
            self::assertSame('==', $group->location->or[0][0]['operator']);
        }
        $pages = array_map(static fn (FieldGroup $g): string => $g->location->or[0][0]['value'], $groups);
        self::assertContains(self::CADCO, $pages);
        self::assertContains('theme-settings-footer', $pages);

        // A page group declaring a post location by accident never reaches posts.
        $this->place('sneaky', '{"title": "Sneaky", "fields": [{"key": "group_sneaky", "title": "S", '
            . '"location": [[{"param": "post_type", "operator": "==", "value": "post"}]], '
            . '"fields": [{"name": "note", "type": "text"}]}]}');
        $reader = $this->reader();
        $GLOBALS['pb_test_posts'][7] = ['ID' => 7, 'post_type' => 'post', 'post_status' => 'publish'];
        self::assertNull($reader->get('note', 7));
        self::assertSame('', $reader->get('note', 'option:sneaky'));
    }

    public function testBridgeKeepsExistingKeysAndIgnoresJunk(): void
    {
        $existing = self::group('group_cadco_display_settings_1', [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]], [
            ['key' => 'field_other', 'name' => 'other', 'type' => 'text'],
        ]);
        $groups = $this->bridge()->filterGroups([$existing]);
        $keys = array_map(static fn (FieldGroup $g): string => $g->key, $groups);
        self::assertSame($existing, $groups[0]);
        self::assertCount(1, array_keys($keys, 'group_cadco_display_settings_1'));

        self::assertIsArray($this->bridge()->filterGroups('junk'));
        self::assertArrayHasKey('option', $this->bridge()->filterStores(null));
    }
}
