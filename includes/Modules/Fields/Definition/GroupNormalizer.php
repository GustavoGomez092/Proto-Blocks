<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Definition;

/**
 * Turns a raw ACF-local-JSON-shaped field group into a FieldGroup.
 *
 * normalize() runs GroupValidator first and merges its report, so callers
 * need only this class: a group with any structural error yields `group =>
 * null` (fail closed). On top of the validator it reports `unknown_type`
 * (the field is skipped, the group stays), `location_ignored`,
 * `mixed_location_kinds` and `missing_location`. Bad input never throws; only
 * a missing or corrupt shipped alias map does (see loadAliases()).
 */
final class GroupNormalizer
{
    public const LOCATION_PARAMS = ['post_type', 'post_status', 'post_taxonomy', 'page_template', 'post', 'taxonomy', 'user_role', 'user_form', 'options_page'];

    public const PARAM_KINDS = [
        'post_type' => 'post', 'post_status' => 'post', 'post_taxonomy' => 'post', 'page_template' => 'post', 'post' => 'post',
        'taxonomy' => 'term',
        'user_role' => 'user', 'user_form' => 'user',
        'options_page' => 'option',
    ];

    public const TYPES = [
        'text', 'textarea', 'number', 'email', 'url', 'password', 'wysiwyg', 'image', 'file', 'gallery', 'video', 'link',
        'select', 'radio', 'true_false', 'color', 'date', 'time', 'datetime', 'taxonomy', 'relationship',
        'group', 'repeater', 'flexible_content', 'code', 'tab', 'accordion', 'message',
    ];

    /** Keys modelled by Field; every other key of a raw field goes to `settings`. */
    private const FIELD_KEYS = ['key', 'name', 'label', 'type', 'required', 'default_value', 'instructions', 'placeholder', 'conditional_logic', 'sub_fields', 'layouts'];

    /** @var array<string, string>|null */
    private static ?array $aliases = null;

    /**
     * ACF type => canonical type, from schemas/field-type-aliases.json (memoised).
     *
     * @return array<string, string>
     * @throws \RuntimeException when the shipped alias map is missing or invalid
     */
    public static function aliases(): array
    {
        return self::$aliases ??= self::loadAliases(self::aliasesFile());
    }

    /** The alias map shipped with the plugin (schemas/ must be in the release zip). */
    public static function aliasesFile(): string
    {
        return dirname(__DIR__, 4) . '/schemas/field-type-aliases.json';
    }

    /**
     * Reads and checks an alias map. A missing or malformed map is a packaging
     * bug, never silently an empty map: every ACF alias type would be dropped.
     *
     * @return array<string, string>
     * @throws \RuntimeException
     */
    public static function loadAliases(string $file): array
    {
        $text = is_readable($file) ? file_get_contents($file) : false;
        if ($text === false) {
            throw new \RuntimeException(sprintf('Proto-Blocks: field type alias map %s is missing or unreadable.', $file));
        }
        $data = json_decode($text, true);
        if (!is_array($data) || $data === [] || array_is_list($data)) {
            throw new \RuntimeException(sprintf('Proto-Blocks: field type alias map %s is not a JSON object.', $file));
        }
        foreach ($data as $from => $to) {
            if (!is_string($from) || !is_string($to) || !in_array($to, self::TYPES, true)) {
                throw new \RuntimeException(sprintf('Proto-Blocks: field type alias map %s maps "%s" to an unknown type.', $file, (string) $from));
            }
        }
        return $data;
    }

