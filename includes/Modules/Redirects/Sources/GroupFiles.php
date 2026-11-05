<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Sources;

use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\ContentModel\SchemaPaths;
use ProtoBlocks\Modules\Redirects\Definition\GroupValidator;

/**
 * Redirect group files `<root>/proto-redirects/<slug>/redirects.jsonc|.json`
 * and the settings file `<root>/proto-redirects/settings.jsonc|.json`.
 *
 * Files are data: decoded through the injected decoder, never included.
 * Discovery is confined to each root (realpaths of the folder, the slug
 * folder and the file must lie inside the root: `path_outside_root`); a
 * folder name failing GroupValidator::FILE_SLUG_PATTERN (or the reserved
 * `settings` and `php`) is skipped (`invalid_slug`); a file over MAX_BYTES is never
 * read (`file_too_large`: split the group); a decode failure is
 * `invalid_jsonc`; `.jsonc` wins over `.json` (`both_extensions` warning).
 * Roots are searched in order; a later root (the child theme) replaces an
 * earlier one's group of the same slug and its settings file. A later root's
 * broken file never lets the earlier one back in (`broken_override`, fail
 * closed). Results are computed once per instance.
 */
final class GroupFiles
{
    public const KIND = 'redirects';
    public const SETTINGS_KIND = 'redirect-settings';
    public const FOLDER = 'proto-redirects';
    public const BASENAME = 'redirects';
    public const SETTINGS_BASENAME = 'settings';
    public const SETTINGS_SLUG = 'settings';
    public const SLUG_PATTERN = GroupValidator::FILE_SLUG_PATTERN;
    public const MAX_BYTES = FileLoader::MAX_BYTES;

    private readonly \Closure $roots;
    private readonly \Closure $decode;

    /** @var array<string, array{slug: string, path: string, root: string, data: array<string|int, mixed>}>|null */
    private ?array $files = null;

    /** @var array{path: string, root: string, data: array<string|int, mixed>}|null */
    private ?array $settings = null;

    /** Whether $settings holds the settings-only read (no group file opened). */
    private bool $settingsRead = false;

    /** @var list<Issue> */
    private array $issues = [];

    /** @var list<string> every file read (group and settings), for filesHash() */
    private array $paths = [];

    /**
     * @param (callable(): iterable<string>)|null $roots  theme roots, parent first (default SchemaPaths::roots)
     * @param (callable(string): mixed)|null      $decode JSONC decoder that throws on invalid input (default Jsonc::decode)
     */
    public function __construct(?callable $roots = null, ?callable $decode = null)
    {
        $this->roots = \Closure::fromCallable($roots ?? [SchemaPaths::class, 'roots']);
        $this->decode = \Closure::fromCallable($decode ?? [Jsonc::class, 'decode']);
    }

    /**
     * The readable group files, keyed and sorted by slug.
     *
     * @return array<string, array{slug: string, path: string, root: string, data: array<string|int, mixed>}>
     */
    public function groups(): array
    {
        $this->load();

        return (array) $this->files;
    }

    /**
     * The settings file of the last root that has one; null when none, or
     * when that file is broken (the defaults apply, never a parent's file).
     *
     * Reads only the settings file (no group folder is scanned or opened)
     * unless the groups were loaded already, so booting the module on a
     * request that never matches costs one small read. Its findings are
     * reported by issues(), which does the full load.
     *
     * @return array{path: string, root: string, data: array<string|int, mixed>}|null
     */
    public function settingsFile(): ?array
    {
        if ($this->files === null && !$this->settingsRead) {
            $this->settings = $this->readSettingsOnly();
            $this->settingsRead = true;
        }

        return $this->settings;
    }

    /** @return list<Issue> */
    public function issues(): array
    {
        $this->load();

        return $this->issues;
    }

    /** @return list<string> every declaration file found, sorted (read or not) */
    public function paths(): array
    {
        $this->load();

        return $this->paths;
    }

