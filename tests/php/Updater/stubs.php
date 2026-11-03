<?php
/**
 * WordPress stand-ins needed by the Updater unit tests only. WP_Error,
 * is_wp_error() and the other shared stubs live in tests/php/bootstrap.php.
 * Every stub is guarded so a real WordPress wins.
 */

declare(strict_types=1);

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) { return parse_url((string) $url, $component); }
}
