<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * Turns a post type declaration into register_post_type() args.
 *
 * build() applies the spec's defaults (section 2) for a type Proto-Blocks
 * registers; override() merges only the declared keys onto args someone else
 * registers. Declared `labels` merge over the generated / existing ones,
 * `rewrite` merges when both sides are arrays, every other declared key
 * replaces (so a declared `supports` replaces the default list) and unknown
 * keys pass through. PLUGIN_KEYS are Proto-Blocks' own and never reach
 * WordPress.
 *
 * PHP declarations are not schema-checked when they are collected, so they
 * are checked here: an invalid one yields `schema_invalid` errors and is
 * skipped (empty args from build(), the existing args unchanged from
 * override()), exactly as an invalid file is skipped by the loader.
 */
final class PostTypeArgs
{
    public const PLUGIN_KEYS = ['name', 'plural', 'slug', 'taxonomies', 'fields', '$schema'];

    public const DEFAULT_SUPPORTS = ['title', 'editor', 'thumbnail', 'excerpt', 'revisions'];
    public const DEFAULT_MENU_POSITION = 22;

    /**
     * Full args for a type Proto-Blocks registers. When `issues` holds an
     * error the declaration must not be registered (`args` is then empty).
     *
     * @return array{args: array<string, mixed>, issues: list<Issue>}
     */
    public static function build(Declaration $d, string $themeDir, string $themeUrl): array
    {
        $issues = self::schemaIssues($d, '#/$defs/type');
        if ($issues !== []) {
            return ['args' => [], 'issues' => $issues];
        }

        $data = $d->data;
        $name = $d->name() ?? $d->slug;
        $plural = is_string($data['plural'] ?? null) && $data['plural'] !== '' ? $data['plural'] : Inflector::plural($name);

        $args = [
            'labels'          => Labels::postType($name, $plural),
            'public'          => true,
            'show_in_rest'    => true,
            'has_archive'     => true,
            'rewrite'         => ['slug' => $d->slug, 'with_front' => false],
            'supports'        => self::DEFAULT_SUPPORTS,
            'menu_position'   => self::DEFAULT_MENU_POSITION,
            'menu_icon'       => MenuIcon::DEFAULT_ICON,
            'hierarchical'    => false,
            'capability_type' => 'post',
            'taxonomies'      => self::stringTaxonomies($data['taxonomies'] ?? []),
        ];

        return self::apply($args, $d, $themeDir, $themeUrl);
    }

    /**
     * Declared keys merged onto someone else's args (override file). When
     * `issues` holds an error the existing args come back unchanged.
     *
     * @param array<string, mixed> $existing
     * @return array{args: array<string, mixed>, issues: list<Issue>}
     */
    public static function override(array $existing, Declaration $d, string $themeDir, string $themeUrl): array
    {
        $issues = self::schemaIssues($d, '#/$defs/type');
        if ($issues !== []) {
            return ['args' => $existing, 'issues' => $issues];
        }

        return self::apply($existing, $d, $themeDir, $themeUrl);
    }

    /**
     * `schema_invalid` errors for a PHP declaration; files were checked by
     * the loader and return none.
     *
     * @internal Shared with TaxonomyArgs.
     * @return list<Issue>
     */
    public static function schemaIssues(Declaration $d, string $ref): array
    {
        if ($d->origin !== Declaration::ORIGIN_PHP) {
            return [];
        }

        $issues = [];
        foreach (SchemaCheck::validate($d->data, SchemaCheck::schema(), $ref) as $error) {
            $path = $error['path'] === '' ? '/' : $error['path'];
            $issues[] = new Issue(Issue::ERROR, 'schema_invalid', $path . ': ' . $error['message'], $d->source, $d->slug, $d->kind);
        }

        return $issues;
    }

    /**
     * Declared `labels` merged over $base labels (an array or a label object).
     *
     * @internal Shared with TaxonomyArgs.
     * @param array<string, mixed>|object|null $base
     * @param array<string, mixed>             $declared
     * @return array<string, mixed>
     */
    public static function mergeLabels(array|object|null $base, array $declared): array
    {
        return array_merge(is_object($base) ? get_object_vars($base) : (array) $base, $declared);
    }

    /**
     * A declared `rewrite` array merges over an existing array; anything
     * else replaces it.
     *
     * @internal Shared with TaxonomyArgs.
     */
    public static function mergeRewrite(mixed $base, mixed $declared): mixed
    {
        return is_array($base) && is_array($declared) ? array_merge($base, $declared) : $declared;
    }

    /**
     * @param array<string, mixed> $args
     * @return array{args: array<string, mixed>, issues: list<Issue>}
     */
    private static function apply(array $args, Declaration $d, string $themeDir, string $themeUrl): array
    {
        $issues = [];
        foreach ($d->data as $key => $value) {
            if (in_array($key, self::PLUGIN_KEYS, true)) {
                continue;
            }
            switch ($key) {
                case 'labels':
                    $args['labels'] = self::mergeLabels($args['labels'] ?? [], is_array($value) ? $value : []);
                    break;
                case 'rewrite':
                    $args['rewrite'] = self::mergeRewrite($args['rewrite'] ?? null, $value);
                    break;
                case 'menu_icon':
                    $resolved = MenuIcon::resolve(is_string($value) ? $value : '', $themeDir, $themeUrl);
                    $args['menu_icon'] = $resolved['icon'];
                    if ($resolved['warning'] !== null) {
                        $issues[] = self::iconIssue($d, is_string($value) ? $value : '');
                    }
                    break;
                default:
                    $args[$key] = $value;
            }
        }

        return ['args' => $args, 'issues' => $issues];
    }

    /**
     * String entries of a declared `taxonomies` list name existing
     * taxonomies; object entries are inline declarations registered
     * separately.
     *
     * @return list<string>
     */
    private static function stringTaxonomies(mixed $taxonomies): array
    {
        if (!is_array($taxonomies)) {
            return [];
        }

        return array_values(array_filter($taxonomies, 'is_string'));
    }

    private static function iconIssue(Declaration $d, string $value): Issue
    {
        return new Issue(
            Issue::WARNING,
            'icon_invalid',
            sprintf(
                /* translators: %s: declared menu_icon value */
                __('The menu icon "%s" is not a dashicon, an https:// URL or an SVG, PNG, JPG or WebP file inside the theme. The default icon is used.', 'proto-blocks'),
                $value
            ),
            $d->source,
            $d->slug,
            $d->kind
        );
    }
}
