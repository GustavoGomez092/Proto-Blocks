<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Engine;

use ProtoBlocks\Modules\Redirects\Engine\Matcher;
use ProtoBlocks\Modules\Redirects\Engine\Request;

final class MatcherTest extends EngineTestCase
{
    private static function m(array $rules): Matcher
    {
        return new Matcher($rules, self::settings());
    }

    private static function target(array $rules, string $url, bool $notFound = false, array $server = []): ?string
    {
        return self::m($rules)->match(self::req($url, $server), $notFound)['target'] ?? null;
    }

    public function testPlainMatch(): void
    {
        $match = self::m([self::rule(['from' => '/old', 'to' => '/new'])])->match(self::req('/old'));
        self::assertNotNull($match);
        self::assertSame('/new', $match['target']);
        self::assertSame('url', $match['action']);
        self::assertSame(301, $match['code']);
        self::assertSame('matched', $match['reason']);
    }

    public function testNoMatch(): void
    {
        self::assertNull(self::m([self::rule()])->match(self::req('/other')));
    }

    public function testIgnoreCaseAndTrailingSlashDefaults(): void
    {
        $rules = [self::rule(['from' => '/About-Us', 'to' => '/about'])];
        self::assertSame('/about', self::target($rules, '/about-us/'));
        self::assertSame('/about', self::target($rules, '/ABOUT-US'));
    }

    public function testCaseSensitiveRule(): void
    {
        $rules = [self::rule(['from' => '/About', 'ignoreCase' => false])];
        self::assertNull(self::target($rules, '/about'));
        self::assertSame('/new', self::target($rules, '/About'));
    }

    public function testTrailingSlashStrictRule(): void
    {
        $rules = [self::rule(['from' => '/a/', 'ignoreTrailingSlash' => false])];
        self::assertNull(self::target($rules, '/a'));
        self::assertSame('/new', self::target($rules, '/a/'));
        $rules = [self::rule(['from' => '/a', 'ignoreTrailingSlash' => false])];
        self::assertNull(self::target($rules, '/a/'));
    }

    public function testRootTrailingSlash(): void
    {
        $rules = [self::rule(['from' => '/', 'to' => '/home'])];
        self::assertSame('/home', self::target($rules, '/'));
        self::assertSame('/home', self::target($rules, ''));
        self::assertSame('/home', self::target($rules, '//'));
    }

    public function testEncodedSlashInRequest(): void
    {
        self::assertSame('/new', self::target([self::rule(['from' => '/a/b'])], '/a%2Fb'));
    }

    public function testDoubleSlashRequest(): void
    {
        self::assertSame('/new', self::target([self::rule(['from' => '/double/slash'])], '//double//slash'));
    }

    public function testUnicodeSourceAndRequest(): void
    {
        self::assertSame('/new', self::target([self::rule(['from' => '/café'])], '/caf%C3%A9'));
        self::assertSame('/new', self::target([self::rule(['from' => '/caf%C3%A9'])], '/café'));
        self::assertSame('/new', self::target([self::rule(['from' => '/CAFÉ'])], '/café'), 'unicode ignoreCase');
        self::assertNull(self::target([self::rule(['from' => '/CAFÉ', 'ignoreCase' => false])], '/café'));
    }

    public function testEscapedQuestionMarkInFrom(): void
    {
        $rules = [self::rule(['from' => '/what\?', 'to' => '/faq'])];
        self::assertSame('/faq', self::target($rules, '/what%3F'));
        self::assertNull(self::target($rules, '/what'));
    }

    public function testQueryInFrom(): void
    {
        $rules = [self::rule(['from' => '/p?id=5', 'to' => '/five'])];
        self::assertSame('/five', self::target($rules, '/p?id=5'));
        self::assertNull(self::target($rules, '/p?id=6'));
        self::assertNull(self::target($rules, '/p'));
    }

