<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Scripts\Sources;

use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\Modules\Scripts\Definition\ScriptNormalizer;
use ProtoBlocks\Modules\Scripts\Definition\ScriptValidator;

/**
 * Scripts declared with proto_script() (spec §4). PHP declarations are
 * trusted theme code, but they still follow the file rules for what is
 * output: inline strings only, no PHP opening tag, known keys only.
 *
 * The store is sealed at `template_redirect` priority 1 (before any output
 * hook runs); a call after that is ignored with `declared_too_late`.
 */
final class PhpDeclarations
{
    public const SEAL_HOOK = 'template_redirect';
    public const SEAL_PRIORITY = 1;

    /** @var array<string, array{data: array<string, mixed>, source: string}> slug => declaration */
    private static array $declared = [];

    /** @var list<Issue> */
    private static array $issues = [];

    private static bool $sealed = false;

    /**
     * Records one declaration. Returns the slug, or null when it was refused
     * (an issue says why).
     *
     * @param array<string, mixed> $args
     */
    public static function add(string $title, array $args, string $source): ?string
    {
        $slug = is_string($args['slug'] ?? null) ? $args['slug'] : self::slugFromTitle($title);
        unset($args['slug']);

        if (self::$sealed) {
            self::$issues[] = new Issue(
                Issue::WARNING,
                'declared_too_late',
                sprintf(
                    /* translators: %s: script title */
                    __('proto_script("%s") was called after the page started rendering (template_redirect) and is ignored. Call it from functions.php or on init.', 'proto-blocks'),
                    self::clip($title)
                ),
                $source,
                $slug !== '' ? $slug : null,
                'script'
            );
            return null;
        }

        if ($slug === '' || preg_match(SchemaFileWriter::EXTENDED_SLUG_PATTERN, $slug) !== 1) {
            self::$issues[] = new Issue(
                Issue::ERROR,
                'invalid_slug',
                sprintf(
                    /* translators: %s: the slug as typed */
                    __('"%s" is not a valid script slug: start with a lowercase letter and use only a-z, 0-9, "_" and "-" (32 characters at most). Pass "slug" to proto_script().', 'proto-blocks'),
                    self::clip($slug)
                ),
                $source,
                null,
                'script'
            );
            return null;
        }

        if (isset(self::$declared[$slug])) {
            self::$issues[] = new Issue(
                Issue::WARNING,
                'duplicate_php_declaration',
                sprintf(
                    /* translators: 1: slug, 2: where it was first declared */
                    __('The script "%1$s" is declared again in PHP; the declaration at %2$s is replaced.', 'proto-blocks'),
                    $slug,
                    self::$declared[$slug]['source']
                ),
                $source,
                $slug,
                'script'
            );
        }

        self::$declared[$slug] = ['data' => ['title' => $title] + $args, 'source' => $source];

        return $slug;
    }

    /**
     * Every valid declaration as a Script, in slug order; invalid ones are
     * left out with their errors in issues().
     *
     * @return array<string, array<string, mixed>> slug => Script
     */
    public static function scripts(): array
    {
        $out = [];
        foreach (self::$declared as $slug => $entry) {
            $out[$slug] = ScriptNormalizer::normalize($entry['data'], $slug, 'php', $entry['source']);
        }
        ksort($out, SORT_STRING);

        return array_filter($out, static fn (array $s): bool => self::findings($s['slug'])['errors'] === []);
    }

    /**
     * The validator's findings for a declared slug.
     *
     * @return array{errors: list<array{path: string, code: string, message: string}>, warnings: list<array{path: string, code: string, message: string}>}
     */
    public static function findings(string $slug): array
    {
        $entry = self::$declared[$slug] ?? null;

        return $entry === null ? ['errors' => [], 'warnings' => []] : ScriptValidator::validate($entry['data'], true);
    }

    /**
     * Issues of the store: refused calls and every declaration's findings.
     *
     * @return list<Issue>
     */
    public static function issues(): array
    {
        $out = self::$issues;
        foreach (self::$declared as $slug => $entry) {
            $found = ScriptValidator::validate($entry['data'], true);
            foreach (['errors' => Issue::ERROR, 'warnings' => Issue::WARNING] as $key => $level) {
                foreach ($found[$key] as $f) {
                    $out[] = new Issue($level, $f['code'], ($f['path'] === '' ? '/' : $f['path']) . ': ' . $f['message'], $entry['source'], (string) $slug, 'script');
                }
            }
        }

        return $out;
    }

    /** Declared slugs (valid or not), for the double-declaration check. */
    public static function slugs(): array
    {
        return array_map('strval', array_keys(self::$declared));
    }

    public static function source(string $slug): ?string
    {
        return self::$declared[$slug]['source'] ?? null;
    }

    public static function seal(): void
    {
        self::$sealed = true;
    }

    public static function sealed(): bool
    {
        return self::$sealed;
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$declared = [];
        self::$issues = [];
        self::$sealed = false;
    }

    public static function slugFromTitle(string $title): string
    {
        $slug = function_exists('sanitize_title') ? sanitize_title($title) : strtolower(trim((string) preg_replace('~[^A-Za-z0-9]+~', '-', $title), '-'));
        $slug = ltrim((string) preg_replace('~[^a-z0-9_-]~', '', $slug), '0123456789_-');

        return rtrim(substr($slug, 0, 32), '-_');
    }

    private static function clip(string $value): string
    {
        return mb_substr(str_replace(['<', '>'], '', $value), 0, 80);
    }
}
