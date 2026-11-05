<?php
/**
 * Plugin Name: Proto-Blocks E2E redirects
 * Description: wp-env test site only (mapped by .wp-env.json). Declares one
 * redirect with proto_redirect() while the option `pb_e2e_redirects_php`
 * holds its source path (to `/pb-e2e-php-target`), so the redirects spec can
 * show the PHP origin and the declared-twice badge; without the option
 * (every other spec) nothing is declared. While `pb_e2e_redirects_readonly`
 * is set, `wp-content/pb-e2e-fixtures/redirects-readonly` is added to
 * proto_blocks_schema_paths: its `e2e-locked` group loads but is outside the
 * theme, so the screen cannot write it.
 */

declare(strict_types=1);

add_filter('proto_blocks_schema_paths', static function ($paths) {
    $paths = is_array($paths) ? $paths : [];
    if (get_option('pb_e2e_redirects_readonly')) {
        $paths[] = WP_CONTENT_DIR . '/pb-e2e-fixtures/redirects-readonly';
    }

    return $paths;
});

// proto_redirect() is callable until init 9 (sealed after).
add_action('init', static function (): void {
    $from = get_option('pb_e2e_redirects_php');
    if (!is_string($from) || $from === '' || !function_exists('proto_redirect')) {
        return;
    }
    proto_redirect($from, '/pb-e2e-php-target', ['title' => 'E2E PHP rule']);
}, 1);
