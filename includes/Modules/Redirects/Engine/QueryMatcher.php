<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine;

/**
 * Query-string modes (spec §3 "Query modes").
 *
 * - exact:      every source parameter present with an equal value, no extras, order ignored;
 * - exactOrder: raw query string equality;
 * - ignore:     source parameters present and equal, extras ignored (and dropped);
 * - pass:       as ignore, extras appended to the target by passThrough().
 *
 * ignoreCase lowers keys and values on both sides. Regex rules always match
 * (their pattern sees `path?query` already). Repeated scalar keys follow PHP
 * parsing (last wins) except in exactOrder, which compares the raw string.
 */
final class QueryMatcher
{
    public const MAX_DEPTH = 10;

    /** @param array<string,mixed> $rule */
    public static function matches(array $rule, Request $r): bool
    {
        if (!empty($rule['regex'])) {
            return true;
        }
        $from = $rule['from'] ?? null;
        if (!is_string($from)) {
            return true;
        }
        $rawSource = SourceMatcher::parse($from)['query'];
        $ignoreCase = (bool) ($rule['ignoreCase'] ?? true);
        $mode = (string) ($rule['query'] ?? 'exact');

        if ($mode === 'exactOrder') {
            return $ignoreCase
                ? mb_strtolower($rawSource, 'UTF-8') === mb_strtolower($r->rawQuery, 'UTF-8')
                : $rawSource === $r->rawQuery;
        }

        $source = [];
        if ($rawSource !== '') {
            parse_str($rawSource, $source);
        }
        $source = self::canon($source, $ignoreCase);
        $request = self::canon($r->query, $ignoreCase);

        if ($mode === 'ignore' || $mode === 'pass') {
            foreach ($source as $key => $value) {
                if (!array_key_exists($key, $request) || $request[$key] !== $value) {
                    return false;
                }
            }
            return true;
        }
        // exact (also the fallback for an unknown mode)
        return $source === $request;
    }

    /**
     * Two parsed queries are equal: order of named keys ignored, lists in
     * order, keys and values case-folded when $ignoreCase.
     *
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public static function same(array $a, array $b, bool $ignoreCase): bool
    {
        return self::canon($a, $ignoreCase) === self::canon($b, $ignoreCase);
    }

    /**
     * $target with the request's extra parameters appended (pass mode).
     * Parameters named in the rule's source query are consumed; the target's
     * own parameters win on a clash; keys nested deeper than MAX_DEPTH are
     * dropped; an existing `#fragment` moves after the new query. The request's
     * raw pairs are kept as sent (encoding, repeated `a[]=` items).
     *
     * @param array<string,mixed> $rule
     */
    public static function passThrough(string $target, Request $r, array $rule): string
    {
        if ($r->rawQuery === '') {
            return $target;
        }
        $ignoreCase = (bool) ($rule['ignoreCase'] ?? true);
        $fragment = '';
        $hash = strpos($target, '#');
        if ($hash !== false) {
            $fragment = substr($target, $hash);
            $target = substr($target, 0, $hash);
        }
        $q = strpos($target, '?');
        $base = $q === false ? $target : substr($target, 0, $q);
        $targetQuery = $q === false ? '' : substr($target, $q + 1);

        $taken = [];
        foreach ([$targetQuery, SourceMatcher::parse((string) ($rule['from'] ?? ''))['query']] as $raw) {
            foreach (self::pairs($raw) as [$name]) {
                $taken[self::fold($name, $ignoreCase)] = true;
            }
        }

        $extras = [];
        foreach (self::pairs($r->rawQuery) as [$name, $pair, $depth]) {
            if ($depth > self::MAX_DEPTH || isset($taken[self::fold($name, $ignoreCase)])) {
                continue;
            }
            $extras[] = $pair;
        }
        if ($extras === []) {
            return $target . $fragment;
        }
        $query = ($targetQuery === '' ? '' : rtrim($targetQuery, '&') . '&') . implode('&', $extras);
        return $base . '?' . $query . $fragment;
    }

    /**
     * Raw `name=value` pairs of a query string with their base parameter name
     * (decoded, before the first `[`) and bracket depth.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    private static function pairs(string $raw): array
    {
        $out = [];
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            $rawName = explode('=', $pair, 2)[0];
            $name = urldecode($rawName);
            $bracket = strpos($name, '[');
            $base = $bracket === false ? $name : substr($name, 0, $bracket);
            if ($base === '') {
                continue;
            }
            $depth = $bracket === false ? 0 : substr_count(substr($name, $bracket), '[');
            $out[] = [$base, $pair, $depth];
        }
        return $out;
    }

    private static function fold(string $name, bool $ignoreCase): string
    {
        return $ignoreCase ? mb_strtolower($name, 'UTF-8') : $name;
    }

    /**
     * Comparable form: keys of associative levels sorted, lists kept in order,
     * strings lower-cased when ignoreCase.
     *
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function canon(array $value, bool $ignoreCase, int $depth = 0): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $k = is_string($key) ? self::fold($key, $ignoreCase) : $key;
            if (is_array($item)) {
                $out[$k] = $depth >= self::MAX_DEPTH ? [] : self::canon($item, $ignoreCase, $depth + 1);
            } else {
                $out[$k] = $ignoreCase ? mb_strtolower((string) $item, 'UTF-8') : (string) $item;
            }
        }
        if (!array_is_list($out)) {
            ksort($out, SORT_STRING);
        }
        return $out;
    }
}
