<?php
/**
 * Template Renderer - Renders templates with attribute values
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Template;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ProtoBlocks\Fields\Registry as FieldRegistry;
use ProtoBlocks\Controls\Registry as ControlRegistry;

/**
 * Renders PHP templates with saved attribute values
 */
class Renderer
{
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
    public function __construct(FieldRegistry $fieldRegistry, ControlRegistry $controlRegistry)
    {
        $this->fieldRegistry = $fieldRegistry;
        $this->controlRegistry = $controlRegistry;
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
        $protoConfig = $metadata['protoBlocks'] ?? [];

        // Process control values
        $processedAttributes = $this->processControlValues($attributes, $protoConfig);

        // Execute template with attributes
        $html = $this->executeTemplate($templatePath, $processedAttributes, $protoConfig);

        // Parse and update DOM with attribute values
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8"?>' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        // Apply WordPress core features (alignment, colors, etc.)
        $this->applyCoreFeatures($dom, $processedAttributes);

        // Update field values
        $this->updateFields($dom, $processedAttributes, $protoConfig);

        // Clean up proto/zen attributes
        $this->cleanupAttributes($dom);

        // Return final HTML
        $html = $dom->saveHTML();
        return html_entity_decode($html ?: '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Render a preview (keeps proto-* attributes for editor)
     */
    public function renderPreview(string $templatePath, array $attributes, array $metadata = []): string
    {
        $protoConfig = $metadata['protoBlocks'] ?? [];

        // Process control values
        $processedAttributes = $this->processControlValues($attributes, $protoConfig);

        // Execute template
        $html = $this->executeTemplate($templatePath, $processedAttributes, $protoConfig);

        return $html;
    }

    /**
     * Execute the template file
     */
    private function executeTemplate(string $templatePath, array $attributes, array $protoConfig): string
    {
        if (!file_exists($templatePath)) {
            throw new \RuntimeException(
                sprintf('Template file not found: %s', $templatePath)
            );
        }

        ob_start();

        // Transform attribute keys to PHP-safe variable names
        $variables = $this->transformAttributeKeys($attributes);

        // Extract variables
        extract($variables);

        // Create template helper
        $template = new class($attributes) {
            private array $attrs;

            public function __construct(array $attrs)
            {
                $this->attrs = $attrs;
            }

            public function has_value(string $name): bool
            {
                return !empty($this->attrs[$name]);
            }

            public function get(string $name, mixed $default = null): mixed
            {
                return $this->attrs[$name] ?? $default;
            }
        };

        include $templatePath;

        return ob_get_clean() ?: '';
    }

    /**
     * Transform attribute keys to PHP-safe variable names
     */
    private function transformAttributeKeys(array $attributes): array
    {
        $transformed = [];

        foreach ($attributes as $key => $value) {
            $newKey = str_replace(['-', ' '], '_', $key);
            $transformed[$newKey] = $value;
        }

        return $transformed;
    }

    /**
     * Process control values
     */
    private function processControlValues(array $attributes, array $protoConfig): array
    {
        $controls = $protoConfig['controls'] ?? [];

        foreach ($controls as $name => $config) {
            $type = $config['type'] ?? 'text';

            // Set default if not present
            if (!isset($attributes[$name]) && isset($config['default'])) {
                $attributes[$name] = $config['default'];
            }

            // Process the value
            if (isset($attributes[$name])) {
                $attributes[$name] = $this->controlRegistry->processValue(
                    $type,
                    $attributes[$name],
                    $config
                );
            }
        }

        return $attributes;
    }

    /**
     * Apply WordPress core block features
     */
    private function applyCoreFeatures(DOMDocument $dom, array $attributes): void
    {
        $root = $dom->documentElement;
        if (!($root instanceof DOMElement)) {
            return;
        }

        $classes = explode(' ', $root->getAttribute('class') ?: '');
        $styles = [];

        // Parse existing styles
        $existingStyle = $root->getAttribute('style');
        if ($existingStyle) {
            $styles[] = rtrim($existingStyle, ';');
        }

        // Alignment
        if (!empty($attributes['align'])) {
            $classes[] = 'align' . $attributes['align'];
        }

        // Custom class
        if (!empty($attributes['className'])) {
            $classes = array_merge($classes, explode(' ', $attributes['className']));
        }

        // Anchor
        if (!empty($attributes['anchor'])) {
            $root->setAttribute('id', $attributes['anchor']);
        }

        // Background color
        if (!empty($attributes['backgroundColor'])) {
            $classes[] = 'has-' . $attributes['backgroundColor'] . '-background-color';
            $classes[] = 'has-background';
        }

        // Text color
        if (!empty($attributes['textColor'])) {
            $classes[] = 'has-' . $attributes['textColor'] . '-color';
            $classes[] = 'has-text-color';
        }

        // Font size
        if (!empty($attributes['fontSize'])) {
            $classes[] = 'has-' . $attributes['fontSize'] . '-font-size';
        }

        // Style attribute (colors, spacing, typography)
        if (!empty($attributes['style']) && is_array($attributes['style'])) {
            $this->processStyleAttribute($attributes['style'], $classes, $styles);
        }

        // Apply classes
        $classes = array_unique(array_filter($classes));
        if (!empty($classes)) {
            $root->setAttribute('class', implode(' ', $classes));
        }

        // Apply styles
        if (!empty($styles)) {
            $root->setAttribute('style', implode('; ', $styles) . ';');
        }
    }

    /**
     * Process the style attribute
     */
    private function processStyleAttribute(array $style, array &$classes, array &$styles): void
    {
        // Colors
        if (!empty($style['color'])) {
            if (!empty($style['color']['text'])) {
                $color = $style['color']['text'];
                if (preg_match('/^var:preset\|color\|(.+)$/', $color, $matches)) {
                    $classes[] = 'has-' . $matches[1] . '-color';
                } else {
                    $styles[] = 'color: ' . $color;
                }
            }

            if (!empty($style['color']['background'])) {
                $bg = $style['color']['background'];
                if (preg_match('/^var:preset\|color\|(.+)$/', $bg, $matches)) {
                    $classes[] = 'has-' . $matches[1] . '-background-color';
                } else {
                    $styles[] = 'background-color: ' . $bg;
                }
            }
        }

        // Typography
        if (!empty($style['typography'])) {
            if (!empty($style['typography']['fontSize'])) {
                $size = $style['typography']['fontSize'];
                if (preg_match('/^var:preset\|font-size\|(.+)$/', $size, $matches)) {
                    $classes[] = 'has-' . $matches[1] . '-font-size';
                } else {
                    $styles[] = 'font-size: ' . $size;
                }
            }

            if (!empty($style['typography']['lineHeight'])) {
                $styles[] = 'line-height: ' . $style['typography']['lineHeight'];
            }
        }

        // Spacing
        if (!empty($style['spacing'])) {
            $this->processSpacing($style['spacing'], $styles);
        }
    }

    /**
     * Process spacing styles
     */
    private function processSpacing(array $spacing, array &$styles): void
    {
        foreach (['padding', 'margin'] as $property) {
            if (empty($spacing[$property])) {
                continue;
            }

            $value = $spacing[$property];

            if (is_array($value)) {
                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    if (isset($value[$side])) {
                        $val = $value[$side];
                        if (preg_match('/^var:preset\|spacing\|(.+)$/', $val, $matches)) {
                            $styles[] = "{$property}-{$side}: var(--wp--preset--spacing--{$matches[1]})";
                        } else {
                            $styles[] = "{$property}-{$side}: {$val}";
                        }
                    }
                }
            } else {
                if (preg_match('/^var:preset\|spacing\|(.+)$/', $value, $matches)) {
                    $styles[] = "{$property}: var(--wp--preset--spacing--{$matches[1]})";
                } else {
                    $styles[] = "{$property}: {$value}";
                }
            }
        }
    }

    /**
     * Update field values in DOM
     */
    private function updateFields(DOMDocument $dom, array $attributes, array $protoConfig): void
    {
        $xpath = new DOMXPath($dom);

        // Handle repeaters first
        $repeaters = $xpath->query('//*[@proto-repeater or @zen-repeater]');
        foreach ($repeaters as $element) {
            if (!($element instanceof DOMElement)) {
                continue;
            }

            $name = $element->getAttribute('proto-repeater') ?: $element->getAttribute('zen-repeater');
            if (isset($attributes[$name])) {
                $this->fieldRegistry->updateElement('repeater', $element, $attributes[$name], [
                    'fields' => $protoConfig['fields'][$name]['fields'] ?? [],
                ]);
            }
        }

        // Handle regular fields
        $fields = $xpath->query('//*[@proto-edit or @zen-edit]');
        foreach ($fields as $element) {
            if (!($element instanceof DOMElement)) {
                continue;
            }

            // Skip if inside a repeater (already handled)
            if ($this->isInsideRepeater($element)) {
                continue;
            }

            $name = $element->getAttribute('proto-edit') ?: $element->getAttribute('zen-edit');
            $type = $element->getAttribute('proto-type') ?: $element->getAttribute('zen-type') ?: 'text';

            // Special handling for innerblocks
            if ($type === 'innerblocks' && !empty($attributes['innerBlocksContent'])) {
                $this->fieldRegistry->updateElement($type, $element, $attributes['innerBlocksContent']);
            } elseif (isset($attributes[$name])) {
                $this->fieldRegistry->updateElement($type, $element, $attributes[$name]);
            }
        }
    }

    /**
     * Check if element is inside a repeater
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
     * Clean up proto/zen attributes from output
     */
    private function cleanupAttributes(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);
        $elements = $xpath->query('//*[@*[starts-with(name(), "proto-") or starts-with(name(), "zen-")]]');

        foreach ($elements as $element) {
            if (!($element instanceof DOMElement)) {
                continue;
            }

            $toRemove = [];
            foreach ($element->attributes as $attr) {
                if (str_starts_with($attr->name, 'proto-') || str_starts_with($attr->name, 'zen-')) {
                    // Preserve data-wp-* attributes for Interactivity API
                    if (!str_starts_with($attr->name, 'data-wp-')) {
                        $toRemove[] = $attr->name;
                    }
                }
            }

            foreach ($toRemove as $name) {
                $element->removeAttribute($name);
            }
        }
    }
}
