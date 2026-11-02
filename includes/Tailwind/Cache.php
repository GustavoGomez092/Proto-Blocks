<?php
/**
 * Cache - Manages compiled Tailwind CSS cache
 *
 * Layout under wp-content/uploads/proto-blocks/tailwind/:
 *
 *   tailwind.css        compiled, scoped stylesheet — the only public file
 *   tailwind.css.hash   content hash of the last compile (denied by .htaccess)
 *   .htaccess / index.php   deny everything except *.css
 *   .work/              compile inputs (content.html = raw block template
 *                       source, input.css); deny-all guarded AND deleted after
 *                       every compile, because nginx ignores .htaccess.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Tailwind;

/**
 * Manages Tailwind CSS compilation cache
 */
class Cache
{
    /**
     * Cache directory relative to uploads
     */
    private const CACHE_SUBDIR = 'proto-blocks/tailwind/';

    /**
     * Work (compile input) directory relative to the cache directory.
     */
    private const WORK_SUBDIR = '.work/';

    /**
     * Output CSS filename
     */
    private const CSS_FILE = 'tailwind.css';

    /**
     * Input CSS filename
     */
    private const INPUT_FILE = 'input.css';

    /**
     * Content HTML filename
     */
    private const CONTENT_FILE = 'content.html';

    /**
     * Hash filename
     */
    private const HASH_FILE = 'tailwind.css.hash';

    /**
     * Cache directory path
     */
    private string $cacheDir;

    /**
     * Work directory path (compile inputs)
     */
    private string $workDir;

    /**
     * Cache URL
     */
    private string $cacheUrl;

    /**
     * Constructor
     */
    public function __construct(?string $cacheDir = null, ?string $workDir = null)
    {
        $uploadDir = \wp_upload_dir();
        $this->cacheDir = $cacheDir ?? $uploadDir['basedir'] . '/' . self::CACHE_SUBDIR;
        $this->cacheUrl = $uploadDir['baseurl'] . '/' . self::CACHE_SUBDIR;

        $workDir = $workDir ?? $this->cacheDir . self::WORK_SUBDIR;
        if (function_exists('apply_filters')) {
            /**
             * Filter the directory the compile inputs (block template source,
             * input.css) are written to. Point it outside the web root when the
             * host allows it; the default is a guarded subdirectory of uploads.
             *
             * @param string $workDir Absolute path with trailing slash.
             */
            $workDir = (string) \apply_filters('proto_blocks_tailwind_work_dir', $workDir);
        }
        $this->workDir = rtrim($workDir, '/\\') . '/';
    }

    /**
     * Check if compiled CSS exists
     */
    public function exists(): bool
    {
        return file_exists($this->getCssPath());
    }

    /**
     * Get compiled CSS path
     */
    public function getCssPath(): string
    {
        return $this->cacheDir . self::CSS_FILE;
    }

    /**
     * Get compiled CSS URL (protocol-relative).
     *
     * SSL-terminating hosts (e.g. WP Engine) can report an http scheme in
     * wp_upload_dir(), get_option('home'), and is_ssl() even when the site is
     * actually served over https through the proxy — which makes the browser
     * block this stylesheet as mixed content. Rather than guess the scheme, we
     * strip it so the browser loads the file over the SAME scheme as the page.
     * On an https page that's https; on http it's http. Never mixed content.
     */
    public function getUrl(): string
    {
        $url = $this->cacheUrl . self::CSS_FILE;

        return preg_replace('#^https?://#', '//', $url);
    }

    /**
     * Get input CSS path (inside the work directory)
     */
    public function getInputPath(): string
    {
        return $this->workDir . self::INPUT_FILE;
    }

    /**
     * Get content HTML path (inside the work directory)
     */
    public function getContentPath(): string
    {
        return $this->workDir . self::CONTENT_FILE;
    }

    /**
     * Get hash file path
     */
    public function getHashPath(): string
    {
        return $this->cacheDir . self::HASH_FILE;
    }

    /**
     * Get CSS version for cache busting
     */
    public function getVersion(): string
    {
        $cssPath = $this->getCssPath();
        if (file_exists($cssPath)) {
            return (string) filemtime($cssPath);
        }
        return PROTO_BLOCKS_VERSION;
    }

    /**
     * Get CSS file size in bytes
     */
    public function getSize(): int
    {
        $cssPath = $this->getCssPath();
        if (file_exists($cssPath)) {
            return (int) filesize($cssPath);
        }
        return 0;
    }

