<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine\Conditions;

use ProtoBlocks\Modules\Redirects\Engine\Request;

/** `404`: WordPress resolved the request to a 404 (only evaluated at template_redirect). */
final class NotFound implements ConditionInterface
{
    public static function type(): string
    {
        return '404';
    }

    public function evaluate(array $when, Request $r): bool
    {
        return function_exists('is_404') && is_404();
    }
}
