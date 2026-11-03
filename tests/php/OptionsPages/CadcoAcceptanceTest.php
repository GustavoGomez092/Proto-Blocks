<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\OptionsPages;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../Modules/Fields/wp-stubs.php';
require_once __DIR__ . '/../ContentModel/stubs/hooks.php';
require_once __DIR__ . '/../../../includes/OptionsPages/functions.php';

use PHPUnit\Framework\TestCase;
use ProtoBlocks\API\OptionsValuesRoutes;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\PhpDeclarations;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\SchemaPaths;
use ProtoBlocks\Modules\Fields\FieldsModule;
use ProtoBlocks\Modules\Fields\Read\FieldReader;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\Modules\Fields\Sources\PhpGroups;
use ProtoBlocks\Modules\Fields\Storage\OptionStore;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;
use ProtoBlocks\OptionsPages\FieldsBridge;
use ProtoBlocks\OptionsPages\MenuRegistrar;
use ProtoBlocks\OptionsPages\OptionsFieldStore;
use ProtoBlocks\OptionsPages\OptionsPagesRegistry;
use ProtoBlocks\OptionsPages\OptionsStore;
use ProtoBlocks\OptionsPages\OptionsValues;
use ProtoBlocks\OptionsPages\PageDefinition;

/**
 * Done criterion: the CADCO theme's hand-written Products > Display Settings
 * page, re-declared as `proto-options/cadco-display-settings/page.jsonc`.
 *
 * Confirmed against the live theme (inc/cadco-display-settings.php):
 * same slug, parent menu `edit.php?post_type=product`, capability
 * `manage_woocommerce`, one array option `cadco_display_settings`, same three
 * keys. Documented differences (migration notes, see README "Settings pages"):
 *  - The theme's sanitiser stores `hide_children` as a boolean and always
 *    stores a non-empty heading (`Accessories` when empty). Proto-Blocks
 *    keeps the stored scalar format (`'1'` / `''`) and writes the heading only
 *    while it is visible. The theme reads with `!empty()`, so both work.
 *  - The theme labels differ (`Catalogue listing`, `Row heading`, a radio pair
 *    with long labels); the fixture uses short labels.
 *  - The theme's screen title is `Display settings`; the fixture's is
 *    `Display Settings`.
 *  - `legacy_sort` is not a live key; it stands for any undeclared key that
 *    must survive a save.
 *  - Option names `cadco-display-settings_<field>` never occur: the page uses
 *    `storage.mode = array`, so the live option name is kept.
 */
