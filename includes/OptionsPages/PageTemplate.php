<?php

declare(strict_types=1);

namespace ProtoBlocks\OptionsPages;

/**
 * The commented `page.jsonc` a new options page starts from (CLI, and the
 * schema-file GET for a page that has no file yet).
 *
 * Only `title`, `fields` and the values passed in `$opts` are live; every
 * other key is a commented-out example of its default. The folder carries the
 * slug, so it is left out unless passed.
 */
final class PageTemplate
{
    /**
     * @param array{slug?: string, menu?: string, capability?: string, 'storage-option'?: string} $opts
     */
    public static function render(string $title, array $opts = []): string
    {
        $slug = self::opt($opts, 'slug');
        $menu = self::opt($opts, 'menu');
        $capability = self::opt($opts, 'capability');
        $option = self::opt($opts, 'storage-option');

        $lines = [
            '// Proto-Blocks options page declaration.',
            '// The folder name is the page slug and its admin menu slug. Every commented',
            '// line shows a default; uncomment and edit it to change that default.',
            '{',
            '  "title": ' . self::encode($title) . ',',
        ];
        if ($slug !== null) {
            $lines[] = '  "slug": ' . self::encode($slug) . ',';
        }

        $lines[] = '  // Where the page appears: "top" for a menu item of its own, or the slug of';
        $lines[] = '  // a parent menu such as "options-general.php" or "edit.php?post_type=product".';
        $lines[] = $menu !== null ? '  "menu": ' . self::encode($menu) . ',' : '  // "menu": "top",';
        $lines[] = '  // Who can open the page and save its values.';
        $lines[] = $capability !== null ? '  "capability": ' . self::encode($capability) . ',' : '  // "capability": "manage_options",';
        $lines[] = '  // "menuTitle": ' . self::encode($title) . ',';
        $lines[] = '  // "icon": "dashicons-admin-generic",';
        $lines[] = '  // "position": 80,';
        $lines[] = '  // "autoload": true,';
        $lines[] = '  // Values are stored as one option per field ("<slug>_<field name>"), or';
        $lines[] = '  // all in one array option with { "mode": "array", "option": "<name>" }.';
        $lines[] = $option !== null
            ? '  "storage": { "mode": "array", "option": ' . self::encode($option) . ' },'
            : '  // "storage": { "mode": "fields" },';
        $lines[] = '  // "tabs": [{ "title": "General", "fields": [] }],';
        $lines[] = '  // "children": [{ "slug": "advanced", "title": "Advanced", "fields": [] }],';
        $lines[] = '  "fields": []';
        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    /** @param array<string, mixed> $opts */
    private static function opt(array $opts, string $key): ?string
    {
        $value = $opts[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A JSON string literal that cannot end the line it sits on: newlines and
     * the line and paragraph separators are escaped, so a value never turns
     * into code after a `//` comment marker.
     */
    private static function encode(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
