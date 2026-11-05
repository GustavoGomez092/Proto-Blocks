<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Export;

use ProtoBlocks\Modules\Redirects\Import\Patterns;

/**
 * Nginx export (spec §9), meant to be included inside a `server {}` block:
 * `rewrite ^…$ target permanent|redirect;` for 301/302, `location ~ … { return
 * code target; }` for 303/307/308, `location = /path { return code; }` for
 * errors, `(?i)` / `~*` for ignoreCase, `if ($http_user_agent …)`,
 * `if ($http_referer …)` and `if ($host …)` for conditions. Outside the
 * `pass` query mode the target ends in `?` so nginx drops the request's
 * arguments. Anything else is a `# skipped:` comment.
 */
final class NginxExporter implements ExporterInterface
{
    private const COND_VARS = ['referrer' => '$http_referer', 'agent' => '$http_user_agent', 'server' => '$host'];

    public function format(): string
    {
        return 'nginx';
    }

    public function mime(): string
    {
        return 'text/plain';
    }

    public function extension(): string
    {
        return 'conf';
    }

    public function render(array $groups, array $hits): string
    {
        $lines = [Support::BEGIN, '# Include inside the server {} block.'];
        foreach (Support::groups($groups) as $group) {
            if (($group['enabled'] ?? true) === false) {
                continue;
            }
            $block = [];
            foreach (Support::rules($group) as $rule) {
                if (Support::active($rule, $group)) {
                    array_push($block, ...self::rule($rule, $group));
                }
            }
            if ($block !== []) {
                $lines[] = '';
                $lines[] = Support::groupComment($group);
                array_push($lines, ...$block);
            }
        }
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
            return [Support::skipped($rule, 'condition "' . $type . '" has no nginx equivalent')];
        }
        $action = Support::action($rule);
        if ($action === 'nothing') {
            return [Support::skipped($rule, '"nothing" has no nginx equivalent; the request is served as usual')];
        }
        foreach (['to', 'else'] as $key) {
            if (is_string($rule[$key] ?? null) && preg_match('/\$(?![0-9]|\{[0-9]\})/', $rule[$key]) === 1) {
                return [Support::skipped($rule, 'the target holds a "$" nginx would read as a variable')];
            }
        }

        $variables = [(string) $rule['from'], ...array_map('strval', (array) ($rule['when']['value'] ?? []))];
        foreach ($variables as $text) {
            if (preg_match('/\$[A-Za-z_{]/', $text) === 1) {
                return [Support::skipped($rule, 'a pattern holds a "$" nginx would read as a variable')];
            }
        }

        $regex = (bool) ($rule['regex'] ?? false);
        $ignoreCase = (bool) Support::flag($rule, $group, 'ignoreCase', true);
        $trailing = (bool) Support::flag($rule, $group, 'ignoreTrailingSlash', true);
        $query = (string) Support::flag($rule, $group, 'query', 'exact');
        $code = Support::code($rule);

        $queryIf = null;
        if ($regex) {
            if (str_contains($rule['from'], '\\?')) {
                return [Support::skipped($rule, 'the regex matches the query string, which nginx locations cannot see')];
            }
            $pattern = $rule['from'];
            $path = null;
        } else {
            [$path, $qs] = Support::splitQuery($rule['from']);
            $pattern = Support::literalRegex($path, $trailing, '/');
            if ($qs !== null) {
                $queryIf = self::queryIf($qs, $query);
                if ($queryIf === null) {
                    return [Support::skipped($rule, 'several query parameters cannot be matched by one nginx if')];
                }
            }
        }
        $whenIf = $type !== null ? self::whenIf($rule['when'], false) : null;
        if ($queryIf !== null && $whenIf !== null) {
            return [Support::skipped($rule, 'nginx cannot combine a query and a condition in one if')];
        }
        $if = $queryIf ?? $whenIf;

