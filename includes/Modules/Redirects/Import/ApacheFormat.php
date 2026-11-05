<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

/**
 * Apache import (spec §9): `RewriteRule` (with the `RewriteCond` lines just
 * before it), `Redirect`, `RedirectMatch`, `RedirectPermanent`, `RedirectTemp`.
 * Apache is case-sensitive, so rules without `NC` import `ignoreCase: false`;
 * `RewriteRule` never sees the query, so it imports `query: ignore`, or
 * `pass` with `QSA`. The `# BEGIN WordPress` … `# END WordPress` block is skipped.
 */
final class ApacheFormat implements FormatInterface
{
    private const BOILERPLATE = ['rewriteengine', 'rewritebase', 'rewriteoptions', 'options', 'directoryindex'];
    private const DIRECTIVES = ['rewriterule', 'rewritecond', 'redirect', 'redirectmatch', 'redirectpermanent', 'redirecttemp', 'rewriteengine'];
    private const WORD_CODES = ['permanent' => 301, 'temp' => 302, 'seeother' => 303, 'gone' => 410];
    private const CONDITION_VARS = [
        'HTTP_REFERER' => 'referrer',
        'HTTP_USER_AGENT' => 'agent',
        'HTTP_HOST' => 'server',
        'QUERY_STRING' => 'query',
    ];
    private const UNSUPPORTED_FLAGS = ['P', 'PROXY', 'C', 'CHAIN', 'N', 'NEXT', 'S', 'SKIP'];

    public function id(): string
    {
        return 'apache';
    }

    public function detect(string $filename, string $text): bool
    {
        $byName = Formats::byFilename($filename);
        if ($byName !== null) {
            return $byName === 'apache';
        }
        foreach (Formats::meaningfulLines($text) as $line) {
            $first = strtolower((string) strtok($line, " \t"));
            if (in_array($first, self::DIRECTIVES, true) || str_starts_with($first, '<ifmodule')) {
                return true;
            }
        }
        return false;
    }

    public function parse(string $text): array
    {
        if ($guard = ParsedRow::guardSize($text)) {
            return $guard;
        }
        $rows = [];
        $conds = [];
        $inWordPress = false;
        foreach (self::logicalLines(Formats::clean($text)) as [$line, $content]) {
            $content = trim($content);
            if ($content === '') {
                continue;
            }
            if (str_starts_with($content, '#')) {
                $comment = strtolower(trim(ltrim($content, '#')));
                if ($comment === 'begin wordpress') {
                    $inWordPress = true;
                } elseif ($comment === 'end wordpress') {
                    $inWordPress = false;
                }
                continue;
            }
            if ($inWordPress || str_starts_with($content, '<')) {
                continue;
            }
            $tokens = self::tokens($content);
            if ($tokens === null) {
                $rows[] = ParsedRow::invalid('malformed_line', $line);
                continue;
            }
            $directive = strtolower(array_shift($tokens));
            if (in_array($directive, self::BOILERPLATE, true)) {
                continue;
            }
            switch ($directive) {
                case 'rewritecond':
                    $conds[] = $tokens;
                    break;
                case 'rewriterule':
                    $rows[] = self::rewriteRule($tokens, $conds, $line);
                    $conds = [];
                    break;
                case 'redirect':
                case 'redirectpermanent':
                case 'redirecttemp':
                case 'redirectmatch':
                    $rows[] = self::redirect($directive, $tokens, $line);
                    break;
                default:
                    $rows[] = ParsedRow::invalid('unsupported_directive', $line);
            }
        }
        return $rows;
    }

