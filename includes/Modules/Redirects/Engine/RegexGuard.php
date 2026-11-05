<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine;

/**
 * Bounded PCRE for rule sources and condition values (spec §3 rule 6, §13).
 *
 * A pattern is a PCRE body without delimiters. compile() picks a delimiter
 * that does not occur in the body (so nothing needs escaping and nothing can
 * terminate the pattern early), adds `s`, `i` when ignoreCase, and `u` when
 * the body is valid UTF-8 and compiles with it. Bodies over 500 characters
 * and bodies that do not compile give null. match() checks preg_last_error()
 * so a backtrack/recursion limit means "no match", never a hang or a notice.
 */
final class RegexGuard
{
    public const MAX_LENGTH = 500;

    /** Candidate delimiters, most readable first. None is alphanumeric, a backslash, whitespace or a bracket opener (`(` `[` `{` `<` need a different closer). */
    private const DELIMITERS = ['#', '~', '!', '@', '%', '`', '|', ';', ',', '=', '&', "'", '"', '-', '_', ':', '>', '/', '+', '*', '.', '^', '$', '?', "\x01", "\x02", "\x03"];

    public static function compile(string $pattern, bool $ignoreCase): ?string
    {
        if ($pattern === '' || strlen($pattern) > self::MAX_LENGTH || str_contains($pattern, "\0")) {
            return null;
        }
        $delimiter = null;
        foreach (self::DELIMITERS as $candidate) {
            if (!str_contains($pattern, $candidate)) {
                $delimiter = $candidate;
                break;
            }
        }
        if ($delimiter === null) {
            return null;
        }
        $flags = 's' . ($ignoreCase ? 'i' : '');
        $base = $delimiter . $pattern . $delimiter . $flags;
        if (preg_match('//u', $pattern) === 1 && self::compiles($base . 'u')) {
            return $base . 'u';
        }
        return self::compiles($base) ? $base : null;
    }

    /**
     * Captures (index 0 = whole match) or null for no match and for any PCRE error.
     *
     * @return array<int|string,string>|null
     */
    public static function match(string $compiled, string $subject): ?array
    {
        return self::matchDetailed($compiled, $subject)['captures'];
    }

    /**
     * @return array{captures: array<int|string,string>|null, error: ?string}
     *         error is preg_last_error_msg() when PCRE failed (backtrack limit, bad UTF-8, …).
     */
    public static function matchDetailed(string $compiled, string $subject): array
    {
        $matches = [];
        $result = @preg_match($compiled, $subject, $matches);
        $code = preg_last_error();
        if ($result === false || $code !== PREG_NO_ERROR) {
            return ['captures' => null, 'error' => $code !== PREG_NO_ERROR ? preg_last_error_msg() : 'Invalid pattern'];
        }
        return ['captures' => $result === 1 ? $matches : null, 'error' => null];
    }

    private static function compiles(string $compiled): bool
    {
        $ok = @preg_match($compiled, '') !== false;
        // Leave no error state behind for the caller's own preg_last_error().
        preg_match('/^/', '');
        return $ok;
    }
}
