<?php
/**
 * Plugin Name: Proto-Blocks E2E scripts
 * Description: wp-env test site only (mapped by .wp-env.json). Declares one
 * script with proto_script() while the option `pb_e2e_scripts_php` holds its
 * slug, so the scripts spec can show the PHP origin and the declared-twice
 * badge; without the option (every other spec) nothing is declared.
 */

declare(strict_types=1);

add_action('init', static function (): void {
    $slug = get_option('pb_e2e_scripts_php');
    if (!is_string($slug) || $slug === '' || !function_exists('proto_script')) {
        return;
    }
    proto_script('E2E PHP Script', [
        'slug' => $slug,
        'head' => '<meta name="pb-e2e-php" content="php">',
    ]);
});
