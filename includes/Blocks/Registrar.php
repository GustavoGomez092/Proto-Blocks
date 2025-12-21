<?php
/**
 * Block Registrar - Registers blocks with WordPress
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Blocks;

use ProtoBlocks\Schema\SchemaReader;
use ProtoBlocks\Schema\AttributeGenerator;
use ProtoBlocks\Template\Engine;
use ProtoBlocks\Core\Plugin;

/**
 * Registers Proto-Blocks with WordPress block editor
 */
class Registrar
{
    /**
     * Schema reader
     */
    private SchemaReader $schemaReader;

    /**
     * Template engine
     */
    private Engine $engine;

    /**
     * Block discovery
     */
    private Discovery $discovery;

    /**
     * Attribute generator
     */
    private ?AttributeGenerator $attributeGenerator = null;

    /**
     * Registered blocks
     *
     * @var array<string, array>
     */
    private array $registeredBlocks = [];

    /**
     * Constructor
     */
    public function __construct(SchemaReader $schemaReader, Engine $engine, Discovery $discovery)
    {
        $this->schemaReader = $schemaReader;
        $this->engine = $engine;
        $this->discovery = $discovery;

        // Set schema reader on engine
        $this->engine->setSchemaReader($schemaReader);
    }

    /**
     * Register all discovered blocks
     */
    public function registerBlocks(): void
    {
        $blocks = $this->discovery->discover();

        foreach ($blocks as $name => $path) {
            $this->registerBlock($name, $path);
        }

        // Fire action after all blocks registered
        do_action('proto_blocks_registered', $this->registeredBlocks);
    }

    /**
     * Register a single block
     */
    public function registerBlock(string $name, string $path): bool
    {
        try {
            // Read schema
            $schema = $this->schemaReader->read($path);

            // Generate attributes
            $attributes = $this->getAttributeGenerator()->generate($schema);

            // Get block metadata
            $supports = $schema['supports'] ?? [];
            $protoConfig = $schema['protoBlocks'] ?? [];

            // Register assets
            // Note: viewScriptModule from block.json is handled automatically by register_block_type
            $this->registerAssets($name, $path, $protoConfig, $schema);

            // Build block args
            $blockArgs = [
                'apiVersion' => $schema['apiVersion'] ?? 3,
                'title' => $schema['title'] ?? ucwords(str_replace('-', ' ', $name)),
                'description' => $schema['description'] ?? '',
                'category' => $schema['category'] ?? Category::getSlug(),
                'icon' => $schema['icon'] ?? 'block-default',
                'keywords' => $schema['keywords'] ?? [],
                'supports' => $supports,
                'attributes' => $attributes,
                'render_callback' => [$this->engine, 'renderBlock'],
                'editor_script' => 'proto-blocks-editor',
                'editor_style' => 'proto-blocks-editor',
            ];

            // Add block-specific styles if exists
            if ($this->hasBlockStyle($path, $name)) {
                $blockArgs['style'] = 'proto-blocks-' . $name;
            }

            // Add block-specific scripts if exists
            if ($this->hasBlockScript($path, $name)) {
                $blockArgs['script'] = 'proto-blocks-' . $name;
            }

            // Add example if defined
            if (!empty($schema['example'])) {
                $blockArgs['example'] = $schema['example'];
            }

            // Register with WordPress
            // Pass the block path so WordPress can read block.json and handle viewScriptModule
            $result = register_block_type($path, $blockArgs);

            if ($result) {
                $this->registeredBlocks[$name] = [
                    'path' => $path,
                    'schema' => $schema,
                    'attributes' => $attributes,
                ];

                return true;
            }
        } catch (\Throwable $e) {
            if (PROTO_BLOCKS_DEBUG) {
                error_log(sprintf(
                    'Proto-Blocks: Failed to register block "%s": %s',
                    $name,
                    $e->getMessage()
                ));
            }
        }

        return false;
    }

