<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Storage;

/**
 * Client address resolution, masking and CIDR tests (spec §5).
 *
 * REMOTE_ADDR is the only address trusted by default. A client-IP header is
 * read only when REMOTE_ADDR is one of `log.trustedProxies`, only for the
 * allow-listed names below, in the configured order, and only its last
 * non-empty comma item (the one the trusted proxy appended) counts.
 */
final class IpResolver
{
    /** Header names a trusted proxy may use, lower-case => $_SERVER key. */
    public const HEADERS = [
        'cf-connecting-ip' => 'HTTP_CF_CONNECTING_IP',
        'x-forwarded-for' => 'HTTP_X_FORWARDED_FOR',
        'x-real-ip' => 'HTTP_X_REAL_IP',
        'client-ip' => 'HTTP_CLIENT_IP',
        'true-client-ip' => 'HTTP_TRUE_CLIENT_IP',
    ];

    /**
     * The client address, unmasked, or null when no valid address is known.
     *
     * @param array<string, mixed> $server      $_SERVER-shaped
     * @param array<string, mixed> $logSettings the settings' `log` object
     */
    public static function resolve(array $server, array $logSettings): ?string
    {
        $remote = self::valid($server['REMOTE_ADDR'] ?? null);
        if ($remote === null) {
            return null;
        }
        if (!self::isTrustedProxy($remote, $logSettings['trustedProxies'] ?? [])) {
            return $remote;
        }
        foreach ((array) ($logSettings['ipHeaders'] ?? []) as $name) {
            if (!is_string($name)) {
                continue;
            }
            $key = self::HEADERS[strtolower(trim($name))] ?? null;
            if ($key === null || !is_string($server[$key] ?? null)) {
                continue;
            }
            $ip = self::valid(self::lastItem($server[$key]));
            if ($ip !== null) {
                return $ip;
            }
        }

        return $remote;
    }

    /**
     * The address to store under `log.ip`: `none` (and anything unknown) → null,
     * `masked` → mask(), `full` → as resolved.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $logSettings
     */
    public static function forStorage(array $server, array $logSettings): ?string
    {
        $policy = $logSettings['ip'] ?? 'none';
        if ($policy !== 'masked' && $policy !== 'full') {
            return null;
        }
        $ip = self::resolve($server, $logSettings);
        if ($ip === null) {
            return null;
        }
        if ($policy === 'full') {
            return $ip;
        }
        $masked = self::mask($ip);

        return $masked === '' ? null : $masked;
    }

    /** IPv4: last octet zeroed; IPv6: lower 64 bits zeroed; '' for anything that is not an address. */
    public static function mask(string $ip): string
    {
        $packed = @inet_pton(trim($ip));
        if ($packed === false) {
            return '';
        }
        $keep = strlen($packed) === 4 ? 3 : 8;
        $masked = substr($packed, 0, $keep) . str_repeat("\0", strlen($packed) - $keep);

        return (string) inet_ntop($masked);
    }

    /**
     * Whether $ip lies in $cidr (`addr/prefix`, or a bare address meaning a full-length prefix).
     * Families never mix; anything malformed is false.
     */
    public static function inCidr(string $ip, string $cidr): bool
    {
        $address = @inet_pton(trim($ip));
        $cidr = trim($cidr);
        if ($address === false || $cidr === '') {
            return false;
        }
        $parts = explode('/', $cidr);
        if (count($parts) > 2) {
            return false;
        }
        $network = @inet_pton($parts[0]);
        if ($network === false || strlen($network) !== strlen($address)) {
            return false;
        }
        $bits = strlen($network) * 8;
        if (count($parts) === 2) {
            if (preg_match('/^\d{1,3}\z/D', $parts[1]) !== 1 || (int) $parts[1] > $bits) {
                return false;
            }
            $prefix = (int) $parts[1];
        } else {
            $prefix = $bits;
        }

        $whole = intdiv($prefix, 8);
        if (substr($address, 0, $whole) !== substr($network, 0, $whole)) {
            return false;
        }
        $rest = $prefix % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($address[$whole]) & $mask) === (ord($network[$whole]) & $mask);
    }

    /** @param mixed $list */
    private static function isTrustedProxy(string $remote, mixed $list): bool
    {
        foreach ((array) $list as $entry) {
            if (is_string($entry) && self::inCidr($remote, $entry)) {
                return true;
            }
        }

        return false;
    }

    private static function lastItem(string $value): string
    {
        $items = array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $s): bool => $s !== ''));

        return $items === [] ? '' : $items[count($items) - 1];
    }

    /** The canonical address or null; strips `[v6]:port` and `v4:port` forms. */
    private static function valid(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::\d{1,5})?\z/D', $value, $m) === 1) {
            $value = $m[1];
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d{1,5}\z/D', $value, $m) === 1) {
            $value = $m[1];
        }
        if (filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return (string) inet_ntop((string) inet_pton($value));
    }
}
