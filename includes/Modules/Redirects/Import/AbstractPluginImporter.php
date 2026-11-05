<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Import;

/**
 * Shared plumbing for the plugin importers: `$wpdb` access, the row cap, and
 * the rows the last preview() could not convert (skipped()).
 */
abstract class AbstractPluginImporter implements PluginImporterInterface
{
    /** Upper bound on source rows read in one preview (Redirection's own candidate cap). */
    public const LIMIT = 20000;

    /** @var list<array> */
    protected array $skipped = [];

    /** @return list<array> invalid ParsedRows from the last preview(); `line` is the source row ordinal */
    public function skipped(): array
    {
        return $this->skipped;
    }

    public function cleanupHint(): ?string
    {
        return null;
    }

    protected function db(): object
    {
        return $GLOBALS['wpdb'];
    }

    /**
     * An option's stored value read straight from the options table, not
     * through get_option(): core's maybe_unserialize() would instantiate any
     * class named in the data. A serialized array is decoded with
     * `allowed_classes => false` (objects come back incomplete); anything
     * else is returned as stored, null when the option is absent.
     */
    protected function rawOption(string $name): mixed
    {
        $db = $this->db();
        $raw = $db->get_var($db->prepare("SELECT option_value FROM {$db->options} WHERE option_name = %s LIMIT 1", $name));
        if (!is_string($raw)) {
            return null;
        }
        if (preg_match('/^a:\d+:\{/', ltrim($raw)) === 1) {
            $decoded = @unserialize(ltrim($raw), ['allowed_classes' => false]);
            return is_array($decoded) ? $decoded : null;
        }

        return $raw;
    }

    protected function tableExists(string $table): bool
    {
        $db = $this->db();
        $found = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($table)));
        return is_string($found) && $found === $table;
    }

    /**
     * Sorts rows into `rules` (ok/duplicate) and skipped() (invalid).
     *
     * @param list<array> $rows ParsedRows
     * @return list<array> raw rules
     */
    protected function keep(array $rows): array
    {
        $rules = [];
        foreach ($rows as $row) {
            if ($row['status'] === ParsedRow::INVALID) {
                $this->skipped[] = $row;
            } else {
                $rules[] = $row['rule'];
            }
        }
        return $rules;
    }

    /** @param list<array> $rules */
    protected static function group(string $title, array $rules, bool $enabled = true, int $position = 10): array
    {
        return ['title' => $title, 'enabled' => $enabled, 'position' => $position, 'rules' => $rules];
    }

    /** A wildcard (`*`) source and its target, as Simple 301 Redirects and Safe Redirect Manager write them. */
    protected static function wildcardRule(string $from, string $to, int $line): array
    {
        $from = trim($from);
        if ($from === '') {
            return ParsedRow::invalid('empty_source', $line);
        }
        $regex = str_contains($from, '*');
        $source = RawRule::source($from);
        $rule = ['from' => $regex ? Patterns::wildcard($source) : $source, 'regex' => $regex];
        $target = RawRule::target($regex ? str_replace('*', '$1', $to) : $to);
        if ($target === null) {
            return ParsedRow::invalid(trim($to) === '' ? 'missing_target' : 'invalid_target', $line, $rule);
        }
        return ['rule' => $rule + ['to' => $target], 'status' => ParsedRow::NEW, 'reason' => null, 'line' => $line];
    }

    /**
     * A source pattern written without the leading slash (Rank Math, Yoast):
     * `^blog/(.*)` → `^/blog/(.*)`; unanchored patterns are left alone.
     */
    protected static function slashAnchored(string $pattern): string
    {
        if (str_starts_with($pattern, '^') && !str_starts_with($pattern, '^/')) {
            return '^/' . substr($pattern, 1);
        }
        return $pattern;
    }

    /** Finishes a rule: canonical order, self-redirect and regex checks. */
    protected static function finish(array $rule, int $line): array
    {
        $rule = RawRule::build($rule);
        if (RawRule::selfRedirect($rule)) {
            return ParsedRow::invalid('self_redirect', $line, $rule);
        }
        if (($rule['regex'] ?? false) && !Patterns::compiles((string) $rule['from'])) {
            return ParsedRow::invalid('invalid_regex', $line, $rule);
        }
        return ParsedRow::ok($rule, $line);
    }

    /** JSON or (trusted, class-free) serialized PHP → array, else []. */
    protected static function decodeList(mixed $value): array
    {
        $decoded = RedirectionMapper::decode($value, true);
        if (is_string($decoded) && str_starts_with(ltrim($decoded), '[')) {
            $decoded = json_decode($decoded, true, 16);
        }
        return is_array($decoded) ? $decoded : [];
    }
}
