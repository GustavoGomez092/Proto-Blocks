<?php
/**
 * Global Helper Functions
 *
 * These functions are available in the global namespace for use in templates.
 *
 * Every value a template reads from $attributes is contributor-controlled JSON
 * that WordPress only type-checks (never kses-filters). The helpers below cover
 * the output contexts WordPress core has no single escaper for: colours and
 * url() tokens inside an inline style, and the target/rel pair on a link.
 *
 * @package ProtoBlocks
 */

// Prevent direct access
defined('ABSPATH') || exit;

/**
 * Validate a colour control value as a hex colour.
 *
 * esc_attr() is the wrong tool for a value that lands inside style="": it leaves
 * `;`, `(`, `)` and `:` untouched, so an attacker-controlled "colour" can append
 * arbitrary declarations. This helper only ever returns a 3- or 6-digit hex
 * colour (`#abc` / `#aabbcc`) or the developer-supplied default.
 *
 * @param string|null $value   Stored control value (may be anything).
 * @param string      $default Returned when $value is not a hex colour. Pass ''
 *                             to get an empty string you can test with empty().
 * @return string Hex colour or $default.
 */
function proto_blocks_sanitize_color(?string $value, string $default = ''): string
{
    if ($value === null || $value === '') {
        return $default;
    }

    $hex = sanitize_hex_color(trim($value));

    return is_string($hex) && $hex !== '' ? $hex : $default;
}

/**
 * Build a safe CSS url('...') token from an attribute URL.
 *
 * esc_url() alone is not enough inside url(...): it keeps `(`, `)`, `;` and `:`
 * and spells `'` as &#039;, which the browser decodes back to a quote inside the
 * attribute, so a stored URL can close the url() and start new declarations.
 * This helper runs esc_url() for the protocol allowlist, then percent-encodes
 * every character that is significant inside a CSS string.
 *
 * The returned token is raw CSS. Place it in a style attribute through
 * get_block_wrapper_attributes() (which esc_attr()s it) or echo esc_attr().
 *
 * @param string $url Attribute URL.
 * @return string `url('...')` or an empty string when esc_url() rejects the URL.
 */
function proto_blocks_css_url(string $url): string
{
    $clean = esc_url($url);

    if (!is_string($clean) || $clean === '') {
        return '';
    }

    // Undo the entity spellings esc_url() introduces so the token is plain
    // text; the attribute escaper re-encodes them for the HTML context.
    $clean = str_replace(['&#038;', '&amp;', '&#039;'], ['&', '&', "'"], $clean);

    $clean = str_replace(
        ['(', ')', "'", '"', ';', ' ', '\\', '<', '>'],
        ['%28', '%29', '%27', '%22', '%3B', '%20', '%5C', '%3C', '%3E'],
        $clean
    );

    return "url('" . $clean . "')";
}

/**
 * Build the target/rel attribute pair for a link field value.
 *
 * Only `_blank` is honoured as a target, and `noopener` is always added to a
 * `_blank` link so the opened page cannot reach window.opener. The author's own
 * rel tokens (nofollow, sponsored, ...) are kept. The return value is already
 * escaped and starts with a space, so it can be echoed straight after other
 * attributes: `<a href="..."<?php echo proto_blocks_link_attrs( $link ); ?>>`.
 *
 * @param array $link Link field value (`url`, `text`, `target`, `rel`).
 * @return string Escaped ` target="_blank" rel="..."` fragment or empty string.
 */
function proto_blocks_link_attrs(array $link): string
{
    $is_blank = ($link['target'] ?? '') === '_blank';

    $rel = preg_split('/\s+/', trim((string) ($link['rel'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $rel = array_filter($rel, static fn($token) => (bool) preg_match('/^[a-z][a-z-]*$/i', $token));

    if ($is_blank) {
        $rel[] = 'noopener';
    }

    $rel = array_values(array_unique(array_map('strtolower', $rel)));

    $out = $is_blank ? ' target="_blank"' : '';

    if ($rel !== []) {
        $out .= ' rel="' . esc_attr(implode(' ', $rel)) . '"';
    }

    return $out;
}

/**
 * Convert hex color to rgba
 *
 * A value that is not a hex colour yields the safe default rgba(0, 0, 0, alpha)
 * instead of feeding garbage to hexdec(). Alpha is clamped to 0..1.
 *
 * @param string $hex Hex color (with or without #)
 * @param float $alpha Alpha value (0-1)
 * @return string RGBA color string
 */
function proto_blocks_hex_to_rgba(string $hex, float $alpha = 1): string
{
    $alpha = max(0.0, min(1.0, $alpha));
    $hex   = proto_blocks_sanitize_color('#' . ltrim(trim($hex), '#'), '');

    if ($hex === '') {
        return sprintf('rgba(0, 0, 0, %s)', $alpha);
    }

    $hex = ltrim($hex, '#');

    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    return sprintf('rgba(%d, %d, %d, %s)', $r, $g, $b, $alpha);
}
