<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Import;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Redirects\Import\Formats;
use ProtoBlocks\Modules\Redirects\Import\JsonFormat;

final class JsonFormatTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    /** A Redirection export with one item. */
    private static function one(array $item): array
    {
        $json = json_encode(['plugin' => ['version' => '5'], 'groups' => [['id' => 1, 'name' => 'G']], 'redirects' => [$item + ['group_id' => 1]]]);
        return (new JsonFormat())->parse((string) $json)[0];
    }

    public function testRedirectionExportGroups(): void
    {
        $groups = (new JsonFormat())->parseGroups(self::fixture('redirection-export.json'));

        $this->assertSame(['redirections', 'modified-posts', 'imported'], array_keys($groups));
        $this->assertSame(['title' => 'Redirections', 'enabled' => true, 'position' => 10], array_diff_key($groups['redirections'], ['rules' => 1]));
        $this->assertSame(['title' => 'Modified Posts', 'enabled' => false, 'position' => 3], array_diff_key($groups['modified-posts'], ['rules' => 1]));

        $rules = $groups['redirections']['rules'];
        $this->assertSame(['/first', '/second', '^/blog/(\d+)$', '/members', '/landing'], array_column($rules, 'from'), 'ordered by position then id');
        $this->assertSame([
            'title' => 'Old first', 'from' => '/first', 'regex' => false, 'to' => '/one', 'code' => 301, 'action' => 'url',
            'query' => 'exactOrder', 'ignoreCase' => false, 'ignoreTrailingSlash' => false, 'log' => false,
        ], $rules[0], 'string action_data upgraded; flag_case/flag_trailing/flag_query/log_exclude mapped');
        $this->assertSame(['enabled' => false, 'from' => '^/blog/(\d+)$', 'regex' => true, 'to' => '/news/$1', 'code' => 308, 'action' => 'url'], $rules[2], 'status disabled');
        $this->assertSame(['type' => 'login'], $rules[3]['when']);
        $this->assertSame('/dashboard', $rules[3]['to'], 'logged_in → to');
        $this->assertSame('/login', $rules[3]['else'], 'logged_out → else');
        $this->assertSame(['type' => 'referrer', 'value' => 'google\.', 'regex' => true], $rules[4]['when']);
        $this->assertSame('/landing-google', $rules[4]['to']);
        $this->assertArrayNotHasKey('else', $rules[4], 'empty url_notfrom → no else');

        $this->assertSame([['from' => '/gone', 'regex' => false, 'code' => 410, 'action' => 'error']], $groups['modified-posts']['rules'], 'random dropped from the group');
        $this->assertSame('/orphan', $groups['imported']['rules'][0]['from'], 'unknown group_id → imported');
    }

    public function testRedirectionExportRowsKeepTheirOrdinal(): void
    {
        $rows = (new JsonFormat())->parse(self::fixture('redirection-export.json'));
        $byLine = array_column($rows, null, 'line');
        $this->assertCount(8, $rows);
        $this->assertSame('unsupported_action', $byLine[7]['reason']);
        $this->assertSame('/second', $byLine[1]['rule']['from']);
    }

    public function testUrlFromAndUrlNotFromMapToToAndElse(): void
    {
        $row = self::one(['url' => '/x', 'action_type' => 'url', 'action_code' => 301, 'match_type' => 'agent', 'action_data' => ['agent' => 'iPhone', 'regex' => false, 'url_from' => '/m', 'url_notfrom' => '/d']]);
        $this->assertSame(['type' => 'agent', 'value' => 'iPhone', 'regex' => false], $row['rule']['when']);
        $this->assertSame('/m', $row['rule']['to']);
        $this->assertSame('/d', $row['rule']['else']);
    }

    public function testOnlyUrlNotFromIsUnsupported(): void
    {
        $row = self::one(['url' => '/x', 'action_type' => 'url', 'action_code' => 301, 'match_type' => 'referrer', 'action_data' => ['referrer' => 'a', 'url_from' => '', 'url_notfrom' => '/d']]);
        $this->assertSame('unsupported_condition', $row['reason']);
    }

    public function testLoginWithOnlyLoggedOutIsUnsupported(): void
    {
        $row = self::one(['url' => '/x', 'action_type' => 'url', 'action_code' => 301, 'match_type' => 'login', 'action_data' => ['logged_in' => '', 'logged_out' => '/login']]);
        $this->assertSame('unsupported_condition', $row['reason']);
    }

    public function testEveryConditionType(): void
    {
        $base = ['url' => '/x', 'action_type' => 'url', 'action_code' => 302];
        $cases = [
            ['header', ['name' => 'X-Test', 'value' => 'a', 'regex' => true], ['type' => 'header', 'name' => 'X-Test', 'value' => 'a', 'regex' => true]],
            ['cookie', ['name' => 'c', 'value' => '1', 'regex' => false], ['type' => 'cookie', 'name' => 'c', 'value' => '1', 'regex' => false]],
            ['ip', ['ip' => ['1.2.3.4', ' ::1 ']], ['type' => 'ip', 'value' => ['1.2.3.4', '::1']]],
            ['language', ['language' => 'en, de-DE'], ['type' => 'language', 'value' => ['en', 'de-DE']]],
            ['server', ['server' => 'https://Old.Example.com'], ['type' => 'server', 'value' => 'old.example.com']],
            ['role', ['role' => 'editor'], ['type' => 'role', 'value' => 'editor']],
            ['custom', ['filter' => 'my_filter'], ['type' => 'filter', 'value' => 'my_filter']],
        ];
        foreach ($cases as [$type, $data, $when]) {
            $row = self::one($base + ['match_type' => $type, 'action_data' => $data + ['url_from' => '/to']]);
            $this->assertSame('new', $row['status'], $type);
            $this->assertSame($when, $row['rule']['when'], $type);
        }
    }

    public function testPageMatchBecomesA404Condition(): void
    {
        $row = self::one(['url' => '', 'action_type' => 'url', 'action_code' => 301, 'match_type' => 'page', 'action_data' => ['page' => '404', 'url' => '/']]);
        $this->assertSame(['to' => '/', 'code' => 301, 'action' => 'url', 'when' => ['type' => '404']], array_diff_key($row['rule'], ['regex' => 1]));
    }

    public function testStringActionDataHoldingJsonIsDecoded(): void
    {
        $row = self::one(['url' => '/x', 'action_type' => 'url', 'action_code' => 301, 'match_type' => 'login', 'action_data' => '{"logged_in":"/in","logged_out":"/out"}']);
        $this->assertSame('/in', $row['rule']['to']);
        $this->assertSame('/out', $row['rule']['else']);
    }

    public function testSerializedPhpInAnUploadedFileIsNeverUnserialized(): void
    {
        $row = self::one(['url' => '/x', 'action_type' => 'url', 'action_code' => 301, 'match_type' => 'login', 'action_data' => 'a:1:{s:9:"logged_in";s:3:"/in";}']);
        $this->assertSame('missing_target', $row['reason']);
    }

    public function testFlagRegexIsUsedWhenTheRegexFieldIsAbsent(): void
    {
        $row = self::one(['url' => '^/a', 'match_data' => ['source' => ['flag_regex' => true]], 'action_type' => 'url', 'action_code' => 301, 'action_data' => ['url' => '/b']]);
        $this->assertTrue($row['rule']['regex']);
    }

    public function testCodesAreChecked(): void
    {
        $this->assertSame('invalid_code', self::one(['url' => '/a', 'action_type' => 'url', 'action_code' => 304, 'action_data' => ['url' => '/b']])['reason']);
        $this->assertSame('invalid_code', self::one(['url' => '/a', 'action_type' => 'error', 'action_code' => 301])['reason']);
        $pass = self::one(['url' => '/a', 'action_type' => 'pass', 'action_code' => 0, 'action_data' => ['url' => '/internal']]);
        $this->assertSame(['from' => '/a', 'regex' => false, 'to' => '/internal', 'action' => 'pass'], $pass['rule']);
        $nothing = self::one(['url' => '/a', 'action_type' => 'nothing', 'action_code' => 0]);
        $this->assertSame(['from' => '/a', 'regex' => false, 'action' => 'nothing'], $nothing['rule']);
    }

    public function testUnsafeTargetIsInvalid(): void
    {
        $this->assertSame('invalid_target', self::one(['url' => '/a', 'action_type' => 'url', 'action_code' => 301, 'action_data' => ['url' => 'javascript:alert(1)']])['reason']);
    }

    public function testProtoBlocksExport(): void
    {
        $format = new JsonFormat();
        $text = self::fixture('protoblocks-export.json');
        $groups = $format->parseGroups($text);

        $this->assertSame(['legacy-site', 'bad-slug'], array_keys($groups));
        $this->assertSame('Legacy site', $groups['legacy-site']['title']);
        $this->assertSame(5, $groups['legacy-site']['position']);
        $this->assertSame(['ignoreCase' => false], $groups['legacy-site']['defaults']);
        $this->assertSame(['id' => 'r_8f3a2c1d', 'from' => '/about-us', 'to' => '/about'], $groups['legacy-site']['rules'][0], 'unknown keys dropped');
        $this->assertSame(['enabled' => false, 'to' => '/', 'when' => ['type' => '404']], $groups['legacy-site']['rules'][2]);
        $this->assertCount(3, $groups['legacy-site']['rules']);
        $this->assertSame('bad-slug', array_keys($groups)[1], 'invalid slug key re-slugged');

        $rows = $format->parse($text);
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($rows, 'line'));
        $this->assertSame('not_an_object', $rows[3]['reason']);
        $this->assertSame('empty_source', $rows[4]['reason']);

        $this->assertSame(['trackHits' => false], $format->parseSettings($text));
    }

    public function testSingleGroupFile(): void
    {
        $groups = (new JsonFormat())->parseGroups('{"title":"One","rules":[{"from":"/a","to":"/b"}]}');
        $this->assertSame(['imported'], array_keys($groups));
        $this->assertSame('One', $groups['imported']['title']);
    }

    public function testBrokenAndUnknownInput(): void
    {
        $format = new JsonFormat();
        $this->assertSame('invalid_json', $format->parse('{"groups": ')[0]['reason']);
        $this->assertSame(0, $format->parse('{"groups": ')[0]['line']);
        $this->assertSame('invalid_json', $format->parse('[1,2]')[0]['reason'], 'root must be an object');
        $this->assertSame('unknown_shape', $format->parse('{"foo": 1}')[0]['reason']);
        $this->assertSame('invalid_json', $format->parse('{"__proto__": {}}')[0]['reason'], 'prototype keys refused');
        $this->assertSame([], $format->parseGroups('{"foo": 1}'));
        $this->assertNull($format->parseSettings('nope'));
        $this->assertSame([], $format->parse('{"groups": {}}'));
    }

    public function testTooLarge(): void
    {
        $rows = (new JsonFormat())->parse('{' . str_repeat(' ', Formats::MAX_BYTES) . '}');
        $this->assertSame('too_large', $rows[0]['reason']);
    }

    public function testDetect(): void
    {
        $json = new JsonFormat();
        $this->assertTrue($json->detect('export.json', ''));
        $this->assertTrue($json->detect('', "\xEF\xBB\xBF  {\"groups\":{}}"));
        $this->assertFalse($json->detect('rules.csv', '{}'));
        $this->assertFalse($json->detect('', '/a,/b'));
    }
}
