<?php
/**
 * Attribute Sanitizer - the plugin's own trust boundary for block attributes
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Security;

/**
 * Sanitises Proto-Blocks attribute values by their declared field type.
 *
 * Why this exists: Proto-Blocks attributes live in the block-comment JSON.
 * WordPress kses only acts on literal markup in post_content, so a JSON
 * string such as "<img src=x onerror=...>" written by a Contributor through
 * the REST posts API is stored verbatim; WP_Block_Type only type-checks
 * declared attributes and never strips markup from strings. The editor then
 * feeds the stored strings straight into RichText, and the front end hands
 * them to the theme template. This class mirrors what core does for
 * post_content: values authored by users who lack `unfiltered_html` are
 * sanitised, keyed on the block's protoBlocks field/control config when the
 * key is a declared field, otherwise on the key name and the value type.
 *
 * All methods are pure (no WordPress state) apart from the `wp_kses*` /
 * `esc_url_raw` helpers they call, so the class is unit-testable with stubs.
 */
final class AttributeSanitizer
{
    /**
     * Nesting depth beyond which arrays are dropped rather than walked.
     */
    public const MAX_DEPTH = 10;

    /**
     * Keys that always hold a URL, whatever the field config says.
     */
    private const URL_KEYS = ['url', 'href', 'src', 'poster'];

    /**
     * Allowed values for a link `target`.
     */
    private const TARGETS = ['_self', '_blank', '_parent', '_top'];

    /**
     * Field types whose string value is rich HTML (block-level markup allowed).
     */
    private const RICH_TYPES = ['wysiwyg', 'richtext', 'inner-blocks', 'innerblocks'];

    /**
     * Whether attribute values saved or rendered on behalf of $userId must be
     * sanitised. Mirrors core: only `unfiltered_html` may store raw markup.
     * User id 0 (no user, cron, CLI) never has the capability.
     */
    public static function shouldSanitizeForUser(int $userId): bool
    {
        if ($userId <= 0) {
            return true;
        }

        return !user_can($userId, 'unfiltered_html');
    }

    /**
     * Decide whether a post's stored Proto-Blocks attributes are trusted.
     *
     * @param string $trustMeta Value of the `_proto_blocks_unfiltered` meta:
     *                          '1' trusted, '0' untrusted, '' not recorded
     *                          (legacy post: fall back to the author).
     * @param int    $authorId  The post author, used only for legacy posts.
     */
    public static function isPostTrusted(string $trustMeta, int $authorId): bool
    {
        if ($trustMeta === '1') {
            return true;
        }
        if ($trustMeta === '0') {
            return false;
        }

        return !self::shouldSanitizeForUser($authorId);
    }

    /**
     * Sanitise a block's attributes against its protoBlocks config.
     *
     * @param array $attributes  Attributes as stored in the block comment (or posted to a preview).
     * @param array $protoConfig The block's `protoBlocks` config (`fields`, `controls`); may be
     *                           un-normalised block.json (string shorthand field types are accepted).
     */
    public static function sanitize(array $attributes, array $protoConfig): array
    {
        return self::walk($attributes, $protoConfig, 0);
    }

    /**
     * Recursive worker.
     */
    private static function walk(array $attributes, array $protoConfig, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            // Nothing legitimate nests this deep (attrs > repeater > row >
            // image > sizes > size > url is depth 6). Drop rather than
            // pass through unsanitised.
            return [];
        }

        $fields   = self::configMap($protoConfig['fields'] ?? []);
        $controls = self::configMap($protoConfig['controls'] ?? []);

        foreach ($attributes as $key => $value) {
            $config = null;
            if (is_string($key)) {
                $config = $fields[$key] ?? $controls[$key] ?? null;
            }

            $attributes[$key] = self::sanitizeValue($key, $value, $config, $depth);
        }

