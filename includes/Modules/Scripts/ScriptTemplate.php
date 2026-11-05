<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Scripts;

/**
 * The commented JSONC a new `proto-scripts/<slug>/script.jsonc` starts from.
 * Only the title, enabled, priority and where are live; the location keys
 * are commented examples of the file form.
 */
final class ScriptTemplate
{
    public static function render(string $title, int $priority = 10): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG;
        $title = str_replace(['<', '>'], '', $title);

        return implode("\n", [
            '// Proto-Blocks script: snippets printed on every front-end page in scope.',
            '// The folder name is the script slug. Snippets live next to this file in',
            '// head.html, body-open.html and body-close.html (or inline as strings).',
            '{',
            '  "title": ' . json_encode($title, $flags) . ',',
            '  "enabled": true,',
            '  // Within each location; lower runs first.',
            '  "priority": ' . $priority . ',',
            '  // "head": { "file": "head.html" },',
            '  // "bodyOpen": { "file": "body-open.html" },',
            '  // "bodyClose": { "file": "body-close.html" },',
            '  "where": {',
            '    // all, front_page, home, search, 404, archive, singular, singular:<type>,',
            '    // post_type:<type>, page:<slug|id>, post:<slug|id>, taxonomy:<tax>,',
            '    // term:<tax>:<slug>, url:/<glob with *>',
            '    "include": ["all"],',
            '    "exclude": []',
            '    // "loggedIn": null, "roles": [], "excludeRoles": ["administrator"]',
            '  }',
            '}',
            '',
        ]);
    }
}
