<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Validation;

use ProtoBlocks\Modules\Fields\Definition\Field;
use ProtoBlocks\Security\AttributeSanitizer;

/**
 * Server-side value validation for the fields of one scope (a post, an
 * options page, a repeater row). Authoritative counterpart of the client's
 * zod schema; both are pinned by tests/fixtures/fields/validation-cases.json.
 *
 * Never mutates or drops a value: it only reports. Hidden fields (conditional
 * logic) are skipped and listed in `hidden`; a field that fails is absent from
 * `valid` and present in `errors`. Nested paths are dotted: `agenda.1.title`.
 * Flexible content rows are `{layout: name, ...values}`.
 */
final class ValueValidator
{
    /** Types that only lay out the editor and carry no value. */
    private const LAYOUT_ONLY = ['tab', 'accordion', 'message'];

    /** Types whose `min`/`max` settings bound a row/item count. */
    private const COUNTED = ['repeater', 'flexible_content', 'gallery', 'relationship'];

    /**
     * @param list<Field> $fields
     * @param array<string, mixed> $input field name => submitted value
     * @param (callable(Field): ?array)|null $choiceResolver allowed values for a field with `optionsSource`; absent or null result rejects every value (fail closed)
     * @param (callable(Field): ?int)|null $maxRows hard cap on rows for a field (e.g. the proto_blocks_repeater_max_items filter); null = no extra cap
     * @return array{valid: array<string, mixed>, errors: array<string, string>, hidden: list<string>}
     */
    public static function validate(array $fields, array $input, ?callable $choiceResolver = null, ?callable $maxRows = null): array
    {
        $out = ['valid' => [], 'errors' => [], 'hidden' => []];
        self::scope($fields, $input, '', $choiceResolver, $maxRows, $out);
        return $out;
    }

