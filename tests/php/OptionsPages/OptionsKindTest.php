<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\OptionsPages;

require_once __DIR__ . '/../API/ContentModelRoutesTestCase.php';
require_once __DIR__ . '/wp-stubs.php';

use ProtoBlocks\API\Admin\SchemaFilesRoutes;
use ProtoBlocks\CLI\ContentModelCommands;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\ContentModel\SchemaCheck;
use ProtoBlocks\ContentModel\Slug;
use ProtoBlocks\OptionsPages\OptionsPagesRegistry;
use ProtoBlocks\OptionsPages\PageTemplate;
use ProtoBlocks\Tests\API\ContentModelRoutesTestCase;

/**
 * The `options` declaration kind inside Phase 1's machinery: folders, slugs,
 * schema, the schema-file route and the declaration-errors filter.
 */
final class OptionsKindTest extends ContentModelRoutesTestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/options-pages';

    private function routes(): SchemaFilesRoutes
    {
        return new SchemaFilesRoutes($this->registry(), $this->modules(), $this->writer(), $this->guardrails(), $this->scaffolder());
    }

    private static function fixture(string $relative): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $relative);
    }

    public function testFileLoaderKnowsOptionsKind(): void
    {
        self::assertSame('proto-options', FileLoader::FOLDERS['options']);
        self::assertSame('page', FileLoader::BASENAMES['options']);
    }

    public function testSlugRulesForOptions(): void
    {
        self::assertNull(Slug::error('theme-settings', 'options'));
        self::assertSame('pattern', Slug::error('theme_settings', 'options'));
        self::assertSame('pattern', Slug::error('../x', 'options'));
        self::assertSame('pattern', Slug::error("theme-settings\n", 'options'));
        self::assertSame('pattern', Slug::error('Theme', 'options'));
        self::assertNull(Slug::error(str_repeat('a', 64), 'options'));
        self::assertSame('length', Slug::error(str_repeat('a', 65), 'options'));
        self::assertSame('reserved', Slug::error('proto-blocks', 'options'));
        self::assertSame('reserved', Slug::error('proto-blocks-fields', 'options'));
        self::assertSame('reserved', Slug::error('options-general', 'options'));
        // Words reserved for post types are fine as page slugs.
        self::assertNull(Slug::error('type', 'options'));
    }

    public function testSchemaAcceptsFixturesAndRejectsBadStorage(): void
    {
        $schema = SchemaCheck::schema();
        foreach (['cadco-display-settings', 'theme-settings'] as $slug) {
            $data = Jsonc::decode(self::fixture($slug . '/page.jsonc'));
            self::assertSame([], SchemaCheck::validate($data, $schema, '#/$defs/options'), $slug);
        }

        $errors = SchemaCheck::validate(Jsonc::decode(self::fixture('invalid/unknown-mode.jsonc')), $schema, '#/$defs/options');
        self::assertNotSame([], $errors);
        foreach ($errors as $error) {
            self::assertStringStartsWith('/storage', $error['path']);
        }

        $errors = SchemaCheck::validate(Jsonc::decode(self::fixture('invalid/missing-title.jsonc')), $schema, '#/$defs/options');
        self::assertSame(['/title'], array_column($errors, 'path'));
    }

    public function testSchemaChecksStorageOptionAndChildren(): void
    {
        $schema = SchemaCheck::schema();
        $check = static fn (array $data): array => array_column(SchemaCheck::validate($data, $schema, '#/$defs/options'), 'path');

        self::assertSame(['/storage/option'], $check(['title' => 'X', 'storage' => ['mode' => 'array']]));
        self::assertSame(['/storage/option'], $check(['title' => 'X', 'storage' => ['mode' => 'array', 'option' => "x\n"]]));
        self::assertSame([], $check(['title' => 'X', 'storage' => ['mode' => 'fields']]));
        self::assertSame(['/children/0/slug'], $check(['title' => 'X', 'children' => [['slug' => '../x', 'title' => 'Y']]]));
        self::assertSame(['/children/0/title'], $check(['title' => 'X', 'children' => [['slug' => 'y']]]));
        self::assertSame(['/tabs/0/title'], $check(['title' => 'X', 'tabs' => [['fields' => []]]]));
        self::assertSame(['/menu'], $check(['title' => 'X', 'menu' => 'Top Level']));
        self::assertSame(['/capability'], $check(['title' => 'X', 'capability' => 'Manage_Options']));
        self::assertSame([], $check(['title' => 'X', 'acf_extra' => ['anything' => true]]));
    }

    public function testSchemaFileRoutePatternRejectsTraversal(): void
    {
        $this->routes()->registerRoutes('proto-blocks/v1', static fn (): bool => true, static fn (): bool => true);

        $pattern = null;
        foreach ($GLOBALS['pb_test_routes'] as $entry) {
            if (str_starts_with(ltrim((string) $entry['route'], '/'), 'schema-files/')) {
                $pattern = ltrim((string) $entry['route'], '/');
            }
        }
        self::assertNotNull($pattern);
        // WordPress matches routes case-insensitively, anchored at both ends.
        $match = static fn (string $path): bool => preg_match('@^' . $pattern . '$@i', $path) === 1;

        self::assertTrue($match('schema-files/options/theme-settings'));
        self::assertTrue($match('schema-files/options/' . str_repeat('a', 64)));
        self::assertTrue($match('schema-files/type/product'));
        self::assertTrue($match('schema-files/taxonomy/genre'));
        foreach (['options/../x', 'options/a/b', 'options/', 'options/.x', 'options/%2e%2e', 'option/x', 'plugin/x'] as $path) {
            self::assertFalse($match('schema-files/' . $path), $path);
        }
        self::assertFalse($match('schema-files/options/' . str_repeat('a', 65)));

        // `A` passes the case-insensitive pattern; the handler refuses it before any path is built.
        $routes = $this->routes();
        foreach (['A', 'theme_settings', 'proto-blocks', str_repeat('a', 65)] as $slug) {
            self::assertWpError('proto_blocks_invalid_slug', 400, $routes->get(new \WP_REST_Request(['kind' => 'options', 'slug' => $slug])));
            self::assertWpError('proto_blocks_invalid_slug', 400, $routes->save(new \WP_REST_Request(['kind' => 'options', 'slug' => $slug], ['text' => '{"title": "X"}', 'hash' => null])));
        }
        self::assertFileDoesNotExist($this->root . '/proto-options');
    }

    public function testSchemaFileGetServesOptionsFileWithComments(): void
    {
        $text = self::fixture('cadco-display-settings/page.jsonc');
        $this->write('proto-options/cadco-display-settings/page.jsonc', $text);

        $result = $this->routes()->get(new \WP_REST_Request(['kind' => 'options', 'slug' => 'cadco-display-settings']));
        self::assertIsArray($result, json_encode($result) ?: '');
        self::assertTrue($result['exists']);
        self::assertSame($text, $result['text']);
        self::assertSame(hash('sha256', $text), $result['hash']);
        self::assertSame('manage_woocommerce', $result['data']['capability']);
        $this->assertNoAbsolutePaths($result);

        $missing = $this->routes()->get(new \WP_REST_Request(['kind' => 'options', 'slug' => 'shop-notices']));
        self::assertIsArray($missing);
        self::assertFalse($missing['exists']);
        self::assertNull($missing['hash']);
        self::assertSame(PageTemplate::render('Shop Notices'), $missing['text']);
        self::assertSame('Shop Notices', $missing['data']['title']);
    }

    public function testSchemaFileSaveWritesOptionsPage(): void
    {
        $text = PageTemplate::render('Shop Notices', ['menu' => 'woocommerce']);
        $result = $this->routes()->save(new \WP_REST_Request(['kind' => 'options', 'slug' => 'shop-notices'], ['text' => $text, 'hash' => null, 'acknowledge' => []]));

        self::assertIsArray($result, json_encode($result) ?: '');
        self::assertTrue($result['created']);
        self::assertSame($text, file_get_contents($this->root . '/proto-options/shop-notices/page.jsonc'));
    }

    public function testDeclarationErrorsFilterRefusesProtectedOption(): void
    {
        $data = ['title' => 'X', 'storage' => ['mode' => 'array', 'option' => 'siteurl']];

        $errors = OptionsPagesRegistry::declarationErrors([], 'options', $data, 'x');
        self::assertNotSame([], $errors);
        self::assertSame('/storage/option', $errors[0]['path']);
        self::assertStringContainsString('siteurl', $errors[0]['message']);

        self::assertSame([], OptionsPagesRegistry::declarationErrors([], 'type', $data, 'x'));
        $earlier = [['path' => '/fields/0', 'message' => 'From another validator.']];
        self::assertSame($earlier, OptionsPagesRegistry::declarationErrors($earlier, 'taxonomy', $data, 'x'));
        self::assertSame($earlier[0], OptionsPagesRegistry::declarationErrors($earlier, 'options', $data, 'x')[0]);

        // A slug + field pair deriving a protected name is refused too.
        $derived = OptionsPagesRegistry::declarationErrors([], 'options', ['title' => 'X', 'fields' => [['name' => 'user_roles', 'type' => 'text']]], 'wp');
        self::assertNotSame([], $derived);
        self::assertSame([], OptionsPagesRegistry::declarationErrors([], 'options', ['title' => 'X'], 'x'));

        // Through Phase 1's writer: 400 and nothing written.
        $GLOBALS['pb_test_filters']['proto_blocks_declaration_errors'][] = [OptionsPagesRegistry::class, 'declarationErrors'];
        $text = "{\n  // Hijack the site URL.\n  \"title\": \"X\",\n  \"storage\": { \"mode\": \"array\", \"option\": \"siteurl\" }\n}\n";
        $result = $this->routes()->save(new \WP_REST_Request(['kind' => 'options', 'slug' => 'x'], ['text' => $text, 'hash' => null, 'acknowledge' => []]));

        $error = self::assertWpError('proto_blocks_schema_invalid', 400, $result);
        self::assertSame('/storage/option', $error->get_error_data()['errors'][0]['path']);
        self::assertFileDoesNotExist($this->root . '/proto-options/x/page.jsonc');
        self::assertDirectoryDoesNotExist($this->root . '/proto-options');
    }

    public function testDeclarationErrorsFilterReceivesSlug(): void
    {
        $seen = [];
        $GLOBALS['pb_test_filters']['proto_blocks_declaration_errors'][] = static function (array $errors, string $kind, array $data, string $slug = '') use (&$seen): array {
            $seen[] = [$kind, $slug, $data['title'] ?? null];
            return $errors;
        };

        $text = PageTemplate::render('Shop Notices');
        $this->writer()->write('options', 'shop-notices', $text, null, null);

        self::assertSame([['options', 'shop-notices', 'Shop Notices']], $seen);
    }

    public function testUnknownFindingShapeFailsClosed(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_declaration_errors'][] = static fn (array $errors): array => [...$errors, ['path' => '/x']];

        try {
            $this->writer()->write('options', 'shop-notices', PageTemplate::render('Shop Notices'), null, null);
            self::fail('Expected the write to be refused.');
        } catch (\ProtoBlocks\ContentModel\WriteException $e) {
            self::assertSame('schema_invalid', $e->errorCode);
        }
        self::assertFileDoesNotExist($this->root . '/proto-options/shop-notices/page.jsonc');
    }

    public function testValidateReportsDeclarationErrorsOnce(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_declaration_errors'][] = [OptionsPagesRegistry::class, 'declarationErrors'];
        // Passes the schema (the option name is well formed) but is refused by the filter.
        $this->write('proto-options/hijack/page.jsonc', '{"title": "Hijack", "storage": {"mode": "array", "option": "siteurl"}}');
        $this->write('proto-options/fine/page.jsonc', '{"title": "Fine"}');

        $pages = new OptionsPagesRegistry($this->registry());
        self::assertNull($pages->get('hijack'));
        self::assertNotNull($pages->get('fine'));

        // The writer's filter and the registry report the same finding; validate shows it once.
        $rows = ContentModelCommands::validationRows($this->registry(), $pages->issues());
        $hijack = array_values(array_filter($rows, static fn (array $row): bool => $row['slug'] === 'hijack'));
        self::assertCount(1, $hijack, json_encode($rows) ?: '');
        self::assertSame(['options', 'error', 'declaration_invalid'], [$hijack[0]['kind'], $hijack[0]['level'], $hijack[0]['code']]);
        self::assertStringStartsWith('/storage/option: ', $hijack[0]['message']);
        self::assertSame('proto-options/hijack/page.jsonc', $hijack[0]['source']);
        self::assertSame([], array_filter($rows, static fn (array $row): bool => $row['slug'] === 'fine'));
    }
}
