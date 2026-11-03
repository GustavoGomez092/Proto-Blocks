<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;

/**
 * relationship: ordered list of post IDs. The `post_type` setting is enforced
 * at escape time; posts the visitor cannot read are never exposed.
 */
final class RelationshipType implements FieldTypeInterface
{
    public function names(): array
    {
        return ['relationship'];
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
        $schema = ['type' => 'array', 'items' => ['type' => 'integer']];
        $max = (int) $field->setting('max', 0);
        $schema['maxItems'] = $max > 0 ? min($max, ImageType::MAX_IDS) : ImageType::MAX_IDS;

        return $schema;
    }

    public function sanitize(mixed $value, Field $field): mixed
    {
        return ImageType::ids($value, (int) $field->setting('max', 0));
    }

    public function normalize(mixed $value, Field $field): mixed
    {
        return ImageType::ids($value, (int) $field->setting('max', 0));
    }

    /** @return list<array{id: int, title: string, url: string, type: string}>|array{id: int, title: string, url: string, type: string}|null */
    public function escape(mixed $stored, Field $field): mixed
    {
        $allowed = array_values(array_filter(
            (array) $field->setting('post_type', []),
            static fn($t): bool => is_string($t) && $t !== '',
        ));

        $out = [];
        foreach (ImageType::ids($stored, (int) $field->setting('max', 0)) as $id) {
            $post = get_post($id);
            if (!is_object($post)) {
                continue;
            }
            $type = (string) ($post->post_type ?? '');
            if ($allowed !== [] && !in_array($type, $allowed, true)) {
                continue;
            }
            if (!is_post_publicly_viewable($post) && !current_user_can('read_post', $id)) {
                continue;
            }
            $link = get_permalink($post);
            $out[] = [
                'id' => $id,
                'title' => esc_html((string) get_the_title($post)),
                'url' => esc_url(is_string($link) ? $link : ''),
                'type' => esc_attr($type),
            ];
        }

        return (int) $field->setting('max', 0) === 1 ? ($out[0] ?? null) : $out;
    }

    public function emptyValue(Field $field): mixed
    {
        return [];
    }
}
