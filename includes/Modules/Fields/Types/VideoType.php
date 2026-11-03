<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Security\AttributeSanitizer;

/** video: an attachment ID (int) or an external URL (string), as the block field does. */
final class VideoType implements FieldTypeInterface
{
    public function names(): array
    {
        return ['video'];
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
        return ['type' => ['integer', 'string']];
    }

    public function sanitize(mixed $value, Field $field): mixed
    {
        return $this->shape($value, true);
    }

    public function normalize(mixed $value, Field $field): mixed
    {
        return $this->shape($value, false);
    }

    /** @return array{id?: int, url: string}|null */
    public function escape(mixed $stored, Field $field): mixed
    {
        $shaped = $this->shape($stored, false);
        if (is_int($shaped)) {
            $url = wp_get_attachment_url($shaped);

            return is_string($url) && $url !== '' ? ['id' => $shaped, 'url' => esc_url($url)] : null;
        }
        $url = esc_url($shaped);

        return $url !== '' ? ['url' => $url] : null;
    }

    public function emptyValue(Field $field): mixed
    {
        return '';
    }

    /** int ID, or a URL string ('' when empty). An {id, url} array prefers the ID. */
    private function shape(mixed $value, bool $cleanUrl): int|string
    {
        if (is_array($value)) {
            $id = ImageType::id($value['id'] ?? 0);
            if ($id > 0) {
                return $id;
            }
            $value = $value['url'] ?? '';
        }
        $id = ImageType::id($value);
        if ($id > 0) {
            return $id;
        }
        $url = TextType::string($value);

        return $cleanUrl ? AttributeSanitizer::sanitizeUrl($url) : trim($url);
    }
}
