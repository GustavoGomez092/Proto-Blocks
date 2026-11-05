<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine\Conditions;

use ProtoBlocks\Modules\Redirects\Engine\Request;

/** `referrer`: the Referer header equals value, or matches the case-insensitive regex. No header → false. */
final class Referrer implements ConditionInterface
{
    public static function type(): string
    {
        return 'referrer';
    }

    public function evaluate(array $when, Request $r): bool
    {
        return TextMatch::test($when, array_key_exists('referer', $r->headers) ? $r->referrer : null);
    }
}
