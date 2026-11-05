<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\ContentModel\JsoncException;

/**
 * JSON import (spec §9). Three shapes:
 * - Proto-Blocks export `{ "groups": { "<slug>": <group file object> }, "settings"?: {...} }`;
 * - one Proto-Blocks group file `{ "title"?, "rules": [...] }` (group slug `imported`);
 * - Redirection export `{ "plugin": {...}, "redirects": [...], "groups": [...] }`.
 * Comments and trailing commas are tolerated. `line` is the 1-based rule ordinal.
 */
final class JsonFormat implements FormatInterface
{
    public const FALLBACK_GROUP = 'imported';
    private const SLUG = '/^[a-z][a-z0-9_-]*$/';

    public function id(): string
    {
        return 'json';
    }

    public function detect(string $filename, string $text): bool
    {
        $byName = Formats::byFilename($filename);
        if ($byName !== null) {
            return $byName === 'json';
        }
        return str_starts_with(ltrim(Formats::clean(substr($text, 0, 4096))), '{');
    }

    public function parse(string $text): array
    {
        $rows = [];
        foreach ($this->read($text)['rows'] as $groupRows) {
            array_push($rows, ...$groupRows);
        }
        return $rows;
    }

    /**
     * Raw groups keyed by slug: `title`, `enabled`, `position`, optional `defaults`,
     * and `rules` (the rows that parsed, as raw rules, in evaluation order).
     *
     * @return array<string, array>
     */
    public function parseGroups(string $text): array
    {
        $read = $this->read($text);
        $out = [];
        foreach ($read['groups'] as $slug => $group) {
            $rules = [];
            foreach ($read['rows'][$slug] ?? [] as $row) {
                if ($row['status'] !== ParsedRow::INVALID) {
                    $rules[] = $row['rule'];
                }
            }
            $out[$slug] = $group + ['rules' => $rules];
        }
        return $out;
    }

    /** The `settings` object of a Proto-Blocks export, null when absent or unreadable. */
    public function parseSettings(string $text): ?array
    {
        $data = self::decode($text);
        return is_array($data) && isset($data['groups']) && is_array($data['settings'] ?? null) ? $data['settings'] : null;
    }

    /** @return array{groups: array<string, array>, rows: array<string, list<array>>} */
    private function read(string $text): array
    {
        if ($guard = ParsedRow::guardSize($text)) {
            return ['groups' => [], 'rows' => ['' => $guard]];
        }
        $data = self::decode($text);
        if ($data === null) {
            return ['groups' => [], 'rows' => ['' => [ParsedRow::invalid('invalid_json', 0)]]];
        }
        if (isset($data['redirects']) && is_array($data['redirects'])) {
            return self::redirection($data);
        }
        if (isset($data['groups']) && is_array($data['groups']) && !array_is_list($data['groups'])) {
            return self::protoBlocks($data['groups']);
        }
        if (isset($data['groups']) && $data['groups'] === []) {
            return ['groups' => [], 'rows' => []];
        }
        if (isset($data['rules']) && is_array($data['rules'])) {
            return self::protoBlocks([self::FALLBACK_GROUP => $data]);
        }
        return ['groups' => [], 'rows' => ['' => [ParsedRow::invalid('unknown_shape', 0)]]];
    }

    private static function decode(string $text): ?array
    {
        try {
            return Jsonc::decode($text);
        } catch (JsoncException) {
            return null;
        }
    }