    /**
     * @param array<string, string>|null $impliedLocation e.g. ['post_type' => 'product']
     * @return array{group: ?FieldGroup, report: Report}
     */
    public static function normalize(array $raw, string $source, ?string $path = null, ?array $impliedLocation = null): array
    {
        $report = GroupValidator::validate($raw, $impliedLocation !== null);
        if (array_is_list($raw) && $raw !== []) {
            $report->addError('', 'expected_group', __('Expected one field group, got a list; use normalizeMany().', 'proto-blocks'));
        }
        if (!$report->ok()) {
            return ['group' => null, 'report' => $report];
        }

        $location = self::location($raw, $impliedLocation, $report);
        $fields = self::fields($raw['fields'], 'fields', $report);

        $group = new FieldGroup(
            key: (string) $raw['key'],
            title: (string) $raw['title'],
            fields: $fields,
            location: $location,
            position: (string) ($raw['position'] ?? 'normal'),
            style: (string) ($raw['style'] ?? 'default'),
            menuOrder: is_numeric($raw['menu_order'] ?? null) ? (int) $raw['menu_order'] : 0,
            source: $source,
            path: $path,
            raw: $raw,
        );

        return ['group' => $group, 'report' => $report];
    }

    /**
     * Accepts one group or an ACF Tools export (a list of groups). Report paths
     * for a list are prefixed with the group's index (`[0].fields[2].name`).
     *
     * @param array<string, string>|null $impliedLocation
     * @return array{groups: list<FieldGroup>, report: Report}
     */
    public static function normalizeMany(array $rawOneOrList, string $source, ?string $path = null, ?array $impliedLocation = null): array
    {
        $report = new Report();
        $groups = [];

        if ($rawOneOrList === [] || !array_is_list($rawOneOrList)) {
            $one = self::normalize($rawOneOrList, $source, $path, $impliedLocation);
            if ($rawOneOrList === []) {
                return ['groups' => [], 'report' => $one['report']];
            }
            return ['groups' => $one['group'] ? [$one['group']] : [], 'report' => $one['report']];
        }

        foreach ($rawOneOrList as $i => $raw) {
            if (!is_array($raw)) {
                $report->addError("[$i]", 'invalid_field', __('A field group must be an object.', 'proto-blocks'));
                continue;
            }
            $one = self::normalize($raw, $source, $path, $impliedLocation);
            foreach ($one['report']->errors as $e) {
                $report->addError(self::prefix($i, $e['path']), $e['code'], $e['message']);
            }
            foreach ($one['report']->warnings as $w) {
                $report->addWarning(self::prefix($i, $w['path']), $w['code'], $w['message']);
            }
            if ($one['group'] !== null) {
                $groups[] = $one['group'];
            }
        }

        return ['groups' => $groups, 'report' => $report];
    }

    private static function prefix(int $i, string $path): string
    {
        return $path === '' ? "[$i]" : "[$i].$path";
    }

    /** @param array<string, string>|null $implied */
    private static function location(array $raw, ?array $implied, Report $report): Location
    {
        $declared = $raw['location'] ?? [];

        if ($implied !== null) {
            if (is_array($declared) && $declared !== []) {
                $report->addWarning('location', 'location_ignored', __('This group\'s location is set by the file it lives in; its own "location" is ignored.', 'proto-blocks'));
            }
            $branch = [];
            foreach ($implied as $param => $value) {
                $branch[] = ['param' => (string) $param, 'operator' => '==', 'value' => (string) $value];
            }
            return new Location($branch === [] ? [] : [$branch], true, []);
        }

        if (!is_array($declared) || $declared === []) {
            $report->addWarning('location', 'missing_location', __('This group has no location rules, so it is not shown anywhere.', 'proto-blocks'));
            return new Location([], false, []);
        }

        $or = [];
        $unsupported = [];
        foreach ($declared as $i => $branch) {
            $and = [];
            $kinds = [];
            foreach ($branch as $rule) {
                $param = (string) $rule['param'];
                $and[] = ['param' => $param, 'operator' => (string) $rule['operator'], 'value' => (string) ($rule['value'] ?? '')];
                if (!in_array($param, self::LOCATION_PARAMS, true)) {
                    // The validator already warned (unsupported_location_rule).
                    $unsupported[$param] = true;
                } else {
                    $kinds[self::PARAM_KINDS[$param]] = true;
                }
            }
            if (count($kinds) > 1) {
                $report->addWarning("location[$i]", 'mixed_location_kinds', sprintf(
                    /* translators: %s: list of object kinds, e.g. "post, user" */
                    __('A location rule set mixes rules for different screens (%s) and can never match.', 'proto-blocks'),
                    implode(', ', array_keys($kinds))
                ));
            }
            $or[] = $and;
        }

        return new Location($or, false, array_keys($unsupported));
    }

