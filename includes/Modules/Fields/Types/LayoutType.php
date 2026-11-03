<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;

/** tab, accordion, message: editor presentation only, nothing is stored. */
final class LayoutType implements FieldTypeInterface
{
    public function names(): array
    {
        return ['tab', 'accordion', 'message'];
    }

    public function stores(): bool
    {
        return false;
    }

    public function restSchema(Field $field): array
    {
        return [];
    }

    public function sanitize(mixed $value, Field $field): mixed
    {
        return null;
    }

    public function normalize(mixed $value, Field $field): mixed
    {
        return null;
    }

    public function escape(mixed $stored, Field $field): mixed
    {
        return null;
    }

    public function emptyValue(Field $field): mixed
    {
        return null;
    }
}
