<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Fields;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\Edit\EditRoutes;
use ProtoBlocks\Modules\Fields\Edit\ErrorStore;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/stubs/Structured.php';
require_once __DIR__ . '/stubs/Edit.php';
require_once __DIR__ . '/../../ContentModel/stubs/hooks.php';
require_once __DIR__ . '/../../API/content-model-stubs.php';

final class EditRoutesTest extends TestCase
{
    private const EDITOR = 1;
    private const CONTRIBUTOR = 2;
    private const SUBSCRIBER = 3;
    private const EVENT_GROUP = 'group_6543a1b2c3d4e';

    /** @var list<array<string, mixed>> */
    private array $queries = [];

    /** @var list<object> what the fake query returns */
    private array $found = [];

    protected function setUp(): void
    {
        foreach (['pb_test_routes', 'pb_test_transients', 'pb_test_post_types', 'pb_test_taxonomies', 'pb_test_terms'] as $g) {
            $GLOBALS[$g] = [];
        }
        $GLOBALS['pb_test_caps'] = [
            self::EDITOR => ['edit_posts', 'edit_post', 'read_post', 'edit_term', 'edit_user', 'manage_options'],
            self::CONTRIBUTOR => ['edit_posts'],
            self::SUBSCRIBER => ['read'],
        ];
        $GLOBALS['pb_test_current_user'] = self::EDITOR;
        $GLOBALS['pb_test_posts'] = [
            10 => ['ID' => 10, 'post_type' => 'event', 'post_status' => 'draft', 'post_title' => 'Event'],
            21 => ['ID' => 21, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Hello <b>world</b>'],
            22 => ['ID' => 22, 'post_type' => 'post', 'post_status' => 'private', 'post_title' => 'Secret plans'],
        ];
        $GLOBALS['pb_test_post_types'] = [
            'post' => (object) ['name' => 'post', 'show_ui' => true, 'public' => true, '_builtin' => true, 'publicly_queryable' => true, 'cap' => (object) ['edit_posts' => 'edit_posts']],
            'page' => (object) ['name' => 'page', 'show_ui' => true, 'public' => true, '_builtin' => true, 'publicly_queryable' => false, 'cap' => (object) ['edit_posts' => 'edit_pages']],
            'event' => (object) ['name' => 'event', 'show_ui' => true, 'public' => true, 'publicly_queryable' => true, 'cap' => (object) ['edit_posts' => 'edit_posts']],
            'coupon' => (object) ['name' => 'coupon', 'show_ui' => true, 'public' => false, 'publicly_queryable' => false, 'cap' => (object) ['edit_posts' => 'edit_coupons']],
            'wp_template' => (object) ['name' => 'wp_template', 'show_ui' => false, 'public' => false, 'cap' => (object) ['edit_posts' => 'edit_theme_options']],
        ];
        $GLOBALS['pb_test_taxonomies']['category'] = (object) ['name' => 'category'];
        $GLOBALS['pb_test_terms'] = [5 => ['term_id' => 5, 'taxonomy' => 'category', 'name' => 'Five', 'slug' => 'five']];
        $this->queries = [];
        $this->found = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['pb_test_current_user'] = 0;
        $GLOBALS['pb_test_routes'] = [];
    }

    /** @param list<FieldGroup> $extra */
    private function routes(array $extra = []): EditRoutes
    {
        $raw = json_decode((string) file_get_contents(__DIR__ . '/../../../fixtures/fields/acf-export-realistic.json'), true);
        $groups = [...GroupNormalizer::normalizeMany($raw, 'file:acf-export')['groups'], ...$extra];

        return new EditRoutes(new GroupIndex($groups), new ErrorStore(), function (array $args): array {
            $this->queries[] = $args;
            return ['posts' => $this->found, 'total' => count($this->found), 'pages' => $this->found === [] ? 0 : 3];
        });
    }

    /** @return array{callback: callable, permission_callback: callable, methods: string} */
    private static function route(string $path): array
    {
        foreach ($GLOBALS['pb_test_routes'] as $r) {
            if ($r['route'] === $path) {
                return $r['args'][0] ?? $r['args'];
            }
        }
        self::fail("route $path not registered");
    }

    /** Runs the permission callback, then the callback, like the REST server. */
    private static function dispatch(string $path, array $params): mixed
    {
        $route = self::route($path);
        $request = new \WP_REST_Request($params);
        $allowed = ($route['permission_callback'])($request);
        if ($allowed !== true) {
            return $allowed;
        }

        return ($route['callback'])($request);
    }

    public function testRoutesAreRegistered(): void
    {
        $this->routes()->registerRoutes();

        $paths = array_column($GLOBALS['pb_test_routes'], 'route');
        $this->assertSame(['/fields/errors', '/fields/relationship'], $paths);
        foreach ($GLOBALS['pb_test_routes'] as $r) {
            $this->assertSame('proto-blocks/v1', $r['namespace']);
            $args = $r['args'][0] ?? $r['args'];
            $this->assertSame('GET', $args['methods']);
            $this->assertIsCallable($args['permission_callback']);
        }
    }

    public function testErrorsReturnsThenClears(): void
    {
        $this->routes()->registerRoutes();
        (new ErrorStore())->put(ObjectRef::post(10), self::EDITOR, ['start_date' => 'This field is required.'], ['start_date' => '']);

        $first = self::dispatch('/fields/errors', ['object' => 'post:10']);
        $this->assertSame(['errors' => ['start_date' => 'This field is required.'], 'submitted' => ['start_date' => '']], $first);
        $this->assertSame(['errors' => [], 'submitted' => []], self::dispatch('/fields/errors', ['object' => 'post:10']));
    }

    public function testErrorsForAPostTheUserCannotEditIsForbidden(): void
    {
        $this->routes()->registerRoutes();
        (new ErrorStore())->put(ObjectRef::post(10), self::SUBSCRIBER, ['a' => 'x'], []);
        $GLOBALS['pb_test_current_user'] = self::SUBSCRIBER;

        $result = self::dispatch('/fields/errors', ['object' => 'post:10']);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('proto_blocks_forbidden', $result->get_error_code());
        $this->assertSame(403, $result->get_error_data()['status']);
        $this->assertNotNull((new ErrorStore())->peek(ObjectRef::post(10), self::SUBSCRIBER), 'nothing was consumed');
    }

    public function testErrorsRejectsMalformedObjects(): void
    {
        $this->routes()->registerRoutes();
        foreach (['', 'post:', 'post:0', 'post:10x', "post:10\n", 'comment:5', 'post:-1', 'term:404', 'option:Bad Page'] as $object) {
            $result = self::dispatch('/fields/errors', ['object' => $object]);
            $this->assertInstanceOf(\WP_Error::class, $result, var_export($object, true));
        }
    }

    public function testErrorsForTermsUsersAndOptions(): void
    {
        $this->routes()->registerRoutes();
        $store = new ErrorStore();
        $store->put(ObjectRef::term(5, 'category'), self::EDITOR, ['a' => 'x'], []);
        $store->put(ObjectRef::user(7), self::EDITOR, ['b' => 'y'], []);
        $store->put(ObjectRef::option('site'), self::EDITOR, ['c' => 'z'], []);

        $this->assertSame(['a' => 'x'], self::dispatch('/fields/errors', ['object' => 'term:5'])['errors']);
        $this->assertSame(['b' => 'y'], self::dispatch('/fields/errors', ['object' => 'user:7'])['errors']);
        $this->assertSame(['c' => 'z'], self::dispatch('/fields/errors', ['object' => 'option:site'])['errors']);
    }

    public function testRelationshipUsesTheDeclarationNotTheClient(): void
    {
        $this->routes()->registerRoutes();
        $this->found = [get_post(21)];

        $result = self::dispatch('/fields/relationship', ['group' => self::EVENT_GROUP, 'field' => 'speakers', 'search' => 'hel', 'page' => 2, 'post_type' => 'page', 'post_status' => 'draft', 'per_page' => 500]);

        $this->assertCount(1, $this->queries);
        $q = $this->queries[0];
        $this->assertSame(['post'], $q['post_type']);
        $this->assertSame(['publish', 'private'], $q['post_status']);
        $this->assertSame('readable', $q['perm']);
        $this->assertSame(20, $q['posts_per_page']);
        $this->assertSame(2, $q['paged']);
        $this->assertSame('hel', $q['s']);
        $this->assertSame([['id' => 21, 'title' => 'Hello <b>world</b>', 'type' => 'post', 'status' => 'publish']], $result['items'], 'raw title: the client renders it as text');
        $this->assertSame(2, $result['page']);
        $this->assertTrue($result['hasMore']);
        $this->assertArrayNotHasKey('total', $result, 'no count taken before the visibility check');

        $last = self::dispatch('/fields/relationship', ['group' => self::EVENT_GROUP, 'field' => 'speakers', 'page' => 3]);
        $this->assertFalse($last['hasMore']);
    }

    public function testRelationshipHidesPostsTheUserCannotRead(): void
    {
        $this->routes()->registerRoutes();
        $GLOBALS['pb_test_current_user'] = self::CONTRIBUTOR;
        $this->found = [get_post(21), get_post(22)];

        $result = self::dispatch('/fields/relationship', ['group' => self::EVENT_GROUP, 'field' => 'speakers']);
        $this->assertSame([21], array_column($result['items'], 'id'));
    }

    public function testRelationshipNeedsEditPosts(): void
    {
        $this->routes()->registerRoutes();
        $GLOBALS['pb_test_current_user'] = self::SUBSCRIBER;

        $result = self::dispatch('/fields/relationship', ['group' => self::EVENT_GROUP, 'field' => 'speakers']);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('proto_blocks_forbidden', $result->get_error_code());
        $this->assertSame([], $this->queries);
    }

    public function testRelationshipTypesMustBeRegisteredWithUi(): void
    {
        $group = GroupNormalizer::normalize(['key' => 'group_rel', 'title' => 'Rel', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], 'fields' => [
            ['key' => 'f_rel', 'name' => 'related', 'type' => 'relationship', 'post_type' => ['wp_template', 'nope', 'event'], 'taxonomy' => ['category:five', 'bogus:x', 'category']],
        ]], 'php')['group'];
        $this->routes([$group])->registerRoutes();

        self::dispatch('/fields/relationship', ['group' => 'group_rel', 'field' => 'related']);
        $q = $this->queries[0];
        $this->assertSame(['event'], $q['post_type']);
        $this->assertSame([['taxonomy' => 'category', 'field' => 'slug', 'terms' => ['five']]], array_values(array_filter($q['tax_query'], 'is_array')));
    }

