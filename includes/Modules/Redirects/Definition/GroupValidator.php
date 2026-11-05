<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Definition;

/**
 * A whole group file: the schema (`$defs/group`), every rule's checks
 * (RuleValidator), `duplicate_rule` (two rules with the same id, explicit
 * or derived) and the `redirect_chain` warning (a plain rule whose target is
 * another enabled rule's source, in this group or in $otherRules).
 */
final class GroupValidator
{
    /** Group slug grammar: content-model slug, 60 characters at most, `settings` reserved. */
    public const SLUG_PATTERN = '/^(?!settings$)[a-z][a-z0-9_-]{0,59}$/D';

    /**
     * @param array<string|int, mixed>   $data       the decoded group file
     * @param list<array<string, mixed>> $otherRules normalised rules of the other groups (with `group`), for chains
     * @return array{errors: list<array{path: string, code: string, message: string, level: string}>, warnings: list<array{path: string, code: string, message: string, level: string}>}
     */
    public static function validate(array $data, array $otherRules = [], string $slug = ''): array
    {
        $result = self::analyse($data, $slug, RuleNormalizer::BUILTIN_DEFAULTS, $otherRules);

        return ['errors' => $result['errors'], 'warnings' => $result['warnings']];
    }

    /**
     * validate() plus what the registry needs: whether the group itself is
     * usable and its valid rules, normalised, keyed by file position.
     *
     * @param array<string|int, mixed>   $data
     * @param array<string, mixed>       $inherited site defaults (settings `defaults`)
     * @param list<array<string, mixed>>|null $otherRules null: no chain check
     * @return array{errors: list<array<string, string>>, warnings: list<array<string, string>>, groupValid: bool, defaults: array{ignoreCase: bool, ignoreTrailingSlash: bool, query: string}, rules: array<int, array<string, mixed>>, invalid: array<int, true>}
     */
    public static function analyse(array $data, string $slug, array $inherited, ?array $otherRules = []): array
    {
        $errors = [];
        $warnings = [];
        $badRules = [];
        $groupValid = true;
        foreach (Schema::check($data, 'group') as $finding) {
            $errors[] = $finding;
            if (preg_match('~^/rules/(\d+)(?:/|$)~', $finding['path'], $m) === 1) {
                $badRules[(int) $m[1]] = true;
            } else {
                $groupValid = false;
            }
        }

        $defaults = Settings::flags(
            is_array($data['defaults'] ?? null) ? $data['defaults'] : [],
            Settings::flags($inherited, RuleNormalizer::BUILTIN_DEFAULTS)
        );
        if (!$groupValid) {
            return ['errors' => $errors, 'warnings' => $warnings, 'groupValid' => false, 'defaults' => $defaults, 'rules' => [], 'invalid' => $badRules];
        }

        $rules = [];
        $ids = [];
        $list = is_array($data['rules'] ?? null) ? $data['rules'] : [];
        foreach ($list as $i => $raw) {
            if (!is_array($raw)) {
                $badRules[$i] = true;
                continue;
            }
            $rule = RuleNormalizer::normalize($raw, $defaults, $slug);
            $at = '/rules/' . $i;
            if (isset($ids[$rule['id']])) {
                $errors[] = [
                    'path' => $at . '/id',
                    'code' => 'duplicate_rule',
                    'message' => sprintf(
                        /* translators: 1: rule id, 2: position of the earlier rule */
                        __('Rule id %1$s is already used by rule %2$d of this group. Give one of them its own "id" (two rules without ids that share source, condition and action derive the same id).', 'proto-blocks'),
                        $rule['id'],
                        $ids[$rule['id']]
                    ),
                    'level' => 'error',
                ];
                $badRules[$i] = true;
            } else {
                $ids[$rule['id']] = $i;
            }
            if (isset($badRules[$i])) {
                continue;
            }
            foreach (RuleValidator::validate($rule, $at) as $finding) {
                if ($finding['level'] === 'error') {
                    $errors[] = $finding;
                    $badRules[$i] = true;
                } else {
                    $warnings[] = $finding;
                }
            }
            if (!isset($badRules[$i])) {
                $rules[$i] = $rule;
            }
        }

        if ($otherRules !== null && ($data['enabled'] ?? true) !== false) {
            $own = [];
            foreach ($rules as $i => $rule) {
                $own[] = $rule + ['group' => $slug, 'index' => $i];
            }
            foreach (self::chains($own, $otherRules) as [$rule, $next]) {
                $warnings[] = [
                    'path' => '/rules/' . $rule['index'] . '/to',
                    'code' => 'redirect_chain',
                    'message' => self::chainMessage($rule, $next),
                    'level' => 'warning',
                ];
            }
        }

        ksort($badRules);

        return ['errors' => $errors, 'warnings' => $warnings, 'groupValid' => true, 'defaults' => $defaults, 'rules' => $rules, 'invalid' => $badRules];
    }

    /**
     * Chains starting in $rules: an enabled plain `url` rule whose target is
     * the source of another enabled plain rule of $rules or $others.
     *
     * @param list<array<string, mixed>> $rules  normalised, with `group`
     * @param list<array<string, mixed>> $others normalised, with `group`
     * @return list<array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function chains(array $rules, array $others): array
    {
        $sources = [];
        foreach ([...$rules, ...$others] as $rule) {
            if (($rule['enabled'] ?? true) === true && ($rule['regex'] ?? false) !== true && is_string($rule['from'] ?? null) && $rule['from'] !== '') {
                $sources[RuleNormalizer::pathKey($rule['from'])][] = $rule;
            }
        }

        $out = [];
        foreach ($rules as $rule) {
            if (($rule['enabled'] ?? true) !== true || ($rule['action'] ?? 'url') !== 'url' || ($rule['regex'] ?? false) === true || !is_string($rule['to'] ?? null)) {
                continue;
            }
            $target = RuleNormalizer::reduce($rule['to']);
            if ($target === '' || $target[0] !== '/') {
                continue;
            }
            foreach ($sources[RuleNormalizer::pathKey($target)] ?? [] as $next) {
                if (($next['group'] ?? null) === ($rule['group'] ?? null) && ($next['id'] ?? null) === ($rule['id'] ?? null)) {
                    continue;
                }
                $out[] = [$rule, $next];
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $next
     */
    public static function chainMessage(array $rule, array $next): string
    {
        return sprintf(
            /* translators: 1: rule id, 2: its group, 3: source, 4: target, 5: second rule id, 6: its group */
            __('Rule %1$s (%2$s) redirects %3$s to %4$s, which rule %5$s (%6$s) redirects again. Point the first rule at the final target.', 'proto-blocks'),
            (string) $rule['id'],
            (string) ($rule['group'] ?? ''),
            (string) $rule['from'],
            (string) $rule['to'],
            (string) $next['id'],
            (string) ($next['group'] ?? '')
        );
    }
}
