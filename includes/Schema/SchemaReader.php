<?php
/**
 * Schema Reader - Parses block.json files
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Schema;

use ProtoBlocks\Core\Plugin;

/**
 * Reads and parses block.json schema files
 */
class SchemaReader
{
    /**
     * Cache of parsed schemas
     *
     * @var array<string, array>
     */
    private array $cache = [];

    /**
     * Read and parse a block.json file
     *
     * @param string $blockPath Path to block directory or block.json file
     * @return array Parsed schema data
     * @throws \RuntimeException If schema file not found or invalid
     */
    public function read(string $blockPath): array
    {
        // Determine the JSON file path
        $jsonPath = $this->resolveJsonPath($blockPath);

        // Check cache
        if (isset($this->cache[$jsonPath])) {
            return $this->cache[$jsonPath];
        }

        if (!file_exists($jsonPath)) {
            throw new \RuntimeException(
                sprintf('Block schema file not found: %s', $jsonPath)
            );
        }

        $content = file_get_contents($jsonPath);
        if ($content === false) {
            throw new \RuntimeException(
                sprintf('Failed to read schema file: %s', $jsonPath)
            );
        }

        $schema = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                sprintf('Invalid JSON in schema file: %s - %s', $jsonPath, json_last_error_msg())
            );
        }

        // Validate the schema
        $validator = new SchemaValidator();
        $validator->validate($schema, $jsonPath);

        // Normalize and enhance the schema
        $schema = $this->normalizeSchema($schema, dirname($jsonPath));

        // Cache and return
        $this->cache[$jsonPath] = $schema;

        return $schema;
    }

    /**
     * Resolve the path to block.json
     */
    private function resolveJsonPath(string $blockPath): string
    {
        if (is_file($blockPath) && str_ends_with($blockPath, '.json')) {
            return $blockPath;
        }

        if (is_dir($blockPath)) {
            $blockName = basename($blockPath);
            $jsonPath = $blockPath . '/' . $blockName . '.json';

            if (file_exists($jsonPath)) {
                return $jsonPath;
            }

            // Fallback to block.json
            $fallback = $blockPath . '/block.json';
            if (file_exists($fallback)) {
                return $fallback;
            }
        }

        return $blockPath;
    }

    /**
     * Normalize the schema with defaults
     */
    private function normalizeSchema(array $schema, string $blockDir): array
    {
        // Extract block name from directory if not set
        if (!isset($schema['name'])) {
            $schema['name'] = 'proto-blocks/' . basename($blockDir);
        }

        // Default title from name
        if (!isset($schema['title'])) {
            $parts = explode('/', $schema['name']);
            $schema['title'] = ucwords(str_replace('-', ' ', end($parts)));
        }

        // Default category
        if (!isset($schema['category'])) {
            $schema['category'] = 'proto-blocks';
        }

        // Default API version
        if (!isset($schema['apiVersion'])) {
            $schema['apiVersion'] = 3;
        }

        // Normalize protoBlocks section
        $protoBlocks = $schema['protoBlocks'] ?? [];

        // Default version
        $protoBlocks['version'] = $protoBlocks['version'] ?? '1.0';

        // Resolve template path
        $templateFile = $protoBlocks['template'] ?? basename($blockDir) . '.php';
        $protoBlocks['templatePath'] = $blockDir . '/' . $templateFile;

        // Normalize fields
        $protoBlocks['fields'] = $this->normalizeFields($protoBlocks['fields'] ?? []);

        // Normalize controls
        $protoBlocks['controls'] = $this->normalizeControls($protoBlocks['controls'] ?? []);

        // Store block directory
        $protoBlocks['blockDir'] = $blockDir;

        // Detect preview image
        $protoBlocks['previewImage'] = $this->detectPreviewImage($blockDir);

        $schema['protoBlocks'] = $protoBlocks;

        // Ensure supports defaults
        $schema['supports'] = array_merge([
            'html' => false,
            'anchor' => true,
            'customClassName' => true,
        ], $schema['supports'] ?? []);

        return $schema;
    }

    /**
     * Normalize field definitions
     */
    private function normalizeFields(array $fields): array
    {
        $normalized = [];

        foreach ($fields as $name => $config) {
            if (is_string($config)) {
                // Simple format: "title": "text"
                $normalized[$name] = ['type' => $config];
            } else {
                $normalized[$name] = array_merge([
                    'type' => 'text',
                ], $config);
            }
        }

        return $normalized;
    }

    /**
     * Normalize control definitions
     */
    private function normalizeControls(array $controls): array
    {
        $normalized = [];

        foreach ($controls as $name => $config) {
            $normalized[$name] = array_merge([
                'type' => 'text',
                'label' => ucwords(str_replace(['_', '-'], ' ', $name)),
            ], $config);

            // Ensure options array format for select
            if ($config['type'] === 'select' && isset($config['options'])) {
                $normalized[$name]['options'] = $this->normalizeSelectOptions($config['options']);
            }
        }

        return $normalized;
    }

    /**
     * Normalize select options to consistent format
     */
    private function normalizeSelectOptions(array $options): array
    {
        $normalized = [];

        foreach ($options as $key => $value) {
            if (is_array($value)) {
                // Already in {key, label} format
                $normalized[] = [
                    'key' => $value['key'] ?? $value['value'] ?? $key,
                    'label' => $value['label'] ?? $value['value'] ?? $key,
                ];
            } else {
                // Simple key => label format
                $normalized[] = [
                    'key' => is_int($key) ? $value : $key,
                    'label' => $value,
                ];
            }
        }

        return $normalized;
    }

    /**
     * Get the protoBlocks section from a schema
     */
    public function getProtoBlocksConfig(string $blockPath): array
    {
        $schema = $this->read($blockPath);
        return $schema['protoBlocks'] ?? [];
    }

    /**
     * Clear the schema cache
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Detect preview image in block directory
     *
     * Looks for preview.png, preview.jpg, or preview.jpeg in the block folder.
     *
     * @param string $blockDir Block directory path
     * @return string|null Preview image file path or null if not found
     */
    private function detectPreviewImage(string $blockDir): ?string
    {
        $extensions = ['png', 'jpg', 'jpeg', 'webp'];

        foreach ($extensions as $ext) {
            $previewPath = $blockDir . '/preview.' . $ext;
            if (file_exists($previewPath)) {
                return $previewPath;
            }
        }

        return null;
    }
}
