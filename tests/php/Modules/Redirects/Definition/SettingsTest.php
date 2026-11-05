<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Definition;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\Modules\Redirects\Definition\Settings;
use ProtoBlocks\Modules\Redirects\Definition\SettingsValidator;

/**
 * Settings defaults, merge clamping and SettingsValidator codes.
 */
final class SettingsTest extends TestCase
{
    public function testMonitorGroupCannotBeAReservedSlug(): void
    {
        foreach (['php', 'settings'] as $reserved) {
            self::assertSame('modified-posts', Settings::merge(['monitor' => ['group' => $reserved]])['monitor']['group'], $reserved);
        }
        self::assertSame('php-moves', Settings::merge(['monitor' => ['group' => 'php-moves']])['monitor']['group']);
    }

    /** The spec's settings.jsonc example. */
    private const SPEC_EXAMPLE = <<<'JSONC'
// proto-redirects/settings.jsonc — every key optional
{
  "defaults": { "ignoreCase": true, "ignoreTrailingSlash": true, "query": "exact" },
  "log": {
    "redirects": 7,
    "notFound": 7,
    "ip": "none",
    "headers": false,
    "external": false,
    "trustedProxies": [],
    "ipHeaders": []
  },
  "trackHits": true,
  "cache": 1,
  "monitor": { "postTypes": [], "group": "modified-posts", "trash": false, "associated": "" },
  "headers": { "site": { "X-Content-Type-Options": "nosniff" }, "redirect": {} },
  "canonical": { "https": false, "www": "", "aliases": [], "relocate": "" },
  "coexist": false
}
JSONC;

    /** @return list<string> */
    private static function codes(array $data): array
    {
        return array_map(static fn(array $f): string => $f['code'] . '@' . $f['path'], SettingsValidator::validate($data)['errors']);
    }

    public function testDefaultsHaveEveryKey(): void
    {
        $defaults = Settings::defaults();
        self::assertSame(['defaults', 'log', 'trackHits', 'cache', 'monitor', 'headers', 'canonical', 'coexist'], array_keys($defaults));
        self::assertSame(7, $defaults['log']['redirects']);
        self::assertSame('none', $defaults['log']['ip']);
        self::assertSame(1, $defaults['cache']);
        self::assertSame('modified-posts', $defaults['monitor']['group']);
        self::assertFalse($defaults['coexist']);
        self::assertSame($defaults, Settings::merge([]));
    }

    public function testSpecExampleValidatesAndMerges(): void
    {
        $data = Jsonc::decode(self::SPEC_EXAMPLE);
        self::assertSame([], SettingsValidator::validate($data)['errors']);
        $merged = Settings::merge($data);
        self::assertSame(['X-Content-Type-Options' => 'nosniff'], $merged['headers']['site']);
    }

    public function testLogDaysAreClampedToMinusOneThroughNinety(): void
    {
        self::assertSame(-1, Settings::merge(['log' => ['redirects' => -5]])['log']['redirects']);
        self::assertSame(90, Settings::merge(['log' => ['redirects' => 365]])['log']['redirects']);
        self::assertSame(0, Settings::merge(['log' => ['notFound' => 0]])['log']['notFound']);
        self::assertSame(-1, Settings::merge(['log' => ['notFound' => -1]])['log']['notFound']);
        self::assertSame(30, Settings::merge(['log' => ['notFound' => 30.0]])['log']['notFound']);
        self::assertSame(7, Settings::merge(['log' => ['redirects' => '30']])['log']['redirects'], 'wrong type keeps the default');
        self::assertSame(['schema_invalid@/log/redirects'], self::codes(['log' => ['redirects' => 91]]));
        self::assertSame(['schema_invalid@/log/notFound'], self::codes(['log' => ['notFound' => -2]]));
    }

    public function testCacheIsAnEnum(): void
    {
        foreach ([-1, 0, 1, 24, 168] as $hours) {
            self::assertSame($hours, Settings::merge(['cache' => $hours])['cache']);
            self::assertSame([], self::codes(['cache' => $hours]));
        }
        self::assertSame(1, Settings::merge(['cache' => 48])['cache']);
        self::assertSame(1, Settings::merge(['cache' => '24'])['cache']);
        self::assertSame(['schema_invalid@/cache'], self::codes(['cache' => 48]));
    }

