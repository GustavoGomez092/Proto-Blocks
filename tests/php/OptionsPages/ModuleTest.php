<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\OptionsPages;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/../Modules/Fields/wp-stubs.php';
require_once __DIR__ . '/../ContentModel/stubs/hooks.php';
require_once __DIR__ . '/../CLI/wp-stubs.php';
require_once __DIR__ . '/../API/stubs/wp-roles.php';
require_once __DIR__ . '/../Admin/wp-stubs.php';
require_once __DIR__ . '/../../../includes/content-model.php';
require_once __DIR__ . '/../../../includes/Modules/Fields/functions.php';
require_once __DIR__ . '/../../../includes/OptionsPages/functions.php';

use PHPUnit\Framework\TestCase;
use ProtoBlocks\API\OptionsValuesRoutes;
use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\PhpDeclarations;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\Modules\Fields\FieldsModule;
use ProtoBlocks\Modules\Fields\Read\FieldReader;
use ProtoBlocks\Modules\Fields\Sources\PhpGroups;
use ProtoBlocks\Modules\Manager;
use ProtoBlocks\Modules\OptionsPages;
use ProtoBlocks\OptionsPages\ExternalMenus;
use ProtoBlocks\OptionsPages\FieldsBridge;
use ProtoBlocks\OptionsPages\OptionsFieldStore;

/**
 * The OptionsPages module: what it hooks, how it sits beside the Fields
 * module, and that switching it off leaves nothing behind.
 */
