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
     * Helper: Sanitise a markup string before it is parsed into the output DOM.
     *
     * Field values arrive straight from the block's attribute JSON, which a
     * contributor controls byte for byte (the block serializer writes "<" as
     * "<", so wp_filter_post_kses never sees a tag inside the block
     * comment, and a REST client can write the JSON directly). Nothing on the
     * save path invokes FieldInterface::sanitize(), so the render path cannot
     * assume the value was filtered earlier. Every field that inserts markup
     * must therefore filter it itself, and this is the shared default filter.
     *
     * Subclasses that need a narrower profile (LinkField) apply their own
     * allowlist first; wp_kses_post is a superset, so running it afterwards
     * is a no-op on their output.
     */
    protected static function sanitizeHtmlForOutput(string $html): string
    {
        return wp_kses_post($html);
    }

    /**
     * Helper: Set HTML content on an element
     *
     * @param DOMElement $element Element whose children are replaced.
     * @param string     $html    Value to insert. Plain text (no "<") becomes a
     *                            text node; anything else is parsed as markup.
     * @param bool       $trusted Pass true ONLY for markup that WordPress has
     *                            already rendered (e.g. the inner-blocks
     *                            content handed to render_callback). Default
     *                            false runs sanitizeHtmlForOutput() first, so
     *                            a caller that forgets to filter a raw
     *                            attribute still cannot insert live elements.
     */
    protected static function setHtmlContent(DOMElement $element, string $html, bool $trusted = false): void
    {
        static::clearElement($element);

        if ($html === '') {
            return;
        }

        // Check if content contains HTML
        if (str_contains($html, '<')) {
            if (!$trusted) {
                $html = static::sanitizeHtmlForOutput($html);
            }

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
            // Plain text: a text node is inert by construction (libxml escapes
            // "&" on serialisation), so no filter is applied and "&" survives
            // as a single "&amp;" rather than being double-encoded.
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
