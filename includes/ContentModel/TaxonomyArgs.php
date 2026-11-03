<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * Turns a taxonomy declaration into register_taxonomy() object types and
 * args, with the same merge rules as PostTypeArgs: `labels` merge, `rewrite`
 * merges when both sides are arrays, every other declared key replaces,
 * PLUGIN_KEYS never reach WordPress, and an invalid PHP declaration yields
 * `schema_invalid` errors and is skipped.
 */
final class TaxonomyArgs
{
    public const PLUGIN_KEYS = ['name', 'plural', 'slug', 'post_types', 'fields', '$schema'];

    /**
     * When `issues` holds an error the taxonomy must not be registered
     * (`args` and `objectTypes` are then empty).
     *
     * @return array{objectTypes: list<string>, args: array<string, mixed>, issues: list<Issue>}
     */
    public static function build(Declaration $d): array
    {
        $issues = PostTypeArgs::schemaIssues($d, '#/$defs/taxonomy');
        if ($issues !== []) {
            return ['objectTypes' => [], 'args' => [], 'issues' => $issues];
        }

        $data = $d->data;
        $name = $d->name() ?? $d->slug;
        $plural = is_string($data['plural'] ?? null) && $data['plural'] !== '' ? $data['plural'] : Inflector::plural($name);

        $args = [
            'labels'            => Labels::taxonomy($name, $plural),
            'public'            => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'hierarchical'      => false,
            'rewrite'           => ['slug' => $d->slug, 'with_front' => false],
        ];

        $postTypes = is_array($data['post_types'] ?? null) ? $data['post_types'] : [];

        return [
            'objectTypes' => array_values(array_unique(array_filter($postTypes, 'is_string'))),
            'args' => self::apply($args, $d),
            'issues' => [],
        ];
    }

    /**
     * Declared keys merged onto someone else's args. When `issues` holds an
     * error the existing args come back unchanged.
     *
     * @param array<string, mixed> $existing
     * @return array{args: array<string, mixed>, issues: list<Issue>}
     */
    public static function override(array $existing, Declaration $d): array
    {
        $issues = PostTypeArgs::schemaIssues($d, '#/$defs/taxonomy');
        if ($issues !== []) {
            return ['args' => $existing, 'issues' => $issues];
        }

        return ['args' => self::apply($existing, $d), 'issues' => []];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private static function apply(array $args, Declaration $d): array
    {
        foreach ($d->data as $key => $value) {
            if (in_array($key, self::PLUGIN_KEYS, true)) {
                continue;
            }
            $args[$key] = match ($key) {
                'labels' => PostTypeArgs::mergeLabels($args['labels'] ?? [], is_array($value) ? $value : []),
                'rewrite' => PostTypeArgs::mergeRewrite($args['rewrite'] ?? null, $value),
                default => $value,
            };
        }

        return $args;
    }
}
