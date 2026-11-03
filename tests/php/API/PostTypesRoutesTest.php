<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\API;

require_once __DIR__ . '/ContentModelRoutesTestCase.php';

use ProtoBlocks\Admin\AdminApp;
use ProtoBlocks\Admin\ContentModelMenu;
use ProtoBlocks\API\Admin\PostTypesRoutes;
use ProtoBlocks\ContentModel\PhpDeclarations;

/**
 * admin/types: every post type on the site, tagged by origin, plus the
 * detail and template scaffold routes.
 */
final class PostTypesRoutesTest extends ContentModelRoutesTestCase
{
    private function routes(array $states = [], bool $blockTheme = true, bool $fileMods = true): PostTypesRoutes
    {
        return new PostTypesRoutes($this->registry(), $this->modules($states), $this->writer($fileMods), $this->guardrails(), $this->scaffolder($blockTheme));
    }

    private function seedCorePost(): void
    {
        $GLOBALS['pb_test_post_types']['post'] = (object) [
            'name' => 'post', 'label' => 'Posts', 'labels' => (object) ['name' => 'Posts', 'singular_name' => 'Post'],
            '_builtin' => true, 'show_ui' => true, 'public' => true, 'has_archive' => false,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function bySlug(array $result): array
    {
        return array_column($result['items'], null, 'slug');
    }

    public function testListTagsOrigins(): void
    {
        $this->seedCorePost();
        $GLOBALS['pb_test_post_types']['revision'] = (object) ['name' => 'revision', 'label' => 'Revisions', '_builtin' => true, 'show_ui' => false];
        $this->write('proto-types/product/type.jsonc', '{"name": "Product"}');
        $this->write('proto-types/book/type.jsonc', '{"name": "Book"}');
        PhpDeclarations::add('type', 'Event', [], '/srv/theme/functions.php:4');
        PhpDeclarations::add('type', 'Book', [], '/srv/theme/functions.php:5');
        $this->posts = ['product' => 3, 'post' => 7];

        $result = $this->routes()->index();

        self::assertSame(['moduleEnabled', 'createTarget', 'items', 'issues'], array_keys($result));
        self::assertTrue($result['moduleEnabled']);
        self::assertTrue($result['createTarget']['writable']);
        self::assertNull($result['createTarget']['reason']);
        $items = self::bySlug($result);
        self::assertArrayNotHasKey('revision', $items, 'internal types without show_ui are omitted');

        $product = $items['product'];
        self::assertSame(['slug', 'label', 'origin', 'originLabel', 'source', 'override', 'double', 'count', 'editable', 'readOnlyReason', 'issues'], array_keys($product));
        self::assertSame('file', $product['origin']);
        self::assertSame('Proto-Blocks file', $product['originLabel']);
        self::assertSame('Products', $product['label']);
        self::assertSame(3, $product['count']);
        self::assertFalse($product['override']);
        self::assertNull($product['double']);
        self::assertTrue($product['editable']);
        self::assertNull($product['readOnlyReason']);
        self::assertStringEndsWith('proto-types/product/type.jsonc', $product['source']);

        self::assertSame('php', $items['event']['origin']);
        self::assertSame('Proto-Blocks PHP', $items['event']['originLabel']);
        self::assertStringEndsWith('functions.php:4', $items['event']['source']);
        self::assertTrue($items['event']['editable'], 'saving writes a file that takes over');

        $book = $items['book'];
        self::assertSame('file', $book['origin']);
        self::assertSame('Proto-Blocks file', $book['originLabel']);
        self::assertNotNull($book['double']);
        self::assertStringEndsWith('proto-types/book/type.jsonc', $book['double']['file']);
        self::assertStringEndsWith('functions.php:5', $book['double']['php']);
        self::assertSame(['double_declaration'], array_column($book['issues'], 'code'));

        $post = $items['post'];
        self::assertSame('core', $post['origin']);
        self::assertSame('Core', $post['originLabel']);
        self::assertSame(7, $post['count']);
        self::assertNull($post['source']);
        self::assertFalse($post['override']);
        self::assertFalse($post['editable']);
        self::assertSame('not_proto', $post['readOnlyReason']);
        $this->assertNoAbsolutePaths($result);
    }

    public function testCoreTypeBecomesEditableWithAnOverrideFile(): void
    {
        $this->seedCorePost();
        $this->write('proto-types/post/type.jsonc', '{"supports": ["title", "editor"]}');

        $post = self::bySlug($this->routes()->index())['post'];

        self::assertSame('core', $post['origin']);
        self::assertTrue($post['override']);
        self::assertTrue($post['editable']);
        self::assertNull($post['readOnlyReason']);
        self::assertStringEndsWith('proto-types/post/type.jsonc', $post['source']);
    }

    public function testListIncludesDeclarationsThatDidNotRegister(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product"}');

        $result = $this->routes(['post_types' => false])->index();

        self::assertFalse($result['moduleEnabled']);
        self::assertSame('file', self::bySlug($result)['product']['origin']);
    }

    public function testFileIssuesWithoutDeclarationAreTopLevel(): void
    {
        $this->write('proto-types/broken/type.jsonc', '{"name": ');

        $result = $this->routes()->index();

        self::assertArrayNotHasKey('broken', self::bySlug($result));
        self::assertSame(['invalid_jsonc'], array_column($result['issues'], 'code'));
        $this->assertNoAbsolutePaths($result);
    }

    public function testListReadOnlyWhenFileModsOff(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product"}');

        $result = $this->routes([], true, false)->index();

        self::assertSame('file_mods', $result['createTarget']['reason']);
        self::assertFalse(self::bySlug($result)['product']['editable']);
        self::assertSame('file_mods', self::bySlug($result)['product']['readOnlyReason']);
    }

    public function testDetail(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product", "taxonomies": [{"name": "Brand"}]}');
        $this->posts['product'] = 2;
        $this->terms['brand'] = 5;

        $result = $this->routes()->show(new \WP_REST_Request(['slug' => 'product']));

        self::assertSame(['item', 'effective', 'content', 'blockTheme', 'templates'], array_keys($result));
        self::assertSame('product', $result['item']['slug']);
        self::assertSame('Products', $result['effective']['labels']['name']);
        self::assertTrue($result['effective']['public']);
        self::assertSame(['count' => 2, 'taxonomies' => ['brand' => 5]], $result['content']);
        self::assertTrue($result['blockTheme']);
        self::assertSame(
            [['name' => 'single-product.html', 'exists' => false], ['name' => 'archive-product.html', 'exists' => false], ['name' => 'taxonomy-brand.html', 'exists' => false]],
            $result['templates']
        );
        $this->assertNoAbsolutePaths($result);
    }

    public function testDetailCarriesTheRegisteredSupports(): void
    {
        // WP_Post_Type::add_supports() unsets $supports on the object, so the
        // detail must read the features from get_all_post_type_supports().
        $this->seedCorePost();
        unset($GLOBALS['pb_test_post_types']['post']->supports);
        $GLOBALS['pb_test_supports']['post'] = ['title' => true, 'editor' => true, 'thumbnail' => true, 'excerpt' => true, 'comments' => true];

        $result = $this->routes()->show(new \WP_REST_Request(['slug' => 'post']));

        self::assertSame(['title', 'editor', 'thumbnail', 'excerpt', 'comments'], $result['effective']['supports']);
    }

    public function testDetailUnknownAndInvalid(): void
    {
        self::assertWpError('proto_blocks_not_found', 404, $this->routes()->show(new \WP_REST_Request(['slug' => 'nothing'])));
        self::assertWpError('proto_blocks_invalid_slug', 400, $this->routes()->show(new \WP_REST_Request(['slug' => 'Product'])));
    }

    public function testScaffoldWritesTemplates(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product", "taxonomies": [{"name": "Brand"}]}');
        $this->write('templates/single-product.html', 'mine');

        $result = $this->routes()->scaffold(new \WP_REST_Request(['slug' => 'product'], ['taxonomies' => true]));

        self::assertSame(['written' => ['archive-product.html', 'taxonomy-brand.html'], 'skipped' => ['single-product.html']], $result);
        self::assertSame('mine', file_get_contents($this->root . '/templates/single-product.html'));
    }

    public function testScaffoldOnClassicThemeIs409(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product"}');

        self::assertWpError('proto_blocks_classic_theme', 409, $this->routes([], false)->scaffold(new \WP_REST_Request(['slug' => 'product'], ['taxonomies' => false])));
        self::assertDirectoryDoesNotExist($this->root . '/templates');
    }

    public function testScaffoldValidatesSlugAndExistence(): void
    {
        self::assertWpError('proto_blocks_invalid_slug', 400, $this->routes()->scaffold(new \WP_REST_Request(['slug' => 'Product'])));
        self::assertWpError('proto_blocks_not_found', 404, $this->routes()->scaffold(new \WP_REST_Request(['slug' => 'product'])));
    }

    public function testMenuAddsReactScreens(): void
    {
        require_once __DIR__ . '/../OptionsPages/wp-stubs.php';
        $GLOBALS['pb_test_menus'] = [];
        $app = new AdminApp(null);

        (new ContentModelMenu($app))->register();

        $menus = array_column($GLOBALS['pb_test_menus'], null, 'slug');
        unset($GLOBALS['pb_test_menus']);
        foreach (['proto-blocks-post-types' => ['Post Types', 'post-types'], 'proto-blocks-taxonomies' => ['Taxonomies', 'taxonomies']] as $slug => [$title, $screen]) {
            self::assertSame('proto-blocks', $menus[$slug]['parent']);
            self::assertSame($title, $menus[$slug]['menu_title']);
            self::assertSame('manage_options', $menus[$slug]['capability']);
            self::assertTrue($app->isScreenHook('hook_' . $slug));
            ob_start();
            ($menus[$slug]['fn'])();
            self::assertStringContainsString('data-screen="' . $screen . '"', (string) ob_get_clean());
        }
    }

    public function testScaffoldFileModsOff(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product"}');

        $routes = new PostTypesRoutes($this->registry(), $this->modules(), $this->writer(false), $this->guardrails(), $this->scaffolder(true, false));
        self::assertWpError('proto_blocks_file_mods', 403, $routes->scaffold(new \WP_REST_Request(['slug' => 'product'])));
    }
}
