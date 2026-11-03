<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\API;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ProtoBlocks\Admin\AdminApp;
use ProtoBlocks\Admin\FieldsMenu;
use ProtoBlocks\API\Admin\FieldsRoutes;
use ProtoBlocks\API\Admin\SchemaFilesRoutes;
use ProtoBlocks\Modules\Manager;
use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\Modules\Fields\FieldsModule;
use ProtoBlocks\Tests\Modules\Fields\FieldsTestCase;

require_once __DIR__ . '/../Modules/Fields/FieldsTestCase.php';
require_once __DIR__ . '/../Admin/wp-stubs.php';
require_once __DIR__ . '/../CLI/wp-stubs.php';
require_once __DIR__ . '/../ContentModel/stubs/hooks.php';
require_once __DIR__ . '/content-model-stubs.php';
require_once __DIR__ . '/stubs/wp-roles.php';
require_once __DIR__ . '/../../../includes/Modules/Fields/functions.php';

/**
 * admin/fields: the Fields screen's list, detail and validate routes.
 */
final class FieldsRoutesTest extends FieldsTestCase
{
    private const EVENTS_KEY = 'group_6543a1b2c3d4e';
    private const KEY_ROUTE = 'admin/fields/(?P<key>[A-Za-z0-9_-]+)';

    private string $root = '';
    private ?Registry $registry = null;

    protected function setUp(): void
    {
        parent::setUp();
        FieldsModule::registerFileKind();
        $dir = sys_get_temp_dir() . '/pb-fields-routes-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->root = (string) realpath($dir);
        $GLOBALS['pb_test_theme'] = [
            'template' => $this->root,
            'stylesheet' => $this->root,
            'template_uri' => 'http://example.test/t',
            'stylesheet_uri' => 'http://example.test/t',
        ];
        $GLOBALS['pb_test_current_user'] = 1;
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options']];
        $GLOBALS['pb_test_routes'] = [];

        $this->write('proto-fields/events/group.jsonc', (string) file_get_contents(__DIR__ . '/../../fixtures/fields/acf-export-realistic.json'));
        $this->write('proto-fields/promo/group.jsonc', <<<'JSONC'
{
  // Shown only for one post format: not supported
  "key": "group_promo",
  "title": "Promo",
  "fields": [{ "key": "field_promo_text", "name": "promo_text", "type": "text" }],
  "location": [[{ "param": "post_format", "operator": "==", "value": "video" }]]
}
JSONC);
        $this->write('proto-types/talk/type.jsonc', <<<'JSONC'
{
  "name": "Talk",
  "fields": [
    { "key": "group_talk", "title": "Talk details", "fields": [{ "key": "field_talk_room", "name": "room", "type": "text" }] }
  ]
}
JSONC);
        proto_field_group('product', ['key' => 'group_product', 'title' => 'Product', 'fields' => [['key' => 'f_sku', 'name' => 'sku', 'label' => 'SKU', 'type' => 'text']]]);
        do_action('init');
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
        $this->registry = null;
        parent::tearDown();
    }

