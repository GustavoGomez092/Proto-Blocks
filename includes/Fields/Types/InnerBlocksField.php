<?php
/**
 * InnerBlocks Field Type
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Fields\Types;

use DOMElement;
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
            '__protoType' => 'inner-blocks',
            '__protoAllowedBlocks' => $config['allowedBlocks'] ?? [],
            '__protoTemplate' => $config['template'] ?? [],
            '__protoTemplateLock' => $config['templateLock'] ?? false,
        ];
    }

    /**
     * Update element with InnerBlocks content
     *
     * Two very different strings reach this method under the same type:
     *
     *  - the inner-block HTML WordPress rendered for render_callback (trusted:
     *    core already ran every nested block's save/render path), and
     *  - the block's own string attribute named after the field, which the
     *    Renderer falls back to when the block has no children. That is raw
     *    attribute JSON a contributor controls byte for byte.
     *
     * The field cannot tell them apart by content, so it filters with
     * wp_kses_post unless the caller vouches for the markup by passing
     * `['trustedInnerContent' => true]` in $config. Secure by default: a caller
     * that passes nothing gets the filtered path.
     */
    public static function updateElement(DOMElement $element, mixed $value, array $config = []): void
    {
        if (!is_string($value) || $value === '') {
            // Keep default content if no inner blocks
            return;
        }

        // The helper filters with wp_kses_post on the markup path unless the
        // caller vouches for the content (see sanitize() for the contract).
        static::setHtmlContent($element, $value, !empty($config['trustedInnerContent']));
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
     *
     * The declared attribute is a plain string that WordPress's schema check
     * accepts verbatim, so it has NOT been sanitised by anything upstream.
     */
    public static function sanitize(mixed $value, array $config = []): mixed
    {
        if (!is_string($value)) {
            return '';
        }

        return wp_kses_post($value);
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
