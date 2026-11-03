<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * The commented JSONC a new declaration file starts from.
 *
 * Only `name` (and `slug` when asked) is live; every other key is a
 * commented-out example of a default the developer can change. The folder
 * carries the slug, so it is left out unless passed.
 */
final class DeclarationTemplate
{
    public static function render(string $kind, string $name, ?string $slug = null): string
    {
        if ($kind !== Declaration::TYPE && $kind !== Declaration::TAXONOMY) {
            throw new \InvalidArgumentException('Unknown declaration kind: ' . $kind);
        }

        $live = ['  "name": ' . self::encode($name) . ','];
        if ($slug !== null) {
            $live[] = '  "slug": ' . self::encode($slug) . ',';
        }
        $plural = self::encode(Inflector::plural($name));

        if ($kind === Declaration::TYPE) {
            $lines = [
                '// Proto-Blocks post type declaration.',
                '// The folder name is the post type slug. Every commented line shows a',
                '// default; uncomment and edit it to change that default.',
                '{',
                ...$live,
                '  // "plural": ' . $plural . ',',
                '  // "has_archive": true,',
                '  // "menu_icon": "dashicons-admin-post",',
                '  // "supports": ["title", "editor", "thumbnail", "excerpt", "revisions"],',
                '  // "rewrite": { "with_front": false },',
                '  // "taxonomies": [{ "name": "Category" }],',
                '  "fields": []',
                '}',
            ];
        } else {
            $lines = [
                '// Proto-Blocks taxonomy declaration.',
                '// The folder name is the taxonomy slug. Every commented line shows a',
                '// default; uncomment and edit it to change that default.',
                '{',
                ...$live,
                '  // "plural": ' . $plural . ',',
                '  // "hierarchical": false,',
                '  // "show_admin_column": true,',
                '  // "rewrite": { "with_front": false },',
                '  "post_types": []',
                '}',
            ];
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * A JSON string literal that cannot end the line it sits on: line and
     * paragraph separators are escaped, so a name never turns into code
     * after a `//` comment marker or breaks out of its line.
     */
    private static function encode(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
