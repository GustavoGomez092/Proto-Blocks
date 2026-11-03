<?php

declare(strict_types=1);

// Revision and autosave stand-ins for the Fields save path tests (global
// namespace, guarded, defined nowhere else).
//
//   pb_test_revisions[$id]  parent id when post $id is a revision
//   pb_test_autosaves[$id]  true when post $id is an autosave

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
