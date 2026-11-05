<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

/**
 * CSV import (spec §9): delimiter `,` `;` or `|`; columns by position
 * `source, target, regex, code`, or by name when a header row is present
 * (our own export header adds `type`, `title`, `status`).
 */
final class CsvFormat implements FormatInterface
{
    private const DELIMITERS = [',', ';', '|'];
    private const FORMULA_PREFIX = '[FORMULA] ';

    private const ALIASES = [
        'source' => ['source', 'from', 'url', 'old', 'old url', 'old_url', 'redirect from', 'source url', 'source_url'],
        'target' => ['target', 'to', 'destination', 'new', 'new url', 'new_url', 'redirect to', 'target url', 'target_url'],
        'regex' => ['regex', 'regexp', 'is regex', 'is_regex'],
        'code' => ['code', 'status code', 'status_code', 'http code', 'http_code', 'action_code'],
        'type' => ['type', 'action', 'action_type'],
        'title' => ['title', 'description', 'name'],
        'status' => ['status', 'enabled', 'state'],
    ];

    public function id(): string
    {
        return 'csv';
    }

    public function detect(string $filename, string $text): bool
    {
        $byName = Formats::byFilename($filename);
        if ($byName !== null) {
            return $byName === 'csv';
        }
        $lines = Formats::meaningfulLines($text, 5);
        if ($lines === [] || str_starts_with($lines[0], '{') || str_starts_with($lines[0], '[')) {
            return false;
        }
        return strpbrk($lines[0], implode('', self::DELIMITERS)) !== false;
    }

    public function parse(string $text): array
    {
        if ($guard = ParsedRow::guardSize($text)) {
            return $guard;
        }
        $text = Formats::clean($text);
        $records = self::records($text, self::delimiter($text));

        $columns = ['source' => 0, 'target' => 1, 'regex' => 2, 'code' => 3];
        $rows = [];
        $first = true;
        foreach ($records as [$line, $cells]) {
            $cells = array_map([self::class, 'unescape'], $cells);
            if (count($cells) === 1 && trim($cells[0]) === '') {
                continue; // empty line
            }
            if ($first) {
                $first = false;
                $named = self::header($cells);
                if ($named !== null) {
                    $columns = $named;
                    continue;
                }
            }
            $rows[] = self::row($cells, $columns, $line);
        }
        return $rows;
    }

    private static function row(array $cells, array $columns, int $line): array
    {
        $get = static function (string $name) use ($cells, $columns): ?string {
            $i = $columns[$name] ?? null;
            return $i === null || !array_key_exists($i, $cells) ? null : trim($cells[$i]);
        };

        $source = (string) $get('source');
        if (count($cells) < 2) {
            return ParsedRow::invalid($source === '' ? 'empty_source' : 'too_few_columns', $line, $source === '' ? [] : ['from' => $source]);
        }
        if ($source === '') {
            return ParsedRow::invalid('empty_source', $line);
        }
        if (Patterns::hasControl($source)) {
            return ParsedRow::invalid('invalid_source', $line);
        }

        $regexCell = $get('regex');
        $regex = $regexCell === null || $regexCell === ''
            ? Patterns::looksLikeRegex($source)
            : in_array(strtolower($regexCell), ['1', 'true', 'yes', 'y', 'regex'], true);

        $type = strtolower((string) $get('type'));
        $type = in_array($type, ['url', 'error', 'pass', 'nothing'], true) ? $type : null;
        $codeCell = (string) $get('code');

        $rule = ['from' => $regex ? $source : RawRule::source($source), 'regex' => $regex];
        if (($title = $get('title')) !== null && $title !== '') {
            $rule['title'] = $title;
        }
        $status = strtolower((string) $get('status'));
        if (in_array($status, ['disabled', 'inactive', '0', 'false', 'no'], true)) {
            $rule['enabled'] = false;
        }

        if ($type === 'pass' || $type === 'nothing') {
            $rule['action'] = $type;
        } else {
            if ($codeCell === '') {
                $code = $type === 'error' ? 404 : 301;
            } elseif (ctype_digit($codeCell)) {
                $code = (int) $codeCell;
            } else {
                return ParsedRow::invalid('invalid_code', $line, RawRule::build($rule));
            }
            $action = RawRule::actionForCode($code);
            if ($action === null || ($type !== null && $type !== $action)) {
                return ParsedRow::invalid('invalid_code', $line, RawRule::build($rule + ['code' => $code]));
            }
            $rule['action'] = $action;
            $rule['code'] = $code;
        }

        if ($rule['action'] === 'url' || $rule['action'] === 'pass') {
            $rawTarget = (string) $get('target');
            if ($rawTarget === '') {
                return ParsedRow::invalid('missing_target', $line, RawRule::build($rule));
            }
            $target = RawRule::target($rawTarget);
            if ($target === null) {
                return ParsedRow::invalid('invalid_target', $line, RawRule::build($rule));
            }
            $rule['to'] = $target;
        }

        $rule = RawRule::build($rule);
        if (RawRule::selfRedirect($rule)) {
            return ParsedRow::invalid('self_redirect', $line, $rule);
        }
        if ($regex && !Patterns::compiles($source)) {
            return ParsedRow::invalid('invalid_regex', $line, $rule);
        }
        return ParsedRow::ok($rule, $line);
    }

