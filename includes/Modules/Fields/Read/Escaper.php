<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Read;

use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;

/**
 * Getter output for one field: the stored value escaped by its type, or the
 * field's default when nothing is stored.
 *
 * Escaping is type-driven (FieldTypeInterface::escape(), which nested types
 * apply to every sub-field): text and dates through esc_html, url through
 * esc_url, wysiwyg through wp_kses_post, media arrays with escaped URLs,
 * colour through the colour sanitiser, code as stored (only users with
 * unfiltered_html can write it). It applies to every stored value whoever
 * wrote it, so a trusted administrator's `javascript:` URL or a value written
 * before the plugin existed is escaped all the same. Results depend on the
 * viewer for some types (relationship, taxonomy), so they are never cached.
 */
final class Escaper
{
    public function __construct(private readonly TypeRegistry $types)
    {
    }

    /** A stored value as the getter returns it. */
    public function stored(Field $field, mixed $stored, bool $raw): mixed
    {
        return $raw ? $stored : $this->types->for($field)->escape($stored, $field);
    }

    /** The value of an unset field: its default_value normalised (and escaped unless raw). */
    public function unset(Field $field, bool $raw): mixed
    {
        $type = $this->types->for($field);
        $value = $field->default === null ? $type->emptyValue($field) : $type->normalize($field->default, $field);

        return $raw ? $value : $type->escape($value, $field);
    }
}