        return $attributes;
    }

    /**
     * Normalise a `fields` / `controls` map so every entry is an array with a `type`.
     *
     * @return array<string, array>
     */
    private static function configMap(mixed $map): array
    {
        if (!is_array($map)) {
            return [];
        }

        $normalized = [];
        foreach ($map as $name => $config) {
            if (!is_string($name)) {
                continue;
            }
            if (is_string($config)) {
                $normalized[$name] = ['type' => $config];
            } elseif (is_array($config)) {
                $normalized[$name] = $config + ['type' => 'text'];
            }
        }

        return $normalized;
    }

    /**
     * Sanitise one value. $config is the declared field/control config for
     * this key, or null when the key is undeclared.
     */
    private static function sanitizeValue(int|string $key, mixed $value, ?array $config, int $depth): mixed
    {
        // Scalars that cannot carry markup are left alone.
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        $type = is_array($config) ? strtolower((string) ($config['type'] ?? 'text')) : null;

        // Repeaters (field or control): each row is an attribute set of its own,
        // sanitised against the repeater's sub-field config.
        if ($type === 'repeater') {
            if (!is_array($value)) {
                return [];
            }
            $subConfig = ['fields' => $config['fields'] ?? []];
            foreach ($value as $i => $row) {
                $value[$i] = is_array($row)
                    ? self::walk($row, $subConfig, $depth + 1)
                    : self::sanitizeValue($i, $row, null, $depth + 1);
            }
            return $value;
        }

        // Gallery control: a list of image objects.
        if (is_array($value)) {
            // Objects (link/image/video/file/style/...) and lists: recurse with
            // no field config, so sub-keys are handled by name (url, rel, ...).
            return self::walk($value, [], $depth + 1);
        }

        if (!is_string($value)) {
            // Objects/resources never belong in block attributes.
            return '';
        }

        return self::sanitizeString((string) $key, $value, $type, $config ?? []);
    }

    /**
     * Sanitise a string value by key name first, then by declared type.
     */
    private static function sanitizeString(string $key, string $value, ?string $type, array $config): string
    {
        // Keys that are URLs regardless of config (link.url, image.src, video.poster, ...).
        if (in_array($key, self::URL_KEYS, true) || in_array($type, ['link', 'image', 'video', 'file'], true)) {
            return self::sanitizeUrl($value);
        }

        switch ($key) {
            case 'rel':
                return self::sanitizeRel($value);
            case 'target':
                return in_array($value, self::TARGETS, true) ? $value : '';
            case 'anchor':
                return self::sanitizeAnchor($value);
            case 'className':
                // Left to the Renderer, which DOM-escapes it into the class attribute.
                return $value;
            case 'innerBlocksContent':
                // Rendered inner-block HTML; overwritten by WP on the front end.
                return wp_kses_post($value);
        }

        if ($type !== null && in_array($type, self::RICH_TYPES, true)) {
            return wp_kses_post($value);
        }

        // A `text` field with format "full" offers the same RichText formats
        // as a wysiwyg field (inline images, colours), so narrowing it to the
        // inline allowlist would strip content the editor itself inserted.
        if ($type === 'text' && strtolower((string) ($config['format'] ?? '')) === 'full') {
            return wp_kses_post($value);
        }

        // Text fields and every other string: inline formatting only. Text
        // fields are RichText-backed so may legitimately carry <strong>/<a>;
        // stripping to plain text would break content.
        return self::ksesInline($value);
    }

    /**
     * Inline-formatting allowlist, with core's protocol filtering (wp_kses
     * falls back to wp_allowed_protocols() when the list is empty).
     */
    public static function ksesInline(string $value): string
    {
        return wp_kses($value, self::inlineAllowedHtml(), []);
    }

    /**
     * The inline allowlist used for text fields and undeclared strings.
     *
     * @return array<string, array<string, bool>>
     */
    public static function inlineAllowedHtml(): array
    {
        return [
            'a'      => ['href' => true, 'target' => true, 'rel' => true, 'title' => true],
            'strong' => [],
            'em'     => [],
            'b'      => [],
            'i'      => [],
            'u'      => [],
            's'      => [],
            'span'   => ['class' => true],
            'br'     => [],
            'code'   => [],
            'sub'    => [],
            'sup'    => [],
            'mark'   => [],
        ];
    }

    /**
     * URL: esc_url_raw() returns '' for a disallowed scheme (javascript:, data:).
     */
    public static function sanitizeUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return (string) esc_url_raw($value);
    }

    /**
     * rel: space-separated tokens of [a-z-] only.
     */
    public static function sanitizeRel(string $value): string
    {
        $tokens = preg_split('/\s+/', strtolower(trim($value))) ?: [];
        $tokens = array_filter($tokens, static fn(string $t): bool => $t !== '' && preg_match('/^[a-z-]+$/', $t) === 1);

        return implode(' ', array_unique($tokens));
    }

    /**
     * anchor (HTML id): strip anything outside [A-Za-z0-9_:.-].
     */
    public static function sanitizeAnchor(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_:.\-]/', '', $value);
    }
}
