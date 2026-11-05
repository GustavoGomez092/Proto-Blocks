<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Definition;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\Modules\Redirects\Definition\GroupValidator;
use ProtoBlocks\Modules\Redirects\Definition\RuleNormalizer;
use ProtoBlocks\Modules\Redirects\Definition\Schema;

/**
 * The group schema, duplicate_rule and redirect_chain.
 */
final class GroupValidatorTest extends TestCase
{
    /** The spec's section 1 example, verbatim. */
    public const SPEC_EXAMPLE = <<<'JSONC'
// proto-redirects/legacy-site/redirects.jsonc
{
  "title": "Legacy site",        // shown in the UI; defaults to the slug
  "enabled": true,               // false disables every rule without touching their own "enabled"
  "position": 10,                // groups evaluate in ascending position, then slug; default 10
  "defaults": {                  // per-group defaults for the rule flags below (optional)
    "ignoreCase": true,
    "ignoreTrailingSlash": true,
    "query": "exact"
  },
  "rules": [
    { "from": "/about-us", "to": "/about" },
    { "id": "r_8f3a2c1d", "title": "Old blog", "from": "^/blog/(\\d+)/(.*)$", "regex": true, "to": "/news/$2", "code": 301 },
    { "from": "/promo", "to": "/sale", "code": 302, "query": "pass" },
    { "from": "/members", "when": { "type": "login" }, "to": "/dashboard", "else": "/login" },
    { "from": "/gone-forever", "action": "error", "code": 410 },
    { "from": "/old-api", "action": "pass", "to": "/wp-json/v1/thing" },
    { "from": "/ignore-me", "action": "nothing" },
    { "from": "/landing", "when": { "type": "referrer", "value": "google\\.", "regex": true }, "to": "/landing-google" },
    { "when": { "type": "404" }, "to": "/" , "enabled": false }
  ]
}
JSONC;

    protected function setUp(): void
    {
        $GLOBALS['pb_test_home_url'] = 'https://example.test';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pb_test_home_url']);
    }

    /** @return list<string> */
    private static function codes(array $findings): array
    {
        return array_map(static fn(array $f): string => $f['code'] . '@' . $f['path'], $findings);
    }

    public function testSpecExampleIsValid(): void
    {
        $result = GroupValidator::validate(Jsonc::decode(self::SPEC_EXAMPLE), [], 'legacy-site');

        self::assertSame([], $result['errors']);
        self::assertSame([], $result['warnings']);
    }

    public function testSchemaRefusesUnknownKeysTypesAndLimits(): void
    {
        $check = static fn(array $data): array => self::codes(GroupValidator::validate($data)['errors']);

        self::assertSame(['schema_invalid@/colour'], $check(['colour' => 'red']));
        self::assertSame(['schema_invalid@/rules/0/form'], $check(['rules' => [['form' => '/a', 'from' => '/a', 'to' => '/b']]]));
        self::assertSame(['schema_invalid@/title'], $check(['title' => '<b>x</b>']));
        self::assertSame(['schema_invalid@/rules/0/title'], $check(['rules' => [['from' => '/a', 'to' => '/b', 'title' => 'a<script>']]]));
        self::assertSame(['schema_invalid@/rules/0/title'], $check(['rules' => [['from' => '/a', 'to' => '/b', 'title' => str_repeat('t', 201)]]]));
        self::assertSame(['schema_invalid@/rules/0/from'], $check(['rules' => [['from' => '/' . str_repeat('a', 2000), 'to' => '/b']]]));
        self::assertSame(['schema_invalid@/rules/0/to'], $check(['rules' => [['from' => '/a', 'to' => '/' . str_repeat('a', 2000)]]]));
        self::assertSame(['schema_invalid@/rules/0/id'], $check(['rules' => [['id' => 'r_XYZ', 'from' => '/a', 'to' => '/b']]]));
        self::assertSame(['schema_invalid@/rules/0/action'], $check(['rules' => [['from' => '/a', 'action' => 'random']]]));
        self::assertSame(['schema_invalid@/rules/0/query'], $check(['rules' => [['from' => '/a', 'to' => '/b', 'query' => 'loose']]]));
        self::assertSame(['schema_invalid@/rules/0/code'], $check(['rules' => [['from' => '/a', 'to' => '/b', 'code' => '301']]]));
        self::assertSame(['schema_invalid@/rules/0/when/value'], $check(['rules' => [['from' => '/a', 'to' => '/b', 'when' => ['type' => 'agent', 'value' => str_repeat('a', 501)]]]]));
        self::assertSame(['schema_invalid@/rules/0/when/type'], $check(['rules' => [['from' => '/a', 'to' => '/b', 'when' => []]]]));
        self::assertSame(['schema_invalid@/position'], $check(['position' => 'first']));
        self::assertSame(['schema_invalid@/defaults/query'], $check(['defaults' => ['query' => 'nope']]));
        self::assertSame(['schema_invalid@/rules'], $check(['rules' => ['a' => 1]]));
        self::assertSame([], $check([]));
    }

