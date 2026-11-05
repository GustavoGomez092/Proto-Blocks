<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Import;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Redirects\Import\ApacheFormat;
use ProtoBlocks\Modules\Redirects\Import\Formats;

final class ApacheFormatTest extends TestCase
{
    /** @var array<int, array> */
    private static array $rows;

    public static function setUpBeforeClass(): void
    {
        $rows = (new ApacheFormat())->parse((string) file_get_contents(__DIR__ . '/fixtures/apache.htaccess'));
        self::$rows = array_column($rows, null, 'line');
    }

    private static function rule(int $line): array
    {
        return self::$rows[$line]['rule'];
    }

    private static function one(string $text): array
    {
        return (new ApacheFormat())->parse($text)[0];
    }

    public function testRewriteRuleWithNcR301L(): void
    {
        $this->assertSame(
            ['from' => '/old-page', 'regex' => false, 'to' => '/new-page', 'code' => 301, 'action' => 'url', 'query' => 'ignore', 'ignoreCase' => true, 'ignoreTrailingSlash' => true],
            self::rule(5),
        );
    }

    public function testWithoutNcIsCaseSensitiveAndWithoutSlashOptionalIsStrict(): void
    {
        $this->assertSame(
            ['from' => '/exact', 'regex' => false, 'to' => '/target', 'code' => 302, 'action' => 'url', 'query' => 'ignore', 'ignoreCase' => false, 'ignoreTrailingSlash' => false],
            self::rule(6),
        );
    }

    public function testRegexRuleWithQsaPassesTheQuery(): void
    {
        $rule = self::rule(7);
        $this->assertSame('^/blog/(\d+)/(.*)$', $rule['from']);
        $this->assertTrue($rule['regex']);
        $this->assertSame('/news/$2', $rule['to']);
        $this->assertSame('pass', $rule['query']);
        $this->assertArrayNotHasKey('ignoreTrailingSlash', $rule);
    }

    public function testRewriteCondPairs(): void
    {
        $this->assertSame(['type' => 'referrer', 'value' => 'google\.', 'regex' => true], self::rule(9)['when']);
        $this->assertSame(302, self::rule(9)['code'], 'bare R is 302');
        $this->assertSame(['type' => 'agent', 'value' => 'Mozilla/5.0', 'regex' => false], self::rule(11)['when'], 'anchored literal → exact value');
        $this->assertSame(307, self::rule(11)['code']);
        $this->assertSame(['type' => 'server', 'value' => 'old.example.com'], self::rule(13)['when']);
        $this->assertSame('^/(.*)$', self::rule(13)['from']);
        $this->assertSame('https://new.example.com/$1', self::rule(13)['to']);
        $this->assertSame(301, self::rule(13)['code'], 'R=permanent');
    }

    public function testQueryStringConditionBecomesAnExactQuery(): void
    {
        $rule = self::rule(15);
        $this->assertSame('/product?id=5', $rule['from']);
        $this->assertFalse($rule['regex']);
        $this->assertSame('exact', $rule['query']);
    }

    public function testForbiddenGoneInternalAndStop(): void
    {
        $this->assertSame(['code' => 403, 'action' => 'error'], array_intersect_key(self::rule(16), ['code' => 1, 'action' => 1]));
        $this->assertArrayNotHasKey('to', self::rule(16));
        $this->assertSame(['code' => 410, 'action' => 'error'], array_intersect_key(self::rule(17), ['code' => 1, 'action' => 1]));
        $this->assertSame('pass', self::rule(18)['action'], 'no R and a path target: internal rewrite (code 0)');
        $this->assertSame('/index.php?page=internal', self::rule(18)['to']);
        $this->assertArrayNotHasKey('code', self::rule(18));
        $this->assertSame('nothing', self::rule(19)['action'], '- [L] stops processing');
    }

    public function testRootTrailingPattern(): void
    {
        $this->assertSame('/', self::rule(20)['from']);
        $this->assertTrue(self::rule(20)['ignoreTrailingSlash']);
    }

    public function testUnsupportedPieces(): void
    {
        $this->assertSame('unsupported_condition', self::$rows[22]['reason'], 'REQUEST_FILENAME condition');
        $this->assertSame('unsupported_flag', self::$rows[23]['reason'], '[P] proxy');
        $this->assertSame('unsupported_variable', self::$rows[24]['reason']);
        $this->assertSame('unsupported_directive', self::$rows[38]['reason'], 'Header set');
    }

    public function testLineContinuation(): void
    {
        $this->assertSame('/continued', self::rule(25)['to']);
        $this->assertArrayNotHasKey(26, self::$rows);
    }

    public function testRedirectDirectives(): void
    {
        $this->assertSame(
            ['from' => '/a', 'regex' => false, 'to' => '/b', 'code' => 301, 'action' => 'url', 'ignoreCase' => false, 'ignoreTrailingSlash' => true],
            self::rule(29),
        );
        $this->assertSame(301, self::rule(30)['code'], 'Redirect permanent');
        $this->assertSame(302, self::rule(31)['code'], 'bare Redirect → 302');
        $this->assertSame(['from' => '/gone-alias', 'regex' => false, 'code' => 410, 'action' => 'error', 'ignoreCase' => false, 'ignoreTrailingSlash' => true], self::rule(32));
        $this->assertSame(303, self::rule(33)['code'], 'seeother');
        $this->assertSame(['from' => '^/gone/(.*)', 'regex' => true, 'code' => 410, 'action' => 'error', 'ignoreCase' => false], self::rule(34));
        $this->assertSame('/category/$1', self::rule(35)['to']);
        $this->assertTrue(self::rule(35)['regex']);
        $this->assertSame(301, self::rule(36)['code']);
        $this->assertSame(302, self::rule(37)['code']);
    }

