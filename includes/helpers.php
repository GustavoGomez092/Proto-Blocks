<?php
/**
 * Global Helper Functions
 *
 * These functions are available in the global namespace for use in templates.
 *
 * @package ProtoBlocks
 */

// Prevent direct access
defined('ABSPATH') || exit;

/**
 * Convert hex color to rgba
 *
 * @param string $hex Hex color (with or without #)
 * @param float $alpha Alpha value (0-1)
 * @return string RGBA color string
 */
function proto_blocks_hex_to_rgba(string $hex, float $alpha = 1): string
{
    $hex = ltrim($hex, '#');

    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    return sprintf('rgba(%d, %d, %d, %s)', $r, $g, $b, $alpha);
}
