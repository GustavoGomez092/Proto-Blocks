<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Types;

use ProtoBlocks\Modules\Fields\Definition\Field;

/**
 * group: one object of sub-field values. Sub-values are processed by their own
 * type through the registry; keys that are not sub-field names are dropped.
 *
 * The trust flag is the choice of method: sanitize() is the untrusted path and
 * leaves out any sub-field that requiresUnfilteredHtml() (a code field); normalize()
 * keeps every sub-value (shape only). The static helpers below are shared by
 * RepeaterType and FlexibleContentType.
 */
final class GroupType implements FieldTypeInterface
{
    private TypeRegistry|\Closure $registry;

    /** @param TypeRegistry|\Closure(): TypeRegistry $registry a closure lets TypeRegistry::withCoreTypes() hand itself in lazily */
    public function __construct(TypeRegistry|\Closure $registry)
    {
        $this->registry = $registry;
    }

    public function names(): array
    {
        return ['group'];
    }

    public function requiresUnfilteredHtml(): bool
    {
        // Per field: see hasUnfilteredHtml(). A group as a whole is writable;
        // its code sub-fields are dropped from an untrusted write.
        return false;
    }

    public function stores(): bool
    {
        return true;
    }

    /** True when a code field sits anywhere below this field; the save path must then keep the previous code values for untrusted users. */
    public function hasUnfilteredHtml(Field $field): bool
    {
        return self::anyUnfiltered(self::registry($this->registry), $field->subFields);
    }

    public function restSchema(Field $field): array
    {
        return self::objectSchema(self::registry($this->registry), $field->subFields);
    }

    public function sanitize(mixed $value, Field $field): mixed
    {
        return self::process(self::registry($this->registry), $field->subFields, $value, 'sanitize');
    }

    public function normalize(mixed $value, Field $field): mixed
    {
        return self::process(self::registry($this->registry), $field->subFields, $value, 'normalize');
    }

    public function escape(mixed $stored, Field $field): mixed
    {
        return self::process(self::registry($this->registry), $field->subFields, $stored, 'escape');
    }

    public function emptyValue(Field $field): mixed
    {
        $registry = self::registry($this->registry);
        $out = [];
        foreach ($field->subFields as $sub) {
            $type = self::typeFor($registry, $sub);
            if ($type !== null && $type->stores()) {
                $out[$sub->name] = $type->emptyValue($sub);
            }
        }

        return $out;
    }

    public static function registry(TypeRegistry|\Closure $registry): TypeRegistry
    {
        return $registry instanceof \Closure ? $registry() : $registry;
    }

    /** The type for a storing, named sub-field; null for layout-only, unnamed or unregistered ones. */
    public static function typeFor(TypeRegistry $registry, Field $sub): ?FieldTypeInterface
    {
        if ($sub->name === '') {
            return null;
        }
        try {
            $type = $registry->for($sub);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $type->stores() ? $type : null;
    }

    /**
     * One object of values by sub-field, in definition order.
     *
     * @param list<Field> $subFields
     * @param 'sanitize'|'normalize'|'escape' $mode
     * @return array<string, mixed>
     */
    public static function process(TypeRegistry $registry, array $subFields, mixed $input, string $mode): array
    {
        $input = is_array($input) ? $input : [];
        $out = [];
        foreach ($subFields as $sub) {
            $type = self::typeFor($registry, $sub);
            if ($type === null) {
                continue;
            }
            $has = array_key_exists($sub->name, $input);
            if ($mode === 'escape') {
                $out[$sub->name] = $type->escape($has ? $input[$sub->name] : $type->emptyValue($sub), $sub);
            } elseif ($mode === 'normalize') {
                $out[$sub->name] = $type->normalize($has ? $input[$sub->name] : null, $sub);
            } elseif (!$type->requiresUnfilteredHtml()) {
                $out[$sub->name] = $type->sanitize($has ? $input[$sub->name] : null, $sub);
            }
        }

        return $out;
    }

    /** @param list<Field> $subFields */
    public static function anyUnfiltered(TypeRegistry $registry, array $subFields): bool
    {
        foreach ($subFields as $sub) {
            $type = self::typeFor($registry, $sub);
            if ($type === null) {
                continue;
            }
            if ($type->requiresUnfilteredHtml()) {
                return true;
            }
            if ($type instanceof GroupType || $type instanceof RepeaterType || $type instanceof FlexibleContentType) {
                if ($type->hasUnfilteredHtml($sub)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<Field> $subFields
     * @return array<string, mixed>
     */
    public static function objectSchema(TypeRegistry $registry, array $subFields): array
    {
        $properties = [];
        foreach ($subFields as $sub) {
            $type = self::typeFor($registry, $sub);
            if ($type !== null) {
                $properties[$sub->name] = $type->restSchema($sub);
            }
        }

        return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
    }
}
