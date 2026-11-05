<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Scripts\Sources;

use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\UntrustedKeys;
use ProtoBlocks\Modules\Scripts\Definition\ScriptNormalizer;
use ProtoBlocks\Modules\Scripts\Definition\ScriptValidator;
use ProtoBlocks\Modules\Scripts\SnippetFiles;

/**
 * Script folders: `<root>/proto-scripts/<slug>/script.jsonc` (or
 * `script.json`; `.jsonc` wins) plus the snippet files it references.
 *
 * Files are data: decoded with Jsonc::decode() and read as text, never
 * included. Discovery is confined to each root (a symlinked folder or file
 * pointing out of the theme is skipped with `path_outside_root`); a file over
 * 256 KB is never read (`file_too_large`). Keys WordPress would call
 * (UntrustedKeys::UNSAFE_KEYS, values naming a PHP callable) are stripped
 * with an `unsafe_key` warning before validation.
 *
 * Roots are searched in order (parent theme, child theme, filter roots); a
 * later root replaces an earlier one's slug. A later root's broken file
 * drops the earlier one too (`broken_override`, fail closed), and broken()
 * lists it so the registry also drops a PHP declaration of that slug.
 *
 * A script with an error (schema, `php_in_snippet` in a file or inline,
 * `invalid_snippet_file`, a snippet file outside the folder or too large) is
 * not loaded. A referenced snippet file that does not exist is a
 * `snippet_file_missing` warning; that location outputs nothing.
 */
final class ScriptFiles
{
    public const FOLDER = 'proto-scripts';
    public const BASENAME = 'script';
    public const KIND = 'script';
    public const MAX_BYTES = 262144;

    private readonly \Closure $roots;

    /** @var array<string, array<string, mixed>>|null slug => Script */
    private ?array $scripts = null;

    /** @var array<string, string> slug => broken file path */
    private array $broken = [];

    /** @var list<Issue> */
    private array $issues = [];

    /**
     * @param callable(): iterable<string> $roots theme roots, parent first (SchemaPaths::roots)
     */
    public function __construct(callable $roots)
    {
        $this->roots = \Closure::fromCallable($roots);
    }

    /** @return array<string, array<string, mixed>> slug => Script, in slug order */
    public function scripts(): array
    {
        if ($this->scripts !== null) {
            return $this->scripts;
        }
        $this->scripts = [];
        foreach (($this->roots)() as $root) {
            $real = is_string($root) && $root !== '' ? realpath($root) : false;
            if ($real === false || !is_dir($real)) {
                continue;
            }
            [$ok, $broken] = $this->scan($real);
            foreach ($broken as $slug => $path) {
                if (isset($this->scripts[$slug])) {
                    $this->issues[] = new Issue(
                        Issue::ERROR,
                        'broken_override',
                        sprintf(
                            /* translators: 1: broken file, 2: earlier file that was not loaded */
                            __('%1$s is broken; the parent theme\'s script with the same slug (%2$s) was NOT loaded.', 'proto-blocks'),
                            $path,
                            (string) $this->scripts[$slug]['source']
                        ),
                        $path,
                        $slug,
                        self::KIND
                    );
                    unset($this->scripts[$slug]);
                }
                $this->broken[$slug] = $path;
            }
            foreach ($ok as $slug => $script) {
                unset($this->scripts[$slug], $this->broken[$slug]);
                $this->scripts[$slug] = $script;
            }
        }
        ksort($this->scripts, SORT_STRING);

        return $this->scripts;
    }

    /** @return array<string, string> slug => path of the broken file in the last root that has the slug */
    public function broken(): array
    {
        $this->scripts();

        return $this->broken;
    }

