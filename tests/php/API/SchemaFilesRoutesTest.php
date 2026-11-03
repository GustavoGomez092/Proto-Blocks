<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\API;

require_once __DIR__ . '/ContentModelRoutesTestCase.php';

use ProtoBlocks\API\Admin\PostTypesRoutes;
use ProtoBlocks\API\Admin\SchemaFilesRoutes;
use ProtoBlocks\API\Admin\TaxonomiesRoutes;
use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\ContentModel\DeclarationTemplate;
use ProtoBlocks\ContentModel\PhpDeclarations;
use ProtoBlocks\ContentModel\SchemaFileWriter;

/**
 * schema-files/<kind>/<slug>: the boundary between the admin UI and the
 * theme's declaration files.
 */
final class SchemaFilesRoutesTest extends ContentModelRoutesTestCase
{
    private const ROUTE = 'schema-files/(?P<kind>type|taxonomy|options)/(?P<slug>[a-z][a-z0-9_-]{0,63})';

    private function routes(bool $fileMods = true): SchemaFilesRoutes
    {
        return new SchemaFilesRoutes($this->registry(), $this->modules(), $this->writer($fileMods), $this->guardrails(), $this->scaffolder());
    }

    private function register(object $group, ?callable &$permission = null, ?callable &$writePermission = null): void
    {
        $permission = static fn (): bool => true;
        $writePermission = static fn (): bool => true;
        $group->registerRoutes('proto-blocks/v1', $permission, $writePermission);
    }

    /** @param array<string, mixed>|null $body */
    private static function request(array $params, ?array $body = null): \WP_REST_Request
    {
        return new \WP_REST_Request($params, $body);
    }

    // --- permission, routes, traversal ------------------------------------

    public function testRoutesRegistered(): void
    {
        $this->register($this->routes(), $permission, $writePermission);

        $entry = self::route(self::ROUTE);
        self::assertNotNull($entry);
        self::assertSame('proto-blocks/v1', $entry['namespace']);

        $methods = [];
        foreach ($entry['args'] as $endpoint) {
            $methods[$endpoint['methods']] = $endpoint['permission_callback'];
        }
        self::assertSame(['GET', \WP_REST_Server::EDITABLE, 'DELETE'], array_keys($methods));
        self::assertSame($permission, $methods['GET']);
        self::assertSame($writePermission, $methods[\WP_REST_Server::EDITABLE]);
        self::assertSame($writePermission, $methods['DELETE']);
    }

    public function testWriteRoutesUseWritePermission(): void
    {
        $permission = static fn (): bool => true;
        $writePermission = static fn (): bool => true;
        foreach ([$this->routes(), new PostTypesRoutes($this->registry()), new TaxonomiesRoutes($this->registry())] as $group) {
            $group->registerRoutes('proto-blocks/v1', $permission, $writePermission);
        }

        $writes = 0;
        foreach ($GLOBALS['pb_test_routes'] as $entry) {
            foreach ($entry['args'] as $endpoint) {
                $isWrite = $endpoint['methods'] !== 'GET';
                self::assertSame($isWrite ? $writePermission : $permission, $endpoint['permission_callback'], $entry['route'] . ' ' . $endpoint['methods']);
                $writes += $isWrite ? 1 : 0;
            }
        }
        self::assertSame(3, $writes, 'save, delete and scaffold');
        self::assertNotNull(self::route('admin/types/(?P<slug>[a-z][a-z0-9_-]{0,19})/scaffold'));
    }

    public function testRegisteredThroughTheController(): void
    {
        foreach ([SchemaFilesRoutes::class, PostTypesRoutes::class, TaxonomiesRoutes::class] as $class) {
            self::assertContains($class, AdminRestController::ROUTE_GROUPS);
        }

        $GLOBALS['pb_test_caps'] = [1 => ['edit_posts']];
        (new AdminRestController())->registerRoutes();
        $entry = self::route(self::ROUTE);
        self::assertNotNull($entry);
        foreach ($entry['args'] as $endpoint) {
            self::assertFalse(($endpoint['permission_callback'])(), 'no manage_options, no access');
        }
    }

