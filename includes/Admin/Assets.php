<?php
/**
 * Assets - Handles script and style registration
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Admin;

use ProtoBlocks\Blocks\Discovery;
use ProtoBlocks\Schema\SchemaReader;
use ProtoBlocks\Fields\Registry as FieldRegistry;
use ProtoBlocks\Controls\Registry as ControlRegistry;
use ProtoBlocks\Core\Plugin;

/**
 * Manages asset registration and enqueuing
 */
class Assets
{
    /**
     * Block discovery
     */
    private Discovery $discovery;

    /**
     * Schema reader
     */
    private SchemaReader $schemaReader;

    /**
     * Field registry
     */
    private FieldRegistry $fieldRegistry;

    /**
     * Control registry
     */
    private ControlRegistry $controlRegistry;

    /**
     * Constructor
     */
    public function __construct(
        Discovery $discovery,
        SchemaReader $schemaReader,
        FieldRegistry $fieldRegistry,
        ControlRegistry $controlRegistry
    ) {
        $this->discovery = $discovery;
        $this->schemaReader = $schemaReader;
        $this->fieldRegistry = $fieldRegistry;
        $this->controlRegistry = $controlRegistry;
    }

    /**
     * Enqueue editor assets
     * Note: Script is already registered on init hook (priority 5)
     */
    public function enqueueEditorAssets(): void
    {
        // Enqueue the already-registered script
        wp_enqueue_script('proto-blocks-editor');

        // Localize data for JavaScript
        $this->localizeEditorData();
    }

