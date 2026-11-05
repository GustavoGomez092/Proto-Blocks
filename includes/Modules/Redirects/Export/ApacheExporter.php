<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Export;

use ProtoBlocks\Modules\Redirects\Import\Patterns;

/**
 * Apache export (spec §9): a `<IfModule mod_rewrite.c>` block between the
 * `# Proto-Blocks redirects` markers, enabled rules only. Patterns start
 * with `^/?` so they work in `.htaccess` and in server context. Conditions
 * referrer/agent/server and the source's query are exported as `RewriteCond`;
 * everything else is a `# skipped: <reason>` comment.
 */
final class ApacheExporter implements ExporterInterface
{
    private const COND_VARS = ['referrer' => 'HTTP_REFERER', 'agent' => 'HTTP_USER_AGENT', 'server' => 'HTTP_HOST'];

    public function format(): string
    {
        return 'apache';
    }

    public function mime(): string
    {
        return 'text/plain';
    }

    public function extension(): string
    {
        return 'htaccess';
    }

    public function render(array $groups, array $hits): string
    {
        $lines = [Support::BEGIN, '<IfModule mod_rewrite.c>', 'RewriteEngine On'];
        foreach (Support::groups($groups) as $group) {
            if (($group['enabled'] ?? true) === false) {
                continue;
            }
            $block = [];
            foreach (Support::rules($group) as $rule) {
                if (!Support::active($rule, $group)) {
                    continue;
                }
                array_push($block, ...self::rule($rule, $group));
            }
            if ($block !== []) {
                $lines[] = '';
                $lines[] = Support::groupComment($group);
                array_push($lines, ...$block);
            }
        }
        $lines[] = '</IfModule>';
        $lines[] = Support::END;
        return implode("\n", $lines) . "\n";
    }

    /** @return list<string> */
    private static function rule(array $rule, array $group): array
    {
        if (($reason = Support::unsafe($rule)) !== null) {
            return [Support::skipped($rule, $reason)];
        }
        $type = Support::whenType($rule);
        if ($type === '404' || !is_string($rule['from'] ?? null)) {
            return [Support::skipped($rule, '404 rules need WordPress to know the page is missing')];
        }
        if ($type !== null && !isset(self::COND_VARS[$type])) {
            return [Support::skipped($rule, 'condition "' . $type . '" has no Apache equivalent')];
        }

        $regex = (bool) ($rule['regex'] ?? false);
        $ignoreCase = (bool) Support::flag($rule, $group, 'ignoreCase', true);
        $query = (string) Support::flag($rule, $group, 'query', 'exact');
        $conds = [];

        if ($regex) {
            if (str_contains($rule['from'], '\\?')) {
                return [Support::skipped($rule, 'the regex matches the query string, which RewriteRule cannot see')];
            }
            $pattern = str_starts_with($rule['from'], '^/') ? '^/?' . substr($rule['from'], 2) : $rule['from'];
        } else {
            [$path, $qs] = Support::splitQuery($rule['from']);
            $pattern = Support::literalRegex($path, (bool) Support::flag($rule, $group, 'ignoreTrailingSlash', true), '/?');
            if ($qs !== null) {
                $conds = self::queryConds($qs, $query, $ignoreCase);
            }
        }

        $action = Support::action($rule);
        $flags = [];
        $target = '-';
        $code = Support::code($rule);
        if ($action === 'error') {
            $flags = $code === 403 ? ['F'] : ($code === 410 ? ['G'] : ['R=' . $code, 'L']);
        } elseif ($action === 'nothing') {
            $flags = ['L'];
        } else {
            $target = (string) ($rule['to'] ?? '');
            $flags = $action === 'url' ? ['R=' . $code, 'L'] : ['L'];
        }
        if ($ignoreCase) {
            $flags[] = 'NC';
        }
        if ($query === 'pass' && ($action === 'url' || $action === 'pass')) {
            $flags[] = 'QSA';
        }
        if ($target !== '-' && strpbrk($target, '#%') !== false) {
            $flags[] = 'NE';
            $target = str_replace('%', '\\%', $target);
        }

        $out = [];
        $line = 'RewriteRule ' . self::arg($pattern) . ' ' . self::arg($target) . ' [' . implode(',', $flags) . ']';
        $when = $type !== null ? self::whenCond($rule['when'], false) : null;
        array_push($out, ...$conds);
        if ($when !== null) {
            $out[] = $when;
        }
        $out[] = $line;

        // `else` → the same rule with the condition negated and the other target.
        if ($when !== null && $action === 'url' && is_string($rule['else'] ?? null) && $rule['else'] !== '') {
            $else = (string) $rule['else'];
            $elseFlags = array_values(array_diff($flags, ['NE']));
            if (strpbrk($else, '#%') !== false) {
                $elseFlags[] = 'NE';
                $else = str_replace('%', '\\%', $else);
            }
            array_push($out, ...$conds);
            $out[] = self::whenCond($rule['when'], true);
            $out[] = 'RewriteRule ' . self::arg($pattern) . ' ' . self::arg($else) . ' [' . implode(',', $elseFlags) . ']';
        }
        return $out;
    }

    /** @return list<string> */
    private static function queryConds(string $qs, string $mode, bool $ignoreCase): array
    {
        $nc = $ignoreCase ? ' [NC]' : '';
        if ($mode === 'exact' || $mode === 'exactOrder') {
            return ['RewriteCond %{QUERY_STRING} ' . self::arg('^' . Patterns::quote($qs) . '$') . $nc];
        }
        // ignore / pass: each source parameter must be present.
        $out = [];
        foreach (explode('&', $qs) as $pair) {
            if ($pair !== '') {
                $out[] = 'RewriteCond %{QUERY_STRING} ' . self::arg('(^|&)' . Patterns::quote($pair) . '(&|$)') . $nc;
            }
        }
        return $out;
    }

    private static function whenCond(array $when, bool $negate): string
    {
        $type = (string) $when['type'];
        $value = (string) $when['value'];
        if ($type === 'server') {
            $pattern = '^' . Patterns::quote(strtolower($value)) . '$';
            $flags = ' [NC]';
        } elseif ($when['regex'] ?? false) {
            $pattern = $value;
            $flags = ' [NC]';
        } else {
            $pattern = '^' . Patterns::quote($value) . '$';
            $flags = '';
        }
        return 'RewriteCond %{' . self::COND_VARS[$type] . '} ' . self::arg(($negate ? '!' : '') . $pattern) . $flags;
    }

    /** One directive argument, quoted when it holds whitespace or a quote. */
    private static function arg(string $value): string
    {
        if ($value !== '' && strpbrk($value, " \t\"") === false) {
            return $value;
        }
        return '"' . str_replace('"', '\\"', $value) . '"';
    }
}
