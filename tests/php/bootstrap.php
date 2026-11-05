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
 *   pb_test_multisite              is_multisite() value (default false)
 *   pb_test_super_admins           list of super admin user ids (is_super_admin)
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
        $caps = $GLOBALS['pb_test_caps'][$id] ?? [];
        // Per-object grant: pb_test_caps[user][cap] = [objectId => true]; the object id is required.
        if (is_string($capability) && isset($caps[$capability]) && is_array($caps[$capability])) {
            $object = $args[0] ?? null;
            return $object !== null && !empty($caps[$capability][(int) $object]);
        }
        return in_array($capability, $caps, true);
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
if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return (bool) ($GLOBALS['pb_test_multisite'] ?? false);
    }
}
if (!function_exists('is_super_admin')) {
    function is_super_admin($user_id = false): bool
    {
        $id = $user_id === false ? get_current_user_id() : (int) $user_id;
        return $id > 0 && in_array($id, $GLOBALS['pb_test_super_admins'] ?? [], true);
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
        if (isset($GLOBALS['pb_test_option_reads'])) {
            $GLOBALS['pb_test_option_reads'][] = $name; // tests opt in to see which options were read
        }
        return array_key_exists($name, $GLOBALS['pb_test_options'] ?? []) ? $GLOBALS['pb_test_options'][$name] : $default;
    }
}
if (!function_exists('wp_load_alloptions')) {
    /** The autoloaded options: pb_test_options minus those last written with autoload false. */
    function wp_load_alloptions($force_cache = false): array {
        $GLOBALS['pb_test_alloptions_calls'] = ($GLOBALS['pb_test_alloptions_calls'] ?? 0) + 1;
        return array_filter(
            $GLOBALS['pb_test_options'] ?? [],
            static fn($name): bool => ($GLOBALS['pb_test_autoload'][$name] ?? true) !== false,
            ARRAY_FILTER_USE_KEY
        );
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

// ------------------------------------------------------------------ posts and users (shared)

// Moved here from tests/php/Modules/Fields/wp-stubs.php (2026-11-05) because
// the Redirects and Scripts modules need the same shapes. Globals:
//   pb_test_posts[$id]          array|object post records
//   pb_test_current_post        get_the_ID() value
//   pb_test_queried_object      get_queried_object() value
//   pb_test_permalinks[$id]     get_permalink() values
//   pb_test_post_types[$slug]  post type objects (get_post_types / get_post_type_object)
if (!function_exists('pb_test_obj')) {
    /** Array → object, object as is, anything else null. */
    function pb_test_obj(mixed $value): ?object
    {
        if (is_object($value)) {
            return $value;
        }
        return is_array($value) ? (object) $value : null;
    }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in()
    {
        return get_current_user_id() > 0;
    }
}
if (!function_exists('get_userdata')) {
    function get_userdata($user_id)
    {
        return pb_test_obj($GLOBALS['pb_test_users'][(int) $user_id] ?? null) ?? false;
    }
}
if (!function_exists('get_post')) {
    function get_post($post = null, $output = 'OBJECT', $filter = 'raw')
    {
        if (is_object($post)) {
            return $post;
        }
        $id = $post === null ? get_the_ID() : (int) $post;
        return pb_test_obj($GLOBALS['pb_test_posts'][(int) $id] ?? null);
    }
}
if (!function_exists('get_the_ID')) {
    function get_the_ID()
    {
        return $GLOBALS['pb_test_current_post'] ?? false;
    }
}
if (!function_exists('get_post_type')) {
    function get_post_type($post = null)
    {
        $p = get_post($post);
        return $p && isset($p->post_type) ? (string) $p->post_type : false;
    }
}
if (!function_exists('get_post_status')) {
    function get_post_status($post = null)
    {
        $p = get_post($post);
        return $p && isset($p->post_status) ? (string) $p->post_status : false;
    }
}
if (!function_exists('get_the_title')) {
    function get_the_title($post = 0)
    {
        $p = get_post($post);
        return $p ? (string) ($p->post_title ?? '') : '';
    }
}
if (!function_exists('get_queried_object')) {
    function get_queried_object()
    {
        return $GLOBALS['pb_test_queried_object'] ?? null;
    }
}
if (!function_exists('get_permalink')) {
    /** pb_test_permalinks[$id] when set, else https://x.test/?p=ID for a known post, else false. */
    function get_permalink($post = 0, $leavename = false)
    {
        $id = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;
        if ($id === 0) {
            $id = (int) get_the_ID();
        }
        if (isset($GLOBALS['pb_test_permalinks'][$id])) {
            return $GLOBALS['pb_test_permalinks'][$id];
        }
        $p = get_post($post ?: null);
        return $p ? 'https://x.test/?p=' . (int) $p->ID : false;
    }
}
if (!function_exists('get_post_types')) {
    function get_post_types($args = [], $output = 'names', $operator = 'and')
    {
        $objects = $GLOBALS['pb_test_post_types'] ?? [];
        $names = array_keys($objects);
        return $output === 'objects' ? $objects : array_combine($names, $names);
    }
}
if (!function_exists('get_post_type_object')) {
    function get_post_type_object($post_type)
    {
        return $GLOBALS['pb_test_post_types'][$post_type] ?? null;
    }
}
if (!function_exists('wp_slash')) {
    function wp_slash($value)
    {
        if (is_array($value)) {
            return array_map('wp_slash', $value);
        }
        return is_string($value) ? addslashes($value) : $value;
    }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }
        return is_string($value) ? stripslashes($value) : $value;
    }
}
if (!function_exists('absint')) {
    function absint($maybeint)
    {
        return abs((int) $maybeint);
    }
}
if (!class_exists('WP_Roles')) {
    /**
     * pb_test_roles = role slug => ['name' => ..., 'capabilities' => [cap => bool]]
     * (OptionsPagesRoutesTest reads ->roles; the Scripts/Redirects tests read
     * ->role_names / get_names()). A missing 'name' falls back to the slug.
     */
    class WP_Roles
    {
        public array $roles = [];
        public array $role_names = [];
        public function __construct()
        {
            $this->roles = $GLOBALS['pb_test_roles'] ?? [];
            foreach ($this->roles as $slug => $role) {
                $this->role_names[$slug] = (string) (is_array($role) ? ($role['name'] ?? $slug) : $slug);
            }
        }
        public function get_names(): array { return $this->role_names; }
        public function is_role($role): bool { return isset($this->roles[$role]); }
    }
}
if (!function_exists('wp_roles')) {
    function wp_roles() { return new WP_Roles(); }
}