    public function testSlugRegexRejectsTraversal(): void
    {
        $match = static fn (string $path): bool => preg_match('@^' . self::ROUTE . '$@i', $path) === 1;

        foreach (['schema-files/type/../x', 'schema-files/type/a/b', 'schema-files/type/%2e%2e', 'schema-files/plugin/x', 'schema-files/type/', 'schema-files/type/.x'] as $path) {
            self::assertFalse($match($path), $path);
        }
        self::assertTrue($match('schema-files/type/Product'));
        self::assertTrue($match('schema-files/TYPE/product'));

        $routes = $this->routes();
        foreach (['Product', "product\n", 'post_type', 'abcdefghijklmnopqrstu'] as $slug) {
            self::assertWpError('proto_blocks_invalid_slug', 400, $routes->get(self::request(['kind' => 'type', 'slug' => $slug])));
            self::assertWpError('proto_blocks_invalid_slug', 400, $routes->save(self::request(['kind' => 'type', 'slug' => $slug], ['text' => '{}', 'hash' => null])));
            self::assertWpError('proto_blocks_invalid_slug', 400, $routes->delete(self::request(['kind' => 'type', 'slug' => $slug])));
        }
        self::assertWpError('proto_blocks_invalid_slug', 400, $routes->get(self::request(['kind' => 'TYPE', 'slug' => 'product'])));
        self::assertWpError('proto_blocks_invalid_slug', 400, $routes->save(self::request(['kind' => 'Taxonomy', 'slug' => 'genre'], ['text' => '{}'])));
        self::assertFileDoesNotExist($this->root . '/proto-types');
    }

    public function testBodyAndQueryCannotRetargetTheRoute(): void
    {
        // Core's get_param() prefers JSON, then POST, then the query, then the URL.
        $request = new class (['kind' => 'type', 'slug' => 'other'], ['slug' => 'other', 'text' => '{"name": "Product"}', 'hash' => null]) extends \WP_REST_Request {
            public function get_url_params(): array
            {
                return ['kind' => 'type', 'slug' => 'product'];
            }
        };

        $result = $this->routes()->save($request);

        self::assertIsArray($result, json_encode($result) ?: '');
        self::assertFileExists($this->root . '/proto-types/product/type.jsonc');
        self::assertDirectoryDoesNotExist($this->root . '/proto-types/other');
    }

    // --- file mods and stale hash ------------------------------------------

    public function testPutFileModsOffMapsTo403(): void
    {
        $result = $this->routes(false)->save(self::request(
            ['kind' => 'type', 'slug' => 'product'],
            ['text' => DeclarationTemplate::render('type', 'Product'), 'hash' => null, 'acknowledge' => []]
        ));

        self::assertWpError('proto_blocks_file_mods', 403, $result);
        self::assertFileDoesNotExist($this->root . '/proto-types/product/type.jsonc');
    }

    public function testDeleteFileModsOffMapsTo403(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product"}');

        self::assertWpError('proto_blocks_file_mods', 403, $this->routes(false)->delete(self::request(['kind' => 'type', 'slug' => 'product'])));
        self::assertFileExists($this->root . '/proto-types/product/type.jsonc');
    }

    public function testPutStaleHashReturns409WithCurrentHash(): void
    {
        $path = $this->write('proto-types/product/type.jsonc', '{"name": "Product"}');
        $current = SchemaFileWriter::hash('{"name": "Product"}');

        foreach ([null, str_repeat('0', 64)] as $hash) {
            $error = self::assertWpError('proto_blocks_conflict', 409, $this->routes()->save(self::request(
                ['kind' => 'type', 'slug' => 'product'],
                ['text' => '{"name": "Item"}', 'hash' => $hash, 'acknowledge' => []]
            )));
            self::assertSame($current, $error->get_error_data()['hash']);
        }
        self::assertSame('{"name": "Product"}', file_get_contents($path));
    }

    // --- save ----------------------------------------------------------------

