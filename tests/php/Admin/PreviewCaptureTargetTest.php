<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Admin {

    use PHPUnit\Framework\TestCase;
    use ProtoBlocks\Admin\PreviewCapture;

    /**
     * A capture of a block bundled in the plugin's examples/ folder is written
     * under uploads, never inside the plugin directory.
     */
    final class PreviewCaptureTargetTest extends TestCase
    {
        private string $root;

        protected function setUp(): void
        {
            $this->root = sys_get_temp_dir() . '/pb-capture-' . bin2hex(random_bytes(4));
            mkdir($this->root . '/plugin/examples/cta', 0777, true);
            mkdir($this->root . '/theme/blocks/hero', 0777, true);
        }

        protected function tearDown(): void
        {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->root);
        }

        public function testBundledExampleIsRedirectedToUploads(): void
        {
            $uploads = $this->root . '/uploads/proto-blocks/previews';
            $t = PreviewCapture::captureTarget($this->root . '/plugin/examples/cta', $this->root . '/plugin/examples', $uploads);

            self::assertTrue($t['redirected']);
            self::assertSame($uploads, $t['dir']);
            self::assertSame($uploads . '/cta.png', $t['path']);
        }

        public function testThemeBlockStillWritesNextToBlockJson(): void
        {
            $dir = $this->root . '/theme/blocks/hero';
            $t = PreviewCapture::captureTarget($dir, $this->root . '/plugin/examples', $this->root . '/uploads/x');

            self::assertFalse($t['redirected']);
            self::assertSame($dir . '/preview.png', $t['path']);
        }

        public function testDotDotSegmentsCannotEscapeConfinement(): void
        {
            $sneaky = $this->root . '/plugin/examples/../examples/cta';
            $t = PreviewCapture::captureTarget($sneaky, $this->root . '/plugin/examples', $this->root . '/uploads/x');
            self::assertTrue($t['redirected']);

            $outside = $this->root . '/plugin/examples/cta/../../../theme/blocks/hero';
            $t = PreviewCapture::captureTarget($outside, $this->root . '/plugin/examples', $this->root . '/uploads/x');
            self::assertFalse($t['redirected']);
        }

        public function testSiblingFolderWithSharedPrefixIsNotInsideExamples(): void
        {
            mkdir($this->root . '/plugin/examples-evil/cta', 0777, true);
            $t = PreviewCapture::captureTarget($this->root . '/plugin/examples-evil/cta', $this->root . '/plugin/examples', $this->root . '/uploads/x');
            self::assertFalse($t['redirected']);
        }

        public function testSymlinkOutOfExamplesIsNotRedirected(): void
        {
            symlink($this->root . '/theme/blocks/hero', $this->root . '/plugin/examples/linked');
            $t = PreviewCapture::captureTarget($this->root . '/plugin/examples/linked', $this->root . '/plugin/examples', $this->root . '/uploads/x');
            self::assertFalse($t['redirected']);
            self::assertSame($this->root . '/plugin/examples/linked/preview.png', $t['path']);
        }

        public function testUnsafeFolderNamesAreRejectedNotStripped(): void
        {
            mkdir($this->root . '/plugin/examples/Bad_Name', 0777, true);
            $t = PreviewCapture::captureTarget($this->root . '/plugin/examples/Bad_Name', $this->root . '/plugin/examples', $this->root . '/uploads/x');
            self::assertTrue($t['invalid']);
            self::assertSame('', $t['path']);
        }

        public function testNoUploadsFolderRejectsExampleWrites(): void
        {
            $t = PreviewCapture::captureTarget($this->root . '/plugin/examples/cta', $this->root . '/plugin/examples', '');
            self::assertTrue($t['invalid']);
        }

        public function testExamplesFolderItselfIsNotRedirected(): void
        {
            $t = PreviewCapture::captureTarget($this->root . '/plugin/examples', $this->root . '/plugin/examples', $this->root . '/uploads/x');
            self::assertFalse($t['redirected']);
        }
    }
}
