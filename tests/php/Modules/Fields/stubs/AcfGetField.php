<?php

declare(strict_types=1);

// Stands in for ACF's get_field() in AliasesTest (loaded only in a separate process).

if (!function_exists('get_field')) {
    function get_field($selector, $post_id = false, $format_value = true)
    {
        return 'acf-stub';
    }
}