    public function testPutValidCreatesAndInvalidates(): void
    {
        $registry = $this->registry();
        self::assertNull($registry->get('type', 'product'), 'memoised before the save');
        $text = DeclarationTemplate::render('type', 'Product');

        $result = $this->routes()->save(self::request(
            ['kind' => 'type', 'slug' => 'product'],
            ['text' => $text, 'hash' => null, 'acknowledge' => []]
        ));

        self::assertIsArray($result, json_encode($result) ?: '');
        self::assertSame(['path', 'hash', 'created', 'warnings'], array_keys($result));
        self::assertStringEndsWith('proto-types/product/type.jsonc', $result['path']);
        self::assertSame(SchemaFileWriter::hash($text), $result['hash']);
        self::assertTrue($result['created']);
        self::assertSame([], $result['warnings']);
        self::assertSame($text, file_get_contents($this->root . '/proto-types/product/type.jsonc'));
        self::assertNotNull($registry->get('type', 'product'), 'the registry was invalidated');
        $this->assertNoAbsolutePaths($result);
    }

    public function testPutAcceptsQueryParamsWithoutJsonBody(): void
    {
        $text = DeclarationTemplate::render('taxonomy', 'Genre');
        $result = $this->routes()->save(self::request(['kind' => 'taxonomy', 'slug' => 'genre', 'text' => $text]));

        self::assertIsArray($result);
        self::assertFileExists($this->root . '/proto-taxonomies/genre/taxonomy.jsonc');
    }

    public function testPutWarnsAboutPhpDouble(): void
    {
        PhpDeclarations::add('type', 'Product', [], '/srv/theme/functions.php:3');
        $result = $this->routes()->save(self::request(
            ['kind' => 'type', 'slug' => 'product'],
            ['text' => '{"name": "Product"}', 'hash' => null]
        ));

        self::assertIsArray($result);
        self::assertSame(['double_declaration'], array_column($result['warnings'], 'code'));
        $this->assertNoAbsolutePaths($result);
    }

    public function testPutRejectsNonStringText(): void
    {
        foreach ([['text' => ['x']], ['text' => '{}', 'hash' => 5], [], ['text' => '{}', 'acknowledge' => 'supports_removed']] as $body) {
            self::assertWpError('proto_blocks_invalid_request', 400, $this->routes()->save(self::request(['kind' => 'type', 'slug' => 'product'], $body)));
        }
    }

    public function testPutSchemaInvalid(): void
    {
        $error = self::assertWpError('proto_blocks_schema_invalid', 400, $this->routes()->save(self::request(
            ['kind' => 'type', 'slug' => 'product'],
            ['text' => '{"name": "Product", "public": "yes"}', 'hash' => null, 'acknowledge' => []]
        )));

        self::assertSame('/public', $error->get_error_data()['errors'][0]['path']);
        self::assertFileDoesNotExist($this->root . '/proto-types/product/type.jsonc');
    }

    public function testPutInvalidJsonc(): void
    {
        $error = self::assertWpError('proto_blocks_invalid_jsonc', 400, $this->routes()->save(self::request(
            ['kind' => 'type', 'slug' => 'product'],
            ['text' => '{"name": ', 'hash' => null]
        )));
        self::assertArrayHasKey('line', $error->get_error_data());
    }

    public function testPutNeedsConfirmationForSupportsRemoval(): void
    {
        $path = $this->write('proto-types/product/type.jsonc', '{"name": "Product"}');
        $hash = SchemaFileWriter::hash('{"name": "Product"}');
        $this->posts['product'] = 2;
        $next = '{"name": "Product", "supports": ["title"]}';

        $error = self::assertWpError('proto_blocks_needs_confirmation', 409, $this->routes()->save(self::request(
            ['kind' => 'type', 'slug' => 'product'],
            ['text' => $next, 'hash' => $hash, 'acknowledge' => []]
        )));
        $guardrails = $error->get_error_data()['guardrails'];
        self::assertSame('supports_removed', $guardrails[0]['code']);
        self::assertSame(2, $guardrails[0]['count']);
        self::assertSame('{"name": "Product"}', file_get_contents($path));

        // Acknowledging something else is not enough.
        self::assertWpError('proto_blocks_needs_confirmation', 409, $this->routes()->save(self::request(
            ['kind' => 'type', 'slug' => 'product'],
            ['text' => $next, 'hash' => $hash, 'acknowledge' => ['field_removed']]
        )));

        $result = $this->routes()->save(self::request(
            ['kind' => 'type', 'slug' => 'product'],
            ['text' => $next, 'hash' => $hash, 'acknowledge' => ['supports_removed']]
        ));
        self::assertIsArray($result, json_encode($result) ?: '');
        self::assertFalse($result['created']);
        self::assertSame($next, file_get_contents($path));
    }

