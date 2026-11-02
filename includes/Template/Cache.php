<?php
/**
 * Template Cache - Handles compiled template caching
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Template;

/**
 * Manages template compilation caching for improved performance
 *
 * Cache entries are stored as JSON data files (never PHP) so that a planted
 * file in the web-served, commonly writable uploads tree can never be
 * executed by the plugin. File names are keyed with an install-specific
 * salt so they cannot be derived from the template path alone, and the
 * cache directory is protected with index.php / .htaccess guard files.
 */
class Cache
{
    /**
     * Cache file extension. Data only -- never executable.
     */
    private const FILE_EXTENSION = '.json';

    /**
     * Maximum nesting depth accepted when decoding a cache file.
     */
    private const JSON_DEPTH = 64;

    /**
     * Keys produced by the parser that are never consumed from the cache.
     *
     * `html` is the template executed with schema defaults; nothing reads it
     * back (only `fields` is consumed) and persisting it would write theme
     * markup into a web-reachable file for no benefit.
     */
    private const DROPPED_KEYS = ['html'];

    /**
     * Cache directory
     */
    private string $cacheDir;

    /**
     * In-memory cache
     *
     * @var array<string, array>
     */
    private array $memoryCache = [];

    /**
     * Constructor
     */
    public function __construct(?string $cacheDir = null)
    {
        $this->cacheDir = self::normalizeDir($cacheDir ?? self::defaultCacheDir());

        self::protectDirectory($this->cacheDir);
    }

    /**
     * Resolve the default cache directory.
     *
     * Stored under wp-content/uploads (alongside the Tailwind cache) rather
     * than wp-content/cache. Managed hosts such as WP Engine own and
     * periodically purge wp-content/cache, so template cache files written
     * there don't persist -- the dashboard then shows 0 cached templates.
     * The uploads directory is writable and persistent across hosts. Falls
     * back to wp-content/cache if the uploads dir is unavailable, and is
     * filterable via `proto_blocks_cache_dir` for hosts that need a custom
     * writable location.
     */
    private static function defaultCacheDir(): string
    {
        $dir = WP_CONTENT_DIR . '/cache/proto-blocks/';

        if (function_exists('wp_upload_dir')) {
            $upload = wp_upload_dir(null, false);
            if (empty($upload['error']) && !empty($upload['basedir'])) {
                $dir = rtrim((string) $upload['basedir'], '/\\') . '/proto-blocks/templates/';
            }
        }

        if (function_exists('apply_filters')) {
            $dir = (string) apply_filters('proto_blocks_cache_dir', $dir);
        }

        return $dir;
    }

    /**
     * Ensure a cache directory exists and is shielded from direct web access.
     *
     * Creates the directory if needed and drops two guard files once, if
     * absent: an `index.php` ("Silence is golden") so autoindex-enabled hosts
     * never list the directory, and an `.htaccess` that denies all direct
     * requests on Apache (both mod_authz_core and legacy syntax). Nginx hosts
     * ignore `.htaccess`; the index.php still prevents listings there.
     *
     * Safe to call repeatedly and from any cache that writes under uploads
     * (e.g. the Tailwind cache): existing guard files, or symlinks at those
     * paths, are left untouched.
     *
     * @param string $dir Absolute directory path (with or without trailing slash)
     */
    public static function protectDirectory(string $dir): void
    {
        $dir = self::normalizeDir($dir);

        if (!is_dir($dir)) {
            if (function_exists('wp_mkdir_p')) {
                wp_mkdir_p($dir);
            } else {
                @mkdir($dir, 0755, true);
            }
        }

        if (!is_dir($dir)) {
            return;
        }

        $guards = [
            'index.php' => "<?php\n// Silence is golden.\n",
            '.htaccess' => "<IfModule mod_authz_core.c>\n"
                . "    Require all denied\n"
                . "</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n"
                . "    Order deny,allow\n"
                . "    Deny from all\n"
                . "</IfModule>\n",
        ];

        foreach ($guards as $name => $content) {
            $path = $dir . $name;
            if (file_exists($path) || is_link($path)) {
                continue;
            }
            self::writeAtomic($path, $content);
        }
    }

