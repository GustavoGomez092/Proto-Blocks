<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Export;

use ProtoBlocks\Modules\Redirects\Import\Patterns;

/**
 * Helpers shared by the exporters.
 */
final class Support
{
    public const BEGIN = '# Proto-Blocks redirects';
    public const END = '# End Proto-Blocks redirects';

    /** Characters that make a spreadsheet treat a cell as a formula: ASCII and full-width = + - @, plus tab and CR. */
    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r", "\u{FF1D}", "\u{FF0B}", "\u{FF0D}", "\u{FF20}"];

    /** @param list<array> $groups @return list<array> evaluation order: position, then slug */
    public static function groups(array $groups): array
    {
        $groups = array_values(array_filter($groups, 'is_array'));
        usort($groups, static fn(array $a, array $b): int => [(int) ($a['position'] ?? 10), (string) ($a['slug'] ?? '')]
            <=> [(int) ($b['position'] ?? 10), (string) ($b['slug'] ?? '')]);
        return $groups;
    }

    /** @return list<array> the group's rules by `index` (file order when absent) */
    public static function rules(array $group): array
    {
        $rules = array_values(array_filter(is_array($group['rules'] ?? null) ? $group['rules'] : [], 'is_array'));
        $order = array_keys($rules);
        usort($order, static fn(int $a, int $b): int => [(int) ($rules[$a]['index'] ?? $a), $a] <=> [(int) ($rules[$b]['index'] ?? $b), $b]);
        return array_map(static fn(int $i): array => $rules[$i], $order);
    }

    /** A flag from the rule, else the group defaults, else $default. */
    public static function flag(array $rule, array $group, string $key, mixed $default): mixed
    {
        return $rule[$key] ?? ($group['defaults'][$key] ?? $default);
    }

    public static function active(array $rule, array $group): bool
    {
        return ($group['enabled'] ?? true) !== false && ($rule['enabled'] ?? true) !== false;
    }

    public static function action(array $rule): string
    {
        return (string) ($rule['action'] ?? 'url');
    }

    public static function code(array $rule): ?int
    {
        $action = self::action($rule);
        if ($action === 'pass' || $action === 'nothing') {
            return null;
        }
        return isset($rule['code']) ? (int) $rule['code'] : ($action === 'error' ? 404 : 301);
    }

    /** `[FORMULA] ` before a cell a spreadsheet would evaluate. */
    public static function formula(string $cell): string
    {
        foreach (self::FORMULA_STARTS as $start) {
            if ($cell !== '' && str_starts_with($cell, $start)) {
                return '[FORMULA] ' . $cell;
            }
        }
        return $cell;
    }

    /** RFC 4180 field. */
    public static function csvField(string $cell): string
    {
        if (strpbrk($cell, "\",\r\n") === false) {
            return $cell;
        }
        return '"' . str_replace('"', '""', $cell) . '"';
    }

    /**
     * Why a rule cannot go into a server config file safely, or null: any
     * value with a control character (a line break would inject directives).
     */
    public static function unsafe(array $rule): ?string
    {
        $values = [$rule['from'] ?? '', $rule['to'] ?? '', $rule['else'] ?? ''];
        $when = is_array($rule['when'] ?? null) ? $rule['when'] : [];
        foreach (['value', 'name'] as $k) {
            foreach ((array) ($when[$k] ?? []) as $v) {
                $values[] = $v;
            }
        }
        foreach ($values as $v) {
            if (is_string($v) && Patterns::hasControl($v)) {
                return 'a value contains a control character';
            }
        }
        return null;
    }

    /** Text safe inside a `#` comment line. */
    public static function comment(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));
    }

    /** `# skipped: <id> <from>: <reason>` */
    public static function skipped(array $rule, string $reason): string
    {
        $label = trim(($rule['id'] ?? '') . ' ' . ($rule['from'] ?? '(404)'));
        return '# skipped: ' . self::comment($label) . ': ' . self::comment($reason);
    }

    /** `# <slug>: <title>` */
    public static function groupComment(array $group): string
    {
        $slug = (string) ($group['slug'] ?? '');
        $title = (string) ($group['title'] ?? '');
        return '# ' . self::comment($title !== '' && $title !== $slug ? $slug . ': ' . $title : $slug);
    }

    /** [path, query|null] of a literal source. */
    public static function splitQuery(string $from): array
    {
        $parts = explode('?', $from, 2);
        return [$parts[0], isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null];
    }

    /**
     * The regex body of a literal path: `^` + optional leading slash (Apache)
     * + quoted path + optional trailing slash + `$`.
     */
    public static function literalRegex(string $path, bool $ignoreTrailingSlash, string $lead): string
    {
        $trimmed = ltrim($path, '/');
        if ($ignoreTrailingSlash) {
            $trimmed = rtrim($trimmed, '/');
        }
        $body = Patterns::quote($trimmed);
        if ($trimmed === '') {
            return '^' . $lead . '$';
        }
        return '^' . $lead . $body . ($ignoreTrailingSlash ? '/?' : '') . '$';
    }

    /** The when condition's human name for a skip reason. */
    public static function whenType(array $rule): ?string
    {
        return is_array($rule['when'] ?? null) ? (string) ($rule['when']['type'] ?? '') : null;
    }
}
