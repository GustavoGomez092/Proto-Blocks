<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine\Conditions;

use ProtoBlocks\Modules\Redirects\Engine\Request;

/** `agent`: the User-Agent equals value, or matches the case-insensitive regex. No header → false. */
final class Agent implements ConditionInterface
{
    public static function type(): string
    {
        return 'agent';
    }

    public function evaluate(array $when, Request $r): bool
    {
        return TextMatch::test($when, array_key_exists('user-agent', $r->headers) ? $r->userAgent : null);
    }
}
