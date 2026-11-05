<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine\Conditions;

use ProtoBlocks\Modules\Redirects\Engine\Request;

/** `header`: request header `name` (case-insensitive) equals/matches value; absent → false. */
final class Header implements ConditionInterface
{
    public static function type(): string
    {
        return 'header';
    }

    public function evaluate(array $when, Request $r): bool
    {
        $name = is_string($when['name'] ?? null) ? strtolower(trim((string) $when['name'])) : '';
        if ($name === '') {
            return false;
        }
        return TextMatch::test($when, $r->headers[str_replace('_', '-', $name)] ?? null);
    }
}
