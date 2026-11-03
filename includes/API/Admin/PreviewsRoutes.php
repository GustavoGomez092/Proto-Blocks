<?php
/**
 * PreviewsRoutes - REST routes for the Preview Capture screen
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\API\Admin;

use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\Admin\PreviewCapture;
use ProtoBlocks\Core\Plugin;

/**
 * GET admin/previews: every registered block with its preview status and
 * render URL, plus the capture width and settle time. Read-only; captured
 * images are uploaded through the existing POST preview-capture route
 * (PreviewCapture::registerRestRoute), which keeps its own file-mods gate.
 */
final class PreviewsRoutes
{
    /** Width the render document is laid out and captured at, in pixels. */
    public const CAPTURE_WIDTH = 1280;

    private ?PreviewCapture $capture;

    public function __construct(?PreviewCapture $capture = null)
    {
        $this->capture = $capture;
    }

    public function registerRoutes(string $namespace, callable $permission, callable $writePermission): void
    {
        register_rest_route($namespace, '/' . AdminRestController::BASE . '/previews', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get'],
                'permission_callback' => $permission,
            ],
        ]);
    }

    /** @return array{blocks:list<array<string,mixed>>,width:int,settleMs:int} */
    public function get(): array
    {
        $blocks = [];
        foreach ($this->capture()->collectBlocks() as $block) {
            // The absolute folder path is server detail the screen never shows.
            unset($block['path']);
            $blocks[] = $block;
        }

        return [
            'blocks' => $blocks,
            'width' => self::CAPTURE_WIDTH,
            /**
             * Milliseconds the capture waits after fonts and images have
             * settled before it rasterises, so load-time animation (reveals,
             * counters, a hero zoom) lands at its final frame.
             *
             * @param int $ms Default 1000.
             */
            'settleMs' => max(0, (int) apply_filters('proto_blocks_capture_settle_ms', 1000)),
        ];
    }

    private function capture(): PreviewCapture
    {
        return $this->capture ??= Plugin::getInstance()->getPreviewCapture();
    }
}