    /**
     * The settings-file half of load(): same roots, confinement and
     * "a later root replaces (or breaks) an earlier one" rules. Issues and
     * paths found here are dropped; load() reports them.
     *
     * @return array{path: string, root: string, data: array<string|int, mixed>}|null
     */
    private function readSettingsOnly(): ?array
    {
        [$issues, $paths] = [$this->issues, $this->paths];
        $settings = null;
        foreach (($this->roots)() as $root) {
            $real = is_string($root) && $root !== '' ? realpath($root) : false;
            if ($real === false || !is_dir($real)) {
                continue;
            }
            $dir = $real . DIRECTORY_SEPARATOR . self::FOLDER;
            if (!is_dir($dir)) {
                continue;
            }
            if (!self::inside(realpath($dir), $real)) {
                $settings = null;
                continue;
            }
            $file = $this->fileIn($dir, self::SETTINGS_BASENAME, self::SETTINGS_SLUG, self::SETTINGS_KIND);
            if ($file !== null) {
                $data = $this->read($file, $real, self::SETTINGS_SLUG, self::SETTINGS_KIND);
                $settings = $data === null ? null : ['path' => $file, 'root' => $real, 'data' => $data];
            }
        }
        [$this->issues, $this->paths] = [$issues, $paths];

        return $settings;
    }

    private function load(): void
    {
        if ($this->files !== null) {
            return;
        }
        $this->files = [];
        $this->settings = null;
        foreach (($this->roots)() as $root) {
            $real = is_string($root) && $root !== '' ? realpath($root) : false;
            if ($real === false || !is_dir($real)) {
                continue;
            }
            $dir = $real . DIRECTORY_SEPARATOR . self::FOLDER;
            if (!is_dir($dir)) {
                continue;
            }
            if (!self::inside(realpath($dir), $real)) {
                $this->outside($dir, null, self::KIND);
                foreach ($this->files as $earlier) {
                    $this->brokenOverride($dir, $earlier['path'], $earlier['slug']);
                }
                $this->files = [];
                $this->settings = null;
                continue;
            }

            $settingsFile = $this->fileIn($dir, self::SETTINGS_BASENAME, self::SETTINGS_SLUG, self::SETTINGS_KIND);
            if ($settingsFile !== null) {
                $data = $this->read($settingsFile, $real, self::SETTINGS_SLUG, self::SETTINGS_KIND);
                $this->settings = $data === null ? null : ['path' => $settingsFile, 'root' => $real, 'data' => $data];
            }

            [$ok, $broken] = $this->scan($dir, $real);
            foreach ($broken as $slug => $path) {
                if (isset($this->files[$slug])) {
                    $this->brokenOverride($path, $this->files[$slug]['path'], $slug);
                    unset($this->files[$slug]);
                }
            }
            foreach ($ok as $slug => $file) {
                $this->files[$slug] = $file;
            }
        }
        ksort($this->files, SORT_STRING);
        sort($this->paths, SORT_STRING);
    }