    /**
     * Get cached data for a template
     *
     * @param string $templatePath Path to the template file
     * @return array|null Cached data or null if not found/expired
     */
    public function get(string $templatePath): ?array
    {
        if (!PROTO_BLOCKS_CACHE_ENABLED) {
            return null;
        }

        $cacheKey = $this->getCacheKey($templatePath);

        // Check memory cache first
        if (isset($this->memoryCache[$cacheKey])) {
            return $this->memoryCache[$cacheKey];
        }

        // A legacy executable cache file is never included: remove it and
        // let the caller regenerate the entry as JSON.
        $this->removeLegacyFile($templatePath);

        // Check file cache
        $cacheFile = $this->getCacheFile($templatePath);

        if (!$this->isValid($templatePath, $cacheFile)) {
            return null;
        }

        $data = $this->readFile($cacheFile);

        if ($data === null) {
            // Corrupted or unexpected content
            $this->delete($templatePath);
            return null;
        }

        $this->memoryCache[$cacheKey] = $data;

        return $data;
    }

    /**
     * Set cached data for a template
     *
     * @param string $templatePath Path to the template file
     * @param array $data Data to cache
     */
    public function set(string $templatePath, array $data): void
    {
        if (!PROTO_BLOCKS_CACHE_ENABLED) {
            return;
        }

        foreach (self::DROPPED_KEYS as $key) {
            unset($data[$key]);
        }

        $cacheKey = $this->getCacheKey($templatePath);
        $cacheFile = $this->getCacheFile($templatePath);

        // Store in memory cache
        $this->memoryCache[$cacheKey] = $data;

        // Store in file cache (data only, never executable)
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            self::JSON_DEPTH
        );

        if (!is_string($json)) {
            return;
        }

        $this->removeLegacyFile($templatePath);

