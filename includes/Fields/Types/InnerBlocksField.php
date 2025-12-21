<?php
/**
 * InnerBlocks Field Type
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Fields\Types;

use DOMElement;
use DOMDocument;
use ProtoBlocks\Fields\AbstractField;

/**
 * InnerBlocks field type for nested Gutenberg blocks
 */
class InnerBlocksField extends AbstractField
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
            '__protoType' => 'innerblocks',
            '__protoAllowedBlocks' => $config['allowedBlocks'] ?? [],
            '__protoTemplate' => $config['template'] ?? [],
            '__protoTemplateLock' => $config['templateLock'] ?? false,
        ];
    }

    /**
     * Update element with InnerBlocks content
     */
    public static function updateElement(DOMElement $element, mixed $value, array $config = []): void
    {
        if (!is_string($value) || empty($value)) {
            // Keep default content if no inner blocks
            return;
        }

        // Clear existing content
        static::clearElement($element);

        // Parse and import the inner blocks HTML
        $tempDoc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $tempDoc->loadHTML(
            '<?xml encoding="UTF-8"?><div>' . $value . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $contentNode = $tempDoc->getElementsByTagName('div')->item(0);
        if ($contentNode && $contentNode->hasChildNodes()) {
            foreach ($contentNode->childNodes as $child) {
                $importedNode = $element->ownerDocument->importNode($child, true);
                $element->appendChild($importedNode);
            }
        }
    }

    /**
     * Extract default value from element
     */
    public static function extractDefault(DOMElement $element): mixed
    {
        return static::getInnerHtml($element);
    }

    /**
     * Sanitize InnerBlocks content
     */
    public static function sanitize(mixed $value, array $config = []): mixed
    {
        if (!is_string($value)) {
            return '';
        }

        // InnerBlocks content is already sanitized by WordPress
        // We just need to ensure it's a string
        return $value;
    }

    /**
     * Validate InnerBlocks content
     */
    public static function validate(mixed $value, array $config = []): bool|string
    {
        if (!is_string($value)) {
            return 'InnerBlocks content must be a string';
        }

        // Check required
        if (!empty($config['required']) && empty(trim(strip_tags($value)))) {
            return 'Content is required';
        }

        return true;
    }
}
