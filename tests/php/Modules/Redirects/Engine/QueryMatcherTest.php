<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use ProtoBlocks\Modules\Redirects\Engine\QueryMatcher;

final class QueryMatcherTest extends EngineTestCase
{
    /** @return array<string, array{string, string, string, bool, bool}> from, mode, request, ignoreCase, expected */
    public static function modes(): array
    {
        return [
            'exact no query both' => ['/a', 'exact', '/a', true, true],
            'exact source none, request extra' => ['/a', 'exact', '/a?x=1', true, false],
            'exact same params any order' => ['/a?x=1&y=2', 'exact', '/a?y=2&x=1', true, true],
            'exact missing param' => ['/a?x=1&y=2', 'exact', '/a?x=1', true, false],
            'exact extra param' => ['/a?x=1', 'exact', '/a?x=1&y=2', true, false],
            'exact value differs' => ['/a?x=1', 'exact', '/a?x=2', true, false],
            'exact case-insensitive value' => ['/a?x=ABC', 'exact', '/a?X=abc', true, true],
            'exact case-sensitive value' => ['/a?x=ABC', 'exact', '/a?x=abc', false, false],
            'exact case-sensitive key' => ['/a?X=1', 'exact', '/a?x=1', false, false],
            'exact arrays' => ['/a?a[]=1&a[]=2', 'exact', '/a?a[]=1&a[]=2', true, true],
            'exact array order matters inside a list' => ['/a?a[]=1&a[]=2', 'exact', '/a?a[]=2&a[]=1', true, false],
            'exact array vs scalar' => ['/a?a[]=1', 'exact', '/a?a=1', true, false],
            'exact nested assoc any order' => ['/a?f[x]=1&f[y]=2', 'exact', '/a?f[y]=2&f[x]=1', true, true],
            'exact duplicate key last wins' => ['/a?a=2', 'exact', '/a?a=1&a=2', true, true],
            'exact duplicate key first value loses' => ['/a?a=1', 'exact', '/a?a=1&a=2', true, false],
            'exact encoded value' => ['/a?q=a%20b', 'exact', '/a?q=a+b', true, true],
            'exactOrder same' => ['/a?x=1&y=2', 'exactOrder', '/a?x=1&y=2', true, true],
            'exactOrder reordered' => ['/a?x=1&y=2', 'exactOrder', '/a?y=2&x=1', true, false],
            'exactOrder duplicate keys raw' => ['/a?a=1&a=2', 'exactOrder', '/a?a=1&a=2', true, true],
            'exactOrder duplicate keys differ raw' => ['/a?a=2', 'exactOrder', '/a?a=1&a=2', true, false],
            'exactOrder case-insensitive' => ['/a?X=1', 'exactOrder', '/a?x=1', true, true],
            'exactOrder case-sensitive' => ['/a?X=1', 'exactOrder', '/a?x=1', false, false],
            'exactOrder none none' => ['/a', 'exactOrder', '/a', true, true],
            'ignore extras ignored' => ['/a?x=1', 'ignore', '/a?x=1&utm=z', true, true],
            'ignore no source query' => ['/a', 'ignore', '/a?anything=1', true, true],
            'ignore missing source param' => ['/a?x=1', 'ignore', '/a?utm=z', true, false],
            'ignore value differs' => ['/a?x=1', 'ignore', '/a?x=2', true, false],
            'pass like ignore' => ['/a?x=1', 'pass', '/a?x=1&utm=z', true, true],
            'pass missing source param' => ['/a?x=1', 'pass', '/a', true, false],
        ];
    }

    #[DataProvider('modes')]
    public function testModes(string $from, string $mode, string $request, bool $ignoreCase, bool $expected): void
    {
        $rule = self::rule(['from' => $from, 'query' => $mode, 'ignoreCase' => $ignoreCase]);
        self::assertSame($expected, QueryMatcher::matches($rule, self::req($request)));
    }

    public function testRegexRulesIgnoreQueryMode(): void
    {
        $rule = self::rule(['from' => '^/a', 'regex' => true, 'query' => 'exact']);
        self::assertTrue(QueryMatcher::matches($rule, self::req('/a?x=1')));
    }

    public function testEscapedQuestionMarkIsNotAQuery(): void
    {
        $rule = self::rule(['from' => '/what\?', 'query' => 'exact']);
        self::assertTrue(QueryMatcher::matches($rule, self::req('/what%3F')));
    }

    /** @return array<string, array{string, string, string, string}> from, target, request, expected */
    public static function passCases(): array
    {
        return [
            'appends extras' => ['/a', '/b', '/a?utm=1&x=2', '/b?utm=1&x=2'],
            'no request query' => ['/a', '/b', '/a', '/b'],
            'source params consumed' => ['/a?id=5', '/b', '/a?id=5&utm=1', '/b?utm=1'],
            'target params win' => ['/a', '/b?x=target', '/a?x=request&y=1', '/b?x=target&y=1'],
            'target params win case-insensitively' => ['/a', '/b?X=t', '/a?x=r', '/b?X=t'],
            'fragment moves after query' => ['/a', '/b#top', '/a?x=1', '/b?x=1#top'],
            'fragment with target query' => ['/a', '/b?k=v#top', '/a?x=1', '/b?k=v&x=1#top'],
            'arrays kept as sent' => ['/a', '/b', '/a?a[]=1&a[]=2', '/b?a[]=1&a[]=2'],
            'duplicate keys kept as sent' => ['/a', '/b', '/a?a=1&a=2', '/b?a=1&a=2'],
            'raw encoding kept' => ['/a', '/b', '/a?q=a%20b&r=%2F', '/b?q=a%20b&r=%2F'],
            'depth 10 kept' => ['/a', '/b', '/a?a' . str_repeat('[x]', 10) . '=1', '/b?a' . str_repeat('[x]', 10) . '=1'],
            'depth 11 dropped' => ['/a', '/b', '/a?a' . str_repeat('[x]', 11) . '=1&k=1', '/b?k=1'],
            'absolute target' => ['/a', 'https://other.test/b', '/a?x=1', 'https://other.test/b?x=1'],
            'empty pairs skipped' => ['/a', '/b', '/a?&x=1&&', '/b?x=1'],
        ];
    }

    #[DataProvider('passCases')]
    public function testPassThrough(string $from, string $target, string $request, string $expected): void
    {
        $rule = self::rule(['from' => $from, 'query' => 'pass']);
        self::assertSame($expected, QueryMatcher::passThrough($target, self::req($request), $rule));
    }

    public function testPassThroughCaseSensitiveKeysDoNotClash(): void
    {
        $rule = self::rule(['from' => '/a', 'query' => 'pass', 'ignoreCase' => false]);
        self::assertSame('/b?X=t&x=r', QueryMatcher::passThrough('/b?X=t', self::req('/a?x=r'), $rule));
    }
}
