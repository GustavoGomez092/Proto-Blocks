<?php

declare(strict_types=1);

/**
 * Global-namespace WordPress stubs for the OptionsPages tests. Loaded via
 * require_once; not a test file. Everything is guarded so a real WordPress or a
 * stub from tests/php/bootstrap.php always takes precedence.
 */

if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return $GLOBALS['pb_test_options'][$name] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null)
    {
        $GLOBALS['pb_test_options'][$name] = $value;
        $GLOBALS['pb_test_autoload'][$name] = $autoload;
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option($name)
    {
        unset($GLOBALS['pb_test_options'][$name], $GLOBALS['pb_test_autoload'][$name]);
        return true;
    }
}

$GLOBALS['menu'] = $GLOBALS['menu'] ?? [];
$GLOBALS['submenu'] = $GLOBALS['submenu'] ?? [];
$GLOBALS['admin_page_hooks'] = $GLOBALS['admin_page_hooks'] ?? ($GLOBALS['pb_test_parent_menus'] ?? []);

if (!function_exists('add_menu_page')) {
    function add_menu_page($page_title, $menu_title, $capability, $slug, $fn = '', $icon = '', $position = null)
    {
        $GLOBALS['pb_test_menus'][] = compact('fn', 'page_title', 'menu_title', 'capability', 'slug', 'icon', 'position') + ['parent' => null];
        return 'hook_' . $slug;
    }
}
if (!function_exists('add_submenu_page')) {
    function add_submenu_page($parent, $page_title, $menu_title, $capability, $slug, $fn = '', $position = null)
    {
        $GLOBALS['pb_test_menus'][] = compact('fn', 'parent', 'page_title', 'menu_title', 'capability', 'slug', 'position') + ['icon' => null];
        return 'hook_' . $slug;
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap)
    {
        return user_can(get_current_user_id(), $cap);
    }
}
if (!function_exists('user_can')) {
    function user_can($user, $cap)
    {
        return in_array($cap, $GLOBALS['pb_test_caps'][is_object($user) ? ($user->ID ?? 0) : $user] ?? [], true);
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id()
    {
        return $GLOBALS['pb_test_user_id'] ?? 0;
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($title)
    {
        $title = strtolower(strip_tags((string) $title));
        $title = preg_replace('/[^a-z0-9_-]+/', '-', $title);
        return trim((string) $title, '-');
    }
}
if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}
if (!function_exists('add_action')) {
    function add_action($tag, $cb, $priority = 10, $args = 1)
    {
        $GLOBALS['pb_test_hooks'][$tag][] = $cb;
        return true;
    }
}
if (!function_exists('add_filter')) {
    function add_filter($tag, $cb, $priority = 10, $args = 1)
    {
        $GLOBALS['pb_test_hooks'][$tag][] = $cb;
        return true;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args)
    {
        foreach ($GLOBALS['pb_test_filters'][$tag] ?? [] as $cb) {
            $value = $cb($value, ...$args);
        }
        return $value;
    }
}
if (!function_exists('_doing_it_wrong')) {
    function _doing_it_wrong($fn, $message, $version)
    {
        $GLOBALS['pb_test_doing_it_wrong'][] = [$fn, $message, $version];
    }
}
if (!class_exists('PbTestWpDie')) {
    class PbTestWpDie extends \RuntimeException
    {
        public function __construct(string $message, public readonly int $status = 500)
        {
            parent::__construct($message);
        }
    }
}
if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = [])
    {
        $status = is_array($args) ? (int) ($args['response'] ?? 500) : (int) $args;
        throw new PbTestWpDie(is_string($message) ? $message : 'wp_die', $status);
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(public $code = '', public $message = '', public $data = '') {}
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request implements \ArrayAccess
    {
        private array $params = [];
        private array $headers = [];
        public function __construct(private string $method = 'GET', private string $route = '') {}
        public function get_method() { return $this->method; }
        public function get_route() { return $this->route; }
        public function set_param($k, $v) { $this->params[$k] = $v; }
        public function get_param($k) { return $this->params[$k] ?? null; }
        public function get_params() { return $this->params; }
        public function get_json_params() { return $this->params; }
        public function set_header($k, $v) { $this->headers[strtolower($k)] = $v; }
        public function get_header($k) { return $this->headers[strtolower($k)] ?? null; }
        public function offsetExists(mixed $o): bool { return isset($this->params[$o]); }
        public function offsetGet(mixed $o): mixed { return $this->params[$o] ?? null; }
        public function offsetSet(mixed $o, mixed $v): void { $this->params[$o] = $v; }
        public function offsetUnset(mixed $o): void { unset($this->params[$o]); }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(public $data = null, public int $status = 200) {}
        public function get_data() { return $this->data; }
        public function get_status() { return $this->status; }
    }
}
if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = [], $override = false)
    {
        $GLOBALS['pb_test_routes'][$namespace . $route] = $args;
        return true;
    }
}
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new class {
        public string $prefix = 'wp_';
    };
}