    /** Column map from a header row, or null when the row is data. */
    private static function header(array $cells): ?array
    {
        $map = [];
        foreach ($cells as $i => $cell) {
            $name = strtolower(trim($cell));
            foreach (self::ALIASES as $column => $aliases) {
                if (!isset($map[$column]) && in_array($name, $aliases, true)) {
                    $map[$column] = $i;
                    break;
                }
            }
        }
        // A data row never has a bare word like "source" in the source column and "target"/"code" elsewhere.
        if (!isset($map['source']) || $map['source'] !== 0 || count($map) < 2) {
            return null;
        }
        return $map;
    }

    private static function unescape(string $cell): string
    {
        return str_starts_with($cell, self::FORMULA_PREFIX) ? substr($cell, strlen(self::FORMULA_PREFIX)) : $cell;
    }

    /** The first delimiter that splits the first non-empty line into two or more fields. */
    private static function delimiter(string $text): string
    {
        foreach (explode("\n", $text) as $line) {
            if (trim($line) === '') {
                continue;
            }
            foreach (self::DELIMITERS as $d) {
                if (count(str_getcsv($line, $d, '"', '')) > 1) {
                    return $d;
                }
            }
            break;
        }
        return ',';
    }

    /**
     * RFC 4180 records with the 1-based line each one starts on. Quoted
     * fields may hold delimiters, doubled quotes and line breaks.
     *
     * @return list<array{0: int, 1: list<string>}>
     */
    private static function records(string $text, string $delimiter): array
    {
        $records = [];
        $fields = [];
        $field = '';
        $quoted = false;
        $line = 1;
        $start = 1;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $c = $text[$i];
            if ($quoted) {
                if ($c === '"') {
                    if (($text[$i + 1] ?? '') === '"') {
                        $field .= '"';
                        $i++;
                    } else {
                        $quoted = false;
                    }
                } else {
                    if ($c === "\n") {
                        $line++;
                    }
                    $field .= $c;
                }
                continue;
            }
            if ($c === '"' && trim($field) === '') {
                $quoted = true;
                $field = '';
            } elseif ($c === $delimiter) {
                $fields[] = $field;
                $field = '';
            } elseif ($c === "\n") {
                $fields[] = $field;
                $records[] = [$start, $fields];
                $fields = [];
                $field = '';
                $line++;
                $start = $line;
            } else {
                $field .= $c;
            }
        }
        if ($field !== '' || $fields !== []) {
            $fields[] = $field;
            $records[] = [$start, $fields];
        }
        return $records;
    }
}
