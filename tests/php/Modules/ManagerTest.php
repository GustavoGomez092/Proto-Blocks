<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\PhpDeclarations;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\RewriteHash;
use ProtoBlocks\Modules\Manager;
use ProtoBlocks\Modules\PostTypes;
use ProtoBlocks\Modules\Taxonomies;

require_once __DIR__ . '/../CLI/wp-stubs.php';
require_once __DIR__ . '/../ContentModel/stubs/hooks.php';
require_once __DIR__ . '/../../../includes/content-model.php';

final class ManagerTest extends TestCase
{
    private const GLOBALS = ['pb_test_hooks', 'pb_test_registered', 'pb_test_post_types', 'pb_test_taxonomies', 'pb_test_filters', 'pb_test_tax_links'];

    private string $root;

    protected function setUp(): void
    {
        PhpDeclarations::reset();
        $tmp = sys_get_temp_dir() . '/pb-manager-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->root = (string) realpath($tmp);
        foreach (self::GLOBALS as $key) {
            $GLOBALS[$key] = [];
        }
        $GLOBALS['pb_test_theme'] = ['template' => $this->root, 'stylesheet' => $this->root];
        $GLOBALS['pb_test_is_admin'] = false;
        $GLOBALS['pb_test_flushes'] = 0;
        unset($GLOBALS['pb_test_options'][RewriteHash::OPTION]);
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        unset($GLOBALS['pb_test_theme'], $GLOBALS['pb_test_options'][RewriteHash::OPTION]);
        foreach (self::GLOBALS as $key) {
            $GLOBALS[$key] = [];
        }
        $GLOBALS['pb_test_is_admin'] = false;
        $GLOBALS['pb_test_flushes'] = 0;
        self::removeTree($this->root);
    }

    public function testDefaultsBootBothModules(): void
    {
        $manager = new Manager($this->registry());
        $manager->boot();

        $this->assertInstanceOf(PostTypes::class, $manager->postTypes());
        $this->assertInstanceOf(Taxonomies::class, $manager->taxonomies());
        $this->assertSame(Manager::DEFAULTS, $manager->states());
        $this->assertTrue($manager->isEnabled('post_types'));
        $this->assertTrue($manager->isEnabled('taxonomies'));
        $this->assertNotEmpty($GLOBALS['pb_test_hooks']['register_post_type_args'] ?? []);
        $this->assertNotEmpty($GLOBALS['pb_test_hooks']['register_taxonomy_args'] ?? []);
    }

    public function testFilterDisablesPostTypes(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_modules'][] = static fn (array $states) => ['post_types' => false] + $states;
        $manager = new Manager($this->registry());
        $manager->boot();

        $this->assertNull($manager->postTypes());
        $this->assertArrayNotHasKey('register_post_type_args', $GLOBALS['pb_test_hooks']);
        $this->assertFalse($manager->states()['post_types']);
        $this->assertFalse($manager->isEnabled('post_types'));
        $this->assertInstanceOf(Taxonomies::class, $manager->taxonomies());
        $this->assertTrue($manager->states()['taxonomies']);
    }

    public function testFilterReturningPartialArrayKeepsOtherDefaults(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_modules'][] = static fn () => ['taxonomies' => false, 'unknown' => true];
        $manager = new Manager($this->registry());
        $manager->boot();

        $this->assertSame(['post_types' => true, 'taxonomies' => false], $manager->states());
        $this->assertFalse($manager->isEnabled('unknown'));
        $this->assertNull($manager->taxonomies());
    }

    public function testBootTwiceHooksOnce(): void
    {
        $manager = new Manager($this->registry());
        $manager->boot();
        $manager->boot();

        $this->assertCount(1, $GLOBALS['pb_test_hooks']['register_post_type_args']);
    }

    public function testNoticeHookedOnlyInAdmin(): void
    {
        (new Manager($this->registry()))->boot();
        $this->assertArrayNotHasKey('admin_notices', $GLOBALS['pb_test_hooks']);

        $GLOBALS['pb_test_hooks'] = [];
        $GLOBALS['pb_test_is_admin'] = true;
        (new Manager($this->registry()))->boot();
        $this->assertArrayHasKey('admin_notices', $GLOBALS['pb_test_hooks']);
        $this->assertArrayHasKey('registered_post_type', $GLOBALS['pb_test_hooks']);
    }

    public function testOriginTrackerNotHookedOnFrontEnd(): void
    {
        (new Manager($this->registry()))->boot();

        $this->assertArrayNotHasKey('registered_post_type', $GLOBALS['pb_test_hooks']);
        $this->assertArrayNotHasKey('registered_taxonomy', $GLOBALS['pb_test_hooks']);
    }

    public function testFlushOnceAfterRegistration(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product", "taxonomies": [{"name": "Brand"}]}');
        $manager = new Manager($this->registry());
        $manager->boot();
        $this->assertSame(20, $this->priorityOf('wp_loaded', [$manager, 'maybeFlush']));

        $manager->postTypes()->register();
        $manager->taxonomies()->register();
        $manager->maybeFlush();
        $manager->maybeFlush();

        $this->assertSame(1, $GLOBALS['pb_test_flushes']);
    }

    public function testIssuesAreDeduplicated(): void
    {
        proto_post_type('Book', ['taxonomies' => [['name' => 'Genre', 'hierarchical' => 'yes']]]);
        $manager = new Manager($this->registry());
        $manager->boot();

        $manager->postTypes()->register();
        $manager->taxonomies()->register();
        $manager->postTypes()->filterArgs([], 'book');

        $schema = array_values(array_filter($manager->issues(), static fn (Issue $i) => $i->code === 'schema_invalid'));
        $this->assertCount(1, $schema);
        $this->assertStringContainsString('/taxonomies/0', $schema[0]->message);
    }

    public function testPluginBootsManagerAfterSetupTheme(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../includes/Core/Plugin.php');

        $this->assertStringContainsString("\$this->services['modules'] = new \\ProtoBlocks\\Modules\\Manager(\$this->getContentModel());", $source);
        $this->assertStringContainsString("add_action('after_setup_theme', [\$this->services['modules'], 'boot'], 1);", $source);
        $this->assertStringContainsString('public function getContentModel(): ', $source);
        $this->assertStringContainsString('public function getModules(): ', $source);
    }

    // ------------------------------------------------------------------ helpers

    private function registry(): Registry
    {
        $root = $this->root;
        return new Registry(new FileLoader(), static fn () => [$root]);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function priorityOf(string $hook, callable $callback): ?int
    {
        foreach ($GLOBALS['pb_test_hooks'][$hook] ?? [] as $entry) {
            if (is_array($entry) && array_key_exists('callback', $entry) && $entry['callback'] === $callback) {
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