    /** @return list<Issue> */
    public function issues(): array
    {
        $this->scripts();

        return $this->issues;
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, string>} loaded scripts, broken slugs
     */
    private function scan(string $root): array
    {
        $dir = $root . DIRECTORY_SEPARATOR . self::FOLDER;
        if (!is_dir($dir)) {
            return [[], []];
        }
        if (!self::inside(realpath($dir), $root)) {
            $this->error($dir, null, 'path_outside_root', sprintf(
                /* translators: %s: folder path */
                __('%s resolves outside the theme and was skipped.', 'proto-blocks'),
                $dir
            ));
            return [[], []];
        }
        $entries = scandir($dir) ?: [];
        sort($entries, SORT_STRING);

        $ok = [];
        $broken = [];
        foreach ($entries as $slug) {
            if ($slug === '' || $slug[0] === '.') {
                continue;
            }
            $folder = $dir . DIRECTORY_SEPARATOR . $slug;
            if (!is_dir($folder)) {
                continue;
            }
            if (preg_match(SchemaFileWriter::EXTENDED_SLUG_PATTERN, $slug) !== 1) {
                $this->error($folder, null, 'invalid_slug', sprintf(
                    /* translators: %s: folder name */
                    __('The script folder "%s" must start with a lowercase letter and use only a-z, 0-9, "_" and "-" (32 characters at most); it was skipped.', 'proto-blocks'),
                    $slug
                ));
                continue;
            }
            if (!self::inside(realpath($folder), $root) || is_link($folder)) {
                $this->error($folder, $slug, 'path_outside_root', sprintf(
                    /* translators: %s: folder path */
                    __('%s resolves outside the theme and was skipped.', 'proto-blocks'),
                    $folder
                ));
                $broken[$slug] = $folder;
                continue;
            }
            $file = self::fileIn($folder);
            if ($file === null) {
                continue;
            }
            if (str_ends_with($file, '.jsonc') && is_file(substr($file, 0, -1))) {
                $this->issues[] = new Issue(Issue::WARNING, 'both_extensions', sprintf(
                    /* translators: 1: used file, 2: ignored file */
                    __('Both %1$s and %2$s exist; %1$s is used and %2$s is ignored.', 'proto-blocks'),
                    basename($file),
                    basename(substr($file, 0, -1))
                ), $file, $slug, self::KIND);
            }
            $script = $this->load($file, $folder, $slug, $root);
            if ($script !== null) {
                $ok[$slug] = $script;
            } else {
                $broken[$slug] = $file;
            }
        }

        return [$ok, $broken];
    }

