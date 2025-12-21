<?php
/**
 * Block Discovery - Finds blocks in theme and plugins
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Blocks;

/**
 * Discovers Proto-Blocks in theme and plugin directories
 */
class Discovery
{
    /**
     * Cached discovered blocks
     *
     * @var array<string, string>|null
     */
    private ?array $blocks = null;

    /**
     * Discover all blocks
     *
     * @return array<string, string> Map of block name => block directory path
     */
    public function discover(): array
    {
        if ($this->blocks !== null) {
            return $this->blocks;
        }

        $this->blocks = [];

        // Get all block paths
        $paths = $this->getBlockPaths();

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $this->discoverInPath($path);
        }

        // Allow filtering discovered blocks
        $this->blocks = apply_filters('proto_blocks_discovered', $this->blocks);

        return $this->blocks;
    }

    /**
     * Get all paths to search for blocks
     */
    public function getBlockPaths(): array
    {
        $paths = [
            // Theme directory
            get_template_directory() . '/proto-blocks',
        ];

        // Child theme (if different from parent)
        if (get_stylesheet_directory() !== get_template_directory()) {
            $paths[] = get_stylesheet_directory() . '/proto-blocks';
        }

        // Example blocks (if enabled)
        if (PROTO_BLOCKS_EXAMPLE_BLOCKS) {
            $paths[] = PROTO_BLOCKS_DIR . 'examples';
        }

        // Allow plugins to add paths
        $paths = apply_filters('proto_blocks_paths', $paths);

        return array_unique($paths);
    }

    /**
     * Discover blocks in a specific path
     */
    private function discoverInPath(string $path): void
    {
        $directories = glob($path . '/*', GLOB_ONLYDIR);

        if (!$directories) {
            return;
        }

        foreach ($directories as $dir) {
            $blockName = basename($dir);

            // Check for block.json (required)
            $jsonFile = $dir . '/block.json';
            if (!file_exists($jsonFile)) {
                // Also try {block-name}.json for backwards compatibility
                $jsonFile = $dir . '/' . $blockName . '.json';
                if (!file_exists($jsonFile)) {
                    continue;
                }
            }

            // Check for template file (template.php or {block-name}.php)
            $templateFile = $dir . '/template.php';
            if (!file_exists($templateFile)) {
                $templateFile = $dir . '/' . $blockName . '.php';
                if (!file_exists($templateFile)) {
                    continue;
                }
            }

            // Store block - don't overwrite if already exists (theme blocks take precedence)
            if (!isset($this->blocks[$blockName])) {
                $this->blocks[$blockName] = $dir;
            }
        }
    }

    /**
     * Get a specific block path
     */
    public function getBlockPath(string $blockName): ?string
    {
        $blocks = $this->discover();
        return $blocks[$blockName] ?? null;
    }

    /**
     * Check if a block exists
     */
    public function hasBlock(string $blockName): bool
    {
        return $this->getBlockPath($blockName) !== null;
    }

    /**
     * Refresh the discovery cache
     */
    public function refresh(): void
    {
        $this->blocks = null;
    }

    /**
     * Get block info for admin/API use
     *
     * @return array<string, array>
     */
    public function getBlockInfo(): array
    {
        $blocks = $this->discover();
        $info = [];

        foreach ($blocks as $name => $path) {
            // Try block.json first, then {block-name}.json
            $jsonPath = $path . '/block.json';
            if (!file_exists($jsonPath)) {
                $jsonPath = $path . '/' . $name . '.json';
            }

            $metadata = [];
            if (file_exists($jsonPath)) {
                $content = file_get_contents($jsonPath);
                $metadata = json_decode($content ?: '{}', true) ?: [];
            }

            $info[$name] = [
                'name' => $name,
                'path' => $path,
                'title' => $metadata['title'] ?? ucwords(str_replace('-', ' ', $name)),
                'description' => $metadata['description'] ?? '',
                'category' => $metadata['category'] ?? 'proto-blocks',
                'icon' => $metadata['icon'] ?? 'block-default',
                'hasJson' => file_exists($jsonPath),
                'hasCSS' => file_exists($path . '/style.css') || file_exists($path . '/' . $name . '.css'),
                'hasJS' => file_exists($path . '/view.js') || file_exists($path . '/' . $name . '.js'),
                'isExample' => $metadata['protoBlocks']['isExample']
                    ?? $metadata['isExample']
                    ?? str_contains($path, PROTO_BLOCKS_DIR . 'examples'),
            ];
        }

        return $info;
    }
}
