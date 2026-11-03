<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Definition;

/**
 * Structural checks for a raw (ACF-shaped) field group, mirroring
 * schemas/field-group.schema.json. Never throws: every problem is a Report
 * entry. Accepts one group or an ACF Tools export array of groups.
 *
 * Errors: missing_key, missing_title, missing_fields, invalid_field,
 * missing_type, invalid_name, duplicate_name, layouts_not_allowed,
 * layouts_required, sub_fields_required, invalid_condition, invalid_location,
 * invalid_position, invalid_style. Warning: unsupported_location_rule.
 */
final class GroupValidator
{
    public const NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/';

    /** Types that hold no value and may have an empty name. */
    public const LAYOUT_ONLY_TYPES = ['tab', 'accordion', 'message'];

    public const POSITIONS = ['normal', 'side', 'acf_after_title'];

    public const STYLES = ['default', 'seamless'];

    public const OPERATORS = ['==', '!='];

    public static function validate(array $raw, bool $locationImplied): Report
    {
        $report = new Report();

        if ($raw !== [] && array_is_list($raw)) {
            foreach ($raw as $i => $group) {
                self::group(is_array($group) ? $group : null, $locationImplied, "[$i]", $report);
            }
            return $report;
        }

        self::group($raw, $locationImplied, '', $report);
        return $report;
    }

    private static function group(?array $g, bool $implied, string $at, Report $report): void
    {
        $p = static fn(string $k): string => $at === '' ? $k : "$at.$k";

        if ($g === null) {
            $report->addError($at, 'invalid_field', __('A field group must be an object.', 'proto-blocks'));
            return;
        }
        if (!self::nonEmptyString($g['key'] ?? null)) {
            $report->addError($p('key'), 'missing_key', __('The field group has no key.', 'proto-blocks'));
        }
        if (!is_string($g['title'] ?? null)) {
            $report->addError($p('title'), 'missing_title', __('The field group has no title.', 'proto-blocks'));
        }
        if (isset($g['position']) && !in_array($g['position'], self::POSITIONS, true)) {
            $report->addError($p('position'), 'invalid_position', sprintf(
                /* translators: %s: list of positions */
                __('Position must be one of: %s.', 'proto-blocks'),
                implode(', ', self::POSITIONS)
            ));
        }
        if (isset($g['style']) && !in_array($g['style'], self::STYLES, true)) {
            $report->addError($p('style'), 'invalid_style', sprintf(
                /* translators: %s: list of styles */
                __('Style must be one of: %s.', 'proto-blocks'),
                implode(', ', self::STYLES)
            ));
        }
        if (!$implied) {
            self::location($g['location'] ?? [], $p('location'), $report);
        }
        if (!isset($g['fields']) || !is_array($g['fields']) || !array_is_list($g['fields'])) {
            $report->addError($p('fields'), 'missing_fields', __('The field group needs a "fields" list.', 'proto-blocks'));
            return;
        }
        self::fields($g['fields'], $p('fields'), $report);
    }

    private static function location(mixed $location, string $at, Report $report): void
    {
        $invalid = static function (string $path) use ($report): void {
            $report->addError($path, 'invalid_location', __('Location rules must be a list of rule lists, each rule {param, operator: "==" or "!=", value}.', 'proto-blocks'));
        };

        if ($location === [] || $location === null || $location === '') {
            return;
        }
        if (!is_array($location) || !array_is_list($location)) {
            $invalid($at);
            return;
        }
        foreach ($location as $i => $branch) {
            if (!is_array($branch) || !array_is_list($branch)) {
                $invalid("{$at}[$i]");
                continue;
            }
            foreach ($branch as $j => $rule) {
                $path = "{$at}[$i][$j]";
                if (!is_array($rule) || !self::nonEmptyString($rule['param'] ?? null)
                    || !in_array($rule['operator'] ?? null, self::OPERATORS, true)
                    || (isset($rule['value']) && !is_scalar($rule['value']))) {
                    $invalid($path);
                    continue;
                }
                if (!in_array($rule['param'], GroupNormalizer::LOCATION_PARAMS, true)) {
                    $report->addWarning($path . '.param', 'unsupported_location_rule', self::unsupportedMessage($rule['param']));
                }
            }
        }
    }

    public static function unsupportedMessage(string $param): string
    {
        return sprintf(
            /* translators: %s: ACF location rule param */
            __('Location rule "%s" is not supported by Proto-Blocks; this group will not be shown anywhere.', 'proto-blocks'),
            $param
        );
    }

