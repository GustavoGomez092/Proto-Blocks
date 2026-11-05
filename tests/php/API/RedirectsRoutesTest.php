<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\API;

require_once __DIR__ . '/stubs/redirects-doubles.php';

use PHPUnit\Framework\TestCase;
use ProtoBlocks\API\Admin\RedirectsRoutes;
use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\Modules\Redirects\Export\NetlifyExporter;
use ProtoBlocks\Modules\Redirects\Import\RedirectionImporter;
use ProtoBlocks\Modules\Redirects\RedirectsModule;
use ProtoBlocks\Modules\Redirects\Registry;
use ProtoBlocks\Modules\Redirects\Sources\GroupFiles;
use ProtoBlocks\Modules\Redirects\Sources\PhpDeclarations;
use ProtoBlocks\Modules\Redirects\Storage\Hits;
use ProtoBlocks\Modules\Redirects\Storage\Installer;
use ProtoBlocks\Modules\Redirects\Storage\NotFoundLog;
use ProtoBlocks\Modules\Redirects\Storage\RedirectLog;
use ProtoBlocks\Tests\API\Stubs\FakeImporter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** A request carrying an uploaded file, as WP_REST_Request::get_file_params() returns it. */
final class UploadRequest extends WP_REST_Request
{
    /** @param array<string, array<string, mixed>> $files */
    public function __construct(private readonly array $files, array $params = [])
    {
        parent::__construct($params);
    }

    /** @return array<string, array<string, mixed>> */
    public function get_file_params(): array
    {
        return $this->files;
    }
}

/**
 * admin/redirects/*: list shape, test trace, logs and 404s, downloads,
 * hits, import, plugin importers, export and the tables (spec §11).
 */
final class RedirectsRoutesTest extends TestCase
{
    private const TABLES = ['wp_proto_redirect_hits', 'wp_proto_redirect_log', 'wp_proto_404_log'];