    /**
     * @param list<array<string, mixed>> $rawFields already structurally valid
     * @return list<Field>
     */
    private static function fields(array $rawFields, string $at, Report $report): array
    {
        $out = [];
        foreach ($rawFields as $i => $raw) {
            $field = self::field($raw, "{$at}[$i]", $report);
            if ($field !== null) {
                $out[] = $field;
            }
        }
        return $out;
    }

    private static function field(array $raw, string $at, Report $report): ?Field
    {
        $declared = (string) $raw['type'];
        $aliases = self::aliases();
        $type = in_array($declared, self::TYPES, true) ? $declared : ($aliases[$declared] ?? 'unknown');
        $name = is_string($raw['name'] ?? null) ? $raw['name'] : '';

        if ($type === 'unknown') {
            $report->addWarning("$at.type", 'unknown_type', sprintf(
                /* translators: 1: field name, 2: field type */
                __('Field "%1$s" has type "%2$s", which Proto-Blocks does not support; the field is skipped.', 'proto-blocks'),
                $name !== '' ? $name : (string) $raw['key'],
                $declared
            ));
            return null;
        }

        $settings = array_diff_key($raw, array_flip(self::FIELD_KEYS));
        switch ($declared) {
            case 'checkbox':
                $settings['multiple'] = true;
                break;
            case 'post_object':
            case 'page_link':
                if (empty($settings['multiple'])) {
                    $settings['max'] = 1;
                }
                break;
            case 'user':
                $settings['optionsSource'] = 'wp:users';
                break;
        }

        $subFields = [];
        if ($type === 'repeater' || $type === 'group') {
            $subFields = self::fields($raw['sub_fields'], "$at.sub_fields", $report);
        } elseif (array_key_exists('sub_fields', $raw)) {
            $settings['sub_fields'] = $raw['sub_fields'];
        }

        $layouts = [];
        if ($type === 'flexible_content') {
            foreach ($raw['layouts'] as $k => $layout) {
                $path = is_int($k) ? "$at.layouts[$k]" : "$at.layouts.$k";
                $def = [
                    'key' => is_string($layout['key'] ?? null) ? $layout['key'] : (is_string($k) ? $k : (string) $layout['name']),
                    'name' => (string) $layout['name'],
                    'label' => self::str($layout['label'] ?? $layout['name']),
                    'subFields' => self::fields($layout['sub_fields'] ?? [], "$path.sub_fields", $report),
                ];
                foreach (['min', 'max'] as $bound) {
                    if (isset($layout[$bound]) && is_numeric($layout[$bound])) {
                        $def[$bound] = (int) $layout[$bound];
                    }
                }
                $layouts[] = $def;
            }
        }

        return new Field(
            key: (string) $raw['key'],
            name: $name,
            label: self::str($raw['label'] ?? ''),
            type: $type,
            declaredType: $declared,
            required: !empty($raw['required']),
            default: $raw['default_value'] ?? null,
            instructions: self::str($raw['instructions'] ?? ''),
            placeholder: self::str($raw['placeholder'] ?? ''),
            conditions: self::conditions($raw['conditional_logic'] ?? null),
            subFields: $subFields,
            layouts: $layouts,
            settings: $settings,
        );
    }

    /** @return list<list<array{field: string, operator: string, value: mixed}>> */
    private static function conditions(mixed $logic): array
    {
        if (!is_array($logic)) {
            return [];
        }
        $or = [];
        foreach ($logic as $branch) {
            $and = [];
            foreach ($branch as $rule) {
                $and[] = ['field' => (string) $rule['field'], 'operator' => (string) $rule['operator'], 'value' => $rule['value'] ?? null];
            }
            if ($and !== []) {
                $or[] = $and;
            }
        }
        return $or;
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }
}
