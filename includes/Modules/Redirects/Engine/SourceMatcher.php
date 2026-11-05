<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine;

/**
 * Whether a request matches a rule's `from` (path, flags and query mode).
 *
 * Non-regex: `from` is split at the first unescaped `?` (`\?` is a literal
 * `?` in the path), the path normalised like a request path, then compared
 * honouring the rule's ignoreCase / ignoreTrailingSlash; the query part goes
 * through QueryMatcher. Regex: the bounded pattern runs against `path?query`
 * as one string and the query mode is ignored (Redirection behaviour).
 */
final class SourceMatcher
{
    /** @var array<string, array{path: string, query: string}> */
    private static array $parsed = [];

    /**
     * @return array{path: string, query: string} the normalised source path and its raw query ('' when none)
     */
    public static function parse(string $from): array
    {
        if (isset(self::$parsed[$from])) {
            return self::$parsed[$from];
        }
        $path = $from;
        $query = '';
        if (preg_match('/^((?:[^?\\\\]|\\\\.)*)\?(.*)$/s', $from, $m) === 1) {
            $path = $m[1];
            $query = $m[2];
        }
        $path = str_replace('\\?', '?', $path);
        if (count(self::$parsed) > 5000) {
            self::$parsed = [];
        }
        return self::$parsed[$from] = ['path' => Request::normalisePath($path), 'query' => $query];
    }

    /** The exact-map key: lower-cased, trailing slashes trimmed, root stays `/`. */
    public static function key(string $path): string
    {
        $key = rtrim(mb_strtolower($path, 'UTF-8'), '/');
        return $key === '' ? '/' : $key;
    }

    /** Path comparison honouring the rule's flags against the un-normalised request path. */
    public static function pathMatches(string $sourcePath, string $requestPath, bool $ignoreCase, bool $ignoreTrailingSlash): bool
    {
        if ($ignoreTrailingSlash) {
            $sourcePath = rtrim($sourcePath, '/') ?: '/';
            $requestPath = rtrim($requestPath, '/') ?: '/';
        }
        if ($ignoreCase) {
            return mb_strtolower($sourcePath, 'UTF-8') === mb_strtolower($requestPath, 'UTF-8');
        }
        return $sourcePath === $requestPath;
    }

    /**
     * Captures for a regex rule, [] for a matching non-regex rule, null when
     * the rule does not match. $error receives preg_last_error_msg() when a
     * regex fails (invalid pattern, backtrack/recursion limit). $compiled is
     * the pattern compiled by the caller, false when it was already refused
     * (not compiled again), null to compile it here.
     *
     * @param array<string,mixed> $rule
     * @return array<int|string,string>|null
     */
    public static function matches(array $rule, Request $r, ?string &$error = null, string|false|null $compiled = null): ?array
    {
        $error = null;
        $from = $rule['from'] ?? null;
        if (!is_string($from) || $from === '') {
            return null;
        }
        $ignoreCase = (bool) ($rule['ignoreCase'] ?? true);
        if (!empty($rule['regex'])) {
            $compiled ??= RegexGuard::compile($from, $ignoreCase);
            if ($compiled === null || $compiled === false) {
                $error = 'Invalid or too long pattern';
                return null;
            }
            $result = RegexGuard::matchDetailed($compiled, $r->pathAndQuery());
            $error = $result['error'];
            return $result['captures'];
        }
        $source = self::parse($from);
        if (!self::pathMatches($source['path'], $r->path, $ignoreCase, (bool) ($rule['ignoreTrailingSlash'] ?? true))) {
            return null;
        }
        return QueryMatcher::matches($rule, $r) ? [] : null;
    }
}
