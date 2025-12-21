<?php
/**
 * Field Interface - Contract for all field types
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Fields;

use DOMElement;

/**
 * Interface that all field types must implement
 */
interface FieldInterface
{
    /**
     * Get the attribute schema for this field type
     *
     * @param mixed $default Default value
     * @param array $config Field configuration
     * @return array WordPress attribute schema
     */
    public static function getAttributeSchema(mixed $default = null, array $config = []): array;

    /**
     * Update a DOM element with the field value
     *
     * @param DOMElement $element The element to update
     * @param mixed $value The field value
     * @param array $config Field configuration
     */
    public static function updateElement(DOMElement $element, mixed $value, array $config = []): void;

    /**
     * Extract the default value from a DOM element
     *
     * @param DOMElement $element The element to extract from
     * @return mixed The extracted default value
     */
    public static function extractDefault(DOMElement $element): mixed;

    /**
     * Sanitize a field value
     *
     * @param mixed $value The value to sanitize
     * @param array $config Field configuration
     * @return mixed The sanitized value
     */
    public static function sanitize(mixed $value, array $config = []): mixed;

    /**
     * Validate a field value
     *
     * @param mixed $value The value to validate
     * @param array $config Field configuration
     * @return bool|string True if valid, error message if not
     */
    public static function validate(mixed $value, array $config = []): bool|string;
}
