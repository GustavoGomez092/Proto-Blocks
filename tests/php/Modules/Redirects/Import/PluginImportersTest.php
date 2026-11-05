<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Import;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Redirects\Import\OldSlugsImporter;
use ProtoBlocks\Modules\Redirects\Import\PluginImporters;
use ProtoBlocks\Modules\Redirects\Import\RankMathImporter;
use ProtoBlocks\Modules\Redirects\Import\RedirectionImporter;
use ProtoBlocks\Modules\Redirects\Import\SafeRedirectManagerImporter;
use ProtoBlocks\Modules\Redirects\Import\Simple301Importer;
use ProtoBlocks\Modules\Redirects\Import\YoastImporter;

final class PluginImportersTest extends TestCase
{
    private mixed $savedDb;
    private array $savedGlobals = [];

    protected function setUp(): void
    {
        $this->savedDb = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new \wpdb();
        foreach (['pb_test_options', 'pb_test_posts', 'pb_test_permalinks'] as $key) {
            $this->savedGlobals[$key] = $GLOBALS[$key] ?? null;
            $GLOBALS[$key] = [];
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->savedDb;
        foreach ($this->savedGlobals as $key => $value) {
            $GLOBALS[$key] = $value;
        }
    }

    private static function db(): \wpdb
    {
        return $GLOBALS['wpdb'];
    }

    /** The final SQL of the $n-th read call (get_var, get_results …). */
    private static function sql(int $n = 0): string
    {
        $reads = array_values(array_filter(self::db()->calls, static fn(array $c): bool => in_array($c[0], ['get_var', 'get_results'], true)));
        return (string) ($reads[$n][1] ?? '');
    }

    /** Every read query went through prepare() with its values as arguments. */
    private function assertPrepared(int $reads): void
    {
        $prepared = array_values(array_filter(self::db()->calls, static fn(array $c): bool => $c[0] === 'prepare'));
        $readCalls = array_values(array_filter(self::db()->calls, static fn(array $c): bool => in_array($c[0], ['get_var', 'get_results', 'get_row', 'get_col', 'query'], true)));
        $this->assertCount($reads, $readCalls);
        $this->assertCount($reads, $prepared, 'one prepare() per read');
        foreach ($prepared as $call) {
            $this->assertNotSame([], $call[2], 'prepare() got placeholder values: ' . $call[1]);
        }
    }

    // ------------------------------------------------------------------ registry

    public function testRegistry(): void
    {
        $ids = array_map(static fn($i) => $i->id(), PluginImporters::all());
        $this->assertSame(['redirection', 'simple-301-redirects', 'safe-redirect-manager', 'rank-math', 'yoast', 'old-slugs'], $ids);
        $this->assertInstanceOf(YoastImporter::class, PluginImporters::byId('yoast'));
        $this->assertNull(PluginImporters::byId('nope'));
        foreach (PluginImporters::all() as $importer) {
            $this->assertNotSame('', $importer->label());
        }
    }

    public function testNothingIsAvailableOnAnEmptySite(): void
    {
        // Every $wpdb read returns its "nothing" value; options are empty.
        $this->assertSame([], PluginImporters::available());
    }

    public function testAvailableListsOnlySourcesWithData(): void
    {
        $GLOBALS['pb_test_options']['301_redirects'] = ['/a' => '/b'];
        $available = array_map(static fn($i) => $i->id(), PluginImporters::available());
        $this->assertSame(['simple-301-redirects'], $available);
    }

    // ------------------------------------------------------------------ Redirection

    public function testRedirectionAvailability(): void
    {
        $importer = new RedirectionImporter();
        $this->assertFalse($importer->available(), 'table missing');

        self::db()->queue = ['wp_redirection_items', null];
        $this->assertFalse($importer->available(), 'table empty');

        self::db()->calls = [];
        self::db()->queue = ['wp_redirection_items', '1'];
        $this->assertTrue($importer->available());
        $this->assertPrepared(2);
        $this->assertSame("SHOW TABLES LIKE 'wp\\\\_redirection\\\\_items'", self::sql(0));
        $this->assertSame('SELECT id FROM `wp_redirection_items` LIMIT 1', self::sql(1));
    }

    public function testRedirectionPreviewKeepsGroupsFlagsAndConditions(): void
    {
        self::db()->queue = [
            [
                ['id' => 1, 'name' => 'Redirections', 'status' => 'enabled', 'position' => 0],
                ['id' => 2, 'name' => 'Off group', 'status' => 'disabled', 'position' => 1],
                ['id' => 3, 'name' => 'Empty', 'status' => 'enabled', 'position' => 2],
            ],
            [
                ['id' => 1, 'url' => '/old', 'regex' => '0', 'position' => 0, 'group_id' => 1, 'status' => 'enabled', 'action_type' => 'url', 'action_code' => '301', 'action_data' => '/new', 'match_type' => 'url', 'title' => 'T', 'match_data' => '{"source":{"flag_case":false,"flag_query":"pass"}}'],
                ['id' => 2, 'url' => '/members', 'regex' => '0', 'position' => 1, 'group_id' => 1, 'status' => 'disabled', 'action_type' => 'url', 'action_code' => '302', 'action_data' => serialize(['logged_in' => '/in', 'logged_out' => '/out']), 'match_type' => 'login', 'title' => '', 'match_data' => ''],
                ['id' => 3, 'url' => '/x', 'regex' => '0', 'position' => 0, 'group_id' => 2, 'status' => 'enabled', 'action_type' => 'error', 'action_code' => '410', 'action_data' => '', 'match_type' => 'url', 'title' => '', 'match_data' => null],
                ['id' => 4, 'url' => '/r', 'regex' => '0', 'position' => 1, 'group_id' => 2, 'status' => 'enabled', 'action_type' => 'random', 'action_code' => '301', 'action_data' => '', 'match_type' => 'url', 'title' => '', 'match_data' => null],
                ['id' => 5, 'url' => '/obj', 'regex' => '0', 'position' => 2, 'group_id' => 2, 'status' => 'enabled', 'action_type' => 'url', 'action_code' => '301', 'action_data' => 'O:8:"stdClass":1:{s:3:"url";s:2:"/x";}', 'match_type' => 'agent', 'title' => '', 'match_data' => null],
            ],
        ];
        $importer = new RedirectionImporter();
        $groups = $importer->preview();

        $this->assertSame(['redirections', 'off-group'], array_keys($groups), 'empty groups dropped');
        $this->assertSame(['title' => 'Redirections', 'enabled' => true, 'position' => 0], array_diff_key($groups['redirections'], ['rules' => 1]));
        $this->assertFalse($groups['off-group']['enabled']);
        $this->assertSame(
            ['title' => 'T', 'from' => '/old', 'regex' => false, 'to' => '/new', 'code' => 301, 'action' => 'url', 'query' => 'pass', 'ignoreCase' => false],
            $groups['redirections']['rules'][0],
        );
        $this->assertSame(
            ['enabled' => false, 'from' => '/members', 'regex' => false, 'to' => '/in', 'else' => '/out', 'code' => 302, 'action' => 'url', 'when' => ['type' => 'login']],
            $groups['redirections']['rules'][1],
            'serialized action_data from the database is read (class-free)',
        );
        $this->assertSame([['from' => '/x', 'regex' => false, 'code' => 410, 'action' => 'error']], $groups['off-group']['rules']);
        $this->assertSame(['unsupported_action', 'missing_target'], array_column($importer->skipped(), 'reason'), 'random action skipped; a serialized object is never unserialized');
        $this->assertPrepared(2);
        $this->assertStringContainsString('LIMIT 20000', self::sql(1));
        $this->assertNotNull($importer->cleanupHint());
    }

    public function testRedirectionRuleInAMissingGroupLandsInImported(): void
    {
        self::db()->queue = [[], [['id' => 1, 'url' => '/a', 'regex' => 0, 'group_id' => 9, 'status' => 'enabled', 'action_type' => 'url', 'action_code' => 301, 'action_data' => '/b', 'match_type' => 'url']]];
        $this->assertSame(['imported'], array_keys((new RedirectionImporter())->preview()));
    }

    // ------------------------------------------------------------------ Simple 301 Redirects

    public function testSimple301(): void
    {
        $importer = new Simple301Importer();
        $this->assertFalse($importer->available());
        $GLOBALS['pb_test_options']['301_redirects'] = '';
        $this->assertFalse($importer->available(), 'non-array option');

        $GLOBALS['pb_test_options']['301_redirects'] = [
            '/old' => 'https://example.test/new',
            'relative' => '/rel',
            '/blog/*' => '/news/*',
            '' => '/nothing',
            '/js' => 'javascript:alert(1)',
            '/loop' => '/loop',
        ];
        $this->assertTrue($importer->available());
        $groups = $importer->preview();
        $rules = $groups['simple-301-redirects']['rules'];
        $this->assertSame('Simple 301 Redirects', $groups['simple-301-redirects']['title']);
        $this->assertSame(['from' => '/old', 'regex' => false, 'to' => 'https://example.test/new', 'code' => 301, 'action' => 'url'], $rules[0]);
        $this->assertSame('/relative', $rules[1]['from']);
        $this->assertSame(['from' => '^/blog/(.*)$', 'regex' => true, 'to' => '/news/$1', 'code' => 301, 'action' => 'url'], $rules[2], '* → (.*) and $1');
        $this->assertCount(3, $rules);
        $this->assertSame(['empty_source', 'invalid_target', 'self_redirect'], array_column($importer->skipped(), 'reason'));
        $this->assertSame([], self::db()->calls, 'options only');
    }

    // ------------------------------------------------------------------ Safe Redirect Manager

    public function testSafeRedirectManager(): void
    {
        $importer = new SafeRedirectManagerImporter();
        $this->assertFalse($importer->available());
        self::db()->queue = ['12'];
        $this->assertTrue($importer->available());

        self::db()->calls = [];
        $meta = static fn(int $id, string $status, array $m): array => array_map(
            static fn(string $k, string $v): array => ['ID' => $id, 'post_status' => $status, 'menu_order' => 0, 'meta_key' => $k, 'meta_value' => $v],
            array_keys($m),
            $m,
        );
        self::db()->queue = [array_merge(
            $meta(1, 'publish', ['_redirect_rule_from' => '/old/*', '_redirect_rule_to' => '/new/*', '_redirect_rule_status_code' => '301', '_redirect_rule_from_regex' => '0', '_redirect_rule_notes' => 'Moved <b>section</b>']),
            $meta(2, 'draft', ['_redirect_rule_from' => '^/re/(\d+)', '_redirect_rule_to' => '/r/$1', '_redirect_rule_status_code' => '302', '_redirect_rule_from_regex' => '1']),
            $meta(3, 'publish', ['_redirect_rule_from' => '/gone', '_redirect_rule_to' => '', '_redirect_rule_status_code' => '410']),
            $meta(4, 'publish', ['_redirect_rule_from' => '/default', '_redirect_rule_to' => '/d']),
            $meta(5, 'publish', ['_redirect_rule_from' => '/bad', '_redirect_rule_to' => '/b', '_redirect_rule_status_code' => '200']),
        )];
        $groups = $importer->preview();
        $rules = $groups['safe-redirect-manager']['rules'];
        $this->assertSame(['title' => 'Moved bsection/b', 'from' => '^/old/(.*)$', 'regex' => true, 'to' => '/new/$1', 'code' => 301, 'action' => 'url', 'ignoreCase' => true], $rules[0]);
        $this->assertSame(['enabled' => false, 'from' => '^/re/(\d+)', 'regex' => true, 'to' => '/r/$1', 'code' => 302, 'action' => 'url', 'ignoreCase' => true], $rules[1], 'drafts import disabled');
        $this->assertSame(['from' => '/gone', 'regex' => false, 'code' => 410, 'action' => 'error', 'ignoreCase' => true], $rules[2]);
        $this->assertSame(302, $rules[3]['code'], 'SRM default status');
        $this->assertSame(['invalid_code'], array_column($importer->skipped(), 'reason'));
        $this->assertPrepared(1);
        $this->assertStringContainsString("p.post_type = 'redirect_rule'", self::sql(0));
    }

    // ------------------------------------------------------------------ Rank Math

    public function testRankMath(): void
    {
        $importer = new RankMathImporter();
        $this->assertFalse($importer->available(), 'no table');
        self::db()->queue = ['wp_rank_math_redirections', null];
        $this->assertFalse($importer->available(), 'only trashed rows');
        self::db()->queue = ['wp_rank_math_redirections', '3'];
        $this->assertTrue($importer->available());

        self::db()->calls = [];
        self::db()->queue = [[
            ['id' => 1, 'sources' => serialize([
                ['pattern' => 'old-page', 'comparison' => 'exact', 'ignore' => ''],
                ['pattern' => 'shop', 'comparison' => 'start', 'ignore' => 'case'],
            ]), 'url_to' => 'https://example.test/new/', 'header_code' => '301', 'status' => 'active'],
            ['id' => 2, 'sources' => json_encode([
                ['pattern' => '.pdf', 'comparison' => 'end', 'ignore' => ''],
                ['pattern' => 'promo', 'comparison' => 'contains', 'ignore' => ''],
                ['pattern' => '^blog/(\d+)$', 'comparison' => 'regex', 'ignore' => 'case'],
                ['pattern' => 'x', 'comparison' => 'fuzzy'],
            ]), 'url_to' => '/target', 'header_code' => '307', 'status' => 'inactive'],
            ['id' => 3, 'sources' => serialize([['pattern' => 'gone', 'comparison' => 'exact']]), 'url_to' => '', 'header_code' => '410', 'status' => 'active'],
            ['id' => 4, 'sources' => 'garbage', 'url_to' => '/x', 'header_code' => '301', 'status' => 'active'],
        ]];
        $groups = $importer->preview();
        $rules = $groups['rank-math']['rules'];
        $this->assertSame(['from' => '/old-page', 'regex' => false, 'to' => 'https://example.test/new/', 'code' => 301, 'action' => 'url', 'ignoreCase' => false], $rules[0]);
        $this->assertSame(['^/shop', true, true], [$rules[1]['from'], $rules[1]['regex'], $rules[1]['ignoreCase']], 'start → ^/ + quoted');
        $this->assertSame(['\.pdf$', false], [$rules[2]['from'], $rules[2]['enabled']], 'end → quoted + $; inactive → disabled');
        $this->assertSame('promo', $rules[3]['from'], 'contains → unanchored');
        $this->assertSame('^/blog/(\d+)$', $rules[4]['from'], 'regex gets the leading slash');
        $this->assertSame(['from' => '/gone', 'regex' => false, 'code' => 410, 'action' => 'error', 'ignoreCase' => false], $rules[5]);
        $this->assertCount(6, $rules);
        $this->assertSame(['unsupported_condition', 'empty_source'], array_column($importer->skipped(), 'reason'));
        $this->assertPrepared(1);
        $this->assertStringContainsString("status IN ('active', 'inactive')", self::sql(0));
    }

    // ------------------------------------------------------------------ Yoast

    public function testYoast(): void
    {
        $importer = new YoastImporter();
        $this->assertFalse($importer->available());
        $GLOBALS['pb_test_options']['wpseo-premium-redirects-base'] = [
            ['origin' => 'old', 'url' => 'new', 'type' => 301, 'format' => 'plain'],
            ['origin' => '^cat/(.*)$', 'url' => '/category/$1', 'type' => 302, 'format' => 'regex'],
            ['origin' => 'gone', 'url' => '', 'type' => 410, 'format' => 'plain'],
            ['origin' => 'bad', 'url' => '/x', 'type' => 200, 'format' => 'plain'],
            'junk',
        ];
        $this->assertTrue($importer->available());
        $rules = $importer->preview()['yoast-premium']['rules'];
        $this->assertSame(['from' => '/old', 'regex' => false, 'to' => '/new', 'code' => 301, 'action' => 'url'], $rules[0]);
        $this->assertSame(['from' => '^/cat/(.*)$', 'regex' => true, 'to' => '/category/$1', 'code' => 302, 'action' => 'url', 'ignoreCase' => true], $rules[1]);
        $this->assertSame(['from' => '/gone', 'regex' => false, 'code' => 410, 'action' => 'error'], $rules[2]);
        $this->assertSame(['invalid_code', 'not_an_object'], array_column($importer->skipped(), 'reason'));
    }

    // ------------------------------------------------------------------ old slugs

    public function testOldSlugs(): void
    {
        $importer = new OldSlugsImporter();
        $this->assertFalse($importer->available());
        self::db()->queue = ['7'];
        $this->assertTrue($importer->available());

        $GLOBALS['pb_test_posts'] = [
            10 => ['ID' => 10, 'post_name' => 'new-slug', 'post_status' => 'publish', 'post_type' => 'post'],
            11 => ['ID' => 11, 'post_name' => 'plain', 'post_status' => 'publish', 'post_type' => 'post'],
            12 => ['ID' => 12, 'post_name' => 'draft', 'post_status' => 'draft', 'post_type' => 'post'],
            13 => ['ID' => 13, 'post_name' => 'page', 'post_status' => 'publish', 'post_type' => 'page'],
        ];
        $GLOBALS['pb_test_permalinks'] = [
            10 => 'http://example.test/2024/05/new-slug/',
            11 => 'http://example.test/?p=11',
            13 => 'http://example.test/parent/page',
        ];
        self::db()->calls = [];
        self::db()->queue = [[
            ['ID' => 10, 'post_name' => 'new-slug', 'old_slug' => 'old-slug'],
            ['ID' => 10, 'post_name' => 'new-slug', 'old_slug' => 'old-slug'],
            ['ID' => 10, 'post_name' => 'new-slug', 'old_slug' => 'new-slug'],
            ['ID' => 11, 'post_name' => 'plain', 'old_slug' => 'older'],
            ['ID' => 12, 'post_name' => 'draft', 'old_slug' => 'was'],
            ['ID' => 13, 'post_name' => 'page', 'old_slug' => 'old-page'],
            ['ID' => 13, 'post_name' => 'page', 'old_slug' => '../evil'],
        ]];
        $groups = $importer->preview();
        $this->assertSame('Old slugs', $groups['old-slugs']['title']);
        $this->assertSame([
            ['from' => '/2024/05/old-slug/', 'regex' => false, 'to' => '/2024/05/new-slug/', 'code' => 301, 'action' => 'url'],
            ['from' => '/parent/old-page', 'regex' => false, 'to' => '/parent/page', 'code' => 301, 'action' => 'url'],
        ], $groups['old-slugs']['rules'], 'duplicates collapsed');
        $this->assertSame(['self_redirect', 'unsupported_permalink', 'missing_target', 'invalid_source'], array_column($importer->skipped(), 'reason'));
        $this->assertPrepared(1);
        $this->assertStringContainsString("m.meta_key = '_wp_old_slug' AND p.post_status = 'publish'", self::sql(0));
        $this->assertNull($importer->cleanupHint());
    }

    public function testPreviewOnEmptySourcesIsEmpty(): void
    {
        foreach (PluginImporters::all() as $importer) {
            self::db()->queue = [];
            $this->assertSame([], $importer->preview(), $importer->id());
        }
    }
}
