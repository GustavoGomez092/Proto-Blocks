<?php
/**
 * Video Field Type
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Fields\Types;

use DOMElement;
use ProtoBlocks\Fields\AbstractField;

/**
 * Video field type for selecting a self-hosted video from the media library.
 *
 * Stores the attachment id, file URL, and MIME type. Mirrors the image field
 * but opens the media library filtered to video and renders to a `src`
 * attribute (suitable for <video>, <source>, or a link).
 */
class VideoField extends AbstractField
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
                'mime' => '',
            ],
            '__protoType' => 'video',
        ];
    }

    /**
     * Update element with the video value (sets `src`).
     */
    public static function updateElement(DOMElement $element, mixed $value, array $config = []): void
    {
        if (!is_array($value)) {
            $value = [];
        }

        $url = $value['url'] ?? '';

        if (!empty($url)) {
            $element->setAttribute('src', esc_url($url));
            if (!empty($value['mime']) && strtolower($element->nodeName) === 'source') {
                $element->setAttribute('type', $value['mime']);
            }
        } else {
            // Remove the element if no video is set and there's no default.
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
            'mime' => $element->getAttribute('type') ?? '',
        ];
    }

    /**
     * Sanitize video value
     */
    public static function sanitize(mixed $value, array $config = []): mixed
    {
        if (!is_array($value)) {
            return [
                'id' => null,
                'url' => '',
                'mime' => '',
            ];
        }

        return [
            'id' => isset($value['id']) ? absint($value['id']) : null,
            'url' => isset($value['url']) ? esc_url_raw($value['url']) : '',
            'mime' => isset($value['mime']) ? sanitize_text_field($value['mime']) : '',
        ];
    }

    /**
     * Validate video value
     */
    public static function validate(mixed $value, array $config = []): bool|string
    {
        if (!is_array($value)) {
            return 'Video value must be an object';
        }

        if (!empty($config['required']) && empty($value['url']) && empty($value['id'])) {
            return 'A video is required';
        }

        if (!empty($value['id'])) {
            $attachment = get_post($value['id']);
            if (!$attachment || $attachment->post_type !== 'attachment') {
                return 'Invalid attachment ID';
            }
        }

        return true;
    }
}
