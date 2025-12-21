<?php
/**
 * Admin Page - Settings and management page
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Admin;

use ProtoBlocks\Template\Cache;
use ProtoBlocks\Core\Plugin;

/**
 * Admin settings page for Proto-Blocks
 */
class AdminPage
{
    /**
     * Page slug
     */
    public const SLUG = 'proto-blocks';

    /**
     * Cache instance
     */
    private Cache $cache;

    /**
     * Constructor
     */
    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Add admin menu page
     */
    public function addMenuPage(): void
    {
        // Custom SVG icon (base64 encoded)
        $icon_svg = '<svg width="20" height="20" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg"><path fill="black" d="M 247.29 113.27 C 281.81 92.76 317.20 73.73 351.74 53.24 C 381.54 70.41 411.26 87.73 441.05 104.91 C 445.94 107.55 450.55 110.67 455.17 113.76 C 455.17 190.50 455.17 267.24 455.16 343.98 C 421.16 364.15 386.68 383.52 352.65 403.64 C 320.46 422.38 288.26 441.09 256.02 459.73 C 219.34 438.57 182.70 417.33 146.19 395.88 C 116.39 378.59 86.48 361.51 56.83 343.99 C 56.83 303.91 56.82 263.83 56.83 223.75 C 86.20 206.41 115.83 189.50 145.32 172.37 C 150.57 169.58 155.39 166.09 160.53 163.12 C 166.16 166.77 171.92 170.20 178.05 172.97 C 201.17 186.24 224.21 199.68 247.30 213.01 C 247.27 179.76 247.30 146.52 247.29 113.27 M 273.42 118.25 C 299.35 133.26 325.25 148.31 351.22 163.24 C 359.85 159.03 368.03 154.02 376.35 149.25 C 393.91 139.08 411.49 128.94 429.04 118.75 C 403.10 103.76 377.20 88.68 351.22 73.76 C 325.35 88.68 299.41 103.50 273.42 118.25 M 265.23 134.08 C 265.20 163.98 265.22 193.88 265.21 223.78 C 291.25 238.14 316.57 253.78 342.54 268.26 C 342.49 238.42 342.56 208.58 342.51 178.74 C 316.94 163.53 290.99 148.96 265.23 134.08 M 359.95 178.75 C 359.93 208.41 359.94 238.07 359.94 267.73 C 385.92 253.00 411.84 238.15 437.73 223.27 C 437.79 193.43 437.75 163.60 437.75 133.76 C 411.84 148.79 385.67 163.40 359.95 178.75 M 82.95 228.27 C 108.85 243.38 134.92 258.23 160.73 273.50 C 186.52 258.44 212.27 243.33 238.05 228.26 C 212.33 213.18 186.19 198.81 160.76 183.25 C 135.01 198.56 108.86 213.21 82.95 228.27 M 234.70 250.70 C 215.86 261.54 197.02 272.38 178.18 283.24 C 204.09 298.29 230.24 312.94 256.00 328.23 C 281.73 313.36 307.55 298.63 333.27 283.74 C 307.44 268.48 281.22 253.89 255.47 238.51 C 248.74 242.90 241.61 246.61 234.70 250.70 M 74.24 243.76 C 74.25 273.60 74.21 303.43 74.26 333.27 C 100.19 348.25 126.10 363.27 152.04 378.23 C 152.08 348.41 152.05 318.59 152.05 288.77 C 126.09 273.81 100.18 258.76 74.24 243.76 M 359.95 288.74 C 359.93 318.56 359.92 348.38 359.95 378.20 C 385.91 363.28 411.80 348.23 437.74 333.27 C 437.78 303.60 437.75 273.93 437.75 244.26 C 411.82 259.09 385.86 273.87 359.95 288.74 M 264.74 343.44 C 264.66 373.37 264.73 403.31 264.71 433.24 C 290.68 418.40 316.78 403.76 342.51 388.52 C 342.54 358.51 342.52 328.51 342.53 298.51 C 316.58 313.46 290.73 328.56 264.74 343.44 M 169.48 388.51 C 195.07 403.78 221.04 418.43 246.77 433.46 C 246.79 403.65 246.77 373.84 246.79 344.03 C 220.99 329.11 195.22 314.13 169.43 299.19 C 169.53 328.96 169.43 358.74 169.48 388.51 Z" /></svg>';
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        $icon_base64 = 'data:image/svg+xml;base64,' . base64_encode($icon_svg);

        add_menu_page(
            __('Proto Blocks', 'proto-blocks'),
            __('Proto Blocks', 'proto-blocks'),
            'manage_options',
            self::SLUG,
            [$this, 'renderPage'],
            $icon_base64,
            58
        );

        add_submenu_page(
            self::SLUG,
            __('Blocks', 'proto-blocks'),
            __('Blocks', 'proto-blocks'),
            'manage_options',
            self::SLUG,
            [$this, 'renderPage']
        );

        add_submenu_page(
            self::SLUG,
            __('Settings', 'proto-blocks'),
            __('Settings', 'proto-blocks'),
            'manage_options',
            self::SLUG . '-settings',
            [$this, 'renderSettingsPage']
        );
    }

