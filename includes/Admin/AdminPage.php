<?php
/**
 * Admin Page - Settings and management page
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Admin;

use ProtoBlocks\API\AdminRestController;
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
            [$this, 'renderDashboard'],
            $icon_base64,
            58
        );

        $dashboardHook = add_submenu_page(
            self::SLUG,
            __('Blocks', 'proto-blocks'),
            __('Blocks', 'proto-blocks'),
            'manage_options',
            self::SLUG,
            [$this, 'renderDashboard']
        );
        if (is_string($dashboardHook)) {
            Plugin::getInstance()->getAdminApp()?->addScreenHook($dashboardHook, 'dashboard');
        }

        $tailwindHook = add_submenu_page(
            self::SLUG,
            __('Tailwind Settings', 'proto-blocks'),
            __('Tailwind Settings', 'proto-blocks'),
            'manage_options',
            self::SLUG . '-settings',
            static function (): void {
                Plugin::getInstance()->getAdminApp()?->renderScreen('tailwind');
            }
        );
        if (is_string($tailwindHook)) {
            Plugin::getInstance()->getAdminApp()?->addScreenHook($tailwindHook, 'tailwind');
        }

        $systemHook = add_submenu_page(
            self::SLUG,
            __('System Status', 'proto-blocks'),
            __('System Status', 'proto-blocks'),
            'manage_options',
            self::SLUG . '-system',
            static function (): void {
                Plugin::getInstance()->getAdminApp()?->renderScreen('system');
            }
        );
        if (is_string($systemHook)) {
            Plugin::getInstance()->getAdminApp()?->addScreenHook($systemHook, 'system');
        }
    }

    /**
     * Menu callback for the Blocks screen: the React app mounts it.
     */
    public function renderDashboard(): void
    {
        Plugin::getInstance()->getAdminApp()?->renderScreen('dashboard');
    }

    /**
     * Everything the Blocks screen shows, as the admin/dashboard REST payload.
     *
     * @return array<string,mixed>
     */
    public function dashboardData(): array
    {
        $blocks = Plugin::getInstance()->getDiscovery()->getBlockInfo();
        $stats = $this->cache->getStats();
        $root = defined('ABSPATH') ? (string) \ABSPATH : '';

        $fromConfig = defined('PROTO_BLOCKS_EXAMPLE_BLOCKS_FROM_CONFIG') && \PROTO_BLOCKS_EXAMPLE_BLOCKS_FROM_CONFIG;
        $constant = defined('PROTO_BLOCKS_EXAMPLE_BLOCKS') && \PROTO_BLOCKS_EXAMPLE_BLOCKS;

        $rows = [];
        foreach ($blocks as $name => $block) {
            $rows[] = [
                'name' => (string) $name,
                'title' => (string) $block['title'],
                'description' => (string) $block['description'],
                'category' => (string) $block['category'],
                'location' => AdminRestController::relativePath((string) $block['path'], $root),
                'usesTailwind' => !empty($block['usesTailwind']),
                'hasJson' => (bool) $block['hasJson'],
                'hasCSS' => (bool) $block['hasCSS'],
                'hasJS' => (bool) $block['hasJS'],
                'isExample' => (bool) $block['isExample'],
            ];
        }

        return [
            'stats' => [
                'blocks' => count($rows),
                'cachedTemplates' => (int) $stats['file_count'],
                'cacheSize' => (string) size_format((int) $stats['total_size']),
            ],
            'exampleBlocks' => AdminRestController::exampleBlocksState(
                $fromConfig,
                $constant,
                get_option(self::OPTION_EXAMPLE_BLOCKS, false)
            ),
            'demos' => [
                'vanilla' => $this->getDemoBlockNamesByType('vanilla'),
                'tailwind' => $this->getDemoBlockNamesByType('tailwind'),
                'tailwindEnabled' => TailwindManager::getInstance()->isEnabled(),
                'installPath' => $this->installPath(),
            ],
            'installed' => $this->getInstalledDemoBlocks(),
            'conflicts' => $this->getConflictingDemoBlocks(),
            'blocks' => $rows,
        ];
    }

    /**
     * Where demo blocks are installed, relative to the WordPress root.
     */
    public function installPath(): string
    {
        return AdminRestController::relativePath(
            get_stylesheet_directory() . '/proto-blocks',
            defined('ABSPATH') ? (string) \ABSPATH : ''
        );
    }

    /**
     * Option key for category name
     */
    public const OPTION_CATEGORY_NAME = 'proto_blocks_category_name';

    /**
     * Option key for enabling the bundled example blocks
     */
    public const OPTION_EXAMPLE_BLOCKS = 'proto_blocks_example_blocks';

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
     * Name of the manifest file written into every installed demo block.
     * It records a checksum of each shipped file so install/remove can tell
     * an untouched demo copy from a same-named custom or edited block.
     */
    public const DEMO_MARKER = '.proto-blocks-demo';

    /**
     * Demo block types accepted by installDemoBlocks().
     */
    public const DEMO_TYPES = ['vanilla', 'tailwind', 'all'];

    /**
     * Demo blocks skipped by the last install/remove because the on-disk
     * directory is not an unmodified demo copy.
     *
     * @var string[]
     */
    private array $skippedDemoBlocks = [];

    /**
     * Whether WordPress allows this request to write code files
     * (DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT are not set).
     */
    public static function fileModsAllowed(): bool
    {
        if (defined('DISALLOW_FILE_MODS') && \DISALLOW_FILE_MODS) {
            return false;
        }
        if (defined('DISALLOW_FILE_EDIT') && \DISALLOW_FILE_EDIT) {
            return false;
        }
        return true;
    }

    /**
     * sha256 of every regular file under $dir, keyed by path relative to
     * $dir, sorted. The demo marker itself is excluded so the manifest can
     * be stored inside the directory it describes.
     *
     * @return array<string, string>
     */
    public static function directoryManifest(string $dir): array
    {
        $manifest = [];
        if (!is_dir($dir)) {
            return $manifest;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        $base = rtrim($dir, '/') . '/';

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($base));
            if ($relative === self::DEMO_MARKER) {
                continue;
            }
            $hash = hash_file('sha256', $file->getPathname());
            $manifest[str_replace('\\', '/', $relative)] = $hash === false ? '' : $hash;
        }

        ksort($manifest);
        return $manifest;
    }

    /**
     * Whether $installedDir is an unmodified copy of a shipped demo block.
     *
     * A block carrying the demo marker is compared against the manifest the
     * installer recorded at install time (so a demo installed from an older
     * plugin version still counts as a demo after the shipped files change).
     * A block without the marker (installed before the marker existed, or a
     * same-named custom block) is compared file-for-file with $shippedDir.
     * Anything that differs is treated as the developer's own code.
     */
    public static function isDemoCopy(string $installedDir, string $shippedDir): bool
    {
        if (!is_dir($installedDir)) {
            return false;
        }

        $current = self::directoryManifest($installedDir);
        if ($current === []) {
            return false;
        }

        $markerPath = rtrim($installedDir, '/') . '/' . self::DEMO_MARKER;
        if (is_file($markerPath)) {
            $raw = file_get_contents($markerPath);
            $marker = is_string($raw) ? json_decode($raw, true) : null;
            $recorded = is_array($marker) && isset($marker['files']) && is_array($marker['files'])
                ? $marker['files']
                : null;
            if ($recorded !== null) {
                ksort($recorded);
                return $recorded === $current;
            }
            // Unreadable marker: fall through to the shipped comparison.
        }

        if (!is_dir($shippedDir)) {
            return false;
        }

        return self::directoryManifest($shippedDir) === $current;
    }

    /**
     * Get list of installed demo blocks in the theme.
     * Only directories verified to be unmodified demo copies are listed;
     * see getConflictingDemoBlocks() for same-named directories that are not.
     *
     * @return array List of installed demo block names
     */
    public function getInstalledDemoBlocks(): array
    {
        return $this->classifyThemeDemoDirectories()['demo'];
    }

    /**
     * Same-named directories in the theme that are NOT unmodified demo
     * copies (custom blocks, or demos the developer has edited). These are
     * never overwritten or removed.
     *
     * @return string[]
     */
    public function getConflictingDemoBlocks(): array
    {
        return $this->classifyThemeDemoDirectories()['conflict'];
    }

    /**
     * Demo blocks the last install/remove refused to touch.
     *
     * @return string[]
     */
    public function getSkippedDemoBlocks(): array
    {
        return $this->skippedDemoBlocks;
    }

    /**
     * Split theme directories that share a demo block's name into verified
     * demo copies and everything else.
     *
     * @return array{demo: string[], conflict: string[]}
     */
    private function classifyThemeDemoDirectories(): array
    {
        $source_dir = \PROTO_BLOCKS_DIR . 'examples';
        $theme_blocks_dir = get_stylesheet_directory() . '/proto-blocks';
        $result = ['demo' => [], 'conflict' => []];

        foreach ($this->getDemoBlockNames() as $demo_name) {
            $installed = $theme_blocks_dir . '/' . $demo_name;
            if (!is_dir($installed)) {
                continue;
            }
            if (self::isDemoCopy($installed, $source_dir . '/' . $demo_name)) {
                $result['demo'][] = $demo_name;
            } else {
                $result['conflict'][] = $demo_name;
            }
        }

        return $result;
    }

    /**
     * Install demo blocks to the active theme
     * Only installs/overwrites demo blocks, leaves custom blocks untouched
     *
     * @param string $type Type of blocks to install: 'vanilla', 'tailwind', or 'all'
     * @return int|\WP_Error Number of blocks installed or error
     */
    public function installDemoBlocks(string $type = 'all'): int|\WP_Error
    {
        $this->skippedDemoBlocks = [];

        if (!current_user_can('manage_options')) {
            return new \WP_Error(
                'forbidden',
                __('You do not have permission to install demo blocks.', 'proto-blocks')
            );
        }

        if (!self::fileModsAllowed()) {
            return new \WP_Error(
                'file_mods_disallowed',
                __('File modifications are disabled on this site (DISALLOW_FILE_MODS), so demo blocks cannot be written to the theme.', 'proto-blocks')
            );
        }

        if (!in_array($type, self::DEMO_TYPES, true)) {
            return new \WP_Error(
                'invalid_demo_type',
                __('Unknown demo block type.', 'proto-blocks')
            );
        }

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

            // A same-named directory is only replaced when it is verifiably an
            // unmodified demo copy. Anything else is the developer's code: keep it.
            if (is_dir($dest_block_path)) {
                if (!self::isDemoCopy($dest_block_path, $source_block_path)) {
                    $this->skippedDemoBlocks[] = $block_name;
                    continue;
                }
                $this->removeDirectory($dest_block_path);
            }

            // Copy the block directory and record its manifest
            if ($this->copyDirectory($source_block_path, $dest_block_path)
                && $this->writeDemoMarker($dest_block_path)
            ) {
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
    public function removeDemoBlocks(): int|\WP_Error
    {
        $this->skippedDemoBlocks = [];

        if (!current_user_can('manage_options')) {
            return new \WP_Error(
                'forbidden',
                __('You do not have permission to remove demo blocks.', 'proto-blocks')
            );
        }

        if (!self::fileModsAllowed()) {
            return new \WP_Error(
                'file_mods_disallowed',
                __('File modifications are disabled on this site (DISALLOW_FILE_MODS), so demo blocks cannot be removed from the theme.', 'proto-blocks')
            );
        }

        $source_dir = \PROTO_BLOCKS_DIR . 'examples';
        $theme_blocks_dir = get_stylesheet_directory() . '/proto-blocks';

        // Check if theme blocks directory exists
        if (!is_dir($theme_blocks_dir)) {
            return new \WP_Error(
                'dir_not_found',
                __('No proto-blocks directory found in your theme.', 'proto-blocks')
            );
        }

        // Get installed demo blocks (verified copies only)
        $classified = $this->classifyThemeDemoDirectories();
        $installed_demo_blocks = $classified['demo'];
        $this->skippedDemoBlocks = $classified['conflict'];

        if (empty($installed_demo_blocks)) {
            return new \WP_Error(
                'no_demo_blocks',
                __('No demo blocks found to remove.', 'proto-blocks')
            );
        }

        $removed_count = 0;

        foreach ($installed_demo_blocks as $block_name) {
            $block_path = $theme_blocks_dir . '/' . $block_name;

            // Re-verify immediately before deleting: never remove a directory
            // that is not an unmodified demo copy.
            if (!is_dir($block_path) || !self::isDemoCopy($block_path, $source_dir . '/' . $block_name)) {
                $this->skippedDemoBlocks[] = $block_name;
                continue;
            }

            if ($this->removeDirectory($block_path)) {
                $removed_count++;
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
     * Write the demo marker (a manifest of the files just copied) into an
     * installed demo block directory.
     */
    private function writeDemoMarker(string $dest): bool
    {
        $marker = [
            'source'  => 'proto-blocks/examples',
            'version' => defined('PROTO_BLOCKS_VERSION') ? \PROTO_BLOCKS_VERSION : '',
            'files'   => self::directoryManifest($dest),
        ];
        $json = wp_json_encode($marker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }
        return file_put_contents(rtrim($dest, '/') . '/' . self::DEMO_MARKER, $json) !== false;
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
