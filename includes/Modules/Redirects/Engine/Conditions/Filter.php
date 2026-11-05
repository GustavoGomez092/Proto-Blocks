<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine\Conditions;

use ProtoBlocks\Modules\Redirects\Engine\Request;

/**
 * `filter`: apply_filters(value, false, $request) is truthy. value names a
 * hook (`^[a-z0-9_-]+$`), never a callable: "PHP decides".
 */
final class Filter implements ConditionInterface
{
    public static function type(): string
    {
        return 'filter';
    }

    public function evaluate(array $when, Request $r): bool
    {
        $hook = is_string($when['value'] ?? null) ? (string) $when['value'] : '';
        if (preg_match('/^[a-z0-9_-]{1,500}$/', $hook) !== 1 || !function_exists('apply_filters')) {
            return false;
        }
        return (bool) apply_filters($hook, false, $r);
    }
}
