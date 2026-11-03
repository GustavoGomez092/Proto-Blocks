<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * Content-aware warnings for declaration saves: a valid file can still hide
 * content that exists (removed supports, unregistered taxonomies, renamed
 * fields). Nothing here blocks a save; the caller shows the warnings and asks.
 */
final class Guardrails
{
    public const GUARDED_SUPPORTS = ['title', 'editor', 'thumbnail'];

    /** The plugin's default `supports` when a declaration leaves it out. */
    private const DEFAULT_SUPPORTS = ['title', 'editor', 'thumbnail', 'excerpt', 'revisions'];

    /**
     * @param \Closure      $postCount          fn(string $postType): int
     * @param \Closure      $termCount          fn(string $taxonomy): int
     * @param \Closure|null $registeredSupports fn(string $postType): ?list<string>, the
     *                                          features the registered type supports now (null when
     *                                          the type is not registered)
     */
    public function __construct(
        private readonly \Closure $postCount,
        private readonly \Closure $termCount,
        private readonly ?\Closure $registeredSupports = null
    ) {
    }

    /**
     * A declaration without `supports` leaves the registered type's
     * supports as they are (an override) or gets the plugin defaults it was
     * registered with, so both sides of the comparison read the registered
     * supports when the key is absent. A new declaration of a registered
     * type (an override file being created) starts from the registered
     * object.
     *
     * @param array<string, mixed>|null $before Current declaration data; null for a new declaration.
     * @param array<string, mixed>      $after  Data about to be saved.
     * @return list<array{code: string, message: string, count: int, detail: list<string>}>
     */
    public function checkSave(string $kind, string $slug, ?array $before, array $after): array
    {
        if ($kind === Declaration::OPTIONS) {
            return []; // a settings page holds no posts or terms
        }
        $isType = $kind === Declaration::TYPE;
        $registered = $isType ? $this->registeredSupports($slug) : null;
        if ($before === null) {
            if ($registered === null) {
                return [];
            }
            $before = ['supports' => $registered];
        }

        $issues = [];
        $count = null;
        $contentCount = function () use (&$count, $isType, $slug): int {
            return $count ??= $isType ? $this->posts($slug) : $this->terms($slug);
        };

        if ($isType) {
            $removed = array_values(array_diff(
                array_intersect(self::GUARDED_SUPPORTS, self::supports($before, $registered)),
                self::supports($after, $registered)
            ));
            if ($removed !== [] && $contentCount() > 0) {
                $issues[] = [
                    'code' => 'supports_removed',
                    'message' => sprintf(
                        /* translators: 1: removed features, 2: number of posts, 3: post type slug */
                        __('Removing %1$s hides it on %2$d existing %3$s posts. Their content is kept.', 'proto-blocks'),
                        implode(', ', $removed),
                        $contentCount(),
                        $slug
                    ),
                    'count' => $contentCount(),
                    'detail' => $removed,
                ];
            }

            $remaining = self::inlineTaxonomies($after);
            $lost = [];
            $terms = 0;
            foreach (self::inlineTaxonomies($before) as $taxonomy) {
                if (in_array($taxonomy, $remaining, true)) {
                    continue;
                }
                $n = $this->terms($taxonomy);
                if ($n > 0) {
                    $lost[] = $taxonomy;
                    $terms += $n;
                }
            }
            if ($lost !== []) {
                $issues[] = [
                    'code' => 'taxonomy_removed',
                    'message' => sprintf(
                        /* translators: 1: taxonomy slugs, 2: number of terms */
                        __('Removing %1$s unregisters it while %2$d terms exist. The terms are kept but become inaccessible.', 'proto-blocks'),
                        implode(', ', $lost),
                        $terms
                    ),
                    'count' => $terms,
                    'detail' => $lost,
                ];
            }
        }

        $fields = array_values(array_diff(self::fieldNames($before), self::fieldNames($after)));
        if ($fields !== [] && $contentCount() > 0) {
            $issues[] = [
                'code' => 'field_removed',
                'message' => sprintf(
                    /* translators: %s: field names */
                    __('Stored values stay under the old meta key %s; renaming or removing a field does not move or delete them.', 'proto-blocks'),
                    implode(', ', $fields)
                ),
                'count' => $contentCount(),
                'detail' => $fields,
            ];
        }

        return $issues;
    }

    /**
     * What a declaration currently holds: its posts (or terms), and the terms of each inline taxonomy.
     *
     * @param array<string, mixed>|null $data
     * @return array{count: int, taxonomies: array<string, int>}
     */
    public function contentFor(string $kind, string $slug, ?array $data): array
    {
        if ($kind === Declaration::OPTIONS) {
            return ['count' => 0, 'taxonomies' => []];
        }
        if ($kind !== Declaration::TYPE) {
            return ['count' => $this->terms($slug), 'taxonomies' => []];
        }

        $taxonomies = [];
        foreach (self::inlineTaxonomies($data ?? []) as $taxonomy) {
            $taxonomies[$taxonomy] = $this->terms($taxonomy);
        }

        return ['count' => $this->posts($slug), 'taxonomies' => $taxonomies];
    }

    private function posts(string $type): int
    {
        return max(0, (int) ($this->postCount)($type));
    }

    private function terms(string $taxonomy): int
    {
        return max(0, (int) ($this->termCount)($taxonomy));
    }

    /** @return list<string>|null */
    private function registeredSupports(string $type): ?array
    {
        if ($this->registeredSupports === null) {
            return null;
        }
        $supports = ($this->registeredSupports)($type);

        return is_array($supports) ? array_values(array_filter($supports, 'is_string')) : null;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>|null    $registered
     * @return list<string>
     */
    private static function supports(array $data, ?array $registered): array
    {
        if (!array_key_exists('supports', $data)) {
            return $registered ?? self::DEFAULT_SUPPORTS;
        }

        return is_array($data['supports']) ? array_values(array_filter($data['supports'], 'is_string')) : [];
    }

    /**
     * Slugs of the taxonomy objects declared inline (string references are not declarations).
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private static function inlineTaxonomies(array $data): array
    {
        $out = [];
        foreach (is_array($data['taxonomies'] ?? null) ? $data['taxonomies'] : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $slug = $entry['slug'] ?? (is_string($entry['name'] ?? null) ? Slug::fromName($entry['name'], Slug::TAXONOMY_MAX) : null);
            if (is_string($slug) && $slug !== '' && !in_array($slug, $out, true)) {
                $out[] = $slug;
            }
        }

        return $out;
    }

    /**
     * Field names in every group: `fields[*].fields[*].name`.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private static function fieldNames(array $data): array
    {
        $out = [];
        foreach (is_array($data['fields'] ?? null) ? $data['fields'] : [] as $group) {
            if (!is_array($group) || !is_array($group['fields'] ?? null)) {
                continue;
            }
            foreach ($group['fields'] as $field) {
                $name = is_array($field) ? ($field['name'] ?? null) : null;
                if (is_string($name) && $name !== '' && !in_array($name, $out, true)) {
                    $out[] = $name;
                }
            }
        }

        return $out;
    }
}
