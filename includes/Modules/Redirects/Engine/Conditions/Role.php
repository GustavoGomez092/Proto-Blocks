<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine\Conditions;

use ProtoBlocks\Modules\Redirects\Engine\Request;

/** `role`: current_user_can(value), so a role slug or a capability. */
final class Role implements ConditionInterface
{
    public static function type(): string
    {
        return 'role';
    }

    public function evaluate(array $when, Request $r): bool
    {
        $value = is_string($when['value'] ?? null) ? trim($when['value']) : '';
        return $value !== '' && function_exists('current_user_can') && current_user_can($value);
    }
}
