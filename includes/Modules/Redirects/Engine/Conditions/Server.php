<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine\Conditions;

use ProtoBlocks\Modules\Redirects\Engine\Request;

/**
 * `server`: the request host equals value, case-insensitive, port ignored.
 * value may be written as a bare host, `host:port` or a URL. Internationalised
 * names compare in their ASCII (punycode) form when ext-intl is present, and
 * by lower-cased Unicode otherwise.
 */
final class Server implements ConditionInterface
{
    public static function type(): string
    {
        return 'server';
    }

    public function evaluate(array $when, Request $r): bool
    {
        $value = is_string($when['value'] ?? null) ? trim((string) $when['value']) : '';
        if ($value === '' || $r->host === '') {
            return false;
        }
        return self::normalise($value) === self::normalise($r->host);
    }

    public static function normalise(string $host): string
    {
        $host = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', trim($host));
        $host = (string) preg_replace('#[/?\#].*$#s', '', $host);
        $at = strrpos($host, '@');
        if ($at !== false) {
            $host = substr($host, $at + 1);
        }
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            $host = $end === false ? $host : substr($host, 0, $end + 1);
        } elseif (substr_count($host, ':') === 1) {
            $host = explode(':', $host, 2)[0];
        }
        $host = rtrim(mb_strtolower($host, 'UTF-8'), '.');
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7E]/', $host) === 1) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }
        return $host;
    }
}
