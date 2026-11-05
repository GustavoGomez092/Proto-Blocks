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

    /** Slug grammar of an extended kind unless it names its own; `D` so `$` refuses a trailing newline. */
    public const EXTENDED_SLUG_PATTERN = '/^[a-z][a-z0-9_-]{0,31}$/D';

    /** Kind names an extension may take. */
    private const KIND_PATTERN = '/^[a-z][a-z0-9-]{0,31}\z/D';

    /** Folder and basename grammar of an extension. */
    private const SEGMENT_PATTERN = '/^[a-z][a-z0-9_-]{0,63}\z/D';

    /**
     * Kinds added by modules (C5 extendKind): kind => folder, basename,
     * validator, slug pattern, decoder, template. Not FileLoader::FOLDERS:
     * the Registry stays type/taxonomy/options only.
     *
     * @var array<string, array{folder: string, basename: string, validate: \Closure, slugPattern: string, decode: ?\Closure, template: ?\Closure, flat: bool}>
     */
    private static array $extended = [];

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
     * options-page rules such as protected option names. Only errors: a
     * finding marked `'level' => 'warning'` never refuses a write.
     *
     * @param array<string|int, mixed> $data
     * @return list<array{path: string, message: string}>
     */
    public static function declarationErrors(string $kind, array $data, string $slug): array
    {
        $errors = [];
        foreach (self::declarationFindings($kind, $data, $slug) as $finding) {
            if ($finding['level'] === Issue::ERROR) {
                $errors[] = ['path' => $finding['path'], 'message' => $finding['message']];
            }
        }

        return $errors;
    }

    /**
     * Every `proto_blocks_declaration_errors` finding with its level: an
     * entry `{path, message}` is an error unless it says
     * `'level' => 'warning'`; `code` is kept when given. A finding in a shape
     * this method does not know is an error (fail closed).
     *
     * @param array<string|int, mixed> $data
     * @return list<array{path: string, message: string, level: string, code: ?string}>
     */
    public static function declarationFindings(string $kind, array $data, string $slug): array
    {
        $filtered = apply_filters('proto_blocks_declaration_errors', [], $kind, $data, $slug);

        $findings = [];
        foreach (is_array($filtered) ? $filtered : [] as $error) {
            if (is_array($error) && is_string($error['message'] ?? null)) {
                $findings[] = [
                    'path' => is_string($error['path'] ?? null) ? $error['path'] : '',
                    'message' => $error['message'],
                    'level' => ($error['level'] ?? null) === Issue::WARNING ? Issue::WARNING : Issue::ERROR,
                    'code' => is_string($error['code'] ?? null) && $error['code'] !== '' ? $error['code'] : null,
                ];
            } elseif (is_string($error) && $error !== '') {
                $findings[] = ['path' => '', 'message' => $error, 'level' => Issue::ERROR, 'code' => null];
            } elseif ($error !== null && $error !== []) {
                // Fail closed on a finding in a shape this method does not know.
                $findings[] = ['path' => '', 'message' => __('A validation filter refused this declaration.', 'proto-blocks'), 'level' => Issue::ERROR, 'code' => null];
            }
        }

        return $findings;
    }

    /**
     * Adds a declaration kind this writer may write: `<root>/<folder>/<slug>/<basename>.jsonc|.json`.
     *
     * For an extended kind write() decodes with `$options['decode']` (default
     * Jsonc::decode), runs $validate instead of SchemaCheck, then the
     * `proto_blocks_declaration_errors` filter; it has no `slug` key check.
     * Every other rule (file mods, slug grammar, size, hash conflict,
     * confinement, symlink refusal, atomic swap) is the same as for a type.
     * Registering a kind again replaces it.
     *
     * A `flat` kind is one file per root, `<root>/<folder>/<basename>.jsonc|.json`
     * (no slug folder; its only slug is the basename unless slugPattern says
     * otherwise). It may share its folder with one non-flat kind (the
     * redirects settings file beside the group folders), and delete() never
     * removes that folder.
     *
     * @param \Closure(array<string|int, mixed>): list<array{path: string, message: string}> $validate
     * @param array{slugPattern?: string, decode?: \Closure(string): array<string|int, mixed>, template?: \Closure(string, string): string, flat?: bool} $options
     *        slugPattern: anchored regex with `D` (default EXTENDED_SLUG_PATTERN); decode: throws JsoncException;
     *        template: fn(slug, name): text served for a file that does not exist yet
     * @throws \InvalidArgumentException on a kind, folder or basename the writer cannot address safely
     */
    public static function extendKind(string $kind, string $folder, string $basename, \Closure $validate, array $options = []): void
    {
        if (preg_match(self::KIND_PATTERN, $kind) !== 1 || isset(FileLoader::FOLDERS[$kind])) {
            throw new \InvalidArgumentException(sprintf('Invalid declaration kind "%s".', $kind));
        }
        if (preg_match(self::SEGMENT_PATTERN, $folder) !== 1 || preg_match(self::SEGMENT_PATTERN, $basename) !== 1) {
            throw new \InvalidArgumentException('An extended kind needs a plain folder and basename.');
        }
        $flat = ($options['flat'] ?? false) === true;
        $taken = in_array($folder, FileLoader::FOLDERS, true);
        foreach (self::$extended as $other => $spec) {
            // A flat kind and a slug-folder kind address different paths and may share a folder.
            $taken = $taken || ($other !== $kind && $spec['folder'] === $folder && $spec['flat'] === $flat);
        }
        if ($taken) {
            throw new \InvalidArgumentException(sprintf('The folder "%s" belongs to another kind.', $folder));
        }
        $pattern = $options['slugPattern'] ?? ($flat ? '/^' . preg_quote($basename, '/') . '$/D' : self::EXTENDED_SLUG_PATTERN);
        if (!is_string($pattern) || !str_ends_with($pattern, 'D') || @preg_match($pattern, '') === false) {
            throw new \InvalidArgumentException('The slug pattern must be a valid regex with the D modifier.');
        }
        $decode = $options['decode'] ?? null;
        $template = $options['template'] ?? null;

        self::$extended[$kind] = [
            'folder' => $folder,
            'basename' => $basename,
            'validate' => $validate,
            'slugPattern' => $pattern,
            'decode' => $decode instanceof \Closure ? $decode : null,
            'template' => $template instanceof \Closure ? $template : null,
            'flat' => $flat,
        ];
    }

    /** @return list<string> the kinds added with extendKind(), in order */
    public static function extendedKinds(): array
    {
        return array_keys(self::$extended);
    }

    public static function isExtended(string $kind): bool
    {
        return isset(self::$extended[$kind]);
    }

    /** Tests only: forget every extended kind. */
    public static function resetKinds(): void
    {
        self::$extended = [];
    }

    /** null when $slug may be used for $kind (any kind), else 'pattern', 'length', 'reserved' or 'kind'. */
    public static function slugError(string $kind, string $slug): ?string
    {
        if (isset(self::$extended[$kind])) {
            return preg_match(self::$extended[$kind]['slugPattern'], $slug) === 1 ? null : 'pattern';
        }
        if (!isset(FileLoader::FOLDERS[$kind])) {
            return 'kind';
        }

        return Slug::error($slug, $kind);
    }

    /** The message for a slugError() code. */
    public static function slugMessage(string $kind, string $slug, string $code): string
    {
        if ($code === 'kind') {
            return __('Unknown declaration kind.', 'proto-blocks');
        }
        if (isset(self::$extended[$kind])) {
            return sprintf(
                /* translators: %s: the slug as typed */
                __('"%s" is not a valid slug: start with a lowercase letter and use only a-z, 0-9, "_" and "-" (32 characters at most).', 'proto-blocks'),
                $slug
            );
        }

        return FileLoader::slugMessage($code, $slug, $kind);
    }

    /** The folder an extended kind's files live in (`proto-fields`), or null. */
    public static function extendedFolder(string $kind): ?string
    {
        return self::$extended[$kind]['folder'] ?? null;
    }

    /** The new-file text an extended kind serves for $slug, or null when it has no template. */
    public static function extendedTemplate(string $kind, string $slug, string $name): ?string
    {
        $template = self::$extended[$kind]['template'] ?? null;

        return $template !== null ? (string) $template($slug, $name) : null;
    }

    /**
     * Decodes $text as $kind's writer would.
     *
     * @return array<string|int, mixed>
     * @throws JsoncException
     */
    public static function decodeFor(string $kind, string $text): array
    {
        $decode = self::$extended[$kind]['decode'] ?? null;

        return $decode !== null ? $decode($text) : Jsonc::decode($text);
    }

    /**
     * The existing file of an extended kind under the writable roots, as a
     * minimal file Declaration (no data) for target(), write() and delete();
     * the first root that has one wins (the child theme over its parent),
     * `.jsonc` over `.json`. Null for other kinds (the Registry knows those),
     * for an invalid slug, or when no root has the file.
     */
    public function existing(string $kind, string $slug): ?Declaration
    {
        if (!isset(self::$extended[$kind]) || self::slugError($kind, $slug) !== null) {
            return null;
        }
        foreach ($this->roots as $root) {
            foreach (self::candidates($root, $kind, $slug) as $path) {
                if (is_file($path) || is_link($path)) {
                    return new Declaration($kind, $slug, [], Declaration::ORIGIN_FILE, $path, $root);
                }
            }
        }

        return null;
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
        if (self::slugError($kind, $slug) !== null) {
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

        $extended = self::$extended[$kind] ?? null;
        try {
            $data = self::decodeFor($kind, $text);
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

        $errors = $extended !== null
            ? self::extendedErrors($extended['validate'], $data)
            : SchemaCheck::validate($data, SchemaCheck::schema(), '#/$defs/' . $kind);
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

        if ($extended === null && array_key_exists('slug', $data) && $data['slug'] !== $slug) {
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

        if (self::$extended[$kind]['flat'] ?? false) {
            // The folder of a flat kind holds other kinds' folders: never removed.
            return ['deleted' => $path, 'kept' => []];
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

    /**
     * An extended kind's validator findings in the writer's error shape; a
     * finding in any other shape is an error (fail closed).
     *
     * @param array<string|int, mixed> $data
     * @return list<array{path: string, message: string}>
     */
    private static function extendedErrors(\Closure $validate, array $data): array
    {
        $found = $validate($data);
        $errors = [];
        foreach (is_array($found) ? $found : [null] as $error) {
            $errors[] = is_array($error) && is_string($error['message'] ?? null)
                ? ['path' => is_string($error['path'] ?? null) ? $error['path'] : '', 'message' => $error['message']]
                : ['path' => '', 'message' => __('A validation rule refused this declaration.', 'proto-blocks')];
        }

        return $errors;
    }

    /** @return array{0: string, 1: string} */
    private static function candidates(string $root, string $kind, string $slug): array
    {
        $folder = self::$extended[$kind]['folder'] ?? FileLoader::FOLDERS[$kind];
        $basename = self::$extended[$kind]['basename'] ?? FileLoader::BASENAMES[$kind];
        $base = (self::$extended[$kind]['flat'] ?? false)
            ? $root . '/' . $folder . '/' . $basename
            : $root . '/' . $folder . '/' . $slug . '/' . $basename;

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
        $error = self::slugError($kind, $slug);
        if ($error === 'kind') {
            throw new WriteException('invalid_slug', 400, __('Unknown declaration kind.', 'proto-blocks'));
        }
        if ($error !== null) {
            throw new WriteException('invalid_slug', 400, self::slugMessage($kind, $slug, $error), ['reason' => $error]);
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
