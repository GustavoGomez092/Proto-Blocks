<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\MenuIcon;
use ProtoBlocks\ContentModel\PhpDeclarations;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\Modules\PostTypes;

require_once __DIR__ . '/../CLI/wp-stubs.php';
require_once __DIR__ . '/../ContentModel/stubs/hooks.php';
require_once __DIR__ . '/../../../includes/content-model.php';

final class PostTypesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        PhpDeclarations::reset();
        $tmp = sys_get_temp_dir() . '/pb-post-types-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->root = (string) realpath($tmp);
        foreach (['pb_test_hooks', 'pb_test_registered', 'pb_test_post_types', 'pb_test_supports', 'pb_test_tax_links', 'pb_test_filters'] as $key) {
            $GLOBALS[$key] = [];
        }
        $GLOBALS['pb_test_theme'] = ['template' => $this->root, 'stylesheet' => $this->root, 'template_uri' => 'http://example.test/t', 'stylesheet_uri' => 'http://example.test/t'];
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        unset($GLOBALS['pb_test_theme']);
        foreach (['pb_test_hooks', 'pb_test_registered', 'pb_test_post_types', 'pb_test_supports', 'pb_test_tax_links', 'pb_test_filters'] as $key) {
            $GLOBALS[$key] = [];
        }
        self::removeTree($this->root);
    }

    public function testIdIsPostTypes(): void
    {
        $this->assertSame('post_types', (new PostTypes($this->registry()))->id());
    }

    public function testRegistersDeclaredTypeOnInit10(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product"}');
        $module = new PostTypes($this->registry());
        $module->boot();

        $this->assertSame(10, $this->priorityOf('init', [$module, 'register']));
        $this->assertSame(10, $this->priorityOf('register_post_type_args', [$module, 'filterArgs']));
        $this->assertSame(2, $this->hookFor('register_post_type_args', [$module, 'filterArgs'])['args']);
        $this->assertNotNull($this->hookFor('wp_loaded', [$module, 'resolvePending']));

        $module->register();

        $args = $GLOBALS['pb_test_registered']['post_type']['product'] ?? null;
        $this->assertIsArray($args);
        $this->assertTrue($args['has_archive']);
        $this->assertSame(22, $args['menu_position']);
        $this->assertSame(['product'], $module->registered());
        $this->assertSame([], $module->overridden());
    }

    public function testSealIsHookedAtInit9(): void
    {
        $module = new PostTypes($this->registry());
        $module->boot();

        $this->assertSame(9, $this->priorityOf('init', [$module, 'seal']));
    }

    public function testExistingSlugBecomesOverride(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product", "menu_position": 56}');
        $GLOBALS['pb_test_post_types']['product'] = (object) ['name' => 'product', '_builtin' => false, 'public' => true, 'labels' => []];
        $module = new PostTypes($this->registry());

        $module->register();
        $this->assertArrayNotHasKey('product', $GLOBALS['pb_test_registered']['post_type'] ?? []);
        $this->assertSame(['product'], $module->overridden());
        $this->assertSame([], $module->registered());

        $args = $module->filterArgs(['public' => true], 'product');
        $this->assertSame(56, $args['menu_position']);
        $this->assertTrue($args['public']);
        $this->assertArrayNotHasKey('name', $args);
    }

    public function testFilterBeforeRegisterIsNotLate(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product", "rewrite": {"slug": "shop"}}');
        $module = new PostTypes($this->registry());

        // A plugin registers the slug on init 5, before the module's init 10.
        $GLOBALS['pb_test_filters']['register_post_type_args'][] = [$module, 'filterArgs'];
        register_post_type('product', ['public' => true, 'rewrite' => ['slug' => 'product', 'with_front' => true]]);
        $module->register();

        $this->assertSame(['slug' => 'shop', 'with_front' => true], $GLOBALS['pb_test_registered']['post_type']['product']['rewrite']);
        $this->assertSame(['product'], $module->overridden());
        $this->assertSame([], $module->issues());
    }

    public function testUndeclaredSlugPassesThrough(): void
    {
        $module = new PostTypes($this->registry());

        $this->assertSame(['public' => false], $module->filterArgs(['public' => false], 'other'));
    }

    public function testOwnRegistrationNotDoubleFiltered(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product", "menu_position": 30}');
        $module = new PostTypes($this->registry());
        $seen = [];
        $GLOBALS['pb_test_filters']['register_post_type_args'][] = static function (array $args, string $slug) use ($module, &$seen) {
            $out = $module->filterArgs($args, $slug);
            $seen[] = [$args, $out];
            return $out;
        };

        $module->register();

        $this->assertCount(1, $seen);
        $this->assertSame($seen[0][0], $seen[0][1]);
        $this->assertSame([], $module->overridden());
    }

    public function testBuiltInPostTemplateOverride(): void
    {
        $template = [['core/paragraph', ['placeholder' => 'Intro']]];
        $this->write('proto-types/post/type.jsonc', json_encode(['name' => 'Post', 'template' => $template, 'template_lock' => 'insert']));
        $post = (object) ['name' => 'post', '_builtin' => true, 'labels' => (object) ['name' => 'Posts']];
        $GLOBALS['pb_test_post_types']['post'] = $post;
        $module = new PostTypes($this->registry());

        $module->register();

        $this->assertContains('post', $module->overridden());
        $this->assertArrayNotHasKey('post', $GLOBALS['pb_test_registered']['post_type'] ?? []);
        $this->assertSame($template, $post->template);
        $this->assertSame('insert', $post->template_lock);
        $this->assertSame([], $module->issues());
    }

    public function testBuiltInPostOverrideThroughFilterWhenEarly(): void
    {
        $this->write('proto-types/post/type.jsonc', '{"name": "Post", "template": [["core/heading"]]}');
        $module = new PostTypes($this->registry());

        // Core's init 0 re-registration goes through the filter.
        $args = $module->filterArgs(['public' => true, '_builtin' => true], 'post');
        $GLOBALS['pb_test_post_types']['post'] = (object) ($args + ['name' => 'post']);
        $module->register();

        $this->assertSame([['core/heading']], $args['template']);
        $this->assertSame(['post'], $module->overridden());
        $this->assertSame([], $module->issues());
    }

    public function testLateOverrideAppliesLabelsAndSupports(): void
    {
        $this->write('proto-types/post/type.jsonc', '{"name": "Post", "labels": {"menu_name": "Articles"}, "supports": ["title", "editor", "custom-fields"], "menu_position": 3}');
        $post = (object) ['name' => 'post', '_builtin' => true, 'label' => 'Posts', 'labels' => (object) ['name' => 'Posts', 'menu_name' => 'Posts']];
        $GLOBALS['pb_test_post_types']['post'] = $post;
        $GLOBALS['pb_test_supports']['post'] = ['title' => true, 'editor' => true, 'thumbnail' => true];
        $module = new PostTypes($this->registry());

        $module->register();

        $this->assertSame('Articles', $post->labels->menu_name);
        $this->assertSame('Posts', $post->labels->name);
        $this->assertSame(3, $post->menu_position);
        $this->assertSame(['title', 'editor', 'custom-fields'], array_keys($GLOBALS['pb_test_supports']['post']));
    }

    public function testLateOverrideWarnsForUnappliedKeys(): void
    {
        $this->write('proto-types/post/type.jsonc', '{"name": "Post", "rewrite": {"slug": "blog"}, "template": []}');
        $GLOBALS['pb_test_post_types']['post'] = (object) ['name' => 'post', '_builtin' => true, 'labels' => []];
        $module = new PostTypes($this->registry());

        $module->register();

        $issue = $this->issue($module, 'late_override_ignored');
        $this->assertSame(Issue::WARNING, $issue->level);
        $this->assertStringContainsString('rewrite', $issue->message);
        $this->assertStringNotContainsString('template', $issue->message);
        $this->assertSame('post', $issue->slug);
    }

    public function testMissingNameSkipped(): void
    {
        $file = $this->write('proto-types/widget/type.jsonc', '{"menu_position": 40}');
        $module = new PostTypes($this->registry());

        $module->register();
        $this->assertArrayNotHasKey('widget', $GLOBALS['pb_test_registered']['post_type'] ?? []);
        $this->assertSame([], $module->issues());

        $module->resolvePending();
        $issue = $this->issue($module, 'name_required');
        $this->assertSame(Issue::ERROR, $issue->level);
        $this->assertSame($file, $issue->source);
        $this->assertSame('widget', $issue->slug);
    }

    public function testMissingNameBecomesOverrideWhenRegisteredLater(): void
    {
        $this->write('proto-types/widget/type.jsonc', '{"menu_position": 40}');
        $module = new PostTypes($this->registry());
        $GLOBALS['pb_test_filters']['register_post_type_args'][] = [$module, 'filterArgs'];

        $module->register();
        register_post_type('widget', ['public' => true]); // a plugin, on init 20
        $module->resolvePending();

        $this->assertSame([], $module->issues());
        $this->assertSame(['widget'], $module->overridden());
        $this->assertSame(40, $GLOBALS['pb_test_registered']['post_type']['widget']['menu_position']);
    }

    public function testNamelessPostOverrideFileEndToEnd(): void
    {
        $this->write('proto-types/post/type.jsonc', "{\n  // Only an editor template for posts.\n  \"template\": [[\"core/heading\"]],\n  \"template_lock\": \"all\"\n}");
        $module = new PostTypes($this->registry());
        $module->boot();
        $GLOBALS['pb_test_filters']['register_post_type_args'][] = [$module, 'filterArgs'];

        register_post_type('post', ['public' => true, '_builtin' => true]); // core, init 0
        $module->seal();
        $module->register();
        $module->attachTaxonomies();
        $module->resolvePending();

        $post = get_post_type_object('post');
        $this->assertSame([['core/heading']], $post->template);
        $this->assertSame('all', $post->template_lock);
        $this->assertTrue($post->public);
        $this->assertSame(['post'], $module->overridden());
        $this->assertSame([], $module->registered());
        $this->assertSame([], $module->issues());
    }

    public function testNamelessLatePostOverrideAppliesToObject(): void
    {
        $this->write('proto-types/post/type.jsonc', '{"template": [["core/heading"]]}');
        $post = (object) ['name' => 'post', '_builtin' => true, 'labels' => []];
        $GLOBALS['pb_test_post_types']['post'] = $post;
        $module = new PostTypes($this->registry());

        $module->register();
        $module->resolvePending();

        $this->assertSame([['core/heading']], $post->template);
        $this->assertSame([], $module->issues());
    }

    public function testSupportsFalseRemovesEveryFeature(): void
    {
        $this->write('proto-types/post/type.jsonc', '{"supports": false}');
        $GLOBALS['pb_test_post_types']['post'] = (object) ['name' => 'post', '_builtin' => true, 'labels' => []];
        $GLOBALS['pb_test_supports']['post'] = ['title' => true, 'editor' => true];
        $module = new PostTypes($this->registry());

        $module->register();

        $this->assertSame([], $GLOBALS['pb_test_supports']['post']);
    }

    public function testStringKeyedFalseSupportIsRemoved(): void
    {
        $GLOBALS['pb_test_supports']['post'] = ['title' => true, 'editor' => true];
        $apply = new \ReflectionMethod(PostTypes::class, 'applySupports');

        $apply->invoke(null, 'post', ['title' => true, 'editor' => false, 'author' => ['x']]);

        $this->assertSame(['title' => true, 'author' => [['x']]], $GLOBALS['pb_test_supports']['post']);
    }

    public function testRegisterSealsPhpDeclarations(): void
    {
        $module = new PostTypes($this->registry());

        $module->register();
        proto_post_type('Late');

        $codes = array_map(static fn (Issue $i) => $i->code, PhpDeclarations::issues());
        $this->assertContains('declared_too_late', $codes);
    }

    public function testPhpDeclarationAfterFirstRegistryUseIsSeen(): void
    {
        $module = new PostTypes($this->registry());
        $module->filterArgs([], 'post'); // init 0: builds the registry
        proto_post_type('Event');        // init 5

        $module->seal();
        $module->register();

        $this->assertArrayHasKey('event', $GLOBALS['pb_test_registered']['post_type'] ?? []);
    }

    public function testInvalidPhpDeclarationIsNotRegistered(): void
    {
        proto_post_type('Event', ['menu_position' => 'top']);
        $module = new PostTypes($this->registry());

        $module->register();

        $this->assertArrayNotHasKey('event', $GLOBALS['pb_test_registered']['post_type'] ?? []);
        $this->assertSame([], $module->registered());
        $this->assertSame(Issue::ERROR, $this->issue($module, 'schema_invalid')->level);
    }

    public function testRepeatedFilterCallsReportAnIssueOnce(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product", "menu_icon": "missing.svg"}');
        $module = new PostTypes($this->registry());

        $module->filterArgs([], 'product');
        $module->filterArgs([], 'product');

        $codes = array_map(static fn (Issue $i) => $i->code, $module->issues());
        $this->assertSame(['icon_invalid'], $codes);
    }

    public function testPhpDeclarationIconResolvesAgainstStylesheet(): void
    {
        file_put_contents($this->root . '/icon.png', 'png');
        proto_post_type('Event', ['menu_icon' => 'icon.png']);
        $module = new PostTypes($this->registry());

        $module->register();

        $this->assertSame('http://example.test/t/icon.png', $GLOBALS['pb_test_registered']['post_type']['event']['menu_icon']);
    }

    public function testRasterIconInFilterRootFallsBack(): void
    {
        $extra = $this->root . '/extra';
        mkdir($extra);
        file_put_contents($extra . '/icon.png', 'png');
        $theme = $this->root . '/theme';
        mkdir($theme);
        $GLOBALS['pb_test_theme']['template'] = $GLOBALS['pb_test_theme']['stylesheet'] = $theme;
        $this->write('extra/proto-types/event/type.jsonc', '{"name": "Event", "menu_icon": "icon.png"}');
        $module = new PostTypes(new Registry(new FileLoader(), fn () => [$extra]));

        $module->register();

        $this->assertSame(MenuIcon::DEFAULT_ICON, $GLOBALS['pb_test_registered']['post_type']['event']['menu_icon']);
        $this->assertSame(Issue::WARNING, $this->issue($module, 'icon_invalid')->level);
    }

    public function testStringTaxonomiesAttachToOverriddenTypesOnInit20(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product", "taxonomies": ["category", {"name": "Brand"}]}');
        $GLOBALS['pb_test_post_types']['product'] = (object) ['name' => 'product', '_builtin' => false, 'labels' => []];
        $module = new PostTypes($this->registry());
        $module->boot();

        $this->assertSame(20, $this->priorityOf('init', [$module, 'attachTaxonomies']));

        $module->register();
        $module->attachTaxonomies();

        $this->assertSame([['category', 'product']], $GLOBALS['pb_test_tax_links']);
    }

    public function testAppliedArgsCoverRegisteredAndOverridden(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product"}');
        $this->write('proto-types/page/type.jsonc', '{"name": "Page", "has_archive": false}');
        $module = new PostTypes($this->registry());

        $module->filterArgs(['public' => true], 'page');
        $module->register();

        $this->assertEqualsCanonicalizing(['page', 'product'], array_keys($module->appliedArgs()));
        $this->assertFalse($module->appliedArgs()['page']['has_archive']);
        $this->assertTrue($module->appliedArgs()['product']['has_archive']);
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

    /** @return array{callback: mixed, priority: int, args: int}|null */
    private function hookFor(string $hook, callable $callback): ?array
    {
        foreach ($GLOBALS['pb_test_hooks'][$hook] ?? [] as $entry) {
            if (is_array($entry) && array_key_exists('callback', $entry) && $entry['callback'] === $callback) {
                return $entry;
            }
        }

        return null;
    }

    private function priorityOf(string $hook, callable $callback): ?int
    {
        return $this->hookFor($hook, $callback)['priority'] ?? null;
    }

    private function issue(PostTypes $module, string $code): Issue
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
