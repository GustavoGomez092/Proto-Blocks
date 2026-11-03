<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;

/**
 * Raw HTML/script. Only users with unfiltered_html may write it, so for an
 * untrusted user sanitize() refuses (null) and the caller keeps the previous
 * value. A stored value can only have been written by a trusted user, which is
 * why escape() leaves it as is. Exception: re-typing a field from `text` (or
 * any other type) to `code` makes the values stored under the old type,
 * written by anyone, come back raw.
 */
final class CodeType implements FieldTypeInterface
{
    public function names(): array
    {
        return ['code'];
    }

    public function requiresUnfilteredHtml(): bool
    {
        return true;
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
        return null;
    }

    public function normalize(mixed $value, Field $field): mixed
    {
        return TextType::string($value);
    }

    public function escape(mixed $stored, Field $field): mixed
    {
        return TextType::string($stored);
    }

    public function emptyValue(Field $field): mixed
    {
        return '';
    }
}
