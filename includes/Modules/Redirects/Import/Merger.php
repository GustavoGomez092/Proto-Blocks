<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

use ProtoBlocks\Modules\Redirects\Definition\RuleNormalizer;

/**
 * Merges imported rules into a group (spec §9 duplicate modes):
 * - `skip` (default): a rule whose duplicate key already exists is left out;
 * - `replace`: the existing rule takes the imported keys, keeping its id;
 * - `add`: always appended.
 * Duplicate key = normalised `from` + `regex` + `when`. Added rules always
 * get an id that is unique in the group.
 */
final class Merger
{
    public const MODES = ['skip', 'replace', 'add'];

    /** Keys an existing rule keeps on `replace` when the import does not state them (target, else, code and action always come from the import). */
    private const KEPT_ON_REPLACE = ['title', 'enabled', 'query', 'ignoreCase', 'ignoreTrailingSlash', 'log', 'headers'];

    /**
     * @param array<string, mixed> $group  a group (raw or normalised) with `rules`; `slug` feeds id derivation
     * @param list<array> $rules           raw rules, or ParsedRows (invalid rows are ignored)
     * @return array{group: array, added: int, replaced: int, skipped: int}
     */
    public static function merge(array $group, array $rules, string $mode = 'skip'): array
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : 'skip';
        $slug = (string) ($group['slug'] ?? '');
        $defaultCase = (bool) ($group['defaults']['ignoreCase'] ?? true);
        $existing = array_values(is_array($group['rules'] ?? null) ? $group['rules'] : []);

        $ids = [];
        $index = [];
        foreach ($existing as $i => $rule) {
            if (isset($rule['id']) && is_string($rule['id'])) {
                $ids[$rule['id']] = true;
            }
            $index[self::key($rule, $defaultCase)] ??= $i;
        }

        $added = $replaced = $skipped = 0;
        foreach (self::unwrap($rules) as $incoming) {
            $incoming = RawRule::build($incoming);
            $key = self::key($incoming, $defaultCase);
            $at = $index[$key] ?? null;

            if ($at !== null && $mode === 'skip') {
                $skipped++;
                continue;
            }
            if ($at !== null && $mode === 'replace') {
                $current = $existing[$at];
                $id = isset($current['id']) && is_string($current['id']) ? $current['id'] : self::derive($slug, $current, $ids);
                unset($incoming['id']);
                $existing[$at] = RawRule::build(['id' => $id] + $incoming + array_intersect_key($current, array_flip(self::KEPT_ON_REPLACE)))
                    + array_diff_key($current, array_flip(RawRule::KEYS));
                $ids[$id] = true;
                $replaced++;
                continue;
            }
            if (!isset($incoming['id']) || !is_string($incoming['id']) || isset($ids[$incoming['id']])) {
                unset($incoming['id']);
                $incoming = ['id' => self::derive($slug, $incoming, $ids)] + $incoming;
            }
            $ids[$incoming['id']] = true;
            $existing[] = RawRule::build($incoming);
            $index[$key] ??= count($existing) - 1;
            $added++;
        }

        $group['rules'] = $existing;
        return ['group' => $group, 'added' => $added, 'replaced' => $replaced, 'skipped' => $skipped];
    }

    /**
     * Preview statuses: a `new` row whose key is already in $group, or in an
     * earlier row, becomes `duplicate` (reason `duplicate`).
     *
     * @param list<array> $rows ParsedRows
     * @return list<array>
     */
    public static function mark(array $group, array $rows): array
    {
        $defaultCase = (bool) ($group['defaults']['ignoreCase'] ?? true);
        $seen = [];
        foreach (is_array($group['rules'] ?? null) ? $group['rules'] : [] as $rule) {
            $seen[self::key($rule, $defaultCase)] = true;
        }
        foreach ($rows as $i => $row) {
            if (($row['status'] ?? null) !== ParsedRow::NEW) {
                continue;
            }
            $key = self::key($row['rule'], $defaultCase);
            if (isset($seen[$key])) {
                $rows[$i]['status'] = ParsedRow::DUPLICATE;
                $rows[$i]['reason'] = 'duplicate';
            }
            $seen[$key] = true;
        }
        return $rows;
    }

    /** Normalised from + regex + when. */
    public static function key(array $rule, bool $defaultIgnoreCase = true): string
    {
        $from = is_string($rule['from'] ?? null) ? trim($rule['from']) : '';
        $regex = (bool) ($rule['regex'] ?? false);
        if (!$regex) {
            [$path, $query] = array_pad(explode('?', $from, 2), 2, null);
            $path = rtrim($path, '/') ?: '/';
            if ($rule['ignoreCase'] ?? $defaultIgnoreCase) {
                $path = strtolower($path);
            }
            $from = $path . ($query !== null && $query !== '' ? '?' . $query : '');
        }
        $when = $rule['when'] ?? null;
        return $from . "\0" . ($regex ? '1' : '0') . "\0" . (is_array($when) ? (string) json_encode(self::sorted($when)) : '');
    }

    /**
     * `r_` + 8 hex of sha1(slug, from, when, action) — the loader's derivation —
     * with a counter appended on collision.
     *
     * @param array<string, true> $taken
     */
    public static function derive(string $slug, array $rule, array $taken): string
    {
        // Same derivation as the file loader (RuleNormalizer::deriveId on the
        // normalised condition), so a rule that gets its id here keeps it when
        // the file is read back; the counter only breaks ties inside one import.
        $from = isset($rule['from']) ? (string) $rule['from'] : null;
        $when = RuleNormalizer::when($rule['when'] ?? null);
        $action = (string) ($rule['action'] ?? 'url');
        for ($n = 0; ; $n++) {
            $id = RuleNormalizer::deriveId($slug, $n === 0 ? $from : ($from ?? '') . "\0" . $n, $when, $action);
            if (!isset($taken[$id])) {
                return $id;
            }
        }
    }

    /** @return list<array> raw rules; ParsedRows unwrapped, invalid ones dropped */
    private static function unwrap(array $rules): array
    {
        $out = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if (isset($rule['rule'], $rule['status']) && is_array($rule['rule'])) {
                if ($rule['status'] === ParsedRow::INVALID) {
                    continue;
                }
                $rule = $rule['rule'];
            }
            $out[] = $rule;
        }
        return $out;
    }

    private static function sorted(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::sorted($v);
            }
        }
        return $value;
    }
}
