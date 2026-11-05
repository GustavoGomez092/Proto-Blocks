<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Scripts\Definition;

/**
 * Turns a validated declaration into the array every other part of the
 * module reads (the Script shape):
 *
 * [
 *   'slug' => 'gtm', 'title' => 'Google Tag Manager', 'enabled' => true,
 *   'priority' => 10,
 *   'priorities' => ['head' => 10, 'bodyOpen' => 10, 'bodyClose' => 10],
 *   'locations' => ['head' => ['mode' => 'file'|'inline'|null, 'file' => ?string, 'code' => string], ...],
 *   'where' => ['include' => [...], 'exclude' => [...], 'loggedIn' => ?bool, 'roles' => [...], 'excludeRoles' => [...]],
 *   'origin' => 'file'|'php', 'source' => '/abs/script.jsonc' | 'functions.php:12', 'root' => ?string,
 *   'double' => null | ['file' => string, 'php' => string],
 * ]
 *
 * `code` is the snippet as it is output: the inline string, or the text of
 * the referenced file passed in $files (empty when the file is missing).
 */
final class ScriptNormalizer
{
    /** Location key => the only file name it may be stored in. */
    public const LOCATIONS = ['head' => 'head.html', 'bodyOpen' => 'body-open.html', 'bodyClose' => 'body-close.html'];

    public const DEFAULT_PRIORITY = 10;

    /**
     * @param array<string|int, mixed> $data  validated declaration data
     * @param array<string, string>    $files file name => text of the snippet files read for it
     * @return array<string, mixed> Script
     */
    public static function normalize(array $data, string $slug, string $origin, string $source, ?string $root = null, array $files = []): array
    {
        $priority = is_int($data['priority'] ?? null) ? $data['priority'] : self::DEFAULT_PRIORITY;
        $overrides = is_array($data['priorities'] ?? null) ? $data['priorities'] : [];

        $priorities = [];
        $locations = [];
        foreach (self::LOCATIONS as $location => $fileName) {
            $priorities[$location] = is_int($overrides[$location] ?? null) ? $overrides[$location] : $priority;

            $value = $data[$location] ?? null;
            if (is_string($value) && $value !== '') {
                $locations[$location] = ['mode' => 'inline', 'file' => null, 'code' => $value];
            } elseif (is_array($value) && is_string($value['file'] ?? null)) {
                $named = $value['file'];
                $locations[$location] = ['mode' => 'file', 'file' => $named, 'code' => $files[$named] ?? ''];
            } else {
                $locations[$location] = ['mode' => null, 'file' => null, 'code' => ''];
            }
        }

        return [
            'slug' => $slug,
            'title' => is_string($data['title'] ?? null) ? $data['title'] : $slug,
            'enabled' => ($data['enabled'] ?? true) !== false,
            'priority' => $priority,
            'priorities' => $priorities,
            'locations' => $locations,
            'where' => Targets::normalize(is_array($data['where'] ?? null) ? $data['where'] : []),
            'origin' => $origin,
            'source' => $source,
            'root' => $root,
            'double' => null,
        ];
    }

    /**
     * The locations that output something (non-empty code), in location order.
     *
     * @param array<string, mixed> $script
     * @return list<string>
     */
    public static function present(array $script): array
    {
        $out = [];
        foreach (array_keys(self::LOCATIONS) as $location) {
            if (($script['locations'][$location]['code'] ?? '') !== '') {
                $out[] = $location;
            }
        }

        return $out;
    }
}