    // --- read ----------------------------------------------------------------

    public function testGetExistingFile(): void
    {
        $text = "// mine\n{\"name\": \"Product\", \"supports\": [\"title\"]}\n";
        $this->write('proto-types/product/type.jsonc', $text);

        $result = $this->routes()->get(self::request(['kind' => 'type', 'slug' => 'product']));

        self::assertSame(['exists', 'path', 'text', 'hash', 'data', 'writable', 'reason'], array_keys($result));
        self::assertTrue($result['exists']);
        self::assertSame($text, $result['text']);
        self::assertSame(SchemaFileWriter::hash($text), $result['hash']);
        self::assertSame(['name' => 'Product', 'supports' => ['title']], $result['data']);
        self::assertTrue($result['writable']);
        self::assertNull($result['reason']);
        self::assertStringEndsWith('proto-types/product/type.jsonc', $result['path']);
        $this->assertNoAbsolutePaths($result);
    }

    public function testGetMissingFileServesTemplate(): void
    {
        $result = $this->routes()->get(self::request(['kind' => 'taxonomy', 'slug' => 'media-type']));

        self::assertFalse($result['exists']);
        self::assertNull($result['hash']);
        self::assertSame(DeclarationTemplate::render('taxonomy', 'Media Type'), $result['text']);
        self::assertSame('Media Type', $result['data']['name']);

        $named = $this->routes()->get(self::request(['kind' => 'type', 'slug' => 'product', 'name' => 'Gadget']));
        self::assertSame(DeclarationTemplate::render('type', 'Gadget'), $named['text']);
    }

    public function testGetBrokenFileStillReturnsItsText(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": ');

        $result = $this->routes()->get(self::request(['kind' => 'type', 'slug' => 'product']));

        self::assertTrue($result['exists']);
        self::assertSame('{"name": ', $result['text']);
        self::assertSame(SchemaFileWriter::hash('{"name": '), $result['hash']);
    }

    public function testGetReportsFileModsReadOnly(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product"}');

        $result = $this->routes(false)->get(self::request(['kind' => 'type', 'slug' => 'product']));

        self::assertTrue($result['exists']);
        self::assertFalse($result['writable']);
        self::assertSame('file_mods', $result['reason']);
    }

    // --- delete ----------------------------------------------------------------

    public function testDeleteNeedsConfirmWithContent(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product"}');
        $this->posts['product'] = 4;

        $error = self::assertWpError('proto_blocks_confirm_required', 409, $this->routes()->delete(self::request(['kind' => 'type', 'slug' => 'product'])));
        self::assertSame(4, $error->get_error_data()['content']['count']);
        self::assertWpError('proto_blocks_confirm_required', 409, $this->routes()->delete(self::request(['kind' => 'type', 'slug' => 'product', 'confirm' => 'Product'])));
        self::assertFileExists($this->root . '/proto-types/product/type.jsonc');

        $result = $this->routes()->delete(self::request(['kind' => 'type', 'slug' => 'product'], ['confirm' => 'product']));
        self::assertIsArray($result, json_encode($result) ?: '');
        self::assertSame(['deleted', 'kept'], array_keys($result));
        self::assertSame([], $result['kept']);
        self::assertDirectoryDoesNotExist($this->root . '/proto-types/product');
        $this->assertNoAbsolutePaths($result);
    }

    public function testDeleteReadsConfirmFromQuery(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product"}');
        $this->posts['product'] = 4;

        $result = $this->routes()->delete(self::request(['kind' => 'type', 'slug' => 'product', 'confirm' => 'product']));

        self::assertIsArray($result, json_encode($result) ?: '');
        self::assertFileDoesNotExist($this->root . '/proto-types/product/type.jsonc');
    }