final class ModuleTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/options-pages';
    private const CADCO = 'cadco-display-settings';
    private const GLOBALS = ['pb_test_hooks', 'pb_test_filters', 'pb_test_actions', 'pb_test_menus', 'pb_test_routes', 'pb_test_options', 'pb_test_autoload', 'pb_test_caps', 'pb_test_roles', 'pb_test_registered', 'pb_test_post_types', 'pb_test_taxonomies'];

    private string $root;

    protected function setUp(): void
    {
        PhpDeclarations::reset();
        PhpGroups::reset();
        FieldsModule::setInstance(null);
        FieldReader::setShared(null);
        foreach (self::GLOBALS as $key) {
            $GLOBALS[$key] = [];
        }
        $tmp = sys_get_temp_dir() . '/pb-options-module-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->root = (string) realpath($tmp);
        $GLOBALS['pb_test_theme'] = ['template' => $this->root, 'stylesheet' => $this->root];
        $GLOBALS['pb_test_is_admin'] = true;
        $GLOBALS['pb_test_current_user'] = 0;
        $this->place(self::CADCO, (string) file_get_contents(self::FIXTURES . '/' . self::CADCO . '/page.jsonc'));
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        PhpGroups::reset();
        FieldsModule::setInstance(null);
        FieldReader::setShared(null);
        foreach (self::GLOBALS as $key) {
            $GLOBALS[$key] = [];
        }
        unset($GLOBALS['pb_test_theme'], $_GET['page']);
        $GLOBALS['pb_test_is_admin'] = false;
        $GLOBALS['pb_test_current_user'] = 0;
        self::removeTree($this->root);
    }

    // ------------------------------------------------------------------ boot

    public function testBootHooksTheModule(): void
    {
        $manager = $this->manager();
        $module = $manager->optionsPages();

        $this->assertInstanceOf(OptionsPages::class, $module);
        $this->assertSame('options_pages', $module->id());
        $this->assertTrue($manager->isEnabled('options_pages'));
        $this->assertTrue(Manager::DEFAULTS['options_pages']);

        $this->assertSame(20, $this->priorityOf('admin_menu', [$module, 'registerMenus']));
        $this->assertSame(10, $this->priorityOf('admin_menu', [$module, 'seal']));
        $this->assertNotNull($this->priorityOf('proto_blocks_admin_app_hooks', [$module, 'appHooks']));
        $this->assertSame(10, $this->priorityOf('proto_blocks_declaration_errors', [$module, 'declarationErrors']));
        $this->assertSame(4, $this->argsOf('proto_blocks_declaration_errors', [$module, 'declarationErrors']));
        $this->assertNotNull($this->hookedTo('rest_api_init', OptionsValuesRoutes::class));
        $this->assertNotNull($this->hookedTo('proto_blocks_field_groups', FieldsBridge::class));
        $this->assertNotNull($this->hookedTo('proto_blocks_field_stores', FieldsBridge::class));
        $this->assertSame(PHP_INT_MAX, $this->hookedTo('admin_menu', ExternalMenus::class));
    }

    public function testBootTwiceHooksOnce(): void
    {
        $module = $this->manager()->optionsPages();
        $count = count($GLOBALS['pb_test_hooks']['admin_menu']);
        $module->boot();

        $this->assertCount($count, $GLOBALS['pb_test_hooks']['admin_menu']);
    }

    public function testExternalMenusAreNotCapturedOutsideTheAdmin(): void
    {
        $GLOBALS['pb_test_is_admin'] = false;
        $this->manager();

        $this->assertNull($this->hookedTo('admin_menu', ExternalMenus::class));
    }

    public function testRestRoutesRegister(): void
    {
        $module = $this->manager()->optionsPages();
        foreach ($GLOBALS['pb_test_hooks']['rest_api_init'] as $entry) {
            if (is_array($entry['callback']) && $entry['callback'][0] instanceof OptionsValuesRoutes) {
                ($entry['callback'])();
            }
        }

        $routes = array_column($GLOBALS['pb_test_routes'], 'route');
        $this->assertContains(OptionsValuesRoutes::ROUTE, $routes);
        $this->assertNotNull($module);
    }

    public function testStoresFilterIsAddedBeforeTheFieldsModuleBoots(): void
    {
        // Let apply_filters() run what is hooked at call time, like WordPress.
        $GLOBALS['pb_test_filters']['proto_blocks_field_stores'][] = static function ($stores) {
            foreach ($GLOBALS['pb_test_hooks']['proto_blocks_field_stores'] ?? [] as $entry) {
                $stores = ($entry['callback'])($stores);
            }
            return $stores;
        };
        $manager = $this->manager();

        // FieldsModule::boot() built the reader, and so the stores map, already.
        $this->assertInstanceOf(OptionsFieldStore::class, $manager->fields()->stores()['option']);
    }

    public function testWorksWithFieldsModuleDisabled(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_modules'][] = static fn (array $states) => ['fields' => false] + $states;
        $manager = $this->manager();
        $module = $manager->optionsPages();

        $this->assertInstanceOf(OptionsPages::class, $module);
        $this->assertNull($manager->fields());
        $this->assertNotNull($this->priorityOf('admin_menu', [$module, 'registerMenus']));
        $this->assertNotNull($this->hookedTo('rest_api_init', OptionsValuesRoutes::class));
        $this->assertArrayNotHasKey('proto_blocks_field_groups', $GLOBALS['pb_test_hooks']);
        $this->assertArrayNotHasKey('proto_blocks_field_stores', $GLOBALS['pb_test_hooks']);

        $page = $module->registry()->get(self::CADCO);
        $this->assertNotNull($page);
        $this->assertSame(['hide_children', 'children_placement', 'children_heading'], array_keys($module->values()->fields($page)));
    }

    public function testDisabledModuleRegistersNothing(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_modules'][] = static fn (array $states) => ['options_pages' => false] + $states;
        $manager = $this->manager();

        $this->assertNull($manager->optionsPages());
        $this->assertFalse($manager->states()['options_pages']);
        $this->assertFalse($manager->isEnabled('options_pages'));
        foreach ($GLOBALS['pb_test_hooks'] as $hook => $entries) {
            foreach ($entries as $entry) {
                $callback = $entry['callback'];
                $owner = is_array($callback) && is_object($callback[0]) ? get_class($callback[0]) : '';
                $this->assertFalse(
                    $owner === OptionsPages::class || $owner === OptionsValuesRoutes::class || str_starts_with($owner, 'ProtoBlocks\\OptionsPages\\'),
                    "no options-pages hook on $hook ($owner)"
                );
            }
        }
        $this->assertArrayNotHasKey('proto_blocks_field_stores', $GLOBALS['pb_test_hooks']);
        $this->assertArrayNotHasKey('proto_blocks_admin_app_hooks', $GLOBALS['pb_test_hooks']);
        $this->assertSame([], $GLOBALS['pb_test_menus']);
        $this->assertSame([], $GLOBALS['pb_test_routes']);

        // A theme's proto_options_page() call is still only a recorded declaration.
        proto_options_page('Late Settings');
        $this->assertSame([], $GLOBALS['pb_test_menus']);
    }

    // ------------------------------------------------------------------ menus

    public function testMenusRegisterWithThePageCapabilityAndFeedTheAppHooks(): void
    {
        $GLOBALS['pb_test_roles'] = [
            'subscriber' => ['name' => 'Subscriber', 'capabilities' => ['read' => true]],
            'shop_manager' => ['name' => 'Shop manager', 'capabilities' => ['read' => true, 'manage_woocommerce' => true]],
        ];
        proto_options_page('Site Notice', ['capability' => 'read', 'icon' => 'dashicons-megaphone', 'children' => [['slug' => 'extra', 'title' => 'Extra']]]);
        $module = $this->manager()->optionsPages();
        $module->registerMenus();

        $bySlug = [];
        foreach ($GLOBALS['pb_test_menus'] as $menu) {
            $bySlug[$menu['slug']][] = $menu;
        }
        $this->assertSame('manage_woocommerce', $bySlug[self::CADCO][0]['capability']);
        $this->assertSame('edit.php?post_type=product', $bySlug[self::CADCO][0]['parent']);
        $this->assertSame('read', $bySlug['site-notice'][0]['capability']);
        $this->assertSame('dashicons-megaphone', $bySlug['site-notice'][0]['icon']);
        $this->assertCount(1, $bySlug['site-notice-extra'], 'a child registers once, with its parent');
        $this->assertSame('site-notice', $bySlug['site-notice-extra'][0]['parent']);

        $hooks = $module->appHooks(['other']);
        $this->assertContains('other', $hooks);
        $this->assertContains('hook_' . self::CADCO, $hooks);
        $this->assertContains('hook_site-notice-extra', $hooks);
        $this->assertSame([], array_filter($module->issues(), static fn (Issue $i): bool => $i->code === 'capability_unknown'));
    }

    public function testUnknownCapabilityIsReportedAsAnIssue(): void
    {
        proto_options_page('Odd', ['capability' => 'no_such_cap']);
        $module = $this->manager()->optionsPages();
        $module->registerMenus();

        $codes = array_map(static fn (Issue $i): string => $i->code, $module->issues());
        $this->assertContains('capability_unknown', $codes);
    }

    public function testEmptyIconFallsBackToTheGenericIcon(): void
    {
        proto_options_page('Plain', []);
        $module = $this->manager()->optionsPages();
        $module->registerMenus();

        $plain = array_values(array_filter($GLOBALS['pb_test_menus'], static fn (array $m): bool => $m['slug'] === 'plain'));
        $this->assertSame('dashicons-admin-generic', $plain[0]['icon']);
    }

    public function testRenderCallbackRefusesUsersWithoutThePageCapability(): void
    {
        $module = $this->manager()->optionsPages();
        $module->registerMenus();
        $cadco = array_values(array_filter($GLOBALS['pb_test_menus'], static fn (array $m): bool => $m['slug'] === self::CADCO));
        $GLOBALS['pb_test_current_user'] = 7;
        $GLOBALS['pb_test_caps'][7] = ['read'];

        try {
            ($cadco[0]['fn'])();
            $this->fail('wp_die expected');
        } catch (\PbTestWpDie $e) {
            $this->assertSame(403, $e->status);
        }
    }

    public function testReadCapabilityPageRendersForSubscribersAndWritesStillGoThroughTheGate(): void
    {
        proto_options_page('Site Notice', ['capability' => 'read', 'fields' => [['key' => 'field_notice', 'name' => 'notice', 'type' => 'text']]]);
        $module = $this->manager()->optionsPages();
        $module->registerMenus();
        $page = array_values(array_filter($GLOBALS['pb_test_menus'], static fn (array $m): bool => $m['slug'] === 'site-notice'));
        $GLOBALS['pb_test_current_user'] = 5;
        $GLOBALS['pb_test_caps'][5] = ['read'];

        ob_start();
        ($page[0]['fn'])();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('data-screen="options-page"', $html);
        $this->assertStringContainsString('id="proto-blocks-options-page-data"', $html);

        $routes = $this->routes();
        $request = new \WP_REST_Request(['slug' => 'site-notice']);
        $this->assertTrue($routes->permission($request), 'the page capability (read) is the gate');
        $GLOBALS['pb_test_current_user'] = 0;
        $this->assertFalse($routes->permission($request), 'logged out is refused');
        $GLOBALS['pb_test_current_user'] = 5;
        $this->assertFalse($routes->permission(new \WP_REST_Request(['slug' => self::CADCO])), 'a subscriber cannot reach a manage_woocommerce page');
    }

    // ------------------------------------------------------------------ declarations

    public function testOptionsPagesMayBeDeclaredUntilAdminMenu(): void
    {
        $manager = $this->manager();
        Manager::sealDeclarations($this->registry()); // init 9: post types and taxonomies
        proto_post_type('Late Type');
        proto_options_page('Late Page');
        $this->assertArrayHasKey('late-page', PhpDeclarations::all()[Declaration::OPTIONS]);
        $this->assertArrayNotHasKey('late-type', PhpDeclarations::all()[Declaration::TYPE]);

        $module = $manager->optionsPages();
        $module->seal(); // admin_menu 10
        proto_options_page('Too Late');

        $this->assertArrayNotHasKey('too-late', PhpDeclarations::all()[Declaration::OPTIONS]);
        $late = array_values(array_filter(PhpDeclarations::issues(), static fn (Issue $i): bool => $i->code === 'declared_too_late' && $i->kind === Declaration::OPTIONS));
        $this->assertCount(1, $late);
        $this->assertStringContainsString('admin_menu', $late[0]->message);
        $this->assertStringNotContainsString('post types', $late[0]->message);
        $this->assertNotNull($module->registry()->get('late-page'), 'the registry sees a page declared after init');
    }

    public function testPageDeclaredAfterInitReachesRestAndProtoFieldOutsideTheAdmin(): void
    {
        // Let apply_filters() run what is hooked at call time, like WordPress.
        foreach (['proto_blocks_field_stores', 'proto_blocks_field_groups'] as $hook) {
            $GLOBALS['pb_test_filters'][$hook][] = static function ($value) use ($hook) {
                foreach ($GLOBALS['pb_test_hooks'][$hook] ?? [] as $entry) {
                    $value = ($entry['callback'])($value);
                }
                return $value;
            };
        }
        $GLOBALS['pb_test_is_admin'] = false; // a REST or front-end request
        $manager = $this->manager();
        $module = $manager->optionsPages();
        Manager::sealDeclarations($this->registry()); // init 9
        $this->assertNotNull($module->registry()->get(self::CADCO), 'registry built at init 9');
        $GLOBALS['pb_test_actions']['init'] = [[]];
        $this->assertNotSame([], $manager->fields()->index()->all(), 'the Fields index loaded at init 20 (registerMeta)');
        $this->assertSame(5, $this->priorityOf('wp_loaded', [$module, 'loaded']));

        // init 20: after the registry was built.
        proto_options_page('Late Notice', [
            'storage' => ['mode' => 'array', 'option' => 'late_notice'],
            'fields'  => [['key' => 'field_late_message', 'name' => 'message', 'type' => 'text']],
        ]);
        $module->loaded(); // wp_loaded; no admin_menu on this request

        $this->assertNotNull($module->registry()->get('late-notice'));
        $GLOBALS['pb_test_current_user'] = 1;
        $GLOBALS['pb_test_caps'][1] = ['manage_options'];
        $this->assertTrue($this->routes()->permission(new \WP_REST_Request(['slug' => 'late-notice'])), 'the REST save finds the page');

        $GLOBALS['pb_test_options']['late_notice'] = ['message' => 'Closed Monday'];
        $this->assertSame('Closed Monday', proto_field('message', 'option:late-notice'));

        proto_options_page('After Loaded');
        $this->assertArrayNotHasKey('after-loaded', PhpDeclarations::all()[Declaration::OPTIONS], 'sealed outside the admin');
    }

    public function testWpLoadedKeepsAdminRequestsOpenUntilAdminMenu(): void
    {
        $module = $this->manager()->optionsPages();
        Manager::sealDeclarations($this->registry());
        $module->loaded();
        proto_options_page('Menu Time');
        $this->assertArrayHasKey('menu-time', PhpDeclarations::all()[Declaration::OPTIONS]);
        $module->seal();
        $this->assertNotNull($module->registry()->get('menu-time'));
    }

    public function testDeclarationErrorsFilter(): void
    {
        $module = $this->manager()->optionsPages();

        $this->assertSame([['path' => '/x', 'message' => 'kept']], $module->declarationErrors([['path' => '/x', 'message' => 'kept']], 'type', ['name' => 'X'], 'x'));

        $protected = $module->declarationErrors([], 'options', ['title' => 'S', 'storage' => ['mode' => 'array', 'option' => 'siteurl']], 'site');
        $this->assertNotSame([], $protected);
        $this->assertArrayNotHasKey('level', $protected[0]);

        $child = $module->declarationErrors([], 'options', ['title' => 'P', 'children' => [['slug' => 'blocks-post-types', 'title' => 'C']]], 'proto');
        $this->assertContains('/children/0/slug', array_column($child, 'path'));

        $shared = $module->declarationErrors([], 'options', ['title' => 'Copy', 'storage' => ['mode' => 'array', 'option' => 'cadco_display_settings']], 'copy');
        $this->assertCount(1, $shared);
        $this->assertSame('warning', $shared[0]['level']);
        $this->assertSame('shared_storage', $shared[0]['code']);
        $this->assertStringContainsString(self::CADCO, $shared[0]['message']);

        $own = $module->declarationErrors([], 'options', ['title' => 'Display Settings', 'storage' => ['mode' => 'array', 'option' => 'cadco_display_settings']], self::CADCO);
        $this->assertSame([], $own, 'a page never shares storage with itself');
    }

    public function testIssuesReachValidateOnce(): void
    {
        $this->place('copy', '{"title": "Copy", "storage": {"mode": "array", "option": "cadco_display_settings"}}');
        $manager = $this->manager();
        $registry = $this->registry();
        $module = $manager->optionsPages();
        // apply_filters() in the stubs runs pb_test_filters only.
        $GLOBALS['pb_test_filters']['proto_blocks_declaration_errors'][] = static fn ($errors, ...$args) => $module->declarationErrors($errors, ...$args);

        $rows = \ProtoBlocks\CLI\ContentModelCommands::validationRows($registry, [...$manager->issues(), ...($manager->optionsPages()?->issues() ?? [])]);
        $shared = array_values(array_filter($rows, static fn (array $r): bool => $r['code'] === 'shared_storage'));
        $this->assertNotSame([], $shared);
        $this->assertSame(count($shared), count(array_unique(array_column($shared, 'slug'))), 'one shared_storage row per page');
    }

    public function testValidateCommandPassesOptionsIssues(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../includes/CLI/Commands.php');
        $this->assertStringContainsString('optionsPages()?->issues() ?? []', $source);
    }

    // ------------------------------------------------------------------ helpers

    private ?Registry $registry = null;

    private function manager(): Manager
    {
        $manager = new Manager($this->registry());
        $manager->boot();

        return $manager;
    }

    private function registry(): Registry
    {
        $root = $this->root;

        return $this->registry ??= new Registry(new FileLoader(), static fn () => [$root]);
    }

    private function routes(): OptionsValuesRoutes
    {
        foreach ($GLOBALS['pb_test_hooks']['rest_api_init'] ?? [] as $entry) {
            if (is_array($entry['callback']) && $entry['callback'][0] instanceof OptionsValuesRoutes) {
                return $entry['callback'][0];
            }
        }
        $this->fail('routes not hooked');
    }

    private function place(string $slug, string $text): void
    {
        $dir = $this->root . '/proto-options/' . $slug;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/page.jsonc', $text);
    }

    private function priorityOf(string $hook, callable $callback): ?int
    {
        foreach ($GLOBALS['pb_test_hooks'][$hook] ?? [] as $entry) {
            if ($entry['callback'] === $callback) {
                return $entry['priority'];
            }
        }

        return null;
    }

    private function argsOf(string $hook, callable $callback): ?int
    {
        foreach ($GLOBALS['pb_test_hooks'][$hook] ?? [] as $entry) {
            if ($entry['callback'] === $callback) {
                return $entry['args'];
            }
        }

        return null;
    }

    /** Priority of the first callback on $hook whose object is a $class. */
    private function hookedTo(string $hook, string $class): ?int
    {
        foreach ($GLOBALS['pb_test_hooks'][$hook] ?? [] as $entry) {
            if (is_array($entry['callback']) && $entry['callback'][0] instanceof $class) {
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
