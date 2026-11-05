<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine;

/**
 * Custom response headers (spec §7). `headers.site` go on every non-excluded
 * front-end response, `headers.redirect` and a rule's own `headers` only with
 * a redirect (the rule wins on the same name, compared case-insensitively).
 * Anything that fails valid() is dropped at send time even if a file slipped
 * past validation.
 */
final class Headers
{
    public const MAX_VALUE = 1000;

    /** Names that would let a file forge cookies, the redirect itself (Location, a meta-refresh Refresh) or the framing. */
    public const FORBIDDEN = \ProtoBlocks\Modules\Redirects\Definition\Patterns::FORBIDDEN_HEADERS;

    /**
     * @param array<string,mixed> $settings
     * @return array<string,string>
     */
    public static function site(array $settings): array
    {
        return self::filter($settings['headers']['site'] ?? []);
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $settings
     * @return array<string,string>
     */
    public static function redirect(array $rule, array $settings): array
    {
        $out = self::filter($settings['headers']['redirect'] ?? []);
        foreach (self::filter($rule['headers'] ?? []) as $name => $value) {
            foreach (array_keys($out) as $existing) {
                if (strcasecmp($existing, $name) === 0) {
                    unset($out[$existing]);
                }
            }
            $out[$name] = $value;
        }
        return $out;
    }

    public static function valid(string $name, string $value): bool
    {
        if (preg_match('/^[A-Za-z0-9-]{1,100}$/', $name) !== 1) {
            return false;
        }
        if (in_array(strtolower($name), self::FORBIDDEN, true)) {
            return false;
        }
        if (strlen($value) > self::MAX_VALUE) {
            return false;
        }
        return preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }

    /**
     * @param mixed $map
     * @return array<string,string>
     */
    private static function filter(mixed $map): array
    {
        $out = [];
        if (!is_array($map)) {
            return $out;
        }
        foreach ($map as $name => $value) {
            if (!is_string($name) || !(is_string($value) || is_int($value) || is_float($value))) {
                continue;
            }
            $value = (string) $value;
            if (self::valid($name, $value)) {
                $out[$name] = $value;
            }
        }
        return $out;
    }
}
