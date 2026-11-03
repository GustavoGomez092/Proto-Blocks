<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * Writes and deletes declaration files for the admin UI.
 *
 * Every write is validated (slug, size, JSONC, schema, slug key) before any
 * path is touched, confined to the writable theme roots with no symlink
 * escape, guarded by an optimistic-concurrency hash, and swapped in
 * atomically. Only `<root>/<folder>/<slug>/<basename>.jsonc|.json` is ever
 * written or deleted.
 */
final class SchemaFileWriter
{
    /** Extensions a declaration folder may hold and still be removed with it. */
    private const OWN_FILES = '/\.(?:jsonc?|svg|png|jpe?g|webp)\z/iD';

    /** @var list<string> */
    private array $roots;

    /**
     * @param list<string> $writableRoots realpaths; the first is where new declarations are created
     */
    public function __construct(array $writableRoots, private readonly bool $fileModsAllowed)
    {
        $this->roots = array_values(array_filter($writableRoots, 'is_string'));
    }

    /**
     * What the modules add to the schema check (`proto_blocks_declaration_errors`,
     * called with the kind, the decoded data and the slug): field group rules,
     * options-page rules such as protected option names.
     *
     * @param array<string|int, mixed> $data
     * @return list<array{path: string, message: string}>
     */
    public static function declarationErrors(string $kind, array $data, string $slug): array
    {
        $filtered = apply_filters('proto_blocks_declaration_errors', [], $kind, $data, $slug);

        $errors = [];
        foreach (is_array($filtered) ? $filtered : [] as $error) {
            if (is_array($error) && is_string($error['message'] ?? null)) {
                $errors[] = ['path' => is_string($error['path'] ?? null) ? $error['path'] : '', 'message' => $error['message']];
            } elseif (is_string($error) && $error !== '') {
                $errors[] = ['path' => '', 'message' => $error];
            } elseif ($error !== null && $error !== []) {
                // Fail closed on a finding in a shape this method does not know.
                $errors[] = ['path' => '', 'message' => __('A validation filter refused this declaration.', 'proto-blocks')];
            }
        }

        return $errors;
    }

    public static function hash(string $text): string
    {
        return hash('sha256', $text);
    }

    /**
     * Where a save would go and whether it can.
     *
     * @return array{path: string, writable: bool, reason: ?string} reason 'file_mods'|'not_writable'|'outside_root'
     */
    public function target(string $kind, string $slug, ?Declaration $existing): array
    {
        if (!isset(FileLoader::FOLDERS[$kind]) || Slug::error($slug, $kind) !== null) {
            return ['path' => '', 'writable' => false, 'reason' => 'outside_root'];
        }

        [$path, $inRoot] = $this->resolve($kind, $slug, $existing);

        $reason = null;
        if (!$this->fileModsAllowed) {
            $reason = 'file_mods';
        } elseif (!$inRoot || !SafeFile::isConfined($path, $this->roots)) {
            $reason = 'outside_root';
        } elseif (!self::canWrite($path)) {
            $reason = 'not_writable';
        }

        return ['path' => $path, 'writable' => $reason === null, 'reason' => $reason];
    }

    /**
     * @return array{path: string, hash: string, created: bool, data: array<string|int, mixed>}
     * @throws WriteException
     */
    public function write(string $kind, string $slug, string $text, ?string $expectedHash, ?Declaration $existing): array
    {
        $this->assertFileMods();
        $this->assertSlug($kind, $slug);

        if (strlen($text) > FileLoader::MAX_BYTES) {
            throw new WriteException(
                'file_too_large',
                413,
                /* translators: %d: maximum size in kilobytes */
                sprintf(__('A declaration file may not be larger than %d KB.', 'proto-blocks'), intdiv(FileLoader::MAX_BYTES, 1024))
            );
        }

        try {
            $data = Jsonc::decode($text);
        } catch (JsoncException $e) {
            throw new WriteException(
                'invalid_jsonc',
                400,
                sprintf(
                    /* translators: 1: line number, 2: column number, 3: parser message */
                    __('Invalid JSONC at line %1$d, column %2$d: %3$s', 'proto-blocks'),
                    $e->line,
                    $e->column,
                    $e->getMessage()
                ),
                ['line' => $e->line, 'column' => $e->column]
            );
        }

        $errors = SchemaCheck::validate($data, SchemaCheck::schema(), '#/$defs/' . $kind);
        if ($errors === []) {
            $errors = self::declarationErrors($kind, $data, $slug);
        }
        if ($errors !== []) {
            throw new WriteException(
                'schema_invalid',
                400,
                __('The declaration does not match the schema.', 'proto-blocks'),
                ['errors' => $errors]
            );
        }

        if (array_key_exists('slug', $data) && $data['slug'] !== $slug) {
            throw new WriteException(
                'slug_mismatch',
                400,
                /* translators: %s: folder slug */
                sprintf(__('The "slug" key must match the folder "%s" or be left out.', 'proto-blocks'), $slug)
            );
        }

        [$path, $inRoot] = $this->resolve($kind, $slug, $existing);
        $this->assertConfined($path, $inRoot);
        if (!self::canWrite($path)) {
            throw self::notWritable($path);
        }

        // Read the current hash only once the path is known to be ours.
        $exists = file_exists($path) || is_link($path);
        if (is_file($path)) {
            $current = (string) hash_file('sha256', $path);
            if ($expectedHash === null || !hash_equals($current, $expectedHash)) {
                throw new WriteException(
                    'conflict',
                    409,
                    __('The file changed on disk since it was loaded. Reload it and apply your changes again.', 'proto-blocks'),
                    ['hash' => $current]
                );
            }
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            throw new WriteException(
                'write_failed',
                500,
                /* translators: %s: folder path */
                sprintf(__('%s could not be created.', 'proto-blocks'), $dir)
            );
        }
        // Re-check after creating folders: nothing may have been swapped for a link meanwhile.
        $this->assertConfined($path, true);

        SafeFile::writeAtomic($path, $text);

        return ['path' => $path, 'hash' => self::hash($text), 'created' => !$exists, 'data' => $data];
    }

