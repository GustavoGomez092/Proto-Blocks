<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine\Conditions;

use ProtoBlocks\Modules\Redirects\Engine\Request;

/** `login`: the visitor is logged in. */
final class Login implements ConditionInterface
{
    public static function type(): string
    {
        return 'login';
    }

    public function evaluate(array $when, Request $r): bool
    {
        return function_exists('is_user_logged_in') && is_user_logged_in();
    }
}
