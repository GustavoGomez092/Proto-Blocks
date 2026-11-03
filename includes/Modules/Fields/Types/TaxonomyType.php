<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;

/**
 * taxonomy: list of term IDs. With `save_terms` the save path (Task 5), not
 * this class, also assigns the terms to the object.
 */
final class TaxonomyType implements FieldTypeInterface
{
    public function names(): array
    {
        return ['taxonomy'];
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
        return ['type' => 'array', 'items' => ['type' => 'integer']];
    }

    public function sanitize(mixed $value, Field $field): mixed
    {
        return ImageType::ids($value, (int) $field->setting('max', 0));
    }

    public function normalize(mixed $value, Field $field): mixed
    {
        return ImageType::ids($value, (int) $field->setting('max', 0));
    }

    /** @return list<array{id: int, name: string, slug: string, url: string}> */
    public function escape(mixed $stored, Field $field): mixed
    {
        $taxonomy = $field->setting('taxonomy');
        if (!is_string($taxonomy) || $taxonomy === '') {
            return [];
        }

        $out = [];
        foreach (ImageType::ids($stored, (int) $field->setting('max', 0)) as $id) {
            $term = get_term($id, $taxonomy);
            if (!is_object($term) || !isset($term->taxonomy) || $term->taxonomy !== $taxonomy) {
                continue;
            }
            $link = function_exists('get_term_link') ? get_term_link($term) : '';
            $out[] = [
                'id' => $id,
                'name' => esc_html((string) ($term->name ?? '')),
                'slug' => esc_attr((string) ($term->slug ?? '')),
                'url' => esc_url(is_string($link) ? $link : ''),
            ];
        }

        return $out;
    }

    public function emptyValue(Field $field): mixed
    {
        return [];
    }
}
