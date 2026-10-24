<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Admin;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Admin\PreviewCapture;

/**
 * The capture renders a block from its first real instance when the author
 * wrote no `example`; this is the search over parsed block trees it relies on.
 */
final class PreviewCaptureAttributesTest extends TestCase
{
    public function testFindsTopLevelInstance(): void
    {
        $blocks = [
            ['blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => []],
            ['blockName' => 'proto-blocks/hero', 'attrs' => ['heading' => 'Real heading'], 'innerBlocks' => []],
            ['blockName' => 'proto-blocks/hero', 'attrs' => ['heading' => 'Second'], 'innerBlocks' => []],
        ];

        self::assertSame(['heading' => 'Real heading'], PreviewCapture::firstBlockAttributes($blocks, 'proto-blocks/hero'));
    }

    public function testFindsNestedInstanceDepthFirst(): void
    {
        $blocks = [
            ['blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => [
                ['blockName' => 'core/columns', 'attrs' => [], 'innerBlocks' => [
                    ['blockName' => 'proto-blocks/card', 'attrs' => ['title' => 'Nested'], 'innerBlocks' => []],
                ]],
            ]],
            ['blockName' => 'proto-blocks/card', 'attrs' => ['title' => 'Later'], 'innerBlocks' => []],
        ];

        self::assertSame(['title' => 'Nested'], PreviewCapture::firstBlockAttributes($blocks, 'proto-blocks/card'));
    }

    public function testMissingBlockIsNullAndEmptyAttrsIsEmptyArray(): void
    {
        $blocks = [
            ['blockName' => null, 'attrs' => [], 'innerBlocks' => []], // freeform/whitespace
            ['blockName' => 'proto-blocks/plain', 'attrs' => [], 'innerBlocks' => []],
        ];

        self::assertNull(PreviewCapture::firstBlockAttributes($blocks, 'proto-blocks/absent'));
        self::assertSame([], PreviewCapture::firstBlockAttributes($blocks, 'proto-blocks/plain'));
    }
}
