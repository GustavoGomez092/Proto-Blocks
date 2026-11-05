<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

/**
 * Maps one Redirection item (its JSON export row or its `redirection_items`
 * database row) to a ParsedRow with spec §1 keys. Shared by JsonFormat and
 * RedirectionImporter.
 */
final class RedirectionMapper
{
    /** Redirection match type → our `when.type` and the action_data key holding the value. */
    private const CONDITIONS = [
        'referrer' => ['referrer', 'referrer'],
        'agent' => ['agent', 'agent'],
        'header' => ['header', 'value'],
        'cookie' => ['cookie', 'value'],
        'ip' => ['ip', 'ip'],
        'language' => ['language', 'language'],
        'server' => ['server', 'server'],
        'role' => ['role', 'role'],
        'custom' => ['filter', 'filter'],
    ];

    /**
     * @param array<string, mixed> $item
     * @param bool $trustedSerialized true for database rows: serialized PHP is read with allowed_classes => false.
     *                                Uploaded files never get unserialize().
     */
    public static function row(array $item, int $line, bool $trustedSerialized = false): array
    {
        $data = self::decode($item['action_data'] ?? null, $trustedSerialized);
        $matchData = self::decode($item['match_data'] ?? null, false);
        $flags = is_array($matchData['source'] ?? null) ? $matchData['source'] : [];

        $from = trim((string) ($item['url'] ?? ''));
        $regex = isset($item['regex']) ? self::bool($item['regex']) : self::bool($flags['flag_regex'] ?? false);
        $matchType = strtolower((string) ($item['match_type'] ?? 'url'));
        $actionType = strtolower((string) ($item['action_type'] ?? 'url'));
        $code = (int) ($item['action_code'] ?? 0);

        $rule = [];
        if (isset($item['title']) && is_string($item['title']) && trim($item['title']) !== '') {
            $rule['title'] = trim($item['title']);
        }
        if (array_key_exists('enabled', $item)) {
            if (!self::bool($item['enabled'])) {
                $rule['enabled'] = false;
            }
        } elseif (isset($item['status']) && strtolower((string) $item['status']) === 'disabled') {
            $rule['enabled'] = false;
        }
        $rule['from'] = $regex ? $from : RawRule::source($from);
        $rule['regex'] = $regex;
        if (array_key_exists('flag_case', $flags)) {
            $rule['ignoreCase'] = self::bool($flags['flag_case']);
        }
        if (array_key_exists('flag_trailing', $flags)) {
            $rule['ignoreTrailingSlash'] = self::bool($flags['flag_trailing']);
        }
        if (($mode = RawRule::queryMode($flags['flag_query'] ?? null)) !== null) {
            $rule['query'] = $mode;
        }
        if (is_array($matchData['options'] ?? null) && self::bool($matchData['options']['log_exclude'] ?? false)) {
            $rule['log'] = false;
        }

        if ($from === '' && $matchType !== 'page') {
            return ParsedRow::invalid('empty_source', $line, RawRule::build($rule));
        }

        // Action and code.
        switch ($actionType) {
            case 'url':
                if (RawRule::actionForCode($code) !== 'url') {
                    return ParsedRow::invalid('invalid_code', $line, RawRule::build($rule + ['code' => $code]));
                }
                $rule['code'] = $code;
                break;
            case 'error':
                if (RawRule::actionForCode($code) !== 'error') {
                    return ParsedRow::invalid('invalid_code', $line, RawRule::build($rule + ['code' => $code]));
                }
                $rule['code'] = $code;
                break;
            case 'pass':
            case 'nothing':
                break;
            default:
                return ParsedRow::invalid('unsupported_action', $line, RawRule::build($rule));
        }
        $rule['action'] = $actionType;
        $needsTarget = $actionType === 'url' || $actionType === 'pass';

        // Match type → condition and targets.
        $to = null;
        $else = null;
        if ($matchType === 'url' || $matchType === 'page') {
            $to = is_string($data) ? $data : (is_array($data) ? (string) ($data['url'] ?? '') : '');
            if ($matchType === 'page') {
                $rule['when'] = ['type' => '404'];
                if ($from === '') {
                    unset($rule['from']);
                }
            }
        } elseif ($matchType === 'login') {
            $data = is_array($data) ? $data : [];
            $rule['when'] = ['type' => 'login'];
            $to = (string) ($data['logged_in'] ?? '');
            $else = (string) ($data['logged_out'] ?? '');
        } elseif (isset(self::CONDITIONS[$matchType])) {
            $data = is_array($data) ? $data : [];
            $when = self::condition($matchType, $data);
            if ($when === null) {
                return ParsedRow::invalid('unsupported_condition', $line, RawRule::build($rule));
            }
            $rule['when'] = $when;
            $to = (string) ($data['url_from'] ?? '');
            $else = (string) ($data['url_notfrom'] ?? '');
        } else {
            return ParsedRow::invalid('unsupported_condition', $line, RawRule::build($rule));
        }

        if ($needsTarget) {
            if (trim((string) $to) === '') {
                // Redirection allows "only when not matched"; a negated condition has no equivalent here.
                $reason = isset($rule['when']) && trim((string) $else) !== '' ? 'unsupported_condition' : 'missing_target';
                return ParsedRow::invalid($reason, $line, RawRule::build($rule));
            }
            $target = RawRule::target((string) $to);
            if ($target === null) {
                return ParsedRow::invalid('invalid_target', $line, RawRule::build($rule));
            }
            $rule['to'] = $target;
            if ($actionType === 'url' && isset($rule['when']) && trim((string) $else) !== '') {
                $elseTarget = RawRule::target((string) $else);
                if ($elseTarget === null) {
                    return ParsedRow::invalid('invalid_target', $line, RawRule::build($rule));
                }
                $rule['else'] = $elseTarget;
            }
        }

        $rule = RawRule::build($rule);
        if (RawRule::selfRedirect($rule)) {
            return ParsedRow::invalid('self_redirect', $line, $rule);
        }
        return ParsedRow::ok($rule, $line);
    }