    /** @param array<mixed> $groups */
    private static function protoBlocks(array $groups): array
    {
        $out = ['groups' => [], 'rows' => []];
        $taken = [];
        $line = 0;
        foreach ($groups as $key => $group) {
            if (!is_array($group)) {
                continue;
            }
            $key = (string) $key;
            if (preg_match(self::SLUG, $key) === 1 && strlen($key) <= 60 && $key !== 'settings' && !isset($taken[$key])) {
                $slug = $key;
                $taken[$slug] = true;
            } else {
                $slug = RedirectionMapper::slug($key, $taken);
            }
            $meta = [
                'title' => is_string($group['title'] ?? null) && $group['title'] !== '' ? $group['title'] : $slug,
                'enabled' => !array_key_exists('enabled', $group) || (bool) $group['enabled'],
                'position' => is_int($group['position'] ?? null) ? $group['position'] : 10,
            ];
            if (is_array($group['defaults'] ?? null)) {
                $meta['defaults'] = array_intersect_key($group['defaults'], array_flip(['ignoreCase', 'ignoreTrailingSlash', 'query']));
            }
            $out['groups'][$slug] = $meta;
            $out['rows'][$slug] = [];
            foreach (is_array($group['rules'] ?? null) ? $group['rules'] : [] as $rule) {
                $line++;
                $out['rows'][$slug][] = self::ownRule($rule, $line);
            }
        }
        return $out;
    }

    private static function ownRule(mixed $rule, int $line): array
    {
        if (!is_array($rule) || ($rule !== [] && array_is_list($rule))) {
            return ParsedRow::invalid('not_an_object', $line);
        }
        $raw = RawRule::build($rule);
        $is404 = is_array($raw['when'] ?? null) && ($raw['when']['type'] ?? null) === '404';
        if (!$is404 && (!is_string($raw['from'] ?? null) || trim($raw['from']) === '')) {
            return ParsedRow::invalid('empty_source', $line, $raw);
        }
        return ParsedRow::ok($raw, $line);
    }

    /** @param array<string, mixed> $data */
    private static function redirection(array $data): array
    {
        $out = ['groups' => [], 'rows' => []];
        $taken = [];
        $slugById = [];
        foreach (is_array($data['groups'] ?? null) ? $data['groups'] : [] as $i => $group) {
            if (!is_array($group)) {
                continue;
            }
            $name = trim((string) ($group['name'] ?? ''));
            $slug = RedirectionMapper::slug($name !== '' ? $name : 'group-' . ($group['id'] ?? $i), $taken);
            $slugById[(string) ($group['id'] ?? '')] = $slug;
            $out['groups'][$slug] = self::redirectionGroup($group, $name !== '' ? $name : $slug);
        }

        $fallback = null;
        $items = [];
        foreach (array_values($data['redirects']) as $i => $item) {
            $line = $i + 1;
            if (!is_array($item) || ($item !== [] && array_is_list($item))) {
                $items[] = ['', 0, 0, ParsedRow::invalid('not_an_object', $line)];
                continue;
            }
            $slug = $slugById[(string) ($item['group_id'] ?? '')] ?? null;
            if ($slug === null) {
                $slug = $fallback ??= RedirectionMapper::slug(self::FALLBACK_GROUP, $taken);
                $out['groups'][$slug] ??= ['title' => 'Imported', 'enabled' => true, 'position' => 10];
            }
            $items[] = [$slug, (int) ($item['position'] ?? 0), (int) ($item['id'] ?? $line), RedirectionMapper::row($item, $line)];
        }
        // Redirection orders by position, then id.
        usort($items, static fn(array $a, array $b): int => [$a[1], $a[2], $a[3]['line']] <=> [$b[1], $b[2], $b[3]['line']]);
        foreach ($items as [$slug, , , $row]) {
            $out['rows'][$slug][] = $row;
        }
        return $out;
    }

    /** @param array<string, mixed> $group */
    public static function redirectionGroup(array $group, string $title): array
    {
        $enabled = true;
        if (array_key_exists('enabled', $group)) {
            $enabled = RedirectionMapper::bool($group['enabled']);
        } elseif (isset($group['status'])) {
            $enabled = strtolower((string) $group['status']) !== 'disabled';
        }
        return [
            'title' => $title,
            'enabled' => $enabled,
            'position' => isset($group['position']) && is_numeric($group['position']) ? (int) $group['position'] : 10,
        ];
    }
}