    /**
     * Register block assets
     */
    private function registerAssets(string $name, string $path, array $protoConfig, array $schema = []): void
    {
        // Determine base URL
        $baseUrl = $this->getAssetUrl($path);

        // Register block-specific CSS (try style.css first, then {name}.css)
        $cssFile = $this->getBlockStyleFile($path, $name);
        if ($cssFile) {
            wp_register_style(
                'proto-blocks-' . $name,
                $baseUrl . '/' . basename($cssFile),
                [],
                filemtime($cssFile)
            );
        }

        // Register block-specific JS (try view.js first, then {name}.js)
        // Skip if viewScriptModule is defined (it's an ES module handled by WordPress)
        $hasViewScriptModule = !empty($schema['viewScriptModule']);
        $jsFile = $this->getBlockScriptFile($path, $name);
        if ($jsFile && !$hasViewScriptModule) {
            $deps = [];

            // Add jQuery dependency if configured
            if (!empty($protoConfig['jquery'])) {
                $deps[] = 'jquery';
            }

            wp_register_script(
                'proto-blocks-' . $name,
                $baseUrl . '/' . basename($jsFile),
                $deps,
                filemtime($jsFile),
                true
            );
        }

        // Register interactivity store if configured
        // For viewScriptModule, we still need to manually register for proper dependency handling
        if (!empty($protoConfig['interactivity'])) {
            $this->registerInteractivityStore($name, $path, $protoConfig['interactivity'], $hasViewScriptModule);
        }
    }

    /**
     * Check if block has a style file
     */
    private function hasBlockStyle(string $path, string $name): bool
    {
        return $this->getBlockStyleFile($path, $name) !== null;
    }

    /**
     * Check if block has a script file
     */
    private function hasBlockScript(string $path, string $name): bool
    {
        return $this->getBlockScriptFile($path, $name) !== null;
    }

    /**
     * Get block style file path
     */
    private function getBlockStyleFile(string $path, string $name): ?string
    {
        // Try style.css first
        $styleCss = $path . '/style.css';
        if (file_exists($styleCss)) {
            return $styleCss;
        }

        // Try {name}.css
        $nameCss = $path . '/' . $name . '.css';
        if (file_exists($nameCss)) {
            return $nameCss;
        }

        return null;
    }

    /**
     * Get block script file path (excludes ES module files)
     */
    private function getBlockScriptFile(string $path, string $name): ?string
    {
        // Try view.js first - but check if it's an ES module
        $viewJs = $path . '/view.js';
        if (file_exists($viewJs) && !$this->isEsModule($viewJs)) {
            return $viewJs;
        }

        // Try {name}.js
        $nameJs = $path . '/' . $name . '.js';
        if (file_exists($nameJs) && !$this->isEsModule($nameJs)) {
            return $nameJs;
        }

        return null;
    }

