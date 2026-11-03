<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;

final class ColorType implements FieldTypeInterface
{
    public function names(): array
    {
        return ['color'];
    }

    public function requiresUnfilteredHtml(): bool
    {
        return false;
    }

    public function hasUnfilteredHtml(Field $field): bool
    {
        return $this->requiresUnfilteredHtml();
    }

    public function stores(): bool
    {
        return true;
    }

    public function restSchema(Field $field): array
    {
        return ['type' => 'string'];
    }

    public function sanitize(mixed $value, Field $field): mixed
    {
        return proto_blocks_sanitize_color(TextType::string($value));
    }

    public function normalize(mixed $value, Field $field): mixed
    {
        return TextType::string($value);
    }

    /** A stored colour ends up in CSS, so it is re-validated on output for every author. */
    public function escape(mixed $stored, Field $field): mixed
    {
        return proto_blocks_sanitize_color(TextType::string($stored));
    }

    public function emptyValue(Field $field): mixed
    {
        return '';
    }
}
