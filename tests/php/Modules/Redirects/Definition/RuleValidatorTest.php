<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Definition;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Redirects\Definition\Patterns;
use ProtoBlocks\Modules\Redirects\Definition\RuleNormalizer;
use ProtoBlocks\Modules\Redirects\Definition\RuleValidator;

/**
 * Rule normalisation and every rule-level validation code (spec 1-3).
 */
final class RuleValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pb_test_home_url'] = 'https://example.test';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pb_test_home_url']);
    }

    /** @param array<string, mixed> $raw */
    private static function rule(array $raw, array $defaults = []): array
    {
        return RuleNormalizer::normalize($raw, $defaults, 'legacy');
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<string> "code@path" of every finding
     */
    private static function codes(array $raw): array
    {
        return array_map(
            static fn(array $f): string => $f['code'] . '@' . $f['path'],
            RuleValidator::validate(self::rule($raw))
        );
    }

    public function testNormalisesDefaults(): void
    {
        $rule = self::rule(['from' => '/old', 'to' => '/new']);

        self::assertSame([
            'id' => $rule['id'],
            'title' => '',
            'enabled' => true,
            'from' => '/old',
            'regex' => false,
            'to' => '/new',
            'else' => null,
            'code' => 301,
            'action' => 'url',
            'when' => null,
            'query' => 'exact',
            'ignoreCase' => true,
            'ignoreTrailingSlash' => true,
            'log' => true,
            'headers' => [],
        ], $rule);
        self::assertMatchesRegularExpression('/^r_[a-f0-9]{8}$/D', $rule['id']);
        self::assertSame([], RuleValidator::validate($rule));
    }

    public function testDefaultCodesPerAction(): void
    {
        self::assertSame(404, self::rule(['from' => '/a', 'action' => 'error'])['code']);
        self::assertNull(self::rule(['from' => '/a', 'action' => 'pass', 'to' => '/b'])['code']);
        self::assertNull(self::rule(['from' => '/a', 'action' => 'nothing'])['code']);
        self::assertSame('url', self::rule(['from' => '/a', 'action' => 'bogus', 'to' => '/b'])['action']);
    }

    public function testGroupDefaultsApplyAndRuleFlagsWin(): void
    {
        $defaults = ['ignoreCase' => false, 'query' => 'ignore'];
        $rule = self::rule(['from' => '/a', 'to' => '/b'], $defaults);
        self::assertFalse($rule['ignoreCase']);
        self::assertTrue($rule['ignoreTrailingSlash']);
        self::assertSame('ignore', $rule['query']);

        $rule = self::rule(['from' => '/a', 'to' => '/b', 'ignoreCase' => true, 'query' => 'pass'], $defaults);
        self::assertTrue($rule['ignoreCase']);
        self::assertSame('pass', $rule['query']);
    }

    public function testNormaliserIsIdempotent(): void
    {
        foreach ([
            ['from' => 'https://example.test/old?x=1', 'to' => '/new'],
            ['from' => '/members', 'when' => ['type' => 'header', 'name' => 'x-country', 'value' => 'PT'], 'to' => '/pt', 'else' => '/en'],
            ['when' => ['type' => 'ip', 'value' => '10.0.0.0/8'], 'action' => 'error', 'code' => 403],
            ['from' => '^/blog/(.*)$', 'regex' => true, 'to' => '/news/$1', 'headers' => ['X-A' => 'b']],
        ] as $raw) {
            $once = self::rule($raw);
            self::assertSame($once, self::rule($once));
        }
    }

    public function testIdDerivationIsStableAndDependsOnSourceConditionActionAndGroup(): void
    {
        $a = self::rule(['from' => '/a', 'to' => '/b']);
        self::assertSame($a['id'], self::rule(['from' => '/a', 'to' => '/somewhere-else', 'code' => 302])['id']);
        self::assertSame(
            'r_' . substr(sha1("legacy\0/a\0null\0url"), 0, 8),
            $a['id'],
            'the documented derivation'
        );
        self::assertNotSame($a['id'], self::rule(['from' => '/A', 'to' => '/b'])['id']);
        self::assertNotSame($a['id'], self::rule(['from' => '/a', 'to' => '/b', 'when' => ['type' => 'login']])['id']);
        self::assertNotSame($a['id'], self::rule(['from' => '/a', 'action' => 'nothing'])['id']);
        self::assertNotSame($a['id'], RuleNormalizer::normalize(['from' => '/a', 'to' => '/b'], [], 'other')['id']);
        self::assertSame('r_0123abcd', self::rule(['id' => 'r_0123abcd', 'from' => '/a', 'to' => '/b'])['id']);
    }

    public function testSameHostAbsoluteSourceIsReducedToItsPath(): void
    {
        self::assertSame('/old', self::rule(['from' => 'https://example.test/old', 'to' => '/new'])['from']);
        self::assertSame('/old?a=1', self::rule(['from' => 'http://EXAMPLE.test:8080/old?a=1', 'to' => '/new'])['from']);
        self::assertSame('/', self::rule(['from' => 'https://example.test', 'to' => '/new'])['from']);
        self::assertSame('/?p=1', self::rule(['from' => 'https://example.test?p=1', 'to' => '/new'])['from']);
        self::assertSame([], self::codes(['from' => 'https://example.test/old', 'to' => '/new']));
        // A regex source is never reduced.
        self::assertSame('https://example.test/x', self::rule(['from' => 'https://example.test/x', 'regex' => true, 'to' => '/y'])['from']);
    }

    public function testOtherHostSourceSaysUseWhenServer(): void
    {
        self::assertSame(['use_when_server@/from'], self::codes(['from' => 'https://other.test/old', 'to' => '/new']));
        self::assertSame(['use_when_server@/from'], self::codes(['from' => 'https://user@example.test/old', 'to' => '/new']));
        self::assertStringContainsString('"server"', RuleValidator::validate(self::rule(['from' => 'https://other.test/old', 'to' => '/new']))[0]['message']);
    }

    public function testSourceRules(): void
    {
        self::assertSame(['missing_source@/from'], self::codes(['to' => '/new']));
        self::assertSame(['missing_source@/from'], self::codes(['from' => '', 'to' => '/new']));
        self::assertSame([], self::codes(['when' => ['type' => '404'], 'to' => '/']));
        self::assertSame(['invalid_source@/from'], self::codes(['from' => 'old', 'to' => '/new']));
        self::assertSame(['invalid_source@/from'], self::codes(['from' => "/old\nx", 'to' => '/new']));
        self::assertSame(['invalid_source@/from'], self::codes(['from' => 'ftp://example.test/a', 'to' => '/new']));
    }

    public function testUnicodeSourceIsAcceptedAsWritten(): void
    {
        $rule = self::rule(['from' => '/café', 'to' => '/coffee']);
        self::assertSame('/café', $rule['from']);
        self::assertSame([], RuleValidator::validate($rule));
        self::assertSame([], self::codes(['from' => '/caf%C3%A9', 'to' => '/coffee']));
        self::assertSame([], self::codes(['from' => '^/café/(.*)$', 'regex' => true, 'to' => '/coffee/$1']));
    }

    public function testEscapedQuestionMarkIsALiteralNotAQuerySeparator(): void
    {
        self::assertSame('/faq\?', self::rule(['from' => '/faq\?', 'to' => '/help'])['from']);
        self::assertSame('/faq\?x', self::rule(['from' => 'https://example.test/faq\?x', 'to' => '/help'])['from']);
        self::assertSame([], self::codes(['from' => '/faq\?', 'to' => '/help']));
        self::assertSame([], self::codes(['from' => '^/page\?id=1$', 'regex' => true, 'to' => '/page-1']));
    }

    public function testRegexChecks(): void
    {
        self::assertSame(['invalid_regex@/from'], self::codes(['from' => '^/blog/(\d+$', 'regex' => true, 'to' => '/b']));
        $message = RuleValidator::validate(self::rule(['from' => '^/blog/(\d+$', 'regex' => true, 'to' => '/b']))[0]['message'];
        self::assertStringContainsString('missing closing parenthesis', $message, 'carries PHP\'s message');
        self::assertSame(['invalid_regex@/from'], self::codes(['from' => '^/' . str_repeat('a', 500), 'regex' => true, 'to' => '/b']));
        self::assertSame([], self::codes(['from' => '^/' . str_repeat('a', 498), 'regex' => true, 'to' => '/b']));
        self::assertSame(['unanchored_regex@/from'], self::codes(['from' => 'blog/(.*)', 'regex' => true, 'to' => '/news/$1']));
        self::assertSame('warning', RuleValidator::validate(self::rule(['from' => 'blog', 'regex' => true, 'to' => '/n']))[0]['level']);
        self::assertSame([], self::codes(['from' => '/blog$', 'regex' => true, 'to' => '/n']));
        // An escaped anchor is not an anchor.
        self::assertSame(['unanchored_regex@/from'], self::codes(['from' => '/price\$', 'regex' => true, 'to' => '/n']));
        // The delimiter inside the body cannot end the pattern early.
        self::assertSame([], self::codes(['from' => '^/a~b/(.*)$', 'regex' => true, 'to' => '/c']));
        self::assertSame(['invalid_regex@/from'], self::codes(['from' => '^/a\\', 'regex' => true, 'to' => '/c']));
        // No modifier smuggling through the delimiter.
        self::assertSame([], self::codes(['from' => '^/x~e', 'regex' => true, 'to' => '/c']));
        self::assertSame('~^/x\\~e~su', Patterns::compile('^/x~e', false)[0]);
    }

    public function testRegexCompilesWithSAndOptionalIAndU(): void
    {
        self::assertSame('~^/a$~su', Patterns::compile('^/a$', false)[0]);
        self::assertSame('~^/a$~siu', Patterns::compile('^/a$', true)[0]);
        self::assertSame('~^/caf\x{e9}$~su', Patterns::compile('^/caf\x{e9}$', false)[0]);
        // Invalid UTF-8 compiles without u.
        self::assertSame("~^/\xff$~s", Patterns::compile("^/\xff$", false)[0]);
        self::assertSame('~^/a\~b$~su', Patterns::compile('^/a~b$', false)[0]);
    }

    public function testTargetRules(): void
    {
        self::assertSame(['invalid_target@/to'], self::codes(['from' => '/a']));
        self::assertSame([], self::codes(['from' => '/a', 'to' => 'https://other.test/x']));
        foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.test/x', '/\\evil.test', 'evil.test/x', 'https://', 'https://a@evil.test/', "/x\r\nSet-Cookie: a", '$1'] as $bad) {
            self::assertSame(['invalid_target@/to'], self::codes(['from' => '/a', 'to' => $bad]), $bad);
        }
        self::assertSame(['invalid_target@/to'], self::codes(['from' => '/a', 'action' => 'pass', 'to' => 'https://example.test/x']));
        self::assertSame(['invalid_target@/to'], self::codes(['from' => '/a', 'action' => 'pass']));
        self::assertSame([], self::codes(['from' => '/a', 'action' => 'pass', 'to' => '/wp-json/v1/thing']));
        self::assertSame(['invalid_target@/to'], self::codes(['from' => '/a', 'action' => 'error', 'code' => 410, 'to' => '/b']));
        self::assertSame(['invalid_target@/to'], self::codes(['from' => '/a', 'action' => 'nothing', 'to' => '/b']));
        self::assertSame([], self::codes(['from' => '^/(.*)$', 'regex' => true, 'to' => '/new/$1']));
        self::assertSame([], self::codes(['from' => '^/(.*)$', 'regex' => true, 'to' => 'https://new.test/${1}']));
    }

    public function testCodeRules(): void
    {
        foreach ([301, 302, 303, 307, 308] as $code) {
            self::assertSame([], self::codes(['from' => '/a', 'to' => '/b', 'code' => $code]), (string) $code);
        }
        foreach ([300, 304, 200, 404] as $code) {
            self::assertSame(['invalid_code@/code'], self::codes(['from' => '/a', 'to' => '/b', 'code' => $code]), (string) $code);
        }
        foreach ([400, 401, 403, 404, 410, 418, 451, 500, 501, 502, 503, 504] as $code) {
            self::assertSame([], self::codes(['from' => '/a', 'action' => 'error', 'code' => $code]), (string) $code);
        }
        foreach ([301, 405, 499, 505] as $code) {
            self::assertSame(['invalid_code@/code'], self::codes(['from' => '/a', 'action' => 'error', 'code' => $code]), (string) $code);
        }
        self::assertSame(['invalid_code@/code'], self::codes(['from' => '/a', 'action' => 'pass', 'to' => '/b', 'code' => 301]));
        self::assertSame(['invalid_code@/code'], self::codes(['from' => '/a', 'action' => 'nothing', 'code' => 301]));
    }

    public function testElseRules(): void
    {
        self::assertSame([], self::codes(['from' => '/m', 'when' => ['type' => 'login'], 'to' => '/dash', 'else' => '/login']));
        self::assertSame(['invalid_else@/else'], self::codes(['from' => '/m', 'to' => '/dash', 'else' => '/login']));
        self::assertSame(['invalid_else@/else'], self::codes(['from' => '/m', 'when' => ['type' => 'login'], 'action' => 'error', 'code' => 403, 'else' => '/login']));
        self::assertSame(['invalid_else@/else'], self::codes(['from' => '/m', 'when' => ['type' => 'login'], 'to' => '/d', 'else' => 'javascript:x']));
    }

    public function testSelfRedirect(): void
    {
        self::assertSame(['self_redirect@/to'], self::codes(['from' => '/a', 'to' => '/a']));
        self::assertSame(['self_redirect@/to'], self::codes(['from' => '/About/', 'to' => '/about']));
        self::assertSame(['self_redirect@/to'], self::codes(['from' => '/a', 'to' => 'https://example.test/a']));
        self::assertSame([], self::codes(['from' => '/About', 'to' => '/about', 'ignoreCase' => false]));
        self::assertSame([], self::codes(['from' => '/a/', 'to' => '/a', 'ignoreTrailingSlash' => false]));
        self::assertSame(['self_redirect@/else'], self::codes(['from' => '/a', 'when' => ['type' => 'login'], 'to' => '/b', 'else' => '/a']));
        // A regex rule is guarded at run time instead.
        self::assertSame([], self::codes(['from' => '^/a$', 'regex' => true, 'to' => '/a']));
        self::assertSame([], self::codes(['from' => '/a', 'to' => 'https://other.test/a']));
    }

    public function testHeaderRules(): void
    {
        self::assertSame([], self::codes(['from' => '/a', 'to' => '/b', 'headers' => ['X-Robots-Tag' => 'noindex']]));
        foreach ([['Set-Cookie', 'a=1'], ['location', '/x'], ['Status', '200'], ['Content-Length', '1'], ['Transfer-Encoding', 'chunked'], ['X Bad', 'v'], ['X-Ok', "a\r\nb"], ['X-Ok', str_repeat('a', 1001)]] as [$name, $value]) {
            $codes = self::codes(['from' => '/a', 'to' => '/b', 'headers' => [$name => $value]]);
            self::assertCount(1, $codes, $name);
            self::assertStringStartsWith('invalid_header@/headers/', $codes[0]);
        }
    }

    public function testConditionGrammar(): void
    {
        $ok = [
            ['type' => 'login'],
            ['type' => 'role', 'value' => 'edit_posts'],
            ['type' => 'referrer', 'value' => 'google\.', 'regex' => true],
            ['type' => 'agent', 'value' => 'Googlebot'],
            ['type' => 'header', 'name' => 'x-country', 'value' => 'PT'],
            ['type' => 'cookie', 'name' => 'wp_lang', 'value' => 'pt', 'regex' => false],
            ['type' => 'ip', 'value' => ['10.0.0.1', '192.168.0.0/16', '2001:db8::/32', '::1']],
            ['type' => 'language', 'value' => ['en', 'pt-BR']],
            ['type' => 'server', 'value' => 'shop.example.test'],
            ['type' => 'server', 'value' => 'bücher.example'],
            ['type' => 'filter', 'value' => 'my_theme_is_campaign'],
        ];
        foreach ($ok as $when) {
            self::assertSame([], self::codes(['from' => '/a', 'to' => '/b', 'when' => $when]), json_encode($when));
        }
        $bad = [
            ['type' => 'weather'],
            ['type' => 'login', 'value' => 'x'],
            ['type' => 'role'],
            ['type' => 'role', 'value' => 'edit posts!'],
            ['type' => 'referrer', 'value' => '(', 'regex' => true],
            ['type' => 'header', 'value' => 'PT'],
            ['type' => 'header', 'name' => 'x country', 'value' => 'PT'],
            ['type' => 'cookie', 'name' => 'a=b', 'value' => '1'],
            ['type' => 'agent', 'name' => 'x', 'value' => 'y'],
            ['type' => 'server', 'value' => 'example.test', 'regex' => true],
            ['type' => 'ip', 'value' => ['10.0.0.300']],
            ['type' => 'ip', 'value' => ['10.0.0.0/33']],
            ['type' => 'ip', 'value' => []],
            ['type' => 'language', 'value' => ['english language']],
            ['type' => 'server', 'value' => 'https://example.test'],
            ['type' => 'server', 'value' => 'example.test:8080'],
            ['type' => 'filter', 'value' => 'My\\Class::method'],
            ['type' => 'agent', 'value' => str_repeat('a', 501)],
        ];
        foreach ($bad as $when) {
            $codes = self::codes(['from' => '/a', 'to' => '/b', 'when' => $when]);
            self::assertNotSame([], $codes, json_encode($when));
            foreach ($codes as $code) {
                self::assertMatchesRegularExpression('~^(invalid_condition|invalid_regex)@/when/~', $code, json_encode($when));
            }
        }
    }

    public function testConditionNormalisation(): void
    {
        self::assertSame(['type' => 'login', 'value' => null, 'name' => null, 'regex' => false], self::rule(['from' => '/a', 'to' => '/b', 'when' => ['type' => 'login']])['when']);
        self::assertSame(['type' => 'ip', 'value' => ['10.0.0.1'], 'name' => null, 'regex' => false], self::rule(['from' => '/a', 'to' => '/b', 'when' => ['type' => 'ip', 'value' => '10.0.0.1']])['when']);
        self::assertSame('X-Country-Code', self::rule(['from' => '/a', 'to' => '/b', 'when' => ['type' => 'header', 'name' => 'x-COUNTRY-code', 'value' => 'PT']])['when']['name']);
        self::assertSame('shop.example.test', self::rule(['from' => '/a', 'to' => '/b', 'when' => ['type' => 'server', 'value' => 'Shop.Example.TEST']])['when']['value']);
    }
}
