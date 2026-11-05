<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine;

/**
 * One front-end request, normalised once (spec §3 "Request normalisation").
 *
 * - control characters and invalid UTF-8 are stripped from REQUEST_URI;
 * - path and query split at the first `?`, a `#` fragment is dropped;
 * - the path is `rawurldecode`d once (so `%2F` behaves as `/`), stripped
 *   again, `//` collapsed and given a leading `/`; the trailing slash is kept;
 * - the query is kept raw and parsed to an array;
 * - host is lower-cased without its port (`port` keeps it), scheme comes
 *   from the URL or `is_ssl()`.
 */
final class Request
{
    /**
     * @param array<string,mixed>  $query
     * @param array<string,string> $headers lower-case name => value
     * @param array<string,string> $cookies
     * @param array<string,mixed>  $server  the server variables the request was built from
     */
    public function __construct(
        public readonly string $path,
        public readonly string $rawQuery,
        public readonly array $query,
        public readonly string $host,
        public readonly string $scheme,
        public readonly string $method,
        public readonly array $headers,
        public readonly array $cookies,
        public readonly string $remoteAddr,
        public readonly string $userAgent,
        public readonly string $referrer,
        public readonly string $uri,
        public readonly ?int $port = null,
        public readonly array $server = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $server = isset($_SERVER) && is_array($_SERVER) ? self::unslash($_SERVER) : [];
        $cookies = isset($_COOKIE) && is_array($_COOKIE) ? self::unslash($_COOKIE) : [];
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $scheme = function_exists('is_ssl') && is_ssl() ? 'https' : 'http';
        return self::build($uri, $scheme, (string) ($server['HTTP_HOST'] ?? ''), $server, $cookies);
    }

