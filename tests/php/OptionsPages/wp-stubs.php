<?php

declare(strict_types=1);

/**
 * Global-namespace WordPress stubs only the OptionsPages tests use. Loaded
 * via require_once; not a test file. Shared stubs (hooks, users and
 * capabilities, REST classes, sanitisers, i18n, options) live in
 * tests/php/bootstrap.php; do not copy them here. Everything is guarded so a
 * real WordPress always takes precedence.
 */

// Phase 1's content-model stand-ins first (registration, rewrite, screen), so
// one process sees one emulation whichever directory loads first.
if (is_file(__DIR__ . '/../ContentModel/stubs/hooks.php')) {
    require_once __DIR__ . '/../ContentModel/stubs/hooks.php';
}

$GLOBALS['menu'] = $GLOBALS['menu'] ?? [];
$GLOBALS['submenu'] = $GLOBALS['submenu'] ?? [];
$GLOBALS['admin_page_hooks'] = $GLOBALS['admin_page_hooks'] ?? ($GLOBALS['pb_test_parent_menus'] ?? []);

if (!function_exists('add_menu_page')) {
    function add_menu_page($page_title, $menu_title, $capability, $slug, $fn = '', $icon = '', $position = null)
    {
        $GLOBALS['pb_test_menus'][] = compact('fn', 'page_title', 'menu_title', 'capability', 'slug', 'icon', 'position') + ['parent' => null];
        return 'hook_' . $slug;
    }
}
if (!function_exists('add_submenu_page')) {
    function add_submenu_page($parent, $page_title, $menu_title, $capability, $slug, $fn = '', $position = null)
    {
        $GLOBALS['pb_test_menus'][] = compact('fn', 'parent', 'page_title', 'menu_title', 'capability', 'slug', 'position') + ['icon' => null];
        return 'hook_' . $slug;
    }
}
if (!class_exists('PbTestWpDie')) {
    class PbTestWpDie extends \RuntimeException
    {
        public function __construct(string $message, public readonly int $status = 500)
        {
            parent::__construct($message);
        }
    }
}
if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = [])
    {
        $status = is_array($args) ? (int) ($args['response'] ?? 500) : (int) $args;
        throw new PbTestWpDie(is_string($message) ? $message : 'wp_die', $status);
    }
}
if (!isset($GLOBALS['wpdb'])) {
    $GLOBALS['wpdb'] = new class {
        public string $prefix = 'wp_';
    };
}
