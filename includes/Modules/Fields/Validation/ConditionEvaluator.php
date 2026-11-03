<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Validation;

use ProtoBlocks\Modules\Fields\Definition\Field;

/**
 * Conditional logic with ACF semantics: a field is visible when it has no
 * conditions, or when at least one AND-group of rules all pass. The same
 * rules run client-side (src/fields) and are pinned by the shared corpus.
 *
 * Also the single place user-supplied regexes are compiled, so the `pattern`
 * condition operator and the `pattern` field setting share one safety policy.
 */
final class ConditionEvaluator
{
    /** Longest user pattern accepted; longer ones never compile (ReDoS guard). */
    public const MAX_PATTERN_LENGTH = 200;

    /** Longest subject a user pattern is run against; longer subjects do not match. */
    public const MAX_SUBJECT_LENGTH = 10000;

    /**
     * @param array<string, mixed> $siblingValuesByKey field key => submitted value, for the fields sharing a scope with $field
     */
    public static function visible(Field $field, array $siblingValuesByKey): bool
    {
        if ($field->conditions === []) {
            return true;
        }
        foreach ($field->conditions as $group) {
            $all = $group !== [];
            foreach ($group as $rule) {
                $key = (string) ($rule['field'] ?? '');
                if (!array_key_exists($key, $siblingValuesByKey)
                    || !self::rule((string) ($rule['operator'] ?? ''), $siblingValuesByKey[$key], $rule['value'] ?? null)) {
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

    public static function rule(string $operator, mixed $actual, mixed $expected): bool
    {
        switch ($operator) {
            case '==':
                return self::equals($actual, $expected);
            case '!=':
                return !self::equals($actual, $expected);
            case '==contains':
                $needle = self::str($expected);
                if ($needle === '') {
                    return false;
                }
                if (is_array($actual)) {
                    return self::inList($needle, $actual);
                }
                return str_contains(self::str($actual), $needle);
            case '==empty':
                return self::isEmpty($actual);
            case '!=empty':
                return !self::isEmpty($actual);
            case '==pattern':
                return !is_array($actual) && self::matches(self::str($expected), self::str($actual)) === true;
            default:
                return false;
        }
    }

    /** Empty means null, '', [], or false (a link array without a url counts too). */
    public static function isEmpty(mixed $v): bool
    {
        if ($v === null || $v === '' || $v === false || $v === []) {
            return true;
        }
        if (is_array($v) && array_key_exists('url', $v) && count($v) <= 3 && ($v['url'] === '' || $v['url'] === null)) {
            return true;
        }
        return false;
    }

    /** '/' . escaped . '/Du', or null when empty, too long, or not a valid PCRE. */
    public static function compilePattern(string $pattern): ?string
    {
        if ($pattern === '' || strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            return null;
        }
        $regex = '/' . str_replace('/', '\/', $pattern) . '/Du';
        // The @ is deliberate: an invalid user regex raises a warning, and the
        // false return is the signal we act on.
        return @preg_match($regex, '') === false ? null : $regex;
    }

    /** True/false for a match; null when the pattern is unusable or the engine gave up. */
    public static function matches(string $pattern, string $subject): ?bool
    {
        $regex = self::compilePattern($pattern);
        if ($regex === null) {
            return null;
        }
        if (strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            return false;
        }
        $r = @preg_match($regex, $subject);
        return $r === false ? null : $r === 1;
    }

    private static function equals(mixed $actual, mixed $expected): bool
    {
        $e = self::str($expected);
        return is_array($actual) ? self::inList($e, $actual) : self::str($actual) === $e;
    }

    /** @param array<mixed> $list */
    private static function inList(string $needle, array $list): bool
    {
        foreach ($list as $item) {
            if (!is_array($item) && self::str($item) === $needle) {
                return true;
            }
        }
        return false;
    }

    private static function str(mixed $v): string
    {
        if ($v === true) {
            return '1';
        }
        if ($v === false) {
            return '0';
        }
        return is_scalar($v) ? (string) $v : '';
    }
}
