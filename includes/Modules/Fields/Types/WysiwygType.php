<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;

final class WysiwygType implements FieldTypeInterface
{
    public function names(): array
    {
        return ['wysiwyg'];
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
        return wp_kses_post(TextType::string($value));
    }

    public function normalize(mixed $value, Field $field): mixed
    {
        return TextType::string($value);
    }

    /** A trusted user may have stored scripts; output always goes through the post allowlist. */
    public function escape(mixed $stored, Field $field): mixed
    {
        return wp_kses_post(TextType::string($stored));
    }

    public function emptyValue(Field $field): mixed
    {
        return '';
    }
}
