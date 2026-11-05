<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects;

use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Registry as ContentModelRegistry;
use ProtoBlocks\Modules\Redirects\Coexistence;
use ProtoBlocks\Modules\Redirects\Engine\Conditions\Ip;
use ProtoBlocks\Modules\Redirects\RedirectsModule;

/**
 * Review finding: the permalink monitor built by RedirectsModule::boot()
 * must obey the same file-write gate as every other writer
 * (AdminRestController::fileWritesAllowed(): file mods AND, on multisite,
 * a network administrator). A sub-site editor renaming a post must not
 * write into the network-shared theme.
 */
final class MonitorMultisiteTest extends RedirectsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb'] = new \wpdb();
        $GLOBALS['pb_test_options'] = ['permalink_structure' => '/%postname%/'];
        $GLOBALS['pb_test_cron'] = [];
        $GLOBALS['pb_test_actions'] = [];
        $GLOBALS['pb_test_dbdelta'] = [];
        $GLOBALS['pb_test_posts'] = [];
        $GLOBALS['pb_test_permalinks'] = [];
        Coexistence::setDetector(static fn(): bool => false);
    }

    protected function tearDown(): void
    {
        Coexistence::setDetector(null);
        Ip::setResolver(null);
        $GLOBALS['wpdb'] = new \wpdb();
        $GLOBALS['pb_test_options'] = [];
        $GLOBALS['pb_test_cron'] = [];
        $GLOBALS['pb_test_actions'] = [];
        $GLOBALS['pb_test_posts'] = [];
        $GLOBALS['pb_test_permalinks'] = [];
        unset($GLOBALS['pb_test_dbdelta'], $GLOBALS['pb_test_multisite'], $GLOBALS['pb_test_super_admins'], $GLOBALS['pb_test_current_user']);
        parent::tearDown();
    }

    public function testSubSiteEditorRenamingAPostDoesNotWriteTheSharedTheme(): void
    {
        $this->put($this->child, 'proto-redirects/settings.jsonc', '{"monitor": {"postTypes": ["post"]}}');
        $GLOBALS['pb_test_multisite'] = true;
        $GLOBALS['pb_test_super_admins'] = [1];
        $GLOBALS['pb_test_current_user'] = 7; // a sub-site editor, not a network administrator
        $GLOBALS['pb_test_hooks'] = [];

        $module = new RedirectsModule(new ContentModelRegistry(new FileLoader(), static fn(): array => []));
        $module->boot();

        // Ruling: on multisite the monitor is never registered, so no post hook can write.
        self::assertNull($module->monitor());
        foreach (['pre_post_update', 'post_updated', 'wp_trash_post', 'trashed_post'] as $hook) {
            self::assertFalse(has_action($hook), $hook . ' must not be hooked on multisite');
        }
        self::assertFileDoesNotExist(
            $this->child . '/proto-redirects/modified-posts/redirects.jsonc',
            'a non-super-admin on multisite wrote a redirect into the network-shared theme'
        );

        $found = array_values(array_filter($module->issues(), static fn($i): bool => $i->code === 'monitor_unwritable'));
        self::assertCount(1, $found);
        self::assertSame('warning', $found[0]->level);
        self::assertStringContainsString('multisite', $found[0]->message);
        self::assertSame('settings', $found[0]->slug);
    }

    public function testMultisiteWithoutWatchedPostTypesRecordsNothing(): void
    {
        $GLOBALS['pb_test_multisite'] = true;
        $module = new RedirectsModule(new ContentModelRegistry(new FileLoader(), static fn(): array => []));
        $module->boot();

        self::assertNull($module->monitor());
        self::assertSame([], array_values(array_filter($module->issues(), static fn($i): bool => $i->code === 'monitor_unwritable')));
    }

    public function testSingleSiteStillRegistersTheMonitor(): void
    {
        $this->put($this->child, 'proto-redirects/settings.jsonc', '{"monitor": {"postTypes": ["post"]}}');
        $module = new RedirectsModule(new ContentModelRegistry(new FileLoader(), static fn(): array => []));
        $module->boot();

        self::assertNotNull($module->monitor());
    }
}
