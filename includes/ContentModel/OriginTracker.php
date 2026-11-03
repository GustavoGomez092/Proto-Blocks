<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * Records who registered each post type and taxonomy (core, a plugin, a
 * theme or Proto-Blocks), from the call site of register_post_type() /
 * register_taxonomy().
 *
 * The backtrace costs something, so the Manager hooks this only where the
 * answer is shown: wp-admin, WP-CLI and the plugin's REST routes (wanted()).
 * `name` is the plugin or theme folder; the REST layer resolves display names.
 */
final class OriginTracker
{
    /** Frames inside these files are WordPress's own plumbing, not the caller. */
    private const PLUMBING = [
        '/wp-includes/class-wp-hook.php',
        '/wp-includes/plugin.php',
        '/wp-includes/post.php',
        '/wp-includes/taxonomy.php',
    ];

    /** @var array<string, array<string, array{type: string, name: ?string}>> kind => slug => origin */
    private array $origins = [];

    /** Whether this request shows origins: admin, WP-CLI or a plugin REST route. */
    public static function wanted(): bool
    {
        if (is_admin() || (defined('WP_CLI') && WP_CLI)) {
            return true;
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        return is_string($uri) && str_contains(rawurldecode($uri), '/proto-blocks/v1/');
    }

    public function boot(): void
    {
        add_action('registered_post_type', [$this, 'recordPostType'], PHP_INT_MAX, 1);
        add_action('registered_taxonomy', [$this, 'recordTaxonomy'], PHP_INT_MAX, 1);
    }

    /** @internal Hooked to `registered_post_type`. */
    public function recordPostType(mixed $slug): void
    {
        if (is_string($slug)) {
            $this->origins[Declaration::TYPE][$slug] = $this->caller();
        }
    }

    /** @internal Hooked to `registered_taxonomy`. */
    public function recordTaxonomy(mixed $slug): void
    {
        if (is_string($slug)) {
            $this->origins[Declaration::TAXONOMY][$slug] = $this->caller();
        }
    }

    /**
     * @param string $kind Declaration::TYPE or Declaration::TAXONOMY
     * @return array{type: 'core'|'plugin'|'theme'|'proto'|'unknown', name: ?string}
     */
    public function originOf(string $kind, string $slug): array
    {
        $object = $kind === Declaration::TAXONOMY ? get_taxonomy($slug) : get_post_type_object($slug);
        if (is_object($object) && !empty($object->_builtin)) {
            return ['type' => 'core', 'name' => null];
        }

        return $this->origins[$kind][$slug] ?? ['type' => 'unknown', 'name' => null];
    }

    /**
     * @param list<string> $themeDirs Theme root directories (each holds theme folders).
     * @return array{type: 'core'|'plugin'|'theme'|'proto'|'unknown', name: ?string}
     */
    public static function classify(string $file, string $pluginDir, string $muPluginDir, array $themeDirs, string $protoDir): array
    {
        $file = self::normalise($file);

        if (self::folderUnder($file, $protoDir) !== null) {
            return ['type' => 'proto', 'name' => null];
        }
        foreach ([$muPluginDir, $pluginDir] as $dir) {
            $folder = self::folderUnder($file, $dir);
            if ($folder !== null) {
                return ['type' => 'plugin', 'name' => self::stripPhp($folder)];
            }
        }
        foreach ($themeDirs as $dir) {
            $folder = is_string($dir) ? self::folderUnder($file, $dir) : null;
            if ($folder !== null) {
                return ['type' => 'theme', 'name' => $folder];
            }
        }
        if (str_contains($file, '/wp-includes/') || str_contains($file, '/wp-admin/') || str_ends_with($file, '/wp-settings.php')) {
            return ['type' => 'core', 'name' => null];
        }

        return ['type' => 'unknown', 'name' => null];
    }

    /** @return array{type: string, name: ?string} */
    private function caller(): array
    {
        $file = null;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $candidate = isset($frame['file']) ? self::normalise((string) $frame['file']) : null;
            if ($candidate === null || $candidate === self::normalise(__FILE__) || self::isPlumbing($candidate)) {
                continue;
            }
            $file = $candidate;
            break;
        }
        if ($file === null) {
            return ['type' => 'unknown', 'name' => null];
        }

        $themeDirs = $GLOBALS['wp_theme_directories'] ?? (function_exists('get_theme_root') ? [get_theme_root()] : []);

        return self::classify(
            $file,
            defined('WP_PLUGIN_DIR') ? (string) WP_PLUGIN_DIR : '',
            defined('WPMU_PLUGIN_DIR') ? (string) WPMU_PLUGIN_DIR : '',
            is_array($themeDirs) ? array_values($themeDirs) : [],
            dirname(__DIR__, 2)
        );
    }

    private static function isPlumbing(string $file): bool
    {
        foreach (self::PLUMBING as $suffix) {
            if (str_ends_with($file, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** First path segment of $file below $dir, or null when $file is not inside $dir. */
    private static function folderUnder(string $file, string $dir): ?string
    {
        $dir = rtrim(self::normalise($dir), '/');
        if ($dir === '' || !str_starts_with($file, $dir . '/')) {
            return null;
        }
        $rest = substr($file, strlen($dir) + 1);
        $folder = explode('/', $rest, 2)[0];

        return $folder === '' ? null : $folder;
    }

    private static function stripPhp(string $folder): string
    {
        return str_ends_with($folder, '.php') ? substr($folder, 0, -4) : $folder;
    }

    private static function normalise(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
