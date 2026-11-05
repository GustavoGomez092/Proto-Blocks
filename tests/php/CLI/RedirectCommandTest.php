<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\CLI;

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/wp-cli-stub.php';
require_once __DIR__ . '/../Modules/Redirects/wp-stubs.php';
require_once __DIR__ . '/../API/stubs/redirects-doubles.php';

use PHPUnit\Framework\TestCase;
use ProtoBlocks\CLI\RedirectCommand;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\Modules\Redirects\RedirectsModule;
use ProtoBlocks\Modules\Redirects\Registry;
use ProtoBlocks\Modules\Redirects\Serializer;
use ProtoBlocks\Modules\Redirects\Sources\GroupFiles;
use ProtoBlocks\Modules\Redirects\Sources\PhpDeclarations;
use ProtoBlocks\Modules\Redirects\Storage\Hits;
use ProtoBlocks\Modules\Redirects\Storage\Installer;
use ProtoBlocks\Modules\Redirects\Storage\NotFoundLog;
use ProtoBlocks\Modules\Redirects\Storage\RedirectLog;
use ProtoBlocks\Tests\API\Stubs\FakeImporter;

/**
 * `wp proto-blocks redirect <subcommand>` over a temporary theme root and a recording $wpdb.
 */
final class RedirectCommandTest extends TestCase
{
    private const TABLES = ['wp_proto_redirect_hits', 'wp_proto_redirect_log', 'wp_proto_404_log'];

