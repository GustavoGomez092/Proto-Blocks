<?php
/**
 * Repeater Field Type
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Fields\Types;

use DOMElement;
use ProtoBlocks\Fields\AbstractField;
use ProtoBlocks\Core\Plugin;

/**
 * Repeater field type for repeatable content groups
 */
class RepeaterField extends AbstractField
{
    protected static array $defaultSchema = [
        'type' => 'array',
        'default' => [],
    ];

    /**
     * Get attribute schema
     */
    public static function getAttributeSchema(mixed $default = null, array $config = []): array
    {
        return [
            'type' => 'array',
            'default' => $default ?? [],
            '__protoType' => 'repeater',
            '__protoFields' => $config['fields'] ?? [],
        ];
    }

    /**
     * Update element with repeater items
     */
    public static function updateElement(DOMElement $element, mixed $value, array $config = []): void
    {
        if (!is_array($value)) {
            $value = [];
        }

        // Get the template (first child element)
        $template = $element->firstElementChild;
        if (!$template) {
            return;
        }

        // Clear all existing children
        static::clearElement($element);

        // Clone and populate for each item
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $clone = $template->cloneNode(true);
            static::processRepeaterItem($clone, $item, $config);
            $element->appendChild($clone);
        }
    }

    /**
     * Process a single repeater item
     */
    private static function processRepeaterItem(DOMElement $element, array $item, array $config): void
    {
        $fieldRegistry = Plugin::getInstance()->getFieldRegistry();

        // Process this element if it has zen-edit
        if ($element->hasAttribute('zen-edit') || $element->hasAttribute('proto-edit')) {
            $fieldName = $element->getAttribute('zen-edit') ?: $element->getAttribute('proto-edit');
            $fieldType = $element->getAttribute('zen-type') ?: $element->getAttribute('proto-type') ?: 'text';

            if (isset($item[$fieldName])) {
                $fieldRegistry->updateElement($fieldType, $element, $item[$fieldName], $config['fields'][$fieldName] ?? []);
            }

            // Clean up attributes
            static::cleanZenAttributes($element);
        }

        // Process child elements recursively
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                static::processRepeaterItem($child, $item, $config);
            }
        }
    }

    /**
     * Extract default value from element
     */
    public static function extractDefault(DOMElement $element): mixed
    {
        return [];
    }

    /**
     * Sanitize repeater value
     */
    public static function sanitize(mixed $value, array $config = []): mixed
    {
        if (!is_array($value)) {
            return [];
        }

        $fieldRegistry = Plugin::getInstance()->getFieldRegistry();
        $fields = $config['fields'] ?? [];
        $sanitized = [];

        foreach ($value as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $sanitizedItem = ['id' => $item['id'] ?? wp_generate_uuid4()];

            foreach ($fields as $fieldName => $fieldConfig) {
                $fieldType = $fieldConfig['type'] ?? 'text';
                $fieldClass = $fieldRegistry->getClass($fieldType);

                if ($fieldClass && isset($item[$fieldName])) {
                    $sanitizedItem[$fieldName] = $fieldClass::sanitize($item[$fieldName], $fieldConfig);
                } elseif (isset($item[$fieldName])) {
                    $sanitizedItem[$fieldName] = $item[$fieldName];
                }
            }

            $sanitized[] = $sanitizedItem;
        }

        return $sanitized;
    }

    /**
     * Validate repeater value
     */
    public static function validate(mixed $value, array $config = []): bool|string
    {
        if (!is_array($value)) {
            return 'Repeater value must be an array';
        }

        // Check min items
        if (isset($config['min']) && count($value) < $config['min']) {
            return sprintf('At least %d items are required', $config['min']);
        }

        // Check max items
        if (isset($config['max']) && count($value) > $config['max']) {
            return sprintf('No more than %d items are allowed', $config['max']);
        }

        // Validate each item
        $fieldRegistry = Plugin::getInstance()->getFieldRegistry();
        $fields = $config['fields'] ?? [];

        foreach ($value as $index => $item) {
            if (!is_array($item)) {
                return sprintf('Item %d must be an object', $index + 1);
            }

            foreach ($fields as $fieldName => $fieldConfig) {
                $fieldType = $fieldConfig['type'] ?? 'text';
                $fieldClass = $fieldRegistry->getClass($fieldType);

                if ($fieldClass && isset($item[$fieldName])) {
                    $result = $fieldClass::validate($item[$fieldName], $fieldConfig);
                    if ($result !== true) {
                        return sprintf('Item %d, field "%s": %s', $index + 1, $fieldName, $result);
                    }
                }
            }
        }

        return true;
    }
}