// ------------------------------------------------------------------ front-end request (shared)

// Conditional tags read pb_test_query[$tag]: false/missing = not that page,
// true = that page, or a list of accepted arguments (post types, slugs, ids,
// taxonomies) so is_singular('product') is true when the list holds 'product'.
// Other request state:
//   pb_test_is_admin         is_admin() (default false)
//   pb_test_ssl              is_ssl() (default false)
//   pb_test_home_url         home_url('') base (default http://example.test)
//   pb_test_doing_cron       wp_doing_cron(); pb_test_doing_ajax wp_doing_ajax()
//   pb_test_redirects[]      ['location', 'status', 'by', 'safe'] per wp_redirect()/wp_safe_redirect()
//   pb_test_status           last status_header() code; pb_test_nocache true after nocache_headers()
//   pb_test_now              current_time() clock (unix timestamp)
if (!function_exists('pb_test_conditional')) {
    /** Shared evaluator for the conditional-tag stubs below. */
    function pb_test_conditional(string $tag, mixed $arg = ''): bool
    {
        $value = $GLOBALS['pb_test_query'][$tag] ?? false;
        if ($arg === '' || $arg === null || $arg === [] || $arg === 0) {
            return (bool) $value;
        }
        if (is_bool($value)) {
            return $value;
        }
        $wanted = array_map('strval', (array) $arg);
        $have = array_map('strval', (array) $value);
        return array_intersect($wanted, $have) !== [];
    }
}
if (!function_exists('is_admin')) {
    function is_admin()
    {
        return (bool) ($GLOBALS['pb_test_is_admin'] ?? false);
    }
}
if (!function_exists('is_ssl')) {
    function is_ssl() { return (bool) ($GLOBALS['pb_test_ssl'] ?? false); }
}
if (!function_exists('wp_doing_cron')) {
    function wp_doing_cron() { return (bool) ($GLOBALS['pb_test_doing_cron'] ?? false); }
}
if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax() { return (bool) ($GLOBALS['pb_test_doing_ajax'] ?? false); }
}
if (!function_exists('is_404')) {
    function is_404() { return pb_test_conditional('is_404'); }
}
if (!function_exists('is_front_page')) {
    function is_front_page() { return pb_test_conditional('is_front_page'); }
}
if (!function_exists('is_home')) {
    function is_home() { return pb_test_conditional('is_home'); }
}
if (!function_exists('is_search')) {
    function is_search() { return pb_test_conditional('is_search'); }
}
if (!function_exists('is_archive')) {
    function is_archive() { return pb_test_conditional('is_archive'); }
}
if (!function_exists('is_singular')) {
    function is_singular($post_types = '') { return pb_test_conditional('is_singular', $post_types); }
}
if (!function_exists('is_post_type_archive')) {
    function is_post_type_archive($post_types = '') { return pb_test_conditional('is_post_type_archive', $post_types); }
}
if (!function_exists('is_page')) {
    function is_page($page = '') { return pb_test_conditional('is_page', $page); }
}
if (!function_exists('is_single')) {
    function is_single($post = '') { return pb_test_conditional('is_single', $post); }
}
if (!function_exists('is_tax')) {
    function is_tax($taxonomy = '', $term = '')
    {
        if ($term !== '' && $term !== []) {
            return pb_test_conditional('is_tax', $taxonomy) && pb_test_conditional('is_tax_term', $term);
        }
        return pb_test_conditional('is_tax', $taxonomy);
    }
}
if (!function_exists('is_category')) {
    function is_category($category = '') { return pb_test_conditional('is_category', $category); }
}
if (!function_exists('is_tag')) {
    function is_tag($tag = '') { return pb_test_conditional('is_tag', $tag); }
}
if (!function_exists('has_term')) {
    /** pb_test_query['has_term'][$taxonomy] = list of term slugs on the current post. */
    function has_term($term = '', $taxonomy = '', $post = null)
    {
        $terms = $GLOBALS['pb_test_query']['has_term'][(string) $taxonomy] ?? [];
        if ($term === '' || $term === []) {
            return $terms !== [];
        }
        return array_intersect(array_map('strval', (array) $term), array_map('strval', $terms)) !== [];
    }
}
if (!function_exists('is_embed')) {
    function is_embed() { return pb_test_conditional('is_embed'); }
}
if (!function_exists('is_feed')) {
    function is_feed($feeds = '') { return pb_test_conditional('is_feed'); }
}
if (!function_exists('is_customize_preview')) {
    function is_customize_preview()
    {
        return (bool) ($GLOBALS['pb_test_customize_preview'] ?? false);
    }
}
if (!function_exists('home_url')) {
    function home_url($path = '', $scheme = null)
    {
        $base = rtrim((string) ($GLOBALS['pb_test_home_url'] ?? 'http://example.test'), '/');
        if ($scheme !== null) {
            $base = set_url_scheme($base, $scheme);
        }
        $path = (string) $path;
        if ($path === '') {
            return $base;
        }
        return $base . (str_starts_with($path, '/') || str_starts_with($path, '?') ? '' : '/') . $path;
    }
}
if (!function_exists('site_url')) {
    function site_url($path = '', $scheme = null) { return home_url($path, $scheme); }
}
if (!function_exists('rest_get_url_prefix')) {
    function rest_get_url_prefix() { return (string) ($GLOBALS['pb_test_rest_prefix'] ?? 'wp-json'); }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) { return parse_url((string) $url, $component); }
}
if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = [])
    {
        if (is_object($args)) {
            $args = get_object_vars($args);
        } elseif (!is_array($args)) {
            parse_str((string) $args, $args);
        }
        return array_merge((array) $defaults, (array) $args);
    }
}
if (!function_exists('wp_get_referer')) {
    function wp_get_referer() { return $_SERVER['HTTP_REFERER'] ?? false; }
}
if (!function_exists('wp_normalize_path')) {
    function wp_normalize_path($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        return preg_replace('#(?<=.)/+#', '/', $path);
    }
}
if (!function_exists('wp_check_invalid_utf8')) {
    function wp_check_invalid_utf8($text, $strip = false)
    {
        $text = (string) $text;
        if ($text === '' || preg_match('/^./us', $text) === 1) {
            return $text;
        }
        return $strip ? (string) iconv('UTF-8', 'UTF-8//IGNORE', $text) : '';
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text, $remove_breaks = false)
    {
        $text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text);
        $text = strip_tags((string) $text);
        if ($remove_breaks) {
            $text = preg_replace('/[\r\n\t ]+/', ' ', (string) $text);
        }
        return trim((string) $text);
    }
}
if (!function_exists('esc_textarea')) {
    function esc_textarea($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('is_serialized')) {
    function is_serialized($data, $strict = true)
    {
        if (!is_string($data)) {
            return false;
        }
        $data = trim($data);
        if ($data === 'N;') {
            return true;
        }
        return (bool) preg_match('/^[aObis]:[0-9]*[:;{]/', $data);
    }
}
if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize($data)
    {
        if (is_serialized($data)) {
            return @unserialize(trim((string) $data), ['allowed_classes' => false]);
        }
        return $data;
    }
}
if (!function_exists('current_time')) {
    /** 'mysql' → UTC datetime string, 'timestamp' → int; pb_test_now fixes the clock. */
    function current_time($type, $gmt = 0)
    {
        $now = (int) ($GLOBALS['pb_test_now'] ?? time());
        if ($type === 'timestamp' || $type === 'U') {
            return $now;
        }
        if ($type === 'mysql') {
            return gmdate('Y-m-d H:i:s', $now);
        }
        return gmdate((string) $type, $now);
    }
}