    public function testEveryRuleCodeSurfacesThroughTheGroup(): void
    {
        $result = GroupValidator::validate(['rules' => [
            ['from' => '^/(', 'regex' => true, 'to' => '/x'],
            ['from' => 'blog', 'regex' => true, 'to' => '/x'],
            ['from' => '/a', 'to' => 'javascript:alert(1)'],
            ['from' => '/b', 'to' => '/c', 'code' => 200],
            ['from' => '/d', 'to' => '/d'],
            ['from' => 'https://other.test/e', 'to' => '/f'],
            ['from' => '/g', 'to' => '/h', 'headers' => ['Location' => '/x']],
        ]], [], 'g');

        self::assertSame([
            'invalid_regex@/rules/0/from',
            'invalid_target@/rules/2/to',
            'invalid_code@/rules/3/code',
            'self_redirect@/rules/4/to',
            'use_when_server@/rules/5/from',
            'invalid_header@/rules/6/headers/Location',
        ], self::codes($result['errors']));
        self::assertSame(['unanchored_regex@/rules/1/from'], self::codes($result['warnings']));
    }

    public function testDuplicateRuleForExplicitAndDerivedIds(): void
    {
        $derived = GroupValidator::validate(['rules' => [
            ['from' => '/a', 'to' => '/b'],
            ['from' => '/a', 'to' => '/c'],
        ]], [], 'g');
        self::assertSame(['duplicate_rule@/rules/1/id'], self::codes($derived['errors']));

        $explicit = GroupValidator::validate(['rules' => [
            ['id' => 'r_00000001', 'from' => '/a', 'to' => '/b'],
            ['id' => 'r_00000001', 'from' => '/x', 'to' => '/y'],
        ]], [], 'g');
        self::assertSame(['duplicate_rule@/rules/1/id'], self::codes($explicit['errors']));

        // Same source with different conditions derive different ids.
        $ok = GroupValidator::validate(['rules' => [
            ['from' => '/a', 'to' => '/b'],
            ['from' => '/a', 'to' => '/c', 'when' => ['type' => 'login']],
        ]], [], 'g');
        self::assertSame([], $ok['errors']);
    }

    public function testChainWithinTheGroupAndAcrossGroups(): void
    {
        $result = GroupValidator::validate(['rules' => [
            ['from' => '/a', 'to' => '/b'],
            ['from' => '/B/', 'to' => '/c'],
            ['from' => '/c', 'to' => 'https://example.test/d'],
            ['from' => '/x', 'to' => '/y', 'enabled' => false],
            ['from' => '/y', 'to' => '/z'],
        ]], [], 'g');
        self::assertSame(['redirect_chain@/rules/0/to', 'redirect_chain@/rules/1/to'], self::codes($result['warnings']));
        self::assertStringContainsString('/a to /b', $result['warnings'][0]['message']);

        $other = RuleNormalizer::normalize(['from' => '/d', 'to' => '/e'], [], 'other') + ['group' => 'other'];
        $result = GroupValidator::validate(['rules' => [['from' => '/c', 'to' => '/d']]], [$other], 'g');
        self::assertSame(['redirect_chain@/rules/0/to'], self::codes($result['warnings']));
        self::assertStringContainsString('(other)', $result['warnings'][0]['message']);

        // A disabled group reports no chains; regex rules are not chained.
        self::assertSame([], GroupValidator::validate(['enabled' => false, 'rules' => [['from' => '/c', 'to' => '/d']]], [$other], 'g')['warnings']);
        self::assertSame([], GroupValidator::validate(['rules' => [['from' => '^/c$', 'regex' => true, 'to' => '/d']]], [$other], 'g')['warnings']);
    }

    public function testAnalyseKeepsValidRulesAndFlagsTheGroupLevel(): void
    {
        $result = GroupValidator::analyse(['defaults' => ['ignoreCase' => false], 'rules' => [
            ['from' => '/a', 'to' => '/b'],
            ['from' => '/c', 'to' => 'ftp://x'],
            ['from' => '/d', 'to' => '/e', 'extra' => 1],
        ]], 'g', ['query' => 'ignore']);
        self::assertTrue($result['groupValid']);
        self::assertSame([0], array_keys($result['rules']));
        self::assertSame([1 => true, 2 => true], $result['invalid']);
        self::assertFalse($result['rules'][0]['ignoreCase']);
        self::assertSame('ignore', $result['rules'][0]['query']);

        self::assertFalse(GroupValidator::analyse(['title' => 5, 'rules' => [['from' => '/a', 'to' => '/b']]], 'g', [])['groupValid']);
    }

    public function testSlugPattern(): void
    {
        foreach (['legacy-site', 'a', 'a_b', str_repeat('a', 60), 'settings-old'] as $ok) {
            self::assertSame(1, preg_match(GroupValidator::SLUG_PATTERN, $ok), $ok);
        }
        foreach (['settings', 'Legacy', '1a', '-a', str_repeat('a', 61), "a\n", '../a', 'a.b', ''] as $bad) {
            self::assertSame(0, preg_match(GroupValidator::SLUG_PATTERN, $bad), $bad);
        }
    }

    public function testSchemaFileLoads(): void
    {
        $schema = Schema::get();
        foreach (['group', 'rule', 'when', 'settings'] as $def) {
            self::assertArrayHasKey($def, $schema['$defs']);
        }
    }
}
