<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields\Sources;

use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\Definition\Report;

/**
 * Standalone field-group files: `<root>/proto-fields/<slug>/group.jsonc` or
 * `group.json` (`.jsonc` wins), one group or an ACF Tools export (a list).
 *
 * Files are data: decoded through the injected decoder only, never included.
 * Discovery is confined to each root: a folder name failing SLUG_PATTERN is
 * skipped (`invalid_slug`); the realpath of the `proto-fields` folder, of the
 * slug folder and of the file must lie inside realpath($root), so a symlink
 * pointing out of the theme is skipped (`path_outside_root`); a file over
 * MAX_BYTES is never read (`file_too_large`); a decode failure is
 * `invalid_jsonc`. Roots are searched in order and a later root (the child
 * theme) replaces an earlier one's slug.
 *
 * A later root's file that is refused or broken never lets an earlier root's
 * file of the same slug back in: the earlier file is dropped too, with a
 * `broken_override` error (fail closed: a child that re-typed a parent's
 * `code` field must not fall back to the parent's definition). A refused
 * `proto-fields` folder in a later root drops every earlier file. Slugs whose
 * own file is broken are listed by broken() so the module can warn that a
 * lower-ranked group with the same key may be in effect.
 *
 * Results are computed once per instance (one request).
 */
final class GroupFiles
{
    public const FOLDER = 'proto-fields';
    public const MAX_BYTES = 262144;
    /** Same as the Phase 1 route slug regex; `D` so `$` refuses a trailing newline. */
    public const SLUG_PATTERN = '/^[a-z][a-z0-9_-]{0,31}$/D';

    private const BASENAME = 'group';

    private readonly \Closure $roots;
    private readonly \Closure $decode;

    /** @var array<string, array{slug: string, path: string, root: string, data: array<string|int, mixed>}>|null */
    private ?array $files = null;

    private Report $errors;

    /** @var array<string, string> slug => path of the broken file (latest root) */
    private array $broken = [];

    /**
     * @param callable(): iterable<string> $roots   theme roots, parent first
     * @param callable(string): array      $decode  JSONC decoder; throws on invalid input
     */
    public function __construct(callable $roots, callable $decode)
    {
        $this->roots = \Closure::fromCallable($roots);
        $this->decode = \Closure::fromCallable($decode);
        $this->errors = new Report();
    }

