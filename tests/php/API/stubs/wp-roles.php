<?php

declare(strict_types=1);

// wp_roles() for OptionsPagesRoutesTest. Tests set $GLOBALS['pb_test_roles']
// to a role name => ['capabilities' => [cap => bool]] map.

if (!function_exists('wp_roles')) {
    function wp_roles()
    {
        return (object) ['roles' => $GLOBALS['pb_test_roles'] ?? []];
    }
}
