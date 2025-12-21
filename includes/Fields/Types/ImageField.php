<?php
/**
 * Image Field Type
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Fields\Types;

use DOMElement;
use ProtoBlocks\Fields\AbstractField;

/**
 * Image field type for media library integration
 */
class ImageField extends AbstractField
{
    protected static array $defaultSchema = [
        'type' => 'object',
        'default' => [],
    ];

    /**
     * Get attribute schema
     */
    public static function getAttributeSchema(mixed $default = null, array $config = []): array
    {
        return [
            'type' => 'object',
            'default' => $default ?? [
                'id' => null,
                'url' => '',
                'alt' => '',
                'caption' => '',
                'size' => 'full',
            ],
            '__protoType' => 'image',
        ];
    }

    /**
     * Update element with image value
     */
    public static function updateElement(DOMElement $element, mixed $value, array $config = []): void
    {
        if (!is_array($value)) {
            $value = [];
        }

        $url = $value['url'] ?? '';
        $alt = $value['alt'] ?? '';
        $id = $value['id'] ?? null;
        $size = $value['size'] ?? $config['defaultSize'] ?? 'full';

        // If we have an attachment ID and size, get the proper URL
        if ($id && $size !== 'full') {
            $imageData = wp_get_attachment_image_src((int) $id, $size);
            if ($imageData) {
                $url = $imageData[0];
            }
        }

        if (!empty($url)) {
            $element->setAttribute('src', esc_url($url));
            $element->setAttribute('alt', esc_attr($alt));

            // Add srcset if we have the attachment ID
            if ($id) {
                $srcset = wp_get_attachment_image_srcset((int) $id, $size);
                $sizes = wp_get_attachment_image_sizes((int) $id, $size);

                if ($srcset) {
                    $element->setAttribute('srcset', $srcset);
                }
                if ($sizes) {
                    $element->setAttribute('sizes', $sizes);
                }
            }
        } else {
            // Remove the element if no image is set and there's no default
            if (!$element->hasAttribute('src') || empty($element->getAttribute('src'))) {
                $parent = $element->parentNode;
                if ($parent) {
                    $parent->removeChild($element);
                }
            }
        }
    }

    /**
     * Extract default value from element
     */
    public static function extractDefault(DOMElement $element): mixed
    {
        return [
            'id' => null,
            'url' => $element->getAttribute('src') ?? '',
            'alt' => $element->getAttribute('alt') ?? '',
            'caption' => $element->getAttribute('data-caption') ?? '',
            'size' => $element->getAttribute('data-size') ?? 'full',
        ];
    }

    /**
     * Sanitize image value
     */
    public static function sanitize(mixed $value, array $config = []): mixed
    {
        if (!is_array($value)) {
            return [
                'id' => null,
                'url' => '',
                'alt' => '',
                'caption' => '',
                'size' => 'full',
            ];
        }

        return [
            'id' => isset($value['id']) ? absint($value['id']) : null,
            'url' => isset($value['url']) ? esc_url_raw($value['url']) : '',
            'alt' => isset($value['alt']) ? sanitize_text_field($value['alt']) : '',
            'caption' => isset($value['caption']) ? wp_kses_post($value['caption']) : '',
            'size' => isset($value['size']) ? sanitize_key($value['size']) : 'full',
        ];
    }

    /**
     * Validate image value
     */
    public static function validate(mixed $value, array $config = []): bool|string
    {
        if (!is_array($value)) {
            return 'Image value must be an object';
        }

        // Check required
        if (!empty($config['required']) && empty($value['url']) && empty($value['id'])) {
            return 'An image is required';
        }

        // Validate attachment ID if provided
        if (!empty($value['id'])) {
            $attachment = get_post($value['id']);
            if (!$attachment || $attachment->post_type !== 'attachment') {
                return 'Invalid attachment ID';
            }
        }

        return true;
    }
}
