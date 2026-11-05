<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Storage;

use ProtoBlocks\Modules\Redirects\Engine\RedirectLogger;

/**
 * The redirect log (`{prefix}proto_redirect_log`) and the engine's
 * RedirectLogger: log404() hands the 404 to NotFoundLog so one logger object
 * serves the dispatcher. Whether a rule is logged at all (`log: false`) is
 * the Dispatcher's call; this class only honours `settings['log']`.
 *
 * logRedirect() entry keys: `url` (defaults to the request URL), `target`,
 * `code`, `group`, `ruleId`, `redirectBy` (default `proto-blocks`), `request`
 * (a RequestSnapshot array or the engine's Request; default $_SERVER).
 */
final class RedirectLog extends LogTable implements RedirectLogger
{
    private ?NotFoundLog $notFound = null;

    /** @param array<string, mixed> $settings */
    public function __construct(array $settings = [], ?NotFoundLog $notFound = null)
    {
        parent::__construct($settings);
        $this->notFound = $notFound;
    }

    public function table(): string
    {
        return Schema::logTable((string) $this->db()->prefix);
    }

    /** @param array<string, mixed> $entry */
    public function logRedirect(array $entry): void
    {
        if (!$this->enabled()) {
            return;
        }
        $snapshot = RequestSnapshot::from($entry['request'] ?? null);
        $url = is_string($entry['url'] ?? null) ? $entry['url'] : $snapshot['url'];
        $target = is_string($entry['target'] ?? null) ? $entry['target'] : null;
        $code = is_numeric($entry['code'] ?? null) ? max(0, min(999, (int) $entry['code'])) : 0;
        $group = is_string($entry['group'] ?? null) ? self::cleanText($entry['group'], 60) : null;
        $ruleId = is_string($entry['ruleId'] ?? null) ? self::cleanText($entry['ruleId'], 10) : null;
        $by = is_string($entry['redirectBy'] ?? null) ? self::cleanText($entry['redirectBy'], 50) : null;

        $this->db()->insert($this->table(), [
            'created' => $this->now(),
            'url' => self::cleanUrl($url),
            'target' => $target === null ? null : self::cleanText($target, self::URL_MAX),
            'code' => $code,
            'group_slug' => $group,
            'rule_id' => $ruleId,
            'redirect_by' => $by ?? 'proto-blocks',
            'method' => self::cleanMethod($snapshot['method']),
            'ip' => $this->storedIp($snapshot),
            'agent' => self::cleanText($snapshot['agent'], self::TEXT_MAX),
            'referrer' => self::cleanText($snapshot['referrer'], self::TEXT_MAX),
            'request' => $this->requestColumn($snapshot['headers']),
        ], ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
    }

    /** Delegates to the 404 log (same settings); accepts a RequestSnapshot array or the engine's Request. */
    public function log404(mixed $request): void
    {
        $this->notFound ??= new NotFoundLog($this->settings);
        $this->notFound->log404($request);
    }

    protected function retentionKey(): string
    {
        return 'redirects';
    }

    protected function searchColumns(): array
    {
        return ['url', 'target'];
    }

    protected function extraFilters(): array
    {
        return ['code', 'group', 'ruleId'];
    }

    protected function shape(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'created' => (string) ($row['created'] ?? ''),
            'url' => (string) ($row['url'] ?? ''),
            'target' => isset($row['target']) ? (string) $row['target'] : null,
            'code' => (int) ($row['code'] ?? 0),
            'group' => isset($row['group_slug']) ? (string) $row['group_slug'] : null,
            'ruleId' => isset($row['rule_id']) ? (string) $row['rule_id'] : null,
            'redirectBy' => (string) ($row['redirect_by'] ?? ''),
            'method' => (string) ($row['method'] ?? ''),
            'ip' => isset($row['ip']) ? (string) $row['ip'] : null,
            'agent' => isset($row['agent']) ? (string) $row['agent'] : null,
            'referrer' => isset($row['referrer']) ? (string) $row['referrer'] : null,
            'request' => self::decodeRequest($row['request'] ?? null),
        ];
    }
}
