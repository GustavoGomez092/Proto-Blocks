<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Admin {

    use PHPUnit\Framework\TestCase;
    use ProtoBlocks\Admin\PreviewCapture;
    use ProtoBlocks\Schema\SchemaReader;

    /**
     * The inserter preview of a bundled example is the uploads capture when one
     * exists, and the file shipped in the plugin otherwise.
     */
    final class PreviewCaptureUploadsLookupTest extends TestCase
    {
        private string $plugin;
        private string $uploads;

        protected function setUp(): void
        {
            if (!defined('PROTO_BLOCKS_DIR')) {
                define('PROTO_BLOCKS_DIR', dirname(__DIR__, 3) . '/');
            }
            // The bundled example shipped in the repository (read only here).
            $this->plugin = rtrim((string) PROTO_BLOCKS_DIR, '/');
            if (!is_file($this->plugin . '/examples/cta/preview.png')) {
                self::markTestSkipped('examples/cta is not available.');
            }
            $this->uploads = sys_get_temp_dir() . '/pb-uploads/proto-blocks/previews';
            @unlink($this->uploads . '/cta.png');
        }

        protected function tearDown(): void
        {
            @unlink($this->uploads . '/cta.png');
        }

        public function testBundledPreviewIsUsedWhenNoCaptureExists(): void
        {
            self::assertNull(PreviewCapture::uploadsPreviewFor($this->plugin . '/examples/cta'));

            $schema = (new SchemaReader())->read($this->plugin . '/examples/cta');
            self::assertSame($this->plugin . '/examples/cta/preview.png', $schema['protoBlocks']['previewImage']);
        }

        public function testUploadsCaptureWinsAndHasTheUploadsUrl(): void
        {
            is_dir($this->uploads) || mkdir($this->uploads, 0777, true);
            file_put_contents($this->uploads . '/cta.png', 'captured');

            $found = PreviewCapture::uploadsPreviewFor($this->plugin . '/examples/cta');
            self::assertNotNull($found);
            self::assertSame('http://example.test/uploads/proto-blocks/previews/cta.png', $found['url']);

            $schema = (new SchemaReader())->read($this->plugin . '/examples/cta');
            self::assertSame($this->uploads . '/cta.png', $schema['protoBlocks']['previewImage']);
        }
    }
}
