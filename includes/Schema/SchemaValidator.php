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
        'inner-blocks',
        'innerblocks', // Legacy alias of 'inner-blocks' (warns, see validateField)
    ];

    /**
     * Legacy field type spellings mapped to their canonical type
     */
    private const LEGACY_FIELD_TYPES = [
        'innerblocks' => 'inner-blocks',
    ];

    /**
     * Known control types
     */
    private const VALID_CONTROL_TYPES = [
        'text',
        'textarea',
        'select',
        'multiselect',
        'toggle',
        'checkbox',
        'range',
        'number',
        'color',
        'color-palette',
        'radio',
        'image',
        'video',
        'gallery',
        'file',
        'repeater',
    ];

    /**
     * Control types a repeater row may contain.
     *
     * A repeater cannot nest: a second level of rows inside a sidebar panel is
     * unreadable, and every case met so far is one level deep. Keeping it flat
     * also keeps the stored value a plain list of flat objects.
     */
    private const VALID_REPEATER_FIELD_TYPES = [
        'text',
        'textarea',
        'select',
        'multiselect',
        'toggle',
        'checkbox',
        'range',
        'number',
        'color',
        'color-palette',
        'radio',
        'image',
        'video',
        'file',
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

        if (is_string($type) && isset(self::LEGACY_FIELD_TYPES[$type])) {
            $this->warnings[] = sprintf(
                'Field "%s" uses legacy type "%s"; use "%s" instead.',
                $name,
                $type,
                self::LEGACY_FIELD_TYPES[$type]
            );
        }

        // Validate repeater fields
        if ($type === 'repeater' && isset($field['fields'])) {
            foreach ($field['fields'] as $subName => $subField) {
                $this->validateField("{$name}.{$subName}", $subField);
            }
        }

        /*
         * A repeater's itemControls are the sidebar half of a row: controls shown
         * for whichever row the author has focused, for the values a template
         * consumes rather than prints and which therefore have no element on the
         * canvas to carry data-proto-field. They are validated as controls, not as
         * fields, because that is what they are.
         */
        if ($type === 'repeater' && isset($field['itemControls'])) {
            if (!is_array($field['itemControls'])) {
                $this->errors[] = sprintf(
                    'Field "%s" has itemControls that is not an object',
                    $name
                );
            } else {
                foreach ($field['itemControls'] as $controlName => $control) {
                    if (!is_array($control)) {
                        $this->errors[] = sprintf(
                            'Field "%s" itemControl "%s" must be an object',
                            $name,
                            (string) $controlName
                        );

                        continue;
                    }

                    $this->validateControl("{$name}.{$controlName}", $control);

                    /* A repeater inside a row's controls would nest a list in a
                       list, which the sidebar cannot lay out and the stored value
                       cannot stay flat through. */
                    if (($control['type'] ?? 'text') === 'repeater') {
                        $this->errors[] = sprintf(
                            'Field "%s" itemControl "%s" is a repeater; rows cannot nest',
                            $name,
                            (string) $controlName
                        );
                    }
                }
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

        // Select and multiselect must have static options OR a dynamic source
        if (
            in_array($type, ['select', 'multiselect'], true)
            && empty($control['options'])
            && empty($control['optionsSource'])
        ) {
            $this->errors[] = sprintf(
                'Control "%s" of type "%s" must have options or an optionsSource defined',
                $name,
                $type
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

        // A repeater's rows are built from its own fields
        if ($type === 'repeater') {
            $this->validateRepeaterControl($name, $control);
        }

        // Validate conditions
        if (isset($control['conditions'])) {
            $this->validateConditions($name, $control['conditions']);
        }
    }

    /**
     * Validate a repeater control's row definition.
     *
     * @param array<string, mixed> $control
     */
    private function validateRepeaterControl(string $name, array $control): void
    {
        $fields = $control['fields'] ?? null;

        if (!is_array($fields) || $fields === []) {
            $this->errors[] = sprintf(
                'Repeater control "%s" must define fields for one row',
                $name
            );

            return;
        }

        foreach ($fields as $key => $field) {
            if (!is_array($field)) {
                $this->errors[] = sprintf(
                    'Repeater control "%s" field "%s" must be an object',
                    $name,
                    (string) $key
                );

                continue;
            }

            $fieldType = $field['type'] ?? 'text';

            if (!in_array($fieldType, self::VALID_REPEATER_FIELD_TYPES, true)) {
                $this->errors[] = sprintf(
                    'Repeater control "%s" field "%s" uses type "%s", which a repeater row cannot hold',
                    $name,
                    (string) $key,
                    (string) $fieldType
                );
            }

            if (
                in_array($fieldType, ['select', 'multiselect'], true)
                && empty($field['options'])
                && empty($field['optionsSource'])
            ) {
                $this->errors[] = sprintf(
                    'Repeater control "%s" field "%s" of type "%s" must have options or an optionsSource defined',
                    $name,
                    (string) $key,
                    (string) $fieldType
                );
            }
        }

        // The row's title in the sidebar comes from one of its own fields.
        if (isset($control['itemLabel']) && !isset($fields[$control['itemLabel']])) {
            $this->warnings[] = sprintf(
                'Repeater control "%s" names "%s" as its itemLabel, which is not one of its fields',
                $name,
                (string) $control['itemLabel']
            );
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
