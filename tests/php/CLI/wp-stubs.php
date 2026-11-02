<?php

declare(strict_types=1);

// Global-namespace stand-ins for the WordPress functions the CLI scaffold and
// the template helpers call. Loaded by CreateScaffoldTest; every stub is
// guarded so a real implementation (or another test's stub) wins.

// Minimal stand-ins for the WordPress functions the scaffold and the template
// helpers call. Each mirrors the real function closely enough for these tests;
// none is a substitute for the real thing.
if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        $title = strtolower(strip_tags((string) $title));
        $title = str_replace('.', '-', $title);
        $title = preg_replace('/[^%a-z0-9 _-]/', '', $title);
        $title = preg_replace('/\s+/', '-', trim($title));
        $title = preg_replace('|-+|', '-', $title);
        return trim($title, '-');
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
    }
}
if (!function_exists('sanitize_hex_color')) {
    function sanitize_hex_color($color) {
        if ('' === $color) {
            return '';
        }
        return preg_match('|^#([A-Fa-f0-9]{3}){1,2}$|', (string) $color) ? $color : null;
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_url')) {
    // Stand-in: allows http(s) and relative URLs, strips anything else, and
    // spells ' and & the way esc_url() does.
    function esc_url($url) {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $m) && !in_array(strtolower($m[1]), ['http', 'https'], true)) {
            return '';
        }
        $url = preg_replace('/[^a-z0-9\-~+_.?#=!&;,\/:%@$|*\'()\[\]\x80-\xff]/i', '', $url);
        return str_replace(["'", '&amp;'], ['&#039;', '&#038;'], $url);
    }
}
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!function_exists('proto_blocks_css_url')) {
    require_once __DIR__ . '/../../../includes/helpers.php';
}