    public function testPassModeEndToEnd(): void
    {
        $rules = [self::rule(['from' => '/promo', 'to' => '/sale#top', 'query' => 'pass', 'code' => 302])];
        $match = self::m($rules)->match(self::req('/promo?utm=1&a[]=1&a[]=2'));
        self::assertSame('/sale?utm=1&a[]=1&a[]=2#top', $match['target']);
        self::assertSame(302, $match['code']);
    }

    public function testRegexSubstitution(): void
    {
        $rules = [self::rule(['from' => '^/blog/(\d+)/(.*)$', 'regex' => true, 'to' => '/news/$2'])];
        self::assertSame('/news/hello', self::target($rules, '/blog/12/hello'));
        self::assertNull(self::target($rules, '/blog/x/hello'));
    }

    public function testRegexMatchesPathAndQueryAsOneString(): void
    {
        $rules = [self::rule(['from' => '^/s\?q=(\w+)$', 'regex' => true, 'to' => '/search/$1', 'query' => 'exact'])];
        self::assertSame('/search/shoes', self::target($rules, '/s?q=shoes'));
    }

    public function testRegexCaseFlag(): void
    {
        self::assertSame('/new', self::target([self::rule(['from' => '^/Blog', 'regex' => true])], '/blog'));
        self::assertNull(self::target([self::rule(['from' => '^/Blog', 'regex' => true, 'ignoreCase' => false])], '/blog'));
    }

    public function testUnanchoredRegexMatchesAnywhere(): void
    {
        self::assertSame('/new', self::target([self::rule(['from' => 'legacy', 'regex' => true])], '/x/legacy/y'));
    }

    public function testInvalidRegexNeverMatchesAndIsAnIssue(): void
    {
        $m = self::m([self::rule(['from' => '^/(bad', 'regex' => true, 'id' => 'r_0000beef'])]);
        self::assertNull($m->match(self::req('/bad')));
        self::assertSame('regex_error', $m->issues()[0]['code']);
        self::assertSame('r_0000beef', $m->issues()[0]['ruleId']);
    }

