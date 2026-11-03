<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Read;

/**
 * The ACF-named aliases (get_field, the_field, get_fields), loaded only when
 * ACF is not active and nothing else defined get_field() first.
 */
final class Aliases
{
    /** Requires aliases.php once when !$acfActive() and get_field is undefined; returns whether it loaded. */
    public static function load(callable $acfActive): bool
    {
        if ($acfActive() || function_exists('get_field')) {
            return false;
        }
        require_once dirname(__DIR__) . '/aliases.php';

        return function_exists('get_field');
    }

    /**
     * ACF's `$post_id` argument as a getter context.
     *
     * false/null/''/0 → null (current object); ints, digit strings and WP
     * objects pass through; 'term_<id>' → 'term:<id>'; '<taxonomy>_<id>' →
     * 'term:<id>' when that term is in that taxonomy; 'user_<id>' →
     * 'user:<id>'. 'option'/'options' name ACF's default options page, which
     * does not exist here: they warn and yield false, a context that reads
     * nothing (never the current object). Anything else is false too.
     */
    public static function context(mixed $postId, string $function): mixed
    {
        if ($postId === false || $postId === null || $postId === '' || $postId === 0 || $postId === '0') {
            return null;
        }
        if (is_int($postId) || is_object($postId)) {
            return $postId;
        }
        if (!is_string($postId)) {
            return false;
        }
        if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $postId) === 1) {
            return $postId;
        }
        if ($postId === 'option' || $postId === 'options') {
            if (function_exists('_doing_it_wrong')) {
                _doing_it_wrong(
                    $function,
                    'Proto-Blocks has no default options page; pass "option:<page-slug>" to read an options page field.',
                    defined('PROTO_BLOCKS_VERSION') ? (string) PROTO_BLOCKS_VERSION : ''
                );
            }
            return false;
        }
        if (preg_match('/\A(term|user)_([1-9][0-9]{0,18})\z/D', $postId, $m) === 1) {
            return $m[1] . ':' . $m[2];
        }
        if (preg_match('/\A([a-z0-9_-]{1,32})_([1-9][0-9]{0,18})\z/D', $postId, $m) === 1 && function_exists('get_term')) {
            $term = get_term((int) $m[2], $m[1]);
            if (is_object($term) && ($term->taxonomy ?? null) === $m[1]) {
                return 'term:' . $m[2];
            }
        }

        return false;
    }

    /** What the_field() prints for an escaped value. */
    public static function printable(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        if (!is_array($value)) {
            return '';
        }
        if (isset($value['url']) && is_string($value['url'])) {
            return $value['url'];
        }
        if (array_is_list($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (!is_scalar($item)) {
                    return '';
                }
                $parts[] = is_bool($item) ? ($item ? '1' : '') : (string) $item;
            }
            return implode(', ', $parts);
        }

        return '';
    }
}
