<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\API;

require_once __DIR__ . '/../Security/wp-stubs.php';
require_once __DIR__ . '/../Admin/wp-stubs.php';

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Admin\PreviewCapture;
use ProtoBlocks\API\Admin\PreviewsRoutes;
use ProtoBlocks\API\AdminRestController;

/**
 * GET admin/previews lists the blocks for the Preview Capture screen. It is
 * read-only and gated on manage_options; uploads stay on POST preview-capture.
 */
final class PreviewsRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pb_test_current_user'] = 1;
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options']];
        $GLOBALS['pb_test_routes'] = [];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['pb_test_current_user'],
            $GLOBALS['pb_test_caps'],
            $GLOBALS['pb_test_routes'],
            $GLOBALS['pb_test_filters']['proto_blocks_capture_settle_ms']
        );
    }

    private function capture(): PreviewCapture
    {
        return new class extends PreviewCapture {
            public function collectBlocks(): array
            {
                return [
                    [
                        'name' => 'hero',
                        'title' => 'Hero',
                        'path' => '/srv/wp/wp-content/themes/t/blocks/hero',
                        'hasPreview' => true,
                        'previewUrl' => 'https://example.test/wp-content/themes/t/blocks/hero/preview.png',
                        'previewMtime' => 123,
                        'renderUrl' => 'https://example.test/wp-admin/admin-ajax.php?action=proto_blocks_capture_render&block=hero',
                    ],
                ];
            }
        };
    }

    public function testRouteGroupsListPreviewsRoutes(): void
    {
        self::assertContains(PreviewsRoutes::class, AdminRestController::ROUTE_GROUPS);
    }

    public function testRegistersGetGatedOnManageOptions(): void
    {
        $controller = new AdminRestController([new PreviewsRoutes($this->capture())]);
        $controller->registerRoutes();

        self::assertCount(1, $GLOBALS['pb_test_routes']);
        $route = $GLOBALS['pb_test_routes'][0];
        self::assertSame('proto-blocks/v1', $route['namespace']);
        self::assertSame('/admin/previews', $route['route']);
        self::assertCount(1, $route['args']);
        self::assertSame('GET', $route['args'][0]['methods']);
        self::assertTrue(($route['args'][0]['permission_callback'])());

        $GLOBALS['pb_test_caps'] = [1 => ['edit_posts']];
        self::assertFalse(($route['args'][0]['permission_callback'])());
    }

    public function testPayloadDropsPathAndCarriesWidthAndSettle(): void
    {
        $data = (new PreviewsRoutes($this->capture()))->get();

        self::assertSame(1280, $data['width']);
        self::assertSame(1000, $data['settleMs']);
        self::assertCount(1, $data['blocks']);
        self::assertSame(
            ['name', 'title', 'hasPreview', 'previewUrl', 'previewMtime', 'renderUrl'],
            array_keys($data['blocks'][0])
        );
        self::assertSame('hero', $data['blocks'][0]['name']);
    }

    public function testSettleMsIsFilterableAndNeverNegative(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_capture_settle_ms'] = [static fn() => 250];
        self::assertSame(250, (new PreviewsRoutes($this->capture()))->get()['settleMs']);

        $GLOBALS['pb_test_filters']['proto_blocks_capture_settle_ms'] = [static fn() => -5];
        self::assertSame(0, (new PreviewsRoutes($this->capture()))->get()['settleMs']);
    }

    public function testCollectBlocksIsPublicAndLegacyRenderingIsGone(): void
    {
        self::assertTrue((new \ReflectionMethod(PreviewCapture::class, 'collectBlocks'))->isPublic());
        self::assertFalse(method_exists(PreviewCapture::class, 'renderAdminUi'));
        self::assertFalse(method_exists(PreviewCapture::class, 'enqueueAssets'));
    }
}
