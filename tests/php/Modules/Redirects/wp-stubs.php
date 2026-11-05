<?php

declare(strict_types=1);

// Stand-ins used only by the redirects route and command tests (names no
// other test directory defines; StubUniquenessTest keeps it that way).

if (!function_exists('wp_set_current_user')) {
    /** Switches pb_test_current_user; every switch is recorded in pb_test_user_switches. */
    function wp_set_current_user($id, $name = '')
    {
        $GLOBALS['pb_test_user_switches'][] = (int) $id;
        $GLOBALS['pb_test_current_user'] = (int) $id;
        return (object) ['ID' => (int) $id];
    }
}
if (!function_exists('get_users')) {
    /** The ids in pb_test_user_list; `number` honoured. */
    function get_users($args = [])
    {
        $ids = array_values($GLOBALS['pb_test_user_list'] ?? []);
        if (isset($args['number'])) {
            $ids = array_slice($ids, 0, (int) $args['number']);
        }
        return $ids;
    }
}
if (!function_exists('is_plugin_active_for_network')) {
    function is_plugin_active_for_network($plugin)
    {
        return in_array((string) $plugin, $GLOBALS['pb_test_network_plugins'] ?? [], true);
    }
}
