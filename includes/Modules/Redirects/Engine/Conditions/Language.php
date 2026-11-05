<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine\Conditions;

use ProtoBlocks\Modules\Redirects\Engine\Request;

/**
 * `language`: any listed tag appears in Accept-Language, case-insensitive
 * prefix match on subtag boundaries (`en` matches `en-GB`, not `eng`).
 * Tags the browser marks `q=0` ("not acceptable") are ignored.
 */
final class Language implements ConditionInterface
{
    public static function type(): string
    {
        return 'language';
    }

    public function evaluate(array $when, Request $r): bool
    {
        $wanted = [];
        foreach ((array) ($when['value'] ?? []) as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $wanted[] = strtolower(str_replace('_', '-', trim($tag)));
            }
        }
        if ($wanted === []) {
            return false;
        }
        foreach (self::accepted($r->headers['accept-language'] ?? '') as $tag) {
            foreach ($wanted as $want) {
                if ($tag === $want || str_starts_with($tag, $want . '-')) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return list<string> lower-case tags with q > 0 */
    public static function accepted(string $header): array
    {
        $tags = [];
        foreach (explode(',', $header) as $part) {
            $pieces = array_map('trim', explode(';', $part));
            $tag = strtolower(str_replace('_', '-', (string) array_shift($pieces)));
            if ($tag === '' || $tag === '*') {
                continue;
            }
            $q = 1.0;
            foreach ($pieces as $param) {
                if (preg_match('/^q\s*=\s*([0-9.]+)$/i', $param, $m) === 1) {
                    $q = (float) $m[1];
                }
            }
            if ($q > 0) {
                $tags[] = $tag;
            }
        }
        return $tags;
    }
}