    private string $root = '';
    private \wpdb $db;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/pb-redirect-routes-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->root = (string) realpath($tmp);
        $GLOBALS['pb_test_theme'] = [
            'template' => $this->root,
            'stylesheet' => $this->root,
            'template_uri' => 'http://example.test/t',
            'stylesheet_uri' => 'http://example.test/t',
        ];
        $GLOBALS['pb_test_home_url'] = 'https://example.test';
        $GLOBALS['pb_test_current_user'] = 1;
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options']];
        $GLOBALS['pb_test_routes'] = [];
        $GLOBALS['pb_test_filters'] = [];
        $GLOBALS['pb_test_hooks'] = [];
        $GLOBALS['pb_test_options'] = [];
        $GLOBALS['pb_test_active_plugins'] = [];
        $GLOBALS['pb_test_deactivated'] = [];
        $GLOBALS['pb_test_network_plugins'] = [];
        $GLOBALS['pb_test_user_switches'] = [];
        $GLOBALS['pb_test_dbdelta'] = [];
        $this->db = new \wpdb();
        $GLOBALS['wpdb'] = $this->db;
        PhpDeclarations::reset();
        SchemaFileWriter::resetKinds();
        RedirectsModule::registerFileKinds();
        RedirectsModule::setInstance(null);
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        SchemaFileWriter::resetKinds();
        RedirectsModule::setInstance(null);
        $GLOBALS['wpdb'] = new \wpdb();
        unset($GLOBALS['pb_test_theme'], $GLOBALS['pb_test_home_url'], $GLOBALS['pb_test_current_user'], $GLOBALS['pb_test_caps'], $GLOBALS['pb_test_multisite']);
        foreach (['pb_test_routes', 'pb_test_filters', 'pb_test_hooks', 'pb_test_options', 'pb_test_active_plugins', 'pb_test_deactivated', 'pb_test_network_plugins', 'pb_test_user_switches', 'pb_test_dbdelta'] as $key) {
            $GLOBALS[$key] = [];
        }
        self::removeTree($this->root);
    }

    // --- fixtures ------------------------------------------------------------

    private function put(string $relative, string $text): string
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $text);

        return $path;
    }

    /** @param list<array<string, mixed>> $rules */
    private function group(string $slug, array $rules, array $extra = []): string
    {
        return $this->put(
            'proto-redirects/' . $slug . '/redirects.jsonc',
            (string) json_encode($extra + ['rules' => $rules], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    private function fileRegistry(): Registry
    {
        return new Registry(fn(): GroupFiles => new GroupFiles(fn(): array => [$this->root]));
    }

    private function routes(?Registry $registry = null, bool $fileMods = true, ?\Closure $importers = null, array $extra = []): RedirectsRoutes
    {
        $registry ??= $this->fileRegistry();

        return new RedirectsRoutes(
            $registry,
            new Hits(),
            new RedirectLog(),
            new NotFoundLog(),
            new Installer(),
            null,
            new SchemaFileWriter([$this->root], $fileMods),
            $extra['formats'] ?? null,
            $importers,
            null,
            $extra['upload'] ?? null,
            $extra['as404'] ?? static function (\Closure $fn): mixed {
                $GLOBALS['pb_test_query']['is_404'] = true;
                try {
                    return $fn();
                } finally {
                    unset($GLOBALS['pb_test_query']['is_404']);
                }
            }
        );
    }

    /** @return list<string> final SQL of query/get_* calls, in order */
    private function sql(): array
    {
        $out = [];
        foreach ($this->db->calls as $call) {
            if (in_array($call[0], ['query', 'get_results', 'get_var', 'get_row', 'get_col'], true)) {
                $out[] = (string) $call[1];
            }
        }

        return $out;
    }

    private static function assertWpError(string $code, int $status, mixed $result): void
    {
        self::assertInstanceOf(WP_Error::class, $result, 'expected ' . $code . ', got ' . json_encode($result));
        self::assertSame($code, $result->get_error_code(), $result->get_error_message());
        self::assertSame($status, $result->get_error_data()['status']);
    }

    /** @return array<string, array{methods: string, callback: mixed, permission_callback: mixed}> "METHOD route" => args */
    private function registered(callable $permission, callable $write): array
    {
        $GLOBALS['pb_test_routes'] = [];
        $this->routes()->registerRoutes('proto-blocks/v1', $permission, $write);
        $out = [];
        foreach ($GLOBALS['pb_test_routes'] as $entry) {
            foreach ($entry['args'] as $args) {
                $out[$args['methods'] . ' ' . $entry['route']] = $args;
            }
        }

        return $out;
    }

    private function assertNoAbsolutePaths(mixed $data): void
    {
        $json = (string) json_encode($data, JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString($this->root, $json);
    }

    // --- registration --------------------------------------------------------

    public function testRegistersEveryRouteOfTheSpec(): void
    {
        $routes = $this->registered(static fn(): bool => true, static fn(): bool => true);
        $base = '/' . AdminRestController::BASE . '/redirects';
        $expected = [
            'GET ' . $base,
            'GET ' . $base . '/test',
            'GET ' . $base . '/log',
            'DELETE ' . $base . '/log',
            'GET ' . $base . '/log/export',
            'GET ' . $base . '/404',
            'DELETE ' . $base . '/404',
            'GET ' . $base . '/404/export',
            'DELETE ' . $base . '/hits',
            'POST ' . $base . '/hits/reset',
            'POST ' . $base . '/hits/prune',
            'POST ' . $base . '/import/parse',
            'GET ' . $base . '/import/plugins',
            'POST ' . $base . '/import/plugins/(?P<id>[a-z0-9_-]{1,64})/preview',
            'POST ' . $base . '/import/deactivate',
            'GET ' . $base . '/export',
            'POST ' . $base . '/db/install',
        ];
        self::assertSame($expected, array_keys($routes));
        self::assertSame('proto-blocks/v1', $GLOBALS['pb_test_routes'][0]['namespace']);
        self::assertArrayHasKey('rest_pre_serve_request', $GLOBALS['pb_test_filters'] + ['rest_pre_serve_request' => true]);
    }

    public function testEveryRouteNeedsManageOptionsAndNoneNeedsFileWrites(): void
    {
        $denied = static fn(): bool => false;
        $writeCalls = 0;
        $write = static function () use (&$writeCalls): bool {
            $writeCalls++;
            return true;
        };
        foreach ($this->registered($denied, $write) as $key => $args) {
            self::assertFalse((bool) ($args['permission_callback'])(), $key . ' must be refused without manage_options');
        }
        self::assertSame(0, $writeCalls, 'no redirects route writes a theme file');
    }

    public function testDeactivateAlsoNeedsActivatePlugins(): void
    {
        $routes = $this->registered(static fn(): bool => current_user_can('manage_options'), static fn(): bool => true);
        $deactivate = $routes['POST /' . AdminRestController::BASE . '/redirects/import/deactivate']['permission_callback'];
        self::assertFalse($deactivate());
        $GLOBALS['pb_test_caps'][1][] = 'activate_plugins';
        self::assertTrue($deactivate());
    }

    public function testRoutesUseTheRealPermissionOfTheController(): void
    {
        $routes = $this->registered([new AdminRestController([]), 'permission'], [new AdminRestController([]), 'writePermission']);
        $GLOBALS['pb_test_caps'] = [1 => []];
        self::assertFalse(($routes['GET /' . AdminRestController::BASE . '/redirects/log']['permission_callback'])());
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options']];
        self::assertTrue(($routes['DELETE /' . AdminRestController::BASE . '/redirects/log']['permission_callback'])());
    }

    // --- GET admin/redirects ---------------------------------------------------

    public function testIndexListsGroupsWithHitsIssuesAndFileState(): void
    {
        $path = $this->group('legacy', [
            ['id' => 'r_00000001', 'from' => '/old', 'to' => '/new'],
            ['id' => 'r_00000002', 'from' => 'blog/(.*)', 'regex' => true, 'to' => '/news/$1'],
        ], ['title' => 'Legacy site', 'position' => 5]);
        $this->put('proto-redirects/settings.jsonc', '{"trackHits": true}');
        $this->db->queue = [
            self::TABLES,
            [
                ['group_slug' => 'legacy', 'rule_id' => 'r_00000001', 'hits' => '7', 'last_hit' => '2026-11-01 10:00:00'],
                ['group_slug' => 'legacy', 'rule_id' => 'r_deadbeef', 'hits' => '1', 'last_hit' => null],
            ],
        ];

        $out = $this->routes()->index();

        self::assertTrue($out['moduleEnabled']);
        self::assertFalse($out['paused']);
        self::assertCount(1, $out['groups']);
        $group = $out['groups'][0];
        self::assertSame('legacy', $group['slug']);
        self::assertSame('Legacy site', $group['title']);
        self::assertSame(5, $group['position']);
        self::assertSame('file', $group['origin']);
        self::assertNull($group['double']);
        self::assertTrue($group['writable']);
        self::assertNull($group['readOnlyReason']);
        self::assertSame(hash_file('sha256', $path), $group['hash']);
        self::assertSame(7, $group['rules'][0]['hits']);
        self::assertSame('2026-11-01 10:00:00', $group['rules'][0]['lastHit']);
        self::assertSame(0, $group['rules'][1]['hits']);
        self::assertNull($group['rules'][1]['lastHit']);
        self::assertSame([], $group['rules'][0]['issues']);
        self::assertSame(['unanchored_regex'], array_column($group['rules'][1]['issues'], 'code'));
        self::assertNotContains('unanchored_regex', array_column($out['issues'], 'code'), 'a rule issue is listed with its rule only');
        self::assertSame(['tables' => true, 'orphans' => 1], $out['db']);
        self::assertTrue($out['settingsFile']['exists']);
        self::assertTrue($out['settingsFile']['writable']);
        self::assertSame(hash_file('sha256', $this->root . '/proto-redirects/settings.jsonc'), $out['settingsFile']['hash']);
        self::assertTrue($out['settings']['trackHits']);
        self::assertSame(['ignoreCase' => true, 'ignoreTrailingSlash' => true, 'query' => 'exact'], $out['settings']['defaults']);
        self::assertTrue($out['createTarget']['writable']);
        self::assertStringEndsWith('proto-redirects', $out['createTarget']['path']);
        $this->assertNoAbsolutePaths($out);
    }

    public function testIndexSkipsTheHitsQueryWhenTablesAreMissing(): void
    {
        $this->group('a', [['from' => '/x', 'to' => '/y']]);
        $this->db->queue = [[]];

        $out = $this->routes()->index();

        self::assertSame(['tables' => false, 'orphans' => 0], $out['db']);
        self::assertSame(0, $out['groups'][0]['rules'][0]['hits']);
        foreach ($this->sql() as $sql) {
            self::assertStringNotContainsString('proto_redirect_hits', $sql);
        }
        self::assertFalse($out['settingsFile']['exists']);
        self::assertNull($out['settingsFile']['hash']);
    }

    public function testIndexMarksReadOnlyHostsAndPhpGroups(): void
    {
        $this->group('a', [['from' => '/x', 'to' => '/y']]);
        $this->db->queue = [[]];
        $out = $this->routes(null, false)->index();
        self::assertFalse($out['groups'][0]['writable']);
        self::assertSame('file_mods', $out['groups'][0]['readOnlyReason']);
        self::assertNotNull($out['groups'][0]['hash'], 'the hash is still reported');
        self::assertFalse($out['createTarget']['writable']);

        $registry = Registry::fromArrays([], [['slug' => 'php', 'title' => 'Declared in PHP', 'source' => '/srv/theme/functions.php:12', 'rules' => [['from' => '/p', 'to' => '/q']]]], []);
        $this->db->queue = [[]];
        $out = $this->routes($registry)->index();
        self::assertSame('php', $out['groups'][0]['origin']);
        self::assertFalse($out['groups'][0]['writable']);
        self::assertSame('php', $out['groups'][0]['readOnlyReason']);
        self::assertNull($out['groups'][0]['hash']);
    }

    public function testIndexReportsAGroupInAnotherRootAsReadOnly(): void
    {
        $other = (string) realpath(sys_get_temp_dir()) . '/pb-redirect-parent-' . bin2hex(random_bytes(4));
        mkdir($other . '/proto-redirects/parent', 0777, true);
        file_put_contents($other . '/proto-redirects/parent/redirects.jsonc', '{"rules": [{"from": "/a", "to": "/b"}]}');
        try {
            $registry = new Registry(fn(): GroupFiles => new GroupFiles(fn(): array => [$other, $this->root]));
            $this->db->queue = [[]];
            $out = $this->routes($registry)->index();
            self::assertFalse($out['groups'][0]['writable']);
            self::assertSame('read_only', $out['groups'][0]['readOnlyReason']);
        } finally {
            self::removeTree($other);
        }
    }

    public function testIndexWithoutTheModuleSaysSo(): void
    {
        $routes = new RedirectsRoutes(null, null, null, null, null, null, new SchemaFileWriter([$this->root], true));
        $out = $routes->index();
        self::assertFalse($out['moduleEnabled']);
        self::assertSame([], $out['groups']);
        self::assertSame([], $this->db->calls, 'nothing is queried for a disabled module');
    }

    public function testIndexMarksADoubleDeclaredGroup(): void
    {
        $registry = Registry::fromArrays(
            [['slug' => 'shop', 'source' => $this->root . '/proto-redirects/shop/redirects.jsonc', 'rules' => [['from' => '/a', 'to' => '/b']]]],
            [['slug' => 'shop', 'source' => $this->root . '/functions.php:9', 'rules' => [['from' => '/c', 'to' => '/d']]]],
            []
        );
        $this->db->queue = [[]];
        $out = $this->routes($registry)->index();
        self::assertIsArray($out['groups'][0]['double']);
        self::assertContains('double_declaration', array_column($out['issues'], 'code'));
        $this->assertNoAbsolutePaths($out);
    }

    // --- GET admin/redirects/test ---------------------------------------------

    public function testTestReportsTheFiringRuleAndTrace(): void
    {
        $registry = Registry::fromArrays([[
            'slug' => 'main',
            'rules' => [
                ['id' => 'r_00000001', 'from' => '/off', 'to' => '/x', 'enabled' => false],
                ['id' => 'r_00000002', 'from' => '/other', 'to' => '/y'],
                ['id' => 'r_00000003', 'from' => '/old', 'to' => '/new', 'code' => 302],
            ],
        ]], [], []);

        $out = $this->routes($registry)->test(new WP_REST_Request(['url' => '/old']));

        self::assertTrue($out['matched']);
        self::assertSame('init', $out['stage']);
        self::assertSame('main', $out['group']);
        self::assertSame('r_00000003', $out['ruleId']);
        self::assertSame('url', $out['action']);
        self::assertSame(302, $out['code']);
        self::assertStringEndsWith('/new', (string) $out['target']);
        self::assertSame(
            [['r_00000001', 'skipped', 'disabled'], ['r_00000002', 'skipped', 'no_match'], ['r_00000003', 'matched', 'matched']],
            array_map(static fn(array $s): array => [$s['ruleId'], $s['result'], $s['reason']], $out['trace'])
        );
        self::assertSame(['group', 'ruleId', 'result', 'reason', 'stage'], array_keys($out['trace'][0]));
        self::assertStringContainsString('without redirecting', $out['note']);
        self::assertTrue($out['loggedIn']);
    }

    public function testTestFallsBackToThe404Stage(): void
    {
        $registry = Registry::fromArrays([['slug' => 'main', 'rules' => [
            ['id' => 'r_00000001', 'from' => '/a', 'to' => '/b'],
            ['id' => 'r_00000002', 'when' => ['type' => '404'], 'to' => '/'],
        ]]], [], []);
        $out = $this->routes($registry)->test(new WP_REST_Request(['url' => 'https://example.test/missing']));
        self::assertTrue($out['matched']);
        self::assertSame('404', $out['stage']);
        self::assertSame('r_00000002', $out['ruleId']);
        self::assertSame(['init', '404'], array_values(array_unique(array_column($out['trace'], 'stage'))));
        self::assertNotContains('404_stage_only', array_column($out['trace'], 'reason'));
        self::assertNotContains('not_404_stage', array_column($out['trace'], 'reason'));
        self::assertFalse(is_404(), 'the 404 flag is only set while evaluating');
    }

    public function testAs404FlagsTheMainQueryAndRestoresIt(): void
    {
        $GLOBALS['wp_query'] = (object) ['is_404' => false];
        try {
            $seen = RedirectsRoutes::as404(static fn(): bool => $GLOBALS['wp_query']->is_404);
            self::assertTrue($seen);
            self::assertFalse($GLOBALS['wp_query']->is_404);
            unset($GLOBALS['wp_query']);
            self::assertSame('ran', RedirectsRoutes::as404(static fn(): string => 'ran'), 'no main query: runs as is');
        } finally {
            unset($GLOBALS['wp_query']);
        }
    }

    public function testTestNoMatch(): void
    {
        $out = $this->routes(Registry::fromArrays([], [], []))->test(new WP_REST_Request(['url' => '/nothing']));
        self::assertFalse($out['matched']);
        self::assertNull($out['ruleId']);
        self::assertSame([], $out['trace']);
    }

    public function testTestEvaluatesLoginAgainstTheCallerOrAsAVisitor(): void
    {
        $registry = Registry::fromArrays([['slug' => 'main', 'rules' => [
            ['id' => 'r_00000001', 'from' => '/members', 'when' => ['type' => 'login'], 'to' => '/dashboard', 'else' => '/login'],
        ]]], [], []);
        $routes = $this->routes($registry);

        $in = $routes->test(new WP_REST_Request(['url' => '/members']));
        self::assertStringEndsWith('/dashboard', (string) $in['target']);

        $out = $routes->test(new WP_REST_Request(['url' => '/members', 'loggedIn' => '0']));
        self::assertStringEndsWith('/login', (string) $out['target']);
        self::assertSame('else', $out['reason']);
        self::assertFalse($out['loggedIn']);
        self::assertSame([0, 1], $GLOBALS['pb_test_user_switches']);
        self::assertSame(1, get_current_user_id(), 'the caller is restored');
    }

    /** @return list<array{0: mixed}> */
    public static function badUrls(): array
    {
        return [[null], [''], ['old'], ['//evil.test/x'], ['javascript:alert(1)'], ['/' . str_repeat('a', 2001)], [['/x']]];
    }

    /** @dataProvider badUrls */
    public function testTestRefusesBadUrls(mixed $url): void
    {
        self::assertWpError('proto_blocks_invalid_url', 400, $this->routes(Registry::fromArrays([], [], []))->test(new WP_REST_Request(['url' => $url])));
    }

    // --- logs ----------------------------------------------------------------

    public function testLogPagesWithFilters(): void
    {
        $this->db->queue = [3, [['id' => '9', 'created' => '2026-11-05 10:00:00', 'url' => '/old', 'target' => '/new', 'code' => '301', 'group_slug' => 'main', 'rule_id' => 'r_00000001', 'redirect_by' => 'proto-blocks', 'method' => 'GET']]];

        $out = $this->routes()->log(new WP_REST_Request([
            'page' => '2', 'per_page' => '10', 'search' => 'old', 'code' => '301', 'rule' => 'main/r_00000001', 'from' => '2026-11-01', 'to' => '2026-11-05',
        ]));

        self::assertSame(3, $out['total']);
        self::assertSame(2, $out['page']);
        self::assertSame(10, $out['perPage']);
        self::assertSame(1, $out['pages']);
        self::assertSame('main', $out['rows'][0]['group']);
        self::assertSame(301, $out['rows'][0]['code']);
        $select = $this->sql()[1];
        self::assertStringContainsString('code = 301', $select);
        self::assertStringContainsString("group_slug = 'main'", $select);
        self::assertStringContainsString("rule_id = 'r_00000001'", $select);
        self::assertStringContainsString("LIKE '%old%'", $select);
        self::assertStringContainsString("created >= '2026-11-01 00:00:00'", $select);
        self::assertStringContainsString("created <= '2026-11-05 23:59:59'", $select);
        self::assertStringContainsString('LIMIT 10 OFFSET 10', $select);
    }

    /** @return list<array{0: mixed, 1: mixed, 2: int, 3: int}> */
    public static function pagingBounds(): array
    {
        return [
            [null, null, 1, 50],
            ['0', '1', 1, 5],
            ['-3', '4', 1, 5],
            ['1', '5', 1, 5],
            ['1', '200', 1, 200],
            ['3', '999', 3, 200],
            ['x', 'y', 1, 50],
        ];
    }

    /** @dataProvider pagingBounds */
    public function testPagingIsClamped(mixed $page, mixed $perPage, int $expectedPage, int $expectedPer): void
    {
        $this->db->queue = [0, []];
        $out = $this->routes()->log(new WP_REST_Request(array_filter(['page' => $page, 'per_page' => $perPage], static fn($v): bool => $v !== null)));
        self::assertSame($expectedPage, $out['page']);
        self::assertSame($expectedPer, $out['perPage']);
        self::assertStringContainsString('LIMIT ' . $expectedPer . ' OFFSET ' . (($expectedPage - 1) * $expectedPer), $this->sql()[1]);
    }

    public function testLogIgnoresMalformedFilters(): void
    {
        $this->db->queue = [0, []];
        $this->routes()->log(new WP_REST_Request(['code' => 'abc', 'rule' => "x' OR 1=1", 'group' => 'Bad Slug', 'from' => 'yesterday']));
        self::assertStringNotContainsString('WHERE', $this->sql()[0]);
    }

    public function testNotFoundListsRowsOrGroupsByUrl(): void
    {
        $this->db->queue = [1, [['id' => '4', 'created' => '2026-11-05 10:00:00', 'url' => '/missing', 'method' => 'GET']]];
        $rows = $this->routes()->notFound(new WP_REST_Request(['url' => '/missing']));
        self::assertSame('/missing', $rows['rows'][0]['url']);
        self::assertNull($rows['groupBy']);
        self::assertStringContainsString("url = '/missing'", $this->sql()[1]);

        $this->db->calls = [];
        $this->db->queue = [2, [['url' => '/a', 'count' => '5', 'last_seen' => '2026-11-05 10:00:00', 'last_referrer' => 'https://ref.test/']]];
        $grouped = $this->routes()->notFound(new WP_REST_Request(['group_by' => 'url', 'search' => 'a']));
        self::assertSame('url', $grouped['groupBy']);
        self::assertSame([['url' => '/a', 'count' => 5, 'lastSeen' => '2026-11-05 10:00:00', 'lastReferrer' => 'https://ref.test/']], $grouped['rows']);
        self::assertSame(2, $grouped['total']);
        self::assertStringContainsString('COUNT(DISTINCT l.url)', $this->sql()[0]);
        self::assertStringContainsString('GROUP BY l.url', $this->sql()[1]);
    }

    public function testDeleteByIdsAllWithFiltersOrNothing(): void
    {
        $this->db->queue = [2];
        self::assertSame(['deleted' => 2], $this->routes()->deleteLog(new WP_REST_Request(['ids' => ['3', '4', 'x']])));
        self::assertStringContainsString('WHERE id IN (3, 4)', $this->sql()[0]);

        $this->db->calls = [];
        $this->db->queue = [5];
        self::assertSame(['deleted' => 5], $this->routes()->deleteNotFound(new WP_REST_Request(['all' => '1', 'url' => '/gone'])));
        self::assertStringContainsString("DELETE FROM wp_proto_404_log WHERE url = '/gone'", $this->sql()[0]);

        $this->db->calls = [];
        $this->db->queue = [9];
        self::assertSame(['deleted' => 9], $this->routes()->deleteLog(new WP_REST_Request(['all' => 'true'])));
        self::assertSame('DELETE FROM wp_proto_redirect_log', $this->sql()[0]);

        $this->db->calls = [];
        $this->db->queue = [4];
        self::assertSame(['deleted' => 4], $this->routes()->deleteNotFound(new WP_REST_Request(['url' => '/exact'])));
        self::assertSame("DELETE FROM wp_proto_404_log WHERE url = '/exact'", $this->sql()[0]);

        self::assertWpError('proto_blocks_nothing_to_delete', 400, $this->routes()->deleteLog(new WP_REST_Request([])));
        self::assertWpError('proto_blocks_nothing_to_delete', 400, $this->routes()->deleteLog(new WP_REST_Request(['url' => '/x'])));
        self::assertWpError('proto_blocks_nothing_to_delete', 400, $this->routes()->deleteNotFound(new WP_REST_Request(['all' => '0', 'ids' => []])));
    }

    public function testLogExportIsAFormulaEscapedCsvDownload(): void
    {
        $this->db->queue = [[
            ['id' => '2', 'created' => '2026-11-05 10:00:00', 'url' => '=HYPERLINK("http://evil")', 'target' => '+1', 'code' => '301', 'group_slug' => 'main', 'rule_id' => 'r_00000001', 'redirect_by' => 'proto-blocks', 'method' => 'GET', 'agent' => '@agent', 'referrer' => '－1'],
            ['id' => '1', 'created' => '2026-11-04 10:00:00', 'url' => '/plain', 'target' => '-x', 'code' => '302', 'redirect_by' => 'proto-blocks', 'method' => 'GET'],
        ]];

        $response = $this->routes()->exportLog(new WP_REST_Request(['format' => 'csv']));

        self::assertInstanceOf(WP_REST_Response::class, $response);
        $headers = $response->get_headers();
        self::assertSame('text/csv; charset=utf-8', $headers['Content-Type']);
        self::assertMatchesRegularExpression('/^attachment; filename="proto-redirect-log-\d{4}-\d{2}-\d{2}\.csv"$/', $headers['Content-Disposition']);
        self::assertSame('1', $headers[RedirectsRoutes::DOWNLOAD_HEADER]);
        $lines = explode("\n", trim((string) $response->get_data()));
        self::assertSame('created,url,target,code,group,ruleId,redirectBy,method,ip,agent,referrer', $lines[0]);
        self::assertStringContainsString('"[FORMULA] =HYPERLINK(""http://evil"")"', $lines[1]);
        self::assertStringContainsString('"[FORMULA] +1"', $lines[1]);
        self::assertStringContainsString('"[FORMULA] @agent"', $lines[1]);
        self::assertStringContainsString('"[FORMULA] －1"', $lines[1]);
        self::assertStringContainsString('"[FORMULA] -x"', $lines[2]);
        self::assertStringContainsString(',302,', $lines[2], 'numbers are not escaped');
    }

    public function testNotFoundExportAndFormatCheck(): void
    {
        $this->db->queue = [[['id' => '1', 'created' => '2026-11-05 10:00:00', 'url' => '/m', 'method' => 'GET']]];
        $response = $this->routes()->exportNotFound(new WP_REST_Request([]));
        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame("created,url,method,ip,agent,referrer\n\"2026-11-05 10:00:00\",/m,GET,,,\n", $response->get_data());
        self::assertStringContainsString('proto-404-log-', $response->get_headers()['Content-Disposition']);

        self::assertWpError('proto_blocks_invalid_format', 400, $this->routes()->exportLog(new WP_REST_Request(['format' => 'xlsx'])));
    }

    /** @return list<array{0: mixed, 1: string}> */
    public static function cells(): array
    {
        return [
            ['=1+1', '[FORMULA] =1+1'], ['+x', '[FORMULA] +x'], ['-x', '[FORMULA] -x'], ['@x', '[FORMULA] @x'],
            ['＝x', '[FORMULA] ＝x'], ['＋x', '[FORMULA] ＋x'], ['＠x', '[FORMULA] ＠x'], ["\tx", "[FORMULA] \tx"], ["\rx", "[FORMULA] \rx"],
            ['/a=b', '/a=b'], [301, '301'], [-1, '-1'], [null, ''], [true, '1'], [['a' => 1], '{"a":1}'],
        ];
    }

    /** @dataProvider cells */
    public function testCsvCellEscaping(mixed $value, string $expected): void
    {
        self::assertSame($expected, RedirectsRoutes::csvCell($value));
    }

    public function testServeDownloadPrintsRawBodiesOnly(): void
    {
        $download = new WP_REST_Response("a,b\n", 200);
        $download->header(RedirectsRoutes::DOWNLOAD_HEADER, '1');
        ob_start();
        $served = RedirectsRoutes::serveDownload(false, $download);
        self::assertSame("a,b\n", ob_get_clean());
        self::assertTrue($served);

        ob_start();
        self::assertFalse(RedirectsRoutes::serveDownload(false, new WP_REST_Response(['x' => 1])));
        self::assertFalse(RedirectsRoutes::serveDownload(false, new WP_REST_Response("text")));
        self::assertTrue(RedirectsRoutes::serveDownload(true, $download), 'an earlier filter that served wins');
        self::assertSame('', ob_get_clean());
    }

    // --- hits ------------------------------------------------------------------

    public function testResetHits(): void
    {
        self::assertSame(['reset' => true], $this->routes()->resetHits(new WP_REST_Request([], ['group' => 'main', 'ruleId' => 'r_00000001'])));
        self::assertStringContainsString("WHERE group_slug = 'main' AND rule_id = 'r_00000001'", $this->sql()[0]);
        foreach ([['group' => 'main'], ['group' => 'Main', 'ruleId' => 'r_00000001'], ['group' => 'main', 'ruleId' => 'r_1'], ['group' => ['x'], 'ruleId' => 'r_00000001']] as $body) {
            self::assertWpError('proto_blocks_invalid_rule', 400, $this->routes()->resetHits(new WP_REST_Request([], $body)));
        }
    }

    public function testDeleteHitsRemovesTheRow(): void
    {
        self::assertSame(['deleted' => true], $this->routes()->deleteHits(new WP_REST_Request(['group' => 'main', 'ruleId' => 'r_0000000a'])));
        self::assertStringContainsString("DELETE FROM wp_proto_redirect_hits WHERE group_slug = 'main' AND rule_id = 'r_0000000a'", $this->sql()[0]);
        self::assertWpError('proto_blocks_invalid_rule', 400, $this->routes()->deleteHits(new WP_REST_Request(['group' => 'main'])));
    }

    public function testListHashMatchesTheSchemaFilesHash(): void
    {
        $path = $this->group('main', [['from' => '/a', 'to' => '/b']]);
        $this->db->queue = [[]];
        $out = $this->routes()->index();
        self::assertSame(SchemaFileWriter::hash((string) file_get_contents($path)), $out['groups'][0]['hash']);
    }

    public function testPruneKeepsEveryKnownRuleDisabledOnesToo(): void
    {
        $this->group('main', [['id' => 'r_00000001', 'from' => '/a', 'to' => '/b'], ['id' => 'r_00000002', 'from' => '/c', 'to' => '/d', 'enabled' => false]]);
        $this->group('off', [['id' => 'r_00000003', 'from' => '/e', 'to' => '/f']], ['enabled' => false]);
        $this->db->queue = [
            [
                ['group_slug' => 'main', 'rule_id' => 'r_00000001'],
                ['group_slug' => 'main', 'rule_id' => 'r_00000002'],
                ['group_slug' => 'off', 'rule_id' => 'r_00000003'],
                ['group_slug' => 'gone', 'rule_id' => 'r_00000009'],
            ],
            1,
        ];
        self::assertSame(['deleted' => 1], $this->routes()->pruneHits());
        self::assertStringContainsString("IN (('gone', 'r_00000009'))", $this->sql()[1]);
        self::assertSame(['main/r_00000001', 'main/r_00000002', 'off/r_00000003'], RedirectsRoutes::knownRules($this->fileRegistry()));
    }

    // --- import ----------------------------------------------------------------

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function formats(): array
    {
        return [
            ['csv', "source,target\n/old-csv,/new-csv\n", '/old-csv'],
            ['json', '{"groups": {"g": {"rules": [{"from": "/old-json", "to": "/new-json"}]}}}', '/old-json'],
            ['apache', "Redirect 301 /old-apache /new-apache\n", '/old-apache'],
            ['redirects', "/old-netlify /new-netlify 301\n", '/old-netlify'],
        ];
    }

    /** @dataProvider formats */
    public function testParseEachFormatFromJson(string $format, string $text, string $from): void
    {
        $out = $this->routes(Registry::fromArrays([], [], []))->parseImport(new WP_REST_Request([], ['format' => $format, 'text' => $text]));
        self::assertIsArray($out, json_encode($out));
        self::assertSame($format, $out['format']);
        self::assertSame($from, $out['rules'][0]['rule']['from']);
        self::assertSame('new', $out['rules'][0]['status']);
        self::assertSame(['rule', 'status', 'reason', 'line', 'message'], array_keys($out['rules'][0]));
    }

    public function testParseDetectsTheFormatFromTheFilename(): void
    {
        $out = $this->routes(Registry::fromArrays([], [], []))->parseImport(new WP_REST_Request([], ['text' => "/a /b 302\n", 'filename' => '_redirects']));
        self::assertSame('redirects', $out['format']);
    }

    public function testParseRedirectionJsonReturnsGroups(): void
    {
        $text = (string) file_get_contents(__DIR__ . '/../Modules/Redirects/Import/fixtures/redirection-export.json');
        $out = $this->routes(Registry::fromArrays([], [], []))->parseImport(new WP_REST_Request([], ['format' => 'json', 'text' => $text]));
        self::assertArrayHasKey('groups', $out);
        self::assertNotSame([], $out['groups']);
    }

    public function testParseMarksDuplicatesAndInvalidRows(): void
    {
        $registry = Registry::fromArrays([
            ['slug' => 'main', 'rules' => [['from' => '/exists', 'to' => '/x']]],
            ['slug' => 'other', 'rules' => [['from' => '/elsewhere', 'to' => '/x']]],
        ], [], []);
        $text = "source,target\n/Exists/,/y\n/fresh,/z\n/fresh,/w\n/elsewhere,/q\n";

        $all = $this->routes($registry)->parseImport(new WP_REST_Request([], ['format' => 'csv', 'text' => $text]));
        self::assertSame(['duplicate', 'new', 'duplicate', 'duplicate'], array_column($all['rules'], 'status'));
        self::assertSame('duplicate', $all['rules'][0]['reason']);
        self::assertNotNull($all['rules'][0]['message']);

        $main = $this->routes($registry)->parseImport(new WP_REST_Request([], ['format' => 'csv', 'text' => $text, 'group' => 'main']));
        self::assertSame(['duplicate', 'new', 'duplicate', 'new'], array_column($main['rules'], 'status'), 'only the target group counts');
    }

    public function testParseRechecksRowsWithTheFileValidator(): void
    {
        $text = '{"groups": {"g": {"rules": [{"from": "/a", "to": "javascript:alert(1)"}, {"from": "^/b", "regex": true, "to": "/c", "headers": {"Set-Cookie": "x"}}]}}}';
        $out = $this->routes(Registry::fromArrays([], [], []))->parseImport(new WP_REST_Request([], ['format' => 'json', 'text' => $text]));
        self::assertSame(['invalid', 'invalid'], array_column($out['rules'], 'status'));
        self::assertNotNull($out['rules'][0]['reason']);
        self::assertNotNull($out['rules'][1]['message']);
    }

    public function testParseErrors(): void
    {
        $routes = $this->routes(Registry::fromArrays([], [], []));
        self::assertWpError('proto_blocks_import_empty', 400, $routes->parseImport(new WP_REST_Request([], [])));
        self::assertWpError('proto_blocks_unknown_format', 400, $routes->parseImport(new WP_REST_Request([], ['format' => 'xml', 'text' => '<x/>'])));
        self::assertWpError('proto_blocks_unknown_format', 400, $routes->parseImport(new WP_REST_Request([], ['text' => 'just words', 'filename' => 'notes.doc'])));
        self::assertWpError('proto_blocks_import_too_large', 413, $routes->parseImport(new WP_REST_Request([], ['format' => 'csv', 'text' => str_repeat('a', RedirectsRoutes::MAX_IMPORT_BYTES + 1)])));
    }

    public function testParseAcceptsAMultipartUpload(): void
    {
        $seen = [];
        $routes = $this->routes(Registry::fromArrays([], [], []), true, null, ['upload' => static function (array $file) use (&$seen): string {
            $seen[] = $file['tmp_name'];
            return "/from-upload /to 301\n";
        }]);
        $out = $routes->parseImport(new UploadRequest(['file' => ['name' => '../../_redirects', 'tmp_name' => '/tmp/php123', 'error' => 0, 'size' => 20]]));
        self::assertSame(['/tmp/php123'], $seen);
        self::assertSame('redirects', $out['format']);
        self::assertSame('/from-upload', $out['rules'][0]['rule']['from']);
    }

    public function testTheDefaultUploadReaderRefusesFilesThatWereNotUploaded(): void
    {
        $fake = $this->put('fake.csv', "/a,/b\n");
        $routes = $this->routes(Registry::fromArrays([], [], []));
        self::assertWpError('proto_blocks_upload_failed', 400, $routes->parseImport(new UploadRequest(['file' => ['name' => 'a.csv', 'tmp_name' => $fake, 'error' => 0]])));
        self::assertWpError('proto_blocks_import_too_large', 413, $routes->parseImport(new UploadRequest(['file' => ['name' => 'a.csv', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE]])));
        self::assertWpError('proto_blocks_upload_failed', 400, $routes->parseImport(new UploadRequest(['file' => ['name' => 'a.csv', 'tmp_name' => '', 'error' => UPLOAD_ERR_PARTIAL]])));
    }

    // --- plugin importers --------------------------------------------------------

    private function importers(): \Closure
    {
        $list = [
            new FakeImporter('fake', true, ['fake-group' => ['title' => 'Fake', 'rules' => [['from' => '/a', 'to' => '/b']]]], [['rule' => [], 'status' => 'invalid', 'reason' => 'invalid_regex', 'line' => 3]]),
            new FakeImporter('absent', false),
            new FakeImporter('multi', true, [], [], ['multi/one.php', 'multi/two.php']),
        ];

        return static fn(): array => $list;
    }

    public function testPluginsListsOnlyAvailableImporters(): void
    {
        $GLOBALS['pb_test_active_plugins'] = ['fake/fake.php'];
        $out = $this->routes(null, true, $this->importers())->importPlugins()['importers'];
        self::assertSame('fake/fake.php', $out[0]['plugin']);
        self::assertSame(['fake', 'multi'], array_column($out, 'id'));
        self::assertSame(['fake/fake.php'], $out[0]['active']);
        self::assertSame('Deactivate fake.', $out[0]['cleanupHint']);
        self::assertFalse($out[0]['canDeactivate']);
        self::assertSame(['multi/one.php', 'multi/two.php'], $out[1]['plugins']);
    }

    public function testPreviewReturnsGroupsAndSkippedRows(): void
    {
        $routes = $this->routes(null, true, $this->importers());
        $request = new WP_REST_Request(['id' => 'fake']);
        $out = $routes->previewPlugin($request);
        self::assertSame(['fake-group'], array_keys($out['groups']));
        self::assertSame('invalid_regex', $out['skipped'][0]['reason']);
        self::assertSame('The regular expression does not compile.', $out['skipped'][0]['message']);

        self::assertWpError('proto_blocks_importer_not_found', 404, $routes->previewPlugin(new WP_REST_Request(['id' => 'absent'])));
        self::assertWpError('proto_blocks_importer_not_found', 404, $routes->previewPlugin(new WP_REST_Request(['id' => 'nope'])));
        self::assertWpError('proto_blocks_importer_not_found', 404, $routes->previewPlugin(new WP_REST_Request([])));
    }

    public function testDeactivateOnlyTouchesTheImportersActivePlugins(): void
    {
        $GLOBALS['pb_test_caps'][1][] = 'activate_plugins';
        $GLOBALS['pb_test_active_plugins'] = ['multi/one.php', 'akismet/akismet.php'];
        $routes = $this->routes(null, true, $this->importers());

        $out = $routes->deactivatePlugin(new WP_REST_Request([], ['id' => 'multi']));
        self::assertSame(['multi/one.php'], $out['deactivated']);
        self::assertSame([['plugin' => 'multi/two.php', 'reason' => 'inactive']], $out['skipped']);
        self::assertSame(['multi/one.php'], $GLOBALS['pb_test_deactivated']);

        self::assertWpError('proto_blocks_invalid_plugin', 400, $routes->deactivatePlugin(new WP_REST_Request([], ['id' => 'multi', 'plugin' => 'akismet/akismet.php'])));
        self::assertWpError('proto_blocks_importer_not_found', 404, $routes->deactivatePlugin(new WP_REST_Request([], ['id' => 'nope'])));
        self::assertContains('akismet/akismet.php', $GLOBALS['pb_test_active_plugins']);
    }

    public function testDeactivateRefusesWithoutCapabilityAndSkipsNetworkPlugins(): void
    {
        $routes = $this->routes(null, true, $this->importers());
        self::assertWpError('proto_blocks_forbidden', 403, $routes->deactivatePlugin(new WP_REST_Request([], ['id' => 'fake'])));

        $GLOBALS['pb_test_caps'][1][] = 'activate_plugins';
        $GLOBALS['pb_test_multisite'] = true;
        $GLOBALS['pb_test_active_plugins'] = ['fake/fake.php'];
        $GLOBALS['pb_test_network_plugins'] = ['fake/fake.php'];
        $out = $routes->deactivatePlugin(new WP_REST_Request([], ['id' => 'fake', 'plugin' => 'fake/fake.php']));
        self::assertSame([], $out['deactivated']);
        self::assertSame('network', $out['skipped'][0]['reason']);
        self::assertSame([], $GLOBALS['pb_test_deactivated']);
    }

    public function testKnownImportersMapToTheirPluginBasenames(): void
    {
        self::assertSame(['redirection/redirection.php'], RedirectsRoutes::pluginsOf(new RedirectionImporter()));
        self::assertSame([], RedirectsRoutes::PLUGINS['old-slugs'] ?? []);
    }

    // --- export ----------------------------------------------------------------

    public function testExportCsvOfOneGroup(): void
    {
        $this->group('main', [['id' => 'r_00000001', 'from' => '/a', 'to' => '/b']]);
        $this->group('other', [['id' => 'r_00000002', 'from' => '/c', 'to' => '/d']]);
        $this->db->queue = [self::TABLES, [['group_slug' => 'main', 'rule_id' => 'r_00000001', 'hits' => '4', 'last_hit' => null]]];

        $response = $this->routes()->export(new WP_REST_Request(['format' => 'csv', 'group' => 'main']));

        self::assertInstanceOf(WP_REST_Response::class, $response);
        $headers = $response->get_headers();
        self::assertStringStartsWith('text/csv', $headers['Content-Type']);
        self::assertMatchesRegularExpression('/filename="proto-redirects-main-\d{4}-\d{2}-\d{2}\.csv"/', $headers['Content-Disposition']);
        self::assertStringContainsString('/a', (string) $response->get_data());
        self::assertStringNotContainsString('/c', (string) $response->get_data());
    }

    public function testExportNetlifyIsNamedRedirectsAndJsonCarriesSettings(): void
    {
        $this->group('main', [['from' => '/a', 'to' => '/b']]);
        $this->put('proto-redirects/settings.jsonc', '{"trackHits": false}');
        $this->db->queue = [[]];
        $netlify = $this->routes()->export(new WP_REST_Request(['format' => 'redirects']));
        self::assertSame('attachment; filename="_redirects"', $netlify->get_headers()['Content-Disposition']);
        self::assertSame('_redirects', RedirectsRoutes::exportFilename(new NetlifyExporter(), 'main'));

        $this->db->queue = [[]];
        $json = $this->routes()->export(new WP_REST_Request(['format' => 'json']));
        $data = json_decode((string) $json->get_data(), true);
        self::assertArrayHasKey('main', $data['groups']);
        self::assertArrayHasKey('settings', $data);

        $this->db->queue = [[]];
        $one = json_decode((string) $this->routes()->export(new WP_REST_Request(['format' => 'json', 'group' => 'main']))->get_data(), true);
        self::assertArrayNotHasKey('settings', $one, 'a single-group export is not a backup');
    }

    public function testExportErrors(): void
    {
        self::assertWpError('proto_blocks_invalid_format', 400, $this->routes()->export(new WP_REST_Request(['format' => 'xml'])));
        self::assertWpError('proto_blocks_invalid_format', 400, $this->routes()->export(new WP_REST_Request(['format' => '../csv'])));
        self::assertWpError('proto_blocks_group_not_found', 404, $this->routes()->export(new WP_REST_Request(['format' => 'csv', 'group' => 'nope'])));
    }

    // --- db ----------------------------------------------------------------------

    public function testInstallCreatesTheTables(): void
    {
        $this->db->queue = [self::TABLES];
        self::assertSame(['tables' => true], $this->routes()->installDb());
        self::assertCount(3, $GLOBALS['pb_test_dbdelta']);
        self::assertSame('1', get_option(Installer::OPTION));

        $this->db->queue = [[]];
        self::assertWpError('proto_blocks_db_install_failed', 500, $this->routes()->installDb());
    }

    private static function removeTree(string $path): void
    {
        if ($path === '' || (!is_dir($path) && !is_link($path))) {
            if (is_file($path)) {
                @unlink($path);
            }
            return;
        }
        if (is_link($path)) {
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
}
