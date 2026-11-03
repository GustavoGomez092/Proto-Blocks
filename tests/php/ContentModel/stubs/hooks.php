<?php

declare(strict_types=1);

// Hook, registration, rewrite and screen stand-ins for the content-model and
// Modules tests. Every stub is guarded so the first definition wins (PHPUnit
// loads all test files into one process); each has the same shape as its
// siblings elsewhere in tests/php. apply_filters(), get_option() and
// update_option() come from tests/php/bootstrap.php and are not redefined.
//
// Globals read or written here:
//   pb_test_hooks[$hook][]                 ['callback', 'priority', 'args'] per add_action/add_filter
//   pb_test_registered['post_type'][$slug] args passed to register_post_type()
//   pb_test_registered['taxonomy'][$slug]  ['object_types' => ..., 'args' => ...]
//   pb_test_post_types[$slug]              registered post type objects (seed one to mark it registered)
//   pb_test_taxonomies[$slug]              registered taxonomy objects
//   pb_test_supports[$slug][$feature]      post type supports
//   pb_test_tax_links[]                    [taxonomy, object type] from register_taxonomy_for_object_type()
//   pb_test_flushes                        flush_rewrite_rules() call count
//   pb_test_stylesheet                     get_stylesheet() value (default 'theme')
//   pb_test_is_admin                       is_admin() value (default false)
//   pb_test_screen                         get_current_screen() value (object with ->id, or null)
//   pb_test_caps[$userId]                  capabilities of a user, read by current_user_can()

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        $GLOBALS['pb_test_hooks'][$hook][] = ['callback' => $callback, 'priority' => (int) $priority, 'args' => (int) $accepted_args];
        return true;
    }
}
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        return add_filter($hook, $callback, $priority, $accepted_args);
    }
}

if (!function_exists('register_post_type')) {
    /** Applies register_post_type_args like WordPress, records the args, stores an object. */
    function register_post_type($post_type, $args = [])
    {
        $args = apply_filters('register_post_type_args', (array) $args, $post_type);
        $GLOBALS['pb_test_registered']['post_type'][$post_type] = $args;
        $object = (object) ($args + ['_builtin' => false, 'labels' => []]);
        $object->name = $post_type;
        $GLOBALS['pb_test_post_types'][$post_type] = $object;
        return $object;
    }
}
if (!function_exists('post_type_exists')) {
    function post_type_exists($post_type)
    {
        return isset($GLOBALS['pb_test_post_types'][$post_type]);
    }
}
if (!function_exists('get_post_type_object')) {
    function get_post_type_object($post_type)
    {
        return $GLOBALS['pb_test_post_types'][$post_type] ?? null;
    }
}
if (!function_exists('get_post_type_labels')) {
    function get_post_type_labels($post_type_object)
    {
        return (object) (array) ($post_type_object->labels ?? []);
    }
}
if (!function_exists('get_all_post_type_supports')) {
    function get_all_post_type_supports($post_type)
    {
        return $GLOBALS['pb_test_supports'][$post_type] ?? [];
    }
}
if (!function_exists('add_post_type_support')) {
    function add_post_type_support($post_type, $feature, ...$args)
    {
        foreach ((array) $feature as $one) {
            $GLOBALS['pb_test_supports'][$post_type][$one] = $args === [] ? true : $args;
        }
    }
}
if (!function_exists('remove_post_type_support')) {
    function remove_post_type_support($post_type, $feature)
    {
        unset($GLOBALS['pb_test_supports'][$post_type][$feature]);
    }
}

if (!function_exists('register_taxonomy')) {
    /** Applies register_taxonomy_args like WordPress, records the call, stores an object. */
    function register_taxonomy($taxonomy, $object_type, $args = [])
    {
        $args = apply_filters('register_taxonomy_args', (array) $args, $taxonomy, (array) $object_type);
        $GLOBALS['pb_test_registered']['taxonomy'][$taxonomy] = ['object_types' => (array) $object_type, 'args' => $args];
        $object = (object) ($args + ['_builtin' => false, 'labels' => []]);
        $object->name = $taxonomy;
        $object->object_type = (array) $object_type;
        $GLOBALS['pb_test_taxonomies'][$taxonomy] = $object;
        return $object;
    }
}
if (!function_exists('taxonomy_exists')) {
    function taxonomy_exists($taxonomy)
    {
        return isset($GLOBALS['pb_test_taxonomies'][$taxonomy]);
    }
}
if (!function_exists('get_taxonomy')) {
    function get_taxonomy($taxonomy)
    {
        return $GLOBALS['pb_test_taxonomies'][$taxonomy] ?? false;
    }
}
if (!function_exists('get_taxonomy_labels')) {
    function get_taxonomy_labels($taxonomy_object)
    {
        return (object) (array) ($taxonomy_object->labels ?? []);
    }
}
if (!function_exists('register_taxonomy_for_object_type')) {
    function register_taxonomy_for_object_type($taxonomy, $object_type)
    {
        $GLOBALS['pb_test_tax_links'][] = [$taxonomy, $object_type];
        return true;
    }
}

if (!function_exists('flush_rewrite_rules')) {
    function flush_rewrite_rules($hard = true)
    {
        $GLOBALS['pb_test_flushes'] = ($GLOBALS['pb_test_flushes'] ?? 0) + 1;
    }
}
if (!function_exists('get_stylesheet')) {
    function get_stylesheet()
    {
        return (string) ($GLOBALS['pb_test_stylesheet'] ?? 'theme');
    }
}
if (!function_exists('is_admin')) {
    function is_admin()
    {
        return (bool) ($GLOBALS['pb_test_is_admin'] ?? false);
    }
}
if (!function_exists('get_current_screen')) {
    function get_current_screen()
    {
        return $GLOBALS['pb_test_screen'] ?? null;
    }
}
// Same shape as tests/php/Admin/wp-stubs.php.
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return (int) ($GLOBALS['pb_test_current_user'] ?? $GLOBALS['pb_test_user'] ?? 1);
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can($capability, ...$args): bool
    {
        return in_array($capability, $GLOBALS['pb_test_caps'][get_current_user_id()] ?? [], true);
    }
}
