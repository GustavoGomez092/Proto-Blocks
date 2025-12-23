<?php
/**
 * Tailwind Manager - Main orchestrator for Tailwind CSS integration
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Tailwind;

use ProtoBlocks\Blocks\Discovery;

/**
 * Main Tailwind CSS manager singleton
 */
final class Manager
{
    /**
     * Singleton instance
     */
    private static ?Manager $instance = null;

    /**
     * Option name for Tailwind settings
     */
    public const OPTION_NAME = 'proto_blocks_tailwind';

    /**
     * Default settings
     */
    private const DEFAULT_SETTINGS = [
        'enabled' => false,
        'mode' => 'cached', // 'cached' or 'on_reload'
        'last_compiled' => null,
        'content_hash' => null,
        'theme_config' => '',
        'cli_version' => null,
        'disable_global_styles' => false, // Disable WordPress global styles
    ];

    /**
     * Services
     */
    private ?BinaryManager $binaryManager = null;
    private ?Scanner $scanner = null;
    private ?Compiler $compiler = null;
    private ?Cache $cache = null;
    private ?Scoper $scoper = null;
    private ?ConfigEditor $configEditor = null;

    /**
     * Block discovery instance
     */
    private ?Discovery $discovery = null;

    /**
     * Private constructor (singleton)
     */
    private function __construct()
    {
    }

