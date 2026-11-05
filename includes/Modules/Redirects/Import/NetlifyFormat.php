<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

/**
 * Netlify `_redirects` import (spec §9): `from to [status][!]` per line,
 * `#` comment lines, `*` in the source with `:splat` in the target, named
 * `:placeholders` rejected. Status defaults to 301; 200 is an internal
 * rewrite (`pass`); 4xx/5xx are errors. An absolute source on another host
 * becomes a `server` condition.
 */
final class NetlifyFormat implements FormatInterface
{
    private const PLACEHOLDER = '~(?:^|/):(?!splat\b)[A-Za-z_]\w*~';

    public function id(): string
    {
        return 'redirects';
    }

    public function detect(string $filename, string $text): bool
    {
        $byName = Formats::byFilename($filename);
        if ($byName !== null) {
            return $byName === 'redirects';
        }
        $lines = Formats::meaningfulLines($text);
        if ($lines === []) {
            return false;
        }
        foreach ($lines as $line) {
            if (preg_match('~^(/|https?://)[^\s,;|]*\s+\S+(\s+\S+)*$~', $line) !== 1) {
                return false;
            }
        }
        return true;
    }

    public function parse(string $text): array
    {
        if ($guard = ParsedRow::guardSize($text)) {
            return $guard;
        }
        $rows = [];
        foreach (explode("\n", Formats::clean($text)) as $i => $content) {
            $content = trim($content);
            if ($content === '' || str_starts_with($content, '#')) {
                continue;
            }
            $rows[] = self::line(preg_split('/\s+/', $content) ?: [], $i + 1);
        }
        return $rows;
    }

    /** @param list<string> $tokens */
    private static function line(array $tokens, int $line): array
    {
        if (count($tokens) < 2) {
            return ParsedRow::invalid('too_few_columns', $line, ['from' => $tokens[0] ?? '']);
        }
        [$from, $to] = $tokens;
        $rest = array_slice($tokens, 2);

        // Query-parameter matching (`/store id=:id /x`) and conditions (`Country=us`) have no equivalent.
        if (str_contains($to, '=') && !str_starts_with($to, '/') && preg_match('~^https?://~i', $to) !== 1) {
            return ParsedRow::invalid('unsupported_condition', $line, ['from' => $from]);
        }
        $status = 301;
        foreach ($rest as $j => $token) {
            if ($j === 0 && preg_match('/^(\d{3})!?$/', $token, $m) === 1) {
                $status = (int) $m[1];
                continue;
            }
            return ParsedRow::invalid('unsupported_condition', $line, ['from' => $from]);
        }
        if (preg_match(self::PLACEHOLDER, self::pathOf($from)) === 1 || preg_match(self::PLACEHOLDER, self::pathOf($to)) === 1) {
            return ParsedRow::invalid('unsupported_placeholder', $line, ['from' => $from]);
        }

        $rule = [];
        if (preg_match('~^https?://([^/]+)(/.*)?$~i', $from, $m) === 1) {
            $rule['when'] = ['type' => 'server', 'value' => strtolower($m[1])];
            $from = ($m[2] ?? '') === '' ? '/' : $m[2];
        }
        $from = RawRule::source($from);
        $regex = str_contains($from, '*');
        $rule = ['from' => $regex ? Patterns::wildcard($from) : $from, 'regex' => $regex] + $rule;

        if ($status === 200) {
            if (preg_match('~^https?://~i', $to) === 1) {
                return ParsedRow::invalid('unsupported_action', $line, RawRule::build($rule)); // Netlify proxying
            }
            $rule['action'] = 'pass';
        } else {
            $action = RawRule::actionForCode($status);
            if ($action === null) {
                return ParsedRow::invalid('invalid_code', $line, RawRule::build($rule + ['code' => $status]));
            }
            $rule['code'] = $status;
            $rule['action'] = $action;
        }

        if ($rule['action'] !== 'error') {
            $target = RawRule::target(str_replace(':splat', $regex ? '$1' : '', $to));
            if ($target === null) {
                return ParsedRow::invalid('invalid_target', $line, RawRule::build($rule));
            }
            $rule['to'] = $target;
        }

        $rule = RawRule::build($rule);
        if (RawRule::selfRedirect($rule)) {
            return ParsedRow::invalid('self_redirect', $line, $rule);
        }
        return ParsedRow::ok($rule, $line);
    }

    private static function pathOf(string $url): string
    {
        return preg_match('~^https?://[^/]*(/.*)?$~i', $url, $m) === 1 ? ($m[1] ?? '') : $url;
    }
}