// ------------------------------------------------------------------ redirects and headers (shared)

if (!function_exists('wp_sanitize_redirect')) {
    /** Core's character allow-list and CRLF removal, close enough for tests. */
    function wp_sanitize_redirect($location)
    {
        $location = (string) $location;
        $location = preg_replace('|[^a-z0-9-~+_.?#=&;,/:%!*\[\]()@]|i', '', $location);
        $location = wp_kses_no_null_pb($location);
        // Strip %0d and %0a in any case, repeatedly.
        $strip = ['%0d', '%0a', '%0D', '%0A'];
        do {
            $before = $location;
            $location = str_replace($strip, '', $location);
        } while ($before !== $location);
        return $location;
    }
    function wp_kses_no_null_pb(string $s): string
    {
        return (string) preg_replace('/\\\\0+|\\\\x00|\x00/', '', $s);
    }
}
if (!function_exists('wp_validate_redirect')) {
    /** Same-host or relative locations pass; anything else returns $fallback. */
    function wp_validate_redirect($location, $fallback = '')
    {
        $location = trim((string) $location);
        if ($location === '') {
            return $fallback;
        }
        if (str_starts_with($location, '//')) {
            $location = 'http:' . $location;
        }
        $parts = parse_url($location);
        if ($parts === false) {
            return $fallback;
        }
        if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return $fallback;
        }
        if (!isset($parts['host'])) {
            return $location;
        }
        $home = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
        return strtolower($parts['host']) === $home ? $location : $fallback;
    }
}
if (!function_exists('wp_redirect')) {
    /** Records the call in pb_test_redirects[]; never exits. */
    function wp_redirect($location, $status = 302, $x_redirect_by = 'WordPress')
    {
        $GLOBALS['pb_test_redirects'][] = ['location' => (string) $location, 'status' => (int) $status, 'by' => (string) $x_redirect_by, 'safe' => false];
        $GLOBALS['pb_test_status'] = (int) $status;
        return true;
    }
}
if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect($location, $status = 302, $x_redirect_by = 'WordPress')
    {
        $validated = wp_validate_redirect((string) $location, home_url('/'));
        $GLOBALS['pb_test_redirects'][] = ['location' => $validated, 'status' => (int) $status, 'by' => (string) $x_redirect_by, 'safe' => true];
        $GLOBALS['pb_test_status'] = (int) $status;
        return true;
    }
}
if (!function_exists('status_header')) {
    function status_header($code, $description = '') { $GLOBALS['pb_test_status'] = (int) $code; }
}
if (!function_exists('nocache_headers')) {
    function nocache_headers() { $GLOBALS['pb_test_nocache'] = true; }
}