        self::writeAtomic($cacheFile, $json);
    }

    /**
     * Check if cache is valid for a template
     *
     * The entry is stale when the template or any of its JSON config files
     * (block.json, <dir>.json, <template>.json) is newer than the cache file.
     */
    public function isValid(string $templatePath, ?string $cacheFile = null): bool
    {
        $cacheFile = $cacheFile ?? $this->getCacheFile($templatePath);

        if (!is_file($cacheFile) || is_link($cacheFile)) {
            return false;
        }

        $cacheTime = filemtime($cacheFile);

        // Check template file modification time
        if (!file_exists($templatePath) || filemtime($templatePath) > $cacheTime) {
            return false;
        }

        // Check every JSON config file that feeds the parse result
        foreach ($this->getConfigPaths($templatePath) as $jsonPath) {
            if (filemtime($jsonPath) > $cacheTime) {
                return false;
            }
        }

        return true;
    }

    /**
     * Delete cached data for a template
     */
    public function delete(string $templatePath): void
    {
        $cacheKey = $this->getCacheKey($templatePath);
        $cacheFile = $this->getCacheFile($templatePath);

        unset($this->memoryCache[$cacheKey]);

        if (file_exists($cacheFile) || is_link($cacheFile)) {
            @unlink($cacheFile);
        }

        $this->removeLegacyFile($templatePath);
    }

    /**
     * Clear all cached data
     *
     * Removes current JSON entries and any legacy executable `.php` entries
     * (the guard index.php is kept).
     */
    public function clear(): int
    {
        $this->memoryCache = [];
        $count = 0;

        $files = array_merge(
            glob($this->cacheDir . '*' . self::FILE_EXTENSION) ?: [],
            $this->legacyFiles()
        );

        foreach ($files as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        $files = glob($this->cacheDir . '*' . self::FILE_EXTENSION) ?: [];

        $totalSize = 0;
        foreach ($files as $file) {
            $totalSize += (int) filesize($file);
        }

        return [
            'directory' => $this->cacheDir,
            'file_count' => count($files),
            'total_size' => $totalSize,
            'memory_count' => count($this->memoryCache),
            'enabled' => PROTO_BLOCKS_CACHE_ENABLED,
        ];
    }

    /**
     * Get cache directory
     */
    public function getCacheDir(): string
    {
        return $this->cacheDir;
    }

    /**
     * Read and validate a JSON cache file.
     *
     * @return array|null Decoded data, or null when the content is not a
     *                    well-formed cache entry.
     */
    private function readFile(string $cacheFile): ?array
    {
        $raw = @file_get_contents($cacheFile);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true, self::JSON_DEPTH);

        if (!is_array($data) || !isset($data['fields']) || !is_array($data['fields'])) {
            return null;
        }

        return $data;
    }

    /**
     * Write a file atomically: temp file in the same directory, then rename.
     *
     * Refuses to write when the target is a symlink so a planted link cannot
     * redirect the write elsewhere on disk.
     */
    private static function writeAtomic(string $target, string $content): bool
    {
        if (is_link($target)) {
            return false;
        }

        $dir = dirname($target);
        if (!is_dir($dir)) {
            return false;
        }

        $tmp = $dir . '/.' . basename($target) . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }

        @chmod($tmp, 0644);

        // Re-check right before the swap: the target may have become a link
        // between the first check and the write.
        if (is_link($target) || !@rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /**
     * Get cache key for a template
     *
     * Keyed with the install's auth salt so the on-disk name cannot be
     * predicted from the (conventional, often leaked) absolute template path.
     */
    private function getCacheKey(string $templatePath): string
    {
        return hash_hmac('sha256', $templatePath, self::salt());
    }

    /**
     * Install-specific salt for cache keys.
     *
     * Uses wp_salt('auth') in WordPress; falls back to AUTH_SALT or an empty
     * key when running outside WordPress (unit tests), where predictability
     * is not a concern.
     */
    private static function salt(): string
    {
        if (function_exists('wp_salt')) {
            return (string) wp_salt('auth');
        }

        if (defined('AUTH_SALT') && is_string(AUTH_SALT)) {
            return AUTH_SALT;
        }

        return '';
    }

    /**
     * Get cache file path for a template
     */
    private function getCacheFile(string $templatePath): string
    {
        $key = $this->getCacheKey($templatePath);
        $name = $this->blockName($templatePath);

        return $this->cacheDir . $name . '-' . $key . self::FILE_EXTENSION;
    }

    /**
     * Path of the pre-JSON executable cache file for a template
     * (`{blockdir}-{md5(path)}.php`). Only ever deleted, never included.
     */
    private function getLegacyFile(string $templatePath): string
    {
        return $this->cacheDir . $this->blockName($templatePath) . '-' . md5($templatePath) . '.php';
    }

    /**
     * Remove a legacy executable cache file if present.
     */
    private function removeLegacyFile(string $templatePath): void
    {
        $legacy = $this->getLegacyFile($templatePath);

        if (file_exists($legacy) || is_link($legacy)) {
            @unlink($legacy);
        }
    }

    /**
     * All legacy `.php` cache entries in the directory (guard index.php excluded).
     *
     * @return string[]
     */
    private function legacyFiles(): array
    {
        $files = glob($this->cacheDir . '*.php') ?: [];

        return array_values(array_filter($files, static function (string $file): bool {
            return basename($file) !== 'index.php';
        }));
    }

    /**
     * Sanitised block directory name used as the file name prefix.
     */
    private function blockName(string $templatePath): string
    {
        $name = basename(dirname($templatePath));
        $name = preg_replace('/[^A-Za-z0-9_-]/', '', $name) ?? '';

        return $name !== '' ? $name : 'template';
    }

    /**
     * JSON config files whose changes invalidate a template's cache entry.
     *
     * Blocks are discovered via block.json and the parser merges
     * `protoBlocks.fields` from it, so it must participate in invalidation
     * alongside the legacy `<dir>.json` / `<template>.json` layouts.
     *
     * @return string[] Existing files only
     */
    private function getConfigPaths(string $templatePath): array
    {
        $dir = dirname($templatePath);

        $candidates = array_unique([
            $dir . '/block.json',
            $dir . '/' . basename($dir) . '.json',
            $dir . '/' . basename($templatePath, '.php') . '.json',
        ]);

        return array_values(array_filter($candidates, 'is_file'));
    }

    /**
     * Normalise a directory path to a single trailing slash.
     */
    private static function normalizeDir(string $dir): string
    {
        return rtrim($dir, '/\\') . '/';
    }
}
