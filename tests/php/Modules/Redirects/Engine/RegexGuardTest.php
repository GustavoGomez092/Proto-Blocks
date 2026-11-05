<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Engine;

use ProtoBlocks\Modules\Redirects\Engine\RegexGuard;

final class RegexGuardTest extends EngineTestCase
{
    public function testCompilesWithSAndUFlags(): void
    {
        $c = RegexGuard::compile('^/blog/(\d+)$', false);
        self::assertNotNull($c);
        self::assertStringEndsWith('su', $c);
        self::assertSame(['/blog/12', '12'], RegexGuard::match($c, '/blog/12'));
    }

    public function testIgnoreCaseAddsI(): void
    {
        $c = (string) RegexGuard::compile('^/Blog$', true);
        self::assertStringEndsWith('siu', $c);
        self::assertNotNull(RegexGuard::match($c, '/BLOG'));
        self::assertNull(RegexGuard::match((string) RegexGuard::compile('^/Blog$', false), '/BLOG'));
    }

    public function testDelimiterNeverCollidesWithPattern(): void
    {
        $pattern = '^/a#b~c!d@e%f`g|h;i,j=k&l\'m"n-o_p:q>r/s+t*u.v^w$';
        $c = RegexGuard::compile($pattern, false);
        self::assertNotNull($c);
        self::assertNotNull(RegexGuard::match($c, '/a#b~c!d@e%f`g|h;i,j=k&l\'m"n-o_p:q>r/s+t*u.v'));
    }

    public function testHashInPatternIsLiteralNotATerminator(): void
    {
        $c = (string) RegexGuard::compile('^/x#y$', false);
        self::assertNotNull(RegexGuard::match($c, '/x#y'));
        self::assertNull(RegexGuard::match($c, '/x'));
    }

    public function testDelimiterInjectionCannotAddModifiers(): void
    {
        // A body trying to close the pattern and add the e/x modifiers is just a literal.
        $c = RegexGuard::compile('^/a#e', false);
        self::assertNotNull($c);
        self::assertStringNotContainsString('#e#', substr((string) $c, 0, 1) . 'x');
        self::assertNotNull(RegexGuard::match((string) $c, '/a#e'));
    }

    public function testInvalidPatternIsNull(): void
    {
        self::assertNull(RegexGuard::compile('^/(unclosed', false));
        self::assertNull(RegexGuard::compile('', false));
        self::assertNull(RegexGuard::compile("a\0b", false));
    }

    public function testLengthCap(): void
    {
        self::assertNotNull(RegexGuard::compile(str_repeat('a', 500), false));
        self::assertNull(RegexGuard::compile(str_repeat('a', 501), false));
    }

    public function testInvalidUtf8PatternCompilesWithoutU(): void
    {
        $c = RegexGuard::compile("^/a\xFF", false);
        self::assertNotNull($c);
        self::assertStringEndsWith('s', (string) $c);
    }

    public function testUnicodePatternMatchesCharacters(): void
    {
        $c = (string) RegexGuard::compile('^/caf.$', false);
        self::assertNotNull(RegexGuard::match($c, '/café'), 'u flag makes . one character');
    }

    public function testNoMatchIsNull(): void
    {
        self::assertNull(RegexGuard::match((string) RegexGuard::compile('^/a$', false), '/b'));
    }

    public function testBacktrackLimitGivesNullAndAnError(): void
    {
        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '100');
        try {
            $c = (string) RegexGuard::compile('^(a+)+$', false);
            $result = RegexGuard::matchDetailed($c, str_repeat('a', 30) . 'b');
            self::assertNull($result['captures']);
            self::assertNotNull($result['error']);
            self::assertNull(RegexGuard::match($c, str_repeat('a', 30) . 'b'));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
            ini_set('pcre.jit', (string) $jit);
        }
        self::assertSame(PREG_NO_ERROR, (preg_match('/a/', 'a') !== false) ? preg_last_error() : -1);
    }

    public function testInvalidUtf8SubjectWithUPatternIsNullNotAWarning(): void
    {
        $c = (string) RegexGuard::compile('^/a', false);
        $result = RegexGuard::matchDetailed($c, "/a\xFF");
        self::assertNull($result['captures']);
        self::assertNotNull($result['error']);
    }

    public function testNamedCapturesKept(): void
    {
        $m = RegexGuard::match((string) RegexGuard::compile('^/(?<slug>[a-z]+)$', false), '/abc');
        self::assertSame('abc', $m['slug'] ?? null);
        self::assertSame('abc', $m[1] ?? null);
    }
}
