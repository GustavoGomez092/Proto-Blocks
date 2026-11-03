<?php

declare(strict_types=1);

// ABSPATH and the plugin helpers the CLI scaffold and content-model tests
// need. The WordPress stand-ins they call (sanitize_*, esc_*) are shared and
// live in tests/php/bootstrap.php; do not copy them here.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!function_exists('proto_blocks_css_url')) {
    require_once __DIR__ . '/../../../includes/helpers.php';
}
