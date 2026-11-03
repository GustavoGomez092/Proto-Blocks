<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\API;

// Same stub order as AdminRestControllerTest (first definition wins).
require_once __DIR__ . '/../Security/wp-stubs.php';
require_once __DIR__ . '/../Admin/wp-stubs.php';

use PHPUnit\Framework\TestCase;
use ProtoBlocks\API\Admin\TailwindRoutes;
use ProtoBlocks\API\AdminRestController;

/**
 * admin/tailwind routes behind the React Tailwind screen: settings
 * normalisation, permission gates and the starter theme file.
 */
final class TailwindRoutesTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $GLOBALS['pb_test_current_user'] = 1;
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options']];
        $GLOBALS['pb_test_routes'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pb_test_current_user'], $GLOBALS['pb_test_caps'], $GLOBALS['pb_test_routes']);
        if ($this->dir !== '') {
            foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file) || is_link($file)) {
                    unlink($file);
                }
            }
            @rmdir($this->dir);
        }
    }

    public function testNormalizeTailwindSettingsAcceptsSubset(): void
    {
        self::assertSame(
            ['mode' => 'on_reload', 'enabled' => true],
            TailwindRoutes::normalizeTailwindSettings(['mode' => 'on_reload', 'enabled' => '1'])
        );
        self::assertSame(
            ['engine' => 'browser', 'disableGlobalStyles' => false],
            TailwindRoutes::normalizeTailwindSettings(['engine' => 'browser', 'disableGlobalStyles' => false])
        );
    }

    public function testNormalizeTailwindSettingsRejectsBadEngine(): void
    {
        $error = TailwindRoutes::normalizeTailwindSettings(['engine' => 'wasm']);

        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('proto_blocks_invalid_setting', $error->get_error_code());
        self::assertSame(400, $error->get_error_data()['status']);
    }

    public function testNormalizeTailwindSettingsRejectsBadValues(): void
    {
        $inputs = [
            ['mode' => 'sometimes'],
            ['enabled' => 'maybe'],
            ['disableGlobalStyles' => []],
            ['engine' => ['cli']],
            ['mode' => "cached\n"],
        ];
        foreach ($inputs as $input) {
            $error = TailwindRoutes::normalizeTailwindSettings($input);
            self::assertInstanceOf(\WP_Error::class, $error, (string) json_encode($input));
            self::assertSame('proto_blocks_invalid_setting', $error->get_error_code());
        }
    }

    public function testNormalizeTailwindSettingsIgnoresUnknownKeys(): void
    {
        self::assertSame([], TailwindRoutes::normalizeTailwindSettings(['foo' => 1]));
    }

    public function testRouteGroupsListTailwindRoutes(): void
    {
        self::assertContains(TailwindRoutes::class, AdminRestController::ROUTE_GROUPS);
    }

    public function testRoutesAndPermissionGates(): void
    {
        $controller = new AdminRestController([new TailwindRoutes()]);
        $controller->registerRoutes();

        $endpoints = [];
        foreach ($GLOBALS['pb_test_routes'] as $route) {
            self::assertSame('proto-blocks/v1', $route['namespace']);
            foreach ($route['args'] as $endpoint) {
                $endpoints[$endpoint['methods'] . ' ' . $route['route']] = $endpoint['permission_callback'];
            }
        }

        self::assertSame([
            'GET /admin/tailwind',
            'POST /admin/tailwind/settings',
            'POST /admin/tailwind/compile',
            'DELETE /admin/tailwind/cache',
            'POST /admin/tailwind/cli',
            'POST /admin/tailwind/theme-file',
        ], array_keys($endpoints));

        foreach ($endpoints as $permission) {
            self::assertTrue($permission());
        }
        self::assertSame([$controller, 'writePermission'], $endpoints['POST /admin/tailwind/cli']);
        self::assertSame([$controller, 'writePermission'], $endpoints['POST /admin/tailwind/theme-file']);
        self::assertSame([$controller, 'permission'], $endpoints['POST /admin/tailwind/compile']);
        self::assertSame([$controller, 'permission'], $endpoints['POST /admin/tailwind/settings']);

        $GLOBALS['pb_test_caps'] = [1 => ['edit_posts']];
        foreach ($endpoints as $permission) {
            self::assertFalse($permission());
        }
    }

    public function testCreateThemeFileWritesStarterAndRefusesToOverwrite(): void
    {
        $this->dir = sys_get_temp_dir() . '/pb-tw-theme-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $path = $this->dir . '/tailwind-theme.css';

        $routes = new TailwindRoutes();
        self::assertTrue($routes->createThemeFileAt($path));
        self::assertStringContainsString('@theme {', (string) file_get_contents($path));

        file_put_contents($path, '@theme { --color-x: red; }');
        $error = $routes->createThemeFileAt($path);
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('proto_blocks_exists', $error->get_error_code());
        self::assertSame(409, $error->get_error_data()['status']);
        self::assertSame('@theme { --color-x: red; }', file_get_contents($path), 'existing file untouched');
    }

    public function testCreateThemeFileRefusesDanglingSymlink(): void
    {
        $this->dir = sys_get_temp_dir() . '/pb-tw-theme-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $path = $this->dir . '/tailwind-theme.css';
        symlink($this->dir . '/missing-target.css', $path);

        $error = (new TailwindRoutes())->createThemeFileAt($path);
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('proto_blocks_exists', $error->get_error_code());
    }

    public function testCreateThemeFileFailsWhenFolderMissing(): void
    {
        $path = sys_get_temp_dir() . '/pb-no-such-dir-' . bin2hex(random_bytes(4)) . '/tailwind-theme.css';

        $error = (new TailwindRoutes())->createThemeFileAt($path);
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('proto_blocks_not_writable', $error->get_error_code());
        self::assertSame(500, $error->get_error_data()['status']);
    }
}
