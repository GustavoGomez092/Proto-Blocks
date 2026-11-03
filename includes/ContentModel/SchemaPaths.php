<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * The directories declaration folders are discovered in.
 *
 * Every root is a realpath, so a theme directory that is itself a symlink
 * resolves to its target, and confinement checks compare realpaths.
 */
final class SchemaPaths
{
    public const FILTER = 'proto_blocks_schema_paths';

    /**
     * Parent theme first, child theme next (it wins on the same slug), then
     * any roots added through the `proto_blocks_schema_paths` filter.
     *
     * @return list<string>
     */
    public static function roots(): array
    {
        $themeRoots = self::themeRoots();
        $filtered = apply_filters(self::FILTER, $themeRoots);

        return self::normalise(is_array($filtered) ? $filtered : $themeRoots);
    }

    /**
     * Roots the UI may write to: the stylesheet (active) and template
     * directories only, never a filter-added root.
     *
     * @return list<string>
     */
    public static function writableRoots(): array
    {
        return self::normalise([get_stylesheet_directory(), get_template_directory()]);
    }

    /** @return list<string> */
    private static function themeRoots(): array
    {
        return self::normalise([get_template_directory(), get_stylesheet_directory()]);
    }

    /**
     * Resolve to realpaths, drop anything that is not an existing directory,
     * and drop duplicates while keeping the first position.
     *
     * @param array<mixed> $paths
     * @return list<string>
     */
    private static function normalise(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $real = realpath($path);
            if ($real === false || !is_dir($real) || in_array($real, $out, true)) {
                continue;
            }
            $out[] = $real;
        }

        return $out;
    }
}
