<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\API;

require_once __DIR__ . '/ContentModelRoutesTestCase.php';

use ProtoBlocks\API\Admin\TaxonomiesRoutes;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\SchemaFileWriter;

/**
 * admin/taxonomies: every taxonomy on the site, tagged by origin, and the
 * detail route.
 */
final class TaxonomiesRoutesTest extends ContentModelRoutesTestCase
{
    private function routes(array $states = []): TaxonomiesRoutes
    {
        return new TaxonomiesRoutes($this->registry(), $this->modules($states), $this->writer(), $this->guardrails(), $this->scaffolder());
    }

    /** @return array<string, array<string, mixed>> */
    private static function bySlug(array $result): array
    {
        return array_column($result['items'], null, 'slug');
    }

    public function testInlineTaxonomyListsTheTypeFile(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product", "taxonomies": [{"name": "Brand"}]}');
        $this->write('proto-taxonomies/genre/taxonomy.jsonc', '{"name": "Genre", "post_types": ["product"]}');
        $this->terms = ['brand' => 4];

        $result = $this->routes()->index();

        self::assertSame(['moduleEnabled', 'createTarget', 'items', 'issues'], array_keys($result));
        self::assertTrue($result['moduleEnabled']);
        self::assertStringEndsWith('proto-taxonomies', $result['createTarget']['path']);
        $items = self::bySlug($result);

        $brand = $items['brand'];
        self::assertSame('file', $brand['origin']);
        self::assertStringEndsWith('proto-types/product/type.jsonc', $brand['source']);
        // Edited through the type's file, never on its own screen.
        self::assertFalse($brand['editable']);
        self::assertSame('inline', $brand['readOnlyReason']);
        self::assertSame('Declared inline in proto-types/product/type.jsonc; edit it there.', $brand['readOnlyMessage']);
        self::assertSame('product', $brand['parentType']);
        self::assertSame(['product'], $brand['objectTypes']);
        self::assertSame(4, $brand['count']);
        self::assertSame('Brands', $brand['label']);

        $genre = $items['genre'];
        self::assertStringEndsWith('proto-taxonomies/genre/taxonomy.jsonc', $genre['source']);
        self::assertTrue($genre['editable']);
        self::assertNull($genre['readOnlyReason']);
        self::assertNull($genre['readOnlyMessage']);
        self::assertNull($genre['parentType']);
        self::assertSame(['product'], $genre['objectTypes']);
        $this->assertNoAbsolutePaths($result);
    }

    public function testEveryListItemCarriesObjectTypesAndParentType(): void
    {
        $GLOBALS['pb_test_taxonomies']['category'] = (object) [
            'name' => 'category', 'label' => 'Categories', '_builtin' => true, 'show_ui' => true, 'object_type' => ['post', 'page', 'post'],
        ];
        $GLOBALS['pb_test_taxonomies']['loose'] = (object) ['name' => 'loose', 'label' => 'Loose', 'show_ui' => true];
        $this->write('proto-taxonomies/genre/taxonomy.jsonc', '{"name": "Genre"}');

        $items = self::bySlug($this->routes()->index());

        foreach ($items as $slug => $item) {
            self::assertArrayHasKey('objectTypes', $item, $slug);
            self::assertArrayHasKey('parentType', $item, $slug);
            self::assertArrayHasKey('readOnlyMessage', $item, $slug);
        }
        self::assertSame(['post', 'page'], $items['category']['objectTypes']);
        self::assertSame([], $items['loose']['objectTypes']);
        self::assertSame([], $items['genre']['objectTypes']);
        self::assertNull($items['category']['parentType']);
        self::assertNull($items['category']['readOnlyMessage']);
    }

    public function testFilterRootDeclarationIsReadOnly(): void
    {
        $extra = $this->root . '/extra';
        mkdir($extra . '/proto-taxonomies/topic', 0777, true);
        file_put_contents($extra . '/proto-taxonomies/topic/taxonomy.jsonc', '{"name": "Topic"}');
        $this->registry = new Registry(new FileLoader(), fn (): array => [$this->root, $extra]);
        $writer = new SchemaFileWriter([$this->root], true);

        $result = (new TaxonomiesRoutes($this->registry, $this->modules(), $writer, $this->guardrails(), $this->scaffolder()))->index();

        $topic = self::bySlug($result)['topic'];
        self::assertSame('file', $topic['origin']);
        self::assertFalse($topic['editable']);
        self::assertSame('outside_root', $topic['readOnlyReason']);
    }

    public function testCoreTaxonomyIsNotProto(): void
    {
        $GLOBALS['pb_test_taxonomies']['category'] = (object) [
            'name' => 'category', 'label' => 'Categories', '_builtin' => true, 'show_ui' => true, 'object_type' => ['post'],
        ];
        $GLOBALS['pb_test_taxonomies']['wp_theme'] = (object) ['name' => 'wp_theme', 'label' => 'Themes', '_builtin' => true, 'show_ui' => false];
        $this->terms['category'] = 6;

        $items = self::bySlug($this->routes()->index());

        self::assertArrayNotHasKey('wp_theme', $items);
        self::assertSame('core', $items['category']['origin']);
        self::assertSame(6, $items['category']['count']);
        self::assertFalse($items['category']['editable']);
        self::assertSame('not_proto', $items['category']['readOnlyReason']);
    }

    public function testModuleDisabled(): void
    {
        $this->write('proto-taxonomies/genre/taxonomy.jsonc', '{"name": "Genre"}');

        $result = $this->routes(['taxonomies' => false])->index();

        self::assertFalse($result['moduleEnabled']);
        self::assertArrayHasKey('genre', self::bySlug($result));
    }

    public function testDetail(): void
    {
        $this->write('proto-taxonomies/genre/taxonomy.jsonc', '{"name": "Genre", "post_types": ["book"]}');
        $this->terms['genre'] = 9;

        $result = $this->routes()->show(new \WP_REST_Request(['slug' => 'genre']));

        self::assertSame(['item', 'effective', 'objectTypes', 'content'], array_keys($result));
        self::assertSame('genre', $result['item']['slug']);
        self::assertSame(['book'], $result['objectTypes']);
        self::assertSame(['book'], $result['item']['objectTypes']);
        self::assertSame(['count' => 9], $result['content']);
        self::assertSame('Genres', $result['effective']['labels']['name']);
        $this->assertNoAbsolutePaths($result);
    }

    public function testDetailUnknownAndInvalid(): void
    {
        self::assertWpError('proto_blocks_not_found', 404, $this->routes()->show(new \WP_REST_Request(['slug' => 'nothing'])));
        self::assertWpError('proto_blocks_invalid_slug', 400, $this->routes()->show(new \WP_REST_Request(['slug' => 'Genre'])));
        self::assertWpError('proto_blocks_invalid_slug', 400, $this->routes()->show(new \WP_REST_Request(['slug' => 'a' . str_repeat('b', 32)])));
    }
}
