<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Storage;

/**
 * The 404 log (`{prefix}proto_404_log`). The same URL from the same stored
 * IP within one clock minute (UTC) refreshes the existing row with a single
 * UPDATE instead of adding another, to blunt scanners. Under `log.ip: none`
 * the stored IP is NULL, so one URL keeps one row per minute.
 */
final class NotFoundLog extends LogTable
{
    public function table(): string
    {
        return Schema::notFoundTable((string) $this->db()->prefix);
    }

    /**
     * Logs a 404 unless `log.notFound` is -1 or `proto_blocks_redirects_log_404`
     * returns false. Accepts a RequestSnapshot array or the engine's Request.
     */
    public function log404(mixed $request): void
    {
        if (!$this->enabled()) {
            return;
        }
        $snapshot = RequestSnapshot::from($request);
        if (apply_filters('proto_blocks_redirects_log_404', true, $snapshot) === false) {
            return;
        }

        $db = $this->db();
        $table = $this->table();
        $url = self::cleanUrl($snapshot['url']);
        $ip = $this->storedIp($snapshot);
        $now = $this->now();
        $row = [
            'created' => $now,
            'method' => self::cleanMethod($snapshot['method']),
            'agent' => self::cleanText($snapshot['agent'], self::TEXT_MAX),
            'referrer' => self::cleanText($snapshot['referrer'], self::TEXT_MAX),
            'request' => $this->requestColumn($snapshot['headers']),
        ];

        $minute = intdiv((int) current_time('timestamp', true), 60) * 60;
        $args = [$url];
        $ipClause = 'ip IS NULL';
        if ($ip !== null) {
            $ipClause = 'ip = %s';
            $args[] = $ip;
        }
        $args[] = gmdate('Y-m-d H:i:s', $minute);
        $args[] = gmdate('Y-m-d H:i:s', $minute + 60);
        $existing = $db->get_var($db->prepare(
            "SELECT id FROM {$table} WHERE url = %s AND {$ipClause} AND created >= %s AND created < %s ORDER BY id DESC LIMIT 1",
            $args
        ));

        if (is_numeric($existing) && (int) $existing > 0) {
            $db->update($table, $row, ['id' => (int) $existing], ['%s', '%s', '%s', '%s', '%s'], ['%d']);
            return;
        }

        $db->insert($table, ['url' => $url, 'ip' => $ip] + $row, ['%s', '%s', '%s', '%s', '%s', '%s', '%s']);
    }

    /**
     * One row per URL: how often, when last, and the latest referrer.
     *
     * @param array<string, mixed> $filters
     * @return array{rows: list<array{url: string, count: int, lastSeen: string, lastReferrer: ?string}>, total: int}
     */
    public function grouped(array $filters, int $page = 1, int $perPage = 50): array
    {
        $db = $this->db();
        $table = $this->table();
        [$where, $args] = $this->where($filters, 'l');
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $offset = (max(1, $page) - 1) * $perPage;

        $total = (int) $db->get_var($this->prepared("SELECT COUNT(DISTINCT l.url) FROM {$table} l{$where}", $args));
        $rows = (array) $db->get_results($db->prepare(
            'SELECT l.url, COUNT(*) AS count, MAX(l.created) AS last_seen,'
            . " (SELECT r.referrer FROM {$table} r WHERE r.url = l.url ORDER BY r.id DESC LIMIT 1) AS last_referrer"
            . " FROM {$table} l{$where} GROUP BY l.url ORDER BY count DESC, last_seen DESC LIMIT %d OFFSET %d",
            array_merge($args, [$perPage, $offset])
        ), ARRAY_A);

        return [
            'rows' => array_map(static fn(array $r): array => [
                'url' => (string) ($r['url'] ?? ''),
                'count' => (int) ($r['count'] ?? 0),
                'lastSeen' => (string) ($r['last_seen'] ?? ''),
                'lastReferrer' => isset($r['last_referrer']) && $r['last_referrer'] !== '' ? (string) $r['last_referrer'] : null,
            ], $rows),
            'total' => $total,
        ];
    }

    /** Deletes every row for $url (as stored); returns how many. */
    public function deleteByUrl(string $url): int
    {
        $db = $this->db();
        $table = $this->table();
        $result = $db->query($db->prepare("DELETE FROM {$table} WHERE url = %s", $url));

        return is_int($result) ? $result : 0;
    }

    protected function retentionKey(): string
    {
        return 'notFound';
    }

    protected function searchColumns(): array
    {
        return ['url', 'referrer'];
    }

    protected function extraFilters(): array
    {
        return ['url'];
    }

    protected function shape(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'created' => (string) ($row['created'] ?? ''),
            'url' => (string) ($row['url'] ?? ''),
            'method' => (string) ($row['method'] ?? ''),
            'ip' => isset($row['ip']) ? (string) $row['ip'] : null,
            'agent' => isset($row['agent']) ? (string) $row['agent'] : null,
            'referrer' => isset($row['referrer']) ? (string) $row['referrer'] : null,
            'request' => self::decodeRequest($row['request'] ?? null),
        ];
    }
}
