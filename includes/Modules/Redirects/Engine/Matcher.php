<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine;

use ProtoBlocks\Modules\Redirects\Engine\Conditions\Factory;

/**
 * Finds the rule that fires for a request (spec §3 "Candidate selection").
 *
 * Rules arrive in evaluation order (group position, slug, index; see
 * order()). They are compiled once into an exact map keyed by
 * SourceMatcher::key(path) and an ordered regex list. A request takes the
 * bucket for its own key plus the regex list (plus the from-less `404`
 * rules), merged back into evaluation order; each candidate then enforces its
 * own flags, query mode, condition and target safety. The first rule whose
 * action fires wins:
 *
 * - `when` true → `to`; false → `else` (url only) or fall through;
 * - `error` / `nothing` / `pass` with a false `when` fall through;
 * - a target refused by TargetBuilder (unsafe, host change, self-loop) falls through;
 * - `404` rules only run in the not-found stage; every other rule only in the init stage.
 */
final class Matcher
{
    /** @var list<array<string,mixed>> */
    private array $rules = [];
    /** @var array<string, list<int>> */
    private array $exact = [];
    /** @var list<int> */
    private array $regex = [];
    /** @var list<int> */
    private array $sourceless = [];
    /** @var array<int, string|false> compiled patterns; false = refused once, never recompiled */
    private array $compiled = [];
    /** @var array<string, array{code: string, group: string, ruleId: string, message: string}> */
    private array $issues = [];

    /**
     * @param list<array<string,mixed>> $enabledRules normalised rules in evaluation order
     * @param array<string,mixed>       $settings
     */
    public function __construct(array $enabledRules, array $settings = [])
    {
        $defaults = is_array($settings['defaults'] ?? null) ? $settings['defaults'] : [];
        foreach (array_values($enabledRules) as $i => $rule) {
            $rule += [
                'ignoreCase' => (bool) ($defaults['ignoreCase'] ?? true),
                'ignoreTrailingSlash' => (bool) ($defaults['ignoreTrailingSlash'] ?? true),
                'query' => (string) ($defaults['query'] ?? 'exact'),
                'action' => 'url',
                'enabled' => true,
                'regex' => false,
            ];
            $this->rules[$i] = $rule;
            $from = $rule['from'] ?? null;
            if (!is_string($from) || $from === '') {
                $this->sourceless[] = $i;
            } elseif (!empty($rule['regex'])) {
                $this->regex[] = $i;
                $this->compiled[$i] = RegexGuard::compile($from, (bool) $rule['ignoreCase']) ?? false;
            } else {
                $this->exact[SourceMatcher::key(SourceMatcher::parse($from)['path'])][] = $i;
            }
        }
    }

    /**
     * Flattens groups into evaluation order: groups by `position` asc then
     * slug, rules by `index` (file order when absent). Disabled groups
     * contribute nothing; disabled rules stay in (with enabled false) so the
     * trace can name them. Each rule gets `group` and `index`.
     *
     * @param list<array<string,mixed>> $groups
     * @return list<array<string,mixed>>
     */
    public static function order(array $groups): array
    {
        $groups = array_values(array_filter($groups, static fn(array $g): bool => ($g['enabled'] ?? true) !== false));
        usort($groups, static function (array $a, array $b): int {
            return [(int) ($a['position'] ?? 10), (string) ($a['slug'] ?? '')] <=> [(int) ($b['position'] ?? 10), (string) ($b['slug'] ?? '')];
        });
        $out = [];
        foreach ($groups as $group) {
            $rules = [];
            foreach (array_values((array) ($group['rules'] ?? [])) as $i => $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $rule['group'] = (string) ($rule['group'] ?? ($group['slug'] ?? ''));
                $rule['index'] = (int) ($rule['index'] ?? $i);
                $rules[] = $rule;
            }
            usort($rules, static fn(array $a, array $b): int => $a['index'] <=> $b['index']);
            array_push($out, ...$rules);
        }
        return $out;
    }

    /**
     * @return array{rule: array<string,mixed>, target: ?string, action: string, code: ?int, reason: string}|null
     *         reason is 'matched' (when true or absent) or 'else'
     */
    public function match(Request $r, bool $notFoundStage = false): ?array
    {
        foreach ($this->candidates($r) as $i) {
            $step = $this->evaluate($i, $r, $notFoundStage);
            if ($step['match'] !== null) {
                return $step['match'];
            }
        }
        return null;
    }

    /**
     * Every rule in evaluation order up to (and including) the one that fires.
     *
     * @return list<array{group: string, ruleId: string, result: 'matched'|'skipped', reason: string}>
     */
    public function trace(Request $r, bool $notFoundStage = false): array
    {
        $out = [];
        foreach (array_keys($this->rules) as $i) {
            $step = $this->evaluate($i, $r, $notFoundStage);
            $out[] = [
                'group' => (string) ($this->rules[$i]['group'] ?? ''),
                'ruleId' => (string) ($this->rules[$i]['id'] ?? ''),
                'result' => $step['match'] !== null ? 'matched' : 'skipped',
                'reason' => $step['reason'],
            ];
            if ($step['match'] !== null) {
                break;
            }
        }
        return $out;
    }