    /** @param list<mixed> $fields */
    private static function fields(array $fields, string $at, Report $report): void
    {
        $seen = [];
        foreach ($fields as $i => $f) {
            $path = "{$at}[$i]";
            if (!is_array($f) || array_is_list($f) && $f !== []) {
                $report->addError($path, 'invalid_field', __('A field must be an object.', 'proto-blocks'));
                continue;
            }
            if (!self::nonEmptyString($f['key'] ?? null)) {
                $report->addError("$path.key", 'missing_key', __('The field has no key.', 'proto-blocks'));
            }
            $type = $f['type'] ?? null;
            if (!self::nonEmptyString($type)) {
                $report->addError("$path.type", 'missing_type', __('The field has no type.', 'proto-blocks'));
                $type = '';
            }

            $name = $f['name'] ?? null;
            $layoutOnly = in_array($type, self::LAYOUT_ONLY_TYPES, true);
            if (!($layoutOnly && ($name === null || $name === ''))) {
                if (!is_string($name) || !preg_match(self::NAME_PATTERN, $name)) {
                    $report->addError("$path.name", 'invalid_name', self::invalidNameMessage(is_scalar($name) ? (string) $name : ''));
                } elseif (isset($seen[$name])) {
                    $report->addError("$path.name", 'duplicate_name', sprintf(
                        /* translators: %s: field name */
                        __('The name "%s" is used by more than one field at this level.', 'proto-blocks'),
                        $name
                    ));
                } else {
                    $seen[$name] = true;
                }
            }

            self::conditions($f['conditional_logic'] ?? null, "$path.conditional_logic", $report);

            if (array_key_exists('layouts', $f) && $type !== 'flexible_content') {
                $report->addError("$path.layouts", 'layouts_not_allowed', sprintf(
                    /* translators: %s: field type */
                    __('Only flexible_content fields have layouts, not "%s".', 'proto-blocks'),
                    (string) $type
                ));
            }

            if ($type === 'repeater' || $type === 'group') {
                $subs = $f['sub_fields'] ?? null;
                if (!is_array($subs) || $subs === [] || !array_is_list($subs)) {
                    $report->addError("$path.sub_fields", 'sub_fields_required', sprintf(
                        /* translators: %s: field type */
                        __('A %s field needs at least one sub-field.', 'proto-blocks'),
                        $type
                    ));
                } else {
                    self::fields($subs, "$path.sub_fields", $report);
                }
            }

            if ($type === 'flexible_content') {
                self::layouts($f['layouts'] ?? null, "$path.layouts", $report);
            }
        }
    }

    private static function layouts(mixed $layouts, string $at, Report $report): void
    {
        if (!is_array($layouts) || $layouts === []) {
            $report->addError($at, 'layouts_required', __('A flexible_content field needs at least one layout.', 'proto-blocks'));
            return;
        }
        $seen = [];
        foreach ($layouts as $k => $layout) {
            $path = is_int($k) ? "{$at}[$k]" : "$at.$k";
            if (!is_array($layout)) {
                $report->addError($path, 'invalid_field', __('A layout must be an object.', 'proto-blocks'));
                continue;
            }
            $name = $layout['name'] ?? null;
            if (!is_string($name) || !preg_match(self::NAME_PATTERN, $name)) {
                $report->addError("$path.name", 'invalid_name', self::invalidNameMessage(is_scalar($name) ? (string) $name : ''));
            } elseif (isset($seen[$name])) {
                $report->addError("$path.name", 'duplicate_name', sprintf(
                    /* translators: %s: layout name */
                    __('The layout name "%s" is used more than once.', 'proto-blocks'),
                    $name
                ));
            } else {
                $seen[$name] = true;
            }
            $subs = $layout['sub_fields'] ?? [];
            if (!is_array($subs) || !array_is_list($subs)) {
                $report->addError("$path.sub_fields", 'invalid_field', __('A layout\'s sub_fields must be a list.', 'proto-blocks'));
                continue;
            }
            self::fields($subs, "$path.sub_fields", $report);
        }
    }

    private static function conditions(mixed $logic, string $at, Report $report): void
    {
        // ACF writes 0 (or false / "") when a field has no conditions.
        if ($logic === null || $logic === 0 || $logic === '0' || $logic === false || $logic === '' || $logic === []) {
            return;
        }
        $ok = is_array($logic) && array_is_list($logic);
        if ($ok) {
            foreach ($logic as $branch) {
                if (!is_array($branch) || !array_is_list($branch)) {
                    $ok = false;
                    break;
                }
                foreach ($branch as $rule) {
                    if (!is_array($rule) || !self::nonEmptyString($rule['field'] ?? null) || !self::nonEmptyString($rule['operator'] ?? null)) {
                        $ok = false;
                        break 2;
                    }
                }
            }
        }
        if (!$ok) {
            $report->addError($at, 'invalid_condition', __('conditional_logic must be 0 or a list of rule lists, each rule {field, operator, value}.', 'proto-blocks'));
        }
    }

    private static function invalidNameMessage(string $name): string
    {
        return sprintf(
            /* translators: %s: field name */
            __('Field name "%s" must start with a letter and contain only letters, digits, "_" or "-" (64 characters at most).', 'proto-blocks'),
            $name
        );
    }

    private static function nonEmptyString(mixed $v): bool
    {
        return is_string($v) && $v !== '';
    }
}
