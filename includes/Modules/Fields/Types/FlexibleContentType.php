<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;

/**
 * flexible_content: list of rows `{layout, ...values}`. A row whose `layout`
 * is not a declared layout name is dropped; rows are capped like a repeater's.
 */
final class FlexibleContentType implements FieldTypeInterface
{
    private TypeRegistry|\Closure $registry;

    /** @param TypeRegistry|\Closure(): TypeRegistry $registry */
    public function __construct(TypeRegistry|\Closure $registry)
    {
        $this->registry = $registry;
    }

    public function names(): array
    {
        return ['flexible_content'];
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
        $registry = GroupType::registry($this->registry);
        foreach ($field->layouts as $layout) {
            if (GroupType::anyUnfiltered($registry, $layout['subFields'])) {
                return true;
            }
        }

        return false;
    }

    public function restSchema(Field $field): array
    {
        $registry = GroupType::registry($this->registry);
        $variants = [];
        foreach ($field->layouts as $layout) {
            $schema = GroupType::objectSchema($registry, $layout['subFields']);
            $schema['properties'] = ['layout' => ['type' => 'string', 'const' => $layout['name']]] + $schema['properties'];
            $schema['required'] = ['layout'];
            $variants[] = $schema;
        }

        return [
            'type' => 'array',
            'items' => ['oneOf' => $variants],
            'maxItems' => RepeaterType::maxRows($field),
        ];
    }

    public function sanitize(mixed $value, Field $field): mixed
    {
        if ($this->hasUnfilteredHtml($field)) {
            return null;
        }
        $budget = RepeaterType::rowBudget($field);

        return $this->rows($value, $field, 'sanitize', $budget);
    }

    public function normalize(mixed $value, Field $field): mixed
    {
        $budget = RepeaterType::rowBudget($field);

        return $this->rows($value, $field, 'normalize', $budget);
    }

    public function escape(mixed $stored, Field $field): mixed
    {
        $budget = RepeaterType::rowBudget($field);

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
        $byName = [];
        foreach ($field->layouts as $layout) {
            $byName[$layout['name']] = $layout['subFields'];
        }
        $registry = GroupType::registry($this->registry);
        $limit = RepeaterType::maxRows($field);
        $out = [];
        foreach ($value as $row) {
            if (count($out) >= $limit || $budget <= 0) {
                break;
            }
            if (!is_array($row) || !is_string($row['layout'] ?? null) || !isset($byName[$row['layout']])) {
                continue;
            }
            $name = $row['layout'];
            $budget--;
            $out[] = ['layout' => $name] + GroupType::process($registry, $byName[$name], $row, $mode, $budget);
        }

        return $out;
    }
}
