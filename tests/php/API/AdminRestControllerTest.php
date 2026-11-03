<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\API;

// Security stubs first: this file sorts before the other API tests, and the
// first definition of a stub wins for the whole run (Security's esc_url_raw
// actually sanitises; the Admin one passes URLs through).
require_once __DIR__ . '/../Security/wp-stubs.php';
require_once __DIR__ . '/../Admin/wp-stubs.php';

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\Admin\AdminPage;
use ProtoBlocks\API\Admin\DashboardRoutes;
use ProtoBlocks\API\Admin\SystemRoutes;
use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\Template\Cache;

/**
 * AdminRestController serves the React admin: one permission gate
 * (manage_options), a stricter one for routes that write files, and one
 * route group per screen.
 */
final class AdminRestControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pb_test_current_user'] = 1;
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options']];
        $GLOBALS['pb_test_routes'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pb_test_current_user'], $GLOBALS['pb_test_caps'], $GLOBALS['pb_test_routes']);
    }

    public function testRelativePathStripsRoot(): void
    {
        self::assertSame('wp-content/themes/t', AdminRestController::relativePath('/srv/wp/wp-content/themes/t', '/srv/wp/'));
        self::assertSame('wp-content/themes/t', AdminRestController::relativePath('/srv/wp/wp-content/themes/t', '/srv/wp'));
        self::assertSame('/opt/elsewhere/cache', AdminRestController::relativePath('/opt/elsewhere/cache', '/srv/wp/'));
        // A sibling that only shares the prefix string is outside the root.
        self::assertSame('/srv/wp-other/x', AdminRestController::relativePath('/srv/wp-other/x', '/srv/wp'));
    }

    public function testPermissionRequiresManageOptions(): void
    {
        $controller = new AdminRestController();
        self::assertTrue($controller->permission());

        $GLOBALS['pb_test_caps'] = [1 => ['edit_posts']];
        self::assertFalse($controller->permission());
        self::assertFalse($controller->writePermission());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWritePermissionFalseWhenFileModsDisabled(): void
    {
        $GLOBALS['pb_test_current_user'] = 1;
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options']];
        define('DISALLOW_FILE_MODS', true);

        $controller = new AdminRestController();

        self::assertTrue($controller->permission());
        self::assertFalse($controller->writePermission());
    }

    public function testFileModsErrorShape(): void
    {
        $error = AdminRestController::fileModsError();

        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('proto_blocks_file_mods', $error->get_error_code());
        self::assertSame(403, $error->get_error_data()['status']);
        self::assertNotSame('', $error->get_error_message());
    }

    public function testRouteGroupsListSystemRoutes(): void
    {
        self::assertContains(SystemRoutes::class, AdminRestController::ROUTE_GROUPS);
    }

    public function testRegisterRoutesDeclaresSystemRoutes(): void
    {
        (new AdminRestController())->registerRoutes();

        $found = null;
        foreach ($GLOBALS['pb_test_routes'] as $route) {
            if ($route['namespace'] === 'proto-blocks/v1' && $route['route'] === '/admin/system') {
                $found = $route;
            }
        }
        self::assertNotNull($found, 'admin/system is registered under proto-blocks/v1');

        $methods = [];
        foreach ($found['args'] as $endpoint) {
            self::assertIsArray($endpoint);
            self::assertArrayHasKey('permission_callback', $endpoint);
            self::assertTrue(($endpoint['permission_callback'])(), 'manage_options passes the gate');
            $methods[] = $endpoint['methods'];
        }
        self::assertContains('GET', $methods);
        self::assertContains('POST', $methods);
    }

    public function testSystemRoutesGateOnManageOptions(): void
    {
        $GLOBALS['pb_test_caps'] = [1 => ['edit_posts']];
        (new AdminRestController())->registerRoutes();

        self::assertNotEmpty($GLOBALS['pb_test_routes']);
        foreach ($GLOBALS['pb_test_routes'] as $route) {
            foreach ($route['args'] as $endpoint) {
                self::assertFalse(($endpoint['permission_callback'])());
            }
        }
    }

    public function testSystemPayloadAndCategorySave(): void
    {
        $dir = sys_get_temp_dir() . '/pb-admin-rest-' . bin2hex(random_bytes(4));
        $routes = new SystemRoutes(new Cache($dir));
        $GLOBALS['pb_test_options'] = [AdminPage::OPTION_CATEGORY_NAME => 'Proto Blocks'];

        $data = $routes->get();
        self::assertSame('Proto Blocks', $data['categoryName']);
        self::assertSame(['debug', 'cacheEnabled', 'exampleBlocks', 'exampleBlocksFromConfig'], array_keys($data['config']));
        self::assertSame(['pluginVersion', 'wpVersion', 'phpVersion', 'cacheDir', 'cacheDirWritable'], array_keys($data['system']));
        self::assertSame(PHP_VERSION, $data['system']['phpVersion']);
        self::assertTrue($data['system']['cacheDirWritable']);

        $saved = $routes->update(new \WP_REST_Request(['categoryName' => '  Shop blocks ']));
        self::assertSame('Shop blocks', $GLOBALS['pb_test_options'][AdminPage::OPTION_CATEGORY_NAME]);
        self::assertSame('Shop blocks', $saved['categoryName']);

        $rejected = $routes->update(new \WP_REST_Request(['categoryName' => str_repeat('a', 101)]));
        self::assertInstanceOf(\WP_Error::class, $rejected);
        self::assertSame('Shop blocks', $GLOBALS['pb_test_options'][AdminPage::OPTION_CATEGORY_NAME]);

        unset($GLOBALS['pb_test_options']);
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($dir);
    }

    public function testCategoryNameValidationRejectsLongNames(): void
    {
        self::assertTrue(SystemRoutes::validateCategoryName('Shop blocks'));
        self::assertTrue(SystemRoutes::validateCategoryName(''));
        self::assertTrue(SystemRoutes::validateCategoryName(str_repeat('é', 100)));

        $error = SystemRoutes::validateCategoryName(str_repeat('a', 101));
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('proto_blocks_invalid_category_name', $error->get_error_code());
        self::assertSame(400, $error->get_error_data()['status']);

        self::assertInstanceOf(\WP_Error::class, SystemRoutes::validateCategoryName(['x']));
    }

    public function testExampleBlocksStateLockedByConfig(): void
    {
        self::assertSame(
            ['enabled' => true, 'locked' => true],
            AdminRestController::exampleBlocksState(true, true, false)
        );
    }

    public function testExampleBlocksStateFromOption(): void
    {
        self::assertSame(
            ['enabled' => true, 'locked' => false],
            AdminRestController::exampleBlocksState(false, false, '1')
        );
        self::assertSame(
            ['enabled' => false, 'locked' => false],
            AdminRestController::exampleBlocksState(false, true, false)
        );
    }

    public function testValidateDemoTypeRejectsUnknown(): void
    {
        $error = AdminRestController::validateDemoType('all', true);

        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('proto_blocks_invalid_type', $error->get_error_code());
        self::assertSame(400, $error->get_error_data()['status']);
    }

    public function testValidateDemoTypeRequiresTailwind(): void
    {
        $error = AdminRestController::validateDemoType('tailwind', false);

        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('proto_blocks_tailwind_disabled', $error->get_error_code());
        self::assertSame(409, $error->get_error_data()['status']);
        self::assertNull(AdminRestController::validateDemoType('tailwind', true));
        self::assertNull(AdminRestController::validateDemoType('vanilla', false));
    }

    public function testRouteGroupsListDashboardRoutes(): void
    {
        self::assertContains(DashboardRoutes::class, AdminRestController::ROUTE_GROUPS);
    }

    public function testDashboardRoutesRegisterCacheDeleteBehindPermission(): void
    {
        (new AdminRestController())->registerRoutes();

        $found = null;
        foreach ($GLOBALS['pb_test_routes'] as $route) {
            if ($route['route'] === '/admin/cache') {
                $found = $route;
            }
        }
        self::assertNotNull($found, 'admin/cache is registered');
        self::assertSame('DELETE', $found['args'][0]['methods']);

        $GLOBALS['pb_test_caps'] = [1 => ['edit_posts']];
        self::assertFalse(($found['args'][0]['permission_callback'])());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExampleBlocksPostIsRejectedWhenLockedBeforeWriting(): void
    {
        define('PROTO_BLOCKS_EXAMPLE_BLOCKS_FROM_CONFIG', true);
        define('PROTO_BLOCKS_EXAMPLE_BLOCKS', true);
        $GLOBALS['pb_test_options'] = [];

        $routes = new DashboardRoutes(new AdminPage(new Cache(sys_get_temp_dir() . '/pb-dash-' . bin2hex(random_bytes(4)))));
        $result = $routes->setExampleBlocks(new \WP_REST_Request(['enabled' => false]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('proto_blocks_locked', $result->get_error_code());
        self::assertSame(409, $result->get_error_data()['status']);
        self::assertArrayNotHasKey(AdminPage::OPTION_EXAMPLE_BLOCKS, $GLOBALS['pb_test_options']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInstallAndRemoveReturnFileModsErrorWhenDisabled(): void
    {
        define('DISALLOW_FILE_MODS', true);

        $routes = new DashboardRoutes(new AdminPage(new Cache(sys_get_temp_dir() . '/pb-dash-' . bin2hex(random_bytes(4)))));

        foreach ([$routes->install(new \WP_REST_Request(['type' => 'vanilla'])), $routes->remove()] as $result) {
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame('proto_blocks_file_mods', $result->get_error_code());
            self::assertSame(403, $result->get_error_data()['status']);
        }
    }
}
