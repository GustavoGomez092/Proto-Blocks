<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

/**
 * Simple 301 Redirects: option `301_redirects` (source => target), all 301.
 * A `*` in the source becomes `(.*)` and a `*` in the target `$1`.
 */
final class Simple301Importer extends AbstractPluginImporter
{
    public const OPTION = '301_redirects';

    public function id(): string
    {
        return 'simple-301-redirects';
    }

    public function label(): string
    {
        return 'Simple 301 Redirects';
    }

    public function available(): bool
    {
        $value = get_option(self::OPTION, []);
        return is_array($value) && $value !== [];
    }

    public function preview(): array
    {
        $this->skipped = [];
        $value = get_option(self::OPTION, []);
        $rows = [];
        $line = 0;
        foreach (is_array($value) ? $value : [] as $from => $to) {
            $line++;
            $row = self::wildcardRule((string) $from, is_scalar($to) ? (string) $to : '', $line);
            $rows[] = $row['status'] === ParsedRow::INVALID ? $row : self::finish($row['rule'] + ['code' => 301, 'action' => 'url'], $line);
        }
        $rules = $this->keep($rows);
        return $rules === [] ? [] : ['simple-301-redirects' => self::group('Simple 301 Redirects', $rules)];
    }

    public function cleanupHint(): ?string
    {
        return 'Deactivate Simple 301 Redirects once the imported rules are verified.';
    }
}
