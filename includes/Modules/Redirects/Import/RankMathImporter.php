<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

/**
 * Rank Math (`{prefix}rank_math_redirections`): one rule per source. Sources
 * hold `pattern`, `comparison` (exact | contains | start | end | regex) and
 * `ignore` ("case" = case-insensitive). Patterns are written without the
 * leading slash. Active rows import enabled, inactive disabled, trashed not at all.
 */
final class RankMathImporter extends AbstractPluginImporter
{
    public function id(): string
    {
        return 'rank-math';
    }

    public function label(): string
    {
        return 'Rank Math';
    }

    private function table(): string
    {
        return $this->db()->prefix . 'rank_math_redirections';
    }

    public function available(): bool
    {
        if (!$this->tableExists($this->table())) {
            return false;
        }
        $db = $this->db();
        return $db->get_var($db->prepare("SELECT id FROM `{$this->table()}` WHERE status IN (%s, %s) LIMIT %d", 'active', 'inactive', 1)) !== null;
    }

    public function preview(): array
    {
        $this->skipped = [];
        $db = $this->db();
        $results = (array) $db->get_results($db->prepare(
            "SELECT id, sources, url_to, header_code, status FROM `{$this->table()}` WHERE status IN (%s, %s) ORDER BY id ASC LIMIT %d",
            'active',
            'inactive',
            self::LIMIT,
        ), ARRAY_A);

        $rows = [];
        $line = 0;
        foreach ($results as $r) {
            $r = (array) $r;
            $line++;
            $sources = self::decodeList($r['sources'] ?? null);
            if ($sources === []) {
                $rows[] = ParsedRow::invalid('empty_source', $line);
                continue;
            }
            foreach ($sources as $source) {
                $rows[] = self::rule(is_array($source) ? $source : [], $r, $line);
            }
        }
        $rules = $this->keep($rows);
        return $rules === [] ? [] : ['rank-math' => self::group('Rank Math', $rules)];
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $row */
    private static function rule(array $source, array $row, int $line): array
    {
        $pattern = trim((string) ($source['pattern'] ?? ''));
        if ($pattern === '') {
            return ParsedRow::invalid('empty_source', $line);
        }
        $comparison = strtolower((string) ($source['comparison'] ?? 'exact'));
        $path = ltrim($pattern, '/');
        [$from, $regex] = match ($comparison) {
            'exact' => ['/' . $path, false],
            'contains' => [Patterns::quote($pattern), true],
            'start' => ['^/' . Patterns::quote($path), true],
            'end' => [Patterns::quote($pattern) . '$', true],
            'regex' => [self::slashAnchored($pattern), true],
            default => [null, false],
        };
        if ($from === null) {
            return ParsedRow::invalid('unsupported_condition', $line, ['from' => $pattern]);
        }

        $code = (int) ($row['header_code'] ?? 301);
        $action = RawRule::actionForCode($code);
        $rule = ['from' => $from, 'regex' => $regex];
        if ($action === null) {
            return ParsedRow::invalid('invalid_code', $line, $rule + ['code' => $code]);
        }
        if ($action === 'url') {
            $to = (string) ($row['url_to'] ?? '');
            $target = RawRule::target($to);
            if ($target === null) {
                return ParsedRow::invalid(trim($to) === '' ? 'missing_target' : 'invalid_target', $line, $rule);
            }
            $rule['to'] = $target;
        }
        if (strtolower((string) ($row['status'] ?? 'active')) !== 'active') {
            $rule['enabled'] = false;
        }
        $rule['ignoreCase'] = strtolower((string) ($source['ignore'] ?? '')) === 'case';
        return self::finish($rule + ['code' => $code, 'action' => $action], $line);
    }

    public function cleanupHint(): ?string
    {
        return 'Turn off the Redirections module in Rank Math once the imported rules are verified.';
    }
}
