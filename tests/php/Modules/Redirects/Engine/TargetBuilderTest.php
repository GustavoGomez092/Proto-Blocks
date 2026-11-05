<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use ProtoBlocks\Modules\Redirects\Engine\TargetBuilder;

final class TargetBuilderTest extends EngineTestCase
{
    private static function regexRule(array $o = []): array
    {
        return self::rule($o + ['from' => '^/old/(.*)$', 'regex' => true]);
    }

    public function testSubstitutesDollarAndBraceForms(): void
    {
        $rule = self::regexRule();
        $captures = ['/old/a/b', 'a', 'b'];
        self::assertSame('/new/a-b', TargetBuilder::build($rule, '/new/$1-$2', self::req('/old/a/b'), $captures));
        self::assertSame('/new/a0', TargetBuilder::build($rule, '/new/${1}0', self::req('/old/a/b'), $captures));
    }

    public function testMissingGroupIsEmpty(): void
    {
        self::assertSame('/new/', TargetBuilder::build(self::regexRule(), '/new/$9', self::req('/old/x'), ['/old/x', 'x']));
    }

    public function testNoSubstitutionForPlainRules(): void
    {
        // Not substituted (that would give /price/y); core's wp_sanitize_redirect drops the literal `$`.
        self::assertSame('/price/1', TargetBuilder::build(self::rule(), '/price/$1', self::req('/old'), ['x', 'y']));
    }

    public function testSpacesEncodedPathAndQuery(): void
    {
        $t = TargetBuilder::build(self::regexRule(), '/s/$1?q=$1', self::req('/old/a b'), ['/old/a b', 'a b']);
        self::assertSame('/s/a%20b?q=a+b', $t);
    }

    /** @return array<string, array{string, string, string}> target template, capture, reason */
    public static function unsafe(): array
    {
        return [
            'backslash' => ['/$1', '\\evil.com', 'unsafe_target'],
            'backslash inside' => ['/x$1', '/\\evil.com', 'unsafe_target'],
            'tab' => ['/$1', "\t/evil.com", 'unsafe_target'],
            'newline' => ['/$1', "a\nLocation: x", 'unsafe_target'],
            'javascript scheme' => ['$1', 'javascript:alert(1)', 'unsafe_target'],
            'data scheme' => ['$1', 'data:text/html,x', 'unsafe_target'],
            'absolute via capture into path template' => ['$1', 'https://evil.com/', 'unsafe_target'],
            'host changed by capture' => ['https://good.test$1', '@evil.com/', 'host_changed'],
            'host suffix by capture' => ['https://good.test$1', '.evil.com/', 'host_changed'],
            'host from capture' => ['https://$1/', 'evil.com', 'host_changed'],
            'absolute backslash' => ['https://good.test/$1', '\\@evil.com', 'unsafe_target'],
        ];
    }

    #[DataProvider('unsafe')]
    public function testUnsafeTargetsDoNotFire(string $template, string $capture, string $reason): void
    {
        $result = TargetBuilder::evaluate(self::regexRule(), $template, self::req('/old/x'), ['/old/x', $capture]);
        self::assertNull($result['target']);
        self::assertSame($reason, $result['reason']);
    }

    public function testDoubleSlashCollapses(): void
    {
        self::assertSame('/evil.com', TargetBuilder::build(self::regexRule(), '/$1', self::req('/old/x'), ['/old/x', '/evil.com']));
        self::assertSame('/evil.com', TargetBuilder::build(self::regexRule(), '$1', self::req('/old/x'), ['/old/x', '///evil.com']));
    }

    public function testJavascriptAfterSlashIsJustAPath(): void
    {
        self::assertSame('/javascript:alert(1)', TargetBuilder::build(self::regexRule(), '/$1', self::req('/old/x'), ['/old/x', 'javascript:alert(1)']));
    }

    public function testAbsoluteTargetKeepsHost(): void
    {
        $t = TargetBuilder::build(self::regexRule(), 'https://Good.test/n/$1', self::req('/old/x'), ['/old/x', 'p']);
        self::assertSame('https://Good.test/n/p', $t);
    }

