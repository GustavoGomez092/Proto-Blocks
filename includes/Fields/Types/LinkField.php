<?php
/**
 * Link Field Type
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Fields\Types;

use DOMElement;
use ProtoBlocks\Fields\AbstractField;

/**
 * Link field type for URL inputs with target/rel attributes
 */
class LinkField extends AbstractField
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
                'url' => '',
                'text' => '',
                'target' => '',
                'rel' => '',
                'title' => '',
            ],
            '__protoType' => 'link',
        ];
    }

    /**
     * Update element with link value
     */
    public static function updateElement(DOMElement $element, mixed $value, array $config = []): void
    {
        if (is_string($value)) {
            // Simple URL string
            $value = ['url' => $value];
        }

        if (!is_array($value)) {
            $value = [];
        }

        $url = $value['url'] ?? '';
        $text = $value['text'] ?? '';
        $target = $value['target'] ?? '';
        $rel = $value['rel'] ?? '';
        $title = $value['title'] ?? '';

        // Set URL
        if (!empty($url)) {
            $element->setAttribute('href', esc_url($url));
        } elseif ($element->hasAttribute('href')) {
            $element->removeAttribute('href');
        }

        // Set target
        if (!empty($target)) {
            $element->setAttribute('target', esc_attr($target));
        } elseif ($element->hasAttribute('target')) {
            $element->removeAttribute('target');
        }

        // Set rel (add noopener for external links)
        if (!empty($rel)) {
            $element->setAttribute('rel', esc_attr($rel));
        } elseif ($target === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        } elseif ($element->hasAttribute('rel')) {
            $element->removeAttribute('rel');
        }

        // Set title
        if (!empty($title)) {
            $element->setAttribute('title', esc_attr($title));
        }

        // Set text content if provided
        if (!empty($text)) {
            static::setHtmlContent($element, $text);
        }
    }

    /**
     * Extract default value from element
     */
    public static function extractDefault(DOMElement $element): mixed
    {
        return [
            'url' => $element->getAttribute('href') ?? '',
            'text' => static::getTextContent($element),
            'target' => $element->getAttribute('target') ?? '',
            'rel' => $element->getAttribute('rel') ?? '',
            'title' => $element->getAttribute('title') ?? '',
        ];
    }

    /**
     * Sanitize link value
     */
    public static function sanitize(mixed $value, array $config = []): mixed
    {
        if (is_string($value)) {
            $value = ['url' => $value];
        }

        if (!is_array($value)) {
            return [
                'url' => '',
                'text' => '',
                'target' => '',
                'rel' => '',
                'title' => '',
            ];
        }

        $sanitized = [
            'url' => isset($value['url']) ? esc_url_raw($value['url']) : '',
            'text' => isset($value['text']) ? wp_kses_post($value['text']) : '',
            'target' => isset($value['target']) ? sanitize_key($value['target']) : '',
            'rel' => isset($value['rel']) ? sanitize_text_field($value['rel']) : '',
            'title' => isset($value['title']) ? sanitize_text_field($value['title']) : '',
        ];

        // Validate target value
        if (!in_array($sanitized['target'], ['', '_self', '_blank', '_parent', '_top'], true)) {
            $sanitized['target'] = '';
        }

        return $sanitized;
    }

    /**
     * Validate link value
     */
    public static function validate(mixed $value, array $config = []): bool|string
    {
        if (!is_array($value) && !is_string($value)) {
            return 'Link value must be an object or string';
        }

        $url = is_string($value) ? $value : ($value['url'] ?? '');

        // Check required
        if (!empty($config['required']) && empty($url)) {
            return 'A URL is required';
        }

        // Validate URL format if provided
        if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL) && !str_starts_with($url, '#') && !str_starts_with($url, '/')) {
            return 'Please enter a valid URL';
        }

        return true;
    }
}