    public function testRelationshipWithNoUsableTypeReturnsNothing(): void
    {
        $group = GroupNormalizer::normalize(['key' => 'group_rel', 'title' => 'Rel', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], 'fields' => [
            ['key' => 'f_rel', 'name' => 'related', 'type' => 'relationship', 'post_type' => ['wp_template']],
        ]], 'php')['group'];
        $this->routes([$group])->registerRoutes();

        $result = self::dispatch('/fields/relationship', ['group' => 'group_rel', 'field' => 'related']);
        $this->assertSame(['items' => [], 'page' => 1, 'hasMore' => false], $result);
        $this->assertSame([], $this->queries);
    }

    public function testRelationshipUnknownGroupOrFieldIsNotFound(): void
    {
        $this->routes()->registerRoutes();

        foreach ([['group' => 'nope', 'field' => 'speakers'], ['group' => self::EVENT_GROUP, 'field' => 'nope'], ['group' => self::EVENT_GROUP, 'field' => 'subtitle']] as $params) {
            $result = self::dispatch('/fields/relationship', $params);
            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame(404, $result->get_error_data()['status']);
        }
        $this->assertSame([], $this->queries);
    }

    public function testDeclaredNonPublicTypeNeedsItsEditCapability(): void
    {
        $group = GroupNormalizer::normalize(['key' => 'group_rel', 'title' => 'Rel', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], 'fields' => [
            ['key' => 'f_rel', 'name' => 'related', 'type' => 'relationship', 'post_type' => ['coupon', 'post', 'page']],
        ]], 'php')['group'];
        $this->routes([$group])->registerRoutes();

        $GLOBALS['pb_test_current_user'] = self::CONTRIBUTOR;
        self::dispatch('/fields/relationship', ['group' => 'group_rel', 'field' => 'related']);
        $this->assertSame(['post', 'page'], $this->queries[0]['post_type'], 'a show_ui, non-public type is excluded for a contributor; page is a built-in public type');

        $GLOBALS['pb_test_caps'][self::CONTRIBUTOR][] = 'edit_coupons';
        self::dispatch('/fields/relationship', ['group' => 'group_rel', 'field' => 'related']);
        $this->assertSame(['coupon', 'post', 'page'], $this->queries[1]['post_type'], 'a user who edits coupons may search them');
    }

    public function testUndeclaredTypesFallBackToViewableTypesOnly(): void
    {
        $group = GroupNormalizer::normalize(['key' => 'group_rel', 'title' => 'Rel', 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'event']]], 'fields' => [
            ['key' => 'f_rel', 'name' => 'related', 'type' => 'relationship'],
        ]], 'php')['group'];
        $this->routes([$group])->registerRoutes();
        $GLOBALS['pb_test_caps'][self::EDITOR][] = 'edit_coupons';

        self::dispatch('/fields/relationship', ['group' => 'group_rel', 'field' => 'related']);
        $this->assertSame(['post', 'page', 'event'], $this->queries[0]['post_type'], 'never coupon or wp_template by default, even for a user who edits coupons');
    }
}
