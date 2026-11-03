<?php
/**
 * WordPress stand-ins only the Admin / API unit tests use. Shared stubs
 * (users and capabilities, REST classes and register_rest_route, nonces,
 * URLs, escaping, options) live in tests/php/bootstrap.php; do not copy them
 * here. Every stub is guarded so a real WordPress wins.
 *
 * Globals tests may set:
 * - $GLOBALS['pb_test_enqueued_scripts']    script handles reported as enqueued
 * Stubs collect into:
 * - $GLOBALS['pb_test_inline']              wp_add_inline_script calls
 */

declare(strict_types=1);

if (!function_exists('wp_is_writable')) {
    function wp_is_writable($path): bool
    {
        return is_writable((string) $path);
    }
}

if (!function_exists('wp_script_is')) {
    function wp_script_is($handle, $status = 'enqueued'): bool
    {
        return in_array($handle, $GLOBALS['pb_test_enqueued_scripts'] ?? [], true);
    }
}

if (!function_exists('wp_add_inline_script')) {
    function wp_add_inline_script($handle, $data, $position = 'after'): bool
    {
        $GLOBALS['pb_test_inline'][] = ['handle' => $handle, 'data' => $data, 'position' => $position];
        return true;
    }
}
