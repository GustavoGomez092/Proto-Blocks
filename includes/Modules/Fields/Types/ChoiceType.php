<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;

/**
 * select and radio. Value is a string, or list<string> when `multiple`.
 * Membership is checked against the static `choices` keys; with an
 * `optionsSource` the values come from a provider, which Task 4 checks, so
 * here only the text sanitiser applies.
 */
final class ChoiceType implements FieldTypeInterface
{
    public function names(): array
    {
        return ['select', 'radio'];
    }

    public function stores(): bool
    {
        return true;
    }

    public function restSchema(Field $field): array
    {
        $item = ['type' => 'string'];
        $keys = $this->choiceKeys($field);
        if ($keys !== null && !$this->hasSource($field)) {
            $item['enum'] = $keys;
        }

        return $this->multiple($field) ? ['type' => 'array', 'items' => $item] : $item;
    }

    public function sanitize(mixed $value, Field $field): mixed
    {
        $allowed = $this->hasSource($field) ? null : ($this->choiceKeys($field) ?? []);
        $keep = static function (mixed $item) use ($allowed): ?string {
            if (!is_string($item) && !is_int($item)) {
                return null;
            }
            $clean = sanitize_text_field((string) $item);
            if ($allowed === null) {
                return $clean === '' ? null : $clean;
            }

            return in_array($clean, $allowed, true) ? $clean : null;
        };

        if ($this->multiple($field)) {
            $out = [];
            foreach (is_array($value) ? $value : [$value] as $item) {
                $clean = $keep($item);
                if ($clean !== null && !in_array($clean, $out, true)) {
                    $out[] = $clean;
                }
            }

            return $out;
        }

        return $keep($value) ?? '';
    }

    public function normalize(mixed $value, Field $field): mixed
    {
        if ($this->multiple($field)) {
            $out = [];
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ((is_string($item) && $item !== '') || is_int($item)) {
                    $out[] = (string) $item;
                }
            }

            return $out;
        }

        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    public function escape(mixed $stored, Field $field): mixed
    {
        $value = $this->normalize($stored, $field);

        return is_array($value) ? array_map('esc_html', $value) : esc_html($value);
    }

    public function emptyValue(Field $field): mixed
    {
        return $this->multiple($field) ? [] : '';
    }

    private function multiple(Field $field): bool
    {
        $multiple = $field->setting('multiple');

        return $multiple === true || $multiple === 1 || $multiple === '1';
    }

    private function hasSource(Field $field): bool
    {
        $source = $field->setting('optionsSource');

        return !($source === null || $source === '' || $source === [] || $source === false);
    }

    /**
     * Static choice keys as strings (PHP turns numeric-string keys into ints),
     * or null when the field declares no choices.
     *
     * @return list<string>|null
     */
    private function choiceKeys(Field $field): ?array
    {
        $choices = $field->setting('choices');
        if (!is_array($choices) || $choices === []) {
            return null;
        }

        return array_map('strval', array_keys($choices));
    }
}
