<?php
/**
 * TailwindRoutes - REST routes for the Tailwind Settings screen
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\API\Admin;

use ProtoBlocks\Admin\AdminPage;
use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\Tailwind\Cache;
use ProtoBlocks\Tailwind\Manager;
use WP_Error;
use WP_REST_Request;

/**
 * admin/tailwind: status, settings, compile (CLI engine), cache, CLI download
 * and the starter theme file. The browser engine compiles through the two
 * AJAX actions Tailwind\AdminSettings keeps (KEPT_AJAX_ACTIONS).
 */
final class TailwindRoutes
{
    private const MODES = ['cached', 'on_reload'];
    private const ENGINES = ['auto', 'cli', 'browser'];

    /**
     * Starter @theme template written into the active theme by the
     * theme-file route. A jumping-off point, not a full colour system.
     */
    private const STARTER_THEME_CSS = <<<'CSS'
/*
 * Tailwind v4 design tokens.
 *
 * Each --color-*, --font-*, --shadow-* etc. exposed here is compiled into a
 * matching utility class (bg-brand, text-brand, font-display, ...) on every
 * Tailwind recompile. Edit this file in your theme repo -- nothing is read
 * from the database.
 *
 * Recompile happens on any front-end page load, or run:
 *   wp eval 'ProtoBlocks\Core\Plugin::getInstance()->getTailwindManager()->compile();'
 *
 * Docs:
 *   https://tailwindcss.com/docs/theme
 */

@theme {
  /* Brand colors -- replace with yours */
  --color-brand:     #3B82F6;
  --color-brand-700: #1D4ED8;

  /* Typography */
  --font-display: "Inter", ui-sans-serif, system-ui, sans-serif;
  --font-body:    "Inter", ui-sans-serif, system-ui, sans-serif;
}

CSS;

    private ?Manager $manager = null;

