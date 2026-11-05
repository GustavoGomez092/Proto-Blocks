<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine\Conditions;

use ProtoBlocks\Modules\Redirects\Engine\Request;

/** One `when.type` (spec §2). evaluate() receives the rule's normalised `when` array. */
interface ConditionInterface
{
    public static function type(): string;

    /** @param array<string,mixed> $when ['type' => ..., 'value' => string|list, 'name' => ?string, 'regex' => bool] */
    public function evaluate(array $when, Request $r): bool;
}