    /** @param list<string> $args @param list<list<string>> $conds */
    private static function rewriteRule(array $args, array $conds, int $line): array
    {
        if (count($args) < 2) {
            return ParsedRow::invalid('malformed_line', $line);
        }
        [$pattern, $target] = $args;
        $flags = self::flags($args[2] ?? '');
        if ($flags === null) {
            return ParsedRow::invalid('malformed_line', $line);
        }
        foreach (self::UNSUPPORTED_FLAGS as $f) {
            if (array_key_exists($f, $flags)) {
                return ParsedRow::invalid('unsupported_flag', $line);
            }
        }
        if (str_starts_with($pattern, '!')) {
            return ParsedRow::invalid('unsupported_condition', $line);
        }

        // Conditions.
        $when = null;
        $queryPattern = null;
        foreach ($conds as $cond) {
            $parsed = self::condition($cond);
            if ($parsed === null) {
                return ParsedRow::invalid('unsupported_condition', $line);
            }
            if ($parsed[0] === 'query') {
                if ($queryPattern !== null) {
                    return ParsedRow::invalid('multiple_conditions', $line);
                }
                $queryPattern = $parsed[1];
                continue;
            }
            if ($when !== null) {
                return ParsedRow::invalid('multiple_conditions', $line);
            }
            $when = $parsed[1];
        }

        $ignoreCase = isset($flags['NC']) || isset($flags['NOCASE']);
        $rule = [];

        // Action and code.
        $redirect = $flags['R'] ?? $flags['REDIRECT'] ?? null;
        if (isset($flags['F']) || isset($flags['FORBIDDEN'])) {
            $rule += ['code' => 403, 'action' => 'error'];
        } elseif (isset($flags['G']) || isset($flags['GONE'])) {
            $rule += ['code' => 410, 'action' => 'error'];
        } elseif ($redirect !== null) {
            $code = self::code($redirect === '' ? '302' : $redirect);
            $action = $code === null ? null : RawRule::actionForCode($code);
            if ($action === null) {
                return ParsedRow::invalid('invalid_code', $line);
            }
            if ($action === 'url' && $target === '-') {
                return ParsedRow::invalid('missing_target', $line);
            }
            $rule += ['code' => $code, 'action' => $action];
        } elseif ($target === '-') {
            $rule['action'] = 'nothing'; // "stop here, serve as is"
        } elseif (preg_match('~^https?://~i', $target) === 1) {
            $rule += ['code' => 302, 'action' => 'url']; // Apache's implicit redirect
        } else {
            $rule['action'] = 'pass'; // internal rewrite
        }

        if ($rule['action'] === 'url' || $rule['action'] === 'pass') {
            $target = str_replace('\\%', "\0", $target);
            if (str_contains($target, '%{') || preg_match('/%\d/', $target) === 1) {
                return ParsedRow::invalid('unsupported_variable', $line);
            }
            $resolved = RawRule::target(str_replace("\0", '%', $target));
            if ($resolved === null) {
                return ParsedRow::invalid('invalid_target', $line);
            }
            $rule['to'] = $resolved;
        }

        // Pattern: .htaccess strips the leading slash, server context keeps it.
        $usesCaptures = isset($rule['to']) && preg_match('/\$\{?\d/', $rule['to']) === 1;
        $anchored = str_starts_with($pattern, '^');
        $rest = $pattern;
        if ($anchored) {
            $rest = substr($pattern, 1);
            if (str_starts_with($rest, '/?')) {
                $rest = substr($rest, 2);
            } elseif (str_starts_with($rest, '/')) {
                $rest = substr($rest, 1);
            }
        }
        $literal = $anchored && !$usesCaptures ? Patterns::anchoredLiteral('^' . $rest) : null;

        if ($literal !== null) {
            $from = '/' . $literal[0];
            $regex = false;
            $trailing = $literal[1] || $literal[0] === '';
        } else {
            $from = $anchored ? '^/' . $rest : $pattern;
            $regex = true;
            $trailing = null;
        }

        $query = isset($flags['QSA']) || isset($flags['QSAPPEND']) ? 'pass' : 'ignore';
        if ($queryPattern !== null) {
            $q = Patterns::anchoredLiteral($queryPattern);
            if (!$regex && $q !== null && !$q[1]) {
                if ($q[0] !== '') {
                    $from .= '?' . $q[0];
                }
                if ($query !== 'pass') {
                    $query = 'exact';
                }
            } else {
                // A regex rule matches `path?query` as one string.
                $path = $regex ? (string) preg_replace('/\$$/', '', $from) : '^' . Patterns::quote($from);
                $from = $path . '\?' . (string) preg_replace('/^\^/', '', $queryPattern);
                $regex = true;
                $trailing = null;
            }
        }

        $rule = ['from' => $from, 'regex' => $regex] + $rule;
        if ($when !== null) {
            $rule['when'] = $when;
        }
        $rule['query'] = $query;
        $rule['ignoreCase'] = $ignoreCase;
        if ($trailing !== null) {
            $rule['ignoreTrailingSlash'] = $trailing;
        }
        return self::finish($rule, $line);
    }

    /**
     * @param list<string> $cond [testString, pattern, flags?]
     * @return array{0: string, 1: mixed}|null ['query', pattern] | [type, when] | null when unsupported
     */
    private static function condition(array $cond): ?array
    {
        if (count($cond) < 2 || preg_match('/^%\{([A-Z_]+)\}$/', $cond[0], $m) !== 1 || !isset(self::CONDITION_VARS[$m[1]])) {
            return null;
        }
        $flags = self::flags($cond[2] ?? '');
        $pattern = $cond[1];
        if ($flags === null || isset($flags['OR']) || isset($flags['ORNEXT']) || str_starts_with($pattern, '!')) {
            return null;
        }
        $type = self::CONDITION_VARS[$m[1]];
        if ($type === 'query') {
            return ['query', $pattern];
        }
        $literal = Patterns::anchoredLiteral($pattern);
        if ($type === 'server') {
            if ($literal === null || $literal[1] || $literal[0] === '') {
                return null;
            }
            return ['server', ['type' => 'server', 'value' => strtolower($literal[0])]];
        }
        if ($literal !== null && !$literal[1]) {
            return [$type, ['type' => $type, 'value' => $literal[0], 'regex' => false]];
        }
        return [$type, ['type' => $type, 'value' => $pattern, 'regex' => true]];
    }