    /**
     * Enqueue block assets (frontend + editor iframe)
     * This is the correct way to add styles to the iframed editor in WP 6.3+
     */
    public function enqueueBlockAssets(): void
    {
        // Editor styles - works in iframe
        if (is_admin()) {
            wp_enqueue_style(
                'proto-blocks-editor-css',
                PROTO_BLOCKS_URL . 'assets/css/editor.css',
                [],
                PROTO_BLOCKS_VERSION
            );
        }

        // Register common styles for frontend
        wp_register_style(
            'proto-blocks-common',
            PROTO_BLOCKS_URL . 'assets/css/common.css',
            [],
            PROTO_BLOCKS_VERSION
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueueAdminAssets(string $hook): void
    {
        // Only load on Proto-Blocks admin pages
        if (!str_contains($hook, 'proto-blocks')) {
            return;
        }

        wp_enqueue_style(
            'proto-blocks-admin',
            PROTO_BLOCKS_URL . 'assets/css/admin.css',
            [],
            PROTO_BLOCKS_VERSION
        );

        wp_enqueue_script(
            'proto-blocks-admin',
            PROTO_BLOCKS_URL . 'assets/js/admin.js',
            ['jquery', 'wp-api-fetch'],
            PROTO_BLOCKS_VERSION,
            true
        );

        wp_localize_script('proto-blocks-admin', 'protoBlocksAdmin', [
            'apiNamespace' => 'proto-blocks/v1',
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * Register editor script
     * Called early on init hook before blocks are registered
     */
    public function registerEditorScript(): void
    {
        $assetPath = PROTO_BLOCKS_DIR . 'assets/js/editor.asset.php';
        $asset = file_exists($assetPath) ? require $assetPath : [
            'dependencies' => [
                'wp-blocks',
                'wp-element',
                'wp-editor',
                'wp-components',
                'wp-i18n',
                'wp-api-fetch',
                'wp-block-editor',
                'wp-rich-text',
            ],
            'version' => PROTO_BLOCKS_VERSION,
        ];

        // Filter out invalid dependency handles
        // - Those with slashes (like wp-element/jsx-runtime)
        // - react-jsx-runtime (not a valid WordPress script handle)
        $invalidDeps = ['react-jsx-runtime'];
        $dependencies = array_filter($asset['dependencies'], function ($dep) use ($invalidDeps) {
            return !str_contains($dep, '/') && !in_array($dep, $invalidDeps, true);
        });

        wp_register_script(
            'proto-blocks-editor',
            PROTO_BLOCKS_URL . 'assets/js/editor.js',
            array_values($dependencies),
            $asset['version'],
            ['in_footer' => true, 'strategy' => 'defer']
        );
    }


    /**
     * Localize data for the editor
     */
    private function localizeEditorData(): void
    {
        // Get block data
        $blocksData = $this->getBlocksData();

        // Localize main data
        wp_localize_script('proto-blocks-editor', 'protoBlocksData', [
            'blocks' => $blocksData,
            'fieldTypes' => $this->fieldRegistry->getForJavaScript(),
            'controlTypes' => $this->controlRegistry->getForJavaScript(),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'previewNonce' => wp_create_nonce('proto_blocks_preview'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'debug' => PROTO_BLOCKS_DEBUG,
            'version' => PROTO_BLOCKS_VERSION,
            'categorySlug' => \ProtoBlocks\Blocks\Category::getSlug(),
            'categoryIcon' => 'M 247.29 113.27 C 281.81 92.76 317.20 73.73 351.74 53.24 C 381.54 70.41 411.26 87.73 441.05 104.91 C 445.94 107.55 450.55 110.67 455.17 113.76 C 455.17 190.50 455.17 267.24 455.16 343.98 C 421.16 364.15 386.68 383.52 352.65 403.64 C 320.46 422.38 288.26 441.09 256.02 459.73 C 219.34 438.57 182.70 417.33 146.19 395.88 C 116.39 378.59 86.48 361.51 56.83 343.99 C 56.83 303.91 56.82 263.83 56.83 223.75 C 86.20 206.41 115.83 189.50 145.32 172.37 C 150.57 169.58 155.39 166.09 160.53 163.12 C 166.16 166.77 171.92 170.20 178.05 172.97 C 201.17 186.24 224.21 199.68 247.30 213.01 C 247.27 179.76 247.30 146.52 247.29 113.27 M 273.42 118.25 C 299.35 133.26 325.25 148.31 351.22 163.24 C 359.85 159.03 368.03 154.02 376.35 149.25 C 393.91 139.08 411.49 128.94 429.04 118.75 C 403.10 103.76 377.20 88.68 351.22 73.76 C 325.35 88.68 299.41 103.50 273.42 118.25 M 265.23 134.08 C 265.20 163.98 265.22 193.88 265.21 223.78 C 291.25 238.14 316.57 253.78 342.54 268.26 C 342.49 238.42 342.56 208.58 342.51 178.74 C 316.94 163.53 290.99 148.96 265.23 134.08 M 359.95 178.75 C 359.93 208.41 359.94 238.07 359.94 267.73 C 385.92 253.00 411.84 238.15 437.73 223.27 C 437.79 193.43 437.75 163.60 437.75 133.76 C 411.84 148.79 385.67 163.40 359.95 178.75 M 82.95 228.27 C 108.85 243.38 134.92 258.23 160.73 273.50 C 186.52 258.44 212.27 243.33 238.05 228.26 C 212.33 213.18 186.19 198.81 160.76 183.25 C 135.01 198.56 108.86 213.21 82.95 228.27 M 234.70 250.70 C 215.86 261.54 197.02 272.38 178.18 283.24 C 204.09 298.29 230.24 312.94 256.00 328.23 C 281.73 313.36 307.55 298.63 333.27 283.74 C 307.44 268.48 281.22 253.89 255.47 238.51 C 248.74 242.90 241.61 246.61 234.70 250.70 M 74.24 243.76 C 74.25 273.60 74.21 303.43 74.26 333.27 C 100.19 348.25 126.10 363.27 152.04 378.23 C 152.08 348.41 152.05 318.59 152.05 288.77 C 126.09 273.81 100.18 258.76 74.24 243.76 M 359.95 288.74 C 359.93 318.56 359.92 348.38 359.95 378.20 C 385.91 363.28 411.80 348.23 437.74 333.27 C 437.78 303.60 437.75 273.93 437.75 244.26 C 411.82 259.09 385.86 273.87 359.95 288.74 M 264.74 343.44 C 264.66 373.37 264.73 403.31 264.71 433.24 C 290.68 418.40 316.78 403.76 342.51 388.52 C 342.54 358.51 342.52 328.51 342.53 298.51 C 316.58 313.46 290.73 328.56 264.74 343.44 M 169.48 388.51 C 195.07 403.78 221.04 418.43 246.77 433.46 C 246.79 403.65 246.77 373.84 246.79 344.03 C 220.99 329.11 195.22 314.13 169.43 299.19 C 169.53 328.96 169.43 358.74 169.48 388.51 Z',
        ]);
    }

    /**
     * Get blocks data for JavaScript
     */
    private function getBlocksData(): array
    {
        $blocks = $this->discovery->discover();
        $data = [];

        foreach ($blocks as $name => $path) {
            try {
                $schema = $this->schemaReader->read($path);
                $protoConfig = $schema['protoBlocks'] ?? [];

                // Resolve template path properly
                $templatePath = $this->resolveTemplatePath($path, $name, $protoConfig);

                $engine = Plugin::getInstance()->getEngine();
                $parsedData = $engine->parse($templatePath, $schema);

                // Get preview image URL if exists
                $previewImageUrl = null;
                if (!empty($protoConfig['previewImage'])) {
                    $previewImageUrl = $this->pathToUrl($protoConfig['previewImage']);
                }

                $data[] = [
                    'name' => $name,
                    'title' => $schema['title'] ?? ucwords(str_replace('-', ' ', $name)),
                    'description' => $schema['description'] ?? '',
                    'category' => $schema['category'] ?? 'proto-blocks',
                    'icon' => $schema['icon'] ?? 'block-default',
                    'keywords' => $schema['keywords'] ?? [],
                    'supports' => $schema['supports'] ?? [],
                    'fields' => $protoConfig['fields'] ?? $parsedData['fields'] ?? [],
                    'controls' => $protoConfig['controls'] ?? [],
                    'attributes' => $this->generateAttributes($schema, $parsedData),
                    'previewImage' => $previewImageUrl,
                    'metadata' => $schema,
                ];
            } catch (\Throwable $e) {
                if (PROTO_BLOCKS_DEBUG) {
                    error_log(sprintf(
                        'Proto-Blocks: Failed to load block "%s": %s in %s:%d',
                        $name,
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    ));
                }
            }
        }

        return $data;
    }

    /**
     * Resolve template path for a block
     */
    private function resolveTemplatePath(string $path, string $name, array $protoConfig): string
    {
        // Check if templatePath is already set
        if (!empty($protoConfig['templatePath']) && file_exists($protoConfig['templatePath'])) {
            return $protoConfig['templatePath'];
        }

        // Check for template config
        if (!empty($protoConfig['template'])) {
            $configPath = $path . '/' . $protoConfig['template'];
            if (file_exists($configPath)) {
                return $configPath;
            }
        }

        // Try template.php first
        $templatePhp = $path . '/template.php';
        if (file_exists($templatePhp)) {
            return $templatePhp;
        }

        // Fall back to {name}.php
        $namePhp = $path . '/' . $name . '.php';
        if (file_exists($namePhp)) {
            return $namePhp;
        }

        return $templatePhp;
    }

    /**
     * Generate attributes for a block
     */
    private function generateAttributes(array $schema, array $parsedData): array
    {
        $attributes = [];
        $protoConfig = $schema['protoBlocks'] ?? [];

        // From parsed fields
        foreach ($parsedData['fields'] ?? [] as $name => $fieldConfig) {
            $type = $fieldConfig['type'] ?? 'text';
            $fieldTypeConfig = $this->fieldRegistry->get($type);
            $attrSchema = $fieldTypeConfig['attribute_schema'] ?? ['type' => 'string'];

            $attributes[$name] = array_merge($attrSchema, [
                'default' => $fieldConfig['default'] ?? $attrSchema['default'] ?? '',
            ]);
        }

        // From controls
        foreach ($protoConfig['controls'] ?? [] as $name => $controlConfig) {
            if (!isset($attributes[$name])) {
                $attributes[$name] = $this->controlRegistry->buildAttribute($name, $controlConfig);
            }
        }

        // Core attributes
        $supports = $schema['supports'] ?? [];

        if (!empty($supports['align'])) {
            $attributes['align'] = ['type' => 'string'];
        }

        if (!empty($supports['anchor'])) {
            $attributes['anchor'] = ['type' => 'string'];
        }

        if (!empty($supports['customClassName'])) {
            $attributes['className'] = ['type' => 'string'];
        }

        // Style attribute
        $attributes['style'] = ['type' => 'object'];
        $attributes['innerBlocksContent'] = ['type' => 'string', 'default' => ''];

        return $attributes;
    }

    /**
     * Convert a file system path to a URL
     *
     * @param string $path File system path
     * @return string URL
     */
    private function pathToUrl(string $path): string
    {
        $themeDir = get_template_directory();
        $childThemeDir = get_stylesheet_directory();

        // Check if in child theme
        if (str_starts_with($path, $childThemeDir)) {
            return get_stylesheet_directory_uri() . substr($path, strlen($childThemeDir));
        }

        // Check if in parent theme
        if (str_starts_with($path, $themeDir)) {
            return get_template_directory_uri() . substr($path, strlen($themeDir));
        }

        // Check if in plugin directory
        if (str_starts_with($path, WP_PLUGIN_DIR)) {
            $relativePath = substr($path, strlen(WP_PLUGIN_DIR));
            return plugins_url($relativePath);
        }

        // Check if in wp-content
        if (str_starts_with($path, WP_CONTENT_DIR)) {
            $relativePath = substr($path, strlen(WP_CONTENT_DIR));
            return content_url($relativePath);
        }

        // Fallback - return as-is (might not work)
        return $path;
    }
}
