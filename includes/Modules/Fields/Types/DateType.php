<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;

/** date (Y-m-d), time (H:i:s), datetime (Y-m-d H:i:s). */
final class DateType implements FieldTypeInterface
{
    private const FORMATS = ['date' => 'Y-m-d', 'time' => 'H:i:s', 'datetime' => 'Y-m-d H:i:s'];

    public function names(): array
    {
        return ['date', 'time', 'datetime'];
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
        // No `format`: core's date-time check rejects '' and the stored
        // "Y-m-d H:i:s" shape is stricter than any JSON Schema format, so
        // sanitize() is the only enforcement. Empty is a valid value.
        return ['type' => 'string'];
    }

    /** Strict round trip: a date PHP would roll over (2026-02-30) is rejected. */
    public function sanitize(mixed $value, Field $field): mixed
    {
        $format = self::FORMATS[$field->type] ?? 'Y-m-d';
        $string = trim(TextType::string($value));
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $string);

        return $date !== false && $date->format($format) === $string ? $string : '';
    }

    public function normalize(mixed $value, Field $field): mixed
    {
        return self::fromAcf(TextType::string($value), $field);
    }

    public function escape(mixed $stored, Field $field): mixed
    {
        return esc_html(self::fromAcf(TextType::string($stored), $field));
    }

    /**
     * ACF's date picker stores `Ymd` (20261102); a `date` field reads it as
     * Y-m-d. Only a real calendar date converts; anything else is unchanged.
     */
    private static function fromAcf(string $value, Field $field): string
    {
        if ($field->type !== 'date' || preg_match('/\A[0-9]{8}\z/D', $value) !== 1) {
            return $value;
        }
        $date = \DateTimeImmutable::createFromFormat('!Ymd', $value);

        return $date !== false && $date->format('Ymd') === $value ? $date->format('Y-m-d') : $value;
    }

    public function emptyValue(Field $field): mixed
    {
        return '';
    }
}
