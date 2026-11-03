<?php

declare(strict_types=1);

// Stands in for an active Advanced Custom Fields in AcfBridgeTest. Loaded only
// inside tests that run in their own process: once defined, ACF counts as
// active for the rest of the process.
//
//   pb_test_acf_groups[$key]  the groups ACF "knows" (acf_get_field_group / acf_get_field_groups)

if (!class_exists('ACF')) {
    class ACF
    {
    }
}
if (!function_exists('acf_get_field_group')) {
    function acf_get_field_group($id = 0)
    {
        return $GLOBALS['pb_test_acf_groups'][$id] ?? false;
    }
}
if (!function_exists('acf_get_field_groups')) {
    function acf_get_field_groups($filter = [])
    {
        return array_values($GLOBALS['pb_test_acf_groups'] ?? []);
    }
}
