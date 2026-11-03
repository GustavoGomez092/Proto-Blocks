<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Security\AttributeSanitizer;

/** text, password, textarea, number, email, url. */
final class TextType implements FieldTypeInterface
{
    public function names(): array
    {
        return ['text', 'password', 'textarea', 'number', 'email', 'url'];
    }

    public function stores(): bool
    {
        return true;
    }

    public function restSchema(Field $field): array
    {
        return match ($field->type) {
            'number' => ['type' => 'number'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'url' => ['type' => 'string', 'format' => 'uri'],
            default => ['type' => 'string'],
        };
    }

    public function sanitize(mixed $value, Field $field): mixed
    {
        if ($field->type === 'number') {
            return $this->number($value, $field);
        }
        // Arrays and objects must never be cast to "Array"; they are empty.
        $string = self::string($value);

        return match ($field->type) {
            'textarea' => sanitize_textarea_field($string),
            'email' => sanitize_email($string),
            'url' => AttributeSanitizer::sanitizeUrl($string),
            default => sanitize_text_field($string),
        };
    }

    public function normalize(mixed $value, Field $field): mixed
    {
        return $field->type === 'number' ? $this->number($value, $field) : self::string($value);
    }

    public function escape(mixed $stored, Field $field): mixed
    {
        if ($field->type === 'number') {
            return $this->number($stored, $field);
        }
        $string = self::string($stored);

        return match ($field->type) {
            'url' => esc_url($string),
            'textarea' => $field->setting('new_lines') === 'br' ? nl2br(esc_html($string)) : esc_html($string),
            default => esc_html($string),
        };
    }

    public function emptyValue(Field $field): mixed
    {
        return $field->type === 'number' ? null : '';
    }

    /** Scalars as a string; anything else (array, object, bool, null) as ''. */
    public static function string(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (string) $value : '';
        }

        return '';
    }

    /**
     * int when `step` is integral, float when it is fractional or "any"; with
     * no step, the number's natural type. '', non-numeric input, INF/NaN and
     * an integer outside the platform range all become null.
     */
    private function number(mixed $value, Field $field): int|float|null
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if (!(is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))) {
            return null;
        }
        $number = $value + 0;
        if (is_float($number) && !is_finite($number)) {
            return null;
        }

        $step = $field->setting('step');
        $unset = $step === null || $step === '';
        $integral = is_numeric($step) && (float) $step != 0.0 && floor((float) $step) == (float) $step;
        if (is_int($number)) {
            return $integral || $unset ? $number : (float) $number;
        }
        if ($integral) {
            // Past 9.2e18 a float no longer fits an int; null beats a wrapped value.
            return abs($number) < 9.2e18 ? (int) round($number) : null;
        }

        return $number;
    }
}
