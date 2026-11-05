<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Storage;

use ProtoBlocks\Modules\Redirects\Engine\HitRecorder;

/**
 * Hit counts per (group, rule id). One bounded upsert per matched request.
 * The engine's HitRecorder: the Dispatcher decides whether a rule counts
 * (its `log` flag); record() only honours the site-wide `trackHits`.
 */
final class Hits implements HitRecorder
{
    private const GROUP_PATTERN = '/^[a-z][a-z0-9_-]{0,59}\z/D';
    private const RULE_PATTERN = '/^r_[a-f0-9]{8}\z/D';
    private const DELETE_BATCH = 500;

    /** @param array<string, mixed> $settings full module settings; `trackHits` false makes record() a no-op */
    public function __construct(private readonly array $settings = [])
    {
    }

    public function record(string $group, string $ruleId): void
    {
        if (($this->settings['trackHits'] ?? true) === false || !self::validKey($group, $ruleId)) {
            return;
        }
        $db = $this->db();
        $table = $this->table();
        $db->query($db->prepare(
            "INSERT INTO {$table} (group_slug, rule_id, hits, last_hit) VALUES (%s, %s, 1, %s)"
            . ' ON DUPLICATE KEY UPDATE hits = hits + 1, last_hit = VALUES(last_hit)',
            $group,
            $ruleId,
            current_time('mysql', true)
        ));
    }

    /** @return array<string, array<string, array{hits: int, lastHit: ?string}>> group => rule id => counts */
    public function all(): array
    {
        $table = $this->table();
        $rows = (array) $this->db()->get_results("SELECT group_slug, rule_id, hits, last_hit FROM {$table}", ARRAY_A);
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['group_slug']][(string) $row['rule_id']] = [
                'hits' => (int) $row['hits'],
                'lastHit' => isset($row['last_hit']) && $row['last_hit'] !== '' ? (string) $row['last_hit'] : null,
            ];
        }

        return $out;
    }

    /** Zeroes the count; the row stays. */
    public function reset(string $group, string $ruleId): void
    {
        $db = $this->db();
        $table = $this->table();
        $db->query($db->prepare(
            "UPDATE {$table} SET hits = 0, last_hit = NULL WHERE group_slug = %s AND rule_id = %s",
            $group,
            $ruleId
        ));
    }

    public function delete(string $group, string $ruleId): void
    {
        $db = $this->db();
        $table = $this->table();
        $db->query($db->prepare("DELETE FROM {$table} WHERE group_slug = %s AND rule_id = %s", $group, $ruleId));
    }

    /**
     * Rows whose "group/ruleId" is not in $known.
     *
     * @param list<string> $known
     * @return list<array{0: string, 1: string}>
     */
    public function orphanKeys(array $known): array
    {
        $keep = array_flip(array_map('strval', $known));
        $table = $this->table();
        $rows = (array) $this->db()->get_results("SELECT group_slug, rule_id FROM {$table}", ARRAY_A);
        $orphans = [];
        foreach ($rows as $row) {
            $group = (string) $row['group_slug'];
            $rule = (string) $row['rule_id'];
            if (!isset($keep[$group . '/' . $rule])) {
                $orphans[] = [$group, $rule];
            }
        }

        return $orphans;
    }

    /** @param list<string> $known */
    public function orphans(array $known): int
    {
        return count($this->orphanKeys($known));
    }

    /**
     * Deletes every row whose "group/ruleId" is not in $known.
     *
     * @param list<string> $known
     * @return int rows deleted
     */
    public function prune(array $known): int
    {
        $orphans = $this->orphanKeys($known);
        $db = $this->db();
        $table = $this->table();
        $deleted = 0;
        foreach (array_chunk($orphans, self::DELETE_BATCH) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '(%s, %s)'));
            $args = array_merge(...$chunk);
            $result = $db->query($db->prepare("DELETE FROM {$table} WHERE (group_slug, rule_id) IN ({$placeholders})", $args));
            $deleted += is_int($result) ? $result : 0;
        }

        return $deleted;
    }

    private static function validKey(string $group, string $ruleId): bool
    {
        return preg_match(self::GROUP_PATTERN, $group) === 1 && preg_match(self::RULE_PATTERN, $ruleId) === 1;
    }

    private function table(): string
    {
        return Schema::hitsTable((string) $this->db()->prefix);
    }

    private function db(): object
    {
        global $wpdb;

        return $wpdb;
    }
}