    /**
     * @return array{0: array<string, array{slug: string, path: string, root: string, data: array<string|int, mixed>}>, 1: array<string, string>}
     */
    private function scan(string $dir, string $root): array
    {
        $entries = scandir($dir);
        if ($entries === false) {
            return [[], []];
        }
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
            if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
                $this->issues[] = new Issue(Issue::ERROR, 'invalid_slug', $slug === self::SETTINGS_SLUG
                    ? __('The redirect group folder "settings" is reserved for proto-redirects/settings.jsonc; the folder was skipped.', 'proto-blocks')
                    : ($slug === PhpDeclarations::VIRTUAL_SLUG
                    ? __('The redirect group folder "php" is reserved for the rules declared with proto_redirect(); the folder was skipped. Rename it.', 'proto-blocks')
                    : sprintf(
                        /* translators: %s: folder name */
                        __('The redirect group folder "%s" must start with a lowercase letter and use only a-z, 0-9, "_" and "-" (60 characters at most); it was skipped.', 'proto-blocks'),
                        $slug
                    )), $folder, null, self::KIND);
                continue;
            }
            if (!self::inside(realpath($folder), $root)) {
                $this->outside($folder, $slug, self::KIND);
                $broken[$slug] = $folder;
                continue;
            }
            $file = $this->fileIn($folder, self::BASENAME, $slug, self::KIND);
            if ($file === null) {
                continue;
            }
            $data = $this->read($file, $root, $slug, self::KIND);
            if ($data === null) {
                $broken[$slug] = $file;
                continue;
            }
            $ok[$slug] = ['slug' => $slug, 'path' => $file, 'root' => $root, 'data' => $data];
        }

        return [$ok, $broken];
    }

    private function fileIn(string $folder, string $basename, string $slug, string $kind): ?string
    {
        $jsonc = $folder . DIRECTORY_SEPARATOR . $basename . '.jsonc';
        $json = $folder . DIRECTORY_SEPARATOR . $basename . '.json';
        $hasJsonc = is_file($jsonc);
        $hasJson = is_file($json);
        if ($hasJsonc && $hasJson) {
            $this->issues[] = new Issue(Issue::WARNING, 'both_extensions', sprintf(
                /* translators: 1: used file, 2: ignored file */
                __('Both %1$s and %2$s exist; %1$s is used and %2$s is ignored.', 'proto-blocks'),
                basename($jsonc),
                basename($json)
            ), $jsonc, $slug, $kind);
        }
        $file = $hasJsonc ? $jsonc : ($hasJson ? $json : null);
        if ($file !== null) {
            $this->paths[] = $file;
        }

        return $file;
    }

    /** @return array<string|int, mixed>|null */
    private function read(string $file, string $root, string $slug, string $kind): ?array
    {
        $real = realpath($file);
        if (!self::inside($real, $root)) {
            $this->outside($file, $slug, $kind);
            return null;
        }
        $size = filesize((string) $real);
        if ($size === false || $size > self::MAX_BYTES) {
            $this->tooLarge($file, $slug, $kind);
            return null;
        }
        $text = file_get_contents((string) $real, false, null, 0, self::MAX_BYTES + 1);
        if ($text === false) {
            /* translators: %s: file path */
            $this->issues[] = new Issue(Issue::ERROR, 'unreadable', sprintf(__('%s could not be read.', 'proto-blocks'), $file), $file, $slug, $kind);
            return null;
        }
        if (strlen($text) > self::MAX_BYTES) {
            $this->tooLarge($file, $slug, $kind);
            return null;
        }
        try {
            $data = ($this->decode)($text);
        } catch (\Throwable $e) {
            $this->issues[] = new Issue(Issue::ERROR, 'invalid_jsonc', sprintf(
                /* translators: 1: file path, 2: parser message */
                __('%1$s is not valid JSONC and was skipped: %2$s', 'proto-blocks'),
                $file,
                $e->getMessage()
            ), $file, $slug, $kind);
            return null;
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            /* translators: %s: file path */
            $this->issues[] = new Issue(Issue::ERROR, 'invalid_jsonc', sprintf(__('%s must hold one JSON object; it was skipped.', 'proto-blocks'), $file), $file, $slug, $kind);
            return null;
        }

        return $data;
    }

    private function brokenOverride(string $broken, string $earlier, string $slug): void
    {
        $this->issues[] = new Issue(Issue::ERROR, 'broken_override', sprintf(
            /* translators: 1: broken file or folder, 2: the earlier file that was not loaded */
            __('%1$s is broken; the parent theme\'s group with the same slug (%2$s) was NOT loaded.', 'proto-blocks'),
            $broken,
            $earlier
        ), $broken, $slug, self::KIND);
    }

    private function outside(string $path, ?string $slug, string $kind): void
    {
        $this->issues[] = new Issue(Issue::ERROR, 'path_outside_root', sprintf(
            /* translators: %s: file or folder path */
            __('%s resolves outside the theme and was skipped.', 'proto-blocks'),
            $path
        ), $path, $slug, $kind);
    }

    private function tooLarge(string $file, string $slug, string $kind): void
    {
        $this->issues[] = new Issue(Issue::ERROR, 'file_too_large', sprintf(
            /* translators: 1: file path, 2: maximum size in kilobytes */
            __('%1$s is larger than %2$d KB and was not read. Split the group into several groups.', 'proto-blocks'),
            $file,
            intdiv(self::MAX_BYTES, 1024)
        ), $file, $slug, $kind);
    }

    private static function inside(string|false $path, string $root): bool
    {
        return $path !== false && str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }
}