    /**
     * Problems met while matching (regex errors, refused targets), one per
     * rule and code, for the issues list and the redirect log.
     *
     * @return list<array{code: string, group: string, ruleId: string, message: string}>
     */
    public function issues(): array
    {
        return array_values($this->issues);
    }

    /** @return list<int> */
    private function candidates(Request $r): array
    {
        $list = array_merge($this->exact[SourceMatcher::key($r->path)] ?? [], $this->regex, $this->sourceless);
        sort($list);
        return $list;
    }

    /**
     * @return array{match: ?array, reason: string}
     */
    private function evaluate(int $i, Request $r, bool $notFoundStage): array
    {
        $rule = $this->rules[$i];
        $skip = static fn(string $reason): array => ['match' => null, 'reason' => $reason];

        if (($rule['enabled'] ?? true) === false) {
            return $skip('disabled');
        }
        $when = is_array($rule['when'] ?? null) ? $rule['when'] : null;
        $is404 = $when !== null && (string) ($when['type'] ?? '') === '404';
        if ($is404 !== $notFoundStage) {
            return $skip($notFoundStage ? 'not_404_stage' : '404_stage_only');
        }

        $captures = [];
        $from = $rule['from'] ?? null;
        if (is_string($from) && $from !== '') {
            $error = null;
            $captures = SourceMatcher::matches($rule, $r, $error, $this->compiled[$i] ?? null);
            if ($error !== null) {
                $this->issue('regex_error', $rule, $error);
                return $skip('regex_error');
            }
            if ($captures === null) {
                return $skip('no_match');
            }
        } elseif (!$is404) {
            return $skip('no_source');
        }

        $passed = true;
        if ($when !== null) {
            try {
                $passed = Factory::for((string) ($when['type'] ?? ''))->evaluate($when, $r);
            } catch (\InvalidArgumentException) {
                return $skip('unknown_condition');
            }
        }

        $action = (string) $rule['action'];
        switch ($action) {
            case 'url':
                $chosen = $passed ? ($rule['to'] ?? null) : ($rule['else'] ?? null);
                if (!is_string($chosen) || trim($chosen) === '') {
                    return $skip($passed ? 'no_target' : 'condition_false');
                }
                $built = TargetBuilder::evaluate($rule, $chosen, $r, $captures);
                if ($built['target'] === null) {
                    $this->issue($built['reason'] === 'self_loop' ? 'self_loop' : 'unsafe_target', $rule, 'Target refused: ' . $built['reason']);
                    return $skip($built['reason']);
                }
                $code = (int) ($rule['code'] ?? 301);
                return [
                    'match' => ['rule' => $rule, 'target' => $built['target'], 'action' => 'url', 'code' => $code, 'reason' => $passed ? 'matched' : 'else'],
                    'reason' => $passed ? 'matched' : 'else',
                ];

            case 'pass':
                if (!$passed) {
                    return $skip('condition_false');
                }
                $to = $rule['to'] ?? null;
                if (!is_string($to) || !str_starts_with(trim($to), '/')) {
                    $this->issue('unsafe_target', $rule, 'pass needs a path target');
                    return $skip('unsafe_target');
                }
                $built = TargetBuilder::evaluate($rule, $to, $r, $captures);
                if ($built['target'] === null) {
                    $this->issue('unsafe_target', $rule, 'Target refused: ' . $built['reason']);
                    return $skip($built['reason']);
                }
                return ['match' => ['rule' => $rule, 'target' => $built['target'], 'action' => 'pass', 'code' => null, 'reason' => 'matched'], 'reason' => 'matched'];

            case 'error':
                if (!$passed) {
                    return $skip('condition_false');
                }
                return ['match' => ['rule' => $rule, 'target' => null, 'action' => 'error', 'code' => (int) ($rule['code'] ?? 404), 'reason' => 'matched'], 'reason' => 'matched'];

            case 'nothing':
                if (!$passed) {
                    return $skip('condition_false');
                }
                return ['match' => ['rule' => $rule, 'target' => null, 'action' => 'nothing', 'code' => null, 'reason' => 'matched'], 'reason' => 'matched'];
        }
        return $skip('unknown_action');
    }

    /** @param array<string,mixed> $rule */
    private function issue(string $code, array $rule, string $message): void
    {
        $group = (string) ($rule['group'] ?? '');
        $id = (string) ($rule['id'] ?? '');
        $this->issues[$code . "\0" . $group . "\0" . $id] ??= ['code' => $code, 'group' => $group, 'ruleId' => $id, 'message' => $message];
    }
}
