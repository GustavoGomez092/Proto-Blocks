<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;

/**
 * The refusal sentence for a field only users with unfiltered_html may write
 * (code and password fields, and containers holding one).
 */
final class TrustMessage
{
    public static function for(Field $field): string
    {
        if (in_array($field->type, ['code', 'password'], true)) {
            return __('Only users with unfiltered HTML may edit this field.', 'proto-blocks');
        }
        if (self::contains($field, 'code')) {
            return __('This field contains a code sub-field that only users with unfiltered HTML may edit.', 'proto-blocks');
        }

        return __('This field contains a password sub-field that only users with unfiltered HTML may edit.', 'proto-blocks');
    }

    /** True when the field is, or (at any depth) holds, a field of $type. */
    public static function holds(Field $field, string $type): bool
    {
        return $field->type === $type || self::contains($field, $type);
    }

    private static function contains(Field $field, string $type): bool
    {
        $children = $field->subFields;
        foreach ($field->layouts as $layout) {
            foreach ((array) ($layout['subFields'] ?? []) as $sub) {
                $children[] = $sub;
            }
        }
        foreach ($children as $sub) {
            if ($sub instanceof Field && ($sub->type === $type || self::contains($sub, $type))) {
                return true;
            }
        }

        return false;
    }
}
