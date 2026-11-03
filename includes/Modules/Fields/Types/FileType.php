<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;

/** file: stored as an attachment ID; the getter returns {id, url, filename, mime, size}. */
final class FileType implements FieldTypeInterface
{
    public function names(): array
    {
        return ['file'];
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
        return ['type' => 'integer'];
    }

    public function sanitize(mixed $value, Field $field): mixed
    {
        return ImageType::id($value);
    }

    public function normalize(mixed $value, Field $field): mixed
    {
        return ImageType::id($value);
    }

    /** @return array<string, mixed>|null */
    public function escape(mixed $stored, Field $field): mixed
    {
        $id = ImageType::id($stored);
        $url = $id > 0 ? wp_get_attachment_url($id) : false;
        if (!is_string($url) || $url === '') {
            return null;
        }

        $path = function_exists('get_attached_file') ? get_attached_file($id) : false;
        $size = is_string($path) && is_file($path) ? (int) filesize($path) : 0;

        return [
            'id' => $id,
            'url' => esc_url($url),
            'filename' => esc_html(basename((string) parse_url($url, PHP_URL_PATH))),
            'mime' => esc_attr((string) get_post_mime_type($id)),
            'size' => $size,
        ];
    }

    public function emptyValue(Field $field): mixed
    {
        return null;
    }
}
