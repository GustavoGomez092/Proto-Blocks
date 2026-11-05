<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Import;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Redirects\Import\Merger;
use ProtoBlocks\Modules\Redirects\Import\ParsedRow;

final class MergerTest extends TestCase
{
    private static function group(): array
    {
        return [
            'slug' => 'legacy',
            'title' => 'Legacy',
            'rules' => [
                ['id' => 'r_00000001', 'title' => 'Kept title', 'from' => '/About/', 'to' => '/about', 'code' => 301, 'ignoreCase' => true],
                ['id' => 'r_00000002', 'from' => '/members', 'to' => '/dash', 'when' => ['type' => 'login']],
                ['from' => '^/blog/(.*)$', 'regex' => true, 'to' => '/news/$1'],
            ],
        ];
    }

    private static function incoming(): array
    {
        return [
            ['from' => '/about', 'to' => '/company', 'code' => 302, 'action' => 'url'],          // dup of #1 (case + slash)
            ['from' => '/members', 'to' => '/other'],                                             // not a dup: no when
            ['from' => '/members', 'when' => ['type' => 'login'], 'to' => '/x'],                 // dup of #2
            ['from' => '^/blog/(.*)$', 'regex' => true, 'to' => '/posts/$1'],                    // dup of #3 (no id)
            ['from' => '^/blog/(.*)$', 'regex' => false, 'to' => '/y'],                          // regex flag differs
        ];
    }

    public function testSkipKeepsExistingRules(): void
    {
        $result = Merger::merge(self::group(), self::incoming(), 'skip');
        $this->assertSame(2, $result['added']);
        $this->assertSame(0, $result['replaced']);
        $this->assertSame(3, $result['skipped']);
        $rules = $result['group']['rules'];
        $this->assertCount(5, $rules);
        $this->assertSame('/about', $rules[0]['to']);
        $this->assertSame('/other', $rules[3]['to']);
        $this->assertMatchesRegularExpression('/^r_[a-f0-9]{8}$/', $rules[3]['id'], 'added rules get an id');
        $this->assertSame('Legacy', $result['group']['title'], 'group keys untouched');
    }

    public function testReplaceOverwritesTargetCodeAndFlagsButKeepsTheId(): void
    {
        $result = Merger::merge(self::group(), self::incoming(), 'replace');
        $this->assertSame(['added' => 2, 'replaced' => 3, 'skipped' => 0], array_diff_key($result, ['group' => 1]));
        $rules = $result['group']['rules'];
        $this->assertSame(
            ['id' => 'r_00000001', 'title' => 'Kept title', 'from' => '/about', 'to' => '/company', 'code' => 302, 'action' => 'url', 'ignoreCase' => true],
            $rules[0],
        );
        $this->assertSame('r_00000002', $rules[1]['id']);
        $this->assertSame('/x', $rules[1]['to']);
        $this->assertMatchesRegularExpression('/^r_[a-f0-9]{8}$/', $rules[2]['id'], 'an id-less rule gets its id pinned on replace');
        $this->assertSame('/posts/$1', $rules[2]['to']);
        $this->assertCount(5, $rules);
    }

    public function testReplaceDropsTheOldElseWhenTheImportHasNone(): void
    {
        $group = ['slug' => 'g', 'rules' => [['id' => 'r_00000009', 'from' => '/m', 'when' => ['type' => 'login'], 'to' => '/a', 'else' => '/b']]];
        $rules = Merger::merge($group, [['from' => '/m', 'when' => ['type' => 'login'], 'code' => 410, 'action' => 'error']], 'replace')['group']['rules'];
        $this->assertSame(['id' => 'r_00000009', 'from' => '/m', 'code' => 410, 'action' => 'error', 'when' => ['type' => 'login']], $rules[0]);
    }

