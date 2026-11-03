<?php

declare(strict_types=1);

// Theme directory stand-ins for the content-model tests. Every stub
// is guarded so the first definition wins (PHPUnit loads all test files into
// one process); each has the same shape as its siblings elsewhere in tests/php.
//
// Tests set $GLOBALS['pb_test_theme'] = [
//     'template' => '/path/parent', 'stylesheet' => '/path/child',
//     'template_uri' => 'http://...', 'stylesheet_uri' => 'http://...',
// ]; stylesheet values fall back to the template ones (a theme without a child).

if (!function_exists('get_template_directory')) {
    function get_template_directory() {
        return (string) ($GLOBALS['pb_test_theme']['template'] ?? '');
    }
}
if (!function_exists('get_stylesheet_directory')) {
    function get_stylesheet_directory() {
        return (string) ($GLOBALS['pb_test_theme']['stylesheet'] ?? get_template_directory());
    }
}
if (!function_exists('get_template_directory_uri')) {
    function get_template_directory_uri() {
        return (string) ($GLOBALS['pb_test_theme']['template_uri'] ?? 'http://example.test/wp-content/themes/parent');
    }
}
if (!function_exists('get_stylesheet_directory_uri')) {
    function get_stylesheet_directory_uri() {
        return (string) ($GLOBALS['pb_test_theme']['stylesheet_uri'] ?? get_template_directory_uri());
    }
}
if (!function_exists('wp_is_block_theme')) {
    function wp_is_block_theme() {
        return (bool) ($GLOBALS['pb_test_block_theme'] ?? true);
    }
}
// apply_filters() is stubbed once in tests/php/bootstrap.php.
