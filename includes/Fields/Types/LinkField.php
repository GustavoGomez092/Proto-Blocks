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
     * Values the `target` attribute may take; anything else is dropped.
     */
    private const ALLOWED_TARGETS = ['_self', '_blank', '_parent', '_top'];

    /**
     * Inline-only kses profile for the link text.
     *
     * Link text is phrasing content, so block-level tags and anything that can
     * carry a script (img/svg/iframe/style...) have no business here. This is
     * deliberately narrower than wp_kses_post.
     */
    private const TEXT_ALLOWED_HTML = [
        'a'      => ['href' => true, 'title' => true, 'target' => true, 'rel' => true, 'class' => true],
        'strong' => ['class' => true],
        'em'     => ['class' => true],
        'b'      => ['class' => true],
        'i'      => ['class' => true],
        'span'   => ['class' => true],
        'br'     => [],
        'code'   => ['class' => true],
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
     *
     * The value is raw attribute JSON (see AbstractField::sanitizeHtmlForOutput
     * for why), so every member is filtered here before it touches the DOM.
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

        $url = self::stringMember($value, 'url');
        $text = self::stringMember($value, 'text');
        $target = self::stringMember($value, 'target');
        $rel = self::stringMember($value, 'rel');
        $title = self::stringMember($value, 'title');

        // Set URL
        if ($url !== '') {
            $element->setAttribute('href', esc_url($url));
        } elseif ($element->hasAttribute('href')) {
            $element->removeAttribute('href');
        }

        // Set target (allowlisted; arbitrary window names are dropped)
        if (!in_array($target, self::ALLOWED_TARGETS, true)) {
            $target = '';
        }
        if ($target !== '') {
            $element->setAttribute('target', $target);
        } elseif ($element->hasAttribute('target')) {
            $element->removeAttribute('target');
        }

        // Set rel. Tokens are merged, never replaced: a _blank link always
        // ends up with noopener, and an explicit "opener" token (which would
        // re-enable window.opener for the target page) is discarded.
        $relTokens = self::relTokens($rel);
        if ($target === '_blank') {
            if ($relTokens === []) {
                $relTokens = ['noopener', 'noreferrer'];
            } elseif (!in_array('noopener', $relTokens, true)) {
                $relTokens[] = 'noopener';
            }
        }
        if ($relTokens !== []) {
            $element->setAttribute('rel', implode(' ', $relTokens));
        } elseif ($element->hasAttribute('rel')) {
            $element->removeAttribute('rel');
        }

        // Set title
        if ($title !== '') {
            $element->setAttribute('title', esc_attr($title));
        }

        // Set text content if provided. The raw value goes to the helper: it
        // takes the text-node path when there is no markup (so "&" is encoded
        // exactly once) and otherwise runs sanitizeHtmlForOutput() below.
        if ($text !== '') {
            static::setHtmlContent($element, $text);
        }
    }

    /**
     * Link text gets the inline-only profile, not wp_kses_post.
     */
    protected static function sanitizeHtmlForOutput(string $html): string
    {
        return wp_kses($html, self::TEXT_ALLOWED_HTML);
    }

    /**
     * Read a member of the value as a string; non-scalars become ''.
     */
    private static function stringMember(array $value, string $key): string
    {
        $member = $value[$key] ?? '';

        return is_scalar($member) ? (string) $member : '';
    }

    /**
     * Split a rel attribute into lower-case `[a-z-]` tokens, dropping "opener".
     *
     * @return list<string>
     */
    private static function relTokens(string $rel): array
    {
        $tokens = preg_split('/\s+/', strtolower(trim($rel)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_filter(
            $tokens,
            static fn (string $token): bool => $token !== 'opener' && preg_match('/^[a-z-]+$/', $token) === 1
        );

        return array_values(array_unique($tokens));
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
            'text' => isset($value['text']) ? wp_kses($value['text'], self::TEXT_ALLOWED_HTML) : '',
            'target' => isset($value['target']) ? sanitize_key($value['target']) : '',
            'rel' => isset($value['rel']) ? implode(' ', self::relTokens(sanitize_text_field($value['rel']))) : '',
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