    /**
     * Render main admin page
     */
    public function renderPage(): void
    {
        // Handle cache clear action
        if (isset($_POST['proto_blocks_clear_cache']) && check_admin_referer('proto_blocks_clear_cache')) {
            $count = $this->cache->clear();
            echo '<div class="notice notice-success"><p>';
            printf(
                esc_html(_n(
                    '%d cached template cleared.',
                    '%d cached templates cleared.',
                    $count,
                    'proto-blocks'
                )),
                esc_html($count)
            );
            echo '</p></div>';
        }

        // Handle install demo blocks action
        if (isset($_POST['proto_blocks_install_demos']) && check_admin_referer('proto_blocks_install_demos')) {
            $result = $this->installDemoBlocks();
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>';
                echo esc_html($result->get_error_message());
                echo '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>';
                printf(
                    esc_html(_n(
                        '%d demo block installed to your theme.',
                        '%d demo blocks installed to your theme.',
                        $result,
                        'proto-blocks'
                    )),
                    esc_html($result)
                );
                echo '</p></div>';
            }
        }

        // Handle remove demo blocks action
        if (isset($_POST['proto_blocks_remove_demos']) && check_admin_referer('proto_blocks_remove_demos')) {
            $result = $this->removeDemoBlocks();
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>';
                echo esc_html($result->get_error_message());
                echo '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>';
                printf(
                    esc_html(_n(
                        '%d demo block removed from your theme.',
                        '%d demo blocks removed from your theme.',
                        $result,
                        'proto-blocks'
                    )),
                    esc_html($result)
                );
                echo '</p></div>';
            }
        }

        $discovery = Plugin::getInstance()->getDiscovery();
        $blocks = $discovery->getBlockInfo();
        $cacheStats = $this->cache->getStats();
        ?>
        <div class="wrap proto-blocks-admin">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="proto-blocks-dashboard">
                <!-- Stats Cards -->
                <div class="proto-blocks-stats">
                    <div class="stat-card">
                        <span class="stat-number"><?php echo esc_html(count($blocks)); ?></span>
                        <span class="stat-label"><?php esc_html_e('Registered Blocks', 'proto-blocks'); ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-number"><?php echo esc_html($cacheStats['file_count']); ?></span>
                        <span class="stat-label"><?php esc_html_e('Cached Templates', 'proto-blocks'); ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-number"><?php echo esc_html(size_format($cacheStats['total_size'])); ?></span>
                        <span class="stat-label"><?php esc_html_e('Cache Size', 'proto-blocks'); ?></span>
                    </div>
                </div>

                <!-- Install Demo Blocks -->
                <div class="proto-blocks-section proto-blocks-section--highlight">
                    <h2><?php esc_html_e('Demo Blocks', 'proto-blocks'); ?></h2>
                    <?php
                    $demo_block_names = $this->getDemoBlockNames();
                    $demo_blocks_list = implode(', ', array_map(function($name) {
                        return '<strong>' . esc_html(ucfirst($name)) . '</strong>';
                    }, $demo_block_names));
                    ?>
                    <p><?php
                        printf(
                            esc_html__('Install demo blocks (%s) to your theme to quickly test Proto-Blocks. Custom blocks you create will not be affected.', 'proto-blocks'),
                            $demo_blocks_list
                        );
                    ?></p>
                    <?php
                    $theme_blocks_dir = get_stylesheet_directory() . '/proto-blocks';
                    $existing_demo_blocks = [];
                    foreach ($demo_block_names as $demo_name) {
                        if (is_dir($theme_blocks_dir . '/' . $demo_name)) {
                            $existing_demo_blocks[] = $demo_name;
                        }
                    }
                    ?>
                    <?php if (!empty($existing_demo_blocks)): ?>
                        <p class="proto-blocks-warning">
                            <span class="dashicons dashicons-warning"></span>
                            <?php
                            $blocks_formatted = array_map(function($name) {
                                return '<code>' . esc_html($name) . '</code>';
                            }, $existing_demo_blocks);
                            printf(
                                /* translators: %s: list of block names */
                                __('The following demo blocks already exist and will be replaced: %s', 'proto-blocks'),
                                implode(', ', $blocks_formatted)
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                    <form method="post">
                        <?php wp_nonce_field('proto_blocks_install_demos'); ?>
                        <button type="submit" name="proto_blocks_install_demos" class="button button-primary">
                            <span class="dashicons dashicons-download" style="margin-top: 4px;"></span>
                            <?php esc_html_e('Install Demo Blocks to Theme', 'proto-blocks'); ?>
                        </button>
                    </form>
                    <p class="description" style="margin-top: 10px;">
                        <?php
                        printf(
                            esc_html__('Blocks will be installed to: %s', 'proto-blocks'),
                            '<code>' . esc_html(str_replace(ABSPATH, '', get_stylesheet_directory() . '/proto-blocks/')) . '</code>'
                        );
                        ?>
                    </p>
                </div>

                <!-- Remove Demo Blocks -->
                <?php
                $installed_demo_blocks = $this->getInstalledDemoBlocks();
                if (!empty($installed_demo_blocks)):
                ?>
                <div class="proto-blocks-section proto-blocks-section--danger">
                    <h2><?php esc_html_e('Remove Demo Blocks', 'proto-blocks'); ?></h2>
                    <p><?php esc_html_e('Remove all demo blocks from your theme to start with a clean slate. Your custom blocks will not be affected.', 'proto-blocks'); ?></p>
                    <p class="proto-blocks-info">
                        <span class="dashicons dashicons-info"></span>
                        <?php
                        $blocks_formatted = array_map(function($name) {
                            return '<code>' . esc_html($name) . '</code>';
                        }, $installed_demo_blocks);
                        printf(
                            /* translators: %s: list of block names */
                            __('Demo blocks that will be removed: %s', 'proto-blocks'),
                            implode(', ', $blocks_formatted)
                        );
                        ?>
                    </p>
                    <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to remove all demo blocks? This action cannot be undone.', 'proto-blocks')); ?>');">
                        <?php wp_nonce_field('proto_blocks_remove_demos'); ?>
                        <button type="submit" name="proto_blocks_remove_demos" class="button button-link-delete">
                            <span class="dashicons dashicons-trash" style="margin-top: 4px;"></span>
                            <?php esc_html_e('Remove Demo Blocks', 'proto-blocks'); ?>
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- Cache Management -->
                <div class="proto-blocks-section">
                    <h2><?php esc_html_e('Cache Management', 'proto-blocks'); ?></h2>
                    <p><?php esc_html_e('Clear the template cache to force recompilation of all blocks.', 'proto-blocks'); ?></p>
                    <form method="post">
                        <?php wp_nonce_field('proto_blocks_clear_cache'); ?>
                        <button type="submit" name="proto_blocks_clear_cache" class="button button-secondary">
                            <?php esc_html_e('Clear Cache', 'proto-blocks'); ?>
                        </button>
                    </form>
                </div>

                <!-- Blocks List -->
                <div class="proto-blocks-section">
                    <h2><?php esc_html_e('Registered Blocks', 'proto-blocks'); ?></h2>

                    <?php if (empty($blocks)): ?>
                        <p><?php esc_html_e('No blocks found. Create a block in your theme\'s proto-blocks directory.', 'proto-blocks'); ?></p>
                    <?php else: ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Block', 'proto-blocks'); ?></th>
                                    <th><?php esc_html_e('Category', 'proto-blocks'); ?></th>
                                    <th><?php esc_html_e('Location', 'proto-blocks'); ?></th>
                                    <th><?php esc_html_e('Assets', 'proto-blocks'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($blocks as $name => $block): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html($block['title']); ?></strong>
                                            <br>
                                            <code>proto-blocks/<?php echo esc_html($name); ?></code>
                                            <?php if ($block['isExample']): ?>
                                                <span class="proto-blocks-badge example"><?php esc_html_e('Example', 'proto-blocks'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html($block['category']); ?></td>
                                        <td>
                                            <code><?php echo esc_html(str_replace(ABSPATH, '', $block['path'])); ?></code>
                                        </td>
                                        <td>
                                            <?php if ($block['hasJson']): ?>
                                                <span class="proto-blocks-badge">JSON</span>
                                            <?php endif; ?>
                                            <?php if ($block['hasCSS']): ?>
                                                <span class="proto-blocks-badge">CSS</span>
                                            <?php endif; ?>
                                            <?php if ($block['hasJS']): ?>
                                                <span class="proto-blocks-badge">JS</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Quick Start -->
                <div class="proto-blocks-section">
                    <h2><?php esc_html_e('Quick Start', 'proto-blocks'); ?></h2>
                    <p><?php esc_html_e('Create your first block by adding files to your theme:', 'proto-blocks'); ?></p>
                    <pre><code>your-theme/
└── proto-blocks/
    └── my-block/
        ├── my-block.json   # Block configuration
        ├── my-block.php    # Template
        └── my-block.css    # Styles (optional)</code></pre>
                </div>
            </div>
        </div>

        <style>
            .proto-blocks-dashboard { max-width: 1200px; }
            .proto-blocks-stats { display: flex; gap: 20px; margin: 20px 0; }
            .stat-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; text-align: center; min-width: 150px; }
            .stat-number { display: block; font-size: 32px; font-weight: 600; color: #1d2327; margin-bottom: 8px; }
            .stat-label { color: #646970; font-size: 13px; display: block; }
            .proto-blocks-section { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin: 20px 0; }
            .proto-blocks-section h2 { margin-top: 0; }
            .proto-blocks-section--highlight { border-color: #2271b1; border-left-width: 4px; }
            .proto-blocks-section--danger { border-color: #d63638; border-left-width: 4px; }
            .proto-blocks-badge { display: inline-block; background: #e7e8ea; border-radius: 3px; padding: 2px 6px; font-size: 11px; margin-right: 4px; }
            .proto-blocks-badge.example { background: #dff0d8; color: #3c763d; }
            .proto-blocks-warning { background: #fcf9e8; border-left: 4px solid #dba617; padding: 10px 12px; margin: 10px 0; }
            .proto-blocks-warning .dashicons { color: #dba617; margin-right: 5px; }
            .proto-blocks-info { background: #f0f6fc; border-left: 4px solid #72aee6; padding: 10px 12px; margin: 10px 0; }
            .proto-blocks-info .dashicons { color: #72aee6; margin-right: 5px; }
            .button-link-delete { color: #d63638 !important; border-color: #d63638 !important; }
            .button-link-delete:hover { background: #d63638 !important; color: #fff !important; }
        </style>
        <?php
    }

    /**
     * Render settings page
     */
    public function renderSettingsPage(): void
    {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Proto Blocks Settings', 'proto-blocks'); ?></h1>

            <div class="proto-blocks-section">
                <h2><?php esc_html_e('Configuration', 'proto-blocks'); ?></h2>
                <p><?php esc_html_e('Proto-Blocks can be configured via constants in wp-config.php:', 'proto-blocks'); ?></p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><code>PROTO_BLOCKS_DEBUG</code></th>
                        <td>
                            <code><?php echo \PROTO_BLOCKS_DEBUG ? 'true' : 'false'; ?></code>
                            <p class="description"><?php esc_html_e('Enable debug mode for detailed error messages.', 'proto-blocks'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><code>PROTO_BLOCKS_CACHE_ENABLED</code></th>
                        <td>
                            <code><?php echo \PROTO_BLOCKS_CACHE_ENABLED ? 'true' : 'false'; ?></code>
                            <p class="description"><?php esc_html_e('Enable template caching for improved performance.', 'proto-blocks'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><code>PROTO_BLOCKS_EXAMPLE_BLOCKS</code></th>
                        <td>
                            <code><?php echo \PROTO_BLOCKS_EXAMPLE_BLOCKS ? 'true' : 'false'; ?></code>
                            <p class="description"><?php esc_html_e('Register example blocks for testing.', 'proto-blocks'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="proto-blocks-section">
                <h2><?php esc_html_e('System Information', 'proto-blocks'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Plugin Version', 'proto-blocks'); ?></th>
                        <td><?php echo esc_html(\PROTO_BLOCKS_VERSION); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('WordPress Version', 'proto-blocks'); ?></th>
                        <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('PHP Version', 'proto-blocks'); ?></th>
                        <td><?php echo esc_html(PHP_VERSION); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Cache Directory', 'proto-blocks'); ?></th>
                        <td>
                            <code><?php echo esc_html($this->cache->getCacheDir()); ?></code>
                            <?php if (is_writable($this->cache->getCacheDir())): ?>
                                <span class="dashicons dashicons-yes" style="color: green;"></span>
                            <?php else: ?>
                                <span class="dashicons dashicons-no" style="color: red;"></span>
                                <?php esc_html_e('Not writable', 'proto-blocks'); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <style>
            .proto-blocks-section { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin: 20px 0; }
            .proto-blocks-section h2 { margin-top: 0; }
        </style>
        <?php
    }

    /**
     * Get list of demo block names
     *
     * @return array List of demo block directory names
     */
    public function getDemoBlockNames(): array
    {
        $source_dir = \PROTO_BLOCKS_DIR . 'examples';
        if (!is_dir($source_dir)) {
            return [];
        }

        $blocks = glob($source_dir . '/*', GLOB_ONLYDIR);
        return array_map('basename', $blocks);
    }

    /**
     * Get list of installed demo blocks in the theme
     *
     * @return array List of installed demo block names
     */
    public function getInstalledDemoBlocks(): array
    {
        $demo_block_names = $this->getDemoBlockNames();
        $theme_blocks_dir = get_stylesheet_directory() . '/proto-blocks';
        $installed = [];

        foreach ($demo_block_names as $demo_name) {
            if (is_dir($theme_blocks_dir . '/' . $demo_name)) {
                $installed[] = $demo_name;
            }
        }

        return $installed;
    }

    /**
     * Install demo blocks to the active theme
     * Only installs/overwrites demo blocks, leaves custom blocks untouched
     *
     * @return int|\WP_Error Number of blocks installed or error
     */
    private function installDemoBlocks(): int|\WP_Error
    {
        // Get source and destination directories
        $source_dir = \PROTO_BLOCKS_DIR . 'examples';
        $dest_dir = get_stylesheet_directory() . '/proto-blocks';

        // Check if source exists
        if (!is_dir($source_dir)) {
            return new \WP_Error(
                'source_not_found',
                __('Demo blocks source directory not found.', 'proto-blocks')
            );
        }

        // Create destination directory if it doesn't exist
        if (!is_dir($dest_dir)) {
            if (!wp_mkdir_p($dest_dir)) {
                return new \WP_Error(
                    'cannot_create_dir',
                    sprintf(
                        __('Cannot create directory: %s', 'proto-blocks'),
                        $dest_dir
                    )
                );
            }
        }

        // Check if destination is writable
        if (!is_writable($dest_dir)) {
            return new \WP_Error(
                'not_writable',
                sprintf(
                    __('Directory is not writable: %s', 'proto-blocks'),
                    $dest_dir
                )
            );
        }

        // Get demo block names - only these will be installed/overwritten
        $demo_block_names = $this->getDemoBlockNames();
        $installed_count = 0;

        foreach ($demo_block_names as $block_name) {
            $source_block_path = $source_dir . '/' . $block_name;
            $dest_block_path = $dest_dir . '/' . $block_name;

            // If destination exists, remove it first (only for demo blocks)
            if (is_dir($dest_block_path)) {
                $this->removeDirectory($dest_block_path);
            }

            // Copy the block directory
            if ($this->copyDirectory($source_block_path, $dest_block_path)) {
                $installed_count++;
            }
        }

        // Clear the block discovery cache so new blocks are found
        delete_transient('proto_blocks_discovered');

        return $installed_count;
    }

    /**
     * Remove demo blocks from the active theme
     * Only removes demo blocks, leaves custom blocks untouched
     *
     * @return int|\WP_Error Number of blocks removed or error
     */
    private function removeDemoBlocks(): int|\WP_Error
    {
        $theme_blocks_dir = get_stylesheet_directory() . '/proto-blocks';

        // Check if theme blocks directory exists
        if (!is_dir($theme_blocks_dir)) {
            return new \WP_Error(
                'dir_not_found',
                __('No proto-blocks directory found in your theme.', 'proto-blocks')
            );
        }

        // Get installed demo blocks
        $installed_demo_blocks = $this->getInstalledDemoBlocks();

        if (empty($installed_demo_blocks)) {
            return new \WP_Error(
                'no_demo_blocks',
                __('No demo blocks found to remove.', 'proto-blocks')
            );
        }

        $removed_count = 0;

        foreach ($installed_demo_blocks as $block_name) {
            $block_path = $theme_blocks_dir . '/' . $block_name;

            if (is_dir($block_path)) {
                if ($this->removeDirectory($block_path)) {
                    $removed_count++;
                }
            }
        }

        // Clear the block discovery cache
        delete_transient('proto_blocks_discovered');

        return $removed_count;
    }

    /**
     * Recursively copy a directory
     *
     * @param string $source Source directory path
     * @param string $dest Destination directory path
     * @return bool Success status
     */
    private function copyDirectory(string $source, string $dest): bool
    {
        // Create destination directory
        if (!is_dir($dest)) {
            if (!wp_mkdir_p($dest)) {
                return false;
            }
        }

        // Open source directory
        $dir = opendir($source);
        if (!$dir) {
            return false;
        }

        // Copy each item
        while (($item = readdir($dir)) !== false) {
            // Skip . and ..
            if ($item === '.' || $item === '..') {
                continue;
            }

            $source_path = $source . '/' . $item;
            $dest_path = $dest . '/' . $item;

            if (is_dir($source_path)) {
                // Recursively copy subdirectory
                if (!$this->copyDirectory($source_path, $dest_path)) {
                    closedir($dir);
                    return false;
                }
            } else {
                // Copy file
                if (!copy($source_path, $dest_path)) {
                    closedir($dir);
                    return false;
                }
            }
        }

        closedir($dir);
        return true;
    }

    /**
     * Recursively remove a directory
     *
     * @param string $dir Directory path to remove
     * @return bool Success status
     */
    private function removeDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $items = scandir($dir);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }
}