    /**
     * Remove a declaration file, and its folder when it holds nothing but files the plugin recognises.
     *
     * @return array{deleted: string, kept: list<string>}
     * @throws WriteException
     */
    public function delete(string $kind, string $slug, Declaration $existing): array
    {
        $this->assertFileMods();
        $this->assertSlug($kind, $slug);

        if ($existing->origin !== Declaration::ORIGIN_FILE) {
            throw new WriteException(
                'not_a_file',
                400,
                __('This declaration is made in PHP; remove the call from the theme instead.', 'proto-blocks')
            );
        }

        [$path, $inRoot] = $this->resolve($kind, $slug, $existing);
        $this->assertConfined($path, $inRoot);

        if (!is_file($path)) {
            throw new WriteException(
                'not_found',
                404,
                /* translators: %s: file path */
                sprintf(__('%s does not exist.', 'proto-blocks'), $path)
            );
        }
        $dir = dirname($path);
        if (!is_writable($dir)) {
            throw self::notWritable($dir);
        }
        if (!@unlink($path)) {
            throw new WriteException(
                'write_failed',
                500,
                /* translators: %s: file path */
                sprintf(__('%s could not be deleted.', 'proto-blocks'), $path)
            );
        }

        $kept = [];
        $own = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $entryPath = $dir . '/' . $entry;
            if (is_link($entryPath) || !is_file($entryPath) || !preg_match(self::OWN_FILES, $entry)) {
                $kept[] = $entry;
            } else {
                $own[] = $entryPath;
            }
        }
        sort($kept, SORT_STRING);

        if ($kept === []) {
            foreach ($own as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }

        return ['deleted' => $path, 'kept' => $kept];
    }

    /**
     * The file a save for $kind/$slug addresses, and whether it is one of the
     * declaration paths under a writable root.
     *
     * A file-origin $existing is edited in place only when its source is exactly
     * `<writable root>/<folder>/<slug>/<basename>.jsonc|.json`; any other source
     * (a filter root, or a forged path) is outside. Anything else is created in
     * the first root, reusing a declaration file already in that folder.
     *
     * @return array{0: string, 1: bool}
     */
    private function resolve(string $kind, string $slug, ?Declaration $existing): array
    {
        if ($existing !== null && $existing->origin === Declaration::ORIGIN_FILE) {
            if ($existing->kind === $kind && $existing->slug === $slug) {
                foreach ($this->roots as $root) {
                    if (in_array($existing->source, self::candidates($root, $kind, $slug), true)) {
                        return [$existing->source, true];
                    }
                }
            }
            return [$existing->source, false];
        }

        if ($this->roots === []) {
            return ['', false];
        }

        [$jsonc, $json] = self::candidates($this->roots[0], $kind, $slug);
        if (!file_exists($jsonc) && !is_link($jsonc) && is_file($json)) {
            return [$json, true];
        }

        return [$jsonc, true];
    }

    /** @return array{0: string, 1: string} */
    private static function candidates(string $root, string $kind, string $slug): array
    {
        $base = $root . '/' . FileLoader::FOLDERS[$kind] . '/' . $slug . '/' . FileLoader::BASENAMES[$kind];

        return [$base . '.jsonc', $base . '.json'];
    }

    /**
     * The file (or, before it exists, its nearest existing ancestor) can be written.
     */
    private static function canWrite(string $path): bool
    {
        if (file_exists($path)) {
            return is_file($path) && is_writable($path) && is_writable(dirname($path));
        }

        $dir = dirname($path);
        while (!file_exists($dir)) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                return false;
            }
            $dir = $parent;
        }

        return is_dir($dir) && is_writable($dir);
    }

    private function assertFileMods(): void
    {
        if (!$this->fileModsAllowed) {
            throw new WriteException('file_mods', 403, __('File modifications are disabled on this site.', 'proto-blocks'));
        }
    }

    private function assertSlug(string $kind, string $slug): void
    {
        if (!isset(FileLoader::FOLDERS[$kind])) {
            throw new WriteException('invalid_slug', 400, __('Unknown declaration kind.', 'proto-blocks'));
        }
        $error = Slug::error($slug, $kind);
        if ($error !== null) {
            throw new WriteException('invalid_slug', 400, FileLoader::slugMessage($error, $slug, $kind), ['reason' => $error]);
        }
    }

    private function assertConfined(string $path, bool $inRoot): void
    {
        if (!$inRoot || !SafeFile::isConfined($path, $this->roots)) {
            throw new WriteException(
                'path_outside_root',
                403,
                __('Declarations can only be written inside the active theme or its parent theme.', 'proto-blocks')
            );
        }
    }

    private static function notWritable(string $path): WriteException
    {
        return new WriteException(
            'not_writable',
            403,
            /* translators: %s: path */
            sprintf(__('The theme folder is not writable: %s', 'proto-blocks'), $path)
        );
    }
}