        if ($action === 'error') {
            if ($if !== null) {
                return [Support::skipped($rule, 'conditional error responses have no nginx equivalent here')];
            }
            if (!$regex && !$ignoreCase && !$trailing) {
                return ['location = ' . self::arg($path) . ' { return ' . $code . '; }'];
            }
            return ['location ' . ($ignoreCase ? '~* ' : '~ ') . self::arg($pattern) . ' { return ' . $code . '; }'];
        }

        $target = self::target((string) ($rule['to'] ?? ''), $query);
        if ($action === 'pass' || $code === 301 || $code === 302) {
            $flag = $action === 'pass' ? 'last' : ($code === 301 ? 'permanent' : 'redirect');
            $line = 'rewrite ' . self::arg(($ignoreCase ? '(?i)' : '') . $pattern) . ' ' . self::arg($target) . ' ' . $flag . ';';
            $out = [$if === null ? $line : $if . ' { ' . $line . ' }'];
            if ($whenIf !== null && $action === 'url' && is_string($rule['else'] ?? null) && $rule['else'] !== '') {
                $else = 'rewrite ' . self::arg(($ignoreCase ? '(?i)' : '') . $pattern) . ' ' . self::arg(self::target($rule['else'], $query)) . ' ' . $flag . ';';
                $out[] = self::whenIf($rule['when'], true) . ' { ' . $else . ' }';
            }
            return $out;
        }

        // 303 / 307 / 308: rewrite only knows 301 and 302.
        if ($if !== null) {
            return [Support::skipped($rule, 'a conditional ' . $code . ' has no nginx equivalent here')];
        }
        $returnTarget = $query === 'pass' ? rtrim($target, '?') . '$is_args$args' : rtrim($target, '?');
        return ['location ' . ($ignoreCase ? '~* ' : '~ ') . self::arg($pattern) . ' { return ' . $code . ' ' . self::arg($returnTarget) . '; }'];
    }

    /** `${1}` → `$1`; outside `pass`, a trailing `?` so nginx drops the request's arguments. */
    private static function target(string $target, string $query): string
    {
        $target = (string) preg_replace('/\$\{([0-9])\}/', '\$$1', $target);
        return $query === 'pass' ? $target : $target . '?';
    }

    private static function queryIf(string $qs, string $mode): ?string
    {
        if ($mode === 'exact' || $mode === 'exactOrder') {
            return 'if ($args = ' . self::quoted($qs) . ')';
        }
        $pairs = array_values(array_filter(explode('&', $qs), 'strlen'));
        if (count($pairs) !== 1) {
            return null;
        }
        [$key, $value] = array_pad(explode('=', $pairs[0], 2), 2, '');
        if (preg_match('/^[A-Za-z0-9_]+$/', $key) !== 1) {
            return null;
        }
        return 'if ($arg_' . $key . ' = ' . self::quoted($value) . ')';
    }

    private static function whenIf(array $when, bool $negate): string
    {
        $type = (string) $when['type'];
        $value = (string) $when['value'];
        if ($type === 'server') {
            $op = $negate ? '!=' : '=';
            $value = strtolower($value);
        } elseif ($when['regex'] ?? false) {
            $op = $negate ? '!~*' : '~*';
        } else {
            $op = $negate ? '!=' : '=';
        }
        return 'if (' . self::COND_VARS[$type] . ' ' . $op . ' ' . self::quoted($value) . ')';
    }

    private static function quoted(string $value): string
    {
        $value = str_replace('"', '\\"', $value);
        if (str_ends_with($value, '\\') && !str_ends_with($value, '\\\\')) {
            $value .= '\\';
        }
        return '"' . $value . '"';
    }

    /** A bare argument unless it holds characters nginx treats specially. */
    private static function arg(string $value): string
    {
        if ($value !== '' && strpbrk($value, " \t\"'{};") === false) {
            return $value;
        }
        return '"' . str_replace('"', '\\"', $value) . '"';
    }
}
