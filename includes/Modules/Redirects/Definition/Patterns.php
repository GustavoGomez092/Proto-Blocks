<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Definition;

/**
 * Validation-time grammar shared by the rule and settings validators:
 * regex bodies (mirrors Engine\RegexGuard: own delimiter, `s` plus `i` when
 * case-insensitive, `u` when the body is valid UTF-8 and compiles with it,
 * 500-character cap), header names and values, hosts, IPs and CIDRs.
 */
final class Patterns
{
    public const REGEX_MAX = 500;
    public const DELIMITER = '~';
    public const HEADER_NAME = '/^[A-Za-z0-9-]{1,100}$/D';
    public const HEADER_VALUE_MAX = 1000;
    /** Lower-cased names a rule or the settings may never send. */
    public const FORBIDDEN_HEADERS = ['set-cookie', 'location', 'status', 'content-length', 'transfer-encoding'];

    private const ASCII_HOST = '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/Di';
    private const UNICODE_HOST = '/^(?=.{1,253}$)[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?(?:\.[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?)*$/Du';

    /**
     * The delimited pattern for a regex body, or the reason it cannot be used.
     *
     * @return array{0: ?string, 1: ?string} [compiled pattern, error message]
     */
    public static function compile(string $body, bool $ignoreCase): array
    {
        if ($body === '') {
            return [null, __('The pattern is empty.', 'proto-blocks')];
        }
        $utf8 = mb_check_encoding($body, 'UTF-8');
        $length = $utf8 ? mb_strlen($body, 'UTF-8') : strlen($body);
        if ($length > self::REGEX_MAX) {
            return [null, sprintf(
                /* translators: %d: maximum pattern length */
                __('The pattern is longer than %d characters.', 'proto-blocks'),
                self::REGEX_MAX
            )];
        }
        $delimited = self::DELIMITER . self::escapeDelimiter($body) . self::DELIMITER . 's' . ($ignoreCase ? 'i' : '');

        if ($utf8 && self::tryCompile($delimited . 'u') === null) {
            return [$delimited . 'u', null];
        }
        $error = self::tryCompile($delimited);

        return $error === null ? [$delimited, null] : [null, $error];
    }

    /** null when the body compiles, else PHP's message. */
    public static function regexError(string $body, bool $ignoreCase = false): ?string
    {
        return self::compile($body, $ignoreCase)[1];
    }

    /** Whether the body holds an unescaped `^` or `$`. */
    public static function anchored(string $body): bool
    {
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            if ($body[$i] === '\\') {
                $i++;
                continue;
            }
            if ($body[$i] === '^' || $body[$i] === '$') {
                return true;
            }
        }

        return false;
    }

    /** null when the header may be sent, else the reason. */
    public static function headerError(mixed $name, mixed $value): ?string
    {
        if (!is_string($name) || preg_match(self::HEADER_NAME, $name) !== 1) {
            return __('Header names use only letters, digits and "-" (100 characters at most).', 'proto-blocks');
        }
        if (in_array(strtolower($name), self::FORBIDDEN_HEADERS, true)) {
            /* translators: %s: header name */
            return sprintf(__('The %s header cannot be set here.', 'proto-blocks'), $name);
        }
        if (!is_string($value)) {
            return __('A header value must be a string.', 'proto-blocks');
        }
        if (strlen($value) > self::HEADER_VALUE_MAX) {
            /* translators: %d: maximum length */
            return sprintf(__('A header value may not be longer than %d characters.', 'proto-blocks'), self::HEADER_VALUE_MAX);
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return __('A header value may not hold line breaks or control characters.', 'proto-blocks');
        }

        return null;
    }

    /** A bare hostname (no scheme, port, path); $unicode also accepts IDN in Unicode form. */
    public static function validHost(string $host, bool $unicode = false): bool
    {
        return preg_match($unicode ? self::UNICODE_HOST : self::ASCII_HOST, $host) === 1;
    }

    /** An IPv4/IPv6 address or a CIDR range of one. */
    public static function validIpOrCidr(string $value): bool
    {
        $parts = explode('/', $value);
        if (count($parts) > 2) {
            return false;
        }
        $ip = filter_var($parts[0], FILTER_VALIDATE_IP);
        if ($ip === false) {
            return false;
        }
        if (count($parts) === 1) {
            return true;
        }
        $max = str_contains($parts[0], ':') ? 128 : 32;

        return preg_match('/^\d{1,3}$/D', $parts[1]) === 1 && (int) $parts[1] <= $max;
    }

    /** Has control characters (CR, LF, NUL, ...). */
    public static function hasControl(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    /** The lower-cased host of the site (home_url), without port. */
    public static function siteHost(): string
    {
        $host = function_exists('home_url') ? wp_parse_url((string) home_url(), PHP_URL_HOST) : null;

        return is_string($host) ? strtolower($host) : '';
    }

    /**
     * Splits an absolute http(s) URL into its lower-cased host (no port) and
     * the rest from the first `/`, `?` or `#` (never splitting on `\?`).
     * Null when $url is not http(s)://; host null when it carries userinfo.
     *
     * @return array{0: ?string, 1: string}|null
     */
    public static function splitAbsolute(string $url): ?array
    {
        if (preg_match('~^https?://([^/?#\\\\]*)(.*)$~isD', $url, $m) !== 1) {
            return null;
        }
        $authority = $m[1];
        if ($authority === '' || str_contains($authority, '@')) {
            return [null, $m[2]];
        }
        $host = strtolower((string) preg_replace('/:\d*$/D', '', $authority));

        return [$host, $m[2]];
    }

    private static function escapeDelimiter(string $body): string
    {
        $out = '';
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];
            if ($char === '\\') {
                $out .= $char . ($body[$i + 1] ?? '');
                $i++;
                continue;
            }
            $out .= $char === self::DELIMITER ? '\\' . $char : $char;
        }

        return $out;
    }

    private static function tryCompile(string $pattern): ?string
    {
        error_clear_last();
        $result = @preg_match($pattern, '');
        if ($result !== false) {
            return null;
        }
        $last = error_get_last();
        $message = is_array($last) ? (string) $last['message'] : preg_last_error_msg();

        return (string) preg_replace('/^preg_match\(\):\s*/', '', $message);
    }
}