// ------------------------------------------------------------------ cron and plugins (shared)

// pb_test_cron[$hook][] = ['timestamp', 'recurrence', 'args'] per schedule call.
if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = [], $wp_error = false)
    {
        $GLOBALS['pb_test_cron'][$hook][] = ['timestamp' => (int) $timestamp, 'recurrence' => (string) $recurrence, 'args' => (array) $args];
        return true;
    }
}
if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = [], $wp_error = false)
    {
        $GLOBALS['pb_test_cron'][$hook][] = ['timestamp' => (int) $timestamp, 'recurrence' => null, 'args' => (array) $args];
        return true;
    }
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = [])
    {
        foreach ($GLOBALS['pb_test_cron'][$hook] ?? [] as $event) {
            if ($event['args'] === (array) $args) {
                return $event['timestamp'];
            }
        }
        return false;
    }
}
if (!function_exists('wp_unschedule_event')) {
    function wp_unschedule_event($timestamp, $hook, $args = [], $wp_error = false)
    {
        foreach ($GLOBALS['pb_test_cron'][$hook] ?? [] as $i => $event) {
            if ($event['timestamp'] === (int) $timestamp && $event['args'] === (array) $args) {
                unset($GLOBALS['pb_test_cron'][$hook][$i]);
            }
        }
        return true;
    }
}
if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook, $args = [], $wp_error = false)
    {
        $count = count($GLOBALS['pb_test_cron'][$hook] ?? []);
        unset($GLOBALS['pb_test_cron'][$hook]);
        return $count;
    }
}
// pb_test_active_plugins = list of plugin basenames; deactivate_plugins() records
// what it removed in pb_test_deactivated[].
if (!function_exists('is_plugin_active')) {
    function is_plugin_active($plugin)
    {
        return in_array((string) $plugin, $GLOBALS['pb_test_active_plugins'] ?? [], true);
    }
}
if (!function_exists('deactivate_plugins')) {
    function deactivate_plugins($plugins, $silent = false, $network_wide = null)
    {
        foreach ((array) $plugins as $plugin) {
            $GLOBALS['pb_test_deactivated'][] = (string) $plugin;
            $GLOBALS['pb_test_active_plugins'] = array_values(array_diff($GLOBALS['pb_test_active_plugins'] ?? [], [(string) $plugin]));
        }
    }
}

