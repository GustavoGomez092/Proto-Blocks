<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\MenuIcon;
use ProtoBlocks\ContentModel\PostTypeArgs;
use ProtoBlocks\ContentModel\UntrustedKeys;

require_once __DIR__ . '/../CLI/wp-stubs.php';

final class PostTypeArgsTest extends TestCase
{
    private const THEME_URL = 'https://example.test/wp-content/themes/t';

    /**
     * @param array<string, mixed> $data
     * @return array{args: array<string, mixed>, issues: list<Issue>}
     */
    private static function build(array $data, string $slug = 'product', string $origin = Declaration::ORIGIN_FILE): array
    {
        return PostTypeArgs::build(self::decl($data, $slug, $origin), sys_get_temp_dir(), self::THEME_URL);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function decl(array $data, string $slug = 'product', string $origin = Declaration::ORIGIN_FILE): Declaration
    {
        return new Declaration(Declaration::TYPE, $slug, $data, $origin, '/theme/proto-types/' . $slug . '/type.jsonc', $origin === Declaration::ORIGIN_FILE ? '/theme' : null);
    }

    public function testBareDeclarationDefaults(): void
    {
        $built = self::build(['name' => 'Product']);
        $args = $built['args'];

        $this->assertSame([], $built['issues']);
        $this->assertTrue($args['public']);
        $this->assertTrue($args['show_in_rest']);
        $this->assertTrue($args['has_archive']);
        $this->assertSame(['slug' => 'product', 'with_front' => false], $args['rewrite']);
        $this->assertSame(['title', 'editor', 'thumbnail', 'excerpt', 'revisions'], $args['supports']);
        $this->assertSame(22, $args['menu_position']);
        $this->assertSame(MenuIcon::DEFAULT_ICON, $args['menu_icon']);
        $this->assertFalse($args['hierarchical']);
        $this->assertSame('post', $args['capability_type']);
        $this->assertSame([], $args['taxonomies']);
        $this->assertSame('Products', $args['labels']['name']);
        $this->assertSame('Product', $args['labels']['singular_name']);
        foreach (PostTypeArgs::PLUGIN_KEYS as $key) {
            if ($key !== 'taxonomies') {
                $this->assertArrayNotHasKey($key, $args, $key);
            }
        }
    }

    public function testPluginKeysNeverReachWordPress(): void
    {
        $args = self::build([
            '$schema' => './schema.json',
            'name' => 'Product',
            'plural' => 'Productz',
            'slug' => 'product',
            'fields' => [['name' => 'price']],
            'taxonomies' => ['category'],
        ])['args'];

        foreach (['$schema', 'name', 'plural', 'slug', 'fields'] as $key) {
            $this->assertArrayNotHasKey($key, $args, $key);
        }
        $this->assertSame('Productz', $args['labels']['name']);
    }

    public function testPluralOverride(): void
    {
        $labels = self::build(['name' => 'Person', 'plural' => 'Persons'], 'person')['args']['labels'];
        $this->assertSame('Persons', $labels['name']);
        $this->assertSame('No persons found.', $labels['not_found']);

        $this->assertSame('People', self::build(['name' => 'Person'], 'person')['args']['labels']['name']);
    }

    public function testHasArchiveStringKept(): void
    {
        $this->assertSame('resources', self::build(['name' => 'Product', 'has_archive' => 'resources'])['args']['has_archive']);
        $this->assertFalse(self::build(['name' => 'Product', 'has_archive' => false])['args']['has_archive']);
    }

    public function testDeclaredLabelsMergeOverGenerated(): void
    {
        $labels = self::build(['name' => 'Product', 'labels' => ['menu_name' => 'Library']])['args']['labels'];
        $this->assertSame('Library', $labels['menu_name']);
        $this->assertSame('Add New Product', $labels['add_new_item']);
    }

    public function testRewriteArrayMergesOverDefault(): void
    {
        $this->assertSame(
            ['slug' => 'resources', 'with_front' => false],
            self::build(['name' => 'Product', 'rewrite' => ['slug' => 'resources']])['args']['rewrite']
        );
        $this->assertSame(
            ['slug' => 'product', 'with_front' => true],
            self::build(['name' => 'Product', 'rewrite' => ['with_front' => true]])['args']['rewrite']
        );
    }

    public function testRewriteFalseReplaces(): void
    {
        $this->assertFalse(self::build(['name' => 'Product', 'rewrite' => false])['args']['rewrite']);
    }

    public function testSupportsReplacedNotMerged(): void
    {
        $this->assertSame(['title'], self::build(['name' => 'Product', 'supports' => ['title']])['args']['supports']);
        $this->assertFalse(self::build(['name' => 'Product', 'supports' => false])['args']['supports']);
    }

    public function testUnknownKeysPassThrough(): void
    {
        $args = self::build(['name' => 'Product', 'delete_with_user' => true, 'menu_position' => 5])['args'];
        $this->assertTrue($args['delete_with_user']);
        $this->assertSame(5, $args['menu_position']);
    }

    public function testStringTaxonomiesPassed(): void
    {
        $args = self::build(['name' => 'Book', 'taxonomies' => ['category', ['name' => 'Genre']]], 'book')['args'];
        $this->assertSame(['category'], $args['taxonomies']);
    }

    public function testMissingNameFallsBackToSlug(): void
    {
        $built = self::build(['public' => false], 'widget');
        $this->assertSame([], $built['issues']);
        $this->assertSame('widgets', $built['args']['labels']['name']);
        $this->assertFalse($built['args']['public']);
    }

    public function testOverrideOnlyDeclaredKeys(): void
    {
        $existing = ['public' => true, 'labels' => ['name' => 'Posts'], 'supports' => ['title']];
        $template = [['core/paragraph', ['placeholder' => 'Write…']]];
        $out = PostTypeArgs::override(
            $existing,
            self::decl(['template' => $template, 'labels' => ['menu_name' => 'Blog']], 'post'),
            sys_get_temp_dir(),
            self::THEME_URL
        );

        $this->assertSame([], $out['issues']);
        $this->assertTrue($out['args']['public']);
        $this->assertSame(['name' => 'Posts', 'menu_name' => 'Blog'], $out['args']['labels']);
        $this->assertSame($template, $out['args']['template']);
        $this->assertSame(['title'], $out['args']['supports']);
        $this->assertArrayNotHasKey('menu_icon', $out['args']);
    }

    public function testOverrideMergesObjectLabelsAndRewriteAndStripsPluginKeys(): void
    {
        $existing = [
            'labels' => (object) ['name' => 'Products', 'singular_name' => 'Product'],
            'rewrite' => ['slug' => 'shop', 'with_front' => true],
            'menu_position' => 56,
        ];
        $out = PostTypeArgs::override(
            $existing,
            self::decl(['name' => 'Item', 'plural' => 'Items', 'fields' => [], 'labels' => ['menu_name' => 'Shop'], 'rewrite' => ['with_front' => false], 'menu_icon' => 'dashicons-cart', 'menu_position' => 20]),
            sys_get_temp_dir(),
            self::THEME_URL
        );
        $args = $out['args'];

        $this->assertSame(['name' => 'Products', 'singular_name' => 'Product', 'menu_name' => 'Shop'], $args['labels']);
        $this->assertSame(['slug' => 'shop', 'with_front' => false], $args['rewrite']);
        $this->assertSame('dashicons-cart', $args['menu_icon']);
        $this->assertSame(20, $args['menu_position']);
        foreach (['name', 'plural', 'fields'] as $key) {
            $this->assertArrayNotHasKey($key, $args, $key);
        }
    }

    public function testOverrideRewriteReplacedWhenNotBothArrays(): void
    {
        $decl = self::decl(['rewrite' => ['slug' => 'items']]);
        $this->assertSame(['slug' => 'items'], PostTypeArgs::override(['rewrite' => false], $decl, '/x', self::THEME_URL)['args']['rewrite']);
        $this->assertFalse(PostTypeArgs::override(['rewrite' => ['slug' => 'a']], self::decl(['rewrite' => false]), '/x', self::THEME_URL)['args']['rewrite']);
    }

    public function testInvalidIconFallsBackWithIssue(): void
    {
        $built = self::build(['name' => 'Product', 'menu_icon' => 'http://x/i.png']);

        $this->assertSame(MenuIcon::DEFAULT_ICON, $built['args']['menu_icon']);
        $this->assertCount(1, $built['issues']);
        $issue = $built['issues'][0];
        $this->assertSame('icon_invalid', $issue->code);
        $this->assertSame(Issue::WARNING, $issue->level);
        $this->assertSame('product', $issue->slug);
        $this->assertSame(Declaration::TYPE, $issue->kind);
        $this->assertStringContainsString('http://x/i.png', $issue->message);

        $override = PostTypeArgs::override([], self::decl(['menu_icon' => 'http://x/i.png']), '/x', self::THEME_URL);
        $this->assertSame(MenuIcon::DEFAULT_ICON, $override['args']['menu_icon']);
        $this->assertSame('icon_invalid', $override['issues'][0]->code);
    }

    public function testPhpDeclarationIsSchemaCheckedAndSkipped(): void
    {
        foreach ([['public' => 'yes'], ['supports' => 'title'], ['rewrite' => true]] as $bad) {
            $built = self::build(['name' => 'Product'] + $bad, 'product', Declaration::ORIGIN_PHP);

            $this->assertSame([], $built['args'], json_encode($bad));
            $this->assertNotEmpty($built['issues']);
            foreach ($built['issues'] as $issue) {
                $this->assertSame('schema_invalid', $issue->code);
                $this->assertSame(Issue::ERROR, $issue->level);
                $this->assertSame('product', $issue->slug);
                $this->assertSame(Declaration::TYPE, $issue->kind);
            }
            $this->assertStringStartsWith('/' . array_key_first($bad) . ':', $built['issues'][0]->message);

            $existing = ['public' => true, 'supports' => ['title']];
            $override = PostTypeArgs::override($existing, self::decl($bad, 'post', Declaration::ORIGIN_PHP), '/x', self::THEME_URL);
            $this->assertSame($existing, $override['args']);
            $this->assertSame('schema_invalid', $override['issues'][0]->code);
        }
    }

    public function testValidPhpDeclarationBuilds(): void
    {
        $built = self::build(['name' => 'Product', 'has_archive' => 'shop', 'delete_with_user' => false], 'product', Declaration::ORIGIN_PHP);
        $this->assertSame([], $built['issues']);
        $this->assertSame('shop', $built['args']['has_archive']);
        $this->assertFalse($built['args']['delete_with_user']);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeKeys(): iterable
    {
        foreach (UntrustedKeys::UNSAFE_KEYS as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('unsafeKeys')]
    public function testFileDeclarationDropsUnsafeKeyWithWarning(string $key): void
    {
        $data = ['name' => 'Product', $key => 'phpinfo'];
        $results = [
            'build' => self::build($data),
            'override' => PostTypeArgs::override(['public' => true], self::decl($data, 'post'), sys_get_temp_dir(), self::THEME_URL),
        ];

        foreach ($results as $mode => $result) {
            $this->assertArrayNotHasKey($key, $result['args'], $mode);
            $this->assertSame(['unsafe_key'], array_map(static fn (Issue $i): string => $i->code, $result['issues']), $mode);
            $this->assertSame(Issue::WARNING, $result['issues'][0]->level);
            $this->assertStringContainsString('"' . $key . '"', $result['issues'][0]->message);
            $this->assertSame(Declaration::TYPE, $result['issues'][0]->kind);
        }
        $this->assertTrue($results['override']['args']['public']);
        $this->assertSame('Products', $results['build']['args']['labels']['name'], 'the rest of the declaration still registers');
    }

    #[DataProvider('unsafeKeys')]
    public function testPhpDeclarationKeepsUnsafeKey(string $key): void
    {
        $callback = static function (): void {
        };
        $built = self::build(['name' => 'Product', $key => $callback], 'product', Declaration::ORIGIN_PHP);
        $this->assertSame([], $built['issues']);
        $this->assertSame($callback, $built['args'][$key]);

        $override = PostTypeArgs::override([], self::decl([$key => 'My_Class'], 'post', Declaration::ORIGIN_PHP), '/x', self::THEME_URL);
        $this->assertSame([], $override['issues']);
        $this->assertSame('My_Class', $override['args'][$key]);
    }

    public function testFileDeclarationDropsUnknownKeyNamingCallable(): void
    {
        $built = self::build([
            'name' => 'Product',
            'my_callback' => 'strlen',
            'my_static' => 'ProtoBlocks\ContentModel\PostTypeArgs::build',
            'my_pair' => ['\ProtoBlocks\ContentModel\PostTypeArgs', 'build'],
            'my_flag' => 'not_a_function_pb_test',
            'delete_with_user' => false,
        ]);

        foreach (['my_callback', 'my_static', 'my_pair'] as $key) {
            $this->assertArrayNotHasKey($key, $built['args'], $key);
        }
        $this->assertSame('not_a_function_pb_test', $built['args']['my_flag']);
        $this->assertFalse($built['args']['delete_with_user']);
        $this->assertSame(['unsafe_key', 'unsafe_key', 'unsafe_key'], array_map(static fn (Issue $i): string => $i->code, $built['issues']));
    }

    public function testFileDeclarationDropsMarkupInLabels(): void
    {
        $built = self::build([
            'name' => 'Product <x>',
            'labels' => ['add_new_item' => '<img src=x onerror=alert(1)>', 'menu_name' => 'Shop'],
        ]);

        $labels = $built['args']['labels'];
        $this->assertSame('Shop', $labels['menu_name']);
        $this->assertSame('Add New Product x', $labels['add_new_item'], 'the generated label replaces the dropped one');
        foreach ($labels as $key => $label) {
            $this->assertFalse(is_string($label) && UntrustedKeys::hasMarkup($label), (string) $key);
        }
        $codes = array_map(static fn (Issue $i): string => $i->code, $built['issues']);
        $this->assertSame(['unsafe_label', 'unsafe_label'], $codes);
        $this->assertSame(Issue::WARNING, $built['issues'][0]->level);
        $this->assertStringContainsString('"name"', $built['issues'][0]->message);
        $this->assertStringContainsString('"labels.add_new_item"', $built['issues'][1]->message);
    }

    public function testPhpDeclarationKeepsLabelText(): void
    {
        $built = self::build(['name' => 'Product', 'labels' => ['menu_name' => 'Shop <b>new</b>']], 'product', Declaration::ORIGIN_PHP);
        $this->assertSame([], $built['issues']);
        $this->assertSame('Shop <b>new</b>', $built['args']['labels']['menu_name']);
    }
}
