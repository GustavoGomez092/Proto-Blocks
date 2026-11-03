<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

/*
 * The shared WordPress stand-ins for every test directory.
 *
 * Any stub that more than one tests/php/<Dir> needs lives here, with ONE
 * shape, so which directory PHPUnit happens to load first never decides how
 * a stub behaves (`phpunit tests/php/OptionsPages tests/php/API` used to
 * break because OptionsPages' copies shadowed the API ones). Per-directory
 * wp-stubs.php files hold only stubs no other directory defines; do not add
 * a second copy of anything below there. Every stub is guarded, so a real
 * WordPress (were one loaded) wins.
 *
 * Globals tests may set or read:
 *   pb_test_hooks[$hook][]         ['callback' => ..., 'priority' => int, 'args' => int]
 *                                  per add_action()/add_filter()
 *   pb_test_filters[$hook][]       callables apply_filters() runs (tests register these)
 *   pb_test_actions[$hook][]       argument lists do_action() was called with
 *   pb_test_current_user           acting user id (default 0, logged out)
 *   pb_test_caps[$userId]          list of that user's capabilities
 *   pb_test_users[$id]             array|object user records (wp_get_current_user)
 *   pb_test_routes[]               ['namespace', 'route', 'args'] per register_rest_route()
 *   pb_test_options[$name]         option store (see get_option below)
 *   pb_test_transients[$key]       ['value' => ..., 'expiration' => int]
 *   pb_test_bloginfo[$show]        get_bloginfo() overrides
 *   pb_test_theme                  ['template', 'stylesheet', 'template_uri', 'stylesheet_uri']
 *   pb_test_block_theme            wp_is_block_theme() value (default true)
 */

// ------------------------------------------------------------------ i18n

if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}
if (!function_exists('_x')) {
    function _x($text, $context, $domain = 'default') { return $text; }
}
if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = 'default') { return (int) $number === 1 ? $single : $plural; }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') { return esc_html($text); }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = 'default') { return esc_attr($text); }
}

// ------------------------------------------------------------------ escaping

if (!function_exists('esc_html')) {
    // Like WordPress: encode <, >, &, " and ', never double-encoding an existing entity.
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', false); }
}
if (!function_exists('esc_attr')) {
    // Same encoding as esc_html(), as in WordPress.
    function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', false); }
}
if (!function_exists('wp_allowed_protocols')) {
    function wp_allowed_protocols(): array
    {
        return ['http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'tel', 'sms', 'feed'];
    }
}
if (!function_exists('esc_url')) {
    /** Stand-in: http(s)/mailto/tel and relative URLs pass, other schemes become ''; quotes are neutralised. */
    function esc_url($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $m) && !in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true)) {
            return '';
        }
        return str_replace(['"', "'"], ['', '&#039;'], $url);
    }
}
if (!function_exists('esc_url_raw')) {
    /** Strips characters URLs cannot hold, rejects disallowed schemes, adds http:// to bare hosts. */
    function esc_url_raw($url, $protocols = null): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        $url = (string) preg_replace('/[^a-z0-9-~+_.?#=!&;,\/:%@$|*\'()\[\]\\x80-\\xff]/i', '', $url);
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $m)) {
            return in_array(strtolower($m[1]), wp_allowed_protocols(), true) ? $url : '';
        }
        if (!preg_match('#^[/?\#]#', $url) && !str_starts_with($url, '//')) {
            $url = 'http://' . $url;
        }
        return $url;
    }
}

/*
 * The kses stubs are deliberately an approximation: an allowlist of tags, an
 * allowlist of attributes per tag, everything else removed. They model what
 * wp_kses()/wp_kses_post() do to the payloads under test; production code
 * relies on core's implementation.
 */
