<?php
/**
 * Template Renderer - Renders templates with attribute values
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Template;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ProtoBlocks\Fields\Registry as FieldRegistry;
use ProtoBlocks\Controls\Registry as ControlRegistry;
use ProtoBlocks\Tailwind\Manager as TailwindManager;
use ProtoBlocks\Tailwind\Scoper as TailwindScoper;

/**
 * Renders PHP templates with saved attribute values
 */
class Renderer
{
    /**
     * Field registry
     */
    private FieldRegistry $fieldRegistry;

    /**
     * Control registry
     */
    private ControlRegistry $controlRegistry;

    /**
     * Constructor
     */
    public function __construct(FieldRegistry $fieldRegistry, ControlRegistry $controlRegistry)
    {
        $this->fieldRegistry = $fieldRegistry;
        $this->controlRegistry = $controlRegistry;
    }

    /**
     * Render a template with attributes
     *
     * @param string $templatePath Path to template file
     * @param array $attributes Block attributes
     * @param array $metadata Block metadata
     * @return string Rendered HTML
     */
    public function render(string $templatePath, array $attributes, array $metadata = [], ?\WP_Block $block = null): string
    {
        $protoConfig = $metadata['protoBlocks'] ?? [];

        // Process control values
        $processedAttributes = $this->processControlValues($attributes, $protoConfig);

        // Execute template with attributes
        $html = $this->executeTemplate($templatePath, $processedAttributes, $protoConfig, $block);

        // Parse and update DOM with attribute values
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8"?>' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        // The XML declaration prefixed above stops libxml assuming ISO-8859-1,
        // but libxml keeps it as a processing-instruction node in the document.
        // The whole-document saveHTML() below would then serialise it straight
        // back out, putting a stray XML declaration in front of every rendered
        // block on the front end. Drop the node now that it has done its job.
        // Snapshot childNodes first: removing from a live DOMNodeList while
        // iterating it skips siblings.
        //
        // (Note for future editors: do not write a literal PHP close tag in a
        // comment here -- it ends PHP mode even inside a // comment.)
        foreach (iterator_to_array($dom->childNodes) as $node) {
            if (XML_PI_NODE === $node->nodeType) {
                $dom->removeChild($node);
            }
        }

        // Apply WordPress core features (alignment, colors, etc.)
        $this->applyCoreFeatures($dom, $processedAttributes);

        // Update field values
        $this->updateFields($dom, $processedAttributes, $protoConfig);

        // Clean up proto/zen attributes
        $this->cleanupAttributes($dom);

        // Return final HTML
        return self::decodeNonMarkupEntities($dom->saveHTML() ?: '');
    }

