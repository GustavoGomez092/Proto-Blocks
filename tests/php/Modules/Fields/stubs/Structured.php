<?php

declare(strict_types=1);

// Post/term link stubs for StructuredTypesTest (global namespace, guarded).

if (!function_exists('get_the_title')) {
    function get_the_title($post = 0)
    {
        $p = get_post($post);
        return $p ? (string) ($p->post_title ?? '') : '';
    }
}
if (!function_exists('get_permalink')) {
    function get_permalink($post = 0)
    {
        $p = get_post($post);
        return $p ? 'https://x.test/?p=' . (int) $p->ID : false;
    }
}
if (!function_exists('get_term_link')) {
    function get_term_link($term, $taxonomy = '')
    {
        return 'https://x.test/term/' . ($term->slug ?? '');
    }
}
