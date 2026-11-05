<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Export;

use ProtoBlocks\Modules\Redirects\Import\Patterns;

/**
 * Netlify `_redirects` export (spec §9): `from to status` per line, enabled
 * rules only. Literal sources, `^/prefix/(.*)$` regexes (as `/prefix/*` with
 * `:splat`) and anchored literal regexes are expressible; `pass` is status
 * 200. Everything else is a `# skipped:` comment.
 */
final class NetlifyExporter implements ExporterInterface
{
    public function format(): string
    {
        return 'redirects';
    }

    public function mime(): string
    {
        return 'text/plain';
    }

    public function extension(): string
    {
        return '';
    }

    public function render(array $groups, array $hits): string
    {
        $lines = [Support::BEGIN];
        foreach (Support::groups($groups) as $group) {
            if (($group['enabled'] ?? true) === false) {
                continue;
            }
            $block = [];
            foreach (Support::rules($group) as $rule) {
                if (Support::active($rule, $group)) {
                    $block[] = self::rule($rule);
                }
            }
            if ($block !== []) {
                $lines[] = Support::groupComment($group);
                array_push($lines, ...$block);
            }
        }
        $lines[] = Support::END;
        return implode("\n", $lines) . "\n";
    }

    private static function rule(array $rule): string
    {
        if (($reason = Support::unsafe($rule)) !== null) {
            return Support::skipped($rule, $reason);
        }
        $type = Support::whenType($rule);
        if ($type === '404' || !is_string($rule['from'] ?? null)) {
            return Support::skipped($rule, '404 rules need WordPress to know the page is missing');
        }
        if ($type !== null) {
            return Support::skipped($rule, 'condition "' . $type . '" has no _redirects equivalent');
        }
        $action = Support::action($rule);
        if ($action === 'error') {
            return Support::skipped($rule, 'error responses need a page to serve in _redirects');
        }
        if ($action === 'nothing') {
            return Support::skipped($rule, '"nothing" has no _redirects equivalent');
        }
        $to = (string) ($rule['to'] ?? '');

        if ($rule['regex'] ?? false) {
            $from = self::fromRegex($rule['from']);
            if ($from === null) {
                return Support::skipped($rule, 'only ^/prefix/(.*)$ regexes can be written as a _redirects splat');
            }
            $to = str_replace(['${1}', '$1'], ':splat', $to);
            if (preg_match('/\$\{?\d/', $to) === 1) {
                return Support::skipped($rule, 'the target uses a capture other than the splat');
            }
        } else {
            $from = $rule['from'];
            if (str_contains($from, '?')) {
                return Support::skipped($rule, 'query matching needs named placeholders, which are not exported');
            }
            if (str_contains($from, '*') || str_contains($from, ':')) {
                return Support::skipped($rule, 'the source holds a character _redirects reads as a placeholder');
            }
        }
        if (strpbrk($from . $to, " \t") !== false) {
            return Support::skipped($rule, 'a value holds a space');
        }
        $status = $action === 'pass' ? 200 : Support::code($rule);
        return $from . ' ' . $to . ' ' . $status;
    }

    /** `^/prefix/(.*)$` → `/prefix/*`, `^/literal$` → `/literal`, else null. */
    private static function fromRegex(string $pattern): ?string
    {
        if (str_ends_with($pattern, '(.*)$') && str_starts_with($pattern, '^/')) {
            $prefix = Patterns::literal(substr($pattern, 1, -5));
            return $prefix === null ? null : $prefix . '*';
        }
        $literal = Patterns::anchoredLiteral($pattern);
        return $literal !== null && str_starts_with($literal[0], '/') ? $literal[0] : null;
    }
}