    /**
     * Restore libxml's entity-encoded characters without undoing escaping.
     *
     * DOMDocument::saveHTML() serialises every non-ASCII character as an
     * entity (&reg; &mdash; &ldquo; &#9731; ...), in text nodes, attribute
     * values and even inside <script>/<style> raw text, which mangles UTF-8
     * copy and JS string literals. The previous implementation ran the whole
     * document through html_entity_decode(), which also turned the entities
     * libxml uses for markup characters (&lt; &gt; &amp; &quot;) back into
     * raw < > & " -- i.e. it undid every esc_html()/esc_attr() a template had
     * applied and turned escaped visitor or contributor input into live
     * markup (reflected and stored XSS).
     *
     * This decoder walks each entity individually and only restores it when
     * the DECODED character contains none of < > & " '. It tests the decoded
     * value, not the spelling, so &lt; &#60; &#x3C; &#X3C; &#0060; are all
     * refused alike. Because libxml encodes every literal "&" as &amp; and
     * &amp; is refused, decoding can never produce a new "&...;" sequence, so
     * the pass cannot double-decode. Entities html_entity_decode() leaves
     * alone (unknown names, U+0000, C0/C1 controls, surrogates, > U+10FFFF)
     * are passed through untouched for the browser to handle. Multi-codepoint
     * HTML5 entities are checked with strpbrk() over the whole decoded string.
     *
     * Attribute values are always quoted by libxml, so decoding whitespace,
     * backtick or "=" inside one cannot open a new attribute, and the quote
     * characters themselves are in the refused set.
     *
     * Note the one observable spelling change: a template that echoes
     * "&#038;" (esc_html's spelling of "&") now reaches the browser as
     * "&amp;" instead of a bare "&". The DOM is identical.
     */
    private static function decodeNonMarkupEntities(string $html): string
    {
        $decoded = preg_replace_callback(
            '/&(#[0-9]+|#[xX][0-9a-fA-F]+|[A-Za-z][A-Za-z0-9]*);/',
            static function (array $m): string {
                $entity  = $m[0];
                $decoded = html_entity_decode($entity, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                // Unknown or unrepresentable entity: leave it for the browser.
                if ($decoded === $entity) {
                    return $entity;
                }

                // Never restore a character that could open or close markup.
                return false !== strpbrk($decoded, '<>&"\'') ? $entity : $decoded;
            },
            $html
        );

        // On a PCRE failure fall back to libxml's still-encoded output: it is
        // valid HTML that renders correctly, merely with entities not restored.
        return $decoded ?? $html;
    }

    /**
     * Render a preview (keeps proto-* attributes for editor)
     */
    public function renderPreview(string $templatePath, array $attributes, array $metadata = []): string
    {
        $protoConfig = $metadata['protoBlocks'] ?? [];

        // Process control values
        $processedAttributes = $this->processControlValues($attributes, $protoConfig);

        // Execute template
        $html = $this->executeTemplate($templatePath, $processedAttributes, $protoConfig);

        return $html;
    }

    /**
     * Execute the template file
     */
    private function executeTemplate(string $templatePath, array $attributes, array $protoConfig, ?\WP_Block $block = null): string
    {
        if (!file_exists($templatePath)) {
            throw new \RuntimeException(
                sprintf('Template file not found: %s', $templatePath)
            );
        }

        ob_start();

        // Transform attribute keys to PHP-safe variable names
        $variables = $this->transformAttributeKeys($attributes);

        // Extract attribute variables WITHOUT overwriting the method's own
        // parameters that are already in scope ($templatePath, $attributes,
        // $protoConfig, $block). EXTR_SKIP guarantees an attribute key that
        // collides with one of those names can't clobber it.
        extract($variables, EXTR_SKIP);

        // Documented contract: $block is the WP_Block instance on the frontend,
        // null in the editor preview. Templates branch on it via
        //   $is_preview = !isset($block) || $block === null;

        // Create template helper
        $template = new TemplateHelper($attributes, $block);

        include $templatePath;

        return ob_get_clean() ?: '';
    }

    /**
     * Transform attribute keys to PHP-safe variable names
     */
    private function transformAttributeKeys(array $attributes): array
    {
        $transformed = [];

        foreach ($attributes as $key => $value) {
            $newKey = str_replace(['-', ' '], '_', $key);
            $transformed[$newKey] = $value;
        }

        return $transformed;
    }

    /**
     * Process control values
     */
    private function processControlValues(array $attributes, array $protoConfig): array
    {
        $controls = $protoConfig['controls'] ?? [];

        foreach ($controls as $name => $config) {
            $type = $config['type'] ?? 'text';

            // Set default if not present
            if (!isset($attributes[$name]) && isset($config['default'])) {
                $attributes[$name] = $config['default'];
            }

            // Process the value
            if (isset($attributes[$name])) {
                $attributes[$name] = $this->controlRegistry->processValue(
                    $type,
                    $attributes[$name],
                    $config
                );
            }
        }

        return $attributes;
    }

    /**
     * Apply WordPress core block features
     */
    private function applyCoreFeatures(DOMDocument $dom, array $attributes): void
    {
        $root = $dom->documentElement;
        if (!($root instanceof DOMElement)) {
            return;
        }

        // The template's own root class/style are template-authored and are
        // kept verbatim. Everything derived from block attributes below is
        // contributor-controlled (block-comment JSON is never kses'd), so
        // each piece is sanitised before it reaches the wrapper.
        $classes = explode(' ', $root->getAttribute('class') ?: '');
        $styles = [];

        // Parse existing styles
        $existingStyle = $root->getAttribute('style');
        if ($existingStyle) {
            $styles[] = rtrim($existingStyle, ';');
        }

        // Alignment
        if (!empty($attributes['align']) && is_string($attributes['align'])) {
            $classes[] = 'align' . self::sanitizeClassToken($attributes['align']);
        }

        // Custom class. Tokens are not forced through sanitize_html_class():
        // Tailwind utilities (md:grid-cols-2, w-1/2, bg-[#fff]) are a
        // supported use of className and would be destroyed. The DOM already
        // escapes the attribute, so only characters that could never be part
        // of a class name are stripped.
        if (!empty($attributes['className']) && is_string($attributes['className'])) {
            foreach (preg_split('/\s+/', $attributes['className']) ?: [] as $token) {
                $token = preg_replace('/[<>"\'&\x00-\x1F\x7F]/', '', $token);
                if ($token !== '' && $token !== null) {
                    $classes[] = $token;
                }
            }
        }

        // Anchor (HTML id)
        if (!empty($attributes['anchor']) && is_string($attributes['anchor'])) {
            $anchor = preg_replace('/[^A-Za-z0-9_:.\-]/', '', $attributes['anchor']);
            if ($anchor !== '' && $anchor !== null) {
                $root->setAttribute('id', $anchor);
            }
        }

        // Background color
        if (!empty($attributes['backgroundColor']) && is_string($attributes['backgroundColor'])) {
            $classes[] = 'has-' . self::sanitizeClassToken($attributes['backgroundColor']) . '-background-color';
            $classes[] = 'has-background';
        }

        // Text color
        if (!empty($attributes['textColor']) && is_string($attributes['textColor'])) {
            $classes[] = 'has-' . self::sanitizeClassToken($attributes['textColor']) . '-color';
            $classes[] = 'has-text-color';
        }

        // Font size
        if (!empty($attributes['fontSize']) && is_string($attributes['fontSize'])) {
            $classes[] = 'has-' . self::sanitizeClassToken($attributes['fontSize']) . '-font-size';
        }

        // Style attribute (colors, spacing, typography). Declarations built
        // from the attribute are collected separately so they can be run
        // through safecss_filter_attr() -- the same gate core's style engine
        // applies -- without touching the template's own style attribute.
        if (!empty($attributes['style']) && is_array($attributes['style'])) {
            $attributeStyles = [];
            $this->processStyleAttribute($attributes['style'], $classes, $attributeStyles);
            $filtered = self::filterCss(implode('; ', $attributeStyles));
            if ($filtered !== '') {
                // safecss_filter_attr() joins with a bare ';'. Restore the
                // '; ' spacing the previous implementation emitted so rendered
                // markup stays byte-identical on the happy path.
                $styles[] = preg_replace('/;(?![^(]*\))(?=\S)/', '; ', rtrim($filtered, ';'));
            }
        }

        // Add Tailwind scope class if enabled
        if (TailwindManager::getInstance()->isEnabled()) {
            $classes[] = TailwindScoper::SCOPE_CLASS;
        }

        // Apply classes
        $classes = array_unique(array_filter($classes));
        if (!empty($classes)) {
            $root->setAttribute('class', implode(' ', $classes));
        }

        // Apply styles
        if (!empty($styles)) {
            $root->setAttribute('style', implode('; ', $styles) . ';');
        }
    }

    /**
     * A single class token derived from a block attribute (align, preset
     * colour/font-size slugs): sanitize_html_class() when WordPress is
     * loaded, otherwise its equivalent.
     */
    private static function sanitizeClassToken(string $token): string
    {
        if (function_exists('sanitize_html_class')) {
            return (string) sanitize_html_class($token);
        }

        return (string) preg_replace('/[^A-Za-z0-9_-]/', '', $token);
    }

    /**
     * Inline CSS declarations built from block attributes, through
     * safecss_filter_attr() when WordPress is loaded. Without it (unit tests
     * outside WP) declarations are passed through unchanged.
     */
    private static function filterCss(string $css): string
    {
        if ($css === '') {
            return '';
        }

        if (function_exists('safecss_filter_attr')) {
            return (string) safecss_filter_attr($css);
        }

        return $css;
    }

    /**
     * Process the style attribute
     */
    private function processStyleAttribute(array $style, array &$classes, array &$styles): void
    {
        // Colors
        if (!empty($style['color'])) {
            if (!empty($style['color']['text'])) {
                $color = $style['color']['text'];
                if (!is_scalar($color)) {
                    $color = '';
                }
                $color = (string) $color;
                if (preg_match('/^var:preset\|color\|(.+)$/', $color, $matches)) {
                    $classes[] = 'has-' . self::sanitizeClassToken($matches[1]) . '-color';
                } else {
                    $styles[] = 'color: ' . $color;
                }
            }

            if (!empty($style['color']['background'])) {
                $bg = $style['color']['background'];
                if (!is_scalar($bg)) {
                    $bg = '';
                }
                $bg = (string) $bg;
                if (preg_match('/^var:preset\|color\|(.+)$/', $bg, $matches)) {
                    $classes[] = 'has-' . self::sanitizeClassToken($matches[1]) . '-background-color';
                } else {
                    $styles[] = 'background-color: ' . $bg;
                }
            }
        }

        // Typography
        if (!empty($style['typography'])) {
            if (!empty($style['typography']['fontSize'])) {
                $size = $style['typography']['fontSize'];
                if (!is_scalar($size)) {
                    $size = '';
                }
                $size = (string) $size;
                if (preg_match('/^var:preset\|font-size\|(.+)$/', $size, $matches)) {
                    $classes[] = 'has-' . self::sanitizeClassToken($matches[1]) . '-font-size';
                } else {
                    $styles[] = 'font-size: ' . $size;
                }
            }

            if (!empty($style['typography']['lineHeight']) && is_scalar($style['typography']['lineHeight'])) {
                $styles[] = 'line-height: ' . (string) $style['typography']['lineHeight'];
            }
        }

        // Spacing
        if (!empty($style['spacing'])) {
            $this->processSpacing($style['spacing'], $styles);
        }
    }

    /**
     * Process spacing styles
     */
    private function processSpacing(array $spacing, array &$styles): void
    {
        foreach (['padding', 'margin'] as $property) {
            if (empty($spacing[$property])) {
                continue;
            }

            $value = $spacing[$property];

            if (is_array($value)) {
                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                    if (isset($value[$side])) {
                        $val = $value[$side];
                        if (!is_scalar($val)) {
                            continue;
                        }
                        $val = (string) $val;
                        if (preg_match('/^var:preset\|spacing\|(.+)$/', $val, $matches)) {
                            $slug = self::sanitizeClassToken($matches[1]);
                            $styles[] = "{$property}-{$side}: var(--wp--preset--spacing--{$slug})";
                        } else {
                            $styles[] = "{$property}-{$side}: {$val}";
                        }
                    }
                }
            } elseif (is_scalar($value)) {
                $value = (string) $value;
                if (preg_match('/^var:preset\|spacing\|(.+)$/', $value, $matches)) {
                    $slug = self::sanitizeClassToken($matches[1]);
                    $styles[] = "{$property}: var(--wp--preset--spacing--{$slug})";
                } else {
                    $styles[] = "{$property}: {$value}";
                }
            }
        }
    }

