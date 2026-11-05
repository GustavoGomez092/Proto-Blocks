<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects;

use ProtoBlocks\Modules\Redirects\Definition\RuleNormalizer;
use ProtoBlocks\Modules\Redirects\Definition\Settings;

/**
 * Stable JSONC for server-side writes (CLI, monitor; spec section 10): a
 * header comment, two-space indent, one rule per line in the canonical key
 * order of section 1, flags omitted when equal to the group default.
 * serialize(decode(serialize(x))) === serialize(x). Hand-written comments
 * are not preserved (UI writes keep them through jsonc-parser instead).
 */
final class Serializer
{
    public const GROUP_HEADER = [
        '// Managed by Proto-Blocks. Edit by hand or in wp-admin → Proto-Blocks → Redirects.',
        '// Hand-written comments inside the rules array survive UI edits but not CLI or monitor writes.',
    ];
    public const SETTINGS_HEADER = [
        '// Proto-Blocks redirect settings. Edit by hand or in wp-admin → Proto-Blocks → Redirects → Settings.',
        '// Every key is optional. Comments in this file do not survive CLI writes.',
    ];
    public const RULE_KEYS = ['id', 'title', 'enabled', 'from', 'regex', 'to', 'else', 'code', 'action', 'when', 'query', 'ignoreCase', 'ignoreTrailingSlash', 'log', 'headers'];
    public const FLAG_KEYS = ['ignoreCase', 'ignoreTrailingSlash', 'query'];

    /** Settings keys holding free name => value maps (everything else empty by default is a list). */
    private const SETTINGS_MAPS = ['headers.site', 'headers.redirect'];

    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param array<string, mixed>      $group     raw group-file data or a normalised Group (optional `slug`)
     * @param array<string, mixed>|null $inherited the site defaults (settings `defaults`); null = built-in
     */
    public static function group(array $group, ?array $inherited = null): string
    {
        $inherited = Settings::flags($inherited ?? [], RuleNormalizer::BUILTIN_DEFAULTS);
        $effective = Settings::flags(is_array($group['defaults'] ?? null) ? $group['defaults'] : [], $inherited);
        $slug = is_string($group['slug'] ?? null) ? $group['slug'] : '';

        $lines = [...self::GROUP_HEADER, '{'];
        $title = is_string($group['title'] ?? null) && $group['title'] !== '' ? $group['title'] : $slug;
        if ($title !== '') {
            $lines[] = '  "title": ' . self::encode($title) . ',';
        }
        $lines[] = '  "enabled": ' . (($group['enabled'] ?? true) === false ? 'false' : 'true') . ',';
        $lines[] = '  "position": ' . (is_int($group['position'] ?? null) ? $group['position'] : Registry::DEFAULT_POSITION) . ',';
        $defaults = [];
        foreach (self::FLAG_KEYS as $key) {
            if ($effective[$key] !== $inherited[$key]) {
                $defaults[$key] = $effective[$key];
            }
        }
        if ($defaults !== []) {
            $lines[] = '  "defaults": ' . self::inline($defaults) . ',';
        }

        $rules = [];
        foreach (is_array($group['rules'] ?? null) ? $group['rules'] : [] as $rule) {
            if (is_array($rule)) {
                $rules[] = '    ' . self::inline(self::compactRule($rule, $effective, $slug));
            }
        }
        if ($rules === []) {
            $lines[] = '  "rules": []';
        } else {
            $lines[] = '  "rules": [';
            $lines[] = implode(",\n", $rules);
            $lines[] = '  ]';
        }
        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    /**
     * The settings in Settings::defaults() key order; unknown keys are dropped.
     *
     * @param array<string|int, mixed> $settings raw or merged settings
     */
    public static function settings(array $settings): string
    {
        $body = self::settingsObject($settings, Settings::defaults(), '', 0);

        return implode("\n", self::SETTINGS_HEADER) . "\n" . $body . "\n";
    }

    /**
     * A rule with every key equal to its default left out.
     *
     * @param array<string|int, mixed> $raw
     * @param array<string, mixed>     $effective
     * @return array<string, mixed>
     */
    public static function compactRule(array $raw, array $effective, string $slug): array
    {
        $rule = RuleNormalizer::normalize($raw, $effective, $slug);
        $defaultCode = match ($rule['action']) {
            'url' => 301,
            'error' => 404,
            default => null,
        };
        $out = [];
        foreach (self::RULE_KEYS as $key) {
            $value = $rule[$key];
            $omit = match ($key) {
                'id' => false,
                'title' => $value === '',
                'enabled', 'log' => $value === true,
                'regex' => $value === false,
                'from', 'to', 'else', 'when' => $value === null,
                'code' => $value === null || $value === $defaultCode,
                'action' => $value === 'url',
                'query', 'ignoreCase', 'ignoreTrailingSlash' => $value === $effective[$key],
                'headers' => $value === [],
            };
            if ($omit) {
                continue;
            }
            if ($key === 'when') {
                $when = ['type' => $value['type']];
                if ($value['name'] !== null) {
                    $when['name'] = $value['name'];
                }
                if ($value['value'] !== null) {
                    $when['value'] = $value['value'];
                }
                if ($value['regex'] === true) {
                    $when['regex'] = true;
                }
                $value = $when;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string|int, mixed> $in
     * @param array<string, mixed>     $shape the defaults at this level (key order and structure)
     */
    private static function settingsObject(array $in, array $shape, string $path, int $depth): string
    {
        $pad = str_repeat('  ', $depth + 1);
        $lines = [];
        foreach ($shape as $key => $default) {
            if (!array_key_exists($key, $in)) {
                continue;
            }
            $value = $in[$key];
            $here = $path === '' ? $key : $path . '.' . $key;
            if (is_array($default) && $default !== [] && !array_is_list($default) && is_array($value) && ($value === [] || !array_is_list($value))) {
                $text = self::settingsObject($value, $default, $here, $depth + 1);
            } elseif (in_array($here, self::SETTINGS_MAPS, true) && is_array($value)) {
                $text = self::mapObject($value, $depth + 1);
            } else {
                $text = self::inline($value);
            }
            $lines[] = $pad . self::encode((string) $key) . ': ' . $text;
        }
        if ($lines === []) {
            return '{}';
        }

        return "{\n" . implode(",\n", $lines) . "\n" . str_repeat('  ', $depth) . '}';
    }

    /** @param array<string|int, mixed> $map */
    private static function mapObject(array $map, int $depth): string
    {
        if ($map === []) {
            return '{}';
        }
        $pad = str_repeat('  ', $depth + 1);
        $lines = [];
        foreach ($map as $name => $value) {
            $lines[] = $pad . self::encode((string) $name) . ': ' . self::inline($value);
        }

        return "{\n" . implode(",\n", $lines) . "\n" . str_repeat('  ', $depth) . '}';
    }

    /** One-line JSON with a space after `:` and `,` (and inside braces). */
    private static function inline(mixed $value): string
    {
        if (!is_array($value)) {
            return self::encode($value);
        }
        if ($value === []) {
            return '[]';
        }
        if (array_is_list($value)) {
            return '[' . implode(', ', array_map([self::class, 'inline'], $value)) . ']';
        }
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = self::encode((string) $key) . ': ' . self::inline($item);
        }

        return '{ ' . implode(', ', $parts) . ' }';
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode($value, self::JSON);
    }
}
