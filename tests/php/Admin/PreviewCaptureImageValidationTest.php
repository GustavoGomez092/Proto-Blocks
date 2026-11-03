<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Admin {

    use PHPUnit\Framework\TestCase;
    use ProtoBlocks\Admin\PreviewCapture;

    /**
     * The REST capture endpoint must only ever write a real PNG/JPEG into the
     * block folder; this is the byte-level check it relies on.
     */
    final class PreviewCaptureImageValidationTest extends TestCase
    {
        /** A 1x1 transparent PNG. */
        private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

        /** A 1x1 GIF: a real image, but not an accepted type. */
        private const GIF_1X1 = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

        public function testPngBytesPass(): void
        {
            $bytes = base64_decode(self::PNG_1X1, true);
            self::assertIsString($bytes);
            self::assertNull(PreviewCapture::validateImageBytes($bytes));
        }

        public function testJpegBytesPass(): void
        {
            if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
                self::markTestSkipped('GD is required to build a JPEG fixture.');
            }
            $im = imagecreatetruecolor(2, 2);
            ob_start();
            imagejpeg($im);
            $bytes = (string) ob_get_clean();
            imagedestroy($im);

            self::assertNull(PreviewCapture::validateImageBytes($bytes));
        }

        public function testTextFails(): void
        {
            self::assertSame('not_image', PreviewCapture::validateImageBytes('<?php phpinfo(); ?>'));
            self::assertSame('not_image', PreviewCapture::validateImageBytes('just some text'));
        }

        public function testPngHeaderAloneIsNotAnImage(): void
        {
            // The 8-byte PNG signature with no IHDR behind it must not pass.
            self::assertSame('not_image', PreviewCapture::validateImageBytes("\x89PNG\r\n\x1a\n"));
        }

        public function testEmptyFails(): void
        {
            self::assertSame('empty', PreviewCapture::validateImageBytes(''));
        }

        public function testOversizeFails(): void
        {
            $bytes = base64_decode(self::PNG_1X1, true);
            self::assertIsString($bytes);

            // The same valid PNG is rejected once the cap is below its size.
            self::assertSame('too_large', PreviewCapture::validateImageBytes($bytes, strlen($bytes) - 1));
            self::assertNull(PreviewCapture::validateImageBytes($bytes, strlen($bytes)));
        }

        public function testOversizeIsCheckedBeforeDecoding(): void
        {
            // Garbage that is also over the cap reports the size problem first,
            // so a huge body is never handed to the image parser.
            self::assertSame('too_large', PreviewCapture::validateImageBytes(str_repeat('A', 100), 10));
        }

        public function testNonPngJpegImageFails(): void
        {
            $bytes = base64_decode(self::GIF_1X1, true);
            self::assertIsString($bytes);
            self::assertSame('bad_mime', PreviewCapture::validateImageBytes($bytes));
        }

        public function testKsesAttributesStripsScriptRecursively(): void
        {
            $attrs = [
                'title' => 'Hello <script>alert(1)</script>world',
                'count' => 3,
                'items' => [
                    ['label' => '<script>x</script>ok'],
                ],
            ];

            $clean = PreviewCapture::ksesAttributes($attrs);

            // The assertion is "every string went through wp_kses_post, nothing
            // else was touched" rather than a fixed output: the bootstrap's
            // kses stub is an approximation of core's.
            self::assertSame(wp_kses_post($attrs['title']), $clean['title']);
            self::assertStringNotContainsString('<script>', $clean['title']);
            self::assertSame(3, $clean['count']);
            self::assertSame(wp_kses_post($attrs['items'][0]['label']), $clean['items'][0]['label']);
            self::assertStringNotContainsString('<script>', $clean['items'][0]['label']);
        }
    }
}