    /**
     * Every group of every readable file, in slug order; a group with
     * structural errors is dropped (fail closed).
     *
     * @return list<FieldGroup>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->files() as $file) {
            $result = GroupNormalizer::normalizeMany($file['data'], 'file:' . $file['slug'], $file['path']);
            array_push($out, ...$result['groups']);
        }

        return $out;
    }

    /**
     * The decoded files, keyed by slug.
     *
     * @return array<string, array{slug: string, path: string, root: string, data: array<string|int, mixed>}>
     */
    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }
        $this->files = [];
        foreach (($this->roots)() as $root) {
            $real = is_string($root) && $root !== '' ? realpath($root) : false;
            if ($real === false || !is_dir($real)) {
                continue;
            }
            [$ok, $broken] = $this->scan($real);
            if ($broken === true) {
                // The whole proto-fields folder of this root was refused.
                foreach ($this->files as $slug => $earlier) {
                    $this->brokenOverride($real . DIRECTORY_SEPARATOR . self::FOLDER, $earlier['path']);
                    $this->broken[$slug] = $real . DIRECTORY_SEPARATOR . self::FOLDER;
                }
                $this->files = [];
                continue;
            }
            foreach ($broken as $slug => $path) {
                if (isset($this->files[$slug])) {
                    $this->brokenOverride($path, $this->files[$slug]['path']);
                    unset($this->files[$slug]);
                }
                $this->broken[$slug] = $path;
            }
            foreach ($ok as $slug => $file) {
                // The later root (child theme) wins; keep slug order stable.
                unset($this->files[$slug], $this->broken[$slug]);
                $this->files[$slug] = $file;
            }
        }
        ksort($this->files, SORT_STRING);

        return $this->files;
    }

    /**
     * Slugs whose file in the last root that has one is refused or broken.
     *
     * @return array<string, string> slug => broken file or folder path
     */
    public function broken(): array
    {
        $this->files();

        return $this->broken;
    }

    private function brokenOverride(string $broken, string $earlier): void
    {
        $this->errors->addError($broken, 'broken_override', sprintf(
            /* translators: 1: broken file or folder, 2: the earlier file that was not loaded */
            __('%1$s is broken; the parent theme\'s group with the same slug (%2$s) was NOT loaded.', 'proto-blocks'),
            $broken,
            $earlier
        ));
    }

    /**
     * Discovery errors (path = the folder or file concerned): invalid_slug,
     * path_outside_root, file_too_large, unreadable, invalid_jsonc,
     * broken_override; warning both_extensions.
     */
    public function errors(): Report
    {
        $this->files();

        return $this->errors;
    }

    /**
     * @return array{0: array<string, array{slug: string, path: string, root: string, data: array<string|int, mixed>}>, 1: array<string, string>|true}
     *         the readable files, and the broken slugs (true: the whole folder was refused)
     */
    private function scan(string $root): array
    {
        $dir = $root . DIRECTORY_SEPARATOR . self::FOLDER;
        if (!is_dir($dir)) {
            return [[], []];
        }
        if (!self::inside(realpath($dir), $root)) {
            $this->outside($dir);
            return [[], true];
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return [[], true];
        }
        sort($entries, SORT_STRING);

        $out = [];
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
                $this->errors->addError($folder, 'invalid_slug', sprintf(
                    /* translators: %s: folder name */
                    __('The field group folder "%s" must start with a lowercase letter and use only a-z, 0-9, "_" and "-" (32 characters at most); it was skipped.', 'proto-blocks'),
                    $slug
                ));
                continue;
            }
            if (!self::inside(realpath($folder), $root)) {
                $this->outside($folder);
                $broken[$slug] = $folder;
                continue;
            }
            $file = $this->fileIn($folder);
            if ($file === null) {
                continue;
            }
            if (str_ends_with($file, '.jsonc') && is_file(substr($file, 0, -1))) {
                $this->errors->addWarning($file, 'both_extensions', sprintf(
                    /* translators: 1: used file, 2: ignored file */
                    __('Both %1$s and %2$s exist; %1$s is used and %2$s is ignored.', 'proto-blocks'),
                    basename($file),
                    basename(substr($file, 0, -1))
                ));
            }
            $data = $this->read($file, $root);
            if ($data !== null) {
                $out[$slug] = ['slug' => $slug, 'path' => $file, 'root' => $root, 'data' => $data];
            } else {
                $broken[$slug] = $file;
            }
        }

        return [$out, $broken];
    }

    private function fileIn(string $folder): ?string
    {
        foreach (['.jsonc', '.json'] as $extension) {
            $file = $folder . DIRECTORY_SEPARATOR . self::BASENAME . $extension;
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /** @return array<string|int, mixed>|null */
    private function read(string $file, string $root): ?array
    {
        $real = realpath($file);
        if (!self::inside($real, $root)) {
            $this->outside($file);
            return null;
        }
        // Size first, from the inode: a file over the cap is never opened.
        $size = filesize((string) $real);
        if ($size === false || $size > self::MAX_BYTES) {
            $this->tooLarge($file);
            return null;
        }
        $text = file_get_contents((string) $real, false, null, 0, self::MAX_BYTES + 1);
        if ($text === false) {
            /* translators: %s: file path */
            $this->errors->addError($file, 'unreadable', sprintf(__('%s could not be read.', 'proto-blocks'), $file));
            return null;
        }
        if (strlen($text) > self::MAX_BYTES) {
            $this->tooLarge($file); // grew after the size check
            return null;
        }

        try {
            $data = ($this->decode)($text);
        } catch (\Throwable $e) {
            $this->errors->addError($file, 'invalid_jsonc', sprintf(
                /* translators: 1: file path, 2: parser message */
                __('%1$s is not valid JSONC: %2$s', 'proto-blocks'),
                $file,
                $e->getMessage()
            ));
            return null;
        }
        if (!is_array($data)) {
            /* translators: %s: file path */
            $this->errors->addError($file, 'invalid_jsonc', sprintf(__('%s does not hold a field group.', 'proto-blocks'), $file));
            return null;
        }

        return $data;
    }

    private function outside(string $path): void
    {
        $this->errors->addError($path, 'path_outside_root', sprintf(
            /* translators: %s: file or folder path */
            __('%s resolves outside the theme and was skipped.', 'proto-blocks'),
            $path
        ));
    }

    private function tooLarge(string $file): void
    {
        $this->errors->addError($file, 'file_too_large', sprintf(
            /* translators: 1: file path, 2: maximum size in kilobytes */
            __('%1$s is larger than %2$d KB and was not read.', 'proto-blocks'),
            $file,
            intdiv(self::MAX_BYTES, 1024)
        ));
    }

    /** Strictly inside: the root itself does not count. */
    private static function inside(string|false $path, string $root): bool
    {
        return $path !== false && str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }
}
