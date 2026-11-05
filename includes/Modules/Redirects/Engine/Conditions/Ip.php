<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine\Conditions;

use ProtoBlocks\Modules\Redirects\Engine\Request;

/**
 * `ip`: the client IP is in any listed IP or CIDR (IPv4 and IPv6).
 *
 * The client IP comes from a resolver (spec §6 trusted-proxy resolution lives
 * in Track C's IpResolver); the default is REMOTE_ADDR. inCidr() is the one
 * CIDR implementation in the module, usable by Track C.
 */
final class Ip implements ConditionInterface
{
    /** @var (callable(Request): ?string)|null */
    private static $resolver = null;

    public static function type(): string
    {
        return 'ip';
    }

    /** @param (callable(Request): ?string)|null $resolver null restores REMOTE_ADDR */
    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function clientIp(Request $r): string
    {
        $ip = self::$resolver !== null ? (self::$resolver)($r) : $r->remoteAddr;
        return is_string($ip) ? $ip : '';
    }

    public function evaluate(array $when, Request $r): bool
    {
        $ip = self::clientIp($r);
        if ($ip === '') {
            return false;
        }
        foreach ((array) ($when['value'] ?? []) as $entry) {
            if (is_string($entry) && self::inCidr($ip, trim($entry))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether $ip is $cidr (a bare IP is a /32 or /128) or inside it. Mixed
     * families never match, except an IPv4-mapped IPv6 address (::ffff:a.b.c.d)
     * which is compared as its IPv4 form. Malformed input is false.
     */
    public static function inCidr(string $ip, string $cidr): bool
    {
        $ip = self::unmap(trim($ip));
        $cidr = trim($cidr);
        $bits = null;
        if (str_contains($cidr, '/')) {
            [$cidr, $bitText] = explode('/', $cidr, 2);
            if ($bitText === '' || !ctype_digit($bitText)) {
                return false;
            }
            $bits = (int) $bitText;
        }
        $cidr = self::unmap($cidr);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($cidr);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $max = strlen($netBin) * 8;
        $bits ??= $max;
        if ($bits > $max) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }

    private static function unmap(string $ip): string
    {
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m) === 1) {
            return $m[1];
        }
        return $ip;
    }
}
