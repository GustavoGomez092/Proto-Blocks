<?php
/**
 * Plugin Name: Proto-Blocks E2E options pages
 * Description: wp-env test site only (mapped by .wp-env.json). Adds the
 * tests/e2e/fixtures declaration root and blocks folder, a `product` type
 * when WooCommerce is absent, and a shop manager without unfiltered_html.
 */

declare(strict_types=1);

add_filter('proto_blocks_schema_paths', static function ($paths) {
    $paths = is_array($paths) ? $paths : [];
    $paths[] = WP_CONTENT_DIR . '/pb-e2e';

    return $paths;
});

add_filter('proto_blocks_paths', static function ($paths) {
    $paths = is_array($paths) ? $paths : [];
    $paths[] = WP_CONTENT_DIR . '/pb-e2e/blocks';

    return $paths;
});

add_action('init', static function (): void {
    if (!class_exists('WooCommerce') && !post_type_exists('product')) {
        register_post_type('product', [
            'label' => 'Products',
            'public' => true,
            'show_in_rest' => true,
        ]);
    }

    // First load only: the role, the capability and the user persist.
    if (get_option('pb_e2e_options_setup') === '2') {
        return;
    }

    $admin = get_role('administrator');
    if ($admin !== null) {
        $admin->add_cap('manage_woocommerce');
    }
    if (get_role('pb_shop_manager') === null) {
        add_role('pb_shop_manager', 'Shop manager (E2E)', [
            'read' => true,
            'manage_woocommerce' => true,
        ]);
    }
    // Version 2: edit_posts (the stand-in product type uses post capabilities)
    // so the Products menu, and its Display Settings item, shows for the role.
    $shop = get_role('pb_shop_manager');
    if ($shop !== null) {
        $shop->add_cap('edit_posts');
    }
    if (!username_exists('shopmanager')) {
        wp_insert_user([
            'user_login' => 'shopmanager',
            'user_pass' => 'password',
            'user_email' => 'shopmanager@example.test',
            'role' => 'pb_shop_manager',
        ]);
    }
    update_option('pb_e2e_options_setup', '2', false);
}, 1);
