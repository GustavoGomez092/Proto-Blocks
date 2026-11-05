<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Storage;

/**
 * What the redirect log and the 404 log share: row shaping (spec §5
 * truncation, method allow-list, IP policy, header stripping), filters,
 * paging, deletes and export. Every value reaches SQL through prepare() or
 * $wpdb->insert()/update(); table names come from the prefix and constants.
 */
abstract class LogTable
{
    public const URL_MAX = 2000;
    public const TEXT_MAX = 255;
    public const IP_MAX = 45;
    public const MAX_PER_PAGE = 200;
    public const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'TRACE', 'CONNECT'];

    /** Request headers never stored, lower-case. */
    public const SENSITIVE_HEADERS = [
        'authorization', 'cookie', 'host', 'proxy-authorization',
        'set-cookie', 'x-wp-nonce', 'x-api-key', 'x-auth-token', 'x-csrf-token', 'x-xsrf-token',
    ];

    /** Request headers that carry a client address, lower-case: stored only when `log.ip` is `full`. */
    public const IP_HEADERS = [
        'x-forwarded-for', 'cf-connecting-ip', 'x-real-ip', 'client-ip', 'true-client-ip', 'forwarded',
        'x-forwarded', 'x-cluster-client-ip', 'via', 'forwarded-for', 'x-client-ip',
    ];

    private const HEADER_COUNT_MAX = 50;
    private const HEADER_VALUE_MAX = 1000;
    private const DELETE_BATCH = 1000;
    private const EXPORT_BATCH = 500;

    /** @param array<string, mixed> $settings full module settings (`log` is read) */
    public function __construct(protected readonly array $settings = [])
    {
    }

    /** The table name with the current prefix. */
    abstract public function table(): string;

    /** The `log.*` days key: `redirects` or `notFound`. */
    abstract protected function retentionKey(): string;

    /** @return list<string> columns the `search` filter reads */
    abstract protected function searchColumns(): array;

    /** @return list<string> filters this table understands besides search/from/to */
    abstract protected function extraFilters(): array;

    /**
     * @param array<string, mixed> $row database row
     * @return array<string, mixed> API row
     */
    abstract protected function shape(array $row): array;

    /** -1 = do not log, 0 = forever, N days (clamped to 90). */
    public function retentionDays(): int
    {
        $value = $this->logSettings()[$this->retentionKey()] ?? 7;
        $days = is_numeric($value) ? (int) $value : 7;

        return $days < 0 ? -1 : min($days, 90);
    }

    public function enabled(): bool
    {
        return $this->retentionDays() !== -1;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function query(array $filters, int $page = 1, int $perPage = 50): array
    {
        $db = $this->db();
        $table = $this->table();
        [$where, $args] = $this->where($filters);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $offset = (max(1, $page) - 1) * $perPage;

        $total = (int) $db->get_var($this->prepared("SELECT COUNT(*) FROM {$table}{$where}", $args));
        $rows = (array) $db->get_results(
            $db->prepare("SELECT * FROM {$table}{$where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge($args, [$perPage, $offset])),
            ARRAY_A
        );

        return ['rows' => array_map(fn(array $r): array => $this->shape($r), $rows), 'total' => $total];
    }

    /**
     * @param list<int|string> $ids
     * @return int rows deleted
     */
    public function delete(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn($id): int => is_numeric($id) ? (int) $id : 0, $ids),
            static fn(int $id): bool => $id > 0
        )));
        $db = $this->db();
        $table = $this->table();
        $deleted = 0;
        foreach (array_chunk($ids, self::DELETE_BATCH) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $result = $db->query($db->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})", $chunk));
            $deleted += is_int($result) ? $result : 0;
        }

        return $deleted;
    }

    /**
     * Deletes every row matching $filters ([] = every row).
     *
     * @param array<string, mixed> $filters
     */
    public function clear(array $filters = []): int
    {
        [$where, $args] = $this->where($filters);
        $table = $this->table();
        $result = $this->db()->query($this->prepared("DELETE FROM {$table}{$where}", $args));

        return is_int($result) ? $result : 0;
    }

    /**
     * Every matching row, newest first, read in batches by id.
     *
     * @param array<string, mixed> $filters
     * @return \Generator<int, array<string, mixed>>
     */
    public function exportRows(array $filters = []): \Generator
    {
        $db = $this->db();
        $table = $this->table();
        [$where, $args] = $this->where($filters);
        $before = null;
        do {
            $sql = "SELECT * FROM {$table}{$where}";
            $batchArgs = $args;
            if ($before !== null) {
                $sql .= ($where === '' ? ' WHERE' : ' AND') . ' id < %d';
                $batchArgs[] = $before;
            }
            $sql .= ' ORDER BY id DESC LIMIT %d';
            $batchArgs[] = self::EXPORT_BATCH;
            $rows = (array) $db->get_results($db->prepare($sql, $batchArgs), ARRAY_A);
            foreach ($rows as $row) {
                $before = (int) $row['id'];
                yield $this->shape($row);
            }
        } while (count($rows) === self::EXPORT_BATCH);
    }

    /** Deletes up to $limit rows created before $cutoff (UTC datetime); returns how many. */
    public function pruneBefore(string $cutoff, int $limit): int
    {
        $db = $this->db();
        $table = $this->table();
        $result = $db->query($db->prepare("DELETE FROM {$table} WHERE created < %s ORDER BY id ASC LIMIT %d", $cutoff, $limit));

        return is_int($result) ? $result : 0;
    }

    // ------------------------------------------------------------------ shaping

    /** @return array<string, mixed> */
    protected function logSettings(): array
    {
        $log = $this->settings['log'] ?? [];

        return is_array($log) ? $log : [];
    }

    /** Decoded once, invalid UTF-8 and control characters removed, sanitised, cut at 2000. */
    public static function cleanUrl(string $url): string
    {
        $url = rawurldecode($url);

        return (string) self::cut(self::text($url), self::URL_MAX);
    }

    /** Sanitised free text cut at $max characters; null when empty. */
    public static function cleanText(?string $text, int $max): ?string
    {
        if ($text === null) {
            return null;
        }

        return self::cut(self::text($text), $max);
    }

    /** Upper-cased and allow-listed; anything else is OTHER, nothing is ''. */
    public static function cleanMethod(string $method): string
    {
        $method = strtoupper(trim($method));
        if ($method === '') {
            return '';
        }

        return in_array($method, self::METHODS, true) ? $method : 'OTHER';
    }

    /**
     * The stored `request` column: null unless `log.headers` is true, then the
     * JSON of the headers minus the sensitive ones (and minus IP_HEADERS
     * unless `log.ip` is `full`).
     *
     * @param array<string, string> $headers lower-case names
     */
    protected function requestColumn(array $headers): ?string
    {
        if (($this->logSettings()['headers'] ?? false) !== true) {
            return null;
        }
        // Masking a header value is not worth it: unless the IP policy is `full`
        // the address-bearing headers are dropped, so `none`/`masked` hold.
        $withIps = ($this->logSettings()['ip'] ?? 'none') === 'full';
        $kept = [];
        foreach ($headers as $name => $value) {
            $name = strtolower(trim((string) $name));
            if ($name === '' || in_array($name, self::SENSITIVE_HEADERS, true) || preg_match('/^[a-z0-9-]{1,100}\z/D', $name) !== 1) {
                continue;
            }
            if (!$withIps && in_array($name, self::IP_HEADERS, true)) {
                continue;
            }
            $kept[$name] = (string) self::cut(self::text((string) $value), self::HEADER_VALUE_MAX);
            if (count($kept) >= self::HEADER_COUNT_MAX) {
                break;
            }
        }
        ksort($kept);
        $json = json_encode(['headers' => $kept], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? null : $json;
    }

    /** @param array{ip: string, headers: array<string, string>} $snapshot */
    protected function storedIp(array $snapshot): ?string
    {
        $ip = IpResolver::forStorage(RequestSnapshot::toServer($snapshot), $this->logSettings());

        return $ip === null ? null : substr($ip, 0, self::IP_MAX);
    }

    /** @return array<string, mixed>|null */
    protected static function decodeRequest(mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function now(): string
    {
        return (string) current_time('mysql', true);
    }

    // ------------------------------------------------------------------ filters

    /**
     * @param array<string, mixed> $filters
     * @param string $alias table alias the columns are qualified with ('' = none)
     * @return array{0: string, 1: list<mixed>} " WHERE …" (or '') and its arguments
     */
    protected function where(array $filters, string $alias = ''): array
    {
        $db = $this->db();
        $c = $alias === '' ? '' : $alias . '.';
        $clauses = [];
        $args = [];

        $search = $filters['search'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $like = '%' . $db->esc_like(trim($search)) . '%';
            $parts = [];
            foreach ($this->searchColumns() as $column) {
                $parts[] = "{$c}{$column} LIKE %s";
                $args[] = $like;
            }
            $clauses[] = '(' . implode(' OR ', $parts) . ')';
        }

        $extra = $this->extraFilters();
        if (in_array('code', $extra, true) && self::isIntLike($filters['code'] ?? null)) {
            $clauses[] = "{$c}code = %d";
            $args[] = (int) $filters['code'];
        }
        if (in_array('group', $extra, true) && is_string($filters['group'] ?? null) && $filters['group'] !== '') {
            $clauses[] = "{$c}group_slug = %s";
            $args[] = $filters['group'];
        }
        if (in_array('ruleId', $extra, true) && is_string($filters['ruleId'] ?? null) && $filters['ruleId'] !== '') {
            $clauses[] = "{$c}rule_id = %s";
            $args[] = $filters['ruleId'];
        }
        if (in_array('url', $extra, true) && is_string($filters['url'] ?? null) && $filters['url'] !== '') {
            $clauses[] = "{$c}url = %s";
            $args[] = $filters['url'];
        }

        $from = self::date($filters['from'] ?? null, false);
        if ($from !== null) {
            $clauses[] = "{$c}created >= %s";
            $args[] = $from;
        }
        $to = self::date($filters['to'] ?? null, true);
        if ($to !== null) {
            $clauses[] = "{$c}created <= %s";
            $args[] = $to;
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $args];
    }

    /** `Y-m-d` (start or end of that UTC day) or `Y-m-d H:i[:s]` / ISO `T`; anything else null. */
    protected static function date(mixed $value, bool $endOfDay): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/D', $value, $m) === 1) {
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }
            return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?\z/D', $value, $m) === 1) {
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) ($m[6] ?? 0) > 59) {
                return null;
            }
            return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] ?? ($endOfDay ? '59' : '00'));
        }

        return null;
    }

    /** prepare() only when there is something to bind (prepare() refuses a query without placeholders). */
    protected function prepared(string $sql, array $args): string
    {
        return $args === [] ? $sql : (string) $this->db()->prepare($sql, $args);
    }

    protected function db(): object
    {
        global $wpdb;

        return $wpdb;
    }

    private static function isIntLike(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/^\d{1,3}\z/D', $value) === 1);
    }

    private static function text(string $text): string
    {
        $text = wp_check_invalid_utf8($text, true);
        $text = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $text);

        return sanitize_text_field($text);
    }

    private static function cut(string $text, int $max): ?string
    {
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $max, 'UTF-8');
    }
}