    /**
     * Prevent cloning
     */
    private function __clone()
    {
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initialize the Tailwind manager with dependencies
     */
    public function init(Discovery $discovery): void
    {
        $this->discovery = $discovery;
    }

    /**
     * Get settings
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        $settings = \get_option(self::OPTION_NAME, []);
        return array_merge(self::DEFAULT_SETTINGS, $settings);
    }

    /**
     * Update settings
     *
     * @param array<string, mixed> $settings
     */
    public function updateSettings(array $settings): bool
    {
        $current = \get_option(self::OPTION_NAME, []);
        $updated = array_merge($current, $settings);

        // update_option returns false if value unchanged, but that's still a "success" for our purposes
        \update_option(self::OPTION_NAME, $updated);

        // Always return true - if there was a real error, WordPress would handle it
        return true;
    }

    /**
     * Check if Tailwind is enabled
     */
    public function isEnabled(): bool
    {
        $settings = $this->getSettings();
        return !empty($settings['enabled']);
    }

    /**
     * Get compilation mode
     */
    public function getMode(): string
    {
        $settings = $this->getSettings();
        return $settings['mode'] ?? 'cached';
    }

    /**
     * Check if on-reload mode is active
     */
    public function isOnReloadMode(): bool
    {
        return $this->getMode() === 'on_reload';
    }

    /**
     * Enable Tailwind support
     */
    public function enable(): bool
    {
        return $this->updateSettings(['enabled' => true]);
    }

    /**
     * Disable Tailwind support
     */
    public function disable(): bool
    {
        return $this->updateSettings(['enabled' => false]);
    }

    /**
     * Set compilation mode
     */
    public function setMode(string $mode): bool
    {
        if (!in_array($mode, ['cached', 'on_reload'], true)) {
            return false;
        }
        return $this->updateSettings(['mode' => $mode]);
    }

    /**
     * Check if global styles should be disabled
     */
    public function shouldDisableGlobalStyles(): bool
    {
        $settings = $this->getSettings();
        return $this->isEnabled() && !empty($settings['disable_global_styles']);
    }

    /**
     * Set disable global styles option
     */
    public function setDisableGlobalStyles(bool $disable): bool
    {
        return $this->updateSettings(['disable_global_styles' => $disable]);
    }

    /**
     * Disable WordPress global styles (hooked to init)
     */
    public function maybeDisableGlobalStyles(): void
    {
        if (!$this->shouldDisableGlobalStyles()) {
            return;
        }

        // Remove global styles from frontend
        \remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
        \remove_action('wp_footer', 'wp_enqueue_global_styles', 1);
        \remove_action('wp_body_open', 'wp_global_styles_render_svg_filters');

        // Remove global styles from block editor
        \add_filter('block_editor_settings_all', [$this, 'filterEditorGlobalStyles'], 10, 2);

        // Remove inline global styles from editor
        \add_action('enqueue_block_editor_assets', [$this, 'dequeueEditorGlobalStyles'], 100);
    }

    /**
     * Filter editor settings to remove global styles
     *
     * @param array $settings Editor settings
     * @param \WP_Block_Editor_Context $context Editor context
     * @return array Modified settings
     */
    public function filterEditorGlobalStyles(array $settings, $context): array
    {
        // Remove the global styles from editor settings
        if (isset($settings['styles'])) {
            $settings['styles'] = array_filter($settings['styles'], function ($style) {
                // Keep styles that don't contain global-styles markers
                if (isset($style['css'])) {
                    // Filter out styles with wp-style- variables (global styles)
                    if (strpos($style['css'], '--wp--style--') !== false) {
                        return false;
                    }
                    if (strpos($style['css'], '--wp--preset--') !== false) {
                        return false;
                    }
                }
                return true;
            });
            // Re-index array
            $settings['styles'] = array_values($settings['styles']);
        }

        return $settings;
    }

    /**
     * Dequeue global styles in the editor
     */
    public function dequeueEditorGlobalStyles(): void
    {
        // Dequeue global styles in editor context
        \wp_dequeue_style('global-styles');
        \wp_dequeue_style('wp-block-library-theme');
    }

    /**
     * Compile Tailwind CSS
     *
     * @return array{success: bool, message: string, output?: string}
     */
    public function compile(): array
    {
        if (!$this->isEnabled()) {
            return [
                'success' => false,
                'message' => \__('Tailwind CSS support is not enabled.', 'proto-blocks'),
            ];
        }

        $compiler = $this->getCompiler();
        $result = $compiler->compile();

        if ($result['success']) {
            $this->updateSettings([
                'last_compiled' => time(),
                'content_hash' => $this->getScanner()->getContentHash(),
            ]);
        }

        return $result;
    }

    /**
     * Clear cache and optionally recompile
     */
    public function clearCache(bool $recompile = false): array
    {
        $this->getCache()->clear();

        if ($recompile && $this->isEnabled()) {
            return $this->compile();
        }

        return [
            'success' => true,
            'message' => \__('Cache cleared successfully.', 'proto-blocks'),
        ];
    }

    /**
     * Check if recompilation is needed
     */
    public function needsRecompilation(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $settings = $this->getSettings();
        $currentHash = $this->getScanner()->getContentHash();

        // No previous compilation
        if (empty($settings['content_hash'])) {
            return true;
        }

        // Content has changed
        if ($settings['content_hash'] !== $currentHash) {
            return true;
        }

        // Cache file doesn't exist
        if (!$this->getCache()->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Maybe compile on page load (for on-reload mode)
     */
    public function maybeCompileOnReload(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (!$this->isOnReloadMode()) {
            return;
        }

        // Only for admins
        if (!\current_user_can('manage_options')) {
            return;
        }

        // Check if recompilation is needed
        if ($this->needsRecompilation()) {
            $this->compile();
        }
    }

    /**
     * Get status information
     *
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        $settings = $this->getSettings();
        $cache = $this->getCache();
        $binaryManager = $this->getBinaryManager();

        return [
            'enabled' => $this->isEnabled(),
            'mode' => $this->getMode(),
            'disable_global_styles' => !empty($settings['disable_global_styles']),
            'cli_installed' => $binaryManager->isInstalled(),
            'cli_version' => $binaryManager->getVersion(),
            'cache_exists' => $cache->exists(),
            'cache_size' => $cache->getSize(),
            'cache_url' => $cache->getUrl(),
            'last_compiled' => $settings['last_compiled'],
            'needs_recompilation' => $this->needsRecompilation(),
        ];
    }

    // Service getters

    /**
     * Get BinaryManager instance
     */
    public function getBinaryManager(): BinaryManager
    {
        if ($this->binaryManager === null) {
            $this->binaryManager = new BinaryManager();
        }
        return $this->binaryManager;
    }

    /**
     * Get Scanner instance
     */
    public function getScanner(): Scanner
    {
        if ($this->scanner === null) {
            $this->scanner = new Scanner($this->discovery);
        }
        return $this->scanner;
    }

    /**
     * Get Compiler instance
     */
    public function getCompiler(): Compiler
    {
        if ($this->compiler === null) {
            $this->compiler = new Compiler(
                $this->getBinaryManager(),
                $this->getScanner(),
                $this->getCache(),
                $this->getScoper(),
                $this->getConfigEditor()
            );
        }
        return $this->compiler;
    }

    /**
     * Get Cache instance
     */
    public function getCache(): Cache
    {
        if ($this->cache === null) {
            $this->cache = new Cache();
        }
        return $this->cache;
    }

    /**
     * Get Scoper instance
     */
    public function getScoper(): Scoper
    {
        if ($this->scoper === null) {
            $this->scoper = new Scoper();
        }
        return $this->scoper;
    }

    /**
     * Get ConfigEditor instance
     */
    public function getConfigEditor(): ConfigEditor
    {
        if ($this->configEditor === null) {
            $this->configEditor = new ConfigEditor();
        }
        return $this->configEditor;
    }

    /**
     * Get Discovery instance
     */
    public function getDiscovery(): ?Discovery
    {
        return $this->discovery;
    }
}
