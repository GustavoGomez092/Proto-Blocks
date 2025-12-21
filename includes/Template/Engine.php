<?php
/**
 * Template Engine - Main orchestrator for template processing
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Template;

use ProtoBlocks\Fields\Registry as FieldRegistry;
use ProtoBlocks\Controls\Registry as ControlRegistry;
use ProtoBlocks\Schema\SchemaReader;
use ProtoBlocks\Core\Plugin;

/**
 * Main engine that orchestrates template parsing, caching, and rendering
 */
class Engine
{
    /**
     * Template cache
     */
    private Cache $cache;

    /**
     * Field registry
     */
    private FieldRegistry $fieldRegistry;

    /**
     * Control registry
     */
    private ControlRegistry $controlRegistry;

    /**
     * Parser instance (lazy loaded)
     */
    private ?Parser $parser = null;

    /**
     * Renderer instance (lazy loaded)
     */
    private ?Renderer $renderer = null;

    /**
     * Schema reader
     */
    private ?SchemaReader $schemaReader = null;

    /**
     * Constructor
     */
    public function __construct(Cache $cache, FieldRegistry $fieldRegistry, ?ControlRegistry $controlRegistry = null)
    {
        $this->cache = $cache;
        $this->fieldRegistry = $fieldRegistry;
        $this->controlRegistry = $controlRegistry ?? Plugin::getInstance()->getControlRegistry();
    }

    /**
     * Set the schema reader
     */
    public function setSchemaReader(SchemaReader $schemaReader): void
    {
        $this->schemaReader = $schemaReader;
    }

    /**
     * Parse a template and extract field definitions
     *
     * @param string $templatePath Path to template file
     * @param array $metadata Block metadata (from JSON)
     * @return array Parsed template data
     */
    public function parse(string $templatePath, array $metadata = []): array
    {
        // Check cache first
        $cached = $this->cache->get($templatePath);
        if ($cached !== null) {
            return $cached;
        }

        // Parse the template
        $data = $this->getParser()->parse($templatePath, $metadata);

        // Store in cache
        $this->cache->set($templatePath, $data);

        return $data;
    }

    /**
     * Render a template with attributes
     *
     * @param string $templatePath Path to template file
     * @param array $attributes Block attributes
     * @param array $metadata Block metadata
     * @return string Rendered HTML
     */
    public function render(string $templatePath, array $attributes, array $metadata = []): string
    {
        return $this->getRenderer()->render($templatePath, $attributes, $metadata);
    }

    /**
     * Render a preview for the editor
     *
     * @param string $templatePath Path to template file
     * @param array $attributes Block attributes
     * @param array $metadata Block metadata
     * @return string Preview HTML (with proto-* attributes preserved)
     */
    public function renderPreview(string $templatePath, array $attributes, array $metadata = []): string
    {
        return $this->getRenderer()->renderPreview($templatePath, $attributes, $metadata);
    }

    /**
     * Compile and cache a template
     *
     * Pre-compiles a template for faster subsequent renders
     *
     * @param string $blockPath Path to block directory
     * @return array Compiled template data
     */
    public function compile(string $blockPath): array
    {
        $templatePath = $this->resolveTemplatePath($blockPath);
        $metadata = $this->getMetadata($blockPath);

        // Force re-parse and cache
        $this->cache->delete($templatePath);
        $data = $this->parse($templatePath, $metadata);

        // Add compilation metadata
        $data['compiled_at'] = time();
        $data['block_path'] = $blockPath;

        // Update cache with compilation data
        $this->cache->set($templatePath, $data);

        return $data;
    }

    /**
     * Get compiled template data from cache or compile fresh
     */
    public function getCompiled(string $blockPath): array
    {
        $templatePath = $this->resolveTemplatePath($blockPath);

        $cached = $this->cache->get($templatePath);
        if ($cached !== null) {
            return $cached;
        }

        return $this->compile($blockPath);
    }

    /**
     * Clear cache for a specific template or all templates
     */
    public function clearCache(?string $templatePath = null): int
    {
        if ($templatePath !== null) {
            $this->cache->delete($templatePath);
            return 1;
        }

        return $this->cache->clear();
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        return $this->cache->getStats();
    }

    /**
     * Render callback for WordPress block registration
     *
     * @param array $attributes Block attributes
     * @param string $content Inner blocks content
     * @param \WP_Block $block Block instance
     * @return string Rendered HTML
     */
    public function renderBlock(array $attributes, string $content, \WP_Block $block): string
    {
        $blockName = str_replace('proto-blocks/', '', $block->name);
        $blockPath = $this->resolveBlockPath($blockName);

        if (!$blockPath) {
            return sprintf('<!-- Block not found: %s -->', esc_html($blockName));
        }

        // Add inner blocks content to attributes
        $attributes['innerBlocksContent'] = $content;

        $metadata = $this->getMetadata($blockPath);
        $templatePath = $this->resolveTemplatePath($blockPath);

        try {
            return $this->render($templatePath, $attributes, $metadata);
        } catch (\Throwable $e) {
            if (PROTO_BLOCKS_DEBUG) {
                return sprintf(
                    '<!-- Error rendering block %s: %s -->',
                    esc_html($blockName),
                    esc_html($e->getMessage())
                );
            }
            return '';
        }
    }

    /**
     * Get the parser instance
     */
    private function getParser(): Parser
    {
        if ($this->parser === null) {
            $this->parser = new Parser($this->fieldRegistry);
        }

        return $this->parser;
    }

    /**
     * Get the renderer instance
     */
    private function getRenderer(): Renderer
    {
        if ($this->renderer === null) {
            $this->renderer = new Renderer($this->fieldRegistry, $this->controlRegistry);
        }

        return $this->renderer;
    }

    /**
     * Resolve template path from block path
     */
    private function resolveTemplatePath(string $blockPath): string
    {
        // Check metadata first for explicit template configuration
        $metadata = $this->getMetadata($blockPath);
        $protoConfig = $metadata['protoBlocks'] ?? [];

        if (!empty($protoConfig['templatePath']) && file_exists($protoConfig['templatePath'])) {
            return $protoConfig['templatePath'];
        }

        if (!empty($protoConfig['template'])) {
            $configPath = $blockPath . '/' . $protoConfig['template'];
            if (file_exists($configPath)) {
                return $configPath;
            }
        }

        // Try template.php first (standard naming)
        $templatePhp = $blockPath . '/template.php';
        if (file_exists($templatePhp)) {
            return $templatePhp;
        }

        // Fall back to {blockName}.php
        $blockName = basename($blockPath);
        $blockNamePhp = $blockPath . '/' . $blockName . '.php';
        if (file_exists($blockNamePhp)) {
            return $blockNamePhp;
        }

        // Return template.php as default (even if doesn't exist, for error reporting)
        return $templatePhp;
    }

    /**
     * Get block metadata from JSON
     */
    private function getMetadata(string $blockPath): array
    {
        if ($this->schemaReader) {
            try {
                return $this->schemaReader->read($blockPath);
            } catch (\Throwable $e) {
                // Fall back to direct JSON read
            }
        }

        $blockName = basename($blockPath);
        $jsonPath = $blockPath . '/' . $blockName . '.json';

        if (!file_exists($jsonPath)) {
            return [];
        }

        $content = file_get_contents($jsonPath);
        return json_decode($content ?: '{}', true) ?: [];
    }

    /**
     * Resolve block path from block name
     */
    private function resolveBlockPath(string $blockName): ?string
    {
        $discovery = Plugin::getInstance()->getDiscovery();
        $blocks = $discovery->discover();

        return $blocks[$blockName] ?? null;
    }
}
