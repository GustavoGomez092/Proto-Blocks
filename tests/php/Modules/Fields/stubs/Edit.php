<?php

declare(strict_types=1);

// Revision, autosave and post type visibility stand-ins for the Fields save
// path and edit route tests (global namespace, guarded, defined nowhere else).
//
//   pb_test_revisions[$id]  parent id when post $id is a revision
//   pb_test_autosaves[$id]  true when post $id is an autosave
//   is_post_type_viewable() reads publicly_queryable / _builtin / public of
//   the type object (pb_test_post_types via get_post_type_object for a name)

if (!function_exists('wp_is_post_revision')) {
    function wp_is_post_revision($post)
    {
        $id = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;
        $parent = $GLOBALS['pb_test_revisions'][$id] ?? false;
        return $parent === false ? false : (int) $parent;
    }
}
if (!function_exists('wp_is_post_autosave')) {
    function wp_is_post_autosave($post)
    {
        $id = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;
        return !empty($GLOBALS['pb_test_autosaves'][$id]) ? $id : false;
    }
}
if (!function_exists('is_post_type_viewable')) {
    /** Core's rule: publicly_queryable, or a built-in public type. */
    function is_post_type_viewable($post_type)
    {
        if (is_scalar($post_type)) {
            $post_type = get_post_type_object((string) $post_type);
        }
        if (!is_object($post_type)) {
            return false;
        }
        return !empty($post_type->publicly_queryable) || (!empty($post_type->_builtin) && !empty($post_type->public));
    }
}
