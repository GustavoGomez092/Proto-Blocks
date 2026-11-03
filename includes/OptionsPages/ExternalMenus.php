<?php

declare(strict_types=1);

namespace ProtoBlocks\OptionsPages;

use ProtoBlocks\ContentModel\OriginTracker;

/**
 * The admin menu as other code registered it, captured while the Options
 * Pages screen loads so the editor can offer menu parents and show what a new
 * page will sit next to. Only slugs, titles and who registered them are kept;
 * no callbacks, capabilities or URLs.
 *
 * Captured at the very end of `admin_menu` (so every plugin and theme has
 * registered), only on the Options Pages screen, and kept for an hour in a
 * transient that the REST routes read.
 */
final class ExternalMenus
{
    public const TRANSIENT = 'proto_blocks_external_menus';
    public const SCREEN = 'proto-blocks-options-pages';

    private const TTL = 3600;
    private const TITLE_MAX = 200;

    /** @var array{plugin: string, mu: string, themes: list<string>}|null */
    private ?array $dirs;

    /**
     * @param array{plugin?: string, mu?: string, themes?: list<string>}|null $dirs Where plugins and themes live; WordPress's own when null.
     */
    public function __construct(?array $dirs = null)
    {
        $this->dirs = $dirs === null ? null : [
            'plugin' => (string) ($dirs['plugin'] ?? ''),
            'mu' => (string) ($dirs['mu'] ?? ''),
            'themes' => array_values($dirs['themes'] ?? []),
        ];
    }

    /** Hook the capture to the end of `admin_menu`. */
    public function boot(): void
    {
        add_action('admin_menu', [$this, 'capture'], PHP_INT_MAX);
    }

