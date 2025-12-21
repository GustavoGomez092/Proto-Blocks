<?php
/**
 * PSR-4 Autoloader for Proto-Blocks
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Core;

/**
 * Handles autoloading of classes following PSR-4 standard
 */
final class Autoloader
{
    /**
     * Namespace prefix for Proto-Blocks
     */
    private const NAMESPACE_PREFIX = 'ProtoBlocks\\';

    /**
     * Base directory for the namespace
     */
    private static string $baseDir;

    /**
     * Whether autoloader has been registered
     */
    private static bool $registered = false;

    /**
     * Register the autoloader
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$baseDir = dirname(__DIR__) . '/';

        spl_autoload_register([self::class, 'loadClass']);
        self::$registered = true;
    }

    /**
     * Load a class file
     *
     * @param string $class Fully qualified class name
     */
    public static function loadClass(string $class): void
    {
        // Check if the class uses our namespace prefix
        $prefixLength = strlen(self::NAMESPACE_PREFIX);
        if (strncmp(self::NAMESPACE_PREFIX, $class, $prefixLength) !== 0) {
            return;
        }

        // Get the relative class name
        $relativeClass = substr($class, $prefixLength);

        // Replace namespace separators with directory separators
        $file = self::$baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        // Load the file if it exists
        if (file_exists($file)) {
            require_once $file;
        }
    }

    /**
     * Get the base directory
     */
    public static function getBaseDir(): string
    {
        return self::$baseDir;
    }
}
