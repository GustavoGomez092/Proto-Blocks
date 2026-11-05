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

        $module = new RedirectsModule(new ContentModelRegistry(new FileLoader(), static fn(): array => []));
        $module->boot();
        $monitor = $module->monitor();
        self::assertNotNull($monitor);

        $post = (object) ['ID' => 5, 'post_status' => 'publish', 'post_type' => 'post', 'post_title' => 'Hello'];
        $GLOBALS['pb_test_posts'][5] = $post;
        $GLOBALS['pb_test_permalinks'][5] = 'https://example.test/hello-world/';
        $monitor->onPrePostUpdate(5, []);
        $GLOBALS['pb_test_permalinks'][5] = 'https://example.test/hello-there/';
        $monitor->onPostUpdated(5, $post, $post);

        self::assertFileDoesNotExist(
            $this->child . '/proto-redirects/modified-posts/redirects.jsonc',
            'a non-super-admin on multisite wrote a redirect into the network-shared theme'
        );
    }
}
