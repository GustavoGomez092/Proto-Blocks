<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;

/** date (Y-m-d), time (H:i:s), datetime (Y-m-d H:i:s). */
final class DateType implements FieldTypeInterface
{
    private const FORMATS = ['date' => 'Y-m-d', 'time' => 'H:i:s', 'datetime' => 'Y-m-d H:i:s'];
    private const REST_FORMATS = ['date' => 'date', 'time' => 'time', 'datetime' => 'date-time'];

    public function names(): array
    {
        return ['date', 'time', 'datetime'];
    }

    public function stores(): bool
    {
        return true;
    }

    public function restSchema(Field $field): array
    {
        return ['type' => 'string', 'format' => self::REST_FORMATS[$field->type] ?? 'date'];
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
        return TextType::string($value);
    }

    public function escape(mixed $stored, Field $field): mixed
    {
        return esc_html(TextType::string($stored));
    }

    public function emptyValue(Field $field): mixed
    {
        return '';
    }
}
