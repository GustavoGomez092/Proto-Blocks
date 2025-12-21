<?php
/**
 * Attribute Generator - Generates WordPress block attributes from schema
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Schema;

use ProtoBlocks\Fields\Registry as FieldRegistry;
use ProtoBlocks\Controls\Registry as ControlRegistry;

/**
 * Generates WordPress block attribute definitions from Proto-Blocks schema
 */
class AttributeGenerator
{
    /**
     * Field type registry
     */
    private FieldRegistry $fieldRegistry;

    /**
     * Control type registry
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
     * Generate attributes from a schema
     *
     * @param array $schema The block schema
     * @return array WordPress-compatible attributes array
     */
    public function generate(array $schema): array
    {
        $attributes = [];

        $protoBlocks = $schema['protoBlocks'] ?? [];

        // Generate attributes from fields
        $fields = $protoBlocks['fields'] ?? [];
        foreach ($fields as $name => $fieldConfig) {
            $attributes[$name] = $this->generateFieldAttribute($name, $fieldConfig);
        }

        // Generate attributes from controls
        $controls = $protoBlocks['controls'] ?? [];
        foreach ($controls as $name => $controlConfig) {
            // Don't override if field already defined this attribute
            if (!isset($attributes[$name])) {
                $attributes[$name] = $this->generateControlAttribute($name, $controlConfig);
            }
        }

        // Add core WordPress block attributes
        $attributes = $this->addCoreAttributes($attributes, $schema['supports'] ?? []);

        return $attributes;
    }

    /**
     * Generate a single field attribute
     */
    private function generateFieldAttribute(string $name, array $config): array
    {
        $type = $config['type'] ?? 'text';
        $fieldType = $this->fieldRegistry->get($type);

        if ($fieldType) {
            $baseSchema = $fieldType['attribute_schema'] ?? ['type' => 'string'];
        } else {
            $baseSchema = ['type' => 'string'];
        }

        // Get default value
        $default = $config['default'] ?? $baseSchema['default'] ?? null;

        $attribute = [
            'type' => $baseSchema['type'],
        ];

        // Only set default if not null
        if ($default !== null) {
            $attribute['default'] = $default;
        }

        // For repeaters, include nested field definitions
        if ($type === 'repeater' && isset($config['fields'])) {
            $attribute['items'] = ['type' => 'object'];
        }

        // Add type info for JavaScript
        $attribute['__protoType'] = $type;
        $attribute['__protoConfig'] = $config;

        return $attribute;
    }

    /**
     * Generate a single control attribute
     */
    private function generateControlAttribute(string $name, array $config): array
    {
        $type = $config['type'] ?? 'text';
        $controlType = $this->controlRegistry->get($type);

        $dataType = $controlType['data_type'] ?? 'string';
        $defaultValue = $config['default'] ?? $controlType['default'] ?? null;

        $attribute = [
            'type' => $this->mapDataType($dataType),
        ];

        if ($defaultValue !== null) {
            $attribute['default'] = $defaultValue;
        }

        // Add control info for JavaScript
        $attribute['__protoControl'] = true;
        $attribute['__protoControlConfig'] = $config;

        return $attribute;
    }

    /**
     * Add core WordPress block attributes based on supports
     */
    private function addCoreAttributes(array $attributes, array $supports): array
    {
        // Alignment
        if (!empty($supports['align']) || !empty($supports['alignWide'])) {
            $attributes['align'] = [
                'type' => 'string',
                'default' => $supports['defaultAlign'] ?? null,
            ];
        }

        // Anchor
        if (!empty($supports['anchor'])) {
            $attributes['anchor'] = [
                'type' => 'string',
            ];
        }

        // Custom class name
        if (!empty($supports['className']) || !empty($supports['customClassName'])) {
            $attributes['className'] = [
                'type' => 'string',
            ];
        }

        // Color support generates multiple attributes
        if (isset($supports['color'])) {
            $color = $supports['color'];

            if (!empty($color['text']) || $color === true) {
                $attributes['textColor'] = ['type' => 'string'];
            }

            if (!empty($color['background']) || $color === true) {
                $attributes['backgroundColor'] = ['type' => 'string'];
            }

            if (!empty($color['gradient'])) {
                $attributes['gradient'] = ['type' => 'string'];
            }
        }

        // Typography
        if (isset($supports['typography'])) {
            $typography = $supports['typography'];

            if (!empty($typography['fontSize'])) {
                $attributes['fontSize'] = ['type' => 'string'];
            }

            if (!empty($typography['fontFamily'])) {
                $attributes['fontFamily'] = ['type' => 'string'];
            }
        }

        // Style attribute (for all style-related features)
        $attributes['style'] = [
            'type' => 'object',
        ];

        // InnerBlocks content (always included for dynamic blocks)
        $attributes['innerBlocksContent'] = [
            'type' => 'string',
            'default' => '',
        ];

        return $attributes;
    }

    /**
     * Map Proto-Blocks data types to WordPress attribute types
     */
    private function mapDataType(string $dataType): string
    {
        return match ($dataType) {
            'string' => 'string',
            'number', 'integer', 'float' => 'number',
            'boolean', 'bool' => 'boolean',
            'array' => 'array',
            'object' => 'object',
            default => 'string',
        };
    }

    /**
     * Extract dependencies for hybrid rendering
     *
     * Analyzes which attributes affect which parts of the template
     *
     * @param array $schema Block schema
     * @return array Dependencies map
     */
    public function extractDependencies(array $schema): array
    {
        $dependencies = [];
        $protoBlocks = $schema['protoBlocks'] ?? [];
        $controls = $protoBlocks['controls'] ?? [];

        // Fields are generally client-renderable unless they affect layout
        foreach ($protoBlocks['fields'] ?? [] as $name => $config) {
            $dependencies[$name] = [
                'type' => 'field',
                'clientRenderable' => !$this->fieldAffectsLayout($name, $controls),
            ];
        }

        // Controls that "affect" fields require server render
        foreach ($controls as $name => $config) {
            $affects = $config['affects'] ?? [];
            $dependencies[$name] = [
                'type' => 'control',
                'clientRenderable' => false,
                'affects' => $affects,
                'requiresServerRender' => !empty($affects) || $this->controlAffectsTemplate($name, $protoBlocks),
            ];
        }

        return $dependencies;
    }

    /**
     * Check if a field affects layout (is used in conditionals)
     */
    private function fieldAffectsLayout(string $fieldName, array $controls): bool
    {
        foreach ($controls as $control) {
            $affects = $control['affects'] ?? [];
            if (in_array($fieldName, $affects, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a control is used in template conditionals
     */
    private function controlAffectsTemplate(string $controlName, array $protoBlocks): bool
    {
        // If the control affects any fields, it affects the template
        $controls = $protoBlocks['controls'] ?? [];
        $control = $controls[$controlName] ?? [];

        return !empty($control['affects']);
    }
}