final class CadcoAcceptanceTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/options-pages';
    private const SLUG = 'cadco-display-settings';
    private const CTX = 'option:cadco-display-settings';
    private const OPTION = 'cadco_display_settings';
    private const G = 'group_cadco_display_settings_1';

    private string $theme = '';

    protected function setUp(): void
    {
        PhpDeclarations::reset();
        PhpGroups::reset();
        FieldsModule::setInstance(null);
        foreach (['pb_test_filters', 'pb_test_options', 'pb_test_autoload', 'pb_test_actions', 'pb_test_hooks', 'pb_test_menus'] as $g) {
            $GLOBALS[$g] = [];
        }
        $GLOBALS['pb_test_parent_menus'] = [];
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
        $GLOBALS['admin_page_hooks'] = ['edit.php?post_type=product' => 'x'];
        $GLOBALS['pb_test_caps'] = [
            1 => ['manage_woocommerce'],
            2 => ['edit_posts'],
        ];
        $GLOBALS['pb_test_current_user'] = 1;
        $tmp = sys_get_temp_dir() . '/pb-cadco-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->theme = (string) realpath($tmp);
        $dir = $this->theme . '/proto-options/' . self::SLUG;
        mkdir($dir, 0777, true);
        copy(self::FIXTURES . '/' . self::SLUG . '/page.jsonc', $dir . '/page.jsonc');
        $GLOBALS['pb_test_theme'] = ['template' => $this->theme, 'stylesheet' => $this->theme];

        // The live theme's stored option, before boot.
        $GLOBALS['pb_test_options'][self::OPTION] = [
            'hide_children' => '1',
            'children_placement' => 'section',
            'children_heading' => 'More in this range',
            'legacy_sort' => 'menu_order',
        ];
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        PhpGroups::reset();
        FieldsModule::setInstance(null);
        FieldReader::setShared(null);
        foreach (['pb_test_filters', 'pb_test_options', 'pb_test_autoload', 'pb_test_actions', 'pb_test_hooks', 'pb_test_caps'] as $g) {
            $GLOBALS[$g] = [];
        }
        $GLOBALS['pb_test_current_user'] = 0;
        unset($GLOBALS['pb_test_theme']);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->theme, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->theme);
    }

    private function registry(): OptionsPagesRegistry
    {
        return new OptionsPagesRegistry(new Registry(new FileLoader(), static fn (): array => SchemaPaths::roots()));
    }

    private function values(OptionsPagesRegistry $registry): OptionsValues
    {
        return new OptionsValues($registry, new OptionsStore(), TypeRegistry::withCoreTypes(), static fn (string $slug): array => []);
    }

    private function page(OptionsPagesRegistry $registry): PageDefinition
    {
        $page = $registry->get(self::SLUG);
        self::assertNotNull($page);

        return $page;
    }

    /** @return array<string, mixed> */
    private static function stored(): array
    {
        $value = $GLOBALS['pb_test_options'][self::OPTION] ?? [];

        return is_array($value) ? $value : [];
    }

    public function testFixtureLoadsAndRegistersTheMenu(): void
    {
        $registry = $this->registry();
        self::assertCount(1, $registry->all());
        self::assertSame([], $registry->warnings());
        self::assertSame([], $registry->issues());

        $menus = new MenuRegistrar(
            static fn (PageDefinition $p): array => ['value' => '', 'warning' => null],
            static function (PageDefinition $p): void {},
            static fn (string $cap): bool => $cap === 'manage_woocommerce'
        );
        $menus->register($registry->all());

        $m = $GLOBALS['pb_test_menus'];
        self::assertCount(1, $m);
        self::assertSame('edit.php?post_type=product', $m[0]['parent']);
        self::assertSame('Display Settings', $m[0]['menu_title']);
        self::assertSame('manage_woocommerce', $m[0]['capability']);
        self::assertSame(self::SLUG, $m[0]['slug']);
        self::assertSame([], $menus->warnings());
    }

    public function testStoredValuesReadUnchanged(): void
    {
        $registry = $this->registry();
        $values = $this->values($registry)->read($this->page($registry));

        self::assertSame('1', $values['hide_children']);
        self::assertSame('section', $values['children_placement']);
        self::assertSame('More in this range', $values['children_heading']);
    }

    public function testProtoFieldReaderSeesTheThemesValues(): void
    {
        $registry = $this->registry();
        $bridge = new FieldsBridge($registry, new OptionsFieldStore($registry, new OptionsStore()));
        $reader = new FieldReader(
            new GroupIndex($bridge->filterGroups([])),
            TypeRegistry::withCoreTypes(),
            $bridge->filterStores(['option' => new OptionStore()])
        );

        self::assertSame('More in this range', $reader->get('children_heading', self::CTX));
        self::assertTrue($reader->get('hide_children', self::CTX));
        self::assertSame('section', $reader->get('children_placement', self::CTX));
    }

    public function testSaveKeepsUndeclaredKeysAndSkipsTheHiddenHeading(): void
    {
        $registry = $this->registry();
        $fields = ['hide_children' => '0', 'children_placement' => 'related', 'children_heading' => ''];
        $result = $this->values($registry)->save(
            $this->page($registry),
            [self::G => $fields],
            [self::G => array_keys($fields)],
            1
        );

        self::assertIsArray($result);
        self::assertSame([
            'hide_children' => '',
            'children_placement' => 'related',
            'children_heading' => 'More in this range',
            'legacy_sort' => 'menu_order',
        ], self::stored());
        // The theme's `!empty($opts['hide_children'])` read.
        self::assertEmpty(self::stored()['hide_children']);
    }

    public function testOnlyTheCapabilityHolderMaySave(): void
    {
        $registry = $this->registry();
        $routes = new OptionsValuesRoutes($registry, $this->values($registry));

        $GLOBALS['pb_test_current_user'] = 1;
        self::assertTrue($routes->permission(new \WP_REST_Request(['slug' => self::SLUG])));

        $GLOBALS['pb_test_current_user'] = 2;
        self::assertFalse($routes->permission(new \WP_REST_Request(['slug' => self::SLUG])));
    }
}