    private function write(string $relative, string $text): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $text);
    }

    private static function removeTree(string $path): void
    {
        if ($path === '' || (!is_dir($path) && !is_file($path) && !is_link($path))) {
            return;
        }
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    private function registry(): Registry
    {
        return $this->registry ??= new Registry(new FileLoader(), fn(): array => [$this->root]);
    }

    private function module(): FieldsModule
    {
        $registry = $this->registry();

        return new FieldsModule(
            fn(): array => [$this->root],
            [FieldsModule::class, 'decodeFile'],
            static fn(): array => [...array_values($registry->postTypes()), ...array_values($registry->taxonomies())]
        );
    }

    private function routes(bool $fileMods = true, ?FieldsModule $module = null): FieldsRoutes
    {
        return new FieldsRoutes($this->registry(), null, new SchemaFileWriter([$this->root], $fileMods), $module ?? $this->module());
    }

    /** @param array<string, mixed> $list */
    private static function group(array $list, string $key): array
    {
        foreach ($list['groups'] as $group) {
            if ($group['key'] === $key) {
                return $group;
            }
        }
        self::fail("no group $key in " . json_encode(array_column($list['groups'], 'key')));
    }

    /** @param mixed $result */
    private static function assertWpError(string $code, int $status, $result): \WP_Error
    {
        self::assertInstanceOf(\WP_Error::class, $result, 'expected ' . $code . ', got ' . json_encode($result));
        self::assertSame($code, $result->get_error_code(), $result->get_error_message());
        self::assertSame($status, $result->get_error_data()['status']);

        return $result;
    }

    private function assertNoAbsolutePaths(mixed $data): void
    {
        $json = (string) json_encode($data, JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString($this->root, $json);
        self::assertStringNotContainsString(sys_get_temp_dir(), $json);
    }

    public function testRoutesRegisteredWithThePermission(): void
    {
        $permission = static fn(): bool => true;
        $writePermission = static fn(): bool => true;
        $this->routes()->registerRoutes('proto-blocks/v1', $permission, $writePermission);

        $seen = [];
        foreach ($GLOBALS['pb_test_routes'] as $entry) {
            self::assertSame('proto-blocks/v1', $entry['namespace']);
            foreach ($entry['args'] as $endpoint) {
                $seen[ltrim($entry['route'], '/') . ' ' . $endpoint['methods']] = true;
                self::assertSame($permission, $endpoint['permission_callback']);
            }
        }
        self::assertSame(['admin/fields GET', 'admin/fields/validate POST', self::KEY_ROUTE . ' GET'], array_keys($seen));
        self::assertContains(FieldsRoutes::class, AdminRestController::ROUTE_GROUPS);
    }

    public function testKeyRouteRegexRefusesTraversal(): void
    {
        $match = static fn(string $path): bool => preg_match('@^' . self::KEY_ROUTE . '$@i', $path) === 1;

        self::assertTrue($match('admin/fields/' . self::EVENTS_KEY));
        foreach (['admin/fields/../etc', 'admin/fields/a/b', 'admin/fields/a.b', 'admin/fields/', 'admin/fields/%2e%2e'] as $path) {
            self::assertFalse($match($path), $path);
        }
    }

    public function testListEntries(): void
    {
        $list = $this->routes()->list();

        self::assertTrue($list['moduleEnabled']);
        self::assertFalse($list['acfActive']);
        $this->assertNoAbsolutePaths($list);

        $events = self::group($list, self::EVENTS_KEY);
        self::assertSame('Event details', $events['title']);
        self::assertSame('file', $events['origin']);
        self::assertSame(['kind' => 'field-group', 'slug' => 'events', 'index' => 0, 'path' => 'proto-fields/events/group.jsonc'], $events['file']);
        self::assertSame('proto-fields/events/group.jsonc', $events['source']);
        self::assertSame('active', $events['status']);
        self::assertSame('post_type == event', $events['location']);
        self::assertSame(18, $events['fieldCount']);
        self::assertSame('acf_after_title', $events['position']);
        self::assertTrue($events['editable']);
        self::assertNull($events['readOnlyReason']);
        self::assertFalse($events['doubleDeclared']);

        $php = self::group($list, 'group_product');
        self::assertSame('php', $php['origin']);
        self::assertNull($php['file']);
        self::assertFalse($php['editable']);
        self::assertSame('inline', $php['readOnlyReason']);
        self::assertStringContainsString('FieldsRoutesTest.php:', $php['source']);

        $talk = self::group($list, 'group_talk');
        self::assertSame('type-file', $talk['origin']);
        self::assertSame(['kind' => 'type', 'slug' => 'talk', 'index' => 0, 'path' => 'proto-types/talk/type.jsonc'], $talk['file']);
        self::assertSame('post_type == talk', $talk['location']);

        $promo = self::group($list, 'group_promo');
        self::assertSame('unrenderable', $promo['status']);
        self::assertNotEmpty($promo['issues']);
        self::assertStringContainsString('post_format', $promo['issues'][0]['message']);
        self::assertSame(['level', 'code', 'path', 'message'], array_keys($promo['issues'][0]));
        self::assertSame('proto-fields/promo/group.jsonc', $promo['issues'][0]['path']);

        self::assertTrue($list['createTarget']['writable']);
    }

    public function testListCarriesNoValues(): void
    {
        $GLOBALS['pb_test_meta']['post'][10]['subtitle'] = ['A secret stored value'];
        $GLOBALS['pb_test_options']['settings_promo_text'] = 'Another stored value';

        $json = (string) json_encode($this->routes()->list());
        self::assertStringNotContainsString('stored value', $json);
    }

    public function testFileModsOffMakesFilesReadOnly(): void
    {
        $list = $this->routes(false)->list();

        $events = self::group($list, self::EVENTS_KEY);
        self::assertFalse($events['editable']);
        self::assertSame('file_mods', $events['readOnlyReason']);
        self::assertSame('file_mods', self::group($list, 'group_talk')['readOnlyReason']);
        self::assertFalse($list['createTarget']['writable']);
    }

    public function testDisabledModule(): void
    {
        $list = $this->routes(true, FieldsModule::disabled())->list();

        self::assertFalse($list['moduleEnabled']);
        self::assertSame([], $list['groups']);
    }

    /** The field-group kind (and route) exists only when the manager boots an enabled Fields module. */
    public function testModuleOffHasNoFieldGroupKindOrGroups(): void
    {
        $schemaRoute = function (): string {
            $GLOBALS['pb_test_routes'] = [];
            (new SchemaFilesRoutes($this->registry(), null, new SchemaFileWriter([$this->root], true)))
                ->registerRoutes('proto-blocks/v1', static fn(): bool => true, static fn(): bool => true);
            foreach ($GLOBALS['pb_test_routes'] as $entry) {
                if (str_contains((string) $entry['route'], 'schema-files')) {
                    return ltrim((string) $entry['route'], '/');
                }
            }
            self::fail('no schema-files route');
        };

        SchemaFileWriter::resetKinds();
        $GLOBALS['pb_test_filters']['proto_blocks_modules'][] = static fn(array $defaults): array => ['fields' => false] + $defaults;
        (new Manager($this->registry()))->boot();

        self::assertSame([], SchemaFileWriter::extendedKinds());
        $route = $schemaRoute();
        self::assertStringNotContainsString('field-group', $route);
        self::assertSame(0, preg_match('@^' . $route . '$@i', 'admin/schema-files/field-group/events'));

        // FieldsRoutes resolves the module the manager registered: the disabled stand-in.
        $routes = new FieldsRoutes($this->registry(), null, new SchemaFileWriter([$this->root], true));
        $list = $routes->list();
        self::assertFalse($list['moduleEnabled']);
        self::assertSame([], $list['groups']);
        self::assertFalse($list['createTarget']['writable']);
        self::assertWpError('proto_blocks_not_found', 404, $routes->detail(new \WP_REST_Request(['key' => self::EVENTS_KEY])));

        // Turned on, the same boot adds the kind to the route.
        SchemaFileWriter::resetKinds();
        FieldsModule::setInstance(null);
        $GLOBALS['pb_test_filters']['proto_blocks_modules'] = [];
        (new Manager($this->registry()))->boot();
        self::assertSame(['field-group'], SchemaFileWriter::extendedKinds());
        self::assertSame(1, preg_match('@^' . $schemaRoute() . '$@i', 'admin/schema-files/field-group/events'));
    }

    public function testDoubleDeclaredUsesTheIssueKey(): void
    {
        // The same key from PHP and from a proto-fields file: the file wins and is flagged.
        proto_field_group('event', ['key' => self::EVENTS_KEY, 'title' => 'Events from PHP', 'fields' => [['key' => 'f_x', 'name' => 'x', 'type' => 'text']]]);

        $list = $this->routes()->list();
        self::assertTrue(self::group($list, self::EVENTS_KEY)['doubleDeclared']);
        self::assertFalse(self::group($list, 'group_promo')['doubleDeclared'], 'same file family, other key');
    }

    public function testValidateReportsGroupErrors(): void
    {
        $text = '{ "key": "g", // c' . "\n" . ' "title": "T", "fields": [ { "key": "f", "name": "_x", "type": "text" } ] }';
        $result = $this->routes()->validate(new \WP_REST_Request([], ['text' => $text, 'kind' => 'field-group']));

        self::assertIsArray($result, json_encode($result));
        self::assertSame('invalid_name', $result['errors'][0]['code']);
        self::assertSame('fields[0].name', $result['errors'][0]['path']);
        self::assertSame(['path', 'code', 'message'], array_keys($result['errors'][0]));
        self::assertIsArray($result['warnings']);
    }

    public function testValidateMalformedText(): void
    {
        $error = self::assertWpError('proto_blocks_invalid_jsonc', 400, $this->routes()->validate(new \WP_REST_Request([], ['text' => "{\n  \"a\": 1,\n  x\n}", 'kind' => 'field-group'])));
        self::assertSame(3, $error->get_error_data()['line']);
        self::assertArrayHasKey('column', $error->get_error_data());
    }

    public function testValidateAGroupInsideATypeFile(): void
    {
        $text = '{ "name": "Talk", "fields": [ { "key": "g", "title": "T", "fields": [] }, { "key": "h", "title": "H", "fields": [ { "key": "f", "name": "Bad name", "type": "text" } ] } ] }';
        $routes = $this->routes();

        $one = $routes->validate(new \WP_REST_Request([], ['text' => $text, 'kind' => 'type', 'index' => 1]));
        self::assertSame('invalid_name', $one['errors'][0]['code']);
        self::assertSame('fields[1].fields[0].name', $one['errors'][0]['path']);

        self::assertSame([], $routes->validate(new \WP_REST_Request([], ['text' => $text, 'kind' => 'type', 'index' => 0]))['errors']);
        self::assertCount(1, $routes->validate(new \WP_REST_Request([], ['text' => $text, 'kind' => 'type']))['errors']);
    }

    public function testValidateRefusesBadRequests(): void
    {
        $routes = $this->routes();
        self::assertWpError('proto_blocks_invalid_request', 400, $routes->validate(new \WP_REST_Request([], ['text' => 1, 'kind' => 'field-group'])));
        self::assertWpError('proto_blocks_invalid_request', 400, $routes->validate(new \WP_REST_Request([], ['text' => '{}', 'kind' => 'options'])));
        self::assertWpError('proto_blocks_invalid_request', 400, $routes->validate(new \WP_REST_Request([], ['text' => '{}', 'kind' => 'type', 'index' => -1])));
        self::assertWpError('proto_blocks_file_too_large', 413, $routes->validate(new \WP_REST_Request([], ['text' => str_repeat(' ', FileLoader::MAX_BYTES + 1), 'kind' => 'field-group'])));
    }

    public function testDetail(): void
    {
        $GLOBALS['pb_test_post_types']['event'] = (object) ['name' => 'event', 'label' => 'Events', 'show_ui' => true];
        $GLOBALS['pb_test_taxonomies']['genre'] = (object) ['name' => 'genre', 'label' => 'Genres', 'show_ui' => true];
        $GLOBALS['pb_test_roles'] = ['editor' => ['name' => 'Editor', 'capabilities' => []]];

        $detail = $this->routes()->detail(new \WP_REST_Request(['key' => self::EVENTS_KEY]));

        self::assertIsArray($detail, json_encode($detail));
        $this->assertNoAbsolutePaths($detail);
        self::assertSame(self::EVENTS_KEY, $detail['group']['key']);
        self::assertSame('field-group', $detail['file']['kind']);
        self::assertSame('events', $detail['file']['slug']);
        self::assertSame(0, $detail['file']['index']);
        self::assertTrue($detail['file']['exists']);
        self::assertSame(SchemaFileWriter::hash((string) file_get_contents($this->root . '/proto-fields/events/group.jsonc')), $detail['file']['hash']);
        self::assertSame([['slug' => 'event', 'label' => 'Events']], $detail['postTypes']);
        self::assertSame([['slug' => 'genre', 'label' => 'Genres']], $detail['taxonomies']);
        self::assertSame([['slug' => 'editor', 'label' => 'Editor']], $detail['roles']);
        self::assertIsArray($detail['optionsPages']);
        self::assertIsArray($detail['pageTemplates']);

        $talk = $this->routes()->detail(new \WP_REST_Request(['key' => 'group_talk']));
        self::assertSame(['type', 'talk', 0], [$talk['file']['kind'], $talk['file']['slug'], $talk['file']['index']]);

        self::assertNull($this->routes()->detail(new \WP_REST_Request(['key' => 'group_product']))['file']);
        self::assertWpError('proto_blocks_not_found', 404, $this->routes()->detail(new \WP_REST_Request(['key' => 'group_missing'])));
        self::assertWpError('proto_blocks_not_found', 404, $this->routes()->detail(new \WP_REST_Request(['key' => "../etc"])));
    }

    public function testMenuAddsTheFieldsScreen(): void
    {
        require_once __DIR__ . '/../OptionsPages/wp-stubs.php';
        $GLOBALS['pb_test_menus'] = [];
        $app = new AdminApp(null);

        (new FieldsMenu($app))->register();

        $menu = array_column($GLOBALS['pb_test_menus'], null, 'slug')['proto-blocks-fields'];
        self::assertSame('proto-blocks', $menu['parent']);
        self::assertSame('Fields', $menu['menu_title']);
        self::assertSame('manage_options', $menu['capability']);
        self::assertTrue($app->isScreenHook('hook_proto-blocks-fields'));
        self::assertContains('fields', AdminApp::SCREENS);
        ob_start();
        ($menu['fn'])();
        self::assertStringContainsString('data-screen="fields"', (string) ob_get_clean());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAcfOwnedGroupsAreListedReadOnly(): void
    {
        require __DIR__ . '/../Modules/Fields/stubs/Acf.php';
        $GLOBALS['pb_test_acf_groups'] = [
            self::EVENTS_KEY => ['key' => self::EVENTS_KEY, 'title' => 'Event details (ACF)', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]]],
            'group_seo' => ['key' => 'group_seo', 'title' => 'SEO extras', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]]],
        ];

        $list = $this->routes()->list();
        self::assertTrue($list['acfActive']);

        $skipped = self::group($list, self::EVENTS_KEY);
        self::assertSame('acf', $skipped['origin']);
        self::assertSame('skipped-acf', $skipped['status']);
        self::assertSame('acf', $skipped['readOnlyReason']);
        self::assertNull($skipped['file']);
        self::assertSame('proto-fields/events/group.jsonc', $skipped['source']);
        self::assertSame(1, count(array_filter($list['groups'], static fn(array $g): bool => $g['key'] === self::EVENTS_KEY)), 'listed once');

        $seo = self::group($list, 'group_seo');
        self::assertSame('acf', $seo['origin']);
        self::assertSame('active', $seo['status']);
        self::assertSame('acf', $seo['readOnlyReason']);
        self::assertSame('post_type == post', $seo['location']);
    }
}
