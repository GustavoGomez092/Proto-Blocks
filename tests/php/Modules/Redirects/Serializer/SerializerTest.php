<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Serializer;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\Modules\Redirects\Definition\GroupValidator;
use ProtoBlocks\Modules\Redirects\Definition\Settings;
use ProtoBlocks\Modules\Redirects\Definition\SettingsValidator;
use ProtoBlocks\Modules\Redirects\Registry;
use ProtoBlocks\Modules\Redirects\Serializer;
use ProtoBlocks\Tests\Modules\Redirects\Definition\GroupValidatorTest;

require_once dirname(__DIR__) . '/Definition/GroupValidatorTest.php';

/**
 * Stable server-side JSONC (spec section 10).
 */
final class SerializerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pb_test_home_url'] = 'https://example.test';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pb_test_home_url']);
    }

    public function testGroupOutputShape(): void
    {
        $text = Serializer::group([
            'slug' => 'legacy-site',
            'title' => 'Legacy site',
            'rules' => [
                ['from' => '/about-us', 'to' => '/about'],
                ['id' => 'r_8f3a2c1d', 'title' => 'Old blog', 'from' => '^/blog/(\d+)/(.*)$', 'regex' => true, 'to' => '/news/$2', 'code' => 301],
                ['from' => '/gone', 'action' => 'error', 'code' => 410, 'log' => false],
                ['from' => '/m', 'when' => ['type' => 'header', 'name' => 'x-country', 'value' => 'PT'], 'to' => '/pt', 'else' => '/en', 'headers' => ['X-Robots-Tag' => 'noindex']],
            ],
        ]);

        $id = \ProtoBlocks\Modules\Redirects\Definition\RuleNormalizer::deriveId('legacy-site', '/about-us', null, 'url');
        $mId = \ProtoBlocks\Modules\Redirects\Definition\RuleNormalizer::normalize(['from' => '/m', 'when' => ['type' => 'header', 'name' => 'x-country', 'value' => 'PT']], [], 'legacy-site')['id'];
        $expected = <<<JSONC
// Managed by Proto-Blocks. Edit by hand or in wp-admin → Proto-Blocks → Redirects.
// Hand-written comments inside the rules array survive UI edits but not CLI or monitor writes.
{
  "title": "Legacy site",
  "enabled": true,
  "position": 10,
  "rules": [
    { "id": "$id", "from": "/about-us", "to": "/about" },
    { "id": "r_8f3a2c1d", "title": "Old blog", "from": "^/blog/(\\\\d+)/(.*)$", "regex": true, "to": "/news/$2" },
    { "id": "r_%s", "from": "/gone", "code": 410, "action": "error", "log": false },
    { "id": "$mId", "from": "/m", "to": "/pt", "else": "/en", "when": { "type": "header", "name": "X-Country", "value": "PT" }, "headers": { "X-Robots-Tag": "noindex" } }
  ]
}

JSONC;
        $goneId = \ProtoBlocks\Modules\Redirects\Definition\RuleNormalizer::deriveId('legacy-site', '/gone', null, 'error');
        self::assertSame(sprintf($expected, substr($goneId, 2)), $text);
    }

    public function testFlagsAreOmittedWhenEqualToTheGroupDefault(): void
    {
        $text = Serializer::group([
            'slug' => 'g',
            'defaults' => ['ignoreCase' => false, 'query' => 'exact'],
            'rules' => [
                ['id' => 'r_00000001', 'from' => '/a', 'to' => '/b', 'ignoreCase' => false],
                ['id' => 'r_00000002', 'from' => '/c', 'to' => '/d', 'ignoreCase' => true, 'query' => 'pass', 'ignoreTrailingSlash' => true],
            ],
        ]);

        self::assertStringContainsString('  "defaults": { "ignoreCase": false },', $text, 'only keys differing from the inherited defaults');
        self::assertStringContainsString('{ "id": "r_00000001", "from": "/a", "to": "/b" }', $text);
        self::assertStringContainsString('{ "id": "r_00000002", "from": "/c", "to": "/d", "query": "pass", "ignoreCase": true }', $text);
    }

    public function testInheritedSiteDefaultsKeepMeaning(): void
    {
        // Site default ignoreCase false: a rule that is case-insensitive must say so.
        $site = ['ignoreCase' => false, 'ignoreTrailingSlash' => true, 'query' => 'exact'];
        $text = Serializer::group(['slug' => 'g', 'rules' => [['id' => 'r_00000001', 'from' => '/a', 'to' => '/b', 'ignoreCase' => true], ['id' => 'r_00000002', 'from' => '/c', 'to' => '/d']]], $site);

        self::assertStringNotContainsString('"defaults"', $text);
        self::assertStringContainsString('"to": "/b", "ignoreCase": true }', $text);
        self::assertStringContainsString('{ "id": "r_00000002", "from": "/c", "to": "/d" }', $text);

        $registry = Registry::fromArrays([['slug' => 'g'] + Jsonc::decode($text)], [], ['defaults' => ['ignoreCase' => false]]);
        self::assertSame([true, false], array_column($registry->rules(), 'ignoreCase'));
    }

    public function testRoundTripIsStable(): void
    {
        $inputs = [
            ['slug' => 'legacy-site'] + Jsonc::decode(GroupValidatorTest::SPEC_EXAMPLE),
            ['slug' => 'empty', 'enabled' => false, 'position' => -3],
            ['slug' => 'u', 'title' => 'Café “quotes” \\ slash </script>', 'rules' => [['from' => '/café', 'to' => '/coffee?x=1&y=2', 'when' => ['type' => 'ip', 'value' => ['10.0.0.0/8', '::1']]], ['from' => "/line\u{2028}sep", 'to' => '/x']]],
        ];
        foreach ($inputs as $group) {
            $once = Serializer::group($group);
            $decoded = Jsonc::decode($once);
            $twice = Serializer::group(['slug' => $group['slug']] + $decoded);
            self::assertSame($once, $twice, $group['slug']);
        }
    }

    public function testOutputOfTheSpecExampleValidatesAndKeepsEveryRule(): void
    {
        $group = ['slug' => 'legacy-site'] + Jsonc::decode(GroupValidatorTest::SPEC_EXAMPLE);
        $text = Serializer::group($group);
        $decoded = Jsonc::decode($text);

        self::assertSame([], GroupValidator::validate($decoded, [], 'legacy-site')['errors']);
        self::assertCount(9, $decoded['rules']);
        self::assertSame(9, substr_count($text, "\n    { "), 'one rule per line');
        self::assertStringNotContainsString("\t", $text);

        $before = Registry::fromArrays([$group], [], [])->rules();
        $after = Registry::fromArrays([['slug' => 'legacy-site'] + $decoded], [], [])->rules();
        self::assertSame($before, $after, 'same normalised rules, ids now explicit');
    }

    public function testNormalisedGroupFromTheRegistrySerialises(): void
    {
        $registry = Registry::fromArrays([['slug' => 'g', 'title' => 'G', 'rules' => [['from' => '/a', 'to' => '/b']]]], [], []);
        $text = Serializer::group($registry->group('g'));

        self::assertStringNotContainsString('"origin"', $text);
        self::assertStringNotContainsString('"source"', $text);
        self::assertStringNotContainsString('"index"', $text);
        self::assertStringNotContainsString('"group"', $text);
        self::assertStringNotContainsString('"defaults"', $text);
        self::assertSame([], GroupValidator::validate(Jsonc::decode($text))['errors']);
    }

    public function testEmptyGroup(): void
    {
        self::assertStringEndsWith("  \"position\": 10,\n  \"rules\": []\n}\n", Serializer::group(['slug' => 'g']));
        self::assertStringContainsString('"title": "g"', Serializer::group(['slug' => 'g']));
    }

    public function testSettingsOutput(): void
    {
        $text = Serializer::settings(['coexist' => true, 'cache' => 24, 'unknown' => 1, 'log' => ['ip' => 'masked', 'redirects' => 30], 'headers' => ['site' => ['X-Frame-Options' => 'DENY']]]);

        $expected = <<<'JSONC'
// Proto-Blocks redirect settings. Edit by hand or in wp-admin → Proto-Blocks → Redirects → Settings.
// Every key is optional. Comments in this file do not survive CLI writes.
{
  "log": {
    "redirects": 30,
    "ip": "masked"
  },
  "cache": 24,
  "headers": {
    "site": {
      "X-Frame-Options": "DENY"
    }
  },
  "coexist": true
}

JSONC;
        self::assertSame($expected, $text);
    }

    public function testSettingsRoundTripOfFullDefaults(): void
    {
        $full = Settings::merge(['canonical' => ['aliases' => ['old.test']], 'log' => ['trustedProxies' => ['10.0.0.1']]]);
        $once = Serializer::settings($full);
        $decoded = Jsonc::decode($once);

        self::assertSame($full, Settings::merge($decoded));
        self::assertSame([], SettingsValidator::validate($decoded)['errors']);
        self::assertSame($once, Serializer::settings($decoded));
        self::assertStringContainsString('"site": {}', $once);
        self::assertStringContainsString('"aliases": ["old.test"]', $once);
        self::assertSame("// Proto-Blocks redirect settings. Edit by hand or in wp-admin → Proto-Blocks → Redirects → Settings.\n// Every key is optional. Comments in this file do not survive CLI writes.\n{}\n", Serializer::settings([]));
    }
}
