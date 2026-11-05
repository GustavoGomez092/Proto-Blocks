<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

/**
 * Raw rules as parsers hand them on: spec §1 keys in canonical order, nulls
 * dropped so the group defaults apply to anything the source did not say.
 */
final class RawRule
{
    public const KEYS = [
        'id', 'title', 'enabled', 'from', 'regex', 'to', 'else', 'code', 'action', 'when',
        'query', 'ignoreCase', 'ignoreTrailingSlash', 'log', 'headers',
    ];

    public const URL_CODES = [301, 302, 303, 307, 308];
    public const ERROR_CODES = [400, 401, 403, 404, 410, 418, 451, 500, 501, 502, 503, 504];
    public const QUERY_MODES = ['exact', 'exactOrder', 'ignore', 'pass'];

    /** @param array<string, mixed> $rule */
    public static function build(array $rule): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $rule) && $rule[$key] !== null) {
                $out[$key] = $rule[$key];
            }
        }
        return $out;
    }

    /** 'url' | 'error' | null (not a code either action can use). */
    public static function actionForCode(int $code): ?string
    {
        if (in_array($code, self::URL_CODES, true)) {
            return 'url';
        }
        return in_array($code, self::ERROR_CODES, true) ? 'error' : null;
    }

    /** A path ("/x") or an absolute http(s) URL; a bare relative path gets its leading slash. Null when unusable. */
    public static function target(string $target): ?string
    {
        $target = trim($target);
        if ($target === '' || preg_match('/[\x00-\x1F\x7F]/', $target) === 1) {
            return null;
        }
        if (preg_match('~^https?://~i', $target) === 1 || str_starts_with($target, '/')) {
            return $target;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $target) === 1 || str_starts_with($target, '\\')) {
            return null;
        }
        return '/' . $target;
    }

    /** A non-regex source with its leading slash (absolute URLs are kept for the normaliser). */
    public static function source(string $source): string
    {
        $source = trim($source);
        if ($source === '' || str_starts_with($source, '/') || preg_match('~^https?://~i', $source) === 1) {
            return $source;
        }
        return '/' . $source;
    }

    /** True for a non-regex, unconditional rule whose target is its own source (case and trailing slash ignored). */
    public static function selfRedirect(array $rule): bool
    {
        if (($rule['regex'] ?? false) || !isset($rule['from'], $rule['to']) || isset($rule['when'])) {
            return false;
        }
        $norm = static fn(string $p): string => strtolower(rtrim($p, '/')) ?: '/';
        return $norm((string) $rule['from']) === $norm((string) $rule['to']);
    }

    /** Redirection-style query flag (`exactorder` …) → ours, null when unknown. */
    public static function queryMode(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $map = ['exact' => 'exact', 'exactorder' => 'exactOrder', 'ignore' => 'ignore', 'pass' => 'pass'];
        return $map[strtolower($value)] ?? null;
    }
}