    private string $root = '';
    private \wpdb $db;
    private ?Registry $registry = null;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/pb-redirect-cli-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->root = (string) realpath($tmp);
        $GLOBALS['pb_test_home_url'] = 'https://example.test';
        $GLOBALS['pb_test_current_user'] = 0;
        $GLOBALS['pb_test_options'] = [];
        $GLOBALS['pb_test_dbdelta'] = [];
        $GLOBALS['pb_test_filters'] = [];
        $GLOBALS['pb_test_user_switches'] = [];
        $GLOBALS['pb_test_user_list'] = [];
        $this->db = new \wpdb();
        $GLOBALS['wpdb'] = $this->db;
        PhpDeclarations::reset();
        SchemaFileWriter::resetKinds();
        RedirectsModule::registerFileKinds();
        \WP_CLI::reset();
        $this->registry = null;
    }

    protected function tearDown(): void
    {
        PhpDeclarations::reset();
        SchemaFileWriter::resetKinds();
        $GLOBALS['wpdb'] = new \wpdb();
        unset($GLOBALS['pb_test_home_url'], $GLOBALS['pb_test_current_user'], $GLOBALS['pb_test_query']);
        foreach (['pb_test_options', 'pb_test_dbdelta', 'pb_test_filters', 'pb_test_user_switches', 'pb_test_user_list'] as $key) {
            $GLOBALS[$key] = [];
        }
        self::removeTree($this->root);
    }

    // --- fixtures ------------------------------------------------------------

    private function registry(): Registry
    {
        return $this->registry ??= new Registry(fn(): GroupFiles => new GroupFiles(fn(): array => [$this->root]));
    }

    private function command(?Registry $registry = null, array $importers = [], bool $fileMods = true): RedirectCommand
    {
        return new RedirectCommand(
            $registry ?? $this->registry(),
            new SchemaFileWriter([$this->root], $fileMods),
            new Hits(),
            new RedirectLog(),
            new NotFoundLog(),
            new Installer(),
            static fn(): array => $importers,
            static function (\Closure $fn): mixed {
                $GLOBALS['pb_test_query']['is_404'] = true;
                try {
                    return $fn();
                } finally {
                    unset($GLOBALS['pb_test_query']['is_404']);
                }
            }
        );
    }

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
        return $this->put('proto-redirects/' . $slug . '/redirects.jsonc', (string) json_encode($extra + ['rules' => $rules], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    private function groupFile(string $slug): string
    {
        return $this->root . '/proto-redirects/' . $slug . '/redirects.jsonc';
    }

    /** @return array<string, mixed> */
    private function readGroup(string $slug): array
    {
        return Jsonc::decode((string) file_get_contents($this->groupFile($slug)));
    }

    /** Runs $fn and returns the WP_CLI::error message it ended with. */
    private static function cliError(callable $fn): string
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('WP_CLI::error: ', $e->getMessage());
            return substr($e->getMessage(), strlen('WP_CLI::error: '));
        }
        self::fail('expected WP_CLI::error');
    }

    /** @return list<mixed> decoded rows of the last format_items call */
    private static function lastTable(): array
    {
        $formats = \WP_CLI::messages('format');
        $last = (string) end($formats);

        return (array) json_decode(substr($last, (int) strpos($last, ':') + 1), true);
    }

    /** @return list<string> */
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

    // --- list ------------------------------------------------------------------

    public function testListShowsRulesWithHits(): void
    {
        $this->group('main', [['id' => 'r_00000001', 'from' => '/a', 'to' => '/b'], ['id' => 'r_00000002', 'from' => '/c', 'action' => 'error', 'code' => 410, 'when' => ['type' => 'ip', 'value' => ['1.2.3.4']]]]);
        $this->group('other', [['id' => 'r_00000003', 'from' => '/d', 'to' => '/e']]);
        $this->db->queue = [self::TABLES, [['group_slug' => 'main', 'rule_id' => 'r_00000001', 'hits' => '3', 'last_hit' => null]]];

        $this->command()->list([], ['group' => 'main', 'format' => 'json']);

        $rows = self::lastTable();
        self::assertSame(['r_00000001', 'r_00000002'], array_column($rows, 'id'));
        self::assertSame('3', $rows[0]['hits']);
        self::assertSame('ip:1.2.3.4', $rows[1]['when']);
        self::assertSame('410', $rows[1]['code']);
        self::assertStringStartsWith('json:', \WP_CLI::messages('format')[0]);
    }

    // --- add ---------------------------------------------------------------------

    public function testAddCreatesTheGroupFileWhenMissing(): void
    {
        $this->command()->add(['/old', '/new'], ['code' => '302', 'title' => 'Moved']);

        $file = $this->groupFile(RedirectCommand::DEFAULT_GROUP);
        self::assertFileExists($file);
        $text = (string) file_get_contents($file);
        self::assertStringStartsWith(Serializer::GROUP_HEADER[0], $text);
        $data = $this->readGroup(RedirectCommand::DEFAULT_GROUP);
        self::assertSame('/old', $data['rules'][0]['from']);
        self::assertSame(302, $data['rules'][0]['code']);
        self::assertSame('Moved', $data['rules'][0]['title']);
        self::assertMatchesRegularExpression('/^r_[a-f0-9]{8}$/', $data['rules'][0]['id']);
        self::assertSame($data['rules'][0]['id'], $this->registry()->group('redirects')['rules'][0]['id'], 'the id written is the id the loader derives');
        self::assertStringContainsString('Added ' . $data['rules'][0]['id'], \WP_CLI::messages('success')[0]);
    }

    public function testAddAppendsAndKeepsExistingRulesIncludingOnesWithoutIds(): void
    {
        $this->group('main', [['from' => '/first', 'to' => '/x']], ['title' => 'Main', 'position' => 3]);
        $this->command()->add(['^/blog/(\d+)$', '/news/$1'], ['group' => 'main', 'regex' => true, 'when' => 'ip', 'when-value' => '10.0.0.0/8, 192.168.0.1']);

        $data = $this->readGroup('main');
        self::assertSame('Main', $data['title']);
        self::assertSame(3, $data['position']);
        self::assertSame(['/first', '^/blog/(\d+)$'], array_column($data['rules'], 'from'));
        self::assertTrue($data['rules'][1]['regex']);
        self::assertSame(['type' => 'ip', 'value' => ['10.0.0.0/8', '192.168.0.1']], $data['rules'][1]['when']);
    }

    public function testAddA404Rule(): void
    {
        $this->command()->add(['ignored', '/'], ['when' => '404', 'group' => 'fallback']);
        $rule = $this->readGroup('fallback')['rules'][0];
        self::assertArrayNotHasKey('from', $rule);
        self::assertSame(['type' => '404'], $rule['when']);
    }

    public function testAddRefusesInvalidRulesAndWritesNothing(): void
    {
        $message = self::cliError(fn() => $this->command()->add(['/a', 'javascript:alert(1)'], []));
        self::assertStringContainsString('to:', $message);
        self::assertFileDoesNotExist($this->groupFile('redirects'));

        self::cliError(fn() => $this->command()->add(['/a', '/b'], ['code' => '299']));
        self::cliError(fn() => $this->command()->add(['/a', '/b'], ['group' => 'Bad Slug']));
        self::cliError(fn() => $this->command()->add([], []));
        self::assertFileDoesNotExist($this->groupFile('redirects'));
    }

    public function testAddWarnsAboutAnUnanchoredRegex(): void
    {
        $this->command()->add(['blog', '/news'], ['regex' => true]);
        self::assertNotEmpty(\WP_CLI::messages('warning'));
    }

    public function testAddRefusesTheSameRuleTwice(): void
    {
        $this->command()->add(['/a', '/b'], []);
        $this->registry = null;
        self::assertStringContainsString('already has a rule', self::cliError(fn() => $this->command()->add(['/a', '/c'], [])));
    }

    public function testAddRefusesAGroupDeclaredInPhp(): void
    {
        $registry = Registry::fromArrays([], [['slug' => 'legacy', 'source' => '/srv/theme/functions.php:12', 'rules' => [['from' => '/p', 'to' => '/q']]]], []);
        $message = self::cliError(fn() => $this->command($registry)->add(['/a', '/b'], ['group' => 'legacy']));
        self::assertStringContainsString('declared in PHP', $message);
        self::assertFileDoesNotExist($this->groupFile('legacy'));
    }

    public function testAddOnAReadOnlyHostFails(): void
    {
        self::cliError(fn() => $this->command(null, [], false)->add(['/a', '/b'], []));
        self::assertFileDoesNotExist($this->groupFile('redirects'));
    }

    public function testAddRefusesABrokenFile(): void
    {
        $this->put('proto-redirects/main/redirects.jsonc', '{"rules": [ {"from": "/a", ');
        self::assertStringContainsString('not valid JSONC', self::cliError(fn() => $this->command()->add(['/b', '/c'], ['group' => 'main'])));
    }

    // --- delete / enable / disable ------------------------------------------------

    public function testDeleteRemovesTheRuleAndItsHits(): void
    {
        $this->group('main', [['id' => 'r_00000001', 'from' => '/a', 'to' => '/b'], ['id' => 'r_00000002', 'from' => '/c', 'to' => '/d']]);
        $this->db->queue = [self::TABLES];

        $this->command()->delete(['r_00000001'], []);

        self::assertSame(['r_00000002'], array_column($this->readGroup('main')['rules'], 'id'));
        self::assertStringContainsString("DELETE FROM wp_proto_redirect_hits WHERE group_slug = 'main' AND rule_id = 'r_00000001'", implode("\n", $this->sql()));
    }

    public function testDeleteFindsARuleWithoutAnIdByItsDerivedId(): void
    {
        $this->group('main', [['from' => '/a', 'to' => '/b'], ['from' => '/c', 'to' => '/d']]);
        $id = $this->registry()->group('main')['rules'][1]['id'];
        $this->db->queue = [[]];
        $this->command()->delete([$id], ['group' => 'main']);
        self::assertSame(['/a'], array_column($this->readGroup('main')['rules'], 'from'));
    }

    public function testDisableAndEnable(): void
    {
        $this->group('main', [['id' => 'r_00000001', 'from' => '/a', 'to' => '/b']]);
        $this->command()->disable(['r_00000001'], []);
        self::assertFalse($this->readGroup('main')['rules'][0]['enabled']);
        $this->command()->enable(['r_00000001'], []);
        self::assertArrayNotHasKey('enabled', $this->readGroup('main')['rules'][0]);
        self::assertCount(2, \WP_CLI::messages('success'));
    }

    public function testRuleCommandsRefusePhpRulesNamingTheCallSite(): void
    {
        $registry = Registry::fromArrays([], [['slug' => 'php', 'source' => '/srv/theme/functions.php', 'rules' => [['id' => 'r_0000000f', 'from' => '/p', 'to' => '/q', 'source' => '/srv/theme/inc/redirects.php:42']]]], []);
        foreach (['delete', 'enable', 'disable'] as $method) {
            $message = self::cliError(fn() => $this->command($registry)->{$method}(['r_0000000f'], []));
            self::assertStringContainsString('declared in PHP at', $message);
            self::assertStringContainsString('redirects.php:42', $message);
        }
    }

    public function testRuleCommandsRefuseUnknownOrMalformedIds(): void
    {
        $this->group('main', [['id' => 'r_00000001', 'from' => '/a', 'to' => '/b']]);
        self::assertStringContainsString('No rule with id r_00000009', self::cliError(fn() => $this->command()->delete(['r_00000009'], [])));
        self::assertStringContainsString('in the group "other"', self::cliError(fn() => $this->command()->delete(['r_00000001'], ['group' => 'other'])));
        self::assertStringContainsString('Pass a rule id', self::cliError(fn() => $this->command()->enable(['../x'], [])));
    }

    // --- group -------------------------------------------------------------------

    public function testGroupCreateListToggleDelete(): void
    {
        $this->command()->group(['create', 'Legacy Site'], ['position' => '5']);
        $data = $this->readGroup('legacy-site');
        self::assertSame('Legacy Site', $data['title']);
        self::assertSame(5, $data['position']);
        self::assertSame([], $data['rules']);
        self::assertStringContainsString('already exists', self::cliError(fn() => $this->command()->group(['create', 'Legacy Site'], [])));

        $this->registry = null;
        $this->command()->group(['disable', 'legacy-site'], []);
        self::assertFalse($this->readGroup('legacy-site')['enabled']);
        $this->registry = null;
        $this->command()->group(['list'], ['format' => 'json']);
        self::assertSame([['slug' => 'legacy-site', 'title' => 'Legacy Site', 'enabled' => 'no', 'position' => '5', 'rules' => '0', 'origin' => 'file']], self::lastTable());
        $this->command()->group(['enable', 'legacy-site'], []);
        self::assertTrue($this->readGroup('legacy-site')['enabled']);

        self::assertStringContainsString('--yes', self::cliError(fn() => $this->command()->group(['delete', 'legacy-site'], [])));
        self::assertFileExists($this->groupFile('legacy-site'));
        $this->command()->group(['delete', 'legacy-site'], ['yes' => true]);
        self::assertFileDoesNotExist($this->groupFile('legacy-site'));
    }

    public function testGroupErrors(): void
    {
        self::cliError(fn() => $this->command()->group(['create'], []));
        self::cliError(fn() => $this->command()->group(['enable', 'missing'], []));
        self::cliError(fn() => $this->command()->group(['rename', 'x'], []));
        self::cliError(fn() => $this->command()->group(['create', 'Settings'], []));
    }

    // --- validate / test -----------------------------------------------------------

    public function testValidatePrintsRowsAndFailsOnErrors(): void
    {
        $this->group('main', [['from' => 'blog', 'regex' => true, 'to' => '/x']]);
        $this->command()->validate([], ['format' => 'json']);
        $rows = json_decode(\WP_CLI::messages('line')[0], true);
        self::assertSame('unanchored_regex', $rows[0]['code']);
        self::assertSame('proto-redirects/main/redirects.jsonc', $rows[0]['source']);
        self::assertSame(['kind', 'slug', 'level', 'code', 'message', 'source'], array_keys($rows[0]));
        self::assertNotEmpty(\WP_CLI::messages('success'));

        $this->put('proto-redirects/broken/redirects.jsonc', '{ nope');
        $this->registry = null;
        self::cliError(fn() => $this->command()->validate([], []));
        self::assertContains('invalid_jsonc', array_column(RedirectCommand::validationRows($this->registry()), 'code'));
    }

    public function testTestPrintsTheFiringRule(): void
    {
        $this->group('main', [['id' => 'r_00000001', 'from' => '/old', 'to' => '/new']]);
        $this->command()->test(['/old'], []);
        self::assertStringContainsString('r_00000001 (main) fires: url 301', \WP_CLI::messages('success')[0]);
        self::assertSame('matched', self::lastTable()[0]['result']);

        \WP_CLI::reset();
        $this->command()->test(['/nothing'], []);
        self::assertSame(['No rule fires for /nothing.'], \WP_CLI::messages('log'));
        self::cliError(fn() => $this->command()->test(['nope'], []));
    }

    public function testTestLoggedInUsesTheFirstAdministrator(): void
    {
        $this->group('main', [['id' => 'r_00000001', 'from' => '/members', 'when' => ['type' => 'login'], 'to' => '/dashboard', 'else' => '/login']]);
        $this->command()->test(['/members'], ['format' => 'json']);
        self::assertStringEndsWith('/login', json_decode(\WP_CLI::messages('line')[0], true)['target']);

        self::assertStringContainsString('--user', self::cliError(fn() => $this->command()->test(['/members'], ['logged-in' => true])));

        \WP_CLI::reset();
        $GLOBALS['pb_test_user_list'] = [7];
        $this->command()->test(['/members'], ['logged-in' => true, 'format' => 'json']);
        self::assertStringEndsWith('/dashboard', json_decode(\WP_CLI::messages('line')[0], true)['target']);
        self::assertSame([7, 0], $GLOBALS['pb_test_user_switches']);
    }

    public function testTestReportsA404Rule(): void
    {
        $this->group('main', [['id' => 'r_00000001', 'when' => ['type' => '404'], 'to' => '/']]);
        $this->command()->test(['/missing'], []);
        self::assertStringContainsString('when the page is not found', \WP_CLI::messages('success')[0]);
    }

    // --- import --------------------------------------------------------------------

    public function testImportCsvDryRunWritesNothing(): void
    {
        $file = $this->put('in/rules.csv', "source,target\n/a,/b\n/c,/d\n");
        $this->command()->import([$file], ['dry-run' => true]);
        self::assertFileDoesNotExist($this->groupFile('imported'));
        self::assertStringContainsString('Would write imported: 2 added', \WP_CLI::messages('log')[0]);
        self::assertStringStartsWith('Dry run, nothing written: 2 added', \WP_CLI::messages('success')[0]);
    }

    public function testImportCreatesTheGroupAndReportsInvalidRows(): void
    {
        $file = $this->put('in/rules.csv', "source,target,regex,code\n/a,/b,0,301\n/teapot,,0,402\n/c,javascript:x,0,301\n");
        $this->command()->import([$file], ['group' => 'moved']);
        self::assertSame(['/a'], array_column($this->readGroup('moved')['rules'], 'from'));
        self::assertCount(2, \WP_CLI::messages('warning'));
        self::assertStringContainsString('2 invalid', \WP_CLI::messages('success')[0]);
    }

    /** @return list<array{0: string, 1: list<string>, 2: string}> */
    public static function duplicateModes(): array
    {
        return [
            ['skip', ['/b', '/z'], '1 added, 0 replaced, 1 skipped'],
            ['replace', ['/new', '/z'], '1 added, 1 replaced, 0 skipped'],
            ['add', ['/b', '/new', '/z'], '2 added, 0 replaced, 0 skipped'],
        ];
    }

    /** @dataProvider duplicateModes */
    public function testImportDuplicateModes(string $mode, array $targets, string $summary): void
    {
        $this->group('main', [['id' => 'r_00000001', 'from' => '/a', 'to' => '/b']]);
        $file = $this->put('in/rules.csv', "source,target\n/a,/new\n/y,/z\n");
        $this->command()->import([$file], ['group' => 'main', 'duplicates' => $mode]);
        $rules = $this->readGroup('main')['rules'];
        self::assertSame($targets, array_column($rules, 'to'));
        self::assertSame('r_00000001', $rules[0]['id'], 'the existing rule keeps its id');
        self::assertCount(count(array_unique(array_column($rules, 'id'))), $rules, 'ids stay unique');
        self::assertStringContainsString($summary, \WP_CLI::messages('success')[0]);
    }

    public function testImportJsonExportKeepsItsGroups(): void
    {
        $file = $this->put('in/export.json', (string) json_encode(['groups' => [
            'shop' => ['title' => 'Shop', 'position' => 4, 'rules' => [['from' => '/cart-old', 'to' => '/cart']]],
            'blog' => ['rules' => [['from' => '/blog-old', 'to' => '/blog']]],
        ]]));
        $this->command()->import([$file], []);
        $shop = $this->readGroup('shop');
        self::assertSame('Shop', $shop['title']);
        self::assertSame(4, $shop['position']);
        self::assertSame('/cart-old', $shop['rules'][0]['from']);
        self::assertSame('/blog-old', $this->readGroup('blog')['rules'][0]['from']);
    }

    public function testImportErrors(): void
    {
        self::cliError(fn() => $this->command()->import([$this->root . '/missing.csv'], []));
        $file = $this->put('in/notes.doc', 'words');
        self::assertStringContainsString('--format', self::cliError(fn() => $this->command()->import([$file], [])));
        self::assertStringContainsString('Unknown format', self::cliError(fn() => $this->command()->import([$file], ['format' => 'xml'])));
        $csv = $this->put('in/a.csv', "/a,/b\n");
        self::assertStringContainsString('--duplicates', self::cliError(fn() => $this->command()->import([$csv], ['duplicates' => 'merge'])));
    }

    public function testImportPluginWritesTheImportersGroups(): void
    {
        $importer = new FakeImporter('fake', true, [
            'fake-group' => ['title' => 'From Fake', 'rules' => [['from' => '/x', 'to' => '/y'], ['from' => '/bad', 'to' => 'ftp://nope']]],
        ], [['rule' => [], 'status' => 'invalid', 'reason' => 'invalid_regex', 'line' => 9]]);

        $this->command(null, [$importer])->import_plugin(['fake'], ['dry-run' => true]);
        self::assertFileDoesNotExist($this->groupFile('fake-group'));
        self::assertStringContainsString('Line 9 skipped: The regular expression does not compile.', \WP_CLI::messages('warning')[0]);

        \WP_CLI::reset();
        $this->command(null, [$importer])->import_plugin(['fake'], []);
        $data = $this->readGroup('fake-group');
        self::assertSame('From Fake', $data['title']);
        self::assertSame(['/x'], array_column($data['rules'], 'from'));
        self::assertContains('Deactivate fake.', \WP_CLI::messages('log'));
    }

    public function testImportPluginIntoOneGroupAndErrors(): void
    {
        $importer = new FakeImporter('fake', true, ['a' => ['rules' => [['from' => '/1', 'to' => '/2']]], 'b' => ['rules' => [['from' => '/3', 'to' => '/4']]]]);
        $this->command(null, [$importer])->import_plugin(['fake'], ['group' => 'all-in']);
        self::assertSame(['/1', '/3'], array_column($this->readGroup('all-in')['rules'], 'from'));

        self::assertStringContainsString('Use one of: fake', self::cliError(fn() => $this->command(null, [$importer])->import_plugin(['nope'], [])));
        self::assertStringContainsString('no redirects', self::cliError(fn() => $this->command(null, [new FakeImporter('off', false)])->import_plugin(['off'], [])));
    }

    // --- export ----------------------------------------------------------------------

    public function testExportToStdout(): void
    {
        $this->group('main', [['id' => 'r_00000001', 'from' => '/a', 'to' => '/b']]);
        $this->db->queue = [[]];
        $this->command()->export(['-'], ['format' => 'csv']);
        $out = \WP_CLI::messages('line')[0];
        self::assertStringStartsWith('source,target', $out);
        self::assertStringContainsString('/a', $out);
    }

    public function testExportToAFileRefusesToOverwrite(): void
    {
        $this->group('main', [['from' => '/a', 'to' => '/b']]);
        $target = $this->root . '/out.json';
        $this->db->queue = [[]];
        $this->command()->export([$target], ['format' => 'json']);
        $data = json_decode((string) file_get_contents($target), true);
        self::assertArrayHasKey('main', $data['groups']);
        self::assertArrayHasKey('settings', $data);

        self::assertStringContainsString('--force', self::cliError(fn() => $this->command()->export([$target], ['format' => 'json'])));
        $this->db->queue = [[]];
        $this->command()->export([$target], ['format' => 'apache', 'force' => true]);
        self::assertStringContainsString('Proto-Blocks redirects', (string) file_get_contents($target));
    }

    public function testExportErrors(): void
    {
        self::cliError(fn() => $this->command()->export([], ['format' => 'xml']));
        self::cliError(fn() => $this->command()->export([], ['group' => 'nope']));
    }

    // --- log / prune / db ----------------------------------------------------------------

    public function testLogListsClearsAndNeedsTables(): void
    {
        $this->db->queue = [self::TABLES, 1, [['id' => '5', 'created' => '2026-11-05 10:00:00', 'url' => '/a', 'target' => '/b', 'code' => '301', 'redirect_by' => 'proto-blocks', 'method' => 'GET']]];
        $this->command()->log([], ['limit' => '500', 'format' => 'json']);
        self::assertSame('/a', self::lastTable()[0]['url']);
        self::assertStringContainsString('LIMIT 200 OFFSET 0', $this->sql()[2]);
        self::assertSame(['1 of 1 row(s) in the redirect log.'], \WP_CLI::messages('log'));

        $this->db->calls = [];
        $this->db->queue = [self::TABLES, 12];
        $this->command()->log([], ['404' => true, 'clear' => true]);
        self::assertSame('DELETE FROM wp_proto_404_log', $this->sql()[1]);
        self::assertStringContainsString('Deleted 12 row(s) from the 404 log', \WP_CLI::messages('success')[0]);

        $this->db->queue = [[]];
        self::assertStringContainsString('db install', self::cliError(fn() => $this->command()->log([], [])));
    }

    public function testLogCsvIsFormulaEscapedOtherFormatsAreNot(): void
    {
        $row = ['id' => '5', 'created' => '2026-11-05 10:00:00', 'url' => '/a', 'target' => '=HYPERLINK("https://evil.test")', 'code' => '301', 'redirect_by' => '@sum', 'method' => 'GET'];
        $this->db->queue = [self::TABLES, 1, [$row]];
        $this->command()->log([], ['format' => 'csv']);
        $csv = self::lastTable()[0];
        self::assertSame(\ProtoBlocks\API\Admin\RedirectsRoutes::FORMULA_PREFIX . '=HYPERLINK("https://evil.test")', $csv['target']);
        self::assertSame(\ProtoBlocks\API\Admin\RedirectsRoutes::FORMULA_PREFIX . '@sum', $csv['redirectBy']);
        self::assertSame('/a', $csv['url']);

        $this->db->queue = [self::TABLES, 1, [$row]];
        $this->command()->log([], ['format' => 'json']);
        self::assertSame('=HYPERLINK("https://evil.test")', self::lastTable()[0]['target']);
    }

    public function testPruneDeletesOrphansAndAppliesRetention(): void
    {
        $this->group('main', [['id' => 'r_00000001', 'from' => '/a', 'to' => '/b']]);
        $this->db->queue = [self::TABLES, [['group_slug' => 'main', 'rule_id' => 'r_00000001'], ['group_slug' => 'gone', 'rule_id' => 'r_00000002']], 1, 3, 4];
        $this->command()->prune([], []);
        self::assertSame('Deleted 1 orphan hit row(s), 3 old redirect log row(s) and 4 old 404 log row(s).', \WP_CLI::messages('success')[0]);
    }

    public function testDbStatusInstallAndDrop(): void
    {
        $this->db->queue = [[]];
        $this->command()->db(['status'], []);
        self::assertSame(['Tables: missing', 'Schema version: none'], \WP_CLI::messages('log'));

        $this->db->queue = [self::TABLES];
        $this->command()->db(['install'], []);
        self::assertSame('1', get_option(Installer::OPTION));
        self::assertCount(3, $GLOBALS['pb_test_dbdelta']);

        \WP_CLI::reset();
        $this->db->queue = [self::TABLES, []];
        $this->command()->db(['status'], []);
        self::assertSame(['Tables: present', 'Schema version: 1', 'Orphan hit rows: 0'], \WP_CLI::messages('log'));

        self::assertStringContainsString('--yes', self::cliError(fn() => $this->command()->db(['drop'], [])));
        $this->db->calls = [];
        $this->command()->db(['drop'], ['yes' => true]);
        self::assertCount(3, array_filter($this->sql(), static fn(string $s): bool => str_starts_with($s, 'DROP TABLE IF EXISTS')));
        self::assertFalse(get_option(Installer::OPTION));

        $this->db->queue = [[]];
        self::cliError(fn() => $this->command()->db(['install'], []));
        self::cliError(fn() => $this->command()->db(['upgrade'], []));
    }

    private static function removeTree(string $path): void
    {
        if ($path === '' || (!is_dir($path) && !is_link($path))) {
            if (is_file($path)) {
                @unlink($path);
            }
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
