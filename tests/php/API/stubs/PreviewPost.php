<?php

declare(strict_types=1);

// Stubs for RestApiPreviewPostTest that no other directory defines. Required
// from setUp() inside a separate process only: WP_Post must never leak into
// the shared test process (Core/Plugin branches on instanceof WP_Post).
// current_user_can, register_rest_route and the REST classes come from
// tests/php/bootstrap.php; get_post from Modules/Fields/wp-stubs.php (it hands
// back pb_test_posts objects as they are, so tests store WP_Post instances).
//
//   pb_test_setup_postdata[]  post IDs passed to setup_postdata()

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
if (!function_exists('setup_postdata')) {
    function setup_postdata($post)
    {
        $GLOBALS['pb_test_setup_postdata'][] = $post instanceof WP_Post ? $post->ID : null;
        return true;
    }
}
