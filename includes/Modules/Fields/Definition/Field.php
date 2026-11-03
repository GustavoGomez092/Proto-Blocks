<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Definition;

/**
 * One normalised field definition. Built by GroupNormalizer from an
 * ACF-shaped array; every key the plugin does not model lives in `settings`,
 * untouched, so ACF-only options survive a round trip.
 */
final class Field
{
    /**
     * @param list<list<array{field: string, operator: string, value: mixed}>> $conditions
     * @param list<Field> $subFields
     * @param list<array{key: string, name: string, label: string, subFields: list<Field>, min?: int, max?: int}> $layouts
     * @param array<string, mixed> $settings
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $label,
        public readonly string $type,
        public readonly string $declaredType,
        public readonly bool $required,
        public readonly mixed $default,
        public readonly string $instructions,
        public readonly string $placeholder,
        public readonly array $conditions,
        public readonly array $subFields,
        public readonly array $layouts,
        public readonly array $settings,
    ) {
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
    }

    /**
     * The FieldDef JSON shape shared with the client (src/fields/model/types.ts).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $layouts = [];
        foreach ($this->layouts as $layout) {
            $layout['subFields'] = array_map(static fn(Field $f): array => $f->toArray(), $layout['subFields']);
            $layouts[] = $layout;
        }

        return [
            'key' => $this->key,
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'declaredType' => $this->declaredType,
            'required' => $this->required,
            'default' => $this->default,
            'instructions' => $this->instructions,
            'placeholder' => $this->placeholder,
            'conditions' => $this->conditions,
            'subFields' => array_map(static fn(Field $f): array => $f->toArray(), $this->subFields),
            'layouts' => $layouts,
            // Note: an empty settings array JSON-encodes as `[]`; reading a key
            // from it on the client yields undefined, the same as from `{}`.
            'settings' => $this->settings,
        ];
    }
}
