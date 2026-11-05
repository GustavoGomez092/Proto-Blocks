<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects;

/**
 * The text a new `redirects` or `redirect-settings` file starts from,
 * served by the schema-files route through SchemaFileWriter::extendKind's
 * `template` option (DeclarationTemplate handles only the Registry kinds).
 */
final class Templates
{
    public static function group(string $slug, string $name = ''): string
    {
        $title = trim(str_replace(['<', '>'], '', $name));

        return Serializer::group([
            'slug' => $slug,
            'title' => $title !== '' ? $title : $slug,
            'enabled' => true,
            'position' => Registry::DEFAULT_POSITION,
            'rules' => [],
        ]);
    }

    public static function settings(): string
    {
        return implode("\n", [
            '// Proto-Blocks redirect settings. Every key is optional; uncomment a line to change its default.',
            '// Reference: docs/redirects.md.',
            '{',
            '  // "defaults": { "ignoreCase": true, "ignoreTrailingSlash": true, "query": "exact" },',
            '  // "log": { "redirects": 7, "notFound": 7, "ip": "none", "headers": false, "external": false, "trustedProxies": [], "ipHeaders": [] },',
            '  // "trackHits": true,',
            '  // "cache": 1,',
            '  // "monitor": { "postTypes": [], "group": "modified-posts", "trash": false, "associated": "" },',
            '  // "headers": { "site": {}, "redirect": {} },',
            '  // "canonical": { "https": false, "www": "", "aliases": [], "relocate": "" },',
            '  // "coexist": false',
            '}',
        ]) . "\n";
    }
}
