<?php

declare(strict_types=1);

// Stubs for RestApiPreviewPostTest. Required from setUp() inside a separate
// process only: they define WP_Post and a WP_Post-returning get_post(), which
// must never leak into the shared test process.
//
//   pb_test_posts[$id]             post fields; get_post() wraps them in WP_Post
//   pb_test_editable[$id]          true when current_user_can('edit_post', $id)
//   pb_test_cap_checks[]           [capability, ...args] per current_user_can() call
//   pb_test_setup_postdata[]       post IDs passed to setup_postdata()
//   pb_test_routes[ns . route]     register_rest_route() args

if (!class_exists('WP_Post')) {
    final class WP_Post
    {
        public int $ID = 0;
        public string $post_type = 'post';

        public function __construct(array $fields)
        {
            foreach ($fields as $k => $v) {
                $this->{$k} = $v;
            }
        }
    }
}
if (!function_exists('get_post')) {
    function get_post($post = null, $output = 'OBJECT', $filter = 'raw')
    {
        if ($post instanceof WP_Post) {
            return $post;
        }
        $fields = $GLOBALS['pb_test_posts'][(int) $post] ?? null;
        return is_array($fields) ? new WP_Post($fields) : null;
    }
}
if (!function_exists('setup_postdata')) {
    function setup_postdata($post)
    {
        $GLOBALS['pb_test_setup_postdata'][] = $post instanceof WP_Post ? $post->ID : null;
        return true;
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can($capability, ...$args)
    {
        $GLOBALS['pb_test_cap_checks'][] = array_merge([$capability], $args);
        return $capability === 'edit_post' && !empty($GLOBALS['pb_test_editable'][(int) ($args[0] ?? 0)]);
    }
}
if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = [], $override = false)
    {
        $GLOBALS['pb_test_routes'][$namespace . $route] = $args;
        return true;
    }
}
if (!class_exists('WP_REST_Server')) {
    class WP_REST_Server
    {
        public const READABLE = 'GET';
        public const CREATABLE = 'POST';
        public const EDITABLE = 'POST, PUT, PATCH';
        public const DELETABLE = 'DELETE';
        public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(public $code = '', public $message = '', public $data = '') {}
        public function get_error_code() { return $this->code; }
    }
}
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private array $params = [];
        public function set_param($k, $v) { $this->params[$k] = $v; }
        public function get_param($k) { return $this->params[$k] ?? null; }
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
