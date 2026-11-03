<?php
/**
 * ACF-named aliases of the field getters. Loaded only through
 * ProtoBlocks\Modules\Fields\Read\Aliases::load(), i.e. when ACF is not
 * active; each function is still guarded so another definition always wins.
 *
 * Unlike ACF, values come back escaped for their type by default
 * ($format_value = false returns the stored value). WordPress escapers do not
 * double-encode entities, so a template that escapes again is harmless.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

use ProtoBlocks\Modules\Fields\Read\Aliases;

require_once __DIR__ . '/functions.php';

if (!function_exists('get_field')) {
    /**
     * @param string $selector     Field name.
     * @param mixed  $post_id      ACF post id: ID, object, 'term_<id>', '<taxonomy>_<id>', 'user_<id>'.
     * @param bool   $format_value False returns the stored value.
     */
    function get_field($selector, $post_id = false, $format_value = true)
    {
        if (!is_string($selector)) {
            return null;
        }
        $context = Aliases::context($post_id, 'get_field');

        return $context === false ? null : proto_field($selector, $context, !$format_value);
    }
}

if (!function_exists('the_field')) {
    /**
     * Echoes the escaped value: scalars as they are, lists of scalars joined
     * with ", ", arrays with a "url" key as that URL, other arrays nothing.
     */
    function the_field($selector, $post_id = false): void
    {
        if (!is_string($selector)) {
            return;
        }
        $context = Aliases::context($post_id, 'the_field');
        if ($context === false) {
            return;
        }
        // Escaped by the field type in proto_field().
        echo Aliases::printable(proto_field($selector, $context)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}

if (!function_exists('get_fields')) {
    /**
     * @param mixed $post_id      As get_field().
     * @param bool  $format_value False returns stored values.
     * @return array<string, mixed>
     */
    function get_fields($post_id = false, $format_value = true): array
    {
        $context = Aliases::context($post_id, 'get_fields');

        return $context === false ? [] : proto_fields($context, !$format_value);
    }
}
