<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Definition;

/**
 * The module settings (proto-redirects/settings.jsonc, spec section 1),
 * fully defaulted. merge() never fails: a key of the wrong type falls back to
 * its default, numbers are clamped, lists are filtered. SettingsValidator
 * reports the same problems as errors when the file is written.
 */
final class Settings
{
    public const CACHE = [-1, 0, 1, 24, 168];
    public const IP_MODES = ['none', 'masked', 'full'];
    public const IP_HEADERS = ['CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP', 'Client-IP', 'True-Client-IP'];
    public const WWW = ['', 'www', 'nowww'];
    public const QUERY_MODES = ['exact', 'exactOrder', 'ignore', 'pass'];
    public const LOG_MIN = -1;
    public const LOG_MAX = 90;
    public const ALIASES_MAX = 20;
    public const PROXIES_MAX = 100;
    public const POST_TYPE_PATTERN = '/^[a-z0-9_-]{1,20}$/D';
    public const ASSOCIATED_PATTERN = '/^(|\/[^\s<>]*)$/D';
    public const ASSOCIATED_MAX = 100;

    /** @return array<string, mixed> every key with its default */
    public static function defaults(): array
    {
        return [
            'defaults' => ['ignoreCase' => true, 'ignoreTrailingSlash' => true, 'query' => 'exact'],
            'log' => [
                'redirects' => 7,
                'notFound' => 7,
                'ip' => 'none',
                'headers' => false,
                'external' => false,
                'trustedProxies' => [],
                'ipHeaders' => [],
            ],
            'trackHits' => true,
            'cache' => 1,
            'monitor' => [
                'postTypes' => [],
                'group' => 'modified-posts',
                'trash' => false,
                'associated' => '',
            ],
            'headers' => ['site' => [], 'redirect' => []],
            'canonical' => ['https' => false, 'www' => '', 'aliases' => [], 'relocate' => ''],
            'coexist' => false,
        ];
    }

