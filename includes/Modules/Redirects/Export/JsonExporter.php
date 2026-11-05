<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Export;

use ProtoBlocks\Modules\Redirects\Import\RawRule;

/**
 * JSON export in the Proto-Blocks shape `{ "groups": { "<slug>": <group> }, "settings"?: {...} }`.
 * Lossless for configuration (hits are runtime data and are left out).
 * Stable: groups in evaluation order, rule keys in spec §1 order, two-space indent.
 */
final class JsonExporter implements ExporterInterface
{
    private const WHEN_KEYS = ['type', 'name', 'value', 'regex'];
    private const DEFAULT_KEYS = ['ignoreCase', 'ignoreTrailingSlash', 'query'];

    public function __construct(private readonly ?array $settings = null)
    {
    }

    public function format(): string
    {
        return 'json';
    }

    public function mime(): string
    {
        return 'application/json';
    }

    public function extension(): string
    {
        return 'json';
    }

    public function render(array $groups, array $hits): string
    {
        $out = [];
        foreach (Support::groups($groups) as $group) {
            $slug = (string) ($group['slug'] ?? '');
            $entry = [
                'title' => (string) ($group['title'] ?? $slug),
                'enabled' => ($group['enabled'] ?? true) !== false,
                'position' => (int) ($group['position'] ?? 10),
            ];
            if (is_array($group['defaults'] ?? null)) {
                $defaults = [];
                foreach (self::DEFAULT_KEYS as $key) {
                    if (array_key_exists($key, $group['defaults'])) {
                        $defaults[$key] = $group['defaults'][$key];
                    }
                }
                if ($defaults !== []) {
                    $entry['defaults'] = $defaults;
                }
            }
            $entry['rules'] = array_map([self::class, 'rule'], Support::rules($group));
            $out[$slug] = $entry;
        }

        $data = ['groups' => $out === [] ? new \stdClass() : $out];
        if ($this->settings !== null) {
            $data['settings'] = $this->settings;
        }
        $json = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        // json_encode indents with four spaces; string values never hold raw line breaks.
        $json = (string) preg_replace_callback('/^( +)/m', static fn(array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json);
        return $json . "\n";
    }

    private static function rule(array $rule): array
    {
        $out = RawRule::build($rule);
        if (($out['headers'] ?? null) === []) {
            unset($out['headers']);
        }
        if (is_array($out['headers'] ?? null)) {
            $out['headers'] = (object) $out['headers'];
        }
        if (is_array($out['when'] ?? null)) {
            $when = [];
            foreach (self::WHEN_KEYS as $key) {
                if (array_key_exists($key, $out['when']) && $out['when'][$key] !== null) {
                    $when[$key] = $out['when'][$key];
                }
            }
            $out['when'] = $when + array_filter($out['when'], static fn($v) => $v !== null);
        }
        return $out;
    }
}