    public function testAddAlwaysAppendsWithUniqueIds(): void
    {
        $result = Merger::merge(self::group(), self::incoming(), 'add');
        $this->assertSame(5, $result['added']);
        $this->assertCount(8, $result['group']['rules']);
        $ids = array_filter(array_column($result['group']['rules'], 'id'));
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function testAddTwiceTheSameRuleStillGivesDistinctIds(): void
    {
        $rule = ['from' => '/a', 'to' => '/b'];
        $rules = Merger::merge(['slug' => 'g', 'rules' => []], [$rule, $rule], 'add')['group']['rules'];
        $this->assertNotSame($rules[0]['id'], $rules[1]['id']);
    }

    public function testIncomingIdIsKeptUnlessItCollides(): void
    {
        $rules = Merger::merge(self::group(), [
            ['id' => 'r_abcdef12', 'from' => '/new1', 'to' => '/x'],
            ['id' => 'r_00000001', 'from' => '/new2', 'to' => '/y'],
        ], 'skip')['group']['rules'];
        $this->assertSame('r_abcdef12', $rules[3]['id']);
        $this->assertNotSame('r_00000001', $rules[4]['id']);
    }

    public function testDuplicatesInsideTheImportItself(): void
    {
        $rule = ['from' => '/a', 'to' => '/b'];
        $skip = Merger::merge(['slug' => 'g', 'rules' => []], [$rule, $rule + ['code' => 302]], 'skip');
        $this->assertSame([1, 1], [$skip['added'], $skip['skipped']]);
        $replace = Merger::merge(['slug' => 'g', 'rules' => []], [$rule, ['from' => '/a', 'to' => '/c']], 'replace');
        $this->assertSame([1, 1], [$replace['added'], $replace['replaced']]);
        $this->assertSame('/c', $replace['group']['rules'][0]['to']);
    }

    public function testCaseSensitiveRulesAreDistinct(): void
    {
        $group = ['slug' => 'g', 'defaults' => ['ignoreCase' => false], 'rules' => [['from' => '/About', 'to' => '/x']]];
        $this->assertSame(1, Merger::merge($group, [['from' => '/about', 'to' => '/y']])['added']);
    }

    public function testQueryIsPartOfTheKey(): void
    {
        $group = ['slug' => 'g', 'rules' => [['from' => '/a?x=1', 'to' => '/x']]];
        $this->assertSame(1, Merger::merge($group, [['from' => '/a?x=2', 'to' => '/y']])['added']);
        $this->assertSame(1, Merger::merge($group, [['from' => '/a/?x=1', 'to' => '/y']])['skipped']);
    }

    public function testWhenKeyOrderDoesNotMatter(): void
    {
        $group = ['slug' => 'g', 'rules' => [['from' => '/a', 'to' => '/x', 'when' => ['type' => 'referrer', 'value' => 'a', 'regex' => false]]]];
        $this->assertSame(1, Merger::merge($group, [['from' => '/a', 'to' => '/y', 'when' => ['regex' => false, 'value' => 'a', 'type' => 'referrer']]])['skipped']);
    }

    public function testParsedRowsAreAcceptedAndInvalidOnesIgnored(): void
    {
        $result = Merger::merge(['slug' => 'g', 'rules' => []], [
            ParsedRow::ok(['from' => '/a', 'to' => '/b'], 1),
            ParsedRow::invalid('self_redirect', 2, ['from' => '/c', 'to' => '/c']),
        ]);
        $this->assertSame(1, $result['added']);
        $this->assertSame('/a', $result['group']['rules'][0]['from']);
    }

    public function testUnknownModeFallsBackToSkip(): void
    {
        $this->assertSame(1, Merger::merge(self::group(), [['from' => '/about', 'to' => '/q']], 'bogus')['skipped']);
    }

    public function testRegistryKeysAreStrippedFromImportedRules(): void
    {
        $rules = Merger::merge(['slug' => 'g', 'rules' => []], [['from' => '/a', 'to' => '/b', 'group' => 'x', 'origin' => 'file', 'index' => 3]])['group']['rules'];
        $this->assertSame(['from' => '/a', 'to' => '/b'], array_diff_key($rules[0], ['id' => 1]));
    }

    public function testDerivedIdIsDeterministic(): void
    {
        $rule = ['from' => '/a', 'action' => 'url'];
        $this->assertSame(Merger::derive('g', $rule, []), Merger::derive('g', $rule, []));
        $this->assertSame('r_' . substr(sha1("g\0/a\0null\0url"), 0, 8), Merger::derive('g', $rule, []));
    }

    public function testMarkFlagsDuplicatesForThePreview(): void
    {
        $rows = Merger::mark(self::group(), [
            ParsedRow::ok(['from' => '/about', 'to' => '/q'], 1),
            ParsedRow::ok(['from' => '/fresh', 'to' => '/q'], 2),
            ParsedRow::ok(['from' => '/FRESH/', 'to' => '/r'], 3),
            ParsedRow::invalid('empty_source', 4),
        ]);
        $this->assertSame(['duplicate', 'new', 'duplicate', 'invalid'], array_column($rows, 'status'));
        $this->assertSame('duplicate', $rows[0]['reason']);
        $this->assertSame('empty_source', $rows[3]['reason']);
    }

    public function testAddedIdAvoidsTheDerivedIdOfAnExistingIdLessRule(): void
    {
        // The loader derives ids from slug + from + when + action (not the regex
        // flag), so a regex /a next to an id-less literal /a must get another id.
        $group = ['slug' => 'legacy', 'rules' => [['from' => '/a', 'to' => '/b']]];
        $result = Merger::merge($group, [['from' => '/a', 'regex' => true, 'to' => '/c']], 'skip');

        $this->assertSame(1, $result['added']);
        $derived = \ProtoBlocks\Modules\Redirects\Definition\RuleNormalizer::deriveId('legacy', '/a', null, 'url');
        $this->assertNotSame($derived, $result['group']['rules'][1]['id']);
        $errors = \ProtoBlocks\Modules\Redirects\Definition\GroupValidator::validate(['rules' => $result['group']['rules']], [], 'legacy')['errors'];
        $this->assertSame([], array_column($errors, 'code'), 'the loader reads both rules');
    }

    public function testReplacingAnIdLessRulePinsTheIdTheLoaderDerives(): void
    {
        $group = ['slug' => 'legacy', 'rules' => [['from' => '/a', 'to' => '/b']]];
        $result = Merger::merge($group, [['from' => '/a', 'to' => '/c']], 'replace');

        $derived = \ProtoBlocks\Modules\Redirects\Definition\RuleNormalizer::deriveId('legacy', '/a', null, 'url');
        $this->assertSame($derived, $result['group']['rules'][0]['id'], 'hit counts keyed by the derived id stay attached');
    }

    public function testDuplicateKeyLowerCasesLikeTheLoader(): void
    {
        $group = ['slug' => 'legacy', 'rules' => [['id' => 'r_00000001', 'from' => '/Ärger', 'to' => '/b']]];
        $result = Merger::merge($group, [['from' => '/ärger/', 'to' => '/c']], 'skip');

        $this->assertSame(1, $result['skipped'], 'multibyte case folds like RuleNormalizer::pathKey');
        $this->assertSame(0, $result['added']);
    }
}
