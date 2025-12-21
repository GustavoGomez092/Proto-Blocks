<?php
/**
 * Plugin Name: Proto-Blocks
 * Plugin URI: https://github.com/GustavoGomez092/proto-blocks
 * Description: Create Gutenberg blocks using PHP/HTML templates instead of React. A modern, performant alternative to complex JavaScript block development.
 * Version: 1.0.0
 * Requires at least: 6.3
 * Requires PHP: 8.0
 * Author: Gustavo Gomez
 * Author URI: https://github.com/GustavoGomez092
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: proto-blocks
 * Domain Path: /languages
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks;

// Prevent direct access
defined('ABSPATH') || exit;

// Plugin constants
define('PROTO_BLOCKS_VERSION', '1.0.0');
define('PROTO_BLOCKS_FILE', __FILE__);
define('PROTO_BLOCKS_DIR', plugin_dir_path(__FILE__));
define('PROTO_BLOCKS_URL', plugin_dir_url(__FILE__));
define('PROTO_BLOCKS_BASENAME', plugin_basename(__FILE__));

// Configuration constants (can be overridden in wp-config.php)
if (!defined('PROTO_BLOCKS_DEBUG')) {
    define('PROTO_BLOCKS_DEBUG', false);
}

if (!defined('PROTO_BLOCKS_CACHE_ENABLED')) {
    define('PROTO_BLOCKS_CACHE_ENABLED', true);
}

if (!defined('PROTO_BLOCKS_EXAMPLE_BLOCKS')) {
    define('PROTO_BLOCKS_EXAMPLE_BLOCKS', true);
}

// Load autoloader
require_once PROTO_BLOCKS_DIR . 'includes/Core/Autoloader.php';

// Initialize autoloader
Core\Autoloader::register();

/**
 * Initialize the plugin
 */
function init(): void
{
    // Check PHP version
    if (version_compare(PHP_VERSION, '8.0', '<')) {
        add_action('admin_notices', function (): void {
            echo '<div class="error"><p>';
            echo esc_html__('Proto-Blocks requires PHP 8.0 or higher. Please upgrade your PHP version.', 'proto-blocks');
            echo '</p></div>';
        });
        return;
    }

    // Check WordPress version
    global $wp_version;
    if (version_compare($wp_version, '6.3', '<')) {
        add_action('admin_notices', function (): void {
            echo '<div class="error"><p>';
            echo esc_html__('Proto-Blocks requires WordPress 6.3 or higher. Please upgrade WordPress.', 'proto-blocks');
            echo '</p></div>';
        });
        return;
    }

    // Boot the plugin
    Core\Plugin::getInstance()->boot();
}

// Initialize on plugins_loaded
add_action('plugins_loaded', __NAMESPACE__ . '\\init');

// Activation hook
register_activation_hook(__FILE__, function (): void {
    // Create cache directory
    $cache_dir = WP_CONTENT_DIR . '/cache/proto-blocks';
    if (!is_dir($cache_dir)) {
        wp_mkdir_p($cache_dir);
    }

    // Flush rewrite rules
    flush_rewrite_rules();

    // Set activation flag for welcome message
    set_transient('proto_blocks_activated', true, 30);
});

// Deactivation hook
register_deactivation_hook(__FILE__, function (): void {
    // Flush rewrite rules
    flush_rewrite_rules();
});

// Uninstall hook is in uninstall.php