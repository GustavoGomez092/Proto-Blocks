<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * Spec §6 "declarations are data": a declaration file must never hand
 * WordPress something it calls or instantiates, and its label strings never
 * carry markup.
 *
 * The schema refuses UNSAFE_KEYS and `<`/`>` in labels, so the writer rejects
 * such files; the loader runs filter() before its schema check (strip plus
 * warning, the type still registers), and PostTypeArgs/TaxonomyArgs call it
 * again as defence in depth. PHP
 * declarations are trusted code and keep every key (forSchemaCheck() hides
 * the keys from their schema check).
 */
final class UntrustedKeys
{
    /** register_post_type()/register_taxonomy() keys WordPress calls, instantiates or reserves for core. */
    public const UNSAFE_KEYS = [
        'register_meta_box_cb',
        'meta_box_cb',
        'meta_box_sanitize_cb',
        'update_count_callback',
        'rest_controller_class',
        'autosave_rest_controller_class',
        'revisions_rest_controller_class',
        '_builtin',
        '_edit_link',
    ];

    /** Plugin keys that become labels. */
    private const NAME_KEYS = ['name', 'plural'];

    /**
     * $d with UNSAFE_KEYS, keys whose value names a PHP callable, and
     * markup in labels removed, plus an `unsafe_key` / `unsafe_label`
     * warning per removal. PHP declarations come back unchanged.
     *
     * @param string $ref The schema node (#/$defs/type or #/$defs/taxonomy) whose properties are known keys.
     * @return array{0: Declaration, 1: list<Issue>}
     */
    public static function filter(Declaration $d, string $ref): array
    {
        if ($d->origin !== Declaration::ORIGIN_FILE) {
            return [$d, []];
        }

        $known = self::knownKeys($ref);
        $data = $d->data;
        $issues = [];

        foreach ($data as $key => $value) {
            $key = (string) $key;
            // `meta_box_cb: false` hides a taxonomy box in core; it is data, not code.
            $unsafe = (in_array($key, self::UNSAFE_KEYS, true) && !($key === 'meta_box_cb' && $value === false))
                || (!in_array($key, $known, true) && self::namesCallable($value));
            if ($unsafe) {
                unset($data[$key]);
                $issues[] = new Issue(
                    Issue::WARNING,
                    'unsafe_key',
                    sprintf(
                        /* translators: %s: declaration key */
                        __('The key "%s" names PHP code, which a declaration file cannot do. It is ignored; declare it in PHP instead.', 'proto-blocks'),
                        $key
                    ),
                    $d->source,
                    $d->slug,
                    $d->kind
                );
            }
        }

        foreach (self::NAME_KEYS as $key) {
            if (is_string($data[$key] ?? null) && self::hasMarkup($data[$key])) {
                $data[$key] = str_replace(['<', '>'], '', $data[$key]);
                $issues[] = self::labelIssue($d, $key);
            }
        }

        if (is_array($data['labels'] ?? null)) {
            foreach ($data['labels'] as $key => $value) {
                if (is_string($value) && self::hasMarkup($value)) {
                    unset($data['labels'][$key]);
                    $issues[] = self::labelIssue($d, 'labels.' . $key);
                }
            }
        }

        if ($issues === []) {
            return [$d, []];
        }

        return [new Declaration($d->kind, $d->slug, $data, $d->origin, $d->source, $d->root, $d->parentType), $issues];
    }

    /**
     * PHP declaration data as the schema should see it: trusted code may set
     * UNSAFE_KEYS and markup in labels, so neither is checked (inline
     * taxonomy objects of a type included).
     *
     * @param array<string|int, mixed> $data
     * @return array<string|int, mixed>
     */
    public static function forSchemaCheck(array $data): array
    {
        foreach (self::UNSAFE_KEYS as $key) {
            unset($data[$key]);
        }
        foreach (self::NAME_KEYS as $key) {
            if (is_string($data[$key] ?? null)) {
                $data[$key] = str_replace(['<', '>'], '', $data[$key]);
            }
        }
        if (is_array($data['labels'] ?? null)) {
            foreach ($data['labels'] as $key => $value) {
                if (is_string($value)) {
                    $data['labels'][$key] = str_replace(['<', '>'], '', $value);
                }
            }
        }
        if (is_array($data['taxonomies'] ?? null)) {
            foreach ($data['taxonomies'] as $i => $entry) {
                if (is_array($entry)) {
                    $data['taxonomies'][$i] = self::forSchemaCheck($entry);
                }
            }
        }

        return $data;
    }

    public static function hasMarkup(string $value): bool
    {
        return strpbrk($value, '<>') !== false;
    }

    /**
     * Whether a decoded JSON value names an existing PHP function or static
     * method ("Ns\\fn", "Class::method", ["Class", "method"]). A string must
     * contain `::` or a backslash, so plain words ("Link", "Date") are never
     * taken for functions. Classes are only looked up, never autoloaded.
     */
    public static function namesCallable(mixed $value): bool
    {
        if (is_string($value)) {
            if (!str_contains($value, '::') && !str_contains($value, '\\')) {
                return false;
            }
            $value = ltrim($value, '\\');
            if ($value === '') {
                return false;
            }
            if (str_contains($value, '::')) {
                [$class, $method] = explode('::', $value, 2);
                return self::staticMethodExists($class, $method);
            }
            return function_exists($value);
        }
        if (is_array($value) && array_is_list($value) && count($value) === 2 && is_string($value[0]) && is_string($value[1])) {
            return self::staticMethodExists(ltrim($value[0], '\\'), $value[1]);
        }

        return false;
    }

    private static function staticMethodExists(string $class, string $method): bool
    {
        return $class !== '' && $method !== '' && class_exists($class, false) && method_exists($class, $method);
    }

    /**
     * Property names the schema node at $ref declares.
     *
     * @return list<string>
     */
    private static function knownKeys(string $ref): array
    {
        $name = substr($ref, strlen('#/$defs/'));
        $node = SchemaCheck::schema()['$defs'][$name] ?? [];

        return array_values(array_map('strval', array_keys(array_filter(
            is_array($node['properties'] ?? null) ? $node['properties'] : [],
            'is_array'
        ))));
    }

    private static function labelIssue(Declaration $d, string $key): Issue
    {
        return new Issue(
            Issue::WARNING,
            'unsafe_label',
            sprintf(
                /* translators: %s: declaration key, e.g. labels.add_new_item */
                __('The label "%s" contains "<" or ">"; labels cannot carry markup, so it is not used as written.', 'proto-blocks'),
                $key
            ),
            $d->source,
            $d->slug,
            $d->kind
        );
    }
}
