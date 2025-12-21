<?php
/**
 * Text Field Type
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Fields\Types;

use DOMElement;
use ProtoBlocks\Fields\AbstractField;

/**
 * Text field type for plain and rich text content
 */
class TextField extends AbstractField
{
    protected static array $defaultSchema = [
        'type' => 'string',
        'default' => '',
    ];

    /**
     * Get attribute schema
     */
    public static function getAttributeSchema(mixed $default = null, array $config = []): array
    {
        return [
            'type' => 'string',
            'default' => $default ?? '',
            '__protoType' => 'text',
            '__protoTagName' => $config['tagName'] ?? 'div',
        ];
    }

    /**
     * Update element with text value
     */
    public static function updateElement(DOMElement $element, mixed $value, array $config = []): void
    {
        if (!is_string($value)) {
            $value = '';
        }

        // Sanitize the value
        $sanitizedValue = static::sanitize($value, $config);

        // Set the content
        static::setHtmlContent($element, $sanitizedValue);
    }

    /**
     * Extract default value from element
     */
    public static function extractDefault(DOMElement $element): mixed
    {
        return static::getTextContent($element);
    }

    /**
     * Sanitize text value
     */
    public static function sanitize(mixed $value, array $config = []): mixed
    {
        if (!is_string($value)) {
            return '';
        }

        // Allow basic formatting HTML
        return wp_kses_post($value);
    }

    /**
     * Validate text value
     */
    public static function validate(mixed $value, array $config = []): bool|string
    {
        if (!is_string($value)) {
            return 'Value must be a string';
        }

        // Check max length if specified
        if (isset($config['maxLength']) && strlen($value) > $config['maxLength']) {
            return sprintf('Value must be at most %d characters', $config['maxLength']);
        }

        // Check required
        if (!empty($config['required']) && empty(trim($value))) {
            return 'This field is required';
        }

        return true;
    }
}