    public function testDeleteInlineTaxonomyContentNeedsConfirm(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product", "taxonomies": [{"name": "Brand"}]}');
        $this->terms['brand'] = 3;

        $error = self::assertWpError('proto_blocks_confirm_required', 409, $this->routes()->delete(self::request(['kind' => 'type', 'slug' => 'product'])));
        self::assertSame(['brand' => 3], $error->get_error_data()['content']['taxonomies']);
    }

    public function testDeleteWithoutContentNeedsNoConfirm(): void
    {
        $this->write('proto-taxonomies/genre/taxonomy.jsonc', '{"name": "Genre"}');
        $this->write('proto-taxonomies/genre/notes.txt', 'mine');

        $result = $this->routes()->delete(self::request(['kind' => 'taxonomy', 'slug' => 'genre']));

        self::assertSame(['notes.txt'], $result['kept']);
        self::assertFileExists($this->root . '/proto-taxonomies/genre/notes.txt');
    }

    public function testDeletePhpDeclarationIsNotAFile(): void
    {
        PhpDeclarations::add('type', 'Product', [], '/srv/theme/functions.php:3');

        $error = self::assertWpError('proto_blocks_not_a_file', 409, $this->routes()->delete(self::request(['kind' => 'type', 'slug' => 'product'])));
        $this->assertNoAbsolutePaths($error->get_error_message());
    }

    public function testDeleteInlineTaxonomyIsNotAFile(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product", "taxonomies": [{"name": "Brand"}]}');

        self::assertWpError('proto_blocks_not_a_file', 409, $this->routes()->delete(self::request(['kind' => 'taxonomy', 'slug' => 'brand'])));
        self::assertFileExists($this->root . '/proto-types/product/type.jsonc');
    }

    public function testDeleteUnknownIs404(): void
    {
        self::assertWpError('proto_blocks_not_found', 404, $this->routes()->delete(self::request(['kind' => 'type', 'slug' => 'product'])));
    }

    public function testWriteErrorsCarryNoAbsolutePaths(): void
    {
        $this->write('proto-types/product/type.jsonc', '{"name": "Product"}');
        chmod($this->root . '/proto-types/product', 0555);
        chmod($this->root . '/proto-types/product/type.jsonc', 0444);
        if (is_writable($this->root . '/proto-types/product')) {
            self::markTestSkipped('running as root');
        }

        $error = self::assertWpError('proto_blocks_not_writable', 403, $this->routes()->save(self::request(
            ['kind' => 'type', 'slug' => 'product'],
            ['text' => '{"name": "Item"}', 'hash' => SchemaFileWriter::hash('{"name": "Product"}')]
        )));
        $this->assertNoAbsolutePaths([$error->get_error_message(), $error->get_error_data()]);
        chmod($this->root . '/proto-types/product', 0755);
    }

    public function testCreateTargetReportsNotWritable(): void
    {
        chmod($this->root, 0555);
        if (is_writable($this->root)) {
            self::markTestSkipped('running as root');
        }

        $result = (new PostTypesRoutes($this->registry(), $this->modules(), $this->writer(), $this->guardrails(), $this->scaffolder()))->index();

        self::assertFalse($result['createTarget']['writable']);
        self::assertSame('not_writable', $result['createTarget']['reason']);
        self::assertStringEndsWith('proto-types', $result['createTarget']['path']);
        $this->assertNoAbsolutePaths($result);
    }

    public function testDisplayPathIsRelative(): void
    {
        self::assertSame('wp-content/themes/t/proto-types/a/type.jsonc', SchemaFilesRoutes::displayPath(\ABSPATH . 'wp-content/themes/t/proto-types/a/type.jsonc'));
        self::assertSame('wp-content/themes/t/functions.php:12', SchemaFilesRoutes::displayPath(\ABSPATH . 'wp-content/themes/t/functions.php:12'));
        self::assertSame(basename($this->root) . '/proto-types/a/type.jsonc', SchemaFilesRoutes::displayPath($this->root . '/proto-types/a/type.jsonc'));
        $outside = SchemaFilesRoutes::displayPath('/opt/elsewhere/deep/proto-types/a/type.jsonc');
        self::assertStringStartsNotWith('/', $outside);
        self::assertStringNotContainsString('/opt/elsewhere', $outside);
        self::assertSame('', SchemaFilesRoutes::displayPath(''));
    }
}
