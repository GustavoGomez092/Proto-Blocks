<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\ContentModel\JsoncException;

final class JsoncTest extends TestCase
{
    public function testLineAndBlockCommentsAreStripped(): void
    {
        $this->assertSame(['a' => 1, 'b' => 2], Jsonc::decode("{ // a\n \"a\": 1, /* b */ \"b\": 2 }"));
    }

    public function testCommentMarkersInsideStringsSurvive(): void
    {
        $this->assertSame(
            ['u' => 'https://x.test/a//b', 'c' => '/* no */'],
            Jsonc::decode('{"u": "https://x.test/a//b", "c": "/* no */"}')
        );
    }

    public function testEscapedQuoteInString(): void
    {
        $this->assertSame(['q' => 'say "//hi"'], Jsonc::decode('{"q": "say \"//hi\""}'));
    }

    public function testTrailingCommasTolerated(): void
    {
        $this->assertSame(
            ['a' => [1, 2], 'b' => ['c' => 1]],
            Jsonc::decode('{"a": [1, 2,], "b": {"c": 1,},}')
        );
    }

    public function testSyntaxErrorReportsLineAndColumn(): void
    {
        try {
            Jsonc::decode("{\n  \"a\": 1\n  \"b\": 2\n}");
            $this->fail('Expected JsoncException');
        } catch (JsoncException $e) {
            $this->assertSame(3, $e->line);
            $this->assertGreaterThan(0, $e->column);
        }
    }

    public function testNonObjectRootRejected(): void
    {
        foreach (['[1]', '"x"'] as $text) {
            try {
                Jsonc::decode($text);
                $this->fail("Expected JsoncException for $text");
            } catch (JsoncException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEmptyObjectStaysArray(): void
    {
        $this->assertSame(['labels' => []], Jsonc::decode('{"labels": {}}'));
    }

    public function testProtoKeyRejected(): void
    {
        try {
            Jsonc::decode('{"name": "X", "labels": {"__proto__": {"a": 1}}}');
            $this->fail('Expected JsoncException');
        } catch (JsoncException $e) {
            $this->assertStringContainsString('__proto__', $e->getMessage());
            $this->assertSame(1, $e->line);
        }
        $this->assertSame(['constructor' => 1], Jsonc::decode('{"constructor": 1}'));
    }
}