    public function testBacktrackLimitDoesNotFire(): void
    {
        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '100');
        try {
            $m = self::m([
                self::rule(['from' => '^/(a+)+$', 'regex' => true, 'to' => '/hang']),
                self::rule(['from' => '/' . str_repeat('a', 30) . 'b', 'to' => '/safe']),
            ]);
            $match = $m->match(self::req('/' . str_repeat('a', 30) . 'b'));
            self::assertSame('/safe', $match['target'] ?? null, 'the hanging regex falls through');
            self::assertSame('regex_error', $m->issues()[0]['code']);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
            ini_set('pcre.jit', (string) $jit);
        }
    }

    public function testFirstRuleWinsAcrossExactAndRegexInOrder(): void
    {
        $rules = [
            self::rule(['from' => '.*', 'regex' => true, 'to' => '/everything']),
            self::rule(['from' => '/old', 'to' => '/exact']),
        ];
        self::assertSame('/everything', self::target($rules, '/old'), '.* placed first wins');
        self::assertSame('/everything', self::target($rules, '/anything'));
        self::assertNull(self::target($rules, '/everything'), 'and stops at its own target');
    }

    public function testExactBeforeLaterRegex(): void
    {
        $rules = [
            self::rule(['from' => '/old', 'to' => '/exact']),
            self::rule(['from' => '.*', 'regex' => true, 'to' => '/everything']),
        ];
        self::assertSame('/exact', self::target($rules, '/old'));
    }

    public function testCatchAllToRootDoesNotLoopOnRoot(): void
    {
        $rules = [self::rule(['from' => '.*', 'regex' => true, 'to' => '/'])];
        $m = self::m($rules);
        self::assertNull($m->match(self::req('/')), 'self-loop guard holds for .*');
        self::assertSame('self_loop', $m->issues()[0]['code']);
        self::assertSame('/', $m->match(self::req('/x'))['target'] ?? null);
    }

    public function testSelfRedirectNeverFires(): void
    {
        self::assertNull(self::target([self::rule(['from' => '/a', 'to' => '/a/'])], '/a'));
    }

    public function testDisabledRuleSkippedButTraced(): void
    {
        $rules = [self::rule(['from' => '/old', 'enabled' => false, 'id' => 'r_00000001']), self::rule(['from' => '/old', 'to' => '/second'])];
        self::assertSame('/second', self::target($rules, '/old'));
        $trace = self::m($rules)->trace(self::req('/old'));
        self::assertSame('disabled', $trace[0]['reason']);
        self::assertSame('matched', $trace[1]['result']);
    }

    public function testConditionMatchedElseAndFallThrough(): void
    {
        $rules = [
            self::rule(['from' => '/members', 'when' => ['type' => 'login'], 'to' => '/dashboard', 'else' => '/login']),
        ];
        $match = self::m($rules)->match(self::req('/members'));
        self::assertSame('/login', $match['target']);
        self::assertSame('else', $match['reason']);
        $GLOBALS['pb_test_current_user'] = 1;
        self::assertSame('/dashboard', self::target($rules, '/members'));

        unset($GLOBALS['pb_test_current_user']);
        $rules = [
            self::rule(['from' => '/members', 'when' => ['type' => 'login'], 'to' => '/dashboard']),
            self::rule(['from' => '/members', 'to' => '/fallback']),
        ];
        self::assertSame('/fallback', self::target($rules, '/members'), 'false when without else falls through');
    }

    public function testErrorAndNothingWithFalseWhenFallThrough(): void
    {
        $rules = [
            self::rule(['from' => '/x', 'action' => 'error', 'code' => 410, 'to' => null, 'when' => ['type' => 'login']]),
            self::rule(['from' => '/x', 'action' => 'nothing', 'code' => null, 'to' => null, 'when' => ['type' => 'login']]),
            self::rule(['from' => '/x', 'to' => '/y']),
        ];
        self::assertSame('/y', self::target($rules, '/x'));
        $GLOBALS['pb_test_current_user'] = 1;
        $match = self::m($rules)->match(self::req('/x'));
        self::assertSame('error', $match['action']);
        self::assertSame(410, $match['code']);
        self::assertNull($match['target']);
    }

    public function testNothingAndPassActions(): void
    {
        $match = self::m([self::rule(['from' => '/ignore-me', 'action' => 'nothing', 'to' => null, 'code' => null])])->match(self::req('/ignore-me'));
        self::assertSame('nothing', $match['action']);
        self::assertNull($match['code']);
        $match = self::m([self::rule(['from' => '/old-api', 'action' => 'pass', 'to' => '/wp-json/v1/thing', 'code' => null])])->match(self::req('/old-api'));
        self::assertSame('pass', $match['action']);
        self::assertSame('/wp-json/v1/thing', $match['target']);
    }

    public function testPassWithAbsoluteTargetRefused(): void
    {
        $m = self::m([self::rule(['from' => '/p', 'action' => 'pass', 'to' => 'https://evil.test/', 'code' => null])]);
        self::assertNull($m->match(self::req('/p')));
        self::assertSame('unsafe_target', $m->issues()[0]['code']);
    }

    public function testErrorDefaultCode(): void
    {
        $match = self::m([self::rule(['from' => '/gone', 'action' => 'error', 'code' => null, 'to' => null])])->match(self::req('/gone'));
        self::assertSame(404, $match['code']);
    }

    public function testUnsafeTargetFallsThroughAndIsRecordedOnce(): void
    {
        $rules = [
            self::rule(['from' => '^/r/(.*)$', 'regex' => true, 'to' => '$1', 'id' => 'r_0000000a']),
            self::rule(['from' => '^/r/', 'regex' => true, 'to' => '/safe']),
        ];
        $m = self::m($rules);
        self::assertSame('/safe', $m->match(self::req('/r/javascript:alert(1)'))['target']);
        $m->match(self::req('/r/javascript:alert(2)'));
        self::assertCount(1, $m->issues());
        self::assertSame('unsafe_target', $m->issues()[0]['code']);
    }

    public function testCaptureCannotProduceProtocolRelativeRedirect(): void
    {
        $rules = [self::rule(['from' => '^/go/(.*)$', 'regex' => true, 'to' => '/$1'])];
        self::assertSame('/evil.com', self::target($rules, '/go//evil.com'));
        self::assertSame('/evil.com', self::target($rules, '/go/%2F%2Fevil.com'));
    }

    public function testNotFoundStageOnlyRunsNotFoundRules(): void
    {
        $rules = [
            self::rule(['from' => '/x', 'to' => '/init']),
            self::rule(['from' => null, 'when' => ['type' => '404'], 'to' => '/']),
        ];
        $GLOBALS['pb_test_query']['is_404'] = true;
        self::assertSame('/init', self::target($rules, '/x'));
        self::assertSame('/', self::target($rules, '/x', true));
        self::assertSame('/', self::target($rules, '/missing', true));
        self::assertNull(self::target($rules, '/missing'), '404 rules never run at init');
    }

    public function testNotFoundRuleWithFromMustAlsoMatch(): void
    {
        $rules = [self::rule(['from' => '/shop/', 'when' => ['type' => '404'], 'to' => '/store'])];
        $GLOBALS['pb_test_query']['is_404'] = true;
        self::assertSame('/store', self::target($rules, '/shop', true));
        self::assertNull(self::target($rules, '/other', true));
        unset($GLOBALS['pb_test_query']['is_404']);
        self::assertNull(self::target($rules, '/shop', true), 'not a 404 → condition false → falls through');
    }

    public function testUnknownConditionAndActionSkipped(): void
    {
        $rules = [
            self::rule(['from' => '/x', 'when' => ['type' => 'moon']]),
            self::rule(['from' => '/x', 'action' => 'teleport']),
        ];
        $trace = self::m($rules)->trace(self::req('/x'));
        self::assertSame(['unknown_condition', 'unknown_action'], array_column($trace, 'reason'));
    }

    public function testSourcelessNon404RuleNeverMatches(): void
    {
        self::assertNull(self::target([self::rule(['from' => null])], '/'));
    }

    public function testMissingFlagsTakeSettingsDefaults(): void
    {
        $rule = self::rule(['from' => '/A']);
        unset($rule['ignoreCase']);
        $m = new Matcher([$rule], self::settings(['defaults' => ['ignoreCase' => false]]));
        self::assertNull($m->match(self::req('/a')));
    }

    public function testOrderGroupsByPositionThenSlugThenIndex(): void
    {
        $groups = [
            ['slug' => 'zeta', 'enabled' => true, 'position' => 5, 'rules' => [
                self::rule(['from' => '/x', 'to' => '/zeta', 'index' => 0, 'group' => 'zeta']),
            ]],
            ['slug' => 'alpha', 'enabled' => true, 'position' => 5, 'rules' => [
                self::rule(['from' => '/x', 'to' => '/alpha-1', 'index' => 1, 'group' => 'alpha']),
                self::rule(['from' => '/x', 'to' => '/alpha-0', 'index' => 0, 'group' => 'alpha']),
            ]],
            ['slug' => 'first', 'enabled' => true, 'position' => 1, 'rules' => [
                self::rule(['from' => '/y', 'to' => '/first', 'index' => 0, 'group' => 'first']),
            ]],
        ];
        $ordered = Matcher::order($groups);
        self::assertSame(['/first', '/alpha-0', '/alpha-1', '/zeta'], array_column($ordered, 'to'));
        self::assertSame('/alpha-0', (new Matcher($ordered, self::settings()))->match(self::req('/x'))['target']);
    }

    public function testDisabledGroupWithEnabledRuleContributesNothing(): void
    {
        $groups = [
            ['slug' => 'off', 'enabled' => false, 'position' => 1, 'rules' => [self::rule(['from' => '/x', 'to' => '/off', 'enabled' => true])]],
            ['slug' => 'on', 'enabled' => true, 'position' => 2, 'rules' => [self::rule(['from' => '/x', 'to' => '/on'])]],
        ];
        self::assertSame('/on', (new Matcher(Matcher::order($groups), self::settings()))->match(self::req('/x'))['target']);
    }

    public function testOrderFillsGroupAndIndex(): void
    {
        $rule = self::rule(['from' => '/x']);
        unset($rule['group'], $rule['index']);
        $ordered = Matcher::order([['slug' => 'g1', 'rules' => [$rule]]]);
        self::assertSame('g1', $ordered[0]['group']);
        self::assertSame(0, $ordered[0]['index']);
    }

    public function testTraceStopsAtMatchAndNamesReasons(): void
    {
        $rules = [
            self::rule(['from' => '/other', 'id' => 'r_00000011', 'group' => 'a']),
            self::rule(['from' => '/x', 'when' => ['type' => 'login'], 'id' => 'r_00000012', 'group' => 'a']),
            self::rule(['from' => '/x', 'to' => '/hit', 'id' => 'r_00000013', 'group' => 'b']),
            self::rule(['from' => '/x', 'to' => '/never', 'id' => 'r_00000014', 'group' => 'b']),
        ];
        $trace = self::m($rules)->trace(self::req('/x'));
        self::assertSame([
            ['group' => 'a', 'ruleId' => 'r_00000011', 'result' => 'skipped', 'reason' => 'no_match'],
            ['group' => 'a', 'ruleId' => 'r_00000012', 'result' => 'skipped', 'reason' => 'condition_false'],
            ['group' => 'b', 'ruleId' => 'r_00000013', 'result' => 'matched', 'reason' => 'matched'],
        ], $trace);
    }

    public function testTraceStageReasons(): void
    {
        $rules = [self::rule(['from' => null, 'when' => ['type' => '404']]), self::rule(['from' => '/x'])];
        self::assertSame('404_stage_only', self::m($rules)->trace(self::req('/x'))[0]['reason']);
        self::assertSame('not_404_stage', self::m($rules)->trace(self::req('/x'), true)[1]['reason']);
    }

    public function testTraceAndMatchAgreeOnManyRules(): void
    {
        $rules = [];
        for ($i = 0; $i < 50; $i++) {
            $rules[] = self::rule(['from' => '/p' . $i, 'to' => '/t' . $i]);
        }
        $rules[] = self::rule(['from' => '^/p4\d$', 'regex' => true, 'to' => '/regex']);
        $m = self::m($rules);
        foreach (['/p7', '/p42', '/nope', '/P49/'] as $url) {
            $match = $m->match(self::req($url));
            $trace = $m->trace(self::req($url));
            $last = end($trace);
            self::assertSame($match !== null, $last['result'] === 'matched', $url);
            if ($match !== null) {
                self::assertSame($match['rule']['id'], $last['ruleId'], $url);
            }
        }
    }

    public function testHeadAndPostRequestsAreProcessed(): void
    {
        $rules = [self::rule(['from' => '/form', 'to' => '/new-form', 'code' => 308])];
        $m = self::m($rules);
        self::assertSame(308, $m->match(Request::fromUrl('/form', ['REQUEST_METHOD' => 'POST']))['code']);
        self::assertSame('/new-form', $m->match(Request::fromUrl('/form', ['REQUEST_METHOD' => 'HEAD']))['target']);
    }

    public function testServerConditionOnAbsoluteRequest(): void
    {
        $rules = [self::rule(['from' => '/', 'when' => ['type' => 'server', 'value' => 'old.example'], 'to' => 'https://new.example/'])];
        self::assertSame('https://new.example/', self::m($rules)->match(Request::fromUrl('http://old.example:8080/'))['target']);
        self::assertNull(self::m($rules)->match(Request::fromUrl('http://new.example/')));
    }
}
