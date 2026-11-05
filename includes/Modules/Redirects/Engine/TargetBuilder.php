<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Engine;

/**
 * Computes a rule's final target (spec §3 "Target safety", rules 1–4).
 *
 * 1. A target written as a path must still be a path after substitution:
 *    leading `//` collapses to `/`; a `\`, a control character or anything
 *    that no longer starts with `/` makes the rule not fire (`unsafe_target`).
 * 2. A target written absolute keeps its scheme family and host: if
 *    substitution changes the authority, the rule does not fire (`host_changed`).
 * 3. The result goes through wp_sanitize_redirect().
 * 4. A target that normalises to the current request (same host, same path
 *    under the rule's case/trailing-slash flags, same query: raw for
 *    exactOrder, parsed otherwise) does not fire (`self_loop`). A `.*` rule
 *    therefore fires once and stops at its own target.
 *
 * Substitution: `$1`…`$9` and `${n}` from the regex captures (a missing
 * group is empty); spaces become `%20` in the path and `+` in the query.
 * Query mode `pass` appends the request's extra parameters.
 */
final class TargetBuilder
{
    /**
     * @param array<string,mixed>      $rule
     * @param array<int|string,string> $captures
     */
    public static function build(array $rule, string $chosenTarget, Request $r, array $captures): ?string
    {
        return self::evaluate($rule, $chosenTarget, $r, $captures)['target'];
    }

    /**
     * @param array<string,mixed>      $rule
     * @param array<int|string,string> $captures
     * @return array{target: ?string, reason: string} reason is 'ok', 'unsafe_target', 'host_changed' or 'self_loop'
     */
    public static function evaluate(array $rule, string $chosenTarget, Request $r, array $captures): array
    {
        $template = trim($chosenTarget);
        if ($template === '') {
            return ['target' => null, 'reason' => 'unsafe_target'];
        }
        $absolute = preg_match('#^https?://#i', $template) === 1;

        $target = !empty($rule['regex']) ? self::substitute($template, $captures) : $template;
        $target = self::encodeSpaces($target);

        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $target) === 1) {
            return ['target' => null, 'reason' => 'unsafe_target'];
        }

        if ($absolute) {
            if (preg_match('#^https?://#i', $target) !== 1) {
                return ['target' => null, 'reason' => 'unsafe_target'];
            }
            $expected = strtolower(self::authority(self::substitute($template, [])));
            if ($expected === '' || strtolower(self::authority($target)) !== $expected) {
                return ['target' => null, 'reason' => 'host_changed'];
            }
        } else {
            $target = (string) preg_replace('#^/{2,}#', '/', $target);
            if (!str_starts_with($target, '/')) {
                return ['target' => null, 'reason' => 'unsafe_target'];
            }
        }

        if (($rule['query'] ?? 'exact') === 'pass' && empty($rule['regex'])) {
            $target = QueryMatcher::passThrough($target, $r, $rule);
        }

        $target = function_exists('wp_sanitize_redirect') ? (string) wp_sanitize_redirect($target) : $target;
        if ($target === '' || (!$absolute && !str_starts_with($target, '/')) || str_starts_with($target, '//')) {
            return ['target' => null, 'reason' => 'unsafe_target'];
        }

        if (($rule['action'] ?? 'url') === 'url' && self::loops($rule, $target, $r)) {
            return ['target' => null, 'reason' => 'self_loop'];
        }
        return ['target' => $target, 'reason' => 'ok'];
    }

    /** @param array<int|string,string> $captures */
    private static function substitute(string $template, array $captures): string
    {
        return (string) preg_replace_callback(
            '/\$\{(\d{1,2})\}|\$(\d)/',
            static function (array $m) use ($captures): string {
                $index = (int) ($m[1] !== '' ? $m[1] : $m[2]);
                return (string) ($captures[$index] ?? '');
            },
            $template
        );
    }

    private static function encodeSpaces(string $target): string
    {
        if (!str_contains($target, ' ')) {
            return $target;
        }
        $hash = strpos($target, '#');
        $fragment = $hash === false ? '' : str_replace(' ', '%20', substr($target, $hash));
        $rest = $hash === false ? $target : substr($target, 0, $hash);
        $q = strpos($rest, '?');
        if ($q === false) {
            return str_replace(' ', '%20', $rest) . $fragment;
        }
        return str_replace(' ', '%20', substr($rest, 0, $q)) . '?' . str_replace(' ', '+', substr($rest, $q + 1)) . $fragment;
    }

    /** The `user@host:port` part of an absolute URL, '' when there is none. */
    private static function authority(string $url): string
    {
        return preg_match('#^https?://([^/?\#]*)#i', $url, $m) === 1 ? $m[1] : '';
    }

    /** @param array<string,mixed> $rule */
    private static function loops(array $rule, string $target, Request $r): bool
    {
        $pathAndQuery = $target;
        if (preg_match('#^https?://#i', $target) === 1) {
            $targetRequest = Request::fromUrl($target);
            if ($targetRequest->host !== '' && $r->host !== '' && $targetRequest->host !== $r->host) {
                return false;
            }
            $pathAndQuery = $targetRequest->uri;
        }
        $next = Request::fromUrl($pathAndQuery, ['HTTP_HOST' => $r->host]);
        $ignoreCase = (bool) ($rule['ignoreCase'] ?? true);
        if (!SourceMatcher::pathMatches($next->path, $r->path, $ignoreCase, (bool) ($rule['ignoreTrailingSlash'] ?? true))) {
            return false;
        }
        if (($rule['query'] ?? 'exact') === 'exactOrder') {
            return $ignoreCase ? strtolower($next->rawQuery) === strtolower($r->rawQuery) : $next->rawQuery === $r->rawQuery;
        }
        return QueryMatcher::same($next->query, $r->query, $ignoreCase);
    }
}
