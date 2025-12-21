<?php
/**
 * Abstract Field - Base class for all field types
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Fields;

use DOMElement;
use DOMDocument;

/**
 * Abstract base class for field type implementations
 */
abstract class AbstractField implements FieldInterface
{
    /**
     * Default attribute schema
     */
    protected static array $defaultSchema = [
        'type' => 'string',
        'default' => '',
    ];

    /**
     * Get the attribute schema for this field type
     */
    public static function getAttributeSchema(mixed $default = null, array $config = []): array
    {
        $schema = static::$defaultSchema;

        if ($default !== null) {
            $schema['default'] = $default;
        }

        return $schema;
    }

    /**
     * Default sanitization (wp_kses_post for strings)
     */
    public static function sanitize(mixed $value, array $config = []): mixed
    {
        if (is_string($value)) {
            return wp_kses_post($value);
        }

        return $value;
    }

    /**
     * Default validation (always valid)
     */
    public static function validate(mixed $value, array $config = []): bool|string
    {
        return true;
    }

    /**
     * Helper: Clear all child nodes from an element
     */
    protected static function clearElement(DOMElement $element): void
    {
        while ($element->firstChild) {
            $element->removeChild($element->firstChild);
        }
    }

    /**
     * Helper: Set HTML content on an element
     */
    protected static function setHtmlContent(DOMElement $element, string $html): void
    {
        static::clearElement($element);

        if (empty($html)) {
            return;
        }

        // Check if content contains HTML
        if (str_contains($html, '<')) {
            try {
                $tempDoc = new DOMDocument('1.0', 'UTF-8');
                libxml_use_internal_errors(true);
                $tempDoc->loadHTML(
                    '<?xml encoding="UTF-8"?><div>' . $html . '</div>',
                    LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
                );
                libxml_clear_errors();

                $contentNode = $tempDoc->getElementsByTagName('div')->item(0);
                if ($contentNode && $contentNode->hasChildNodes()) {
                    foreach ($contentNode->childNodes as $childNode) {
                        $importedNode = $element->ownerDocument->importNode($childNode, true);
                        $element->appendChild($importedNode);
                    }
                }
            } catch (\Exception $e) {
                // Fallback to text node
                $textNode = $element->ownerDocument->createTextNode($html);
                $element->appendChild($textNode);
            }
        } else {
            // Plain text
            $textNode = $element->ownerDocument->createTextNode($html);
            $element->appendChild($textNode);
        }
    }

    /**
     * Helper: Get text content from element
     */
    protected static function getTextContent(DOMElement $element): string
    {
        return trim($element->textContent ?? '');
    }

    /**
     * Helper: Get inner HTML from element
     */
    protected static function getInnerHtml(DOMElement $element): string
    {
        $html = '';
        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument->saveHTML($child);
        }
        return trim($html);
    }

    /**
     * Helper: Remove zen-* attributes from element
     */
    protected static function cleanZenAttributes(DOMElement $element): void
    {
        $toRemove = [];

        foreach ($element->attributes as $attr) {
            if (str_starts_with($attr->name, 'zen-') || str_starts_with($attr->name, 'proto-')) {
                $toRemove[] = $attr->name;
            }
        }

        foreach ($toRemove as $name) {
            $element->removeAttribute($name);
        }
    }
}