    /**
     * Hooked to `admin_menu` PHP_INT_MAX. Does nothing unless the Options
     * Pages screen is being loaded.
     */
    public function capture(): void
    {
        $page = $_GET['page'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification -- compared to a constant, never used.
        if ($page !== self::SCREEN) {
            return;
        }

        $menu = $GLOBALS['menu'] ?? [];
        $submenu = $GLOBALS['submenu'] ?? [];
        $pages = [];
        $parents = [];

        foreach (is_array($menu) ? $menu : [] as $entry) {
            if (!is_array($entry) || !isset($entry[2]) || !is_string($entry[2]) || self::isSeparator($entry)) {
                continue;
            }
            $slug = $entry[2];
            $title = self::title($entry[0] ?? '');
            if ($title === '') {
                $title = $slug;
            }
            $parents[] = ['slug' => $slug, 'title' => $title];

            $row = $this->row($slug, $title, 'top', null);
            if ($row !== null) {
                $pages[] = $row;
            }
            $children = is_array($submenu) ? ($submenu[$slug] ?? []) : [];
            foreach (is_array($children) ? $children : [] as $child) {
                // A parent's first submenu entry repeats the parent's own slug.
                if (!is_array($child) || !isset($child[2]) || !is_string($child[2]) || $child[2] === $slug) {
                    continue;
                }
                $childTitle = self::title($child[0] ?? '');
                $row = $this->row($child[2], $childTitle === '' ? $child[2] : $childTitle, $slug, $slug);
                if ($row !== null) {
                    $pages[] = $row;
                }
            }
        }

        set_transient(self::TRANSIENT, ['pages' => $pages, 'parents' => $parents], self::TTL);
    }

    /**
     * @return array{pages: list<array{slug: string, title: string, parent: string, origin: string, originLabel: string}>, parents: list<array{slug: string, title: string}>}
     */
    public function get(): array
    {
        $stored = get_transient(self::TRANSIENT);
        $out = ['pages' => [], 'parents' => []];
        if (!is_array($stored)) {
            return $out;
        }
        foreach ($stored['pages'] ?? [] as $row) {
            if (is_array($row) && is_string($row['slug'] ?? null) && is_string($row['title'] ?? null) && is_string($row['parent'] ?? null)
                && in_array($row['origin'] ?? null, ['core', 'plugin', 'theme'], true) && is_string($row['originLabel'] ?? null)) {
                $out['pages'][] = [
                    'slug' => $row['slug'],
                    'title' => $row['title'],
                    'parent' => $row['parent'],
                    'origin' => $row['origin'],
                    'originLabel' => $row['originLabel'],
                ];
            }
        }
        foreach ($stored['parents'] ?? [] as $row) {
            if (is_array($row) && is_string($row['slug'] ?? null) && is_string($row['title'] ?? null)) {
                $out['parents'][] = ['slug' => $row['slug'], 'title' => $row['title']];
            }
        }

        return $out;
    }

    /**
     * One page, or null for a page Proto-Blocks registered itself.
     *
     * @return array{slug: string, title: string, parent: string, origin: string, originLabel: string}|null
     */
    private function row(string $slug, string $title, string $parent, ?string $parentSlug): ?array
    {
        $origin = $this->originOf($slug, $parentSlug);
        if ($origin === null) {
            return null;
        }
        [$type, $label] = $origin;

        return ['slug' => $slug, 'title' => $title, 'parent' => $parent, 'origin' => $type, 'originLabel' => $label];
    }

    /**
     * @return array{0: string, 1: string}|null [origin, label]; null for Proto-Blocks's own pages
     */
    private function originOf(string $slug, ?string $parentSlug): ?array
    {
        $core = ['core', __('Core', 'proto-blocks')];
        $file = $this->callbackFile($slug, $parentSlug);
        if ($file === null) {
            // File-based screens (options-general.php, edit.php...) have no callback.
            return str_ends_with(strtok($slug, '?') ?: $slug, '.php') ? $core : ['plugin', __('Unknown', 'proto-blocks')];
        }

        $dirs = $this->dirs ?? [
            'plugin' => defined('WP_PLUGIN_DIR') ? (string) WP_PLUGIN_DIR : '',
            'mu' => defined('WPMU_PLUGIN_DIR') ? (string) WPMU_PLUGIN_DIR : '',
            'themes' => self::themeDirs(),
        ];
        $origin = OriginTracker::classify($file, $dirs['plugin'], $dirs['mu'], $dirs['themes'], dirname(__DIR__, 2));
        $name = is_string($origin['name']) && $origin['name'] !== '' ? $origin['name'] : __('Unknown', 'proto-blocks');

        return match ($origin['type']) {
            'proto' => null,
            'core' => $core,
            /* translators: %s: plugin folder name */
            'plugin' => ['plugin', sprintf(__('Plugin: %s', 'proto-blocks'), $name)],
            /* translators: %s: theme folder name */
            'theme' => ['theme', sprintf(__('Theme: %s', 'proto-blocks'), $name)],
            default => ['plugin', __('Unknown', 'proto-blocks')],
        };
    }

    /** The file that defines the callback WordPress renders $slug with, or null when there is none. */
    private function callbackFile(string $slug, ?string $parentSlug): ?string
    {
        if (function_exists('get_plugin_page_hookname')) {
            $hook = get_plugin_page_hookname($slug, $parentSlug ?? '');
        } else {
            $hook = $parentSlug === null ? 'toplevel_page_' . $slug : $parentSlug . '_page_' . $slug;
        }
        $registered = $GLOBALS['wp_filter'][$hook] ?? null;
        $callbacks = is_object($registered) ? ($registered->callbacks ?? []) : (is_array($registered) ? $registered : []);

        foreach (is_array($callbacks) ? $callbacks : [] as $priority) {
            foreach (is_array($priority) ? $priority : [] as $entry) {
                $callback = is_array($entry) ? ($entry['function'] ?? null) : null;
                $file = self::fileOf($callback);
                if ($file !== null) {
                    return $file;
                }
            }
        }

        return null;
    }

    private static function fileOf(mixed $callback): ?string
    {
        try {
            if ($callback instanceof \Closure || (is_string($callback) && function_exists($callback))) {
                $file = (new \ReflectionFunction($callback))->getFileName();
            } elseif (is_array($callback) && count($callback) === 2 && (is_object($callback[0]) || is_string($callback[0])) && is_string($callback[1])) {
                $file = (new \ReflectionMethod($callback[0], $callback[1]))->getFileName();
            } elseif (is_object($callback) && method_exists($callback, '__invoke')) {
                $file = (new \ReflectionMethod($callback, '__invoke'))->getFileName();
            } else {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return is_string($file) && $file !== '' ? $file : null;
    }

    /** @return list<string> */
    private static function themeDirs(): array
    {
        $dirs = $GLOBALS['wp_theme_directories'] ?? (function_exists('get_theme_root') ? [get_theme_root()] : []);

        return is_array($dirs) ? array_values(array_filter($dirs, 'is_string')) : [];
    }

    /** @param array<int|string, mixed> $entry */
    private static function isSeparator(array $entry): bool
    {
        return str_starts_with((string) ($entry[2] ?? ''), 'separator') || str_contains((string) ($entry[4] ?? ''), 'wp-menu-separator');
    }

    /** A menu title as plain text: markup (update bubbles) removed, capped. */
    private static function title(mixed $raw): string
    {
        $text = is_string($raw) ? trim(html_entity_decode(strip_tags($raw), ENT_QUOTES, 'UTF-8')) : '';

        return mb_substr(preg_replace('/\s+/u', ' ', $text) ?? '', 0, self::TITLE_MAX);
    }
}
