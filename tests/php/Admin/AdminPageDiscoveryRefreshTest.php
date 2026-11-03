<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Admin;

require_once __DIR__ . '/wp-stubs.php';

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\Admin\AdminPage;
use ProtoBlocks\Blocks\Discovery;
use ProtoBlocks\Template\Cache;

/**
 * The Blocks screen rebuilds its table from the dashboard payload each
 * write route returns. That payload must describe the registry after the
 * write: demo install/remove and the Example Blocks switch rescan before
 * dashboardData() is built.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminPageDiscoveryRefreshTest extends TestCase
{
    private string $theme;
    private string $cacheDir;

    protected function setUp(): void
    {
        if (!defined('PROTO_BLOCKS_DIR')) {
            define('PROTO_BLOCKS_DIR', dirname(__DIR__, 3) . '/');
        }
        if (!defined('PROTO_BLOCKS_CACHE_ENABLED')) {
            define('PROTO_BLOCKS_CACHE_ENABLED', true);
        }
        if (!function_exists('size_format')) {
            eval('function size_format($bytes, $decimals = 0) { return $bytes . " B"; }');
        }

        $this->theme = sys_get_temp_dir() . '/pb-refresh-theme-' . bin2hex(random_bytes(4));
        $this->cacheDir = sys_get_temp_dir() . '/pb-refresh-cache-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->theme, 0777, true);
        mkdir($this->cacheDir, 0777, true);

        $GLOBALS['pb_test_theme'] = ['template' => $this->theme, 'stylesheet' => $this->theme];
        $GLOBALS['pb_test_options'] = ['proto_blocks_example_blocks' => false];
        $GLOBALS['pb_test_current_user'] = 1;
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options']];
        $GLOBALS['pb_test_transients'] = ['proto_blocks_discovered' => ['value' => ['stale' => '/x'], 'expiration' => 0]];
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->theme);
        $this->rmrf($this->cacheDir);
    }

    private function page(): AdminPage
    {
        return new AdminPage(new Cache($this->cacheDir), new Discovery());
    }

    /** @return list<string> */
    private static function rowNames(array $data): array
    {
        return array_column($data['blocks'], 'name');
    }

    public function testInstallAndRemoveResponsesDescribeTheNewRegistry(): void
    {
        $page = $this->page();

        // Prime discovery with the empty theme, as the page load does.
        $before = $page->dashboardData();
        self::assertSame(0, $before['stats']['blocks']);

        $installed = $page->installDemoBlocks('vanilla');
        self::assertIsInt($installed);
        self::assertGreaterThan(0, $installed);
        self::assertArrayNotHasKey('proto_blocks_discovered', $GLOBALS['pb_test_transients']);

        $after = $page->dashboardData();
        self::assertSame($installed, $after['stats']['blocks']);
        self::assertSame($page->getDemoBlockNamesByType('vanilla'), self::sorted(self::rowNames($after)));

        $removed = $page->removeDemoBlocks();
        self::assertSame($installed, $removed);

        $final = $page->dashboardData();
        self::assertSame(0, $final['stats']['blocks']);
        self::assertSame([], $final['blocks']);
    }

    public function testExampleBlocksSwitchIsSeenAfterRefresh(): void
    {
        $page = $this->page();
        self::assertSame(0, $page->dashboardData()['stats']['blocks']);

        $GLOBALS['pb_test_options']['proto_blocks_example_blocks'] = true;
        $page->refreshDiscovery();
        $on = $page->dashboardData();
        self::assertGreaterThan(0, $on['stats']['blocks']);
        self::assertTrue($on['exampleBlocks']['enabled']);

        $GLOBALS['pb_test_options']['proto_blocks_example_blocks'] = false;
        $page->refreshDiscovery();
        self::assertSame(0, $page->dashboardData()['stats']['blocks']);
    }

    /** @param list<string> $names */
    private static function sorted(array $names): array
    {
        sort($names);
        return $names;
    }

    private function rmrf(string $dir): void
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
