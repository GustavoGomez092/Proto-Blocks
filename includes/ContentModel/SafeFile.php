<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * Path confinement and atomic writes for files the plugin writes into a theme.
 *
 * Shared by SchemaFileWriter and the template scaffolder.
 */
final class SafeFile
{
    /**
     * True when $path lies strictly below one of $roots and cannot escape it.
     *
     * $path must be absolute, lexically below the root (no `.`, `..` or empty
     * segments, no NUL byte), every existing segment below the root must be a
     * real file or directory (never a symlink, even one pointing back inside),
     * and the realpath of the deepest existing ancestor must be the root or
     * below it. Roots must be realpaths themselves (a symlinked theme root is
     * passed resolved, as SchemaPaths does).
     *
     * @param list<string> $roots
     */
    public static function isConfined(string $path, array $roots): bool
    {
        if ($path === '' || str_contains($path, "\0") || $path[0] !== '/') {
            return false;
        }

        foreach ($roots as $root) {
            if (!is_string($root) || $root === '' || $root === '/' || str_contains($root, "\0")) {
                continue;
            }
            if (realpath($root) !== $root || !is_dir($root)) {
                continue;
            }
            $prefix = $root . '/';
            if (!str_starts_with($path, $prefix)) {
                continue;
            }

            if (self::confinedBelow($root, substr($path, strlen($prefix)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write $content to $target through a temp file in the same folder and a
     * rename, so readers never see a partial file. The target is checked for
     * a symlink before the write and again right before the swap; the temp
     * file is removed on any failure.
     *
     * @throws WriteException write_failed (500)
     */
    public static function writeAtomic(string $target, string $content): void
    {
        $fail = static function (string $why) use ($target): WriteException {
            return new WriteException(
                'write_failed',
                500,
                /* translators: 1: file path, 2: reason */
                sprintf(__('%1$s could not be written: %2$s', 'proto-blocks'), $target, $why)
            );
        };

        if (is_link($target)) {
            throw $fail(__('the file is a symbolic link.', 'proto-blocks'));
        }

        $dir = dirname($target);
        if (!is_dir($dir) || is_link($dir)) {
            throw $fail(__('its folder does not exist.', 'proto-blocks'));
        }

        $tmp = $dir . '/.' . basename($target) . '.' . bin2hex(random_bytes(6)) . '.tmp';

        // 'x' refuses to open an existing path (including a planted symlink).
        $handle = @fopen($tmp, 'xb');
        if ($handle === false) {
            throw $fail(__('a temporary file could not be created.', 'proto-blocks'));
        }
        $written = @fwrite($handle, $content);
        $flushed = @fflush($handle);
        @fclose($handle);
        if ($written !== strlen($content) || !$flushed) {
            @unlink($tmp);
            throw $fail(__('the content could not be written.', 'proto-blocks'));
        }

        @chmod($tmp, 0644);

        // Re-check right before the swap: the target may have become a link
        // between the first check and the write.
        if (is_link($target) || !@rename($tmp, $target)) {
            @unlink($tmp);
            throw $fail(__('the file could not be replaced.', 'proto-blocks'));
        }
    }

    private static function confinedBelow(string $root, string $relative): bool
    {
        if ($relative === '') {
            return false;
        }

        $current = $root;
        $deepest = $root;
        $existing = true;
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
            $current .= '/' . $segment;
            if (!$existing) {
                continue;
            }
            if (is_link($current)) {
                return false;
            }
            if (file_exists($current)) {
                $deepest = $current;
            } else {
                $existing = false;
            }
        }

        $real = realpath($deepest);

        return $real !== false && ($real === $root || str_starts_with($real, $root . '/'));
    }
}