    public function testWordPressBlockAndBoilerplateAreSkipped(): void
    {
        $this->assertSame([], array_filter(array_keys(self::$rows), static fn(int $l): bool => $l > 38));
        foreach ([1, 2, 3, 4, 8, 10, 12, 14, 21, 27, 28] as $line) {
            $this->assertArrayNotHasKey($line, self::$rows, "line $line");
        }
    }

    public function testRedirectNeedsATargetForA3xx(): void
    {
        $this->assertSame('missing_target', self::one('Redirect 301 /a')['reason']);
        $this->assertSame('invalid_code', self::one('Redirect 299 /a /b')['reason']);
        $this->assertSame('empty_source', self::one('Redirect 301')['reason']);
    }

    public function testOrConditionsAndNegationsAreUnsupported(): void
    {
        $this->assertSame('unsupported_condition', self::one("RewriteCond %{HTTP_HOST} ^a\\.com$ [OR]\nRewriteRule ^x$ /y [R=301]")['reason']);
        $this->assertSame('unsupported_condition', self::one("RewriteCond %{HTTP_REFERER} !google\nRewriteRule ^x$ /y [R=301]")['reason']);
        $this->assertSame('unsupported_condition', self::one('RewriteRule !^x$ /y [R=301]')['reason']);
        $this->assertSame('unsupported_condition', self::one("RewriteCond %{HTTP_HOST} ^(www\\.)?a\\.com$\nRewriteRule ^x$ /y [R=301]")['reason'], 'host regex');
    }

    public function testTwoConditionsAreRefused(): void
    {
        $row = self::one("RewriteCond %{HTTP_REFERER} a\nRewriteCond %{HTTP_USER_AGENT} b\nRewriteRule ^x$ /y [R=301]");
        $this->assertSame('multiple_conditions', $row['reason']);
    }

    public function testConditionsOnlyApplyToTheNextRule(): void
    {
        $rows = (new ApacheFormat())->parse("RewriteCond %{HTTP_REFERER} a\nRewriteRule ^x$ /y [R=301]\nRewriteRule ^z$ /w [R=301]");
        $this->assertArrayHasKey('when', $rows[0]['rule']);
        $this->assertArrayNotHasKey('when', $rows[1]['rule']);
    }

    public function testQueryConditionWithARegexRule(): void
    {
        $row = self::one("RewriteCond %{QUERY_STRING} ^p=(\\d+)$\nRewriteRule ^shop$ /item [R=301]");
        $this->assertTrue($row['rule']['regex']);
        $this->assertSame('^/shop\?p=(\d+)$', $row['rule']['from']);
    }

    public function testQuotedArgumentsAndEscapedPercent(): void
    {
        $row = self::one('RewriteRule "^with space$" "/new place" [R=301,NE]');
        $this->assertSame('/with space', $row['rule']['from']);
        $this->assertSame('/new place', $row['rule']['to']);
        $this->assertSame('/a%23b', self::one('RewriteRule ^x$ /a\%23b [R=301,NE]')['rule']['to']);
        $this->assertSame('unsupported_variable', self::one('RewriteRule ^x$ /a%1 [R=301]')['reason'], 'condition back-reference');
        $this->assertSame('malformed_line', self::one('RewriteRule "^open /x')['reason']);
        $this->assertSame('malformed_line', self::one('RewriteRule ^x$ /y NC')['reason'], 'flags without brackets');
    }

    public function testAbsoluteTargetWithoutRIsAnImplicit302(): void
    {
        $this->assertSame(302, self::one('RewriteRule ^x$ https://elsewhere.test/ [L]')['rule']['code']);
    }

    public function testR410WithDashIsAnError(): void
    {
        $this->assertSame(['code' => 410, 'action' => 'error'], array_intersect_key(self::one('RewriteRule ^x$ - [R=410,L]')['rule'], ['code' => 1, 'action' => 1]));
        $this->assertSame('missing_target', self::one('RewriteRule ^x$ - [R=301]')['reason']);
    }

    public function testSelfRedirectAndBadRegex(): void
    {
        $this->assertSame('self_redirect', self::one('Redirect 301 /same /same')['reason']);
        $this->assertSame('invalid_regex', self::one('RedirectMatch 301 ^/(a /b')['reason']);
        $this->assertSame('invalid_target', self::one('Redirect 301 /a javascript:alert(1)')['reason']);
    }

    public function testTooLarge(): void
    {
        $this->assertSame('too_large', (new ApacheFormat())->parse(str_repeat('#', Formats::MAX_BYTES + 1))[0]['reason']);
    }

    public function testDetect(): void
    {
        $apache = new ApacheFormat();
        $this->assertTrue($apache->detect('.htaccess', ''));
        $this->assertTrue($apache->detect('site.conf', ''));
        $this->assertTrue($apache->detect('', "# x\nRewriteRule ^a$ /b [R=301]"));
        $this->assertTrue($apache->detect('rules.txt', 'Redirect 301 /a /b'));
        $this->assertFalse($apache->detect('', '/a /b 301'));
        $this->assertFalse($apache->detect('_redirects', 'Redirect 301 /a /b'));
    }
}
