<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;

/**
 * image: stored as an attachment ID. The getter returns
 * {id, url, alt, width, height, sizes}, or null when the attachment is gone.
 */
final class ImageType implements FieldTypeInterface
{
    /** Ceiling for any ID list that has no (or an absurd) `max`; same as repeater rows. */
    public const MAX_IDS = 500;

    public function names(): array
    {
        return ['image'];
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
        return ['type' => 'integer'];
    }

    public function sanitize(mixed $value, Field $field): mixed
    {
        return self::id($value);
    }

    public function normalize(mixed $value, Field $field): mixed
    {
        return self::id($value);
    }

    /** @return array<string, mixed>|null */
    public function escape(mixed $stored, Field $field): mixed
    {
        return self::escapeId(self::id($stored));
    }

    public function emptyValue(Field $field): mixed
    {
        return null;
    }

    /**
     * A positive attachment ID from an int, an integer string, an integral
     * float or an {id} array; everything else (negative, zero, junk) is 0.
     */
    public static function id(mixed $value): int
    {
        if (is_array($value)) {
            $value = $value['id'] ?? 0;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 9.0e15) {
            $value = (int) $value;
        }
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }
        if (is_string($value) && preg_match('/\A[0-9]{1,18}\z/D', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * Unique positive IDs in order, at most $limit of them (<= 0 means MAX_IDS).
     *
     * @return list<int>
     */
    public static function ids(mixed $value, int $limit): array
    {
        $limit = $limit > 0 ? min($limit, self::MAX_IDS) : self::MAX_IDS;
        $items = is_array($value) ? $value : [$value];
        $out = [];
        foreach ($items as $item) {
            $id = self::id($item);
            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function escapeId(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $src = wp_get_attachment_image_src($id, 'full');
        if (!is_array($src) || !isset($src[0])) {
            return null;
        }

        $sizes = [];
        $meta = wp_get_attachment_metadata($id);
        $names = is_array($meta) && is_array($meta['sizes'] ?? null) ? array_keys($meta['sizes']) : [];
        foreach ($names as $name) {
            $size = wp_get_attachment_image_src($id, (string) $name);
            if (is_array($size) && isset($size[0])) {
                $sizes[(string) $name] = [
                    'url' => esc_url((string) $size[0]),
                    'width' => (int) ($size[1] ?? 0),
                    'height' => (int) ($size[2] ?? 0),
                ];
            }
        }

        return [
            'id' => $id,
            'url' => esc_url((string) $src[0]),
            'alt' => esc_attr((string) get_post_meta($id, '_wp_attachment_image_alt', true)),
            'width' => (int) ($src[1] ?? 0),
            'height' => (int) ($src[2] ?? 0),
            'sizes' => $sizes,
        ];
    }
}
