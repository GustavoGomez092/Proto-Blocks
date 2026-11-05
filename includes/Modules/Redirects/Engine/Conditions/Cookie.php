<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine\Conditions;

use ProtoBlocks\Modules\Redirects\Engine\Request;

/** `cookie`: cookie `name` (case-sensitive, as browsers send it) is present and equals/matches value. */
final class Cookie implements ConditionInterface
{
    public static function type(): string
    {
        return 'cookie';
    }

    public function evaluate(array $when, Request $r): bool
    {
        $name = is_string($when['name'] ?? null) ? (string) $when['name'] : '';
        if ($name === '') {
            return false;
        }
        return TextMatch::test($when, $r->cookies[$name] ?? null);
    }
}