    /**
     * Update field values in DOM
     */
    private function updateFields(DOMDocument $dom, array $attributes, array $protoConfig): void
    {
        $xpath = new DOMXPath($dom);

        // Handle repeaters first
        $repeaters = $xpath->query('//*[@proto-repeater or @zen-repeater]');
        foreach ($repeaters as $element) {
            if (!($element instanceof DOMElement)) {
                continue;
            }

            $name = $element->getAttribute('proto-repeater') ?: $element->getAttribute('zen-repeater');
            if (isset($attributes[$name])) {
                // Pass the full field config so RepeaterField can honour
                // block.json `max` when capping the number of rendered rows.
                $fieldConfig = is_array($protoConfig['fields'][$name] ?? null) ? $protoConfig['fields'][$name] : [];
                $this->fieldRegistry->updateElement('repeater', $element, $attributes[$name], $fieldConfig + [
                    'fields' => $fieldConfig['fields'] ?? [],
                ]);
            }
        }

        // Handle regular fields
        $fields = $xpath->query('//*[@proto-edit or @zen-edit]');
        foreach ($fields as $element) {
            if (!($element instanceof DOMElement)) {
                continue;
            }

            // Skip if inside a repeater (already handled)
            if ($this->isInsideRepeater($element)) {
                continue;
            }

            $name = $element->getAttribute('proto-edit') ?: $element->getAttribute('zen-edit');
            $type = $element->getAttribute('proto-type') ?: $element->getAttribute('zen-type') ?: 'text';

            // Special handling for innerblocks
            if (in_array($type, ['inner-blocks', 'innerblocks'], true)) {
                // innerBlocksContent is core-rendered inner block HTML set by
                // Engine::renderBlock, never a stored attribute, so it is
                // trusted. The author-controlled attribute named after the
                // field is deliberately NOT used as a fallback: it would be
                // raw markup from the block comment JSON.
                if (!empty($attributes['innerBlocksContent'])) {
                    $this->fieldRegistry->updateElement($type, $element, $attributes['innerBlocksContent'], [
                        'trustedInnerContent' => true,
                    ]);
                }
            } elseif (isset($attributes[$name])) {
                $this->fieldRegistry->updateElement($type, $element, $attributes[$name]);
            }
        }
    }

    /**
     * Check if element is inside a repeater
     */
    private function isInsideRepeater(DOMElement $element): bool
    {
        $parent = $element->parentNode;

        while ($parent instanceof DOMElement) {
            if ($parent->hasAttribute('proto-repeater') || $parent->hasAttribute('zen-repeater')) {
                return true;
            }
            $parent = $parent->parentNode;
        }

        return false;
    }

    /**
     * Clean up proto/zen attributes from output
     */
    private function cleanupAttributes(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);
        $elements = $xpath->query('//*[@*[starts-with(name(), "proto-") or starts-with(name(), "zen-")]]');

        foreach ($elements as $element) {
            if (!($element instanceof DOMElement)) {
                continue;
            }

            $toRemove = [];
            foreach ($element->attributes as $attr) {
                if (str_starts_with($attr->name, 'proto-') || str_starts_with($attr->name, 'zen-')) {
                    // Preserve data-wp-* attributes for Interactivity API
                    if (!str_starts_with($attr->name, 'data-wp-')) {
                        $toRemove[] = $attr->name;
                    }
                }
            }

            foreach ($toRemove as $name) {
                $element->removeAttribute($name);
            }
        }
    }
}
