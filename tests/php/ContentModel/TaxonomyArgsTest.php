<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\TaxonomyArgs;
use ProtoBlocks\ContentModel\UntrustedKeys;

require_once __DIR__ . '/../CLI/wp-stubs.php';

final class TaxonomyArgsTest extends TestCase
{
    /**
     * @param array<string, mixed> $data
     */
    private static function decl(array $data, string $slug = 'genre', string $origin = Declaration::ORIGIN_FILE, ?string $parentType = null): Declaration
    {
        return new Declaration(Declaration::TAXONOMY, $slug, $data, $origin, '/theme/proto-taxonomies/' . $slug . '/taxonomy.jsonc', $origin === Declaration::ORIGIN_FILE ? '/theme' : null, $parentType);
    }

    public function testBareDefaults(): void
    {
        $built = TaxonomyArgs::build(self::decl(['name' => 'Genre']));
        $args = $built['args'];

        $this->assertSame([], $built['issues']);
        $this->assertSame([], $built['objectTypes']);
        $this->assertTrue($args['public']);
        $this->assertTrue($args['show_in_rest']);
        $this->assertTrue($args['show_admin_column']);
        $this->assertFalse($args['hierarchical']);
        $this->assertSame(['slug' => 'genre', 'with_front' => false], $args['rewrite']);
        $this->assertSame('Genres', $args['labels']['name']);
        $this->assertSame('Genres', $args['labels']['menu_name']);
        $this->assertSame('Genre', $args['labels']['singular_name']);
        foreach (TaxonomyArgs::PLUGIN_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $args, $key);
        }
    }

    public function testInlineDeclarationKeepsObjectTypes(): void
    {
        $built = TaxonomyArgs::build(self::decl(['name' => 'Genre', 'post_types' => ['book']], 'genre', Declaration::ORIGIN_FILE, 'book'));
        $this->assertSame(['book'], $built['objectTypes']);
        $this->assertArrayNotHasKey('post_types', $built['args']);
    }

    public function testLabelsFollowHierarchical(): void
    {
        $flat = TaxonomyArgs::build(self::decl(['name' => 'Genre']))['args']['labels'];
        $this->assertSame('Separate genres with commas', $flat['separate_items_with_commas']);
        $this->assertArrayNotHasKey('parent_item', $flat);

        $tree = TaxonomyArgs::build(self::decl(['name' => 'Genre', 'hierarchical' => true]))['args']['labels'];
        $this->assertSame('Parent Genre', $tree['parent_item']);
        $this->assertArrayNotHasKey('separate_items_with_commas', $tree);
    }

    public function testDeclaredKeysMergeAndPassThrough(): void
    {
        $args = TaxonomyArgs::build(self::decl([
            'name' => 'Genre',
            'plural' => 'Genrez',
            'labels' => ['menu_name' => 'Kinds'],
            'rewrite' => ['slug' => 'kinds'],
            'hierarchical' => true,
            'show_tagcloud' => false,
            'fields' => [],
        ]))['args'];

        $this->assertSame('Kinds', $args['labels']['menu_name']);
        $this->assertSame('Genrez', $args['labels']['name']);
        $this->assertSame(['slug' => 'kinds', 'with_front' => false], $args['rewrite']);
        $this->assertTrue($args['hierarchical']);
        $this->assertFalse($args['show_tagcloud']);
        $this->assertFalse(TaxonomyArgs::build(self::decl(['name' => 'Genre', 'rewrite' => false]))['args']['rewrite']);
    }

    public function testOverrideOnlyDeclaredKeys(): void
    {
        $out = TaxonomyArgs::override(
            ['hierarchical' => true, 'labels' => (object) ['name' => 'Categories'], 'rewrite' => ['slug' => 'cat']],
            self::decl(['name' => 'Category', 'labels' => ['menu_name' => 'Topics'], 'rewrite' => ['with_front' => false], 'post_types' => ['book']], 'category')
        );

        $this->assertSame([], $out['issues']);
        $this->assertTrue($out['args']['hierarchical']);
        $this->assertSame(['name' => 'Categories', 'menu_name' => 'Topics'], $out['args']['labels']);
        $this->assertSame(['slug' => 'cat', 'with_front' => false], $out['args']['rewrite']);
        $this->assertArrayNotHasKey('post_types', $out['args']);
        $this->assertArrayNotHasKey('name', $out['args']);
    }

    public function testPhpDeclarationIsSchemaCheckedAndSkipped(): void
    {
        $built = TaxonomyArgs::build(self::decl(['name' => 'Genre', 'public' => 'yes', 'post_types' => ['book']], 'genre', Declaration::ORIGIN_PHP));

        $this->assertSame([], $built['args']);
        $this->assertSame([], $built['objectTypes']);
        $this->assertSame('schema_invalid', $built['issues'][0]->code);
        $this->assertSame(Issue::ERROR, $built['issues'][0]->level);
        $this->assertSame(Declaration::TAXONOMY, $built['issues'][0]->kind);
        $this->assertSame('/public: Must be of type boolean.', $built['issues'][0]->message);

        $existing = ['public' => true];
        $override = TaxonomyArgs::override($existing, self::decl(['name' => 'Category', 'post_types' => 'book'], 'category', Declaration::ORIGIN_PHP));
        $this->assertSame($existing, $override['args']);
        $this->assertSame('schema_invalid', $override['issues'][0]->code);
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
        $data = ['name' => 'Genre', 'post_types' => ['book'], $key => 'phpinfo'];
        $results = [
            'build' => TaxonomyArgs::build(self::decl($data)),
            'inline' => TaxonomyArgs::build(self::decl($data, 'genre', Declaration::ORIGIN_FILE, 'book')),
            'override' => TaxonomyArgs::override(['public' => true], self::decl($data, 'category')),
        ];

        foreach ($results as $mode => $result) {
            $this->assertArrayNotHasKey($key, $result['args'], $mode);
            $this->assertSame(['unsafe_key'], array_map(static fn (Issue $i): string => $i->code, $result['issues']), $mode);
            $this->assertSame(Issue::WARNING, $result['issues'][0]->level);
            $this->assertStringContainsString('"' . $key . '"', $result['issues'][0]->message);
            $this->assertSame(Declaration::TAXONOMY, $result['issues'][0]->kind);
        }
        $this->assertSame(['book'], $results['build']['objectTypes'], 'the rest of the declaration still registers');
    }

    #[DataProvider('unsafeKeys')]
    public function testPhpDeclarationKeepsUnsafeKey(string $key): void
    {
        $callback = static function (): void {
        };
        $built = TaxonomyArgs::build(self::decl(['name' => 'Genre', $key => $callback], 'genre', Declaration::ORIGIN_PHP));
        $this->assertSame([], $built['issues']);
        $this->assertSame($callback, $built['args'][$key]);

        $override = TaxonomyArgs::override([], self::decl([$key => '_update_post_term_count'], 'category', Declaration::ORIGIN_PHP));
        $this->assertSame([], $override['issues']);
        $this->assertSame('_update_post_term_count', $override['args'][$key]);
    }

    public function testFileDeclarationDropsMarkupInLabels(): void
    {
        $built = TaxonomyArgs::build(self::decl(['name' => 'Genre', 'labels' => ['menu_name' => '<script>x</script>', 'all_items' => 'Every genre']]));

        $this->assertSame('Genres', $built['args']['labels']['menu_name']);
        $this->assertSame('Every genre', $built['args']['labels']['all_items']);
        $this->assertSame(['unsafe_label'], array_map(static fn (Issue $i): string => $i->code, $built['issues']));
    }
}
