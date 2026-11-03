<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Admin;

require_once __DIR__ . '/wp-stubs.php';

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Admin\AdminPage;
use ProtoBlocks\Admin\SetupWizard;
use ProtoBlocks\API\Admin\WizardRoutes;
use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\Template\Cache;

/**
 * The wizard's state lives in options; the React screen drives it over REST.
 */
final class SetupWizardFlowTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        if (!defined('PROTO_BLOCKS_DIR')) {
            define('PROTO_BLOCKS_DIR', dirname(__DIR__, 3) . '/');
        }
        if (!function_exists('trailingslashit')) {
            eval('function trailingslashit($value) { return rtrim((string) $value, "/\\\\") . "/"; }');
        }
        if (!function_exists('get_stylesheet_directory')) {
            eval('function get_stylesheet_directory() { return sys_get_temp_dir() . "/pb-theme"; }');
        }
        if (!function_exists('sanitize_key')) {
            eval('function sanitize_key($key) { return preg_replace("/[^a-z0-9_\\\\-]/", "", strtolower((string) $key)); }');
        }
        $GLOBALS['pb_test_options'] = [];
        $GLOBALS['pb_test_current_user'] = 1;
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options']];
        $GLOBALS['pb_test_routes'] = [];
        $this->cacheDir = sys_get_temp_dir() . '/pb-wizard-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pb_test_options'], $GLOBALS['pb_test_current_user'], $GLOBALS['pb_test_caps'], $GLOBALS['pb_test_routes']);
    }

    private function wizard(): SetupWizard
    {
        return new SetupWizard(new AdminPage(new Cache($this->cacheDir)));
    }

    public function testSaveStyleNormalisesAndStoresBothOptions(): void
    {
        $this->wizard()->saveStyle('Tailwind ', 'Shop');

        self::assertSame('tailwind', $GLOBALS['pb_test_options']['proto_blocks_component_style']);
        self::assertSame('Shop', $GLOBALS['pb_test_options'][AdminPage::OPTION_CATEGORY_NAME]);
    }

    public function testTailwindStyleEnablesTailwind(): void
    {
        $this->wizard()->saveStyle('tailwind', '');

        $settings = $GLOBALS['pb_test_options'][\ProtoBlocks\Tailwind\Manager::OPTION_NAME] ?? [];
        self::assertTrue($settings['enabled'] ?? false);
    }

    public function testUnknownStyleStoresVanilla(): void
    {
        $this->wizard()->saveStyle('bogus', '');

        self::assertSame('vanilla', $GLOBALS['pb_test_options']['proto_blocks_component_style']);
    }

    public function testCompleteStoresTheOption(): void
    {
        $this->wizard()->complete();

        self::assertTrue($GLOBALS['pb_test_options']['proto_blocks_wizard_completed']);
    }

    public function testDataReportsCompletion(): void
    {
        $wizard = $this->wizard();
        self::assertFalse($wizard->data()['completed']);

        $wizard->complete();
        self::assertTrue($wizard->data()['completed']);
    }

    public function testDataShape(): void
    {
        $data = $this->wizard()->data();

        self::assertSame('vanilla', $data['style']);
        self::assertSame('', $data['categoryName']);
        self::assertSame(['vanilla', 'tailwind'], array_keys($data['demos']));
        self::assertIsArray($data['demos']['vanilla']);
        self::assertStringEndsWith('/proto-blocks/', $data['installPath']);
    }

    public function testRoutesAreRegisteredAndOnlyDemosNeedWritePermission(): void
    {
        $routes = new WizardRoutes($this->wizard());
        $routes->registerRoutes('proto-blocks/v1', static fn(): bool => true, static fn(): bool => false);

        $byRoute = [];
        foreach ($GLOBALS['pb_test_routes'] as $route) {
            $byRoute[$route['route']] = ($route['args'][0]['permission_callback'])();
        }

        self::assertSame(
            ['/admin/wizard' => true, '/admin/wizard/style' => true, '/admin/wizard/demos' => false, '/admin/wizard/complete' => true],
            $byRoute
        );
        self::assertContains(WizardRoutes::class, AdminRestController::ROUTE_GROUPS);
    }

    public function testCompleteRouteReturnsDashboardRedirect(): void
    {
        $routes = new WizardRoutes($this->wizard());

        self::assertSame(
            ['redirect' => admin_url('admin.php?page=proto-blocks')],
            $routes->complete()
        );
        self::assertTrue($GLOBALS['pb_test_options']['proto_blocks_wizard_completed']);
    }
}
