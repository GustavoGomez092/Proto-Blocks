<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Definition;

/**
 * Fills a raw rule (file or PHP) into the normalised Rule shape of the
 * interface contract. Tolerant of any input (validation is RuleValidator's
 * job) and idempotent: normalising a normalised rule returns it unchanged
 * (minus the registry-added keys).
 *
 * `from` is kept as written except that an absolute http(s) URL on the
 * site's own host is reduced to its path (and query). In a non-regex
 * `from`, `\?` is a literal question mark, never the query separator.
 */
final class RuleNormalizer
{
    public const ACTIONS = ['url', 'error', 'pass', 'nothing'];
    public const LIST_TYPES = ['ip', 'language'];
    public const NAMED_TYPES = ['header', 'cookie'];
    public const REGEX_TYPES = ['referrer', 'agent', 'header', 'cookie'];
    public const BUILTIN_DEFAULTS = ['ignoreCase' => true, 'ignoreTrailingSlash' => true, 'query' => 'exact'];

    /**
     * @param array<string|int, mixed> $raw
     * @param array<string, mixed>     $groupDefaults ignoreCase / ignoreTrailingSlash / query (site defaults merged with the group's)
     * @return array<string, mixed> Rule
     */
    public static function normalize(array $raw, array $groupDefaults, string $groupSlug): array
    {
        $defaults = Settings::flags($groupDefaults, self::BUILTIN_DEFAULTS);
        $action = in_array($raw['action'] ?? null, self::ACTIONS, true) ? $raw['action'] : 'url';
        $regex = ($raw['regex'] ?? false) === true;
        $when = self::when($raw['when'] ?? null);

        $from = is_string($raw['from'] ?? null) ? $raw['from'] : null;
        if ($from !== null && !$regex) {
            $from = self::reduce($from);
        }

        $code = $raw['code'] ?? null;
        if (is_float($code) && floor($code) === $code) {
            $code = (int) $code;
        }
        if (!is_int($code)) {
            $code = match ($action) {
                'url' => 301,
                'error' => 404,
                default => null,
            };
        }

        $headers = [];
        foreach (is_array($raw['headers'] ?? null) ? $raw['headers'] : [] as $name => $value) {
            if (is_string($value)) {
                $headers[(string) $name] = $value;
            }
        }

        $rule = [
            'id' => '',
            'title' => is_string($raw['title'] ?? null) ? $raw['title'] : '',
            'enabled' => ($raw['enabled'] ?? true) !== false,
            'from' => $from,
            'regex' => $regex,
            'to' => is_string($raw['to'] ?? null) ? $raw['to'] : null,
            'else' => is_string($raw['else'] ?? null) ? $raw['else'] : null,
            'code' => $code,
            'action' => $action,
            'when' => $when,
            'query' => in_array($raw['query'] ?? null, Settings::QUERY_MODES, true) ? $raw['query'] : $defaults['query'],
            'ignoreCase' => is_bool($raw['ignoreCase'] ?? null) ? $raw['ignoreCase'] : $defaults['ignoreCase'],
            'ignoreTrailingSlash' => is_bool($raw['ignoreTrailingSlash'] ?? null) ? $raw['ignoreTrailingSlash'] : $defaults['ignoreTrailingSlash'],
            'log' => ($raw['log'] ?? true) !== false,
            'headers' => $headers,
        ];
        $rule['id'] = is_string($raw['id'] ?? null) && $raw['id'] !== ''
            ? $raw['id']
            : self::deriveId($groupSlug, $from, $when, $action);

        return $rule;
    }

    /**
     * The id of a rule written without one: `r_` + 8 hex of
     * sha1(slug NUL from NUL json(when) NUL action).
     *
     * @param array<string, mixed>|null $when normalised condition
     */
    public static function deriveId(string $groupSlug, ?string $from, ?array $when, string $action): string
    {
        $json = (string) json_encode($when, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return 'r_' . substr(sha1($groupSlug . "\0" . ($from ?? '') . "\0" . $json . "\0" . $action), 0, 8);
    }

    /**
     * The normalised condition: always the keys type, value, name, regex.
     *
     * @return array{type: string, value: mixed, name: ?string, regex: bool}|null
     */
    public static function when(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $type = $raw['type'] ?? '';
        $type = is_string($type) ? $type : (is_int($type) ? (string) $type : '');
        $value = $raw['value'] ?? null;
        if (in_array($type, self::LIST_TYPES, true) && is_string($value)) {
            $value = [$value];
        }
        if ($type === 'server' && is_string($value)) {
            $value = mb_strtolower($value, 'UTF-8');
        }
        $name = is_string($raw['name'] ?? null) ? $raw['name'] : null;
        if ($type === 'header' && $name !== null) {
            $name = implode('-', array_map(static fn(string $part): string => ucfirst(strtolower($part)), explode('-', $name)));
        }

        return [
            'type' => $type,
            'value' => $value,
            'name' => $name,
            'regex' => ($raw['regex'] ?? false) === true,
        ];
    }

    /** An absolute URL on the site's host reduced to its path (and query); anything else unchanged. */
    public static function reduce(string $from): string
    {
        $parts = Patterns::splitAbsolute($from);
        if ($parts === null || $parts[0] === null || $parts[0] !== Patterns::siteHost()) {
            return $from;
        }
        $rest = $parts[1];

        return $rest === '' || $rest[0] !== '/' ? '/' . $rest : $rest;
    }

    /** The key two non-regex paths compare by: lower-cased, trailing slashes off, root kept. */
    public static function pathKey(string $path): string
    {
        $key = rtrim(mb_strtolower($path, 'UTF-8'), '/');

        return $key === '' ? '/' : $key;
    }
}
