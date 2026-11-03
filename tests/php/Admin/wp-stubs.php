<?php
/**
 * WordPress stand-ins for the Admin unit tests. Every stub is guarded so a
 * definition from another test file (or bootstrap) wins.
 *
 * Globals tests may set:
 * - $GLOBALS['pb_test_caps'][$userId]       capabilities of a user
 * - $GLOBALS['pb_test_current_user']        acting user id (default 1;
 *   $GLOBALS['pb_test_user'] is accepted as an alias)
 * - $GLOBALS['pb_test_enqueued_scripts']    script handles reported as enqueued
 * Stubs collect into:
 * - $GLOBALS['pb_test_inline']              wp_add_inline_script calls
 * - $GLOBALS['pb_test_routes']              register_rest_route calls
 */

declare(strict_types=1);

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return (int) ($GLOBALS['pb_test_current_user'] ?? $GLOBALS['pb_test_user'] ?? 1);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability, ...$args): bool
    {
        return in_array($capability, $GLOBALS['pb_test_caps'][get_current_user_id()] ?? [], true);
    }
}

if (!function_exists('rest_url')) {
    function rest_url($path = '', $scheme = 'rest'): string
    {
        return 'https://example.test/wp-json/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1): string
    {
        return 'nonce-' . $action;
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '', $scheme = 'admin'): string
    {
        return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url, $protocols = null): string
    {
        return (string) $url;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options, $depth);
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

if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = [], $override = false): bool
    {
        $GLOBALS['pb_test_routes'][] = ['namespace' => $namespace, 'route' => $route, 'args' => $args];
        return true;
    }
}

if (!class_exists('WP_Error')) {
    /** Minimal WP_Error: code, message and data. */
    class WP_Error
    {
        /** @var array<string,string[]> */
        private array $errors = [];
        /** @var array<string,mixed> */
        private array $data = [];

        public function __construct($code = '', $message = '', $data = '')
        {
            if ($code !== '') {
                $this->errors[(string) $code][] = (string) $message;
                $this->data[(string) $code] = $data;
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
            $code = $code === '' ? $this->get_error_code() : $code;
            return $this->data[$code] ?? null;
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    /** Minimal WP_REST_Request: params plus an optional JSON body. */
    class WP_REST_Request
    {
        /** @var array<string,mixed> */
        private array $params;
        /** @var array<string,mixed>|null */
        private ?array $json;

        public function __construct(array $params = [], ?array $json = null)
        {
            $this->params = $params;
            $this->json = $json;
        }

        public function get_param($key)
        {
            return $this->params[$key] ?? $this->json[$key] ?? null;
        }

        public function set_param($key, $value): void
        {
            $this->params[$key] = $value;
        }

        public function get_json_params(): ?array
        {
            return $this->json;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    /** Minimal WP_REST_Response: data plus status. */
    class WP_REST_Response
    {
        /** @var mixed */
        private $data;
        private int $status;

        public function __construct($data = null, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        public function get_data()
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}
