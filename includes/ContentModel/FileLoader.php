<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * Discovers declaration files under the schema roots.
 *
 * Files are data: only `type.jsonc`/`type.json` (resp. `taxonomy.*`, `page.*`) are read,
 * never included or executed. A broken file is reported and skipped; every
 * other declaration still loads.
 */
final class FileLoader
{
    public const MAX_BYTES = 262144;
    public const FOLDERS = ['type' => 'proto-types', 'taxonomy' => 'proto-taxonomies', 'options' => 'proto-options'];
    public const BASENAMES = ['type' => 'type', 'taxonomy' => 'taxonomy', 'options' => 'page'];

    private const SCHEMA_REFS = ['type' => '#/$defs/type', 'taxonomy' => '#/$defs/taxonomy', 'options' => '#/$defs/options'];

    /**
     * @param list<string> $roots Searched in order; a later root replaces an earlier one's slug.
     * @return array{declarations: array<string, array<string, Declaration>>, issues: list<Issue>} keyed kind => slug
     */
    public function load(array $roots): array
    {
        $declarations = array_fill_keys(array_keys(self::FOLDERS), []);
        $issues = [];

        foreach ($roots as $root) {
            $real = is_string($root) ? realpath($root) : false;
            if ($real === false || !is_dir($real)) {
                continue;
            }
            foreach (self::FOLDERS as $kind => $folder) {
                foreach ($this->loadKind($real, $kind, $folder, $issues) as $slug => $declaration) {
                    $declarations[$kind][$slug] = $declaration;
                }
            }
        }

        return ['declarations' => $declarations, 'issues' => $issues];
    }

    /**
     * The declaration file in a declaration folder: `.jsonc`, else `.json`, else null.
     */
    public static function fileFor(string $dir, string $kind): ?string
    {
        $base = rtrim($dir, '/\\') . '/' . (self::BASENAMES[$kind] ?? $kind);
        foreach (['.jsonc', '.json'] as $extension) {
            if (is_file($base . $extension)) {
                return $base . $extension;
            }
        }

        return null;
    }

    /**
     * @param list<Issue> $issues
     * @return array<string, Declaration>
     */
    private function loadKind(string $root, string $kind, string $folder, array &$issues): array
    {
        $kindDir = $root . '/' . $folder;
        if (!is_dir($kindDir)) {
            return [];
        }
        if (!self::inside(realpath($kindDir), $root)) {
            $issues[] = new Issue(
                Issue::ERROR,
                'path_outside_root',
                /* translators: %s: folder path */
                sprintf(__('%s resolves outside the theme and was skipped.', 'proto-blocks'), $kindDir),
                $kindDir,
                null,
                $kind
            );
            return [];
        }

        $entries = scandir($kindDir);
        if ($entries === false) {
            return [];
        }
        sort($entries, SORT_STRING);

        $out = [];
        foreach ($entries as $slug) {
            if ($slug === '' || $slug[0] === '.') {
                continue;
            }
            $dir = $kindDir . '/' . $slug;
            if (!is_dir($dir)) {
                continue;
            }
            $declaration = $this->loadOne($root, $kind, $slug, $dir, $issues);
            if ($declaration !== null) {
                $out[$slug] = $declaration;
            }
        }

        return $out;
    }

