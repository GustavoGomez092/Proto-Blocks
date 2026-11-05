<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Engine;

use ProtoBlocks\Modules\Redirects\Engine\Matcher;
use ProtoBlocks\Modules\Redirects\Registry;

/**
 * `from` as written in a file (Track A keeps it verbatim) against the
 * engine's exact-path index (Track B decodes when indexing): the two
 * spellings of a unicode path are one source, and `\?` is a literal `?`.
 */
final class SourceDecodingTest extends EngineTestCase
{
    /** @param list<array<string, mixed>> $rules raw file rules */
    private static function matcher(array $rules): Matcher
    {
        $registry = Registry::fromArrays([['slug' => 'legacy', 'rules' => $rules]], [], []);
        self::assertSame([], array_map(static fn($i) => $i->code, array_filter($registry->issues(), static fn($i) => $i->level === 'error')));

        return new Matcher($registry->enabledRules(), $registry->settings());
    }

    private static function targetFor(Matcher $matcher, string $uri): ?string
    {
        $match = $matcher->match(self::req('http://example.test' . $uri));

        return $match === null ? null : $match['target'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function unicodeCases(): iterable
    {
        yield 'decoded rule, decoded request' => ['/café', '/café'];
        yield 'decoded rule, encoded request' => ['/café', '/caf%C3%A9'];
        yield 'encoded rule, decoded request' => ['/caf%C3%A9', '/café'];
        yield 'encoded rule, encoded request' => ['/caf%C3%A9', '/caf%C3%A9'];
        yield 'encoded rule, lower-case escapes' => ['/caf%C3%A9', '/caf%c3%a9'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unicodeCases')]
    public function testUnicodeSourceMatchesBothSpellings(string $from, string $uri): void
    {
        self::assertSame('/new', self::targetFor(self::matcher([['from' => $from, 'to' => '/new']]), $uri));
    }

    public function testBothSpellingsInOneGroupAreOneSourceAndTheFirstWins(): void
    {
        $matcher = self::matcher([['from' => '/caf%C3%A9', 'to' => '/first'], ['from' => '/café', 'to' => '/second', 'query' => 'ignore']]);
        self::assertSame('/first', self::targetFor($matcher, '/café'));
    }

    public function testEscapedQuestionMarkIsALiteralPathCharacter(): void
    {
        $matcher = self::matcher([['from' => '/a\\?b', 'to' => '/new']]);
        self::assertSame('/new', self::targetFor($matcher, '/a%3Fb'), 'the path /a?b arrives percent-encoded');
        self::assertNull(self::targetFor($matcher, '/a?b'), 'an unescaped ? in the request starts the query');
        self::assertNull(self::targetFor($matcher, '/ab'));
    }

    public function testUnescapedQuestionMarkStartsTheSourceQuery(): void
    {
        $matcher = self::matcher([['from' => '/a?b=1', 'to' => '/new']]);
        self::assertSame('/new', self::targetFor($matcher, '/a?b=1'));
        self::assertNull(self::targetFor($matcher, '/a%3Fb=1'));
    }

    public function testEscapedQuestionMarkThenARealQuery(): void
    {
        $matcher = self::matcher([['from' => '/a\\?b?x=1', 'to' => '/new']]);
        self::assertSame('/new', self::targetFor($matcher, '/a%3Fb?x=1'));
        self::assertNull(self::targetFor($matcher, '/a%3Fb'));
    }
}