if (!function_exists('wp_kses')) {
    function wp_kses($string, $allowed_html, $allowed_protocols = [])
    {
        // Like wp_kses_normalize_entities(): a bare "&" becomes "&amp;".
        $string = preg_replace('/&(?!(?:#[0-9]+|#[xX][0-9a-fA-F]+|[A-Za-z][A-Za-z0-9]*);)/', '&amp;', (string) $string);

        return (string) preg_replace_callback(
            '/<(\/?)([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>|[<>]/',
            static function (array $m) use ($allowed_html): string {
                // Like wp_kses_split(): a "<" or ">" outside a tag becomes an entity.
                if ($m[0] === '<' || $m[0] === '>') {
                    return $m[0] === '<' ? '&lt;' : '&gt;';
                }
                $tag = strtolower($m[2]);
                if (!isset($allowed_html[$tag])) {
                    return '';
                }
                if ($m[1] === '/') {
                    return '</' . $tag . '>';
                }
                $attrs = '';
                preg_match_all('/([a-zA-Z-]+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/', $m[3], $found, PREG_SET_ORDER);
                foreach ($found as $a) {
                    $name = strtolower($a[1]);
                    if (!empty($allowed_html[$tag][$name])) {
                        $attrs .= ' ' . $name . '="' . htmlspecialchars(trim($a[2], "\"'"), ENT_QUOTES, 'UTF-8') . '"';
                    }
                }
                return '<' . $tag . $attrs . '>';
            },
            (string) $string
        );
    }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($string)
    {
        static $post = null;
        if ($post === null) {
            $common = ['class' => true, 'id' => true, 'title' => true, 'style' => true];
            $post = [
                'a' => $common + ['href' => true, 'rel' => true, 'target' => true],
                'img' => $common + ['src' => true, 'alt' => true, 'width' => true, 'height' => true, 'srcset' => true, 'sizes' => true],
                'textarea' => $common + ['name' => true],
            ];
            foreach (['p', 'div', 'span', 'strong', 'em', 'b', 'i', 'br', 'code', 'pre', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'figure', 'figcaption', 'section'] as $tag) {
                $post[$tag] = $common;
            }
        }
        return wp_kses($string, $post);
    }
}

// ------------------------------------------------------------------ sanitisers

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
if (!function_exists('sanitize_textarea_field')) {
    /** Like sanitize_text_field() but keeps line breaks and inner whitespace. */
    function sanitize_textarea_field($str)
    {
        if (is_array($str) || is_object($str)) {
            return '';
        }
        $str = (string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $str);
        return trim(strip_tags($str));
    }
}
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
if (!function_exists('sanitize_html_class')) {
    function sanitize_html_class($classname, $fallback = ''): string
    {
        $sanitized = (string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) $classname);
        return $sanitized === '' ? (string) $fallback : $sanitized;
    }
}
if (!function_exists('is_email')) {
    function is_email($email, $deprecated = false)
    {
        return filter_var((string) $email, FILTER_VALIDATE_EMAIL) !== false ? (string) $email : false;
    }
}
if (!function_exists('sanitize_email')) {
    function sanitize_email($email)
    {
        $email = (string) preg_replace("/[^a-zA-Z0-9!#$%&'*+\\/=?^_`{|}~.@-]/", '', trim((string) $email));
        return is_email($email) ? $email : '';
    }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit($value) { return rtrim((string) $value, '/\\') . '/'; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}

// ------------------------------------------------------------------ hooks

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        $GLOBALS['pb_test_hooks'][$hook][] = ['callback' => $callback, 'priority' => (int) $priority, 'args' => (int) $accepted_args];
        return true;
    }
}
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        return add_filter($hook, $callback, $priority, $accepted_args);
    }
}
if (!function_exists('has_filter')) {
    /** Like WordPress: without $callback, whether anything is hooked; with it, its priority or false. */
    function has_filter($hook, $callback = false)
    {
        $entries = $GLOBALS['pb_test_hooks'][$hook] ?? [];
        if ($callback === false) {
            return $entries !== [];
        }
        foreach ($entries as $entry) {
            if ($entry['callback'] === $callback) {
                return $entry['priority'];
            }
        }
        return false;
    }
}
if (!function_exists('has_action')) {
    function has_action($hook, $callback = false)
    {
        return has_filter($hook, $callback);
    }
}
if (!function_exists('remove_filter')) {
    function remove_filter($hook, $callback, $priority = 10): bool
    {
        $removed = false;
        foreach ($GLOBALS['pb_test_hooks'][$hook] ?? [] as $i => $entry) {
            if ($entry['callback'] === $callback && $entry['priority'] === (int) $priority) {
                unset($GLOBALS['pb_test_hooks'][$hook][$i]);
                $removed = true;
            }
        }
        if ($removed) {
            $GLOBALS['pb_test_hooks'][$hook] = array_values($GLOBALS['pb_test_hooks'][$hook]);
        }
        return $removed;
    }
}
if (!function_exists('remove_action')) {
    function remove_action($hook, $callback, $priority = 10): bool
    {
        return remove_filter($hook, $callback, $priority);
    }
}
if (!function_exists('apply_filters')) {
    // Tests register the callbacks that run in $GLOBALS['pb_test_filters'][$hook][]
    // (add_filter() only records, so code under test never filters itself).
    // The constant lets a test assert this stub (not a foreign one) is active.
    define('PB_FIELDS_TEST_APPLY_FILTERS', true);
    function apply_filters($hook, $value, ...$args) {
        foreach ($GLOBALS['pb_test_filters'][$hook] ?? [] as $callback) {
            $value = $callback($value, ...$args);
        }
        return $value;
    }
}
if (!function_exists('do_action')) {
    /** Records the call in pb_test_actions; like apply_filters(), it runs no add_action() callbacks. */
    function do_action($hook, ...$args): void
    {
        $GLOBALS['pb_test_actions'][$hook][] = $args;
    }
}
if (!function_exists('did_action')) {
    function did_action($hook): int
    {
        return count($GLOBALS['pb_test_actions'][$hook] ?? []);
    }
}