    public function testAbsoluteTemplateWithoutSlashAndPathCapture(): void
    {
        $t = TargetBuilder::build(self::regexRule(), 'https://good.test$1', self::req('/old/x'), ['/old/x', '/p']);
        self::assertSame('https://good.test/p', $t);
    }

    public function testSanitizeRedirectApplied(): void
    {
        self::assertSame('/ascript', TargetBuilder::build(self::rule(['from' => '/x']), '/a<script>', self::req('/x'), []));
    }

    public function testEmptyTargetRefused(): void
    {
        self::assertSame('unsafe_target', TargetBuilder::evaluate(self::rule(), '  ', self::req('/old'), [])['reason']);
    }

    public function testSelfLoopPlain(): void
    {
        $rule = self::rule(['from' => '/a', 'to' => '/A/']);
        $result = TargetBuilder::evaluate($rule, '/A/', self::req('/a'), []);
        self::assertSame('self_loop', $result['reason']);
    }

    public function testCaseSensitiveRuleToOtherCaseIsNotALoop(): void
    {
        $rule = self::rule(['from' => '/a', 'ignoreCase' => false]);
        self::assertSame('/A', TargetBuilder::build($rule, '/A', self::req('/a'), []));
    }

    public function testSelfLoopAbsoluteSameHost(): void
    {
        $rule = self::rule(['from' => '/a']);
        self::assertSame('self_loop', TargetBuilder::evaluate($rule, 'https://example.test/a', self::req('/a'), [])['reason']);
    }

    public function testAbsoluteOtherHostSamePathIsNotALoop(): void
    {
        $rule = self::rule(['from' => '/a']);
        self::assertSame('https://new.test/a', TargetBuilder::build($rule, 'https://new.test/a', self::req('/a'), []));
    }

    public function testIgnoreModeStrippingTheQueryFiresOnceThenStops(): void
    {
        $rule = self::rule(['from' => '/a', 'query' => 'ignore']);
        self::assertSame('/a', TargetBuilder::build($rule, '/a', self::req('/a?x=1'), []));
        self::assertSame('self_loop', TargetBuilder::evaluate($rule, '/a', self::req('/a'), [])['reason']);
    }

    public function testSelfLoopQueryComparedParsedAnyOrder(): void
    {
        $rule = self::rule(['from' => '/a?x=1&y=2', 'query' => 'exact']);
        self::assertSame('self_loop', TargetBuilder::evaluate($rule, '/a?y=2&x=1', self::req('/a?x=1&y=2'), [])['reason']);
    }

    public function testSelfLoopExactOrderComparesRaw(): void
    {
        $rule = self::rule(['from' => '/a?x=1&y=2', 'query' => 'exactOrder']);
        self::assertSame('/a?y=2&x=1', TargetBuilder::build($rule, '/a?y=2&x=1', self::req('/a?x=1&y=2'), []));
        self::assertSame('self_loop', TargetBuilder::evaluate($rule, '/a?x=1&y=2', self::req('/a?x=1&y=2'), [])['reason']);
    }

    public function testExactModeSamePathDifferentQueryIsNotALoop(): void
    {
        $rule = self::rule(['from' => '/a?old=1', 'query' => 'exact']);
        self::assertSame('/a?new=1', TargetBuilder::build($rule, '/a?new=1', self::req('/a?old=1'), []));
    }

    public function testRegexEverythingToRootStopsAtRoot(): void
    {
        $rule = self::rule(['from' => '.*', 'regex' => true]);
        self::assertSame('/', TargetBuilder::build($rule, '/', self::req('/anything'), ['/anything']));
        self::assertSame('self_loop', TargetBuilder::evaluate($rule, '/', self::req('/'), ['/'])['reason']);
    }

    public function testPassModeAppendsBeforeLoopCheck(): void
    {
        $rule = self::rule(['from' => '/a', 'query' => 'pass']);
        self::assertSame('/b?utm=1#x', TargetBuilder::build($rule, '/b#x', self::req('/a?utm=1'), []));
    }

    public function testPassActionIsNotLoopChecked(): void
    {
        $rule = self::rule(['from' => '/a', 'action' => 'pass', 'code' => null]);
        self::assertSame('/a', TargetBuilder::build($rule, '/a', self::req('/a'), []));
    }
}