    /**
     * The defaults with every usable key of $fromFile applied.
     *
     * @param array<string|int, mixed> $fromFile
     * @return array<string, mixed>
     */
    public static function merge(array $fromFile): array
    {
        $out = self::defaults();
        $in = static fn(string $section): array => is_array($fromFile[$section] ?? null) ? $fromFile[$section] : [];

        $out['defaults'] = self::flags($in('defaults'), $out['defaults']);

        $log = $in('log');
        foreach (['redirects', 'notFound'] as $key) {
            $days = self::int($log[$key] ?? null);
            if ($days !== null) {
                $out['log'][$key] = max(self::LOG_MIN, min(self::LOG_MAX, $days));
            }
        }
        if (in_array($log['ip'] ?? null, self::IP_MODES, true)) {
            $out['log']['ip'] = $log['ip'];
        }
        foreach (['headers', 'external'] as $key) {
            if (is_bool($log[$key] ?? null)) {
                $out['log'][$key] = $log[$key];
            }
        }
        $proxies = [];
        foreach (self::strings($log['trustedProxies'] ?? null) as $proxy) {
            $proxy = trim($proxy);
            if (Patterns::validIpOrCidr($proxy) && !in_array($proxy, $proxies, true)) {
                $proxies[] = $proxy;
            }
        }
        $out['log']['trustedProxies'] = array_slice($proxies, 0, self::PROXIES_MAX);
        $headers = [];
        foreach (self::strings($log['ipHeaders'] ?? null) as $header) {
            foreach (self::IP_HEADERS as $known) {
                if (strcasecmp($known, trim($header)) === 0 && !in_array($known, $headers, true)) {
                    $headers[] = $known;
                }
            }
        }
        $out['log']['ipHeaders'] = $headers;

        if (is_bool($fromFile['trackHits'] ?? null)) {
            $out['trackHits'] = $fromFile['trackHits'];
        }
        $cache = self::int($fromFile['cache'] ?? null);
        if ($cache !== null && in_array($cache, self::CACHE, true)) {
            $out['cache'] = $cache;
        }

        $monitor = $in('monitor');
        $types = [];
        foreach (self::strings($monitor['postTypes'] ?? null) as $type) {
            if (preg_match(self::POST_TYPE_PATTERN, $type) === 1 && !in_array($type, $types, true)) {
                $types[] = $type;
            }
        }
        $out['monitor']['postTypes'] = $types;
        if (is_string($monitor['group'] ?? null) && preg_match(GroupValidator::SLUG_PATTERN, $monitor['group']) === 1) {
            $out['monitor']['group'] = $monitor['group'];
        }
        if (is_bool($monitor['trash'] ?? null)) {
            $out['monitor']['trash'] = $monitor['trash'];
        }
        $associated = $monitor['associated'] ?? null;
        if (is_string($associated) && strlen($associated) <= self::ASSOCIATED_MAX && preg_match(self::ASSOCIATED_PATTERN, $associated) === 1) {
            $out['monitor']['associated'] = $associated;
        }

        $sent = $in('headers');
        foreach (['site', 'redirect'] as $scope) {
            $map = is_array($sent[$scope] ?? null) ? $sent[$scope] : [];
            foreach ($map as $name => $value) {
                if (Patterns::headerError((string) $name, $value) === null) {
                    $out['headers'][$scope][(string) $name] = $value;
                }
            }
        }

        $canonical = $in('canonical');
        if (is_bool($canonical['https'] ?? null)) {
            $out['canonical']['https'] = $canonical['https'];
        }
        if (in_array($canonical['www'] ?? null, self::WWW, true)) {
            $out['canonical']['www'] = $canonical['www'];
        }
        $aliases = [];
        foreach (self::strings($canonical['aliases'] ?? null) as $alias) {
            $alias = strtolower(trim($alias));
            if (Patterns::validHost($alias) && !in_array($alias, $aliases, true)) {
                $aliases[] = $alias;
            }
        }
        $out['canonical']['aliases'] = array_slice($aliases, 0, self::ALIASES_MAX);
        $relocate = $canonical['relocate'] ?? null;
        if (is_string($relocate) && ($relocate === '' || self::validRelocate($relocate))) {
            $out['canonical']['relocate'] = $relocate;
        }

        if (is_bool($fromFile['coexist'] ?? null)) {
            $out['coexist'] = $fromFile['coexist'];
        }

        return $out;
    }

    /**
     * The three rule flags of $in over $base.
     *
     * @param array<string|int, mixed> $in
     * @param array{ignoreCase: bool, ignoreTrailingSlash: bool, query: string} $base
     * @return array{ignoreCase: bool, ignoreTrailingSlash: bool, query: string}
     */
    public static function flags(array $in, array $base): array
    {
        foreach (['ignoreCase', 'ignoreTrailingSlash'] as $key) {
            if (is_bool($in[$key] ?? null)) {
                $base[$key] = $in[$key];
            }
        }
        if (in_array($in['query'] ?? null, self::QUERY_MODES, true)) {
            $base['query'] = $in['query'];
        }

        return $base;
    }

    /** An absolute http(s) URL with a host and no userinfo, query or fragment. */
    public static function validRelocate(string $url): bool
    {
        if (strlen($url) > 2000 || Patterns::hasControl($url) || str_contains($url, '\\')) {
            return false;
        }
        $parts = Patterns::splitAbsolute($url);
        if ($parts === null || $parts[0] === null || !Patterns::validHost($parts[0])) {
            return false;
        }
        $rest = $parts[1];
        if (str_contains($rest, '?') || str_contains($rest, '#') || preg_match('/\s/', $rest) === 1) {
            return false;
        }

        return $rest === '' || $rest[0] === '/';
    }

    private static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) max(min($value, PHP_INT_MAX), PHP_INT_MIN);
        }

        return null;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
