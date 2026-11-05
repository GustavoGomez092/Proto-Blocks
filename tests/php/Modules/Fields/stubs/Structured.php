<?php

declare(strict_types=1);

// Post/term link stubs for StructuredTypesTest (global namespace, guarded).

if (!function_exists('get_term_link')) {
    function get_term_link($term, $taxonomy = '')
    {
        return 'https://x.test/term/' . ($term->slug ?? '');
    }
}