    /**
     * @param list<Issue> $issues
     */
    private function loadOne(string $root, string $kind, string $slug, string $dir, array &$issues): ?Declaration
    {
        $file = self::fileFor($dir, $kind);
        if ($file === null) {
            return null;
        }
        $error = static function (string $code, string $message) use (&$issues, $file, $slug, $kind): ?Declaration {
            $issues[] = new Issue(Issue::ERROR, $code, $message, $file, $slug, $kind);
            return null;
        };

        $slugError = Slug::error($slug, $kind);
        if ($slugError !== null) {
            return $error('slug_' . $slugError, self::slugMessage($slugError, $slug, $kind));
        }

        $real = realpath($file);
        if ($real === false || !self::inside(realpath($dir), $root) || !self::inside($real, $root)) {
            /* translators: %s: file path */
            return $error('path_outside_root', sprintf(__('%s resolves outside the theme and was skipped.', 'proto-blocks'), $file));
        }

        if (str_ends_with($file, '.jsonc') && is_file(substr($file, 0, -1))) {
            $issues[] = new Issue(
                Issue::WARNING,
                'both_extensions',
                /* translators: 1: used file, 2: ignored file */
                sprintf(__('Both %1$s and %2$s exist; %1$s is used and %2$s is ignored.', 'proto-blocks'), basename($file), basename(substr($file, 0, -1))),
                $file,
                $slug,
                $kind
            );
        }

        // Size first, from the inode: a file over the cap is never opened.
        $size = filesize($real);
        if ($size === false || $size > self::MAX_BYTES) {
            return $error('file_too_large', self::tooLargeMessage($file));
        }
        $text = file_get_contents($real, false, null, 0, self::MAX_BYTES + 1);
        if ($text === false) {
            /* translators: %s: file path */
            return $error('unreadable', sprintf(__('%s could not be read.', 'proto-blocks'), $file));
        }
        if (strlen($text) > self::MAX_BYTES) {
            return $error('file_too_large', self::tooLargeMessage($file)); // grew after the size check
        }

        try {
            $data = Jsonc::decode($text);
        } catch (JsoncException $e) {
            return $error('invalid_jsonc', sprintf(
                /* translators: 1: line number, 2: column number, 3: parser message */
                __('Invalid JSONC at line %1$d, column %2$d: %3$s', 'proto-blocks'),
                $e->line,
                $e->column,
                $e->getMessage()
            ));
        }

        $schemaErrors = SchemaCheck::validate($data, SchemaCheck::schema(), self::SCHEMA_REFS[$kind]);
        if ($schemaErrors !== []) {
            foreach ($schemaErrors as $schemaError) {
                $path = $schemaError['path'] === '' ? '/' : $schemaError['path'];
                $error('schema_invalid', $path . ': ' . $schemaError['message']);
            }
            return null;
        }

        if (array_key_exists('slug', $data) && $data['slug'] !== $slug) {
            return $error('slug_mismatch', sprintf(
                /* translators: 1: slug key in the file, 2: folder name */
                __('The slug "%1$s" in the file does not match its folder "%2$s".', 'proto-blocks'),
                is_scalar($data['slug']) ? (string) $data['slug'] : gettype($data['slug']),
                $slug
            ));
        }

        return new Declaration($kind, $slug, $data, Declaration::ORIGIN_FILE, $file, $root);
    }

    private static function inside(string|false $path, string $root): bool
    {
        return $path !== false && ($path === $root || str_starts_with($path, rtrim($root, '/') . '/'));
    }

    private static function tooLargeMessage(string $file): string
    {
        /* translators: 1: file path, 2: maximum size in kilobytes */
        return sprintf(__('%1$s is larger than %2$d KB and was not read.', 'proto-blocks'), $file, intdiv(self::MAX_BYTES, 1024));
    }

    /**
     * Message for a Slug::error() code. Shared with PhpDeclarations.
     */
    public static function slugMessage(string $code, string $slug, string $kind): string
    {
        $max = Slug::max($kind);
        $options = $kind === Declaration::OPTIONS;

        return match ($code) {
            'reserved' => $options
                /* translators: %s: slug */
                ? sprintf(__('The page slug "%s" is reserved by WordPress or Proto-Blocks.', 'proto-blocks'), $slug)
                /* translators: %s: slug */
                : sprintf(__('The slug "%s" is reserved by WordPress.', 'proto-blocks'), $slug),
            /* translators: 1: slug, 2: maximum length */
            'length' => sprintf(__('The slug "%1$s" is longer than %2$d characters.', 'proto-blocks'), $slug, $max),
            default => $options
                /* translators: %s: slug */
                ? sprintf(__('The page slug "%s" must start with a lowercase letter and use only a-z, 0-9 and "-".', 'proto-blocks'), $slug)
                /* translators: %s: slug */
                : sprintf(__('The slug "%s" must start with a lowercase letter and use only a-z, 0-9, "_" and "-".', 'proto-blocks'), $slug),
        };
    }
}
