<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine\Conditions;

use ProtoBlocks\Modules\Redirects\Engine\RegexGuard;

/**
 * Shared comparison for referrer/agent/header/cookie: exact, case-sensitive
 * equality, or (regex: true) a case-insensitive bounded regex. A missing
 * subject (null) is always false.
 */
final class TextMatch
{
    /** @param array<string,mixed> $when */
    public static function test(array $when, ?string $subject): bool
    {
        if ($subject === null) {
            return false;
        }
        $value = $when['value'] ?? '';
        if (!is_string($value)) {
            return false;
        }
        if (!empty($when['regex'])) {
            $compiled = RegexGuard::compile($value, true);
            return $compiled !== null && RegexGuard::match($compiled, $subject) !== null;
        }
        return $subject === $value;
    }
}
