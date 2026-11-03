<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\OriginTracker;

require_once __DIR__ . '/stubs/hooks.php';

final class OriginTrackerTest extends TestCase
{
    private const PLUGINS = '/wp/wp-content/plugins';
    private const MU = '/wp/wp-content/mu-plugins';
    private const THEMES = ['/wp/wp-content/themes'];
    private const PROTO = '/wp/wp-content/plugins/proto-blocks';

    protected function setUp(): void
    {
        $GLOBALS['pb_test_hooks'] = [];
        $GLOBALS['pb_test_post_types'] = [];
        $GLOBALS['pb_test_taxonomies'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['pb_test_hooks'] = [];
        $GLOBALS['pb_test_post_types'] = [];
        $GLOBALS['pb_test_taxonomies'] = [];
    }

    public function testClassifyPlugin(): void
    {
        $this->assertSame(
            ['type' => 'plugin', 'name' => 'woocommerce'],
            $this->classify('/wp/wp-content/plugins/woocommerce/includes/class-wc-post-types.php')
        );
    }

    public function testClassifySingleFilePlugin(): void
    {
        $this->assertSame(['type' => 'plugin', 'name' => 'hello'], $this->classify('/wp/wp-content/plugins/hello.php'));
    }

    public function testClassifyMuPlugin(): void
    {
        $this->assertSame(['type' => 'plugin', 'name' => 'site-types'], $this->classify('/wp/wp-content/mu-plugins/site-types.php'));
    }

    public function testClassifyTheme(): void
    {
        $this->assertSame(['type' => 'theme', 'name' => 'optimizedit'], $this->classify('/wp/wp-content/themes/optimizedit/inc/post-types.php'));
    }

    public function testClassifyProto(): void
    {
        $this->assertSame(['type' => 'proto', 'name' => null], $this->classify('/wp/wp-content/plugins/proto-blocks/includes/Modules/PostTypes.php'));
    }

    public function testClassifyCore(): void
    {
        $this->assertSame(['type' => 'core', 'name' => null], $this->classify('/wp/wp-includes/post.php'));
        $this->assertSame(['type' => 'core', 'name' => null], $this->classify('/wp/wp-settings.php'));
    }

    public function testClassifyUnknown(): void
    {
        $this->assertSame(['type' => 'unknown', 'name' => null], $this->classify('/elsewhere/file.php'));
        $this->assertSame(['type' => 'unknown', 'name' => null], $this->classify('/wp/wp-content/plugins-old/x/y.php'));
    }

    public function testClassifyWindowsSeparators(): void
    {
        $this->assertSame(
            ['type' => 'plugin', 'name' => 'woocommerce'],
            OriginTracker::classify('C:\\wp\\wp-content\\plugins\\woocommerce\\x.php', 'C:\\wp\\wp-content\\plugins', '', [], 'C:\\wp\\wp-content\\plugins\\proto-blocks')
        );
    }

    public function testBootHooksRegistrationActionsLast(): void
    {
        $tracker = new OriginTracker();
        $tracker->boot();

        foreach (['registered_post_type', 'registered_taxonomy'] as $hook) {
            $entry = $GLOBALS['pb_test_hooks'][$hook][0] ?? null;
            $this->assertIsArray($entry);
            $this->assertSame(PHP_INT_MAX, $entry['priority']);
        }
    }

    public function testBuiltInReportsCore(): void
    {
        $GLOBALS['pb_test_post_types']['post'] = (object) ['name' => 'post', '_builtin' => true];
        $GLOBALS['pb_test_taxonomies']['category'] = (object) ['name' => 'category', '_builtin' => true];
        $tracker = new OriginTracker();

        $this->assertSame(['type' => 'core', 'name' => null], $tracker->originOf('type', 'post'));
        $this->assertSame(['type' => 'core', 'name' => null], $tracker->originOf('taxonomy', 'category'));
    }

    public function testUnrecordedReportsUnknown(): void
    {
        $this->assertSame(['type' => 'unknown', 'name' => null], (new OriginTracker())->originOf('type', 'nothing'));
    }

    public function testRecordsCallerOfRegistration(): void
    {
        $tracker = new OriginTracker();
        $tracker->recordPostType('book');
        $GLOBALS['pb_test_post_types']['book'] = (object) ['name' => 'book', '_builtin' => false];

        // The caller is this test file, inside the plugin's own directory.
        $this->assertSame(['type' => 'proto', 'name' => null], $tracker->originOf('type', 'book'));
    }

    /** @return array{type: string, name: ?string} */
    private function classify(string $file): array
    {
        return OriginTracker::classify($file, self::PLUGINS, self::MU, self::THEMES, self::PROTO);
    }
}
