<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Scripts;

use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\ContentModel\JsoncException;
use ProtoBlocks\ContentModel\SafeFile;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\WriteException;
use ProtoBlocks\Modules\Scripts\Definition\ScriptNormalizer;
use ProtoBlocks\Modules\Scripts\Definition\ScriptValidator;

/**
 * The snippet files next to a script.jsonc: `head.html`, `body-open.html`
 * and `body-close.html`, never any other name.
 *
 * Reading (any theme root): the file must be a regular file directly in the
 * script folder (no symlink, realpath inside that folder) and at most
 * 256 KB; it is read as text and never included.
 *
 * Writing (writable theme roots only): confined like SchemaFileWriter
 * (SafeFile::isConfined, no symlinked segment), only inside
 * `<root>/proto-scripts/<slug>/`, at most 256 KB, no PHP opening tag,
 * swapped in with SafeFile::writeAtomic(). Writing an empty snippet removes
 * the file.
 *
 * Deleting a script folder: only when it holds a valid script.jsonc (or
 * script.json) and nothing but that file and the three snippet files.
 */
final class SnippetFiles
{
    public const MAX_BYTES = ScriptValidator::SNIPPET_MAX;

    /** @var list<string> */
    private array $roots;

    /**
     * @param list<string> $writableRoots realpaths (SchemaPaths::writableRoots())
     */
    public function __construct(array $writableRoots, private readonly bool $fileModsAllowed)
    {
        $this->roots = array_values(array_filter($writableRoots, 'is_string'));
    }

    /** Whether $name is one of the three snippet file names. */
    public static function isSnippetName(string $name): bool
    {
        return in_array($name, ScriptNormalizer::LOCATIONS, true);
    }

    /**
     * Reads one snippet file of the script folder $dir.
     *
     * @return array{0: ?string, 1: ?string} [text, null] | [null, 'missing'|'path_outside_root'|'file_too_large'|'unreadable'|'invalid_name']
     */
    public static function read(string $dir, string $name): array
    {
        if (!self::isSnippetName($name)) {
            return [null, 'invalid_name'];
        }
        $realDir = realpath($dir);
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if ($realDir === false) {
            return [null, 'missing'];
        }
        if (is_link($path)) {
            return [null, 'path_outside_root'];
        }
        if (!file_exists($path)) {
            return [null, 'missing'];
        }
        $real = realpath($path);
        if ($real === false || !is_file($real) || dirname($real) !== $realDir) {
            return [null, 'path_outside_root'];
        }
        $size = filesize($real);
        if ($size === false || $size > self::MAX_BYTES) {
            return [null, 'file_too_large'];
        }
        $text = @file_get_contents($real, false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($text)) {
            return [null, 'unreadable'];
        }
        if (strlen($text) > self::MAX_BYTES) {
            return [null, 'file_too_large'];
        }

        return [$text, null];
    }

    /**
     * Writes (or, for an empty $text, removes) one snippet file in the script
     * folder $dir, which must be `<writable root>/proto-scripts/<slug>`.
     *
     * @return bool whether a file now exists at that path
     * @throws WriteException file_mods, path_outside_root, file_too_large, php_in_snippet, not_writable, write_failed
     */
    public function write(string $dir, string $name, string $text): bool
    {
        if (!$this->fileModsAllowed) {
            throw new WriteException('file_mods', 403, __('File modifications are disabled on this site.', 'proto-blocks'));
        }
        $path = $this->confinedPath($dir, $name);

        if ($text === '') {
            if (is_file($path) && !is_link($path) && !@unlink($path)) {
                /* translators: %s: file path */
                throw new WriteException('write_failed', 500, sprintf(__('%s could not be deleted.', 'proto-blocks'), $path));
            }
            return false;
        }

        $finding = ScriptValidator::snippetError($text, '/' . $name);
        if ($finding !== null) {
            throw new WriteException(
                $finding['code'] === 'snippet_too_large' ? 'file_too_large' : $finding['code'],
                $finding['code'] === 'snippet_too_large' ? 413 : 400,
                $finding['message']
            );
        }
        if (!is_dir($dir)) {
            throw new WriteException('not_found', 404, __('The script folder does not exist; save script.jsonc first.', 'proto-blocks'));
        }
        if (!is_writable($dir) || (file_exists($path) && !is_writable($path))) {
            /* translators: %s: path */
            throw new WriteException('not_writable', 403, sprintf(__('The theme folder is not writable: %s', 'proto-blocks'), $dir));
        }

        SafeFile::writeAtomic($path, $text);

        return true;
    }

    /**
     * Whether the folder may be removed as a whole: a valid script.jsonc (or
     * script.json) and nothing but it and the three snippet files, all
     * regular files.
     */
    public static function deletable(string $dir): bool
    {
        if (!is_dir($dir) || is_link($dir)) {
            return false;
        }
        $meta = null;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path) || !is_file($path)) {
                return false;
            }
            if ($entry === 'script.jsonc' || $entry === 'script.json') {
                if ($meta !== null) {
                    return false;
                }
                $meta = $path;
                continue;
            }
            if (!self::isSnippetName($entry)) {
                return false;
            }
        }
        if ($meta === null) {
            return false;
        }
        $text = @file_get_contents($meta, false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($text) || strlen($text) > self::MAX_BYTES) {
            return false;
        }
        try {
            $data = Jsonc::decode($text);
        } catch (JsoncException) {
            return false;
        }

        return ScriptValidator::validate($data)['errors'] === [];
    }

    /**
     * After script.jsonc was deleted: removes the snippet files and the
     * folder when nothing else is in it. Call only for a folder deletable()
     * accepted before the metadata file went.
     *
     * @return list<string> entries left in the folder
     */
    public function removeLeftovers(string $dir): array
    {
        if (!$this->fileModsAllowed || !is_dir($dir) || is_link($dir)) {
            return [];
        }
        $this->confinedPath($dir, 'head.html');

        $kept = [];
        $snippets = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (self::isSnippetName($entry) && is_file($path) && !is_link($path)) {
                $snippets[] = $path;
            } else {
                $kept[] = $entry;
            }
        }
        if ($kept !== []) {
            sort($kept, SORT_STRING);
            return $kept;
        }
        foreach ($snippets as $file) {
            @unlink($file);
        }
        @rmdir($dir);

        return [];
    }

    /**
     * $dir/$name when $dir is `<writable root>/proto-scripts/<slug>` and the
     * path is confined to that root.
     *
     * @throws WriteException path_outside_root
     */
    private function confinedPath(string $dir, string $name): string
    {
        $outside = new WriteException(
            'path_outside_root',
            403,
            __('Snippets can only be written inside the active theme or its parent theme.', 'proto-blocks')
        );
        if (!self::isSnippetName($name)) {
            throw $outside;
        }
        $slug = basename($dir);
        $parent = dirname($dir);
        if (basename($parent) !== 'proto-scripts' || preg_match(SchemaFileWriter::EXTENDED_SLUG_PATTERN, $slug) !== 1
            || !in_array(dirname($parent), $this->roots, true)) {
            throw $outside;
        }
        $path = $dir . '/' . $name;
        if (!SafeFile::isConfined($path, $this->roots)) {
            throw $outside;
        }

        return $path;
    }
}
