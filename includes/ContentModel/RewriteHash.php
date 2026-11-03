<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * Flushes rewrite rules once when the declared slugs or their rewrite
 * settings change, never on every load.
 *
 * The option holds one entry, `[theme => hash]`, for the active theme only:
 * switching themes (or back again) always differs from what is stored, so
 * the rules are rebuilt for whichever theme's declarations are now live.
 */
final class RewriteHash
{
    public const OPTION = 'proto_blocks_rewrite_hash';

    /** The args that change the generated rewrite rules. */
    private const KEYS = ['rewrite', 'has_archive', 'public', 'query_var', 'hierarchical'];

    /**
     * @param array<string, array<string, mixed>> $postTypes  slug => args
     * @param array<string, array<string, mixed>> $taxonomies slug => args
     */
    public static function compute(array $postTypes, array $taxonomies): string
    {
        $data = [
            'types' => self::pick($postTypes),
            'taxonomies' => self::pick($taxonomies),
        ];

        return sha1((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * Soft-flush and store the hash when it differs from the stored one.
     *
     * @return bool Whether rules were flushed.
     */
    public static function maybeFlush(string $hash, string $themeKey): bool
    {
        $stored = get_option(self::OPTION, []);
        if (is_array($stored) && ($stored[$themeKey] ?? null) === $hash) {
            return false;
        }

        flush_rewrite_rules(false);
        update_option(self::OPTION, [$themeKey => $hash], true);

        return true;
    }

    /**
     * @param array<string, array<string, mixed>> $objects
     * @return array<string, array<string, mixed>>
     */
    private static function pick(array $objects): array
    {
        $out = [];
        foreach ($objects as $slug => $args) {
            $entry = [];
            foreach (self::KEYS as $key) {
                $entry[$key] = self::canonical(is_array($args) ? ($args[$key] ?? null) : null);
            }
            $out[(string) $slug] = $entry;
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /** Sort object keys recursively so key order never changes the hash. */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map([self::class, 'canonical'], $value);
    }
}