    /** @return array<string, mixed>|null Script */
    private function load(string $file, string $folder, string $slug, string $root): ?array
    {
        $real = realpath($file);
        if (!self::inside($real, $root) || is_link($file)) {
            $this->error($file, $slug, 'path_outside_root', sprintf(
                /* translators: %s: file path */
                __('%s resolves outside the theme and was skipped.', 'proto-blocks'),
                $file
            ));
            return null;
        }
        $size = filesize((string) $real);
        if ($size === false || $size > self::MAX_BYTES) {
            $this->error($file, $slug, 'file_too_large', sprintf(
                /* translators: 1: file path, 2: maximum size in kilobytes */
                __('%1$s is larger than %2$d KB and was not read.', 'proto-blocks'),
                $file,
                intdiv(self::MAX_BYTES, 1024)
            ));
            return null;
        }
        $text = @file_get_contents((string) $real, false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($text) || strlen($text) > self::MAX_BYTES) {
            /* translators: %s: file path */
            $this->error($file, $slug, 'unreadable', sprintf(__('%s could not be read.', 'proto-blocks'), $file));
            return null;
        }
        try {
            $data = Jsonc::decode($text);
        } catch (\Throwable $e) {
            $this->error($file, $slug, 'invalid_jsonc', sprintf(
                /* translators: 1: file path, 2: parser message */
                __('%1$s is not valid JSONC: %2$s', 'proto-blocks'),
                $file,
                $e->getMessage()
            ));
            return null;
        }

        $data = $this->stripUnsafe($data, $file, $slug);

        $failed = false;
        $found = ScriptValidator::validate($data);
        foreach ($found['errors'] as $f) {
            $this->error($file, $slug, $f['code'], self::where($f['path']) . $f['message']);
            $failed = true;
        }
        foreach ($found['warnings'] as $f) {
            $this->issues[] = new Issue(Issue::WARNING, $f['code'], self::where($f['path']) . $f['message'], $file, $slug, self::KIND);
        }
        if ($failed) {
            return null;
        }

        $files = [];
        foreach (ScriptNormalizer::LOCATIONS as $location => $default) {
            $named = is_array($data[$location] ?? null) ? ($data[$location]['file'] ?? null) : null;
            if (!is_string($named) || isset($files[$named])) {
                continue;
            }
            $snippetPath = $folder . DIRECTORY_SEPARATOR . $named;
            [$snippet, $why] = SnippetFiles::read($folder, $named);
            if ($why === 'missing') {
                $this->issues[] = new Issue(Issue::WARNING, 'snippet_file_missing', sprintf(
                    /* translators: 1: snippet file name, 2: location key */
                    __('%1$s is referenced by "%2$s" but does not exist; nothing is output there.', 'proto-blocks'),
                    $named,
                    $location
                ), $snippetPath, $slug, self::KIND);
                continue;
            }
            if ($why !== null) {
                $this->error($snippetPath, $slug, $why === 'file_too_large' ? 'file_too_large' : ($why === 'unreadable' ? 'unreadable' : 'path_outside_root'), match ($why) {
                    /* translators: 1: file path, 2: maximum size in kilobytes */
                    'file_too_large' => sprintf(__('%1$s is larger than %2$d KB and was not read.', 'proto-blocks'), $snippetPath, intdiv(SnippetFiles::MAX_BYTES, 1024)),
                    /* translators: %s: file path */
                    'unreadable' => sprintf(__('%s could not be read.', 'proto-blocks'), $snippetPath),
                    /* translators: %s: file path */
                    default => sprintf(__('%s is not a plain file inside the script folder and was skipped.', 'proto-blocks'), $snippetPath),
                });
                $failed = true;
                continue;
            }
            $finding = ScriptValidator::snippetError((string) $snippet, '/' . $location);
            if ($finding !== null) {
                $this->error($snippetPath, $slug, $finding['code'], $named . ': ' . $finding['message']);
                $failed = true;
                continue;
            }
            $files[$named] = (string) $snippet;
        }
        if ($failed) {
            return null;
        }

        return ScriptNormalizer::normalize($data, $slug, 'file', $file, $root, $files);
    }

    /**
     * $data without the keys a declaration file may never set, with an
     * `unsafe_key` warning per removal.
     *
     * @param array<string|int, mixed> $data
     * @return array<string|int, mixed>
     */
    private function stripUnsafe(array $data, string $file, string $slug): array
    {
        $known = ['$schema', 'title', 'enabled', 'priority', 'priorities', 'head', 'bodyOpen', 'bodyClose', 'where'];
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (in_array($key, UntrustedKeys::UNSAFE_KEYS, true) || (!in_array($key, $known, true) && UntrustedKeys::namesCallable($value))) {
                unset($data[$key]);
                $this->issues[] = new Issue(Issue::WARNING, 'unsafe_key', sprintf(
                    /* translators: %s: declaration key */
                    __('The key "%s" names PHP code, which a declaration file cannot do. It is ignored.', 'proto-blocks'),
                    $key
                ), $file, $slug, self::KIND);
            }
        }

        return $data;
    }

    private function error(string $source, ?string $slug, string $code, string $message): void
    {
        $this->issues[] = new Issue(Issue::ERROR, $code, $message, $source, $slug, self::KIND);
    }

    private static function where(string $path): string
    {
        return ($path === '' ? '/' : $path) . ': ';
    }

    private static function fileIn(string $folder): ?string
    {
        foreach (['.jsonc', '.json'] as $extension) {
            $file = $folder . DIRECTORY_SEPARATOR . self::BASENAME . $extension;
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /** Strictly inside: the root itself does not count. */
    private static function inside(string|false $path, string $root): bool
    {
        return $path !== false && str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }
}
