<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\PhpDeclarations;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\Modules\Taxonomies;

require_once __DIR__ . '/../CLI/wp-stubs.php';
require_once __DIR__ . '/../ContentModel/stubs/hooks.php';
require_once __DIR__ . '/../../../includes/content-model.php';

final class TaxonomiesTest extends TestCase
{
    private const GLOBALS = ['pb_test_hooks', 'pb_test_registered', 'pb_test_post_types', 'pb_test_taxonomies', 'pb_test_tax_links', 'pb_test_filters'];

    private string $root;

    protected function setUp(): void
    {
        PhpDeclarations::reset();
        $tmp = sys_get_temp_dir() . '/pb-taxonomies-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->root = (string) realpath($tmp);
        foreach (self::GLOBALS as $key) {
            $GLOBALS[$key] = [];
        }
        $GLOBALS['pb_test_theme'] = ['template' => $this->root, 'stylesheet' => $this->root];
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        unset($GLOBALS['pb_test_theme']);
        foreach (self::GLOBALS as $key) {
            $GLOBALS[$key] = [];
        }
        self::removeTree($this->root);
    }

    public function testBootHooks(): void
    {
        $module = new Taxonomies($this->registry());
        $module->boot();

        $this->assertSame('taxonomies', $module->id());
        $this->assertSame(10, $this->priorityOf('init', [$module, 'register']));
        $this->assertSame(20, $this->priorityOf('init', [$module, 'attachObjectTypes']));
        $this->assertSame(10, $this->priorityOf('register_taxonomy_args', [$module, 'filterArgs']));
        $this->assertNotNull($this->priorityOf('wp_loaded', [$module, 'resolvePending']));
    }

    public function testInlineTaxonomyRegisteredWithParentType(): void
    {
        $this->write('proto-types/book/type.jsonc', '{"name": "Book", "taxonomies": [{"name": "Genre", "hierarchical": true}]}');
        $module = new Taxonomies($this->registry());

        $module->register();

        $call = $GLOBALS['pb_test_registered']['taxonomy']['genre'] ?? null;
        $this->assertIsArray($call);
        $this->assertSame(['book'], $call['object_types']);
        $this->assertTrue($call['args']['hierarchical']);
        $this->assertSame(['genre'], $module->registered());
    }

    public function testCategoryDeclarationIsOverride(): void
    {
        $this->write('proto-taxonomies/category/taxonomy.jsonc', '{"name": "Category", "labels": {"menu_name": "Topics"}}');
        $module = new Taxonomies($this->registry());

        $args = $module->filterArgs(['labels' => ['name' => 'Categories'], 'hierarchical' => true], 'category');
        $module->register();

        $this->assertArrayNotHasKey('category', $GLOBALS['pb_test_registered']['taxonomy'] ?? []);
        $this->assertSame(['category'], $module->overridden());
        $this->assertSame(['name' => 'Categories', 'menu_name' => 'Topics'], $args['labels']);
        $this->assertTrue($args['hierarchical']);
    }

    public function testLateTaxonomyOverride(): void
    {
        $this->write('proto-taxonomies/category/taxonomy.jsonc', '{"name": "Category", "labels": {"menu_name": "Topics"}, "show_admin_column": false, "rewrite": {"slug": "topic"}}');
        $category = (object) ['name' => 'category', '_builtin' => true, 'labels' => (object) ['name' => 'Categories']];
        $GLOBALS['pb_test_taxonomies']['category'] = $category;
        $module = new Taxonomies($this->registry());

        $module->register();

        $this->assertSame('Topics', $category->labels->menu_name);
        $this->assertSame('Categories', $category->labels->name);
        $this->assertFalse($category->show_admin_column);
        $issue = $this->issue($module, 'late_override_ignored');
        $this->assertStringContainsString('rewrite', $issue->message);
    }

    public function testStandaloneTaxonomyNamingExternalType(): void
    {
        $this->write('proto-taxonomies/brand/taxonomy.jsonc', '{"name": "Brand", "post_types": ["product"]}');
        $module = new Taxonomies($this->registry());

        $module->register();

        $this->assertSame(['product'], $GLOBALS['pb_test_registered']['taxonomy']['brand']['object_types']);
    }

    public function testOverriddenTaxonomyAttachesDeclaredTypesOnInit20(): void
    {
        $this->write('proto-taxonomies/post_tag/taxonomy.jsonc', '{"name": "Tag", "post_types": ["product"]}');
        $GLOBALS['pb_test_taxonomies']['post_tag'] = (object) ['name' => 'post_tag', '_builtin' => true, 'labels' => []];
        $module = new Taxonomies($this->registry());

        $module->register();
        $module->attachObjectTypes();

        $this->assertSame([['post_tag', 'product']], $GLOBALS['pb_test_tax_links']);
    }

    public function testInvalidInlineTaxonomyInPhpTypeIsSkippedSilently(): void
    {
        proto_post_type('Book', ['taxonomies' => [['name' => 'Genre', 'hierarchical' => 'yes']]]);
        $module = new Taxonomies($this->registry());

        $module->register();

        $this->assertArrayNotHasKey('genre', $GLOBALS['pb_test_registered']['taxonomy'] ?? []);
        // The type's own schema check reports the error; the taxonomy does not repeat it.
        $this->assertSame([], $module->issues());
    }

    public function testInvalidStandalonePhpTaxonomyReportsError(): void
    {
        proto_taxonomy('Genre', ['hierarchical' => 'yes']);
        $module = new Taxonomies($this->registry());

        $module->register();

        $this->assertArrayNotHasKey('genre', $GLOBALS['pb_test_registered']['taxonomy'] ?? []);
        $this->assertSame(Issue::ERROR, $this->issue($module, 'schema_invalid')->level);
    }

    public function testOwnRegistrationNotDoubleFiltered(): void
    {
        $this->write('proto-taxonomies/brand/taxonomy.jsonc', '{"name": "Brand", "post_types": ["product"]}');
        $module = new Taxonomies($this->registry());
        $GLOBALS['pb_test_filters']['register_taxonomy_args'][] = [$module, 'filterArgs'];

        $module->register();

        $this->assertSame([], $module->overridden());
        $this->assertArrayHasKey('brand', $module->appliedArgs());
    }

    // ------------------------------------------------------------------ helpers

    private function registry(): Registry
    {
        $root = $this->root;
        return new Registry(new FileLoader(), static fn () => [$root]);
    }

    private function write(string $relative, string $contents): string
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);

        return $path;
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

    private function issue(Taxonomies $module, string $code): Issue
    {
        foreach ($module->issues() as $issue) {
            if ($issue->code === $code) {
                return $issue;
            }
        }
        $this->fail("No issue with code $code");
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
