<?php
/**
 * WYSIWYG Field Type
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Fields\Types;

use DOMElement;
use ProtoBlocks\Fields\AbstractField;

/**
 * WYSIWYG field type for full rich text editing
 */
class WysiwygField extends AbstractField
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
            '__protoType' => 'wysiwyg',
        ];
    }

    /**
     * Update element with WYSIWYG content
     */
    public static function updateElement(DOMElement $element, mixed $value, array $config = []): void
    {
        if (!is_string($value)) {
            $value = '';
        }

        // Sanitize and set content
        $sanitizedValue = static::sanitize($value, $config);
        static::setHtmlContent($element, $sanitizedValue);
    }

    /**
     * Extract default value from element
     */
    public static function extractDefault(DOMElement $element): mixed
    {
        return static::getInnerHtml($element);
    }

    /**
     * Sanitize WYSIWYG content
     */
    public static function sanitize(mixed $value, array $config = []): mixed
    {
        if (!is_string($value)) {
            return '';
        }

        // Use wp_kses_post for full HTML support
        return wp_kses_post($value);
    }

    /**
     * Validate WYSIWYG content
     */
    public static function validate(mixed $value, array $config = []): bool|string
    {
        if (!is_string($value)) {
            return 'Content must be a string';
        }

        // Check required
        if (!empty($config['required']) && empty(trim(strip_tags($value)))) {
            return 'This field is required';
        }

        // Check max length (on stripped content)
        if (isset($config['maxLength'])) {
            $textContent = strip_tags($value);
            if (strlen($textContent) > $config['maxLength']) {
                return sprintf('Content must be at most %d characters', $config['maxLength']);
            }
        }

        return true;
    }
}