    public function testAliasesAreHostOnlyAndCappedAtTwenty(): void
    {
        $merged = Settings::merge(['canonical' => ['aliases' => ['Old.Example.test', 'old.example.test', 'https://x.test', 'x.test:8080', 'x.test/path', 'ok.test']]]);
        self::assertSame(['old.example.test', 'ok.test'], $merged['canonical']['aliases']);

        $many = array_map(static fn(int $i): string => "a$i.test", range(1, 25));
        self::assertCount(20, Settings::merge(['canonical' => ['aliases' => $many]])['canonical']['aliases']);
        self::assertSame(['schema_invalid@/canonical/aliases'], self::codes(['canonical' => ['aliases' => $many]]));
        self::assertSame(['invalid_host@/canonical/aliases/0', 'invalid_host@/canonical/aliases/1'], self::codes(['canonical' => ['aliases' => ['https://x.test', 'x.test:80']]]));
    }

    public function testRelocateMustBeAnAbsoluteUrl(): void
    {
        self::assertSame('https://new.example.test', Settings::merge(['canonical' => ['relocate' => 'https://new.example.test']])['canonical']['relocate']);
        self::assertSame('https://new.example.test/site', Settings::merge(['canonical' => ['relocate' => 'https://new.example.test/site']])['canonical']['relocate']);
        foreach (['/relative', 'ftp://x.test', 'https://u:p@x.test', 'https://x.test/?a=1', 'https://x.test/#f', "https://x.test/\n", 'javascript:alert(1)'] as $bad) {
            self::assertSame('', Settings::merge(['canonical' => ['relocate' => $bad]])['canonical']['relocate'], $bad);
            self::assertSame(['invalid_url@/canonical/relocate'], self::codes(['canonical' => ['relocate' => $bad]]), $bad);
        }
        self::assertSame([], self::codes(['canonical' => ['relocate' => '']]));
    }

    public function testHeadersAndProxiesAndOtherKeys(): void
    {
        $merged = Settings::merge([
            'headers' => ['site' => ['X-Frame-Options' => 'DENY', 'Set-Cookie' => 'a=1', 'X-Bad' => "a\nb"], 'redirect' => ['X-Robots-Tag' => 'noindex']],
            'log' => ['trustedProxies' => ['10.0.0.1', 'nope', '10.0.0.0/8', '10.0.0.1'], 'ipHeaders' => ['x-forwarded-for', 'X-Evil', 'CF-Connecting-IP'], 'ip' => 'masked'],
            'monitor' => ['postTypes' => ['post', 'Bad Type', 'page'], 'group' => 'settings', 'associated' => '/feed/'],
            'canonical' => ['www' => 'nowww', 'https' => true],
            'trackHits' => false,
            'coexist' => true,
            'unknown' => 1,
        ]);
        self::assertSame(['X-Frame-Options' => 'DENY'], $merged['headers']['site']);
        self::assertSame(['X-Robots-Tag' => 'noindex'], $merged['headers']['redirect']);
        self::assertSame(['10.0.0.1', '10.0.0.0/8'], $merged['log']['trustedProxies']);
        self::assertSame(['X-Forwarded-For', 'CF-Connecting-IP'], $merged['log']['ipHeaders']);
        self::assertSame('masked', $merged['log']['ip']);
        self::assertSame(['post', 'page'], $merged['monitor']['postTypes']);
        self::assertSame('modified-posts', $merged['monitor']['group'], 'the reserved slug is refused');
        self::assertSame('/feed/', $merged['monitor']['associated']);
        self::assertSame('nowww', $merged['canonical']['www']);
        self::assertTrue($merged['canonical']['https']);
        self::assertFalse($merged['trackHits']);
        self::assertTrue($merged['coexist']);
        self::assertArrayNotHasKey('unknown', $merged);
    }

    public function testValidatorCodes(): void
    {
        self::assertSame(['schema_invalid@/unknown'], self::codes(['unknown' => 1]));
        self::assertSame(['schema_invalid@/log/colour'], self::codes(['log' => ['colour' => 1]]));
        self::assertSame(['schema_invalid@/log/ip'], self::codes(['log' => ['ip' => 'partial']]));
        self::assertSame(['schema_invalid@/log/ipHeaders/0'], self::codes(['log' => ['ipHeaders' => ['X-Evil']]]));
        self::assertSame(['invalid_ip@/log/trustedProxies/0'], self::codes(['log' => ['trustedProxies' => ['proxy.local']]]));
        self::assertSame(['invalid_header@/headers/site/Location'], self::codes(['headers' => ['site' => ['Location' => '/x']]]));
        self::assertSame(['invalid_header@/headers/redirect/X-A'], self::codes(['headers' => ['redirect' => ['X-A' => "1\r\n2"]]]));
        self::assertSame(['schema_invalid@/monitor/group'], self::codes(['monitor' => ['group' => 'settings']]));
        self::assertSame(['schema_invalid@/canonical/www'], self::codes(['canonical' => ['www' => 'yes']]));
        self::assertSame(['schema_invalid@/coexist'], self::codes(['coexist' => 'yes']));
    }
}
