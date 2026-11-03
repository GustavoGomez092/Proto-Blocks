<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;

/** gallery: list of attachment IDs (unique, positive, capped by `max`). */
final class GalleryType implements FieldTypeInterface
{
    public function names(): array
    {
        return ['gallery'];
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
        return ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => self::limit($field)];
    }

    public function sanitize(mixed $value, Field $field): mixed
    {
        return ImageType::ids($value, self::limit($field));
    }

    public function normalize(mixed $value, Field $field): mixed
    {
        return ImageType::ids($value, self::limit($field));
    }

    /** @return list<array<string, mixed>> */
    public function escape(mixed $stored, Field $field): mixed
    {
        $out = [];
        foreach (ImageType::ids($stored, self::limit($field)) as $id) {
            $image = ImageType::escapeId($id);
            if ($image !== null) {
                $out[] = $image;
            }
        }

        return $out;
    }

    public function emptyValue(Field $field): mixed
    {
        return [];
    }

    private static function limit(Field $field): int
    {
        $max = (int) $field->setting('max', 0);

        return $max > 0 ? min($max, ImageType::MAX_IDS) : ImageType::MAX_IDS;
    }
}