// ------------------------------------------------------------------ database (shared)

// A recording $wpdb. Tests queue results with $wpdb->queue[] (each call to a
// read method shifts one; an empty queue returns the method's "nothing" value)
// and inspect $wpdb->calls[] = ['method', ...arguments]. prepare() does a
// simple %s/%d/%f substitution so assertions can read the final SQL.
if (!class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';
        public string $base_prefix = 'wp_';
        public int $insert_id = 0;
        public string $last_error = '';
        public int $rows_affected = 0;
        public array $calls = [];
        public array $queue = [];
        public string $charset = 'utf8mb4';
        public string $collate = 'utf8mb4_unicode_ci';
        public $options;
        public $posts;
        public $postmeta;
        public $termmeta;

        public function __construct()
        {
            $this->options = $this->prefix . 'options';
            $this->posts = $this->prefix . 'posts';
            $this->postmeta = $this->prefix . 'postmeta';
            $this->termmeta = $this->prefix . 'termmeta';
        }
        public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
        public function esc_like($text): string { return addcslashes((string) $text, '_%\\'); }
        public function prepare($query, ...$args)
        {
            if ($args !== [] && is_array($args[0]) && count($args) === 1) {
                $args = $args[0];
            }
            $query = str_replace("'%s'", '%s', (string) $query);
            $query = str_replace('"%s"', '%s', $query);
            $query = preg_replace('/(?<!%)%s/', "'%s'", $query);
            $escaped = array_map(static function ($a) {
                return is_string($a) ? addslashes($a) : $a;
            }, $args);
            $this->calls[] = ['prepare', $query, $args];
            return @vsprintf((string) $query, $escaped) ?: (string) $query;
        }
        private function shift(mixed $default): mixed
        {
            return $this->queue === [] ? $default : array_shift($this->queue);
        }
        public function query($query)
        {
            $this->calls[] = ['query', (string) $query];
            $result = $this->shift(0);
            $this->rows_affected = is_int($result) ? $result : 0;
            return $result;
        }
        public function get_results($query = null, $output = OBJECT)
        {
            $this->calls[] = ['get_results', (string) $query, $output];
            $rows = $this->shift([]);
            if ($output === ARRAY_A) {
                return array_map(static fn($r) => (array) $r, (array) $rows);
            }
            return array_map(static fn($r) => (object) $r, (array) $rows);
        }
        public function get_var($query = null, $x = 0, $y = 0)
        {
            $this->calls[] = ['get_var', (string) $query];
            return $this->shift(null);
        }
        public function get_row($query = null, $output = OBJECT, $y = 0)
        {
            $this->calls[] = ['get_row', (string) $query, $output];
            $row = $this->shift(null);
            if ($row === null) {
                return null;
            }
            return $output === ARRAY_A ? (array) $row : (object) $row;
        }
        public function get_col($query = null, $x = 0)
        {
            $this->calls[] = ['get_col', (string) $query];
            return (array) $this->shift([]);
        }
        public function insert($table, $data, $format = null)
        {
            $this->calls[] = ['insert', (string) $table, (array) $data, $format];
            $this->insert_id++;
            return 1;
        }
        public function replace($table, $data, $format = null)
        {
            $this->calls[] = ['replace', (string) $table, (array) $data, $format];
            return 1;
        }
        public function update($table, $data, $where, $format = null, $where_format = null)
        {
            $this->calls[] = ['update', (string) $table, (array) $data, (array) $where];
            return (int) $this->shift(1);
        }
        public function delete($table, $where, $where_format = null)
        {
            $this->calls[] = ['delete', (string) $table, (array) $where];
            return (int) $this->shift(1);
        }
        public function get_blog_prefix($blog_id = null): string { return $this->prefix; }
    }
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new wpdb();
}
if (!function_exists('dbDelta')) {
    /** Records each statement in pb_test_dbdelta[]; returns an empty result list. */
    function dbDelta($queries = '', $execute = true)
    {
        foreach ((array) $queries as $q) {
            $GLOBALS['pb_test_dbdelta'][] = (string) $q;
        }
        return [];
    }
}

// ------------------------------------------------------------------ plugin helpers

// The plugin's global helpers (proto_blocks_sanitize_color(), ...) are real
// code, not stubs; they are loaded once here so no test needs its own guarded
// require. helpers.php exits without ABSPATH.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
// Defined once, here, so no test file's load order decides it. It sits in a
// dedicated folder like a real install's, never directly in the temp dir:
// dirname(WP_CONTENT_DIR) is the site root display base
// (ContentModelRoutes::displayPath), and a content dir directly in the temp
// dir would make the whole temp dir the "site", so every temp-dir fixture
// path would display relative to it. (macOS hid this: its temp dir is a
// symlink, so realpath'd fixtures never matched the unresolved base.)
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/pb-test-site/wp-content');
}
if (!function_exists('proto_blocks_sanitize_color')) {
    require_once __DIR__ . '/../../includes/helpers.php';
}