    public function registerRoutes(string $namespace, callable $permission, callable $writePermission): void
    {
        $base = '/' . AdminRestController::BASE . '/tailwind';

        register_rest_route($namespace, $base, [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get'],
                'permission_callback' => $permission,
            ],
        ]);
        register_rest_route($namespace, $base . '/settings', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'updateSettings'],
                'permission_callback' => $permission,
            ],
        ]);
        register_rest_route($namespace, $base . '/compile', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'compile'],
                'permission_callback' => $permission,
            ],
        ]);
        register_rest_route($namespace, $base . '/cache', [
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'clearCache'],
                'permission_callback' => $permission,
            ],
        ]);
        register_rest_route($namespace, $base . '/cli', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'downloadCli'],
                'permission_callback' => $writePermission,
            ],
        ]);
        register_rest_route($namespace, $base . '/theme-file', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'createThemeFile'],
                'permission_callback' => $writePermission,
            ],
        ]);
    }

    /**
     * Validate a settings update. Accepts any subset of enabled (bool),
     * mode (cached|on_reload), engine (auto|cli|browser) and
     * disableGlobalStyles (bool); unknown keys are dropped.
     *
     * @param array<string,mixed> $input
     * @return array<string,bool|string>|WP_Error
     */
    public static function normalizeTailwindSettings(array $input)
    {
        $out = [];

        foreach ($input as $key => $value) {
            switch ($key) {
                case 'enabled':
                case 'disableGlobalStyles':
                    $bool = self::toBool($value);
                    if ($bool === null) {
                        return self::invalid();
                    }
                    $out[$key] = $bool;
                    break;
                case 'mode':
                    if (!is_string($value) || !in_array($value, self::MODES, true)) {
                        return self::invalid();
                    }
                    $out[$key] = $value;
                    break;
                case 'engine':
                    if (!is_string($value) || !in_array($value, self::ENGINES, true)) {
                        return self::invalid();
                    }
                    $out[$key] = $value;
                    break;
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function get(): array
    {
        return $this->payload();
    }

    /** @return array<string,mixed>|WP_Error */
    public function updateSettings(WP_REST_Request $request)
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = [];
            foreach (['enabled', 'mode', 'engine', 'disableGlobalStyles'] as $key) {
                $value = $request->get_param($key);
                if ($value !== null) {
                    $body[$key] = $value;
                }
            }
        }

        $settings = self::normalizeTailwindSettings($body);
        if ($settings instanceof WP_Error) {
            return $settings;
        }

        $manager = $this->manager();
        if (array_key_exists('enabled', $settings)) {
            $settings['enabled'] ? $manager->enable() : $manager->disable();
        }
        if (isset($settings['mode'])) {
            $manager->setMode((string) $settings['mode']);
        }
        if (isset($settings['engine'])) {
            $manager->setEngine((string) $settings['engine']);
        }
        if (array_key_exists('disableGlobalStyles', $settings)) {
            $manager->setDisableGlobalStyles((bool) $settings['disableGlobalStyles']);
        }

        return $this->payload();
    }

    /** @return array<string,mixed>|WP_Error */
    public function compile()
    {
        $result = $this->manager()->compile();

        if (empty($result['success'])) {
            $message = (string) ($result['message'] ?? '');
            return new WP_Error(
                'proto_blocks_compile_failed',
                $message !== '' ? $message : __('Tailwind compilation failed.', 'proto-blocks'),
                ['status' => 500]
            );
        }

        return $this->payload();
    }

    /** @return array<string,mixed>|WP_Error */
    public function clearCache()
    {
        $result = $this->manager()->clearCache(false);

        if (empty($result['success'])) {
            return new WP_Error(
                'proto_blocks_cache_clear_failed',
                (string) ($result['message'] ?? __('The Tailwind cache could not be cleared.', 'proto-blocks')),
                ['status' => 500]
            );
        }

        return $this->payload();
    }

    /** @return array<string,mixed>|WP_Error */
    public function downloadCli()
    {
        if (!AdminPage::fileModsAllowed()) {
            return AdminRestController::fileModsError();
        }

        $result = $this->manager()->getBinaryManager()->download(true);

        if (empty($result['success'])) {
            $message = (string) ($result['message'] ?? '');
            return new WP_Error(
                'proto_blocks_cli_download',
                $message !== '' ? $message : __('The Tailwind CLI could not be downloaded.', 'proto-blocks'),
                ['status' => 500]
            );
        }

        return $this->payload();
    }

    /** @return array<string,mixed>|WP_Error */
    public function createThemeFile()
    {
        if (!AdminPage::fileModsAllowed()) {
            return AdminRestController::fileModsError();
        }

        $created = $this->createThemeFileAt($this->manager()->getConfigEditor()->getThemeCssPath());
        if ($created instanceof WP_Error) {
            return $created;
        }

        return $this->payload();
    }

    /**
     * Write the starter theme CSS to $path. Never overwrites an existing
     * file (or a symlink, even a dangling one).
     *
     * @return true|WP_Error
     */
    public function createThemeFileAt(string $path)
    {
        $display = self::displayPath($path);

        if (file_exists($path) || is_link($path)) {
            return new WP_Error(
                'proto_blocks_exists',
                /* translators: %s: theme file path */
                sprintf(__('%s already exists, so it was not overwritten.', 'proto-blocks'), $display),
                ['status' => 409]
            );
        }

        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            return new WP_Error(
                'proto_blocks_not_writable',
                /* translators: %s: theme folder path */
                sprintf(__('The theme folder %s is not writable.', 'proto-blocks'), self::displayPath($dir)),
                ['status' => 500]
            );
        }

        if (!Cache::atomicWrite($path, self::STARTER_THEME_CSS)) {
            return new WP_Error(
                'proto_blocks_write_failed',
                /* translators: %s: theme file path */
                sprintf(__('Could not write %s.', 'proto-blocks'), $display),
                ['status' => 500]
            );
        }

        return true;
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        $manager = $this->manager();
        $status = $manager->getStatus();
        $settings = $manager->getSettings();
        $config = $manager->getConfigEditor()->getConfigData();
        $themeFile = (string) $config['path'];
        $lastCompiled = $settings['last_compiled'] ?? null;

        return [
            'status' => $status,
            'settings' => [
                'enabled' => (bool) $status['enabled'],
                'mode' => (string) $status['mode'],
                'engine' => (string) $status['engine_setting'],
                'disableGlobalStyles' => (bool) $status['disable_global_styles'],
                'lastCompiled' => is_numeric($lastCompiled) ? (int) $lastCompiled : null,
            ],
            'config' => [
                'path' => self::displayPath($themeFile),
                'exists' => (bool) $config['exists'],
                'preview' => (string) $config['preview'],
                'dirWritable' => is_dir(dirname($themeFile)) && is_writable(dirname($themeFile)),
            ],
            'cssSize' => $manager->getCache()->getSize(),
            'cssSizeFormatted' => $manager->getCache()->getFormattedSize(),
        ];
    }

    /** $path relative to the themes folder (wp-content/themes), else to ABSPATH. */
    private static function displayPath(string $path): string
    {
        if (function_exists('get_theme_root')) {
            $relative = AdminRestController::relativePath($path, (string) get_theme_root());
            if ($relative !== $path) {
                return $relative;
            }
        }

        return AdminRestController::relativePath($path, defined('ABSPATH') ? (string) \ABSPATH : '');
    }

    /**
     * @param mixed $value
     */
    private static function toBool($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return $value === 1;
        }
        if (is_string($value)) {
            if (in_array($value, ['1', 'true'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', ''], true)) {
                return false;
            }
        }
        return null;
    }

    private static function invalid(): WP_Error
    {
        return new WP_Error(
            'proto_blocks_invalid_setting',
            __('That Tailwind setting is not valid.', 'proto-blocks'),
            ['status' => 400]
        );
    }

    private function manager(): Manager
    {
        return $this->manager ??= Manager::getInstance();
    }
}
