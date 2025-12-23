<?php
/**
 * Cache - Manages compiled Tailwind CSS cache
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
     * Cache URL
     */
    private string $cacheUrl;

    /**
     * Constructor
     */
    public function __construct(?string $cacheDir = null)
    {
        $uploadDir = \wp_upload_dir();
        $this->cacheDir = $cacheDir ?? $uploadDir['basedir'] . '/' . self::CACHE_SUBDIR;
        $this->cacheUrl = $uploadDir['baseurl'] . '/' . self::CACHE_SUBDIR;
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
     * Get compiled CSS URL
     */
    public function getUrl(): string
    {
        return $this->cacheUrl . self::CSS_FILE;
    }

    /**
     * Get input CSS path
     */
    public function getInputPath(): string
    {
        return $this->cacheDir . self::INPUT_FILE;
    }

    /**
     * Get content HTML path
     */
    public function getContentPath(): string
    {
        return $this->cacheDir . self::CONTENT_FILE;
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

        return file_put_contents($this->getCssPath(), $css, LOCK_EX) !== false;
    }

    /**
     * Save input CSS content
     */
    public function saveInput(string $inputCss): bool
    {
        if (!$this->ensureDirectory()) {
            return false;
        }

        return file_put_contents($this->getInputPath(), $inputCss, LOCK_EX) !== false;
    }

    /**
     * Save content hash
     */
    public function saveHash(string $hash): bool
    {
        if (!$this->ensureDirectory()) {
            return false;
        }

        return file_put_contents($this->getHashPath(), $hash, LOCK_EX) !== false;
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
     * Ensure cache directory exists
     */
    public function ensureDirectory(): bool
    {
        if (is_dir($this->cacheDir)) {
            return true;
        }

        return \wp_mkdir_p($this->cacheDir);
    }

    /**
     * Get cache directory
     */
    public function getCacheDir(): string
    {
        return $this->cacheDir;
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
}
