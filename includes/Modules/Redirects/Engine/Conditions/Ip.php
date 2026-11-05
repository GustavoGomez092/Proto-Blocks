<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine\Conditions;

use ProtoBlocks\Modules\Redirects\Engine\Request;
use ProtoBlocks\Modules\Redirects\Storage\IpResolver;

/**
 * `ip`: the client IP is in any listed IP or CIDR (IPv4 and IPv6).
 *
 * The client IP comes from a resolver (spec §6 trusted-proxy resolution lives
 * in Storage\IpResolver, set by RedirectsModule::boot()); the default is
 * REMOTE_ADDR. CIDR tests delegate to IpResolver::inCidr().
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
     * Whether $ip is $cidr (a bare IP is a /32 or /128) or inside it. A thin
     * delegate: Storage\IpResolver::inCidr() is the module's one CIDR
     * implementation (IPv4-mapped IPv6 compares as IPv4; malformed is false).
     */
    public static function inCidr(string $ip, string $cidr): bool
    {
        return IpResolver::inCidr($ip, $cidr);
    }
}
