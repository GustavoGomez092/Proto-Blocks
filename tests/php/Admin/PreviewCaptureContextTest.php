<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Admin;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Admin\PreviewCapture;

/**
 * A capture renders against a published post (so field()/loops have data), and
 * a blank-looking stored capture is flagged empty.
 */
final class PreviewCaptureContextTest extends TestCase
{
    public function testDeclaredPostTypesAreSearchedFirst(): void
    {
        $seen   = [];
        $finder = static function (array $types) use (&$seen): int {
            $seen[] = $types;
            return $types === ['event'] ? 42 : 7;
        };

        self::assertSame(42, PreviewCapture::contextPostId(['protoBlocks' => ['postTypes' => ['event']]], $finder));
        self::assertSame([['event']], $seen);
    }

    public function testFallsBackToAnyPublishedPostWhenDeclaredTypeHasNone(): void
    {
        $finder = static fn(array $types): int => $types === [] ? 7 : 0;

        self::assertSame(7, PreviewCapture::contextPostId(['protoBlocks' => ['postTypes' => ['event']]], $finder));
        self::assertSame(7, PreviewCapture::contextPostId([], $finder));
    }

    public function testInvalidPostTypeNamesAreIgnoredAndNoPostGivesZero(): void
    {
        $seen   = [];
        $finder = static function (array $types) use (&$seen): int {
            $seen[] = $types;
            return 0;
        };

        self::assertSame(0, PreviewCapture::contextPostId(['protoBlocks' => ['postTypes' => ['Bad Type', 5, "x\n"]]], $finder));
        self::assertSame([[]], $seen);
    }

    public function testEmptyFlagUsesHeightAndByteThresholds(): void
    {
        self::assertTrue(PreviewCapture::emptyFromDimensions(1280, 22, 706)['empty']);
        self::assertTrue(PreviewCapture::emptyFromDimensions(1280, 600, 1500)['empty']);
        self::assertTrue(PreviewCapture::emptyFromDimensions(1280, 39, 90000)['empty']);
        self::assertFalse(PreviewCapture::emptyFromDimensions(1280, 40, 2048)['empty']);
    }

    public function testEmptyShapeReadsTheStoredFile(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD is not available.');
        }
        $path = sys_get_temp_dir() . '/pb-empty-' . bin2hex(random_bytes(4)) . '.png';
        $img  = imagecreatetruecolor(1280, 22);
        imagepng($img, $path);
        try {
            $shape = PreviewCapture::emptyShape($path);
            self::assertTrue($shape['empty']);
            self::assertSame(1280, $shape['width']);
            self::assertSame(22, $shape['height']);
            self::assertSame(['empty' => false, 'width' => null, 'height' => null], PreviewCapture::emptyShape($path . '.missing'));
        } finally {
            @unlink($path);
        }
    }
}
