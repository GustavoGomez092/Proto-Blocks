<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\API;

require_once __DIR__ . '/ContentModelRoutesTestCase.php';
require_once __DIR__ . '/stubs/wp-roles.php';

use ProtoBlocks\API\Admin\OptionsPagesRoutes;
use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\ContentModel\PhpDeclarations;
use ProtoBlocks\OptionsPages\ExternalMenus;
use ProtoBlocks\OptionsPages\OptionsPagesRegistry;

/**
 * admin/options-pages: declared settings pages tagged by origin, one page's
 * detail, and the captured admin menu around them.
 */
final class OptionsPagesRoutesTest extends ContentModelRoutesTestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/options-pages';

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['pb_test_options'] = [];
        $GLOBALS['pb_test_transients'] = [];
        $GLOBALS['pb_test_roles'] = [];
        $GLOBALS['wp_filter'] = [];
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
        unset($_GET['page']);
        foreach (['cadco-display-settings', 'theme-settings'] as $slug) {
            $this->write('proto-options/' . $slug . '/page.jsonc', (string) file_get_contents(self::FIXTURES . '/' . $slug . '/page.jsonc'));
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            self::removeTree($dir);
        }
        $this->cleanup = [];
        unset($_GET['page'], $GLOBALS['pb_test_roles'], $GLOBALS['wp_filter']);
        $GLOBALS['pb_test_transients'] = [];
        $GLOBALS['pb_test_options'] = [];
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
        parent::tearDown();
    }

    private function routes(bool $fileMods = true, ?ExternalMenus $external = null): OptionsPagesRoutes
    {
        return new OptionsPagesRoutes(
            new OptionsPagesRegistry($this->registry()),
            $this->registry(),
            $this->writer($fileMods),
            $external ?? new ExternalMenus()
        );
    }

    /** @return array<string, array<string, mixed>> */
    private static function bySlug(array $result): array
    {
        return array_column($result['pages'], null, 'slug');
    }

    // --- routes ------------------------------------------------------------

    public function testRoutesUseManageOptions(): void
    {
        $permission = static fn (): bool => true;
        $write = static fn (): bool => false;
        $this->routes()->registerRoutes('proto-blocks/v1', $permission, $write);

        $list = self::route(AdminRestController::BASE . '/options-pages');
        $detail = null;
        foreach ($GLOBALS['pb_test_routes'] as $entry) {
            if (str_starts_with(ltrim((string) $entry['route'], '/'), AdminRestController::BASE . '/options-pages/')) {
                $detail = $entry;
            }
        }
        self::assertNotNull($list);
        self::assertNotNull($detail);
        self::assertCount(2, $GLOBALS['pb_test_routes']);
        foreach ([$list, $detail] as $entry) {
            self::assertCount(1, $entry['args']);
            self::assertSame('GET', $entry['args'][0]['methods']);
            self::assertSame($permission, $entry['args'][0]['permission_callback']);
        }
    }

    public function testDetailRoutePatternRejectsTraversal(): void
    {
        $this->routes()->registerRoutes('proto-blocks/v1', static fn (): bool => true, static fn (): bool => true);
        $pattern = null;
        foreach ($GLOBALS['pb_test_routes'] as $entry) {
            if (str_contains((string) $entry['route'], '(?P<slug>')) {
                $pattern = '#^/' . ltrim((string) $entry['route'], '/') . '$#D';
            }
        }
        self::assertNotNull($pattern);

        self::assertSame(1, preg_match($pattern, '/admin/options-pages/theme-settings'));
        foreach (['../x', 'a/b', 'A', 'a_b', '1abc', str_repeat('a', 65)] as $bad) {
            self::assertSame(0, preg_match($pattern, '/admin/options-pages/' . $bad), $bad);
        }
    }

    // --- rows ----------------------------------------------------------------

    public function testRowShape(): void
    {
        $page = (new OptionsPagesRegistry($this->registry()))->get('cadco-display-settings');
        self::assertNotNull($page);

        $row = OptionsPagesRoutes::row($page, null, ['path' => $this->root . '/proto-options/cadco-display-settings/page.jsonc', 'writable' => true, 'reason' => null]);

        self::assertSame(
            ['slug', 'title', 'menu', 'menuLabel', 'capability', 'storage', 'origin', 'originLabel', 'double', 'source', 'editable', 'readOnlyReason', 'children', 'warnings', 'url'],
            array_keys($row)
        );
        self::assertSame('Array: cadco_display_settings', $row['storage']);
        self::assertSame('manage_woocommerce', $row['capability']);
        self::assertSame('file', $row['origin']);
        self::assertSame('Proto-Blocks file', $row['originLabel']);
        self::assertTrue($row['editable']);
        self::assertNull($row['readOnlyReason']);
        self::assertNull($row['double']);
        self::assertSame('https://example.test/wp-admin/edit.php?post_type=product&page=cadco-display-settings', $row['url']);
        self::assertStringEndsWith('proto-options/cadco-display-settings/page.jsonc', $row['source']);
        self::assertStringNotContainsString($this->root, $row['source']);
    }

    public function testFieldsModeStorageAndChildren(): void
    {
        $pages = self::bySlug($this->routes()->index());

        self::assertSame('Per field', $pages['theme-settings']['storage']);
        self::assertSame(['theme-settings-footer'], $pages['theme-settings']['children']);
        self::assertSame([], $pages['theme-settings-footer']['children']);
        self::assertSame('Top level', $pages['theme-settings']['menuLabel']);
        self::assertSame('https://example.test/wp-admin/admin.php?page=theme-settings', $pages['theme-settings']['url']);
        self::assertSame('Under Theme Settings', $pages['theme-settings-footer']['menuLabel']);
    }

    public function testReadOnlyReasonsCarryThrough(): void
    {
        $page = (new OptionsPagesRegistry($this->registry()))->get('cadco-display-settings');
        $row = OptionsPagesRoutes::row($page, null, ['path' => '', 'writable' => false, 'reason' => 'file_mods']);
        self::assertFalse($row['editable']);
        self::assertSame('file_mods', $row['readOnlyReason']);

        $pages = self::bySlug($this->routes(false)->index());
        self::assertFalse($pages['cadco-display-settings']['editable']);
        self::assertSame('file_mods', $pages['cadco-display-settings']['readOnlyReason']);
    }

    public function testPhpPageIsNotEditable(): void
    {
        PhpDeclarations::add('options', 'Shop Notices', ['menu' => 'woocommerce'], ABSPATH . 'functions.php:12');

        $row = self::bySlug($this->routes()->index())['shop-notices'];

        self::assertSame('php', $row['origin']);
        self::assertSame('Proto-Blocks PHP', $row['originLabel']);
        self::assertFalse($row['editable']);
        self::assertSame('functions.php:12', $row['source']);
        self::assertSame('Under woocommerce (missing)', $row['menuLabel']);
    }

    public function testDoubleDeclaredRow(): void
    {
        $page = (new OptionsPagesRegistry($this->registry()))->get('cadco-display-settings');
        $row = OptionsPagesRoutes::row($page, ['file' => 'wp-content/themes/t/proto-options/cadco-display-settings/page.jsonc', 'php' => 'functions.php:40'], ['path' => '', 'writable' => true, 'reason' => null]);
        self::assertSame(
            ['file' => 'wp-content/themes/t/proto-options/cadco-display-settings/page.jsonc', 'php' => 'functions.php:40'],
            $row['double']
        );

        PhpDeclarations::add('options', 'Cadco Display Settings', ['menu' => 'top'], ABSPATH . 'functions.php:40');
        $this->registry()->invalidate();
        $listed = self::bySlug($this->routes()->index())['cadco-display-settings'];
        self::assertSame('functions.php:40', $listed['double']['php']);
        self::assertStringEndsWith('proto-options/cadco-display-settings/page.jsonc', $listed['double']['file']);
        $this->assertNoAbsolutePaths($listed);
    }

    public function testMenuLabel(): void
    {
        self::assertSame('Top level', OptionsPagesRoutes::menuLabel('top', []));
        self::assertSame('Under Products', OptionsPagesRoutes::menuLabel('edit.php?post_type=product', ['edit.php?post_type=product' => 'Products']));
        self::assertSame('Under edit.php?post_type=nope (missing)', OptionsPagesRoutes::menuLabel('edit.php?post_type=nope', ['edit.php?post_type=product' => 'Products']));
    }

    // --- list ------------------------------------------------------------------

    public function testIndexShape(): void
    {
        $GLOBALS['pb_test_roles'] = ['editor' => ['capabilities' => ['read' => true, 'edit_posts' => true, 'denied' => false]]];
        $result = $this->routes()->index();

        self::assertSame(['moduleEnabled', 'createTarget', 'pages', 'external', 'capabilities', 'warnings'], array_keys($result));
        self::assertSame(['edit_posts', 'read'], $result['capabilities']);
        self::assertTrue($result['moduleEnabled']);
        self::assertTrue($result['createTarget']['writable']);
        self::assertNull($result['createTarget']['reason']);
        self::assertSame(['cadco-display-settings', 'theme-settings', 'theme-settings-footer'], array_keys(self::bySlug($result)));
        self::assertSame([], $result['external']);
        self::assertNotFalse(json_encode($result));
        $this->assertNoAbsolutePaths($result);
    }

    public function testCreateTargetReportsFileMods(): void
    {
        $target = $this->routes(false)->index()['createTarget'];

        self::assertFalse($target['writable']);
        self::assertSame('file_mods', $target['reason']);
    }

    public function testWarningsSurfaceWithoutAbsolutePaths(): void
    {
        $this->write('proto-options/broken/page.jsonc', '{"menu": "top"}');

        $result = $this->routes()->index();

        $broken = array_values(array_filter($result['warnings'], static fn (array $w): bool => $w['slug'] === 'broken'));
        self::assertNotEmpty($broken);
        self::assertSame(['slug', 'source', 'message'], array_keys($broken[0]));
        $this->assertNoAbsolutePaths($result);
    }

    // --- detail ------------------------------------------------------------------

    public function testDetailShape(): void
    {
        $GLOBALS['pb_test_roles'] = [
            'administrator' => ['capabilities' => ['manage_options' => true, 'manage_woocommerce' => true, 'denied_cap' => false]],
            'editor' => ['capabilities' => ['edit_posts' => true, 'manage_options' => true]],
        ];
        $GLOBALS['pb_test_transients'][ExternalMenus::TRANSIENT] = ['value' => [
            'pages' => [],
            'parents' => [['slug' => 'edit.php?post_type=product', 'title' => 'Products', 'capability' => 'manage_options']],
        ], 'expiration' => 3600];

        $result = $this->routes()->show(new \WP_REST_Request(['slug' => 'cadco-display-settings']));

        self::assertSame(['page', 'declaration', 'parents', 'capabilities'], array_keys($result));
        self::assertSame('cadco-display-settings', $result['page']['slug']);
        self::assertSame('Under Products', $result['page']['menuLabel']);
        self::assertSame('Display Settings', $result['declaration']['title']);
        self::assertSame(['edit_posts', 'manage_options', 'manage_woocommerce'], $result['capabilities']);
        $parentSlugs = array_column($result['parents'], 'slug');
        self::assertContains('edit.php?post_type=product', $parentSlugs);
        self::assertContains('theme-settings', $parentSlugs);
        self::assertNotContains('cadco-display-settings', $parentSlugs, 'a page is not its own parent');
        $this->assertNoAbsolutePaths($result);
    }

    public function testChildDetailUsesParentDeclaration(): void
    {
        $result = $this->routes()->show(new \WP_REST_Request(['slug' => 'theme-settings-footer']));

        self::assertSame('theme-settings-footer', $result['page']['slug']);
        self::assertSame('Theme Settings', $result['declaration']['title']);
    }

    public function testUnknownSlug404(): void
    {
        self::assertWpError('proto_blocks_options_page_not_found', 404, $this->routes()->show(new \WP_REST_Request(['slug' => 'nothing'])));
    }

    public function testHandlerRechecksSlugCase(): void
    {
        foreach (['Theme-Settings', '../x', 'a/b', '', 'theme_settings'] as $bad) {
            self::assertWpError('proto_blocks_invalid_slug', 400, $this->routes()->show(new \WP_REST_Request(['slug' => $bad])));
        }
    }

    public function testDetailReadsOnlyDeclaredPagesAndNoOptionValues(): void
    {
        $GLOBALS['pb_test_options']['cadco_display_settings'] = ['hide_children' => 'SECRET-VALUE'];
        $GLOBALS['pb_test_options']['siteurl'] = 'https://secret.example';

        // An option name is not a page slug, whatever extra params ride along.
        $probe = new \WP_REST_Request(['slug' => 'siteurl', 'option' => 'siteurl', 'name' => 'siteurl']);
        self::assertWpError('proto_blocks_options_page_not_found', 404, $this->routes()->show($probe));

        $json = (string) json_encode([
            $this->routes()->show(new \WP_REST_Request(['slug' => 'cadco-display-settings'])),
            $this->routes()->index(),
        ]);
        self::assertStringNotContainsString('SECRET-VALUE', $json);
        self::assertStringNotContainsString('secret.example', $json);
    }

    public function testDetailReadsUrlSlugNotBodySlug(): void
    {
        $request = new class (['slug' => 'theme-settings']) extends \WP_REST_Request {
            public function get_url_params(): array
            {
                return ['slug' => 'cadco-display-settings'];
            }
        };

        self::assertSame('cadco-display-settings', $this->routes()->show($request)['page']['slug']);
    }

    // --- external menus ------------------------------------------------------------

    public function testExternalMenusOrigin(): void
    {
        $plugins = sys_get_temp_dir() . '/pb-ext-plugins-' . bin2hex(random_bytes(6));
        mkdir($plugins . '/acme', 0777, true);
        $this->cleanup[] = $plugins;
        $plugins = (string) realpath($plugins);
        $fn = 'pb_acme_render_' . bin2hex(random_bytes(4));
        file_put_contents($plugins . '/acme/acme.php', "<?php\nfunction $fn() {}\n");
        require_once $plugins . '/acme/acme.php';

        $GLOBALS['menu'] = [
            ['Acme', 'manage_options', 'acme', 'Acme', 'menu-top', 'toplevel_page_acme', 'dashicons-admin-generic'],
            ['', 'read', 'separator1', '', 'wp-menu-separator'],
            ['Settings <span class="update-plugins count-1"><span class="plugin-count">1</span></span>', 'manage_options', 'options-general.php', '', 'menu-top'],
        ];
        $GLOBALS['submenu'] = [
            'options-general.php' => [
                ['General', 'manage_options', 'options-general.php'],
                ['Writing', 'manage_options', 'options-writing.php'],
            ],
        ];
        $GLOBALS['wp_filter'] = ['toplevel_page_acme' => (object) ['callbacks' => [10 => ['id' => ['function' => $fn, 'accepted_args' => 1]]]]];

        $external = new ExternalMenus(['plugin' => $plugins, 'mu' => '', 'themes' => []]);
        $_GET['page'] = ExternalMenus::SCREEN;
        $external->capture();

        $rows = array_column($this->routes(true, $external)->index()['external'], null, 'slug');

        self::assertSame('plugin', $rows['acme']['origin']);
        self::assertSame('Plugin: acme', $rows['acme']['originLabel']);
        self::assertSame('top', $rows['acme']['parent']);
        self::assertSame('core', $rows['options-writing.php']['origin']);
        self::assertSame('Core', $rows['options-writing.php']['originLabel']);
        self::assertSame('options-general.php', $rows['options-writing.php']['parent']);
        self::assertSame('Settings 1', $rows['options-general.php']['title'], 'update bubbles are stripped to text');
        self::assertArrayNotHasKey('separator1', $rows);
        self::assertSame(
            [['slug' => 'acme', 'title' => 'Acme'], ['slug' => 'options-general.php', 'title' => 'Settings 1']],
            $external->get()['parents']
        );
        self::assertStringNotContainsString($plugins, (string) json_encode($external->get()));
    }

    public function testCaptureOnlyOnOptionsPagesScreen(): void
    {
        $GLOBALS['menu'] = [['Tools', 'manage_options', 'tools.php', '', 'menu-top']];
        $external = new ExternalMenus();

        $external->capture();
        self::assertSame(['pages' => [], 'parents' => []], $external->get());

        $_GET['page'] = 'something-else';
        $external->capture();
        self::assertSame(['pages' => [], 'parents' => []], $external->get());

        $_GET['page'] = ExternalMenus::SCREEN;
        $external->capture();
        self::assertSame('tools.php', $external->get()['parents'][0]['slug']);
        self::assertSame(3600, $GLOBALS['pb_test_transients'][ExternalMenus::TRANSIENT]['expiration']);
    }

    public function testCaptureIgnoresUsersWithoutManageOptions(): void
    {
        $GLOBALS['menu'] = [['Tools', 'manage_options', 'tools.php', '', 'menu-top']];
        $_GET['page'] = ExternalMenus::SCREEN;
        $external = new ExternalMenus();
        $external->capture();
        self::assertSame('tools.php', $external->get()['parents'][0]['slug']);

        // A subscriber opening the screen URL (admin_menu runs before the access check).
        $GLOBALS['pb_test_current_user'] = 7;
        $GLOBALS['pb_test_caps'][7] = ['read'];
        $GLOBALS['menu'] = [['Profile', 'read', 'profile.php', '', 'menu-top']];
        $external->capture();

        $GLOBALS['pb_test_current_user'] = 1;
        self::assertSame('tools.php', $external->get()['parents'][0]['slug'], 'the cached admin menu is kept');
    }

    public function testDeclaredPagesAreNotListedAsExternal(): void
    {
        $GLOBALS['menu'] = [
            ['Theme Settings', 'manage_options', 'theme-settings', '', 'menu-top'],
            ['Tools', 'manage_options', 'tools.php', '', 'menu-top'],
        ];
        $external = new ExternalMenus();
        $_GET['page'] = ExternalMenus::SCREEN;
        $external->capture();

        $slugs = array_column($this->routes(true, $external)->index()['external'], 'slug');

        self::assertNotContains('theme-settings', $slugs);
        self::assertContains('tools.php', $slugs);
    }

    public function testCapturedMenuIsNarrowedPerViewer(): void
    {
        $GLOBALS['menu'] = [
            ['Shop', 'manage_woocommerce', 'woocommerce', '', 'menu-top'],
            ['Tools', 'manage_options', 'tools.php', '', 'menu-top'],
        ];
        $GLOBALS['submenu'] = ['woocommerce' => [['Orders', 'manage_woocommerce', 'wc-orders']]];
        $external = new ExternalMenus();
        $_GET['page'] = ExternalMenus::SCREEN;
        $external->capture();

        // manage_options only (the fixture user): the WooCommerce entries are hidden everywhere.
        $got = $external->get();
        self::assertSame(['tools.php'], array_column($got['pages'], 'slug'));
        self::assertSame(['tools.php'], array_column($got['parents'], 'slug'));
        self::assertSame(['tools.php'], array_column($this->routes(true, $external)->index()['external'], 'slug'));
        $detailParents = array_column($this->routes(true, $external)->show(new \WP_REST_Request(['slug' => 'theme-settings']))['parents'], 'slug');
        self::assertNotContains('woocommerce', $detailParents);
        self::assertStringNotContainsString('manage_woocommerce', (string) json_encode($got));

        $GLOBALS['pb_test_caps'][1][] = 'manage_woocommerce';
        $got = $external->get();
        self::assertSame(['woocommerce', 'tools.php'], array_column($got['parents'], 'slug'));
        self::assertContains('wc-orders', array_column($got['pages'], 'slug'));
        $detailParents = array_column($this->routes(true, $external)->show(new \WP_REST_Request(['slug' => 'theme-settings']))['parents'], 'slug');
        self::assertContains('woocommerce', $detailParents);
    }

    public function testUnresolvableCallbackIsUnknownOrigin(): void
    {
        $GLOBALS['menu'] = [['Mystery', 'manage_options', 'mystery', '', 'menu-top']];
        $external = new ExternalMenus();
        $_GET['page'] = ExternalMenus::SCREEN;
        $external->capture();

        $row = $external->get()['pages'][0];
        self::assertSame('unknown', $row['origin']);
        self::assertSame('Unknown', $row['originLabel']);
    }

    public function testCacheIsDroppedWhenPluginsOrThemeChange(): void
    {
        (new ExternalMenus())->boot();

        foreach (['admin_menu', 'activated_plugin', 'deactivated_plugin', 'switch_theme'] as $hook) {
            self::assertNotEmpty($GLOBALS['pb_test_hooks'][$hook] ?? [], $hook);
        }
        foreach (['activated_plugin', 'deactivated_plugin', 'switch_theme'] as $hook) {
            $GLOBALS['pb_test_transients'][ExternalMenus::TRANSIENT] = ['value' => ['pages' => [], 'parents' => []], 'expiration' => 3600];
            $entry = end($GLOBALS['pb_test_hooks'][$hook]);
            ($entry['callback'])('acme/acme.php');
            self::assertArrayNotHasKey(ExternalMenus::TRANSIENT, $GLOBALS['pb_test_transients'], $hook);
        }
    }

    public function testStoredGarbageIsIgnored(): void
    {
        $GLOBALS['pb_test_transients'][ExternalMenus::TRANSIENT] = ['value' => [
            'pages' => [['slug' => 'x'], 'junk', ['slug' => 'y', 'title' => 'Y', 'parent' => 'top', 'origin' => 'martian', 'originLabel' => 'M']],
            'parents' => [['slug' => 5], 'junk'],
        ], 'expiration' => 3600];

        self::assertSame(['pages' => [], 'parents' => []], (new ExternalMenus())->get());
    }

    public function testRouteGroupRegistered(): void
    {
        self::assertContains(OptionsPagesRoutes::class, AdminRestController::ROUTE_GROUPS);
    }
}
