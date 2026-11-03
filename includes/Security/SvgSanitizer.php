<?php
/**
 * SVG Sanitizer - server-side counterpart of src/editor/utils/sanitize-svg.ts
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Security;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Sanitises an SVG document read from a theme file.
 *
 * A DOM allowlist rather than wp_kses (kses does not understand SVG
 * namespaces). The rule set mirrors the client sanitiser: non-graphic and
 * scripting elements are removed with their content, `on*` handlers are
 * dropped, SMIL animations that retarget a URL are dropped, and URL
 * attributes are checked. It is deliberately stricter than the client in two
 * places because the output is inlined as a menu icon and never needs them:
 * href / src may only be a same-document fragment (`#id`), and any `url(...)`
 * that is not a fragment is treated as an external reference and dropped.
 */
final class SvgSanitizer
{
    public const MAX_BYTES = 32768;

    private const SVG_NS = 'http://www.w3.org/2000/svg';

    /** Elements removed together with their content. */
    private const DROP_ELEMENTS = [
        'script', 'foreignobject', 'iframe', 'object', 'embed', 'audio', 'video',
        'canvas', 'template', 'noscript', 'math', 'link', 'meta', 'base', 'form',
        'input', 'textarea', 'button', 'select', 'handler', 'listener',
    ];

    /** SMIL elements that can rewrite an attribute (and so a URL) at runtime. */
    private const ANIMATION_ELEMENTS = ['animate', 'set', 'animatemotion', 'animatetransform'];

    private const UNSAFE_CSS = '/(expression\s*\(|javascript:|vbscript:|@import|behavior\s*:|-moz-binding|\\\\|url\s*\(\s*[\'"]?\s*+(?!#))/i';

    /**
     * @return string|null Sanitised `<svg>` markup, or null when the input is
     *                     too large, carries a DOCTYPE/ENTITY, is not
     *                     well-formed XML or does not have an SVG root.
     */
    public static function sanitize(string $svg): ?string
    {
        if ($svg === '' || strlen($svg) > self::MAX_BYTES) {
            return null;
        }
        // Reject DTDs outright: no entity expansion, no external subsets.
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $svg) === 1) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $doc      = new DOMDocument();
        $loaded   = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $doc->doctype !== null) {
            return null;
        }
        $root = $doc->documentElement;
        if (!$root instanceof DOMElement || $root->localName !== 'svg' || $root->namespaceURI !== self::SVG_NS) {
            return null;
        }

        self::scrub($root);

        $out = $doc->saveXML($root);
        return is_string($out) && $out !== '' ? $out : null;
    }

    private static function scrub(DOMElement $element): void
    {
        $remove = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if (self::shouldDrop($child)) {
                    $remove[] = $child;
                } else {
                    self::scrub($child);
                }
            } elseif ($child->nodeType !== XML_TEXT_NODE && $child->nodeType !== XML_CDATA_SECTION_NODE) {
                // Comments, processing instructions, entity references.
                $remove[] = $child;
            }
        }
        foreach ($remove as $node) {
            $element->removeChild($node);
        }

        self::scrubAttributes($element);
    }

    private static function shouldDrop(DOMElement $element): bool
    {
        $tag = strtolower($element->localName);
        if (in_array($tag, self::DROP_ELEMENTS, true)) {
            return true;
        }
        if (in_array($tag, self::ANIMATION_ELEMENTS, true)) {
            $target = strtolower($element->getAttribute('attributeName'));
            if (str_contains($target, 'href') || str_starts_with($target, 'on') || str_contains($target, 'src')) {
                return true;
            }
        }
        if ($tag === 'style') {
            return preg_match(self::UNSAFE_CSS, $element->textContent) === 1;
        }
        return false;
    }

    private static function scrubAttributes(DOMElement $element): void
    {
        $drop = [];
        foreach ($element->attributes as $attr) {
            $name  = strtolower($attr->nodeName);
            $local = strtolower($attr->localName ?? $attr->nodeName);
            $value = $attr->value;

            if (str_starts_with($local, 'on') || $local === 'base' && $attr->prefix === 'xml') {
                $drop[] = $attr;
            } elseif (($local === 'href' || $local === 'src') && !self::isFragment($value)) {
                $drop[] = $attr;
            } elseif ($name !== 'xmlns' && !str_starts_with($name, 'xmlns:') && preg_match(self::UNSAFE_CSS, $value) === 1) {
                // style="..." and presentation attributes such as fill="url(...)".
                $drop[] = $attr;
            }
        }
        foreach ($drop as $attr) {
            $element->removeAttributeNode($attr);
        }
    }

    private static function isFragment(string $value): bool
    {
        $cleaned = preg_replace('/[\x00-\x20\x7f-\x9f]/u', '', $value);
        return is_string($cleaned) && str_starts_with($cleaned, '#');
    }
}