    /**
     * Get formatted file size
     */
    public function getFormattedSize(): string
    {
        $size = $this->getSize();
        if ($size === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB'];
        $i = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2) . ' ' . $units[$i];
    }

    /**
     * Get compiled CSS content
     */
    public function getContent(): ?string
    {
        $cssPath = $this->getCssPath();
        if (!file_exists($cssPath)) {
            return null;
        }

        $content = file_get_contents($cssPath);
        return $content !== false ? $content : null;
    }

    /**
     * Save compiled CSS content
     */
    public function saveContent(string $css): bool
    {
        if (!$this->ensureDirectory()) {
            return false;
        }

        return self::atomicWrite($this->getCssPath(), $css);
    }

    /**
     * Save input CSS content
     */
    public function saveInput(string $inputCss): bool
    {
        if (!$this->ensureWorkDirectory()) {
            return false;
        }

        return self::atomicWrite($this->getInputPath(), $inputCss);
    }

    /**
     * Save content hash
     */
    public function saveHash(string $hash): bool
    {
        if (!$this->ensureDirectory()) {
            return false;
        }

        return self::atomicWrite($this->getHashPath(), $hash);
    }

    /**
     * Get stored hash
     */
    public function getStoredHash(): ?string
    {
        $hashPath = $this->getHashPath();
        if (!file_exists($hashPath)) {
            return null;
        }

        $hash = file_get_contents($hashPath);
        return $hash !== false ? trim($hash) : null;
    }

    /**
     * Remove the compile inputs (block source dump + input.css).
     *
     * Called after every CLI compile, success or failure: the content file is
     * the verbatim PHP source of every block template and must never sit in a
     * web-served directory longer than the compile itself.
     */
    public function removeCompileInputs(): void
    {
        foreach ([$this->getContentPath(), $this->getInputPath()] as $file) {
            if (file_exists($file) || is_link($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Clear all cache files
     */
    public function clear(): int
    {
        $count = 0;
        $files = [
            $this->getCssPath(),
            $this->getInputPath(),
            $this->getContentPath(),
            $this->getHashPath(),
        ];

        foreach ($files as $file) {
            if (file_exists($file) && unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get cache statistics
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'directory' => $this->cacheDir,
            'work_directory' => $this->workDir,
            'css_exists' => $this->exists(),
            'css_path' => $this->getCssPath(),
            'css_url' => $this->getUrl(),
            'css_size' => $this->getSize(),
            'css_size_formatted' => $this->getFormattedSize(),
            'css_version' => $this->getVersion(),
            'hash' => $this->getStoredHash(),
        ];
    }

    /**
     * Ensure the output directory exists and is guarded (css-only web access).
     */
    public function ensureDirectory(): bool
    {
        if (!is_dir($this->cacheDir) && !\wp_mkdir_p($this->cacheDir)) {
            return false;
        }

        DirectoryGuard::allowOnlyCss($this->cacheDir);

        return true;
    }

    /**
     * Ensure the work directory exists and is guarded (no web access).
     */
    public function ensureWorkDirectory(): bool
    {
        if (!is_dir($this->workDir) && !\wp_mkdir_p($this->workDir)) {
            return false;
        }

        DirectoryGuard::denyAll($this->workDir);

        return true;
    }

    /**
     * Get cache directory
     */
    public function getCacheDir(): string
    {
        return $this->cacheDir;
    }

    /**
     * Get work directory (compile inputs)
     */
    public function getWorkDir(): string
    {
        return $this->workDir;
    }

    /**
     * Check if cache is writable
     */
    public function isWritable(): bool
    {
        if (!$this->ensureDirectory()) {
            return false;
        }

        return is_writable($this->cacheDir);
    }

    /**
     * Write a file via temp file + rename, refusing to follow a symlink.
     *
     * The cache paths are fixed and predictable; a pre-planted symlink at one
     * of them (uploads is the most writable directory on a WordPress host)
     * would otherwise make the compile overwrite whatever it points at.
     * rename() replaces the directory entry itself, so even if a link appears
     * between the check and the rename the target is never written through.
     */
    public static function atomicWrite(string $path, string $data): bool
    {
        if (is_link($path)) {
            return false;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            return false;
        }

        $tmp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (file_put_contents($tmp, $data, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }
}
