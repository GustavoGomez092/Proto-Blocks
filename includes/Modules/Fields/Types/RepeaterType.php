<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Fields\Types\RepeaterField;
use ProtoBlocks\Modules\Fields\Definition\Field;

/**
 * repeater: list of rows, each an object of sub-field values. Rows beyond
 * maxRows() are dropped in every path (sanitize, normalize, escape), so the
 * cap holds for trusted users too; Task 4 reports the excess as an error
 * before anything is written on the form path.
 */
final class RepeaterType implements FieldTypeInterface
{
    private TypeRegistry|\Closure $registry;

    /** @param TypeRegistry|\Closure(): TypeRegistry $registry */
    public function __construct(TypeRegistry|\Closure $registry)
    {
        $this->registry = $registry;
    }

    public function names(): array
    {
        return ['repeater'];
    }

    public function requiresUnfilteredHtml(): bool
    {
        return false;
    }

    public function stores(): bool
    {
        return true;
    }

    /** @see GroupType::hasUnfilteredHtml() */
    public function hasUnfilteredHtml(Field $field): bool
    {
        return GroupType::anyUnfiltered(GroupType::registry($this->registry), $field->subFields);
    }

    /** >0 while uncapped() runs. */
    private static int $uncapped = 0;

    /**
     * Runs $fn with the row caps off, so stored rows above a lowered cap are
     * shown, not dropped. Display only (the box payload); never a write path.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function uncapped(callable $fn): mixed
    {
        self::$uncapped++;
        try {
            return $fn();
        } finally {
            self::$uncapped--;
        }
    }

    public static function capped(): bool
    {
        return self::$uncapped === 0;
    }

    /**
     * The row cap: the field's `max` when positive, never above the
     * proto_blocks_repeater_max_items filter (default 500).
     */
    public static function maxRows(Field $field): int
    {
        $max = $field->setting('max');
        $own = is_numeric($max) && (int) $max > 0 ? (int) $max : PHP_INT_MAX;

        return min($own, self::rowBudget($field));
    }

    /**
     * Total rows one top-level field may hold across ALL nesting levels: the
     * proto_blocks_repeater_max_items value (default 500).
     */
    public static function rowBudget(Field $field): int
    {
        $filtered = apply_filters('proto_blocks_repeater_max_items', RepeaterField::DEFAULT_MAX_ITEMS, $field->name, $field->settings);

        return is_numeric($filtered) ? max(0, (int) $filtered) : RepeaterField::DEFAULT_MAX_ITEMS;
    }

    public function restSchema(Field $field): array
    {
        return [
            'type' => 'array',
            'items' => GroupType::objectSchema(GroupType::registry($this->registry), $field->subFields),
            'maxItems' => self::maxRows($field),
        ];
    }

    public function sanitize(mixed $value, Field $field): mixed
    {
        if ($this->hasUnfilteredHtml($field)) {
            return null;
        }
        $budget = self::rowBudget($field);

        return $this->rows($value, $field, 'sanitize', $budget);
    }

    public function normalize(mixed $value, Field $field): mixed
    {
        $budget = self::rowBudget($field);

        return $this->rows($value, $field, 'normalize', $budget);
    }

    public function escape(mixed $stored, Field $field): mixed
    {
        $budget = self::rowBudget($field);

        return $this->rows($stored, $field, 'escape', $budget);
    }

    /**
     * Nested processing that spends from the caller's shared row budget.
     *
     * @param 'sanitize'|'normalize'|'escape' $mode
     * @return list<array<string, mixed>>
     */
    public function processWithBudget(mixed $value, Field $field, string $mode, int &$budget): array
    {
        return $this->rows($value, $field, $mode, $budget);
    }

    public function emptyValue(Field $field): mixed
    {
        return [];
    }

    /**
     * @param 'sanitize'|'normalize'|'escape' $mode
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $value, Field $field, string $mode, int &$budget): array
    {
        if (!is_array($value)) {
            return [];
        }
        $registry = GroupType::registry($this->registry);
        $limit = self::maxRows($field);
        $out = [];
        foreach ($value as $row) {
            if (self::capped() && (count($out) >= $limit || $budget <= 0)) {
                break;
            }
            if (is_array($row)) {
                $budget--;
                $out[] = GroupType::process($registry, $field->subFields, $row, $mode, $budget);
            }
        }

        return $out;
    }
}
