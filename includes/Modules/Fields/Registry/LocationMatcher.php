<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Registry;

use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\Definition\Location;

/**
 * Evaluates a group's location (OR of AND-branches) against one screen.
 *
 * Fails closed everywhere: an unrenderable location, a branch mixing object
 * kinds, a rule of another kind than the screen (even negated: `post_type !=
 * page` says nothing about a user screen), an unknown operator and a
 * malformed value never match.
 */
final class LocationMatcher
{
    /**
     * $ctx keys: post_type, post_status, post_id, page_template, terms
     * (taxonomy => slugs), taxonomy, user_roles, user_form
     * ('add'|'edit'|'register'), options_page; optional `kind`
     * (post|term|user|option), otherwise inferred from the keys present.
     */
    public static function matches(Location $loc, array $ctx): bool
    {
        if (!$loc->isRenderable()) {
            return false;
        }
        $kind = self::contextKind($ctx);
        if ($kind === null) {
            return false;
        }
        foreach ($loc->or as $branch) {
            if (self::branchKind($branch) !== $kind) {
                continue;
            }
            $all = true;
            foreach ($branch as $rule) {
                if (!self::rule($rule, $ctx)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }

        return false;
    }

    /**
     * Object kinds the rules can match, in post, term, user, option order.
     *
     * @return list<string>
     */
    public static function kinds(Location $loc): array
    {
        if (!$loc->isRenderable()) {
            return [];
        }
        $found = [];
        foreach ($loc->or as $branch) {
            $kind = self::branchKind($branch);
            if ($kind !== null) {
                $found[$kind] = true;
            }
        }

        return array_values(array_filter(['post', 'term', 'user', 'option'], static fn(string $k): bool => isset($found[$k])));
    }

    /**
     * The one kind every rule of the branch belongs to; null when the branch
     * is empty, mixes kinds, or holds an unsupported param.
     *
     * @param list<array{param: string, operator: string, value: string}> $branch
     */
    public static function branchKind(array $branch): ?string
    {
        $kind = null;
        foreach ($branch as $rule) {
            $k = GroupNormalizer::PARAM_KINDS[$rule['param'] ?? ''] ?? null;
            if ($k === null || ($kind !== null && $k !== $kind)) {
                return null;
            }
            $kind = $k;
        }

        return $kind;
    }

    private static function contextKind(array $ctx): ?string
    {
        if (isset($ctx['kind']) && in_array($ctx['kind'], ['post', 'term', 'user', 'option'], true)) {
            return $ctx['kind'];
        }

        return match (true) {
            array_key_exists('post_type', $ctx) || array_key_exists('post_id', $ctx) => 'post',
            array_key_exists('taxonomy', $ctx) => 'term',
            array_key_exists('user_roles', $ctx) || array_key_exists('user_form', $ctx) => 'user',
            array_key_exists('options_page', $ctx) => 'option',
            default => null,
        };
    }

    /** @param array{param: string, operator: string, value: string} $rule */
    private static function rule(array $rule, array $ctx): bool
    {
        $op = $rule['operator'] ?? '';
        if ($op !== '==' && $op !== '!=') {
            return false;
        }
        $equal = self::equals((string) $rule['param'], (string) ($rule['value'] ?? ''), $ctx);
        if ($equal === null) {
            return false;
        }

        return $op === '==' ? $equal : !$equal;
    }

    /** null when the rule cannot be decided (malformed value, missing context): it then fails either way. */
    private static function equals(string $param, string $value, array $ctx): ?bool
    {
        switch ($param) {
            case 'post_type':
            case 'post_status':
                $actual = self::str($ctx[$param] ?? null);
                return $actual === '' ? null : $actual === $value;

            case 'post_taxonomy':
                $parts = explode(':', $value, 2);
                if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                    return null;
                }
                $slugs = $ctx['terms'][$parts[0]] ?? [];
                return is_array($slugs) && in_array($parts[1], array_map('strval', $slugs), true);

            case 'page_template':
                $actual = self::str($ctx['page_template'] ?? null);
                $actual = $actual === '' ? 'default' : $actual;
                return $actual === ($value === '' ? 'default' : $value);

            case 'post':
                if (!preg_match('/^[1-9][0-9]{0,18}\z/D', $value)) {
                    return null;
                }
                $id = $ctx['post_id'] ?? null;
                return is_numeric($id) && (string) (int) $id === $value;

            case 'taxonomy':
                $actual = self::str($ctx['taxonomy'] ?? null);
                if ($actual === '') {
                    return null;
                }
                return $value === 'all' || $actual === $value;

            case 'user_role':
                $roles = $ctx['user_roles'] ?? [];
                if (!is_array($roles)) {
                    return null;
                }
                return $value === 'all' || in_array($value, array_map('strval', $roles), true);

            case 'user_form':
                $form = self::str($ctx['user_form'] ?? null);
                if ($form === '') {
                    return null;
                }
                return $value === 'all' || $form === $value;

            case 'options_page':
                $page = self::str($ctx['options_page'] ?? null);
                return $page === '' ? null : $page === $value;
        }

        return null;
    }

    private static function str(mixed $v): string
    {
        return is_string($v) || is_int($v) ? (string) $v : '';
    }
}