    /**
     * Check if a JS file is an ES module (uses import/export)
     */
    private function isEsModule(string $filePath): bool
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return false;
        }

        // Check for ES module syntax
        return preg_match('/^\s*(import|export)\s+/m', $content) === 1;
    }

    /**
     * Register Interactivity API store
     */
    private function registerInteractivityStore(string $name, string $path, array $config, bool $hasViewScriptModule = false): void
    {
        if (!function_exists('wp_register_script_module')) {
            return;
        }

        // Try view.js first, then {name}.interactivity.js
        $moduleFile = $path . '/view.js';
        if (!file_exists($moduleFile)) {
            $moduleFile = $path . '/' . $name . '.interactivity.js';
            if (!file_exists($moduleFile)) {
                return;
            }
        }

        $baseUrl = $this->getAssetUrl($path);
        $moduleHandle = 'proto-blocks-' . $name . '-view';

        // Register the script module with @wordpress/interactivity dependency
        wp_register_script_module(
            $moduleHandle,
            $baseUrl . '/' . basename($moduleFile),
            ['@wordpress/interactivity'],
            filemtime($moduleFile)
        );

        // Store module handle for later enqueueing
        $blockModuleHandles = get_option('proto_blocks_module_handles', []);
        $blockModuleHandles[$name] = $moduleHandle;
        update_option('proto_blocks_module_handles', $blockModuleHandles, false);

        // Enqueue the module when the block is rendered on frontend
        add_filter('render_block_proto-blocks/' . $name, function($block_content, $block) use ($moduleHandle) {
            if (!is_admin() && !empty($block_content)) {
                wp_enqueue_script_module($moduleHandle);
            }
            return $block_content;
        }, 10, 2);

        // Also enqueue via wp_footer as backup
        $moduleUrl = $baseUrl . '/' . basename($moduleFile);
        add_action('wp_footer', function() use ($moduleHandle) {
            if (!is_admin()) {
                wp_enqueue_script_module($moduleHandle);
            }
        }, 1);

        // Fallback: directly output the module script since WordPress's wp_enqueue_script_module isn't working
        // WordPress already outputs the import map, so we just need to load our module
        add_action('wp_footer', function() use ($moduleUrl, $name) {
            if (!is_admin()) {
                echo "\n<!-- Proto-Blocks Interactivity Module for {$name} -->\n";
                echo '<script type="module" src="' . esc_url($moduleUrl) . '"></script>' . "\n";
            }
        }, 5);
    }

    /**
     * Get asset URL for a block path
     */
    private function getAssetUrl(string $path): string
    {
        $themeDir = get_template_directory();
        $childThemeDir = get_stylesheet_directory();

        // Check if in child theme
        if (str_starts_with($path, $childThemeDir)) {
            return get_stylesheet_directory_uri() . substr($path, strlen($childThemeDir));
        }

        // Check if in theme
        if (str_starts_with($path, $themeDir)) {
            return get_template_directory_uri() . substr($path, strlen($themeDir));
        }

        // Check if in plugin
        if (str_starts_with($path, WP_PLUGIN_DIR)) {
            $relativePath = substr($path, strlen(WP_PLUGIN_DIR));
            return plugins_url($relativePath);
        }

        // Fallback
        return $path;
    }

    /**
     * Get the attribute generator
     */
    private function getAttributeGenerator(): AttributeGenerator
    {
        if ($this->attributeGenerator === null) {
            $this->attributeGenerator = new AttributeGenerator(
                Plugin::getInstance()->getFieldRegistry(),
                Plugin::getInstance()->getControlRegistry()
            );
        }

        return $this->attributeGenerator;
    }

    /**
     * Resolve template path for a block
     */
    private function resolveTemplatePath(string $path, string $name): string
    {
        // Try template.php first
        $templatePhp = $path . '/template.php';
        if (file_exists($templatePhp)) {
            return $templatePhp;
        }

        // Try {name}.php
        $namePhp = $path . '/' . $name . '.php';
        if (file_exists($namePhp)) {
            return $namePhp;
        }

        // Return default (may not exist)
        return $templatePhp;
    }

    /**
     * Get registered blocks
     */
    public function getRegisteredBlocks(): array
    {
        return $this->registeredBlocks;
    }

    /**
     * Get data for JavaScript localization
     */
    public function getBlocksData(): array
    {
        $data = [];

        foreach ($this->registeredBlocks as $name => $block) {
            $schema = $block['schema'];
            $protoConfig = $schema['protoBlocks'] ?? [];

            // Parse template to get field info (templatePath should already be resolved by SchemaReader)
            $templatePath = $protoConfig['templatePath'] ?? $this->resolveTemplatePath($block['path'], $name);
            $parsedData = $this->engine->parse($templatePath, $schema);

            // Get preview image URL if exists
            $previewImageUrl = null;
            if (!empty($protoConfig['previewImage'])) {
                $previewImageUrl = $this->getAssetUrl(dirname($protoConfig['previewImage'])) . '/' . basename($protoConfig['previewImage']);
            }

            $data[] = [
                'name' => $name,
                'title' => $schema['title'] ?? ucwords(str_replace('-', ' ', $name)),
                'description' => $schema['description'] ?? '',
                'category' => $schema['category'] ?? Category::getSlug(),
                'icon' => $schema['icon'] ?? 'block-default',
                'keywords' => $schema['keywords'] ?? [],
                'supports' => $schema['supports'] ?? [],
                'attributes' => $block['attributes'],
                'fields' => $parsedData['fields'] ?? [],
                'controls' => $protoConfig['controls'] ?? [],
                'previewImage' => $previewImageUrl,
                'metadata' => $schema,
            ];
        }

        return $data;
    }
}
