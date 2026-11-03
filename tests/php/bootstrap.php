<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}
if (!function_exists('esc_html')) {
    // Like WordPress: encode <, >, &, " and ', never double-encoding an existing entity.
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', false); }
}
if (!function_exists('sanitize_text_field')) {
    /**
     * Stand-in for WordPress's sanitize_text_field(): arrays and objects become
     * '', <script>/<style> elements are removed with their content, remaining
     * tags are stripped, whitespace runs collapse to one space, then trim.
     */
    function sanitize_text_field($text) {
        if (is_array($text) || is_object($text)) {
            return '';
        }
        $text = (string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text);
        $text = strip_tags($text);
        $text = (string) preg_replace('/[\r\n\t ]+/', ' ', $text);
        return trim($text);
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() {
        $base = sys_get_temp_dir() . '/pb-uploads';
        return ['basedir' => $base, 'baseurl' => 'http://example.test/uploads'];
    }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0777, true); }
}
if (!function_exists('get_option')) {
    // Tests set $GLOBALS['pb_test_options'][$name] to control return values.
    function get_option($name, $default = false) {
        return $GLOBALS['pb_test_options'][$name] ?? $default;
    }
}
if (!function_exists('set_url_scheme')) {
    // Minimal stand-in for WP's set_url_scheme: rewrite the leading scheme.
    function set_url_scheme($url, $scheme = null) {
        $scheme = $scheme ?: 'http';
        return preg_replace('#^\w+://#', $scheme . '://', (string) $url);
    }
}
if (!class_exists('WP_Block')) {
    /** Minimal stub: the real WP_Block isn't loaded in unit tests. */
    class WP_Block {
        /** Block context, as core's WP_Block exposes it. */
        public array $context = [];
    }
}
if (!class_exists('WP_CLI_Command')) {
    /** Minimal stub so ProtoBlocks\CLI\Commands can be loaded without WP-CLI. */
    class WP_CLI_Command {}
}
