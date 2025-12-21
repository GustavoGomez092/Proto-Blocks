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
 */
class Cache
{
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
        $this->cacheDir = $cacheDir ?? WP_CONTENT_DIR . '/cache/proto-blocks/';

        // Ensure cache directory exists
        if (!is_dir($this->cacheDir)) {
            wp_mkdir_p($this->cacheDir);
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

        // Check file cache
        $cacheFile = $this->getCacheFile($templatePath);

        if (!$this->isValid($templatePath, $cacheFile)) {
            return null;
        }

        try {
            $data = include $cacheFile;

            if (is_array($data)) {
                $this->memoryCache[$cacheKey] = $data;
                return $data;
            }
        } catch (\Throwable $e) {
            // Cache file is corrupted
            $this->delete($templatePath);
        }

        return null;
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

        $cacheKey = $this->getCacheKey($templatePath);
        $cacheFile = $this->getCacheFile($templatePath);

        // Store in memory cache
        $this->memoryCache[$cacheKey] = $data;

        // Store in file cache
        $content = '<?php return ' . var_export($data, true) . ';';

        file_put_contents($cacheFile, $content, LOCK_EX);
    }

    /**
     * Check if cache is valid for a template
     */
    public function isValid(string $templatePath, ?string $cacheFile = null): bool
    {
        $cacheFile = $cacheFile ?? $this->getCacheFile($templatePath);

        if (!file_exists($cacheFile)) {
            return false;
        }

        $cacheTime = filemtime($cacheFile);

        // Check template file modification time
        if (!file_exists($templatePath) || filemtime($templatePath) > $cacheTime) {
            return false;
        }

        // Check JSON config file
        $jsonPath = $this->getJsonPath($templatePath);
        if (file_exists($jsonPath) && filemtime($jsonPath) > $cacheTime) {
            return false;
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

        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }

    /**
     * Clear all cached data
     */
    public function clear(): int
    {
        $this->memoryCache = [];
        $count = 0;

        $files = glob($this->cacheDir . '*.php');
        if ($files) {
            foreach ($files as $file) {
                if (unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        $files = glob($this->cacheDir . '*.php') ?: [];

        $totalSize = 0;
        foreach ($files as $file) {
            $totalSize += filesize($file);
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
     * Get cache key for a template
     */
    private function getCacheKey(string $templatePath): string
    {
        return md5($templatePath);
    }

    /**
     * Get cache file path for a template
     */
    private function getCacheFile(string $templatePath): string
    {
        $key = $this->getCacheKey($templatePath);
        $name = basename(dirname($templatePath));

        return $this->cacheDir . $name . '-' . $key . '.php';
    }

    /**
     * Get JSON config path for a template
     */
    private function getJsonPath(string $templatePath): string
    {
        $dir = dirname($templatePath);
        $name = basename($templatePath, '.php');

        return $dir . '/' . $name . '.json';
    }

    /**
     * Get cache directory
     */
    public function getCacheDir(): string
    {
        return $this->cacheDir;
    }
}
