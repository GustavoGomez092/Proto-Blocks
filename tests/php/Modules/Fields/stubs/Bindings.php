<?php

declare(strict_types=1);

// Block Bindings stubs for FieldBindingSourceTest (global namespace, guarded).
//
//   pb_test_password_required[$postId]  bool, what post_password_required() returns
//   pb_test_hidden_taxonomies           list of taxonomies is_taxonomy_viewable() rejects

if (!function_exists('post_password_required')) {
    function post_password_required($post = null)
    {
        $id = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;
        return (bool) ($GLOBALS['pb_test_password_required'][$id] ?? false);
    }
}
if (!function_exists('is_taxonomy_viewable')) {
    function is_taxonomy_viewable($taxonomy)
    {
        return !in_array((string) $taxonomy, $GLOBALS['pb_test_hidden_taxonomies'] ?? [], true);
    }
}
