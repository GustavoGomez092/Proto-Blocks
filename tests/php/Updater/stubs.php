<?php
/**
 * WordPress stand-ins needed by the Updater unit tests only. Every stub is
 * guarded so a definition from another test file (or bootstrap) wins.
 */

declare(strict_types=1);

if (!class_exists('WP_Error')) {
    /** Minimal WP_Error: code + message, enough for assertions. */
    class WP_Error
    {
        /** @var array<string,string[]> */
        private array $errors = [];
        /** @var mixed */
        private $data;

        public function __construct($code = '', $message = '', $data = '')
        {
            if ($code !== '') {
                $this->errors[(string) $code][] = (string) $message;
                $this->data = $data;
            }
        }

        public function get_error_code()
        {
            $codes = array_keys($this->errors);
            return $codes[0] ?? '';
        }

        public function get_error_message($code = '')
        {
            $code = $code === '' ? $this->get_error_code() : $code;
            return $this->errors[$code][0] ?? '';
        }

        public function get_error_data($code = '')
        {
            return $this->data;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) { return parse_url((string) $url, $component); }
}

if (!function_exists('apply_filters')) {
    /**
     * Same shape as tests/php/Fields/wp-stubs.php so either definition may
     * win: tests register callbacks in $GLOBALS['pb_test_filters'][$hook][].
     */
    function apply_filters($hook, $value, ...$args) {
        foreach ($GLOBALS['pb_test_filters'][$hook] ?? [] as $callback) {
            $value = $callback($value, ...$args);
        }
        return $value;
    }
}
