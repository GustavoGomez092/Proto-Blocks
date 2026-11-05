<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

/**
 * Pattern helpers shared by the parsers, importers and exporters.
 */
final class Patterns
{
    /** The characters whose presence makes a CSV source look like a regex (Redirection's sniff). */
    public const SNIFF = '()[]$^*';

    public static function looksLikeRegex(string $source): bool
    {
        return strpbrk($source, self::SNIFF) !== false;
    }

    /**
     * $body (no anchors) as a literal once its backslash escapes are removed,
     * or null when it uses any regex feature. `\.` `\-` `\/` count as literal.
     */
    public static function literal(string $body): ?string
    {
        $out = '';
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $c = $body[$i];
            if ($c === '\\') {
                $next = $body[$i + 1] ?? '';
                if ($next === '' || ctype_alnum($next)) {
                    return null; // \d, \w, \1 … are classes or back-references
                }
                $out .= $next;
                $i++;
                continue;
            }
            if (str_contains('.^$*+?()[]{}|', $c)) {
                return null;
            }
            $out .= $c;
        }
        return $out;
    }

    /**
     * `^literal$` (optionally `^literal/?$`) → [literal, trailingSlashOptional]; null otherwise.
     *
     * @return array{0: string, 1: bool}|null
     */
    public static function anchoredLiteral(string $pattern): ?array
    {
        if (!str_starts_with($pattern, '^') || !str_ends_with($pattern, '$') || str_ends_with($pattern, '\\$')) {
            return null;
        }
        $body = substr($pattern, 1, -1);
        $optional = false;
        if (str_ends_with($body, '\\/?')) {
            $body = substr($body, 0, -3);
            $optional = true;
        } elseif (str_ends_with($body, '/?')) {
            $body = substr($body, 0, -2);
            $optional = true;
        }
        $literal = self::literal($body);
        return $literal === null ? null : [$literal, $optional];
    }

    /** `/blog/*` → `^/blog/(.*)$`; every other character quoted. */
    public static function wildcard(string $source): string
    {
        $parts = explode('*', $source);
        return '^' . implode('(.*)', array_map(static fn(string $p): string => preg_quote($p, '~'), $parts)) . '$';
    }

    /** A regex-quoted literal that also leaves `/` alone (it is not a delimiter here). */
    public static function quote(string $literal): string
    {
        return str_replace('\\/', '/', preg_quote($literal, '/'));
    }

    /** True when the PCRE body compiles. */
    public static function compiles(string $body): bool
    {
        $delimited = '~' . str_replace('~', '\\~', $body) . '~s';
        return @preg_match($delimited, '') !== false;
    }

    /** True when $s contains a control character (newline, tab, NUL …). */
    public static function hasControl(string $s): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $s) === 1;
    }
}
