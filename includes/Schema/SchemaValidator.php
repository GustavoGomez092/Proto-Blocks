<?php
/**
 * Schema Validator - Validates block.json schemas
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Schema;

/**
 * Validates block.json schemas for Proto-Blocks
 */
class SchemaValidator
{
    /**
     * Known field types
     */
    private const VALID_FIELD_TYPES = [
        'text',
        'image',
        'link',
        'wysiwyg',
        'repeater',
        'innerblocks',
    ];

    /**
     * Known control types
     */
    private const VALID_CONTROL_TYPES = [
        'text',
        'select',
        'toggle',
        'range',
        'number',
        'color',
        'image',
    ];

    /**
     * Validation errors
     *
     * @var array<string>
     */
    private array $errors = [];

    /**
     * Validation warnings
     *
     * @var array<string>
     */
    private array $warnings = [];

    /**
     * Validate a schema
     *
     * @param array $schema The schema to validate
     * @param string $path Path to the schema file (for error messages)
     * @return bool Whether the schema is valid
     * @throws \InvalidArgumentException If schema has critical errors
     */
    public function validate(array $schema, string $path = ''): bool
    {
        $this->errors = [];
        $this->warnings = [];

        // Required: name
        if (empty($schema['name'])) {
            $this->errors[] = 'Block "name" is required';
        } elseif (!preg_match('/^[a-z0-9-]+\/[a-z0-9-]+$/', $schema['name'])) {
            $this->warnings[] = 'Block "name" should follow namespace/block-name format';
        }

        // Validate protoBlocks section
        $this->validateProtoBlocks($schema['protoBlocks'] ?? []);

        // Validate supports
        $this->validateSupports($schema['supports'] ?? []);

        // Throw if critical errors
        if (!empty($this->errors)) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Invalid block schema%s:\n- %s",
                    $path ? " ({$path})" : '',
                    implode("\n- ", $this->errors)
                )
            );
        }

        return true;
    }

    /**
     * Validate the protoBlocks section
     */
    private function validateProtoBlocks(array $config): void
    {
        // Validate fields
        if (isset($config['fields'])) {
            foreach ($config['fields'] as $name => $field) {
                $this->validateField($name, $field);
            }
        }

        // Validate controls
        if (isset($config['controls'])) {
            foreach ($config['controls'] as $name => $control) {
                $this->validateControl($name, $control);
            }
        }

        // Validate template exists (if specified)
        if (isset($config['templatePath']) && !file_exists($config['templatePath'])) {
            $this->warnings[] = sprintf(
                'Template file not found: %s',
                $config['templatePath']
            );
        }
    }

    /**
     * Validate a field definition
     */
    private function validateField(string $name, mixed $field): void
    {
        // Convert simple string format
        if (is_string($field)) {
            $field = ['type' => $field];
        }

        if (!is_array($field)) {
            $this->errors[] = sprintf('Field "%s" must be an array or string', $name);
            return;
        }

        $type = $field['type'] ?? 'text';

        // Check if type is registered (warning only, as extensions can add types)
        if (!in_array($type, self::VALID_FIELD_TYPES, true)) {
            $this->warnings[] = sprintf(
                'Field "%s" uses unknown type "%s". Make sure it\'s registered.',
                $name,
                $type
            );
        }

        // Validate repeater fields
        if ($type === 'repeater' && isset($field['fields'])) {
            foreach ($field['fields'] as $subName => $subField) {
                $this->validateField("{$name}.{$subName}", $subField);
            }
        }

        // Validate field name format
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            $this->warnings[] = sprintf(
                'Field name "%s" should use only letters, numbers, and underscores',
                $name
            );
        }
    }

    /**
     * Validate a control definition
     */
    private function validateControl(string $name, array $control): void
    {
        $type = $control['type'] ?? 'text';

        // Check if type is valid
        if (!in_array($type, self::VALID_CONTROL_TYPES, true)) {
            $this->warnings[] = sprintf(
                'Control "%s" uses unknown type "%s"',
                $name,
                $type
            );
        }

        // Select controls must have options
        if ($type === 'select' && empty($control['options'])) {
            $this->errors[] = sprintf(
                'Select control "%s" must have options defined',
                $name
            );
        }

        // Range controls should have min/max
        if ($type === 'range') {
            if (!isset($control['min']) || !isset($control['max'])) {
                $this->warnings[] = sprintf(
                    'Range control "%s" should define min and max values',
                    $name
                );
            }
        }

        // Validate conditions
        if (isset($control['conditions'])) {
            $this->validateConditions($name, $control['conditions']);
        }
    }

    /**
     * Validate control conditions
     */
    private function validateConditions(string $name, array $conditions): void
    {
        $validConditionTypes = ['visible', 'enabled'];

        foreach ($conditions as $conditionType => $rules) {
            if (!in_array($conditionType, $validConditionTypes, true)) {
                $this->warnings[] = sprintf(
                    'Control "%s" uses unknown condition type "%s"',
                    $name,
                    $conditionType
                );
            }
        }
    }

    /**
     * Validate supports section
     */
    private function validateSupports(array $supports): void
    {
        $validSupports = [
            'html',
            'align',
            'alignWide',
            'anchor',
            'className',
            'customClassName',
            'color',
            'typography',
            'spacing',
            'dimensions',
            'inserter',
            'multiple',
            'reusable',
            'lock',
        ];

        foreach (array_keys($supports) as $support) {
            if (!in_array($support, $validSupports, true)) {
                $this->warnings[] = sprintf(
                    'Unknown support option: %s',
                    $support
                );
            }
        }
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get validation warnings
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Check if validation passed with no warnings
     */
    public function isClean(): bool
    {
        return empty($this->errors) && empty($this->warnings);
    }
}