    /** @param list<string> $args */
    private static function redirect(string $directive, array $args, int $line): array
    {
        $code = match ($directive) {
            'redirectpermanent' => 301,
            'redirecttemp' => 302,
            default => null,
        };
        if ($code === null && $args !== [] && self::code($args[0]) !== null && !str_starts_with($args[0], '/')) {
            $code = self::code(array_shift($args));
        }
        $code ??= 302; // bare Redirect
        $action = RawRule::actionForCode($code);
        if ($action === null) {
            return ParsedRow::invalid('invalid_code', $line);
        }
        $source = $args[0] ?? '';
        if ($source === '') {
            return ParsedRow::invalid('empty_source', $line);
        }
        $regex = $directive === 'redirectmatch';
        $rule = ['from' => $regex ? $source : RawRule::source($source), 'regex' => $regex, 'code' => $code, 'action' => $action];
        if ($action === 'url') {
            $target = RawRule::target($args[1] ?? '');
            if (($args[1] ?? '') === '') {
                return ParsedRow::invalid('missing_target', $line, RawRule::build($rule));
            }
            if ($target === null) {
                return ParsedRow::invalid('invalid_target', $line, RawRule::build($rule));
            }
            $rule['to'] = $target;
        }
        $rule['ignoreCase'] = false;
        if (!$regex) {
            $rule['ignoreTrailingSlash'] = true;
        }
        return self::finish($rule, $line);
    }

    private static function finish(array $rule, int $line): array
    {
        $rule = RawRule::build($rule);
        if (RawRule::selfRedirect($rule)) {
            return ParsedRow::invalid('self_redirect', $line, $rule);
        }
        if ($rule['regex'] && !Patterns::compiles((string) $rule['from'])) {
            return ParsedRow::invalid('invalid_regex', $line, $rule);
        }
        return ParsedRow::ok($rule, $line);
    }

    /** permanent/temp/seeother/gone or a number → int; null for anything else. */
    private static function code(string $value): ?int
    {
        $value = strtolower(trim($value));
        if (isset(self::WORD_CODES[$value])) {
            return self::WORD_CODES[$value];
        }
        return ctype_digit($value) && strlen($value) === 3 ? (int) $value : null;
    }

    /** `[NC,R=301,L]` → ['NC' => '', 'R' => '301', 'L' => '']; null when not a flag list. */
    private static function flags(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (!str_starts_with($text, '[') || !str_ends_with($text, ']')) {
            return null;
        }
        $out = [];
        foreach (explode(',', substr($text, 1, -1)) as $flag) {
            $flag = trim($flag);
            if ($flag === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $flag, 2), 2, '');
            $out[strtoupper(trim($name))] = trim($value);
        }
        return $out;
    }

    /**
     * Whitespace-separated arguments; double quotes group, backslash escapes
     * are kept as written (they belong to the regex). Null on an unclosed quote.
     *
     * @return list<string>|null
     */
    private static function tokens(string $line): ?array
    {
        $tokens = [];
        $current = '';
        $inToken = false;
        $quoted = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $next = $line[$i + 1];
                $current .= ($quoted && $next === '"') ? '"' : $c . $next;
                $inToken = true;
                $i++;
                continue;
            }
            if ($c === '"') {
                $quoted = !$quoted;
                $inToken = true;
                continue;
            }
            if (!$quoted && ($c === ' ' || $c === "\t")) {
                if ($inToken) {
                    $tokens[] = $current;
                    $current = '';
                    $inToken = false;
                }
                continue;
            }
            $current .= $c;
            $inToken = true;
        }
        if ($quoted) {
            return null;
        }
        if ($inToken) {
            $tokens[] = $current;
        }
        return $tokens === [] ? null : $tokens;
    }

    /**
     * Lines with backslash continuations joined, each with the line it starts on.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function logicalLines(string $text): array
    {
        $out = [];
        $buffer = null;
        $start = 0;
        foreach (explode("\n", $text) as $i => $line) {
            if ($buffer === null) {
                $start = $i + 1;
                $buffer = '';
            }
            if (str_ends_with($line, '\\') && !str_starts_with(ltrim($line), '#')) {
                $buffer .= substr($line, 0, -1) . ' ';
                continue;
            }
            $out[] = [$start, $buffer . $line];
            $buffer = null;
        }
        if ($buffer !== null && $buffer !== '') {
            $out[] = [$start, $buffer];
        }
        return $out;
    }
}
