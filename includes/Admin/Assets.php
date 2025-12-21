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
        if (PROTO_BLOCKS_DEBUG) {
            error_log('Proto-Blocks: enqueueEditorAssets() called');
            error_log('Proto-Blocks: Script registered: ' . (wp_script_is('proto-blocks-editor', 'registered') ? 'yes' : 'no'));
        }

        // Enqueue the already-registered script
        wp_enqueue_script('proto-blocks-editor');

        if (PROTO_BLOCKS_DEBUG) {
            error_log('Proto-Blocks: Script enqueued: ' . (wp_script_is('proto-blocks-editor', 'enqueued') ? 'yes' : 'no'));
        }

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

        if (PROTO_BLOCKS_DEBUG) {
            error_log('Proto-Blocks: Asset file exists: ' . (file_exists($assetPath) ? 'yes' : 'no'));
            error_log('Proto-Blocks: Dependencies: ' . implode(', ', $dependencies));
        }

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
        ]);
    }

    /**
     * Get blocks data for JavaScript
     */
    private function getBlocksData(): array
    {
        $blocks = $this->discovery->discover();
        $data = [];

        if (PROTO_BLOCKS_DEBUG) {
            error_log('Proto-Blocks: Discovered ' . count($blocks) . ' blocks');
        }

        foreach ($blocks as $name => $path) {
            try {
                $schema = $this->schemaReader->read($path);
                $protoConfig = $schema['protoBlocks'] ?? [];

                // Resolve template path properly
                $templatePath = $this->resolveTemplatePath($path, $name, $protoConfig);

                if (PROTO_BLOCKS_DEBUG) {
                    error_log(sprintf('Proto-Blocks: Processing block "%s" with template "%s"', $name, $templatePath));
                }

                $engine = Plugin::getInstance()->getEngine();
                $parsedData = $engine->parse($templatePath, $schema);

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
                    'metadata' => $schema,
                ];

                if (PROTO_BLOCKS_DEBUG) {
                    error_log(sprintf('Proto-Blocks: Successfully loaded block "%s"', $name));
                }
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
}
