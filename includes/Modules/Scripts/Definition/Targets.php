<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Scripts\Definition;

use ProtoBlocks\Modules\Scripts\RequestContext;

/**
 * Placement targets (spec §2) and the `where` block: a page is in scope when
 * it matches any `include` entry and no `exclude` entry, and the visitor
 * passes the login and role rules.
 *
 * Every evaluation goes through WordPress's conditional tags, read on the
 * front end after the main query ran (wp_head and later).
 */
final class Targets
{
    /** Targets without a value. */
    public const SIMPLE = ['all', 'front_page', 'home', 'search', '404', 'archive', 'singular'];

    /** Targets with a `<kind>:<value>` form. */
    public const VALUED = ['singular', 'post_type', 'page', 'post', 'taxonomy', 'term', 'url'];

    public const LIST_MAX = 100;
    public const ENTRY_MAX = 200;

    /** Same grammar as `$defs/target` in scripts.schema.json. */
    public const PATTERN = '~^(?:all|front_page|home|search|404|archive|singular|(?:singular|post_type):[a-z0-9_-]{1,20}|(?:page|post):[A-Za-z0-9_%/-]{1,190}|taxonomy:[a-z0-9_-]{1,32}|term:[a-z0-9_-]{1,32}:[A-Za-z0-9_%-]{1,160}|url:/[^\s<>"]{0,195})$~D';

    public static function valid(string $target): bool
    {
        return strlen($target) <= self::ENTRY_MAX && preg_match(self::PATTERN, $target) === 1;
    }

    /**
     * The fully defaulted `where` block.
     *
     * @param array<string, mixed> $where
     * @return array{include: list<string>, exclude: list<string>, loggedIn: ?bool, roles: list<string>, excludeRoles: list<string>}
     */
    public static function normalize(array $where): array
    {
        $list = static fn (mixed $value): array => is_array($value)
            ? array_values(array_map('strval', array_filter($value, 'is_string')))
            : [];
        $loggedIn = $where['loggedIn'] ?? null;

        return [
            'include' => array_key_exists('include', $where) && is_array($where['include']) ? $list($where['include']) : ['all'],
            'exclude' => $list($where['exclude'] ?? []),
            'loggedIn' => is_bool($loggedIn) ? $loggedIn : null,
            'roles' => $list($where['roles'] ?? []),
            'excludeRoles' => $list($where['excludeRoles'] ?? []),
        ];
    }

    /**
     * Whether a normalised `where` puts the current request in scope.
     *
     * @param array{include: list<string>, exclude: list<string>, loggedIn: ?bool, roles: list<string>, excludeRoles: list<string>} $where
     */
    public static function inScope(array $where, RequestContext $context): bool
    {
        if ($where['loggedIn'] === true && !$context->loggedIn) {
            return false;
        }
        if ($where['loggedIn'] === false && $context->loggedIn) {
            return false;
        }
        if ($where['roles'] !== [] && (!$context->loggedIn || array_intersect($where['roles'], $context->roles) === [])) {
            return false;
        }
        if ($where['excludeRoles'] !== [] && $context->loggedIn && array_intersect($where['excludeRoles'], $context->roles) !== []) {
            return false;
        }

        $included = false;
        foreach ($where['include'] as $target) {
            if (self::matches($target, $context)) {
                $included = true;
                break;
            }
        }
        if (!$included) {
            return false;
        }
        foreach ($where['exclude'] as $target) {
            if (self::matches($target, $context)) {
                return false;
            }
        }

        return true;
    }

    /** Whether one target matches the current request; an invalid target never matches. */
    public static function matches(string $target, RequestContext $context): bool
    {
        if (!self::valid($target)) {
            return false;
        }

        switch ($target) {
            case 'all':
                return true;
            case 'front_page':
                return is_front_page();
            case 'home':
                return is_home();
            case 'search':
                return is_search();
            case '404':
                return is_404();
            case 'archive':
                return is_archive();
            case 'singular':
                return is_singular();
        }

        [$kind, $value] = explode(':', $target, 2);

        switch ($kind) {
            case 'singular':
                return is_singular($value);
            case 'post_type':
                return is_singular($value) || is_post_type_archive($value);
            case 'page':
            case 'post':
                // An id of 0 would read as "any page" to is_page()/is_single(): never match it.
                $which = self::idOrSlug($value);
                if ($which === 0) {
                    return false;
                }
                return $kind === 'page' ? is_page($which) : is_single($which);
            case 'taxonomy':
                return self::isTaxonomyArchive($value);
            case 'term':
                [$taxonomy, $term] = explode(':', $value, 2);
                return self::isTaxonomyArchive($taxonomy, $term) || (is_singular() && has_term($term, $taxonomy));
            case 'url':
                return self::globMatches($value, $context->path);
        }

        return false;
    }

    /**
     * A `url:` glob against a request path: `*` is any run of characters
     * (none included, `/` included), everything else is literal; both sides
     * are compared decoded, case-insensitively, with a trailing slash ignored.
     */
    public static function globMatches(string $pattern, string $path): bool
    {
        $pattern = self::trimSlash(rawurldecode($pattern));
        $path = self::trimSlash($path);
        $regex = '~^' . implode('.*', array_map(static fn (string $part): string => preg_quote($part, '~'), explode('*', $pattern))) . '$~isD';

        if (preg_match($regex, $path) === 1) {
            return true;
        }

        return $path !== '/' && preg_match($regex, $path . '/') === 1;
    }

    /**
     * A short description of a `where` block for lists ("All pages; not page:privacy").
     *
     * @param array{include: list<string>, exclude: list<string>, loggedIn: ?bool, roles: list<string>, excludeRoles: list<string>} $where
     */
    public static function summary(array $where): string
    {
        $parts = [];
        $parts[] = $where['include'] === ['all']
            ? __('All pages', 'proto-blocks')
            : ($where['include'] === [] ? __('Nowhere', 'proto-blocks') : implode(', ', $where['include']));
        if ($where['exclude'] !== []) {
            /* translators: %s: comma-separated targets */
            $parts[] = sprintf(__('except %s', 'proto-blocks'), implode(', ', $where['exclude']));
        }
        if ($where['loggedIn'] === true) {
            $parts[] = __('logged in', 'proto-blocks');
        } elseif ($where['loggedIn'] === false) {
            $parts[] = __('logged out', 'proto-blocks');
        }
        if ($where['roles'] !== []) {
            /* translators: %s: comma-separated role slugs */
            $parts[] = sprintf(__('roles: %s', 'proto-blocks'), implode(', ', $where['roles']));
        }
        if ($where['excludeRoles'] !== []) {
            /* translators: %s: comma-separated role slugs */
            $parts[] = sprintf(__('not roles: %s', 'proto-blocks'), implode(', ', $where['excludeRoles']));
        }

        return implode('; ', $parts);
    }

    private static function isTaxonomyArchive(string $taxonomy, string $term = ''): bool
    {
        if ($taxonomy === 'category') {
            return $term === '' ? is_category() : is_category($term);
        }
        if ($taxonomy === 'post_tag') {
            return $term === '' ? is_tag() : is_tag($term);
        }

        return $term === '' ? is_tax($taxonomy) : is_tax($taxonomy, $term);
    }

    private static function idOrSlug(string $value): int|string
    {
        return ctype_digit($value) ? (int) $value : $value;
    }

    private static function trimSlash(string $value): string
    {
        $value = (string) preg_replace('~/{2,}~', '/', $value);
        $trimmed = rtrim($value, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }
}