    /**
     * A request for $url (absolute or a path). Host and scheme come from an
     * absolute URL, otherwise from $server['HTTP_HOST'] / $server['HTTPS'].
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed> $cookies
     */
    public static function fromUrl(string $url, array $server = [], array $cookies = []): self
    {
        $https = (string) ($server['HTTPS'] ?? '');
        $scheme = $https !== '' && strtolower($https) !== 'off' ? 'https' : 'http';
        $hostHeader = (string) ($server['HTTP_HOST'] ?? '');
        $uri = $url;
        if (preg_match('#^(https?)://([^/?\#]*)(.*)$#is', $url, $m) === 1) {
            $scheme = strtolower($m[1]);
            $authority = $m[2];
            $at = strrpos($authority, '@');
            $hostHeader = $at === false ? $authority : substr($authority, $at + 1);
            $uri = $m[3] === '' ? '/' : $m[3];
        }
        $server['REQUEST_URI'] = $uri;
        $server['HTTP_HOST'] = $hostHeader;
        $server['REQUEST_METHOD'] = $server['REQUEST_METHOD'] ?? 'GET';
        return self::build($uri, $scheme, $hostHeader, $server, $cookies);
    }

    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $cookies
     */
    private static function build(string $uri, string $scheme, string $hostHeader, array $server, array $cookies): self
    {
        $clean = self::clean($uri);
        $hash = strpos($clean, '#');
        if ($hash !== false) {
            $clean = substr($clean, 0, $hash);
        }
        $q = strpos($clean, '?');
        $rawPath = $q === false ? $clean : substr($clean, 0, $q);
        $rawQuery = $q === false ? '' : substr($clean, $q + 1);

        $query = [];
        if ($rawQuery !== '') {
            parse_str($rawQuery, $query);
        }

        [$host, $port] = self::splitHost($hostHeader);

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if (preg_match('/^[A-Z]{1,20}$/', $method) !== 1) {
            $method = 'GET';
        }

        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $name = strtolower(str_replace('_', '-', $key));
            } else {
                continue;
            }
            $headers[$name] = self::clean((string) $value);
        }

        $cookieList = [];
        foreach ($cookies as $name => $value) {
            if (is_scalar($value)) {
                $cookieList[(string) $name] = (string) $value;
            }
        }

        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        if (filter_var($remote, FILTER_VALIDATE_IP) === false) {
            $remote = '';
        }

        return new self(
            path: self::normalisePath($rawPath),
            rawQuery: $rawQuery,
            query: $query,
            host: $host,
            scheme: $scheme === 'https' ? 'https' : 'http',
            method: $method,
            headers: $headers,
            cookies: $cookieList,
            remoteAddr: $remote,
            userAgent: $headers['user-agent'] ?? '',
            referrer: $headers['referer'] ?? '',
            uri: $uri,
            port: $port,
            server: $server,
        );
    }

    /** Decode once, strip, collapse `//`, force a leading `/`. Shared with rule sources. */
    public static function normalisePath(string $rawPath): string
    {
        $path = self::clean(rawurldecode($rawPath));
        $path = (string) preg_replace('#/{2,}#', '/', $path);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        return $path;
    }

    /** Remove invalid UTF-8 sequences and control characters (C0 and DEL). */
    public static function clean(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            // Byte-level scan keeping only well-formed UTF-8 sequences
            // (portable: no iconv //IGNORE differences between libc builds).
            preg_match_all(
                '/[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
                . '|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}/',
                $value,
                $m
            );
            $value = implode('', $m[0]);
        }
        return (string) preg_replace('/[\x00-\x1F\x7F]/', '', $value);
    }

    /** @return array{0: string, 1: ?int} */
    private static function splitHost(string $hostHeader): array
    {
        $hostHeader = strtolower(trim(self::clean($hostHeader)));
        if ($hostHeader === '') {
            return ['', null];
        }
        if ($hostHeader[0] === '[') {
            $end = strpos($hostHeader, ']');
            if ($end === false) {
                return ['', null];
            }
            $rest = substr($hostHeader, $end + 1);
            $port = str_starts_with($rest, ':') && ctype_digit(substr($rest, 1)) ? (int) substr($rest, 1) : null;
            return [substr($hostHeader, 0, $end + 1), $port];
        }
        $port = null;
        if (substr_count($hostHeader, ':') === 1) {
            [$hostHeader, $portText] = explode(':', $hostHeader, 2);
            $port = $portText !== '' && ctype_digit($portText) ? (int) $portText : null;
        }
        return [rtrim($hostHeader, '.'), $port];
    }

    /**
     * Spec §3 exclusions: never matched, never 404-logged. Paths are compared
     * relative to the home path (subdirectory installs) and case-insensitively.
     * Filter `proto_blocks_redirects_excluded` (bool, Request) can exclude more.
     */
    public function isExcluded(): bool
    {
        $excluded = $this->coreExcluded();
        if (function_exists('apply_filters')) {
            $excluded = (bool) apply_filters('proto_blocks_redirects_excluded', $excluded, $this);
        }
        return $excluded;
    }

    /** True for wp-admin, wp-login.php, the REST prefix and ?rest_route= (the paths canonical "relocate" must keep too). */
    public function isProtected(): bool
    {
        return $this->coreExcluded();
    }

    private function coreExcluded(): bool
    {
        if (array_key_exists('rest_route', $this->query)) {
            return true;
        }
        $path = strtolower($this->path);
        if (function_exists('home_url')) {
            $homePath = rtrim(strtolower((string) parse_url((string) home_url(), PHP_URL_PATH)), '/');
            if ($homePath !== '' && ($path === $homePath || str_starts_with($path, $homePath . '/'))) {
                $path = substr($path, strlen($homePath));
                $path = $path === '' ? '/' : $path;
            }
        }
        if ($path === '/wp-admin' || str_starts_with($path, '/wp-admin/')) {
            return true;
        }
        if (in_array($path, ['/wp-login.php', '/wp-cron.php', '/xmlrpc.php'], true)) {
            return true;
        }
        $prefix = function_exists('rest_get_url_prefix') ? trim(strtolower((string) rest_get_url_prefix()), '/') : 'wp-json';
        return $prefix !== '' && ($path === '/' . $prefix || str_starts_with($path, '/' . $prefix . '/'));
    }

    /** The path and raw query as one string: the subject of a regex rule. */
    public function pathAndQuery(): string
    {
        return $this->rawQuery === '' ? $this->path : $this->path . '?' . $this->rawQuery;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function unslash(array $value): array
    {
        return function_exists('wp_unslash') ? (array) wp_unslash($value) : $value;
    }
}
