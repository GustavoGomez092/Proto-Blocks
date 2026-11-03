<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;

final class TrueFalseType implements FieldTypeInterface
{
    public function names(): array
    {
        return ['true_false'];
    }

    public function requiresUnfilteredHtml(): bool
    {
        return false;
    }

    public function stores(): bool
    {
        return true;
    }

    public function restSchema(Field $field): array
    {
        return ['type' => 'boolean'];
    }

    public function sanitize(mixed $value, Field $field): mixed
    {
        return $this->normalize($value, $field);
    }

    public function normalize(mixed $value, Field $field): mixed
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return !(is_float($value) && is_nan($value)) && $value != 0;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
    }

    public function escape(mixed $stored, Field $field): mixed
    {
        return $this->normalize($stored, $field);
    }

    public function emptyValue(Field $field): mixed
    {
        return false;
    }
}
