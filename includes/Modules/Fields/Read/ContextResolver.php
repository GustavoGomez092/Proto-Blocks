<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Read;

use ProtoBlocks\Modules\Fields\Storage\ObjectRef;

/**
 * Turns the getter's `$context` argument into the object a value belongs to.
 *
 *   null                  the current loop post when in the loop, otherwise the
 *                         queried object (singular post, term archive, author archive)
 *   int, digit string     a post ID
 *   WP_Post / WP_Term / WP_User
 *   'post:<id>'  'term:<id>'  'user:<id>'  'option:<page>'
 *
 * Anything else, including IDs of 0 or less, a term that does not exist and
 * any string with trailing junk or a newline, resolves to null: the getter
 * then returns nothing rather than guessing.
 */
final class ContextResolver
{
    private const ID = '[1-9][0-9]{0,18}';

    public static function resolve(mixed $context): ?ObjectRef
    {
        if ($context === null) {
            return self::current();
        }
        if (is_int($context)) {
            return $context > 0 ? ObjectRef::post($context) : null;
        }
        if (is_object($context)) {
            return self::fromObject($context);
        }
        if (!is_string($context)) {
            return null;
        }

        if (preg_match('/\A' . self::ID . '\z/D', $context) === 1) {
            return ObjectRef::post((int) $context);
        }
        if (preg_match('/\A(post|term|user):(' . self::ID . ')\z/D', $context, $m) === 1) {
            $id = (int) $m[2];
            return match ($m[1]) {
                'post' => ObjectRef::post($id),
                'term' => self::term($id),
                'user' => ObjectRef::user($id),
            };
        }
        // Options page slugs: PageDefinition's grammar (lowercase, digits, hyphens, max 64).
        if (preg_match('/\Aoption:([a-z][a-z0-9-]{0,63})\z/D', $context, $m) === 1) {
            return ObjectRef::option($m[1]);
        }

        return null;
    }

    private static function current(): ?ObjectRef
    {
        if (function_exists('in_the_loop') && in_the_loop() && function_exists('get_the_ID')) {
            $id = get_the_ID();
            if (is_int($id) && $id > 0) {
                return ObjectRef::post($id);
            }
        }
        $queried = function_exists('get_queried_object') ? get_queried_object() : null;

        return is_object($queried) ? self::fromObject($queried) : null;
    }

    private static function fromObject(object $o): ?ObjectRef
    {
        if (self::is($o, 'WP_Term') || (isset($o->term_id, $o->taxonomy) && !isset($o->post_type))) {
            $id = self::id($o->term_id ?? null);
            return $id > 0 && is_string($o->taxonomy ?? null) && $o->taxonomy !== '' ? ObjectRef::term($id, $o->taxonomy) : null;
        }
        if (self::is($o, 'WP_Post') || isset($o->post_type)) {
            $id = self::id($o->ID ?? null);
            return $id > 0 ? ObjectRef::post($id) : null;
        }
        if (self::is($o, 'WP_User') || isset($o->user_login)) {
            $id = self::id($o->ID ?? null);
            return $id > 0 ? ObjectRef::user($id) : null;
        }

        return null;
    }

    private static function term(int $id): ?ObjectRef
    {
        $term = function_exists('get_term') ? get_term($id) : null;
        if (!is_object($term) || !is_string($term->taxonomy ?? null) || $term->taxonomy === '') {
            return null;
        }

        return ObjectRef::term($id, $term->taxonomy);
    }

    private static function is(object $o, string $class): bool
    {
        return class_exists($class, false) && $o instanceof $class;
    }

    private static function id(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }

        return is_string($v) && preg_match('/\A' . self::ID . '\z/D', $v) === 1 ? (int) $v : 0;
    }
}
