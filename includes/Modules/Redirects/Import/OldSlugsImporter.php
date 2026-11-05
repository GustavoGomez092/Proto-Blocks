<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

/**
 * WordPress old slugs: `_wp_old_slug` post meta joined to published posts.
 * The old URL is the post's current permalink path with its last segment
 * swapped for the old slug. Plain (`?p=`) permalinks cannot be turned into
 * paths and are skipped.
 */
final class OldSlugsImporter extends AbstractPluginImporter
{
    private const META = '_wp_old_slug';

    public function id(): string
    {
        return 'old-slugs';
    }

    public function label(): string
    {
        return 'WordPress old slugs';
    }

    public function available(): bool
    {
        $db = $this->db();
        return $db->get_var($db->prepare(
            "SELECT m.meta_id FROM {$db->postmeta} m INNER JOIN {$db->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_status = %s LIMIT %d",
            self::META,
            'publish',
            1,
        )) !== null;
    }

    public function preview(): array
    {
        $this->skipped = [];
        $db = $this->db();
        $results = (array) $db->get_results($db->prepare(
            "SELECT p.ID, p.post_name, m.meta_value AS old_slug FROM {$db->postmeta} m"
            . " INNER JOIN {$db->posts} p ON p.ID = m.post_id"
            . ' WHERE m.meta_key = %s AND p.post_status = %s ORDER BY p.ID ASC, m.meta_id ASC LIMIT %d',
            self::META,
            'publish',
            self::LIMIT,
        ), ARRAY_A);

        $rows = [];
        $seen = [];
        $line = 0;
        foreach ($results as $r) {
            $r = (array) $r;
            $line++;
            $row = self::rule((int) ($r['ID'] ?? 0), (string) ($r['post_name'] ?? ''), trim((string) ($r['old_slug'] ?? '')), $line);
            if ($row['status'] !== ParsedRow::INVALID) {
                $key = Merger::key($row['rule']);
                if (isset($seen[$key])) {
                    continue; // the same old slug stored twice
                }
                $seen[$key] = true;
            }
            $rows[] = $row;
        }
        $rules = $this->keep($rows);
        return $rules === [] ? [] : ['old-slugs' => self::group('Old slugs', $rules)];
    }

    private static function rule(int $id, string $slug, string $oldSlug, int $line): array
    {
        $post = get_post($id);
        if (!$post || ($post->post_status ?? '') !== 'publish') {
            return ParsedRow::invalid('missing_target', $line);
        }
        $slug = (string) ($post->post_name ?? $slug);
        if ($oldSlug === '' || $oldSlug === $slug) {
            return ParsedRow::invalid($oldSlug === '' ? 'empty_source' : 'self_redirect', $line);
        }
        $permalink = get_permalink($id);
        $path = is_string($permalink) ? (string) wp_parse_url($permalink, PHP_URL_PATH) : '';
        $query = is_string($permalink) ? wp_parse_url($permalink, PHP_URL_QUERY) : null;
        $trailing = str_ends_with($path, '/');
        $segments = explode('/', trim($path, '/'));
        $last = rawurldecode((string) end($segments));
        if ($query !== null || $path === '' || $last !== rawurldecode($slug)) {
            return ParsedRow::invalid('unsupported_permalink', $line); // plain or unusual permalinks
        }
        if (preg_match('~[/?#\\\\\x00-\x20\x7F]~', $oldSlug) === 1) {
            return ParsedRow::invalid('invalid_source', $line); // a slug is one path segment
        }
        $segments[count($segments) - 1] = $oldSlug; // WordPress stores slugs already URL-encoded
        $from = '/' . implode('/', $segments) . ($trailing ? '/' : '');
        return self::finish(['from' => $from, 'regex' => false, 'to' => $path, 'code' => 301, 'action' => 'url'], $line);
    }
}