    /**
     * @param list<Field> $fields
     * @param array<string, mixed> $input
     * @param array{valid: array<string, mixed>, errors: array<string, string>, hidden: list<string>} $out
     * @return bool true when nothing in this scope failed
     */
    private static function scope(array $fields, array $input, string $prefix, ?callable $choices, ?callable $maxRows, array &$out): bool
    {
        $byKey = [];
        foreach ($fields as $f) {
            if ($f->name !== '' && !in_array($f->type, self::LAYOUT_ONLY, true)) {
                $byKey[$f->key] = $input[$f->name] ?? null;
            }
        }

        $ok = true;
        foreach ($fields as $f) {
            if ($f->name === '' || in_array($f->type, self::LAYOUT_ONLY, true)) {
                continue;
            }
            $path = $prefix . $f->name;
            if (!ConditionEvaluator::visible($f, $byKey)) {
                $out['hidden'][] = $path;
                continue;
            }

            $before = count($out['errors']);
            $value = $input[$f->name] ?? null;
            $clean = self::field($f, $value, $path, $choices, $maxRows, $out);
            if (count($out['errors']) === $before) {
                // Top-level callers get the map; nested scopes read it back via $clean.
                $out['valid'][$path] = $clean;
            } else {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * Validates one visible field. Records errors keyed by path, returns the
     * value to keep (containers return their cleaned rows).
     *
     * @param array{valid: array<string, mixed>, errors: array<string, string>, hidden: list<string>} $out
     */
    private static function field(Field $f, mixed $value, string $path, ?callable $choices, ?callable $maxRows, array &$out): mixed
    {
        if ($f->type === 'group' && ($value === null || $value === '' || $value === [])) {
            $value = []; // a group always descends so its required sub-fields are enforced
        }
        if ($f->type !== 'group' && ConditionEvaluator::isEmpty($value)) {
            if ($f->required) {
                $out['errors'][$path] = __('This field is required.', 'proto-blocks');
            } elseif (in_array($f->type, self::COUNTED, true)) {
                $error = self::count($f, 0, $maxRows); // e.g. min rows with no rows submitted
                if ($error !== null) {
                    $out['errors'][$path] = $error;
                }
            }
            return $value;
        }

        switch ($f->type) {
            case 'group':
                return self::group($f, $value, $path, $choices, $maxRows, $out);
            case 'repeater':
                return self::repeater($f, $value, $path, $choices, $maxRows, $out);
            case 'flexible_content':
                return self::flexible($f, $value, $path, $choices, $maxRows, $out);
        }

        $error = self::scalar($f, $value, $choices, $maxRows);
        if ($error !== null) {
            $out['errors'][$path] = $error;
        }
        return $value;
    }

    private static function scalar(Field $f, mixed $value, ?callable $choices, ?callable $maxRows): ?string
    {
        switch ($f->type) {
            case 'select':
            case 'radio':
                return self::choice($f, $value, $choices);
            case 'number':
                return self::number($f, $value);
            case 'email':
                if (!is_string($value) || is_email($value) === false) {
                    return __('Enter a valid email address.', 'proto-blocks');
                }
                break;
            case 'url':
                if (!is_string($value) || trim($value) === '' || AttributeSanitizer::sanitizeUrl($value) !== $value) {
                    return __('Enter a valid URL.', 'proto-blocks');
                }
                break;
            case 'date':
            case 'time':
            case 'datetime':
                if (!is_string($value) || !self::dateOk($f->type, $value)) {
                    return __('Enter a valid date.', 'proto-blocks');
                }
                break;
        }

        if (in_array($f->type, self::COUNTED, true)) {
            return self::count($f, is_array($value) ? count($value) : 0, $maxRows);
        }

        if (is_string($value)) {
            $len = mb_strlen($value);
            $max = self::intSetting($f, 'maxlength');
            if ($max !== null && $len > $max) {
                /* translators: %d: maximum number of characters */
                return sprintf(__('Enter at most %d characters.', 'proto-blocks'), $max);
            }
            $min = self::intSetting($f, 'minlength');
            if ($min !== null && $len < $min) {
                /* translators: %d: minimum number of characters */
                return sprintf(__('Enter at least %d characters.', 'proto-blocks'), $min);
            }
            $pattern = $f->setting('pattern');
            if (is_string($pattern) && $pattern !== '') {
                if (ConditionEvaluator::compilePattern($pattern) === null) {
                    // A definition error: never accept a value a broken rule could not check.
                    return __('Invalid pattern rule.', 'proto-blocks');
                }
                // false = no match, null = engine failure on this subject (backtracking, invalid UTF-8): both fail closed.
                if (ConditionEvaluator::matches($pattern, $value) !== true) {
                    return __('The value does not match the required format.', 'proto-blocks');
                }
            }
        }
        return null;
    }

    private static function number(Field $f, mixed $value): ?string
    {
        if (is_bool($value) || (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value)))) {
            return __('The value does not match the required format.', 'proto-blocks');
        }
        $n = $value + 0;
        $min = $f->setting('min');
        if (self::isBound($min) && $n < $min + 0) {
            /* translators: %s: minimum value */
            return sprintf(__('Enter a number no less than %s.', 'proto-blocks'), (string) $min);
        }
        $max = $f->setting('max');
        if (self::isBound($max) && $n > $max + 0) {
            /* translators: %s: maximum value */
            return sprintf(__('Enter a number no greater than %s.', 'proto-blocks'), (string) $max);
        }
        return null;
    }

    private static function choice(Field $f, mixed $value, ?callable $resolver): ?string
    {
        $allowed = null;
        if ($f->setting('optionsSource') !== null) {
            // Dynamic source: the caller must resolve it. Without a resolved list, fail closed.
            $resolved = $resolver !== null ? $resolver($f) : null;
            if (!is_array($resolved)) {
                return __('Invalid choice.', 'proto-blocks');
            }
            $allowed = array_map('strval', array_values($resolved));
        } else {
            $static = $f->setting('choices');
            if (is_array($static) && $static !== []) {
                $allowed = array_map('strval', array_is_list($static) ? $static : array_keys($static));
            }
        }
        if ($allowed === null) {
            return null; // no choices declared at all
        }

        $multiple = !empty($f->setting('multiple'));
        $values = is_array($value) ? $value : [$value];
        if (is_array($value) && !$multiple) {
            return __('Choose one of the listed options.', 'proto-blocks');
        }
        foreach ($values as $v) {
            if (!is_scalar($v) || is_bool($v) || !in_array((string) $v, $allowed, true)) {
                return __('Choose one of the listed options.', 'proto-blocks');
            }
        }
        return null;
    }

    private static function count(Field $f, int $n, ?callable $maxRows): ?string
    {
        $min = self::intSetting($f, 'min');
        if ($min !== null && $n < $min) {
            /* translators: %d: minimum number of rows */
            return sprintf(__('Add at least %d rows.', 'proto-blocks'), $min);
        }
        $max = self::intSetting($f, 'max');
        $cap = $maxRows !== null ? $maxRows($f) : null;
        if (is_int($cap) && $cap > 0) {
            $max = $max === null ? $cap : min($max, $cap);
        }
        if ($max !== null && $n > $max) {
            /* translators: %d: maximum number of rows */
            return sprintf(__('Add no more than %d rows.', 'proto-blocks'), $max);
        }
        return null;
    }

    private static function group(Field $f, mixed $value, string $path, ?callable $choices, ?callable $maxRows, array &$out): mixed
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $out['errors'][$path] = __('The value does not match the required format.', 'proto-blocks');
            return $value;
        }
        return self::row($f->subFields, $value, $path . '.', $choices, $maxRows, $out);
    }

    private static function repeater(Field $f, mixed $value, string $path, ?callable $choices, ?callable $maxRows, array &$out): mixed
    {
        if (!is_array($value) || !array_is_list($value)) {
            $out['errors'][$path] = __('The value does not match the required format.', 'proto-blocks');
            return $value;
        }
        $error = self::count($f, count($value), $maxRows);
        if ($error !== null) {
            $out['errors'][$path] = $error;
            return $value;
        }
        $rows = [];
        foreach ($value as $i => $row) {
            if (!is_array($row)) {
                $out['errors']["$path.$i"] = __('The value does not match the required format.', 'proto-blocks');
                continue;
            }
            $rows[] = self::row($f->subFields, $row, "$path.$i.", $choices, $maxRows, $out);
        }
        return $rows;
    }

    private static function flexible(Field $f, mixed $value, string $path, ?callable $choices, ?callable $maxRows, array &$out): mixed
    {
        if (!is_array($value) || !array_is_list($value)) {
            $out['errors'][$path] = __('The value does not match the required format.', 'proto-blocks');
            return $value;
        }
        $error = self::count($f, count($value), $maxRows);
        if ($error !== null) {
            $out['errors'][$path] = $error;
            return $value;
        }
        $layouts = [];
        foreach ($f->layouts as $layout) {
            $layouts[$layout['name']] = $layout;
        }
        $rows = [];
        foreach ($value as $i => $row) {
            $name = is_array($row) ? ($row['layout'] ?? null) : null;
            if (!is_string($name) || !isset($layouts[$name])) {
                $out['errors']["$path.$i.layout"] = __('Choose one of the listed options.', 'proto-blocks');
                continue;
            }
            $clean = self::row($layouts[$name]['subFields'], $row, "$path.$i.", $choices, $maxRows, $out);
            $rows[] = ['layout' => $name] + $clean;
        }
        return $rows;
    }

    /**
     * Validates one row/group value as its own sibling scope and returns its
     * valid, visible values. Errors and hidden paths are written to $out with
     * the row's prefix; `valid` entries of the nested scope are not leaked
     * into the top-level `valid` map.
     *
     * @param list<Field> $fields
     * @param array<string, mixed> $row
     * @param array{valid: array<string, mixed>, errors: array<string, string>, hidden: list<string>} $out
     * @return array<string, mixed>
     */
    private static function row(array $fields, array $row, string $prefix, ?callable $choices, ?callable $maxRows, array &$out): array
    {
        $nested = ['valid' => [], 'errors' => [], 'hidden' => []];
        self::scope($fields, $row, $prefix, $choices, $maxRows, $nested);
        $out['errors'] += $nested['errors'];
        array_push($out['hidden'], ...$nested['hidden']);

        $clean = [];
        foreach ($nested['valid'] as $path => $v) {
            $clean[substr($path, strlen($prefix))] = $v;
        }
        return $clean;
    }

    private static function dateOk(string $type, string $v): bool
    {
        $formats = match ($type) {
            'date' => ['!Y-m-d'],
            'time' => ['!H:i', '!H:i:s'],
            default => ['!Y-m-d H:i', '!Y-m-d H:i:s', '!Y-m-d\TH:i', '!Y-m-d\TH:i:s'],
        };
        foreach ($formats as $format) {
            $d = \DateTimeImmutable::createFromFormat($format, $v);
            if ($d !== false && $d->format(ltrim($format, '!')) === $v) {
                return true;
            }
        }
        return false;
    }

    private static function isBound(mixed $v): bool
    {
        return (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v)));
    }

    private static function intSetting(Field $f, string $key): ?int
    {
        $v = $f->setting($key);
        return self::isBound($v) && (int) $v > 0 ? (int) $v : null;
    }
}
