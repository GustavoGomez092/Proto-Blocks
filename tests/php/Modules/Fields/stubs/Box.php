<?php

declare(strict_types=1);

// Term listing stand-in for the field box payload tests (global namespace,
// guarded, defined nowhere else).
//
//   pb_test_term_lists[$taxonomy]  list of term objects/arrays (term_id, name, parent)

if (!function_exists('get_terms')) {
    function get_terms($args = [], $deprecated = '')
    {
        $taxonomy = is_array($args) ? (string) ($args['taxonomy'] ?? '') : '';
        $terms = array_map('pb_test_obj', $GLOBALS['pb_test_term_lists'][$taxonomy] ?? []);
        $number = is_array($args) ? (int) ($args['number'] ?? 0) : 0;

        return $number > 0 ? array_slice($terms, 0, $number) : $terms;
    }
}
