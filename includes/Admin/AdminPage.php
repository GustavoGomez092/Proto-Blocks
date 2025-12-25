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
use ProtoBlocks\Tailwind\Manager as TailwindManager;

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
            __('Tailwind Settings', 'proto-blocks'),
            __('Tailwind Settings', 'proto-blocks'),
            'manage_options',
            self::SLUG . '-settings',
            [$this, 'renderSettingsPage']
        );

        add_submenu_page(
            self::SLUG,
            __('System Status', 'proto-blocks'),
            __('System Status', 'proto-blocks'),
            'manage_options',
            self::SLUG . '-system',
            [$this, 'renderSystemStatusPage']
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

        // Handle install vanilla CSS demo blocks action
        if (isset($_POST['proto_blocks_install_vanilla_demos']) && check_admin_referer('proto_blocks_install_vanilla_demos')) {
            $result = $this->installDemoBlocks('vanilla');
            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>';
                echo esc_html($result->get_error_message());
                echo '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>';
                printf(
                    esc_html(_n(
                        '%d vanilla CSS demo block installed to your theme.',
                        '%d vanilla CSS demo blocks installed to your theme.',
                        $result,
                        'proto-blocks'
                    )),
                    esc_html($result)
                );
                echo '</p></div>';
            }
        }

        // Handle install Tailwind demo blocks action
        if (isset($_POST['proto_blocks_install_tailwind_demos']) && check_admin_referer('proto_blocks_install_tailwind_demos')) {
            $tailwindEnabled = TailwindManager::getInstance()->isEnabled();
            if (!$tailwindEnabled) {
                echo '<div class="notice notice-error"><p>';
                esc_html_e('Cannot install Tailwind demo blocks: Tailwind CSS support is not enabled. Please enable it in Settings first.', 'proto-blocks');
                echo '</p></div>';
            } else {
                $result = $this->installDemoBlocks('tailwind');
                if (is_wp_error($result)) {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html($result->get_error_message());
                    echo '</p></div>';
                } else {
                    echo '<div class="notice notice-success"><p>';
                    printf(
                        esc_html(_n(
                            '%d Tailwind demo block installed to your theme.',
                            '%d Tailwind demo blocks installed to your theme.',
                            $result,
                            'proto-blocks'
                        )),
                        esc_html($result)
                    );
                    echo '</p></div>';
                }
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
        $vanilla_blocks = $this->getDemoBlockNamesByType('vanilla');
        $tailwind_blocks = $this->getDemoBlockNamesByType('tailwind');
        $tailwindEnabled = TailwindManager::getInstance()->isEnabled();
        $installed_demo_blocks = $this->getInstalledDemoBlocks();
        ?>
        <div class="wrap proto-blocks-admin-ui pb-bg-background-light pb-font-display pb-text-text-main-light pb-min-h-screen">
            <div class="pb-max-w-7xl pb-mx-auto pb-p-6 lg:pb-p-10 pb-space-y-8">
                <!-- Header -->
                <div class="pb-flex pb-flex-col md:pb-flex-row md:pb-items-center pb-justify-between pb-gap-4">
                    <div>
                        <h1 class="pb-text-3xl pb-font-bold pb-text-primary pb-mb-2"><?php esc_html_e('Blocks Dashboard', 'proto-blocks'); ?></h1>
                        <p class="pb-text-text-muted-light"><?php esc_html_e('Manage your Proto Blocks configuration and cache.', 'proto-blocks'); ?></p>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="pb-grid pb-grid-cols-1 md:pb-grid-cols-3 pb-gap-6">
                    <div class="pb-bg-surface-light pb-p-6 pb-rounded-lg pb-shadow-sm pb-border pb-border-border-light pb-flex pb-flex-col pb-items-center pb-justify-center pb-text-center pb-transition-all hover:pb-shadow-md">
                        <div class="pb-text-4xl pb-font-bold pb-text-primary pb-mb-1"><?php echo esc_html(count($blocks)); ?></div>
                        <div class="pb-text-sm pb-font-medium pb-text-text-muted-light pb-uppercase pb-tracking-wide"><?php esc_html_e('Registered Blocks', 'proto-blocks'); ?></div>
                    </div>
                    <div class="pb-bg-surface-light pb-p-6 pb-rounded-lg pb-shadow-sm pb-border pb-border-border-light pb-flex pb-flex-col pb-items-center pb-justify-center pb-text-center pb-transition-all hover:pb-shadow-md">
                        <div class="pb-text-4xl pb-font-bold pb-text-secondary-alt pb-mb-1"><?php echo esc_html($cacheStats['file_count']); ?></div>
                        <div class="pb-text-sm pb-font-medium pb-text-text-muted-light pb-uppercase pb-tracking-wide"><?php esc_html_e('Cached Templates', 'proto-blocks'); ?></div>
                    </div>
                    <div class="pb-bg-surface-light pb-p-6 pb-rounded-lg pb-shadow-sm pb-border pb-border-border-light pb-flex pb-flex-col pb-items-center pb-justify-center pb-text-center pb-transition-all hover:pb-shadow-md">
                        <div class="pb-text-4xl pb-font-bold pb-text-accent pb-mb-1"><?php echo esc_html(size_format($cacheStats['total_size'])); ?></div>
                        <div class="pb-text-sm pb-font-medium pb-text-text-muted-light pb-uppercase pb-tracking-wide"><?php esc_html_e('Cache Size', 'proto-blocks'); ?></div>
                    </div>
                </div>

                <!-- Demo Blocks Section -->
                <div class="pb-bg-surface-light pb-rounded-lg pb-shadow-sm pb-border pb-border-border-light pb-overflow-hidden">
                    <div class="pb-px-6 pb-py-4 pb-border-b pb-border-border-light pb-bg-gray-50">
                        <h2 class="pb-text-lg pb-font-semibold pb-flex pb-items-center pb-gap-2">
                            <span class="material-icons-outlined pb-text-primary">grid_view</span>
                            <?php esc_html_e('Demo Blocks', 'proto-blocks'); ?>
                        </h2>
                    </div>
                    <div class="pb-p-6">
                        <div class="pb-grid pb-grid-cols-1 lg:pb-grid-cols-2 pb-gap-6 pb-mb-6">
                            <!-- Vanilla CSS Blocks -->
                            <div class="pb-border pb-border-border-light pb-rounded-lg pb-p-5 pb-flex pb-flex-col pb-h-full pb-bg-white">
                                <div class="pb-flex pb-items-center pb-gap-2 pb-mb-3">
                                    <span class="material-icons-outlined pb-text-accent">brush</span>
                                    <h3 class="pb-font-bold pb-text-lg"><?php esc_html_e('Vanilla CSS Examples', 'proto-blocks'); ?></h3>
                                </div>
                                <div class="pb-bg-background-light pb-rounded pb-p-3 pb-text-sm pb-text-text-muted-light pb-mb-4">
                                    <?php esc_html_e('Classic CSS-styled demo blocks ready for import.', 'proto-blocks'); ?>
                                </div>
                                <div class="pb-flex pb-flex-wrap pb-gap-2 pb-mb-6">
                                    <?php foreach ($vanilla_blocks as $name): ?>
                                        <span class="pb-px-2 pb-py-1 pb-text-xs pb-font-medium pb-bg-gray-100 pb-text-gray-600 pb-rounded pb-font-mono pb-border pb-border-gray-200"><?php echo esc_html($name); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <div class="pb-mt-auto">
                                    <form method="post">
                                        <?php wp_nonce_field('proto_blocks_install_vanilla_demos'); ?>
                                        <button type="submit" name="proto_blocks_install_vanilla_demos" class="pb-bg-primary hover:pb-bg-primary-hover pb-text-white pb-px-4 pb-py-2 pb-rounded pb-shadow-sm pb-text-sm pb-font-medium pb-flex pb-items-center pb-gap-2 pb-transition-colors pb-w-full sm:pb-w-auto pb-justify-center">
                                            <span class="material-icons-outlined pb-text-sm">download</span>
                                            <?php esc_html_e('Install Vanilla CSS Blocks', 'proto-blocks'); ?>
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <!-- Tailwind CSS Blocks -->
                            <div class="pb-border pb-border-border-light pb-rounded-lg pb-p-5 pb-flex pb-flex-col pb-h-full pb-bg-white <?php echo !$tailwindEnabled ? 'pb-opacity-70' : ''; ?>">
                                <div class="pb-flex pb-items-center pb-gap-2 pb-mb-3">
                                    <span class="material-icons-outlined pb-text-secondary">wind_power</span>
                                    <h3 class="pb-font-bold pb-text-lg"><?php esc_html_e('Tailwind CSS Examples', 'proto-blocks'); ?></h3>
                                    <?php if (!$tailwindEnabled): ?>
                                        <span class="pb-px-2 pb-py-0.5 pb-text-xs pb-font-medium pb-bg-gray-100 pb-text-gray-500 pb-rounded"><?php esc_html_e('Requires Tailwind', 'proto-blocks'); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="pb-bg-background-light pb-rounded pb-p-3 pb-text-sm pb-text-text-muted-light pb-mb-4">
                                    <?php esc_html_e('Utility-first Tailwind CSS blocks for rapid UI development.', 'proto-blocks'); ?>
                                </div>
                                <div class="pb-flex pb-flex-wrap pb-gap-2 pb-mb-6">
                                    <?php foreach ($tailwind_blocks as $name): ?>
                                        <span class="pb-px-2 pb-py-1 pb-text-xs pb-font-medium pb-bg-gray-100 pb-text-gray-600 pb-rounded pb-font-mono pb-border pb-border-gray-200"><?php echo esc_html($name); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (!$tailwindEnabled): ?>
                                    <div class="pb-bg-amber-50 pb-border-l-4 pb-border-amber-400 pb-p-3 pb-mb-4 pb-text-sm pb-text-amber-800">
                                        <?php
                                        printf(
                                            __('Enable Tailwind CSS support in <a href="%s" class="pb-underline pb-font-medium">Settings</a> to install these blocks.', 'proto-blocks'),
                                            esc_url(admin_url('admin.php?page=proto-blocks-settings'))
                                        );
                                        ?>
                                    </div>
                                <?php endif; ?>
                                <div class="pb-mt-auto">
                                    <form method="post">
                                        <?php wp_nonce_field('proto_blocks_install_tailwind_demos'); ?>
                                        <button type="submit" name="proto_blocks_install_tailwind_demos" class="pb-bg-primary hover:pb-bg-primary-hover pb-text-white pb-px-4 pb-py-2 pb-rounded pb-shadow-sm pb-text-sm pb-font-medium pb-flex pb-items-center pb-gap-2 pb-transition-colors pb-w-full sm:pb-w-auto pb-justify-center disabled:pb-opacity-50 disabled:pb-cursor-not-allowed" <?php disabled(!$tailwindEnabled); ?>>
                                            <span class="material-icons-outlined pb-text-sm">download</span>
                                            <?php esc_html_e('Install Tailwind Blocks', 'proto-blocks'); ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Install Path Info -->
                        <div class="pb-bg-gray-50 pb-rounded pb-border pb-border-border-light pb-p-4 pb-flex pb-items-start pb-gap-3 pb-text-sm">
                            <span class="material-icons-outlined pb-text-text-muted-light pb-mt-0.5">folder_open</span>
                            <div>
                                <span class="pb-text-text-muted-light"><?php esc_html_e('Blocks will be installed to:', 'proto-blocks'); ?></span>
                                <code class="pb-ml-2 pb-bg-gray-200 pb-px-2 pb-py-1 pb-rounded pb-text-primary pb-font-mono pb-text-xs pb-break-all"><?php echo esc_html(str_replace(ABSPATH, '', get_stylesheet_directory() . '/proto-blocks/')); ?></code>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($installed_demo_blocks)): ?>
                <!-- Remove Demo Blocks Section -->
                <div class="pb-bg-surface-light pb-rounded-lg pb-shadow-sm pb-border pb-border-red-200 pb-border-l-4 pb-border-l-red-500 pb-overflow-hidden">
                    <div class="pb-px-6 pb-py-4 pb-border-b pb-border-border-light pb-bg-red-50">
                        <h2 class="pb-text-lg pb-font-semibold pb-flex pb-items-center pb-gap-2 pb-text-red-700">
                            <span class="material-icons-outlined">delete_outline</span>
                            <?php esc_html_e('Remove Demo Blocks', 'proto-blocks'); ?>
                        </h2>
                    </div>
                    <div class="pb-p-6">
                        <p class="pb-text-text-muted-light pb-text-sm pb-mb-4"><?php esc_html_e('Remove all demo blocks from your theme to start with a clean slate. Your custom blocks will not be affected.', 'proto-blocks'); ?></p>
                        <div class="pb-bg-blue-50 pb-border-l-4 pb-border-blue-400 pb-p-3 pb-mb-4 pb-text-sm pb-text-blue-800">
                            <?php
                            $blocks_formatted = array_map(function($name) {
                                return '<code class="pb-bg-blue-100 pb-px-1 pb-rounded">' . esc_html($name) . '</code>';
                            }, $installed_demo_blocks);
                            printf(
                                __('Demo blocks that will be removed: %s', 'proto-blocks'),
                                implode(', ', $blocks_formatted)
                            );
                            ?>
                        </div>
                        <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Are you sure you want to remove all demo blocks? This action cannot be undone.', 'proto-blocks')); ?>');">
                            <?php wp_nonce_field('proto_blocks_remove_demos'); ?>
                            <button type="submit" name="proto_blocks_remove_demos" class="pb-bg-white pb-border pb-border-red-500 pb-text-red-600 hover:pb-bg-red-500 hover:pb-text-white pb-px-4 pb-py-2 pb-rounded pb-shadow-sm pb-text-sm pb-font-medium pb-flex pb-items-center pb-gap-2 pb-transition-colors">
                                <span class="material-icons-outlined pb-text-sm">delete</span>
                                <?php esc_html_e('Remove Demo Blocks', 'proto-blocks'); ?>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Cache Management Section -->
                <div class="pb-bg-surface-light pb-rounded-lg pb-shadow-sm pb-border pb-border-border-light pb-overflow-hidden">
                    <div class="pb-px-6 pb-py-4 pb-border-b pb-border-border-light pb-bg-gray-50">
                        <h2 class="pb-text-lg pb-font-semibold pb-flex pb-items-center pb-gap-2">
                            <span class="material-icons-outlined pb-text-primary">cached</span>
                            <?php esc_html_e('Cache Management', 'proto-blocks'); ?>
                        </h2>
                    </div>
                    <div class="pb-p-6">
                        <p class="pb-text-text-muted-light pb-text-sm pb-mb-4"><?php esc_html_e('Clear the template cache to force recompilation of all blocks. Useful during active development.', 'proto-blocks'); ?></p>
                        <form method="post">
                            <?php wp_nonce_field('proto_blocks_clear_cache'); ?>
                            <button type="submit" name="proto_blocks_clear_cache" class="pb-bg-white pb-border pb-border-secondary pb-text-secondary-alt hover:pb-bg-secondary hover:pb-text-white pb-px-4 pb-py-2 pb-rounded pb-shadow-sm pb-text-sm pb-font-medium pb-transition-all pb-duration-200">
                                <?php esc_html_e('Clear Cache', 'proto-blocks'); ?>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Registered Blocks Section -->
                <div class="pb-bg-surface-light pb-rounded-lg pb-shadow-sm pb-border pb-border-border-light pb-overflow-hidden">
                    <div class="pb-px-6 pb-py-4 pb-border-b pb-border-border-light pb-bg-gray-50">
                        <h2 class="pb-text-lg pb-font-semibold pb-flex pb-items-center pb-gap-2">
                            <span class="material-icons-outlined pb-text-primary">list_alt</span>
                            <?php esc_html_e('Registered Blocks', 'proto-blocks'); ?>
                        </h2>
                    </div>
                    <?php if (empty($blocks)): ?>
                        <div class="pb-p-6">
                            <p class="pb-text-text-muted-light"><?php esc_html_e('No blocks found. Create a block in your theme\'s proto-blocks directory.', 'proto-blocks'); ?></p>
                        </div>
                    <?php else: ?>
                        <div class="pb-overflow-x-auto">
                            <table class="pb-w-full pb-text-sm pb-text-left">
                                <thead class="pb-text-xs pb-text-text-muted-light pb-uppercase pb-bg-gray-50 pb-border-b pb-border-border-light">
                                    <tr>
                                        <th class="pb-px-6 pb-py-3 pb-font-medium" scope="col"><?php esc_html_e('Block', 'proto-blocks'); ?></th>
                                        <th class="pb-px-6 pb-py-3 pb-font-medium" scope="col"><?php esc_html_e('Category', 'proto-blocks'); ?></th>
                                        <th class="pb-px-6 pb-py-3 pb-font-medium" scope="col"><?php esc_html_e('Location', 'proto-blocks'); ?></th>
                                        <th class="pb-px-6 pb-py-3 pb-font-medium" scope="col"><?php esc_html_e('Type', 'proto-blocks'); ?></th>
                                        <th class="pb-px-6 pb-py-3 pb-font-medium" scope="col"><?php esc_html_e('Assets', 'proto-blocks'); ?></th>
                                    </tr>
                                </thead>
                                <tbody class="pb-divide-y pb-divide-border-light">
                                    <?php foreach ($blocks as $name => $block): ?>
                                        <tr class="pb-bg-white hover:pb-bg-gray-50 pb-transition-colors">
                                            <td class="pb-px-6 pb-py-4">
                                                <div class="pb-font-medium pb-text-text-main-light"><?php echo esc_html($block['title']); ?></div>
                                                <div class="pb-font-mono pb-text-xs pb-text-text-muted-light pb-mt-1">proto-blocks/<?php echo esc_html($name); ?></div>
                                                <?php if ($block['isExample']): ?>
                                                    <span class="pb-inline-block pb-mt-1 pb-px-2 pb-py-0.5 pb-rounded pb-text-[10px] pb-font-semibold pb-bg-green-100 pb-text-green-700"><?php esc_html_e('Example', 'proto-blocks'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="pb-px-6 pb-py-4 pb-text-text-muted-light"><?php echo esc_html($block['category']); ?></td>
                                            <td class="pb-px-6 pb-py-4">
                                                <code class="pb-bg-gray-100 pb-px-2 pb-py-1 pb-rounded pb-text-xs pb-text-gray-600 pb-break-all"><?php echo esc_html(str_replace(ABSPATH, '', $block['path'])); ?></code>
                                            </td>
                                            <td class="pb-px-6 pb-py-4">
                                                <?php if (!empty($block['usesTailwind'])): ?>
                                                    <span class="pb-px-2 pb-py-1 pb-text-xs pb-font-medium pb-rounded pb-bg-blue-100 pb-text-blue-700 pb-border pb-border-blue-200"><?php esc_html_e('Tailwind', 'proto-blocks'); ?></span>
                                                <?php else: ?>
                                                    <span class="pb-px-2 pb-py-1 pb-text-xs pb-font-medium pb-rounded pb-bg-orange-100 pb-text-orange-700 pb-border pb-border-orange-200"><?php esc_html_e('Vanilla CSS', 'proto-blocks'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="pb-px-6 pb-py-4">
                                                <div class="pb-flex pb-gap-1 pb-flex-wrap">
                                                    <?php if ($block['hasJson']): ?>
                                                        <span class="pb-px-1.5 pb-py-0.5 pb-text-[10px] pb-font-bold pb-uppercase pb-rounded pb-bg-gray-100 pb-text-gray-600 pb-border pb-border-gray-200">JSON</span>
                                                    <?php endif; ?>
                                                    <?php if ($block['hasCSS']): ?>
                                                        <span class="pb-px-1.5 pb-py-0.5 pb-text-[10px] pb-font-bold pb-uppercase pb-rounded pb-bg-gray-100 pb-text-gray-600 pb-border pb-border-gray-200">CSS</span>
                                                    <?php endif; ?>
                                                    <?php if ($block['hasJS']): ?>
                                                        <span class="pb-px-1.5 pb-py-0.5 pb-text-[10px] pb-font-bold pb-uppercase pb-rounded pb-bg-gray-100 pb-text-gray-600 pb-border pb-border-gray-200">JS</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Start Section -->
                <div class="pb-bg-surface-light pb-rounded-lg pb-shadow-sm pb-border pb-border-border-light pb-overflow-hidden">
                    <div class="pb-px-6 pb-py-4 pb-border-b pb-border-border-light pb-bg-gray-50">
                        <h2 class="pb-text-lg pb-font-semibold pb-flex pb-items-center pb-gap-2">
                            <span class="material-icons-outlined pb-text-primary">rocket_launch</span>
                            <?php esc_html_e('Quick Start', 'proto-blocks'); ?>
                        </h2>
                    </div>
                    <div class="pb-p-6">
                        <p class="pb-text-sm pb-text-text-muted-light pb-mb-4"><?php esc_html_e('Create your first block by adding files to your theme structure:', 'proto-blocks'); ?></p>
                        <div class="pb-bg-background-light pb-rounded-lg pb-p-5 pb-border pb-border-border-light pb-font-mono pb-text-sm pb-overflow-x-auto">
                            <div class="pb-flex pb-flex-col pb-gap-1 pb-text-gray-700">
                                <div><span class="pb-bg-gray-200 pb-px-1 pb-rounded">your-theme/</span></div>
                                <div class="pb-pl-4">&#x2514;&#x2500;&#x2500; <span class="pb-text-primary pb-font-bold">proto-blocks/</span></div>
                                <div class="pb-pl-12">&#x2514;&#x2500;&#x2500; <span class="pb-font-semibold">my-block/</span></div>
                                <div class="pb-pl-20 pb-flex pb-gap-4"><span>&#x251C;&#x2500;&#x2500; my-block.json</span><span class="pb-text-gray-400 pb-italic"># Block configuration</span></div>
                                <div class="pb-pl-20 pb-flex pb-gap-4"><span>&#x251C;&#x2500;&#x2500; my-block.php</span><span class="pb-text-gray-400 pb-italic"># Template</span></div>
                                <div class="pb-pl-20 pb-flex pb-gap-4"><span>&#x2514;&#x2500;&#x2500; my-block.css</span><span class="pb-text-gray-400 pb-italic"># Styles (optional)</span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="pb-text-center md:pb-text-left pb-text-xs pb-text-text-muted-light pb-mt-8 pb-pt-4 pb-border-t pb-border-transparent">
                    <p><?php printf(esc_html__('Proto Blocks v%s', 'proto-blocks'), \PROTO_BLOCKS_VERSION); ?> &bull; <?php esc_html_e('Built for modern development.', 'proto-blocks'); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings page (Tailwind Settings)
     */
    public function renderSettingsPage(): void
    {
        ?>
        <div class="wrap proto-blocks-admin-ui pb-bg-background-light pb-font-display pb-text-text-main-light pb-min-h-screen">
            <div class="pb-max-w-4xl pb-mx-auto pb-p-6 lg:pb-p-10 pb-space-y-8">
                <!-- Header -->
                <div>
                    <h1 class="pb-text-3xl pb-font-bold pb-text-primary pb-mb-2"><?php esc_html_e('Tailwind Settings', 'proto-blocks'); ?></h1>
                    <p class="pb-text-text-muted-light"><?php esc_html_e('Configure Tailwind CSS integration for your Proto Blocks.', 'proto-blocks'); ?></p>
                </div>

                <?php
                // Render Tailwind CSS settings section
                $tailwindAdmin = Plugin::getInstance()->getTailwindAdminSettings();
                if ($tailwindAdmin !== null) {
                    $tailwindAdmin->render();
                } else {
                    ?>
                    <div class="pb-bg-surface-light pb-rounded-lg pb-shadow-sm pb-border pb-border-border-light pb-p-6">
                        <p class="pb-text-text-muted-light"><?php esc_html_e('Tailwind CSS settings are not available.', 'proto-blocks'); ?></p>
                    </div>
                    <?php
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Option key for category name
     */
    public const OPTION_CATEGORY_NAME = 'proto_blocks_category_name';

    /**
     * Render system status page
     */
    public function renderSystemStatusPage(): void
    {
        // Handle category name save action
        if (isset($_POST['proto_blocks_save_category_name']) && check_admin_referer('proto_blocks_save_category_name')) {
            $category_name = isset($_POST['proto_blocks_category_name']) ? sanitize_text_field($_POST['proto_blocks_category_name']) : '';
            update_option(self::OPTION_CATEGORY_NAME, $category_name);
            echo '<div class="notice notice-success is-dismissible"><p>';
            esc_html_e('Category name saved successfully.', 'proto-blocks');
            echo '</p></div>';
        }

        $category_name = get_option(self::OPTION_CATEGORY_NAME, '');
        ?>
        <div class="wrap proto-blocks-admin-ui pb-bg-background-light pb-font-display pb-text-text-main-light pb-min-h-screen">
            <div class="pb-max-w-4xl pb-mx-auto pb-p-6 lg:pb-p-10 pb-space-y-8">
                <!-- Header -->
                <div>
                    <h1 class="pb-text-3xl pb-font-bold pb-text-primary pb-mb-2"><?php esc_html_e('System Status', 'proto-blocks'); ?></h1>
                    <p class="pb-text-text-muted-light"><?php esc_html_e('View your Proto Blocks configuration and system information.', 'proto-blocks'); ?></p>
                </div>

                <!-- General Settings Section -->
                <div class="pb-bg-surface-light pb-rounded-lg pb-shadow-sm pb-border pb-border-border-light pb-overflow-hidden">
                    <div class="pb-px-6 pb-py-4 pb-border-b pb-border-border-light pb-bg-gray-50">
                        <h2 class="pb-text-lg pb-font-semibold pb-flex pb-items-center pb-gap-2">
                            <span class="material-icons-outlined pb-text-primary">tune</span>
                            <?php esc_html_e('General Settings', 'proto-blocks'); ?>
                        </h2>
                    </div>
                    <div class="pb-p-6">
                        <form method="post">
                            <?php wp_nonce_field('proto_blocks_save_category_name'); ?>
                            <div class="pb-space-y-4">
                                <div class="pb-flex pb-flex-col sm:pb-flex-row sm:pb-items-start pb-gap-2 sm:pb-gap-4">
                                    <div class="sm:pb-w-64 pb-flex-shrink-0">
                                        <label for="proto_blocks_category_name" class="pb-font-medium pb-text-sm"><?php esc_html_e('Block Category Name', 'proto-blocks'); ?></label>
                                        <p class="pb-text-text-muted-light pb-text-xs pb-mt-1"><?php esc_html_e('Customize the name shown in the block inserter.', 'proto-blocks'); ?></p>
                                    </div>
                                    <div class="pb-flex-1">
                                        <input type="text" name="proto_blocks_category_name" id="proto_blocks_category_name" value="<?php echo esc_attr($category_name); ?>" placeholder="<?php esc_attr_e('Proto Blocks', 'proto-blocks'); ?>" class="pb-w-full sm:pb-max-w-xs pb-px-3 pb-py-2 pb-border pb-border-border-light pb-rounded-lg pb-text-sm focus:pb-outline-none focus:pb-ring-2 focus:pb-ring-primary focus:pb-border-transparent" />
                                        <p class="pb-text-text-muted-light pb-text-xs pb-mt-2"><?php esc_html_e('Leave empty to use the default name "Proto Blocks".', 'proto-blocks'); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="pb-mt-6 pb-pt-4 pb-border-t pb-border-border-light">
                                <button type="submit" name="proto_blocks_save_category_name" class="pb-bg-primary hover:pb-bg-primary-hover pb-text-white pb-px-4 pb-py-2 pb-rounded pb-shadow-sm pb-text-sm pb-font-medium pb-transition-colors">
                                    <?php esc_html_e('Save Settings', 'proto-blocks'); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Configuration Section -->
                <div class="pb-bg-surface-light pb-rounded-lg pb-shadow-sm pb-border pb-border-border-light pb-overflow-hidden">
                    <div class="pb-px-6 pb-py-4 pb-border-b pb-border-border-light pb-bg-gray-50">
                        <h2 class="pb-text-lg pb-font-semibold pb-flex pb-items-center pb-gap-2">
                            <span class="material-icons-outlined pb-text-primary">settings</span>
                            <?php esc_html_e('Configuration', 'proto-blocks'); ?>
                        </h2>
                    </div>
                    <div class="pb-p-6">
                        <p class="pb-text-text-muted-light pb-text-sm pb-mb-6"><?php esc_html_e('Proto-Blocks can be configured via constants in wp-config.php:', 'proto-blocks'); ?></p>
                        <div class="pb-space-y-4">
                            <div class="pb-flex pb-flex-col sm:pb-flex-row sm:pb-items-start pb-gap-2 sm:pb-gap-4 pb-py-3 pb-border-b pb-border-border-light">
                                <div class="sm:pb-w-64 pb-flex-shrink-0">
                                    <code class="pb-bg-gray-100 pb-px-2 pb-py-1 pb-rounded pb-text-sm pb-font-mono pb-text-primary">PROTO_BLOCKS_DEBUG</code>
                                </div>
                                <div class="pb-flex-1">
                                    <code class="pb-text-sm <?php echo \PROTO_BLOCKS_DEBUG ? 'pb-text-green-600' : 'pb-text-gray-500'; ?>"><?php echo \PROTO_BLOCKS_DEBUG ? 'true' : 'false'; ?></code>
                                    <p class="pb-text-text-muted-light pb-text-sm pb-mt-1"><?php esc_html_e('Enable debug mode for detailed error messages.', 'proto-blocks'); ?></p>
                                </div>
                            </div>
                            <div class="pb-flex pb-flex-col sm:pb-flex-row sm:pb-items-start pb-gap-2 sm:pb-gap-4 pb-py-3 pb-border-b pb-border-border-light">
                                <div class="sm:pb-w-64 pb-flex-shrink-0">
                                    <code class="pb-bg-gray-100 pb-px-2 pb-py-1 pb-rounded pb-text-sm pb-font-mono pb-text-primary">PROTO_BLOCKS_CACHE_ENABLED</code>
                                </div>
                                <div class="pb-flex-1">
                                    <code class="pb-text-sm <?php echo \PROTO_BLOCKS_CACHE_ENABLED ? 'pb-text-green-600' : 'pb-text-gray-500'; ?>"><?php echo \PROTO_BLOCKS_CACHE_ENABLED ? 'true' : 'false'; ?></code>
                                    <p class="pb-text-text-muted-light pb-text-sm pb-mt-1"><?php esc_html_e('Enable template caching for improved performance.', 'proto-blocks'); ?></p>
                                </div>
                            </div>
                            <div class="pb-flex pb-flex-col sm:pb-flex-row sm:pb-items-start pb-gap-2 sm:pb-gap-4 pb-py-3">
                                <div class="sm:pb-w-64 pb-flex-shrink-0">
                                    <code class="pb-bg-gray-100 pb-px-2 pb-py-1 pb-rounded pb-text-sm pb-font-mono pb-text-primary">PROTO_BLOCKS_EXAMPLE_BLOCKS</code>
                                </div>
                                <div class="pb-flex-1">
                                    <code class="pb-text-sm <?php echo \PROTO_BLOCKS_EXAMPLE_BLOCKS ? 'pb-text-green-600' : 'pb-text-gray-500'; ?>"><?php echo \PROTO_BLOCKS_EXAMPLE_BLOCKS ? 'true' : 'false'; ?></code>
                                    <p class="pb-text-text-muted-light pb-text-sm pb-mt-1"><?php esc_html_e('Register example blocks for testing.', 'proto-blocks'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Information Section -->
                <div class="pb-bg-surface-light pb-rounded-lg pb-shadow-sm pb-border pb-border-border-light pb-overflow-hidden">
                    <div class="pb-px-6 pb-py-4 pb-border-b pb-border-border-light pb-bg-gray-50">
                        <h2 class="pb-text-lg pb-font-semibold pb-flex pb-items-center pb-gap-2">
                            <span class="material-icons-outlined pb-text-primary">info</span>
                            <?php esc_html_e('System Information', 'proto-blocks'); ?>
                        </h2>
                    </div>
                    <div class="pb-p-6">
                        <div class="pb-grid pb-grid-cols-1 sm:pb-grid-cols-2 pb-gap-4">
                            <div class="pb-p-4 pb-bg-gray-50 pb-rounded-lg">
                                <div class="pb-text-sm pb-text-text-muted-light pb-mb-1"><?php esc_html_e('Plugin Version', 'proto-blocks'); ?></div>
                                <div class="pb-font-semibold"><?php echo esc_html(\PROTO_BLOCKS_VERSION); ?></div>
                            </div>
                            <div class="pb-p-4 pb-bg-gray-50 pb-rounded-lg">
                                <div class="pb-text-sm pb-text-text-muted-light pb-mb-1"><?php esc_html_e('WordPress Version', 'proto-blocks'); ?></div>
                                <div class="pb-font-semibold"><?php echo esc_html(get_bloginfo('version')); ?></div>
                            </div>
                            <div class="pb-p-4 pb-bg-gray-50 pb-rounded-lg">
                                <div class="pb-text-sm pb-text-text-muted-light pb-mb-1"><?php esc_html_e('PHP Version', 'proto-blocks'); ?></div>
                                <div class="pb-font-semibold"><?php echo esc_html(PHP_VERSION); ?></div>
                            </div>
                            <div class="pb-p-4 pb-bg-gray-50 pb-rounded-lg">
                                <div class="pb-text-sm pb-text-text-muted-light pb-mb-1"><?php esc_html_e('Cache Directory', 'proto-blocks'); ?></div>
                                <div class="pb-flex pb-items-center pb-gap-2">
                                    <code class="pb-text-xs pb-break-all"><?php echo esc_html($this->cache->getCacheDir()); ?></code>
                                    <?php if (is_writable($this->cache->getCacheDir())): ?>
                                        <span class="material-icons-outlined pb-text-green-600 pb-text-lg">check_circle</span>
                                    <?php else: ?>
                                        <span class="material-icons-outlined pb-text-red-600 pb-text-lg">error</span>
                                        <span class="pb-text-red-600 pb-text-sm"><?php esc_html_e('Not writable', 'proto-blocks'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="pb-text-center md:pb-text-left pb-text-xs pb-text-text-muted-light pb-pt-4">
                    <p><?php printf(esc_html__('Proto Blocks v%s', 'proto-blocks'), \PROTO_BLOCKS_VERSION); ?> &bull; <?php esc_html_e('Built for modern development.', 'proto-blocks'); ?></p>
                </div>
            </div>
        </div>
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
     * Get list of demo block names filtered by type (vanilla or tailwind)
     *
     * @param string $type 'vanilla' or 'tailwind'
     * @return array List of demo block names of the specified type
     */
    public function getDemoBlockNamesByType(string $type): array
    {
        $source_dir = \PROTO_BLOCKS_DIR . 'examples';
        if (!is_dir($source_dir)) {
            return [];
        }

        $all_blocks = glob($source_dir . '/*', GLOB_ONLYDIR);
        $filtered = [];

        foreach ($all_blocks as $block_path) {
            $block_name = basename($block_path);
            $json_file = $block_path . '/block.json';

            if (!file_exists($json_file)) {
                continue;
            }

            $content = file_get_contents($json_file);
            $metadata = json_decode($content ?: '{}', true) ?: [];
            $usesTailwind = $metadata['protoBlocks']['useTailwind'] ?? false;

            if ($type === 'tailwind' && $usesTailwind) {
                $filtered[] = $block_name;
            } elseif ($type === 'vanilla' && !$usesTailwind) {
                $filtered[] = $block_name;
            }
        }

        return $filtered;
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
     * @param string $type Type of blocks to install: 'vanilla', 'tailwind', or 'all'
     * @return int|\WP_Error Number of blocks installed or error
     */
    private function installDemoBlocks(string $type = 'all'): int|\WP_Error
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

        // Get demo block names based on type
        if ($type === 'vanilla') {
            $demo_block_names = $this->getDemoBlockNamesByType('vanilla');
        } elseif ($type === 'tailwind') {
            $demo_block_names = $this->getDemoBlockNamesByType('tailwind');
        } else {
            $demo_block_names = $this->getDemoBlockNames();
        }

        if (empty($demo_block_names)) {
            return new \WP_Error(
                'no_blocks_found',
                sprintf(
                    __('No %s demo blocks found to install.', 'proto-blocks'),
                    $type === 'all' ? '' : $type
                )
            );
        }

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
