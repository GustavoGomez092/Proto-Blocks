<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\TaxonomyArgs;

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
}
