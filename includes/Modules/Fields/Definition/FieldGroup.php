<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Definition;

/**
 * One normalised field group. `raw` is the group array exactly as declared,
 * ACF-only keys included.
 */
final class FieldGroup
{
    /**
     * @param list<Field> $fields
     * @param string $source 'php' | 'type:<slug>' | 'taxonomy:<slug>' | 'file:<slug>'
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly array $fields,
        public readonly Location $location,
        public readonly string $position,
        public readonly string $style,
        public readonly int $menuOrder,
        public readonly string $source,
        public readonly ?string $path,
        public readonly array $raw,
    ) {
    }

    /** A top-level field by name. */
    public function field(string $name): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name && $name !== '') {
                return $field;
            }
        }
        return null;
    }

    /** A field by key at any depth (sub-fields and flexible-content layouts included). */
    public function fieldByKey(string $key): ?Field
    {
        return self::findByKey($this->fields, $key);
    }

    /** @param list<Field> $fields */
    private static function findByKey(array $fields, string $key): ?Field
    {
        foreach ($fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
            $found = self::findByKey($field->subFields, $key);
            if ($found !== null) {
                return $found;
            }
            foreach ($field->layouts as $layout) {
                $found = self::findByKey($layout['subFields'], $key);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * The GroupDef JSON shape shared with the client.
     *
     * @return array{key: string, title: string, position: string, style: string, fields: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'position' => $this->position,
            'style' => $this->style,
            'fields' => array_map(static fn(Field $f): array => $f->toArray(), $this->fields),
        ];
    }
}
