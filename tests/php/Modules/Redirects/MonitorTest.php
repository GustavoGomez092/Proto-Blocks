<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\Modules\Redirects\Monitor;
use ProtoBlocks\Modules\Redirects\Registry;
use ProtoBlocks\Tests\ContentModel\SafeFileTest;

require_once __DIR__ . '/../../ContentModel/SafeFileTest.php';

/**
 * The monitor against a real SchemaFileWriter on a temp theme, a real
 * Registry over injected groups (Registry::fromArrays) and a JSON stand-in
 * for Serializer::group() (the default serializer is tested separately).
 */
final class MonitorTest extends TestCase
{
    private const NOW = 1793882096; // 2026-11-05 12:34:56 UTC

    private string $root;
    private Registry $registry;
    private int $serialized = 0;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . '/pb-monitor-' . bin2hex(random_bytes(6));
        mkdir($tmp);
        $this->root = (string) realpath($tmp);
        SchemaFileWriter::resetKinds();
        SchemaFileWriter::extendKind('redirects', 'proto-redirects', 'redirects', static fn(array $data): array => [], [
            'slugPattern' => '/^[a-z][a-z0-9_-]{0,59}$/D',
        ]);
        $GLOBALS['pb_test_options'] = ['permalink_structure' => '/%postname%/'];
        $GLOBALS['pb_test_hooks'] = [];
        $GLOBALS['pb_test_now'] = self::NOW;
        $GLOBALS['pb_test_posts'] = [];
        $GLOBALS['pb_test_permalinks'] = [];
        unset($GLOBALS['pb_test_home_url']);
        $this->registry = self::registry([]);
    }

    protected function tearDown(): void
    {
        SafeFileTest::removeTree($this->root);
        SchemaFileWriter::resetKinds();
        $GLOBALS['pb_test_options'] = [];
        $GLOBALS['pb_test_hooks'] = [];
        $GLOBALS['pb_test_posts'] = [];
        $GLOBALS['pb_test_permalinks'] = [];
        unset($GLOBALS['pb_test_now'], $GLOBALS['pb_test_home_url']);
    }

    // ------------------------------------------------------------------ doubles

    /**
     * A real Registry over the given groups, already built, so invalidated()
     * can tell whether the monitor dropped its cache.
     *
     * @param list<array<string, mixed>> $groups normalised groups (Track A shape)
     */
    private static function registry(array $groups): Registry
    {
        $files = array_values(array_filter($groups, static fn(array $g): bool => ($g['origin'] ?? 'file') === 'file'));
        $php = array_values(array_filter($groups, static fn(array $g): bool => ($g['origin'] ?? 'file') === 'php'));
        $registry = Registry::fromArrays($files, $php, []);
        $registry->groups();

        return $registry;
    }

    /** Whether the monitor invalidated the registry (its build cache is gone). */
    private function invalidated(): bool
    {
        $built = new \ReflectionProperty(Registry::class, 'built');

        return $built->getValue($this->registry) === null;
    }

    /** @param array<string, mixed> $settings */
    private function monitor(array $settings = [], bool $fileMods = true): Monitor
    {
        $settings += ['monitor' => ['postTypes' => ['post', 'page'], 'group' => 'modified-posts']];
        $serialize = function (array $group): string {
            $this->serialized++;
            unset($group['origin'], $group['source'], $group['root'], $group['double']);
            return (string) json_encode($group, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        };

        return new Monitor($this->registry, new SchemaFileWriter([$this->root], $fileMods), $settings, $serialize);
    }

    /** @return array<string, mixed>|null the decoded group file */
    private function file(string $slug): ?array
    {
        $path = $this->root . '/proto-redirects/' . $slug . '/redirects.jsonc';
        if (!is_file($path)) {
            return null;
        }

        return Jsonc::decode((string) file_get_contents($path));
    }

    /** Writes a group file and makes the registry return it as loaded. */
    private function seedGroup(string $slug, array $rules, array $extra = []): array
    {
        $dir = $this->root . '/proto-redirects/' . $slug;
        mkdir($dir, 0777, true);
        $group = $extra + [
            'slug' => $slug,
            'title' => ucfirst($slug),
            'enabled' => true,
            'position' => 10,
            'defaults' => ['ignoreCase' => true, 'ignoreTrailingSlash' => true, 'query' => 'exact'],
            'rules' => array_map(static fn(array $r): array => $r + [
                'title' => '', 'enabled' => true, 'regex' => false, 'else' => null, 'code' => 301, 'action' => 'url',
                'when' => null, 'query' => 'exact', 'ignoreCase' => true, 'ignoreTrailingSlash' => true, 'log' => true, 'headers' => [],
            ], $rules),
            'origin' => 'file',
            'source' => $dir . '/redirects.jsonc',
            'root' => $this->root,
            'double' => null,
        ];
        $onDisk = $group;
        unset($onDisk['origin'], $onDisk['source'], $onDisk['root'], $onDisk['double']);
        file_put_contents($dir . '/redirects.jsonc', "// hand comment\n" . json_encode($onDisk, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $group;
    }

    private function post(int $id, string $status = 'publish', string $type = 'post', string $title = 'Hello world'): object
    {
        $post = (object) ['ID' => $id, 'post_status' => $status, 'post_type' => $type, 'post_title' => $title];
        $GLOBALS['pb_test_posts'][$id] = $post;

        return $post;
    }

    /** Runs the pre/post update hooks for a permalink change. */
    private function move(Monitor $monitor, int $id, string $from, string $to, string $beforeStatus = 'publish', string $afterStatus = 'publish', string $type = 'post'): void
    {
        $before = $this->post($id, $beforeStatus, $type);
        $GLOBALS['pb_test_permalinks'][$id] = $from;
        $monitor->onPrePostUpdate($id, []);
        $after = $this->post($id, $afterStatus, $type);
        $GLOBALS['pb_test_permalinks'][$id] = $to;
        $monitor->onPostUpdated($id, $after, $before);
    }

    // ------------------------------------------------------------------ registration

    public function testRegisterHooksWithTheSpecPriorities(): void
    {
        $monitor = $this->monitor();
        $monitor->register();
        $hooks = $GLOBALS['pb_test_hooks'];

        self::assertSame(10, $hooks['pre_post_update'][0]['priority']);
        self::assertSame(2, $hooks['pre_post_update'][0]['args']);
        self::assertSame(11, $hooks['post_updated'][0]['priority']);
        self::assertSame(3, $hooks['post_updated'][0]['args']);
        self::assertSame([$monitor, 'onPrePostUpdate'], $hooks['pre_post_update'][0]['callback']);
        self::assertSame([$monitor, 'onTrashed'], $hooks['trashed_post'][0]['callback']);
        self::assertSame([$monitor, 'onPreTrash'], $hooks['wp_trash_post'][0]['callback']);
    }

    public function testNoWatchedTypesMeansNoHooks(): void
    {
        $this->monitor(['monitor' => ['postTypes' => []]])->register();
        self::assertSame([], $GLOBALS['pb_test_hooks']);
    }

    // ------------------------------------------------------------------ the move rule

    public function testSlugChangeAppendsARuleAndCreatesTheGroup(): void
    {
        $this->move($this->monitor(), 5, 'http://example.test/hello-world/', 'http://example.test/hello-there/');

        $file = $this->file('modified-posts');
        self::assertNotNull($file);
        self::assertSame('modified-posts', $file['slug']);
        self::assertSame('Modified posts', $file['title']);
        self::assertCount(1, $file['rules']);
        $rule = $file['rules'][0];
        self::assertSame('/hello-world/', $rule['from']);
        self::assertSame('/hello-there/', $rule['to']);
        self::assertSame('Hello world (moved 2026-11-05)', $rule['title']);
        self::assertTrue($rule['enabled']);
        self::assertSame(301, $rule['code']);
        self::assertSame('url', $rule['action']);
        self::assertMatchesRegularExpression('/^r_[a-f0-9]{8}$/', $rule['id']);
        self::assertTrue($this->invalidated());
    }

    public function testRuleIsAppendedToAnExistingGroupKeepingItsRules(): void
    {
        $group = $this->seedGroup('modified-posts', [['id' => 'r_00000001', 'from' => '/a', 'to' => '/b']]);
        $this->registry = self::registry([$group]);
        $this->move($this->monitor(), 5, 'http://example.test/old/', 'http://example.test/new/');

        $rules = $this->file('modified-posts')['rules'];
        self::assertSame(['/a', '/old/'], array_column($rules, 'from'));
        self::assertArrayNotHasKey('group', $rules[0], 'registry keys never reach the file');
        self::assertArrayNotHasKey('index', $rules[0]);
    }

    public function testCustomGroupSlug(): void
    {
        $this->move($this->monitor(['monitor' => ['postTypes' => ['post'], 'group' => 'renames']]), 5, 'http://example.test/old/', 'http://example.test/new/');
        self::assertNotNull($this->file('renames'));
        self::assertNull($this->file('modified-posts'));
    }

    public function testDefaultGroupWhenNoneConfigured(): void
    {
        $this->move($this->monitor(['monitor' => ['postTypes' => ['post']]]), 5, 'http://example.test/old/', 'http://example.test/new/');
        self::assertNotNull($this->file('modified-posts'));
    }

    public function testSiteDefaultsSeedANewGroup(): void
    {
        $settings = ['defaults' => ['ignoreCase' => false, 'ignoreTrailingSlash' => true, 'query' => 'ignore']];
        $this->move($this->monitor($settings), 5, 'http://example.test/old/', 'http://example.test/new/');
        $file = $this->file('modified-posts');
        self::assertSame(['ignoreCase' => false, 'ignoreTrailingSlash' => true, 'query' => 'ignore'], $file['defaults']);
        self::assertFalse($file['rules'][0]['ignoreCase']);
        self::assertSame('ignore', $file['rules'][0]['query']);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function statusPairs(): iterable
    {
        yield 'publish → publish' => ['publish', 'publish', true];
        yield 'draft → publish' => ['draft', 'publish', false];
        yield 'publish → draft' => ['publish', 'draft', false];
        yield 'publish → private' => ['publish', 'private', false];
        yield 'future → publish' => ['future', 'publish', false];
        yield 'draft → draft' => ['draft', 'draft', false];
        yield 'pending → pending' => ['pending', 'pending', false];
    }

    #[DataProvider('statusPairs')]
    public function testOnlyPublishToPublishIsWatched(string $before, string $after, bool $writes): void
    {
        $this->move($this->monitor(), 5, 'http://example.test/old/', 'http://example.test/new/', $before, $after);
        self::assertSame($writes, $this->file('modified-posts') !== null);
    }

    public function testUnwatchedTypeIsIgnored(): void
    {
        $this->move($this->monitor(), 5, 'http://example.test/old/', 'http://example.test/new/', 'publish', 'publish', 'product');
        self::assertNull($this->file('modified-posts'));
    }

    public function testWatchedPageType(): void
    {
        $this->move($this->monitor(), 5, 'http://example.test/about/', 'http://example.test/about-us/', 'publish', 'publish', 'page');
        self::assertSame('/about/', $this->file('modified-posts')['rules'][0]['from']);
    }

    public function testUnchangedSlugWritesNothing(): void
    {
        $this->move($this->monitor(), 5, 'http://example.test/same/', 'http://example.test/same/');
        self::assertNull($this->file('modified-posts'));
        self::assertSame(0, $this->serialized);
    }

    public function testRootOldPathIsSkipped(): void
    {
        $this->move($this->monitor(), 5, 'http://example.test/', 'http://example.test/home/');
        self::assertNull($this->file('modified-posts'));
    }

    public function testPlainOldPermalinkResolvesToRootAndIsSkipped(): void
    {
        $this->move($this->monitor(), 5, 'http://example.test/?p=5', 'http://example.test/new/');
        self::assertNull($this->file('modified-posts'));
    }

    public function testPlainPermalinksDisableTheMonitor(): void
    {
        $GLOBALS['pb_test_options']['permalink_structure'] = '';
        $this->move($this->monitor(), 5, 'http://example.test/old/', 'http://example.test/new/');
        self::assertNull($this->file('modified-posts'));
    }

    public function testPostUpdatedWithoutPrePostUpdateDoesNothing(): void
    {
        $after = $this->post(5);
        $GLOBALS['pb_test_permalinks'][5] = 'http://example.test/new/';
        $this->monitor()->onPostUpdated(5, $after, $after);
        self::assertNull($this->file('modified-posts'));
    }

    public function testPathIsRelativeToASubdirectoryHome(): void
    {
        $GLOBALS['pb_test_home_url'] = 'http://example.test/blog';
        $this->move($this->monitor(), 5, 'http://example.test/blog/old-post/', 'http://example.test/blog/new-post/');
        $rule = $this->file('modified-posts')['rules'][0];
        self::assertSame('/old-post/', $rule['from']);
        self::assertSame('/new-post/', $rule['to']);
    }

    public function testPermalinkOnAnotherHostIsIgnored(): void
    {
        $this->move($this->monitor(), 5, 'http://other.test/old/', 'http://example.test/new/');
        self::assertNull($this->file('modified-posts'));
    }

    public function testUnicodeSlugIsStoredDecoded(): void
    {
        $this->move($this->monitor(), 5, 'http://example.test/caf%C3%A9/', 'http://example.test/coffee/');
        self::assertSame('/café/', $this->file('modified-posts')['rules'][0]['from']);
    }

    public function testTitleIsCleanedAndCapped(): void
    {
        $id = 5;
        $before = $this->post($id, 'publish', 'post', '<b>Big</b> ' . str_repeat('x', 300));
        $GLOBALS['pb_test_permalinks'][$id] = 'http://example.test/old/';
        $monitor = $this->monitor();
        $monitor->onPrePostUpdate($id, []);
        $GLOBALS['pb_test_permalinks'][$id] = 'http://example.test/new/';
        $monitor->onPostUpdated($id, $before, $before);

        $title = $this->file('modified-posts')['rules'][0]['title'];
        self::assertLessThanOrEqual(200, mb_strlen($title));
        self::assertStringNotContainsString('<', $title);
        self::assertStringEndsWith(' (moved 2026-11-05)', $title);
    }

    public function testUntitledPostUsesItsId(): void
    {
        $before = $this->post(42, 'publish', 'post', '');
        $GLOBALS['pb_test_permalinks'][42] = 'http://example.test/old/';
        $monitor = $this->monitor();
        $monitor->onPrePostUpdate(42, []);
        $GLOBALS['pb_test_permalinks'][42] = 'http://example.test/new/';
        $monitor->onPostUpdated(42, $before, $before);
        self::assertSame('#42 (moved 2026-11-05)', $this->file('modified-posts')['rules'][0]['title']);
    }

    public function testRenamingTheSameSourceAgainUpdatesTheRuleInPlace(): void
    {
        $group = $this->seedGroup('modified-posts', [['id' => 'r_00000001', 'from' => '/old/', 'to' => '/first/', 'enabled' => false]]);
        $this->registry = self::registry([$group]);
        $this->move($this->monitor(), 5, 'http://example.test/old', 'http://example.test/second/');

        $rules = $this->file('modified-posts')['rules'];
        self::assertCount(1, $rules);
        self::assertSame('r_00000001', $rules[0]['id']);
        self::assertSame('/second/', $rules[0]['to']);
        self::assertTrue($rules[0]['enabled']);
    }

    public function testDerivedIdAvoidsACollisionInTheGroup(): void
    {
        $this->move($this->monitor(), 5, 'http://example.test/old/', 'http://example.test/new/');
        $first = $this->file('modified-posts');
        $id = $first['rules'][0]['id'];

        // Start over with a group where another source already holds that id.
        SafeFileTest::removeTree($this->root . '/proto-redirects');
        $group = $this->seedGroup('modified-posts', [['id' => $id, 'from' => '/x', 'to' => '/y']]);
        $this->registry = self::registry([$group]);
        $this->move($this->monitor(), 5, 'http://example.test/old/', 'http://example.test/new/');

        $ids = array_column($this->file('modified-posts')['rules'], 'id');
        self::assertCount(2, array_unique($ids));
    }

    // ------------------------------------------------------------------ associated

    public function testAssociatedSuffixAddsASecondRule(): void
    {
        $settings = ['monitor' => ['postTypes' => ['post'], 'associated' => '/feed/']];
        $this->move($this->monitor($settings), 5, 'http://example.test/old/', 'http://example.test/new/');

        $rules = $this->file('modified-posts')['rules'];
        self::assertSame([['/old/', '/new/'], ['/old/feed/', '/new/feed/']], array_map(static fn($r) => [$r['from'], $r['to']], $rules));
        self::assertNotSame($rules[0]['id'], $rules[1]['id']);
    }

    public function testAssociatedSuffixWithoutSlashesIsNormalised(): void
    {
        $settings = ['monitor' => ['postTypes' => ['post'], 'associated' => 'amp']];
        $this->move($this->monitor($settings), 5, 'http://example.test/old', 'http://example.test/new');
        self::assertSame('/old/amp/', $this->file('modified-posts')['rules'][1]['from']);
        self::assertSame('/new/amp/', $this->file('modified-posts')['rules'][1]['to']);
    }

    public function testBlankAssociatedAddsNothing(): void
    {
        $settings = ['monitor' => ['postTypes' => ['post'], 'associated' => ' / ']];
        $this->move($this->monitor($settings), 5, 'http://example.test/old/', 'http://example.test/new/');
        self::assertCount(1, $this->file('modified-posts')['rules']);
    }

    // ------------------------------------------------------------------ loop avoidance

    public function testRuleFromTheNewPathIsDisabledInItsOwnGroupFile(): void
    {
        $legacy = $this->seedGroup('legacy', [
            ['id' => 'r_0000000a', 'from' => '/new', 'to' => '/old/'],
            ['id' => 'r_0000000b', 'from' => '/keep', 'to' => '/x'],
        ]);
        $this->registry = self::registry([$legacy]);
        $this->move($this->monitor(), 5, 'http://example.test/old/', 'http://example.test/new/');

        $legacyFile = $this->file('legacy');
        self::assertFalse($legacyFile['rules'][0]['enabled']);
        self::assertTrue($legacyFile['rules'][1]['enabled']);
        self::assertSame('/old/', $this->file('modified-posts')['rules'][0]['from']);

        $issues = $this->monitor()->issues();
        self::assertCount(1, $issues);
        self::assertSame('monitor_rule_disabled', $issues[0]->code);
        self::assertSame(Issue::WARNING, $issues[0]->level);
        self::assertSame('legacy', $issues[0]->slug);
        self::assertStringContainsString('/new', $issues[0]->message);
    }

    public function testRenameBackDisablesTheEarlierRuleInTheMonitorGroup(): void
    {
        $group = $this->seedGroup('modified-posts', [['id' => 'r_00000001', 'from' => '/a/', 'to' => '/b/']]);
        $this->registry = self::registry([$group]);
        $this->move($this->monitor(), 5, 'http://example.test/b/', 'http://example.test/a/');

        $rules = $this->file('modified-posts')['rules'];
        self::assertCount(2, $rules);
        self::assertFalse($rules[0]['enabled'], 'a → b would now loop with b → a');
        self::assertSame(['/b/', '/a/'], [$rules[1]['from'], $rules[1]['to']]);
        self::assertTrue($rules[1]['enabled']);
    }

    public function testLoopMatchHonoursTheRuleFlags(): void
    {
        $legacy = $this->seedGroup('legacy', [
            ['id' => 'r_0000000a', 'from' => '/NEW/', 'to' => '/x'],
            ['id' => 'r_0000000b', 'from' => '/NEW/', 'to' => '/y', 'ignoreCase' => false],
            ['id' => 'r_0000000c', 'from' => '/new', 'to' => '/z', 'ignoreTrailingSlash' => false],
        ]);
        $this->registry = self::registry([$legacy]);
        $this->move($this->monitor(), 5, 'http://example.test/old/', 'http://example.test/new/');

        self::assertSame([false, true, true], array_column($this->file('legacy')['rules'], 'enabled'));
    }

    public function testRegexAndDisabledRulesAreLeftAlone(): void
    {
        $legacy = $this->seedGroup('legacy', [
            ['id' => 'r_0000000a', 'from' => '^/new/$', 'to' => '/x', 'regex' => true],
            ['id' => 'r_0000000b', 'from' => '/new/', 'to' => '/y', 'enabled' => false],
        ]);
        $this->registry = self::registry([$legacy]);
        $this->move($this->monitor(), 5, 'http://example.test/old/', 'http://example.test/new/');

        self::assertNotNull($this->file('modified-posts'));
        $legacyFile = $this->file('legacy');
        self::assertTrue($legacyFile['rules'][0]['enabled']);
        self::assertSame('legacy', $legacyFile['slug']);
        self::assertSame([], $this->monitor()->issues());
        // Untouched: the hand comment is still there because the file was never rewritten.
        self::assertStringStartsWith('// hand comment', (string) file_get_contents($this->root . '/proto-redirects/legacy/redirects.jsonc'));
    }

    public function testPhpDeclaredLoopIsReportedNotWritten(): void
    {
        $php = [
            'slug' => 'php', 'title' => 'Declared in PHP', 'enabled' => true, 'position' => 100, 'defaults' => [],
            'rules' => [['id' => 'r_0000000f', 'from' => '/new/', 'to' => '/x', 'enabled' => true, 'regex' => false]],
            'origin' => 'php', 'source' => 'functions.php:12', 'root' => null, 'double' => null,
        ];
        $this->registry = self::registry([$php]);
        $this->move($this->monitor(), 5, 'http://example.test/old/', 'http://example.test/new/');

        self::assertNull($this->file('php'));
        $issues = $this->monitor()->issues();
        self::assertCount(1, $issues);
        self::assertSame('monitor_rule_disabled', $issues[0]->code);
        self::assertStringContainsString('functions.php:12', $issues[0]->message);
    }

    public function testAssociatedTargetLoopIsAvoidedToo(): void
    {
        $legacy = $this->seedGroup('legacy', [['id' => 'r_0000000a', 'from' => '/new/feed/', 'to' => '/x']]);
        $this->registry = self::registry([$legacy]);
        $this->move($this->monitor(['monitor' => ['postTypes' => ['post'], 'associated' => '/feed/']]), 5, 'http://example.test/old/', 'http://example.test/new/');
        self::assertFalse($this->file('legacy')['rules'][0]['enabled']);
    }

    // ------------------------------------------------------------------ trash

    public function testTrashAddsADisabledRuleToRoot(): void
    {
        $monitor = $this->monitor(['monitor' => ['postTypes' => ['post'], 'trash' => true]]);
        $this->post(7, 'publish');
        $GLOBALS['pb_test_permalinks'][7] = 'http://example.test/gone/';
        $monitor->onPreTrash(7);
        $this->post(7, 'trash');
        $GLOBALS['pb_test_permalinks'][7] = 'http://example.test/gone__trashed/';
        $monitor->onTrashed(7);

        $rule = $this->file('modified-posts')['rules'][0];
        self::assertSame('/gone/', $rule['from']);
        self::assertSame('/', $rule['to']);
        self::assertFalse($rule['enabled']);
        self::assertSame('Hello world (trashed 2026-11-05)', $rule['title']);
    }

    public function testTrashIsOffByDefault(): void
    {
        $monitor = $this->monitor();
        $this->post(7, 'publish');
        $GLOBALS['pb_test_permalinks'][7] = 'http://example.test/gone/';
        $monitor->onPreTrash(7);
        $monitor->onTrashed(7);
        self::assertNull($this->file('modified-posts'));
    }

    public function testTrashingADraftAddsNothing(): void
    {
        $monitor = $this->monitor(['monitor' => ['postTypes' => ['post'], 'trash' => true]]);
        $this->post(7, 'draft');
        $GLOBALS['pb_test_permalinks'][7] = 'http://example.test/?p=7';
        $monitor->onPreTrash(7);
        $monitor->onTrashed(7);
        self::assertNull($this->file('modified-posts'));
    }

    public function testTrashingAnUnwatchedTypeAddsNothing(): void
    {
        $monitor = $this->monitor(['monitor' => ['postTypes' => ['page'], 'trash' => true]]);
        $this->post(7, 'publish', 'post');
        $GLOBALS['pb_test_permalinks'][7] = 'http://example.test/gone/';
        $monitor->onPreTrash(7);
        $monitor->onTrashed(7);
        self::assertNull($this->file('modified-posts'));
    }

    public function testTrashedRuleDoesNotDisableRulesFromRoot(): void
    {
        $legacy = $this->seedGroup('legacy', [['id' => 'r_0000000a', 'from' => '/', 'to' => '/home']]);
        $this->registry = self::registry([$legacy]);
        $monitor = $this->monitor(['monitor' => ['postTypes' => ['post'], 'trash' => true]]);
        $this->post(7, 'publish');
        $GLOBALS['pb_test_permalinks'][7] = 'http://example.test/gone/';
        $monitor->onPreTrash(7);
        $monitor->onTrashed(7);
        self::assertTrue($this->file('legacy')['rules'][0]['enabled']);
    }

    // ------------------------------------------------------------------ unwritable

    public function testUnwritableThemeRecordsAnIssueAndWritesNothing(): void
    {
        $this->move($this->monitor([], false), 5, 'http://example.test/old/', 'http://example.test/new/');

        self::assertNull($this->file('modified-posts'));
        self::assertSame(0, $this->serialized);
        self::assertFalse($this->invalidated());
        $issues = $this->monitor()->issues();
        self::assertCount(1, $issues);
        self::assertSame('monitor_unwritable', $issues[0]->code);
        self::assertSame(Issue::WARNING, $issues[0]->level);
        self::assertSame('modified-posts', $issues[0]->slug);
        self::assertSame('redirects', $issues[0]->kind);
        self::assertStringContainsString('file_mods', $issues[0]->message);
    }

    public function testUnwritableLoopGroupBlocksEveryWrite(): void
    {
        // The loop rule lives in a read-only root (child/parent themes, schema path filter).
        $readOnly = $this->root . '/readonly-root';
        mkdir($readOnly . '/proto-redirects/legacy', 0777, true);
        file_put_contents($readOnly . '/proto-redirects/legacy/redirects.jsonc', '{"rules": []}');
        $legacy = [
            'slug' => 'legacy', 'title' => 'Legacy', 'enabled' => true, 'position' => 10, 'defaults' => [],
            'rules' => [['id' => 'r_0000000a', 'from' => '/new/', 'to' => '/x', 'enabled' => true, 'regex' => false]],
            'origin' => 'file', 'source' => $readOnly . '/proto-redirects/legacy/redirects.jsonc', 'root' => $readOnly, 'double' => null,
        ];
        $this->registry = self::registry([$legacy]);

        $writer = new SchemaFileWriter([$this->root . '/theme'], true);
        mkdir($this->root . '/theme');
        $monitor = new Monitor($this->registry, $writer, ['monitor' => ['postTypes' => ['post']]], function (array $g): string {
            $this->serialized++;
            return (string) json_encode($g);
        });
        $this->move($monitor, 5, 'http://example.test/old/', 'http://example.test/new/');

        self::assertFileDoesNotExist($this->root . '/theme/proto-redirects/modified-posts/redirects.jsonc');
        self::assertSame(0, $this->serialized);
        self::assertSame('{"rules": []}', file_get_contents($readOnly . '/proto-redirects/legacy/redirects.jsonc'));
        self::assertSame('monitor_unwritable', $monitor->issues()[0]->code);
    }

    public function testInvalidGroupSlugIsReported(): void
    {
        $this->move($this->monitor(['monitor' => ['postTypes' => ['post'], 'group' => '../escape']]), 5, 'http://example.test/old/', 'http://example.test/new/');
        self::assertSame(0, $this->serialized);
        self::assertSame('monitor_unwritable', $this->monitor()->issues()[0]->code);
        self::assertDirectoryDoesNotExist($this->root . '/proto-redirects');
    }

    public function testWriterRefusalIsRecorded(): void
    {
        $monitor = new Monitor($this->registry, new SchemaFileWriter([$this->root], true), ['monitor' => ['postTypes' => ['post']]], static fn(array $g): string => '{ not json');
        $this->move($monitor, 5, 'http://example.test/old/', 'http://example.test/new/');

        self::assertNull($this->file('modified-posts'));
        $issue = $monitor->issues()[0];
        self::assertSame('monitor_unwritable', $issue->code);
        self::assertStringContainsString('Invalid JSONC', $issue->message);
    }

    public function testIssuesAreCappedAndClearable(): void
    {
        $monitor = $this->monitor([], false);
        for ($i = 0; $i < 25; $i++) {
            $this->move($monitor, 5, 'http://example.test/old/', 'http://example.test/new/');
        }
        self::assertCount(20, $monitor->issues());
        $monitor->clearIssues();
        self::assertSame([], $monitor->issues());
    }

    public function testDefaultSerializerWritesAgainstTheSiteDefaults(): void
    {
        $settings = [
            'monitor' => ['postTypes' => ['post'], 'group' => 'modified-posts'],
            'defaults' => ['ignoreCase' => false, 'ignoreTrailingSlash' => true, 'query' => 'ignore'],
        ];
        $monitor = new Monitor($this->registry, new SchemaFileWriter([$this->root], true), $settings);
        $this->move($monitor, 5, 'http://example.test/old/', 'http://example.test/new/');

        $text = (string) file_get_contents($this->root . '/proto-redirects/modified-posts/redirects.jsonc');
        $file = Jsonc::decode($text);
        self::assertArrayNotHasKey('defaults', $file, 'the new group inherits the site defaults instead of pinning them');
        self::assertArrayNotHasKey('ignoreCase', $file['rules'][0]);
        self::assertArrayNotHasKey('query', $file['rules'][0]);
        self::assertSame('/old/', $file['rules'][0]['from']);
    }

    public function testExistingFileIsOverwrittenWithItsCurrentHash(): void
    {
        $group = $this->seedGroup('modified-posts', [['id' => 'r_00000001', 'from' => '/a', 'to' => '/b']]);
        $this->registry = self::registry([$group]);
        $this->move($this->monitor(), 5, 'http://example.test/old/', 'http://example.test/new/');

        $text = (string) file_get_contents($this->root . '/proto-redirects/modified-posts/redirects.jsonc');
        self::assertStringNotContainsString('// hand comment', $text, 'server-side writes do not keep comments (spec §8)');
        self::assertCount(2, $this->file('modified-posts')['rules']);
        self::assertSame([], $this->monitor()->issues());
    }
}