    /** @param array<string, mixed> $data */
    private static function condition(string $matchType, array $data): ?array
    {
        [$type, $key] = self::CONDITIONS[$matchType];
        $raw = $data[$key] ?? null;
        switch ($type) {
            case 'ip':
                $list = array_values(array_filter(array_map('trim', array_map('strval', (array) $raw)), 'strlen'));
                return $list === [] ? null : ['type' => 'ip', 'value' => $list];
            case 'language':
                $list = array_values(array_filter(array_map('trim', explode(',', (string) $raw)), 'strlen'));
                return $list === [] ? null : ['type' => 'language', 'value' => $list];
            case 'server':
                $server = trim((string) $raw);
                $host = parse_url(str_contains($server, '://') ? $server : 'http://' . $server, PHP_URL_HOST);
                return is_string($host) && $host !== '' ? ['type' => 'server', 'value' => strtolower($host)] : null;
            case 'role':
            case 'filter':
                $value = trim((string) $raw);
                return $value === '' ? null : ['type' => $type, 'value' => $value];
            case 'header':
            case 'cookie':
                $name = trim((string) ($data['name'] ?? ''));
                if ($name === '') {
                    return null;
                }
                return ['type' => $type, 'name' => $name, 'value' => (string) $raw, 'regex' => self::bool($data['regex'] ?? false)];
            default: // referrer, agent
                return ['type' => $type, 'value' => (string) $raw, 'regex' => self::bool($data['regex'] ?? false)];
        }
    }

    /**
     * action_data / match_data as an array (or a plain string target). A JSON
     * object string is decoded (Redirection's string action_data upgrade);
     * serialized PHP only for trusted database rows, never with classes.
     */
    public static function decode(mixed $value, bool $trustedSerialized): array|string|null
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return json_decode((string) json_encode($value), true) ?: [];
        }
        if (!is_string($value) || $value === '') {
            return $value === null ? null : (string) $value;
        }
        $trimmed = ltrim($value);
        if (str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true, 16);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if ($trustedSerialized && preg_match('/^a:\d+:\{/', $trimmed) === 1) {
            $decoded = @unserialize($trimmed, ['allowed_classes' => false]);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $value;
    }

    public static function bool(mixed $v): bool
    {
        if (is_string($v)) {
            return in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'on', 'enabled'], true);
        }
        return (bool) $v;
    }

    /**
     * Unique group slugs from names: content-model grammar `^[a-z][a-z0-9_-]*$`, ≤ 60, `settings` reserved.
     *
     * @param array<string, true> $taken slugs already used, updated in place
     */
    public static function slug(string $name, array &$taken, string $fallback = 'imported'): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name));
        $slug = trim($slug, '-_');
        if ($slug === '' || !ctype_alpha($slug[0])) {
            $slug = $slug === '' ? $fallback : 'g-' . $slug;
        }
        $slug = rtrim(substr($slug, 0, 60), '-_');
        if ($slug === 'settings') {
            $slug = 'settings-group';
        }
        $base = $slug;
        for ($n = 2; isset($taken[$slug]); $n++) {
            $suffix = '-' . $n;
            $slug = rtrim(substr($base, 0, 60 - strlen($suffix)), '-_') . $suffix;
        }
        $taken[$slug] = true;
        return $slug;
    }
}
