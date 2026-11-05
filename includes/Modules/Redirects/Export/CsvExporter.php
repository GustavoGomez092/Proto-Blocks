<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Export;

/**
 * CSV export (spec §9): `source,target,regex,code,type,hits,title,status,group`,
 * RFC 4180 quoting and CRLF records, every cell formula-escaped. Every rule
 * is listed (status active|disabled) except rules with a `when` condition and
 * 404 rules: re-importing those from CSV would make them unconditional.
 */
final class CsvExporter implements ExporterInterface
{
    public const HEADER = ['source', 'target', 'regex', 'code', 'type', 'hits', 'title', 'status', 'group'];

    public function format(): string
    {
        return 'csv';
    }

    public function mime(): string
    {
        return 'text/csv';
    }

    public function extension(): string
    {
        return 'csv';
    }

    public function render(array $groups, array $hits): string
    {
        $lines = [self::line(self::HEADER)];
        foreach (Support::groups($groups) as $group) {
            $slug = (string) ($group['slug'] ?? '');
            foreach (Support::rules($group) as $rule) {
                if (isset($rule['when']) || !is_string($rule['from'] ?? null)) {
                    continue;
                }
                $action = Support::action($rule);
                $code = Support::code($rule);
                $id = (string) ($rule['id'] ?? '');
                $lines[] = self::line([
                    $rule['from'],
                    $action === 'url' || $action === 'pass' ? (string) ($rule['to'] ?? '') : '',
                    ($rule['regex'] ?? false) ? '1' : '0',
                    $code === null ? '' : (string) $code,
                    $action,
                    (string) (int) ($hits[$slug][$id]['hits'] ?? 0),
                    (string) ($rule['title'] ?? ''),
                    Support::active($rule, $group) ? 'active' : 'disabled',
                    $slug,
                ]);
            }
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    /** @param list<string> $cells */
    private static function line(array $cells): string
    {
        return implode(',', array_map(static fn(string $c): string => Support::csvField(Support::formula($c)), $cells));
    }
}
