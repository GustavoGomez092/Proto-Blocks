<?php
/**
 * Template Parser - Extracts editable fields from templates
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Template;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ProtoBlocks\Fields\Registry as FieldRegistry;

/**
 * Parses PHP/HTML templates to extract editable regions
 */
class Parser
{
    /**
     * Field registry
     */
    private FieldRegistry $fieldRegistry;

    /**
     * Parsed template HTML
     */
    private string $html = '';

    /**
     * Block metadata from JSON
     */
    private array $metadata = [];

    /**
     * Constructor
     */
    public function __construct(FieldRegistry $fieldRegistry)
    {
        $this->fieldRegistry = $fieldRegistry;
    }

    /**
     * Parse a template file
     *
     * @param string $templatePath Path to template file
     * @param array $metadata Block metadata from JSON
     * @return array Parsed data including fields and their configs
     */
    public function parse(string $templatePath, array $metadata = []): array
    {
        $this->metadata = $metadata;
        $protoConfig = $metadata['protoBlocks'] ?? [];

        // Load and execute the template
        $this->loadTemplate($templatePath, $protoConfig);

        // Parse the HTML
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8"?>' . $this->html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        // Extract fields
        $fields = $this->extractFields($dom);

        // Merge with JSON-defined fields
        $definedFields = $protoConfig['fields'] ?? [];
        $fields = $this->mergeFields($fields, $definedFields);

        return [
            'fields' => $fields,
            'html' => $this->html,
        ];
    }

    /**
     * Load and execute a template file
     */
    private function loadTemplate(string $templatePath, array $protoConfig): void
    {
        if (!file_exists($templatePath)) {
            throw new \RuntimeException(
                sprintf('Template file not found: %s', $templatePath)
            );
        }

        // Build default attributes from controls and fields for parsing
        $attributes = [];
        $controls = $protoConfig['controls'] ?? [];
        foreach ($controls as $name => $config) {
            $attributes[$name] = $config['default'] ?? null;
            // Also set as individual variables for backwards compatibility
            $$name = $config['default'] ?? null;
        }

        $fields = $protoConfig['fields'] ?? [];
        foreach ($fields as $name => $config) {
            $type = $config['type'] ?? 'text';
            $attributes[$name] = $this->getDefaultForType($type);
        }

        // Standard WordPress block variables
        $content = '';
        $block = null;

        // Create a template helper object
        $template = new class {
            public function has_value(string $name): bool
            {
                return true; // Always true during parsing
            }
        };

        // Define WordPress functions that may not exist during parsing
        $this->defineParsingHelpers();

        ob_start();

        try {
            // Execute the template
            include $templatePath;
        } catch (\Throwable $e) {
            // Log but don't fail - we just need the HTML structure
            if (defined('PROTO_BLOCKS_DEBUG') && PROTO_BLOCKS_DEBUG) {
                error_log(sprintf('Proto-Blocks Parser: Error executing template %s: %s', $templatePath, $e->getMessage()));
            }
        }

        $this->html = ob_get_clean() ?: '';
    }

    /**
     * Get default value for a field type
     */
    private function getDefaultForType(string $type): mixed
    {
        return match ($type) {
            'image' => [],
            'link' => [],
            'repeater' => [],
            'toggle' => false,
            'number', 'range' => 0,
            default => '',
        };
    }

    /**
     * Define helper functions for parsing context
     */
    private function defineParsingHelpers(): void
    {
        // Define get_block_wrapper_attributes if it doesn't exist
        if (!function_exists('get_block_wrapper_attributes')) {
            // This is a fallback for parsing - real function exists in WP context
        }
    }

    /**
     * Extract fields from DOM
     */
    private function extractFields(DOMDocument $dom): array
    {
        $fields = [];

        // Find all elements with proto-edit or zen-edit attributes
        $xpath = new DOMXPath($dom);
        $elements = $xpath->query('//*[@proto-edit or @zen-edit or @proto-repeater or @zen-repeater]');

        // First pass: find repeaters
        foreach ($elements as $element) {
            if (!($element instanceof DOMElement)) {
                continue;
            }

            $repeaterName = $element->getAttribute('proto-repeater') ?: $element->getAttribute('zen-repeater');
            if ($repeaterName) {
                $fields[$repeaterName] = $this->parseRepeaterField($element);
            }
        }

        // Second pass: find regular fields (not inside repeaters)
        foreach ($elements as $element) {
            if (!($element instanceof DOMElement)) {
                continue;
            }

            // Skip if this is a repeater
            if ($element->hasAttribute('proto-repeater') || $element->hasAttribute('zen-repeater')) {
                continue;
            }

            // Skip if inside a repeater (will be handled by repeater)
            if ($this->isInsideRepeater($element)) {
                continue;
            }

            $fieldName = $element->getAttribute('proto-edit') ?: $element->getAttribute('zen-edit');
            if ($fieldName) {
                $fields[$fieldName] = $this->parseField($element);
            }
        }

        return $fields;
    }

    /**
     * Parse a single field element
     */
    private function parseField(DOMElement $element): array
    {
        $type = $element->getAttribute('proto-type') ?: $element->getAttribute('zen-type') ?: 'text';
        $tagName = strtolower($element->tagName);

        // Get default value from element
        $default = $this->fieldRegistry->extractDefault($type, $element);

        return [
            'type' => $type,
            'tagName' => $tagName,
            'default' => $default,
            'className' => $element->getAttribute('class') ?: '',
        ];
    }

    /**
     * Parse a repeater field element
     */
    private function parseRepeaterField(DOMElement $element): array
    {
        $fields = [];

        // Find nested editable fields
        $xpath = new DOMXPath($element->ownerDocument);
        $nestedElements = $xpath->query('.//*[@proto-edit or @zen-edit]', $element);

        foreach ($nestedElements as $nested) {
            if (!($nested instanceof DOMElement)) {
                continue;
            }

            $fieldName = $nested->getAttribute('proto-edit') ?: $nested->getAttribute('zen-edit');
            if ($fieldName) {
                $fields[$fieldName] = $this->parseField($nested);
            }
        }

        return [
            'type' => 'repeater',
            'fields' => $fields,
            'default' => [],
            'tagName' => strtolower($element->tagName),
            'className' => $element->getAttribute('class') ?: '',
        ];
    }

    /**
     * Check if an element is inside a repeater
     */
    private function isInsideRepeater(DOMElement $element): bool
    {
        $parent = $element->parentNode;

        while ($parent instanceof DOMElement) {
            if ($parent->hasAttribute('proto-repeater') || $parent->hasAttribute('zen-repeater')) {
                return true;
            }
            $parent = $parent->parentNode;
        }

        return false;
    }

    /**
     * Merge extracted fields with JSON-defined fields
     */
    private function mergeFields(array $extracted, array $defined): array
    {
        foreach ($defined as $name => $config) {
            if (isset($extracted[$name])) {
                // Merge with extracted data, preferring JSON config
                $extracted[$name] = array_merge($extracted[$name], $config);
            } else {
                // Add from JSON definition
                $extracted[$name] = is_string($config) ? ['type' => $config] : $config;
            }
        }

        return $extracted;
    }

    /**
     * Get the raw HTML
     */
    public function getHtml(): string
    {
        return $this->html;
    }
}
