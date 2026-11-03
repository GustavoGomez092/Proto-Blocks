<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}
if (!function_exists('esc_html')) {
    function esc_html($text) { return $text; }
}
if (!function_exists('sanitize_text_field')) {
    // Minimal stub — does not strip tags or normalize whitespace like the real WP function.
    function sanitize_text_field($text) { return is_string($text) ? trim($text) : $text; }
}
if (!function_exists('apply_filters')) {
    // The one apply_filters() stub for every test directory, so load order
    // never decides which shape wins. Tests register callbacks in
    // $GLOBALS['pb_test_filters'][$hook][]; the constant lets a test assert
    // this stub (not a real or foreign one) is active.
    define('PB_FIELDS_TEST_APPLY_FILTERS', true);
    function apply_filters($hook, $value, ...$args) {
        foreach ($GLOBALS['pb_test_filters'][$hook] ?? [] as $callback) {
            $value = $callback($value, ...$args);
        }
        return $value;
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
// The one option store for every test directory, so load order never decides
// which shape wins. Backed by $GLOBALS['pb_test_options'] (tests may seed or
// read it directly). Every update_option() call is recorded in
// pb_test_update_calls (count) and pb_test_update_log ([name, value, autoload]);
// the autoload argument per option lands in pb_test_autoload[$name] (null when
// not passed).
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return array_key_exists($name, $GLOBALS['pb_test_options'] ?? []) ? $GLOBALS['pb_test_options'][$name] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null): bool {
        $GLOBALS['pb_test_update_calls'] = ($GLOBALS['pb_test_update_calls'] ?? 0) + 1;
        $GLOBALS['pb_test_update_log'][] = [$name, $value, $autoload];
        $GLOBALS['pb_test_autoload'][$name] = $autoload;
        $exists = array_key_exists($name, $GLOBALS['pb_test_options'] ?? []);
        if ($exists && $GLOBALS['pb_test_options'][$name] === $value) {
            return false; // WordPress: unchanged value is not a write.
        }
        $GLOBALS['pb_test_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('add_option')) {
    function add_option($name, $value = '', $deprecated = '', $autoload = 'yes'): bool {
        if (array_key_exists($name, $GLOBALS['pb_test_options'] ?? [])) {
            return false;
        }
        $GLOBALS['pb_test_options'][$name] = $value;
        $GLOBALS['pb_test_autoload'][$name] = $autoload;
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option($name): bool {
        $existed = array_key_exists($name, $GLOBALS['pb_test_options'] ?? []);
        unset($GLOBALS['pb_test_options'][$name], $GLOBALS['pb_test_autoload'][$name]);
        return $existed;
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
    class WP_Block {}
}
if (!class_exists('WP_CLI_Command')) {
    /** Minimal stub so ProtoBlocks\CLI\Commands can be loaded without WP-CLI. */
    class WP_CLI_Command {}
}