// ------------------------------------------------------------------ users and capabilities

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return (int) ($GLOBALS['pb_test_current_user'] ?? 0);
    }
}
if (!function_exists('user_can')) {
    function user_can($user, $capability, ...$args): bool
    {
        $id = is_object($user) ? (int) ($user->ID ?? 0) : (int) $user;
        return in_array($capability, $GLOBALS['pb_test_caps'][$id] ?? [], true);
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can($capability, ...$args): bool
    {
        return user_can(get_current_user_id(), $capability, ...$args);
    }
}
if (!function_exists('wp_get_current_user')) {
    /** pb_test_users[id] as an object, or a bare user with ID (0 when logged out). */
    function wp_get_current_user(): object
    {
        $id = get_current_user_id();
        $user = $GLOBALS['pb_test_users'][$id] ?? ['ID' => $id];
        $user = is_object($user) ? $user : (object) $user;
        $user->ID = $user->ID ?? $id;
        return $user;
    }
}

// ------------------------------------------------------------------ REST

if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = [], $override = false): bool
    {
        $GLOBALS['pb_test_routes'][] = ['namespace' => $namespace, 'route' => $route, 'args' => $args];
        return true;
    }
}
if (!function_exists('rest_url')) {
    function rest_url($path = '', $scheme = 'rest'): string
    {
        return 'https://example.test/wp-json/' . ltrim((string) $path, '/');
    }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '', $scheme = 'admin'): string
    {
        return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
    }
}
if (!function_exists('wp_create_nonce')) {
    /** The nonce for $action is "nonce:<action>"; wp_verify_nonce() accepts exactly that. */
    function wp_create_nonce($action = -1): string
    {
        return 'nonce:' . $action;
    }
}
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1)
    {
        return $nonce === wp_create_nonce($action) ? 1 : false;
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool { return $thing instanceof WP_Error; }
}
if (!class_exists('WP_Error')) {
    /** Minimal WP_Error: codes with messages and per-code data. */
    class WP_Error
    {
        /** @var array<string,string[]> */
        private array $errors = [];
        /** @var array<string,mixed> */
        private array $data = [];

        public function __construct($code = '', $message = '', $data = '')
        {
            if ($code !== '') {
                $this->add($code, $message, $data);
            }
        }

        public function add($code, $message, $data = ''): void
        {
            $this->errors[(string) $code][] = (string) $message;
            if ($data !== '') {
                $this->data[(string) $code] = $data;
            } elseif (!array_key_exists((string) $code, $this->data)) {
                $this->data[(string) $code] = $data;
            }
        }

        public function has_errors(): bool
        {
            return $this->errors !== [];
        }

        public function get_error_codes(): array
        {
            return array_keys($this->errors);
        }

        public function get_error_code()
        {
            $codes = array_keys($this->errors);
            return $codes[0] ?? '';
        }

        public function get_error_messages($code = ''): array
        {
            if ($code === '') {
                return array_merge([], ...array_values($this->errors));
            }
            return $this->errors[$code] ?? [];
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
    /**
     * Minimal WP_REST_Request. Two constructor forms:
     * - new WP_REST_Request(array $params = [], ?array $json = null): URL/body
     *   params plus an optional JSON body;
     * - new WP_REST_Request(string $method, string $route = ''), like core.
     */
    class WP_REST_Request implements \ArrayAccess
    {
        private string $method = 'GET';
        private string $route = '';
        /** @var array<string,mixed> */
        private array $params = [];
        /** @var array<string,mixed>|null */
        private ?array $json = null;
        /** @var array<string,mixed> */
        private array $headers = [];

        public function __construct(array|string $paramsOrMethod = [], array|string|null $jsonOrRoute = null)
        {
            if (is_string($paramsOrMethod)) {
                $this->method = strtoupper($paramsOrMethod);
                $this->route = is_string($jsonOrRoute) ? $jsonOrRoute : '';
                return;
            }
            $this->params = $paramsOrMethod;
            $this->json = is_array($jsonOrRoute) ? $jsonOrRoute : null;
        }

        public function get_method(): string { return $this->method; }
        public function set_method($method): void { $this->method = strtoupper((string) $method); }
        public function get_route(): string { return $this->route; }
        public function set_route($route): void { $this->route = (string) $route; }

        public function get_param($key)
        {
            return $this->params[$key] ?? $this->json[$key] ?? null;
        }

        public function has_param($key): bool
        {
            return array_key_exists($key, $this->params) || array_key_exists($key, $this->json ?? []);
        }

        public function set_param($key, $value): void
        {
            $this->params[$key] = $value;
        }

        /** @return array<string,mixed> JSON body merged under the other params. */
        public function get_params(): array
        {
            return array_merge($this->json ?? [], $this->params);
        }

        public function get_json_params(): ?array
        {
            return $this->json;
        }

        public function set_header($key, $value): void { $this->headers[strtolower((string) $key)] = $value; }
        public function get_header($key) { return $this->headers[strtolower((string) $key)] ?? null; }

        public function offsetExists(mixed $offset): bool { return $this->has_param($offset); }
        public function offsetGet(mixed $offset): mixed { return $this->get_param($offset); }
        public function offsetSet(mixed $offset, mixed $value): void { $this->set_param($offset, $value); }
        public function offsetUnset(mixed $offset): void { unset($this->params[$offset]); }
    }
}
if (!class_exists('WP_REST_Response')) {
    /** Minimal WP_REST_Response: data, status and headers. */
    class WP_REST_Response
    {
        /** @var mixed */
        private $data;
        private int $status;
        /** @var array<string,string> */
        private array $headers = [];

        public function __construct($data = null, int $status = 200, array $headers = [])
        {
            $this->data = $data;
            $this->status = $status;
            $this->headers = $headers;
        }

        public function get_data() { return $this->data; }
        public function set_data($data): void { $this->data = $data; }
        public function get_status(): int { return $this->status; }
        public function set_status($status): void { $this->status = (int) $status; }
        public function header($key, $value, $replace = true): void { $this->headers[(string) $key] = (string) $value; }
        public function get_headers(): array { return $this->headers; }
    }
}

// ------------------------------------------------------------------ options and transients

// The one option store for every test directory. Backed by
// $GLOBALS['pb_test_options'] (tests may seed or read it directly). Every
// update_option() call is recorded in pb_test_update_calls (count) and
// pb_test_update_log ([name, value, autoload]); the autoload argument per
// option lands in pb_test_autoload[$name] (null when not passed).
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
if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0): bool
    {
        $GLOBALS['pb_test_transients'][$transient] = ['value' => $value, 'expiration' => (int) $expiration];
        return true;
    }
}
if (!function_exists('get_transient')) {
    function get_transient($transient)
    {
        return $GLOBALS['pb_test_transients'][$transient]['value'] ?? false;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient($transient): bool
    {
        $had = isset($GLOBALS['pb_test_transients'][$transient]);
        unset($GLOBALS['pb_test_transients'][$transient]);
        return $had;
    }
}

// ------------------------------------------------------------------ site, theme and files

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '', $filter = 'raw')
    {
        $defaults = ['version' => '6.8.3', 'name' => 'Test site', 'charset' => 'UTF-8', 'language' => 'en-US'];
        return (string) ($GLOBALS['pb_test_bloginfo'][$show] ?? $defaults[$show] ?? '');
    }
}
// Theme directories: stylesheet values fall back to the template ones (a
// theme without a child).
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
if (!function_exists('add_post_type_support')) {
    function add_post_type_support($post_type, $feature, ...$args)
    {
        foreach ((array) $feature as $one) {
            $GLOBALS['pb_test_supports'][$post_type][$one] = $args === [] ? true : $args;
        }
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

// ------------------------------------------------------------------ notices

if (!function_exists('_doing_it_wrong')) {
    /** Records [function, message, version] in pb_test_doing_it_wrong[]. */
    function _doing_it_wrong($fn, $message, $version)
    {
        $GLOBALS['pb_test_doing_it_wrong'][] = [$fn, $message, $version];
    }
}

// ------------------------------------------------------------------ plugin helpers

// The plugin's global helpers (proto_blocks_sanitize_color(), ...) are real
// code, not stubs; they are loaded once here so no test needs its own guarded
// require. helpers.php exits without ABSPATH.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!function_exists('proto_blocks_sanitize_color')) {
    require_once __DIR__ . '/../../includes/helpers.php';
}
