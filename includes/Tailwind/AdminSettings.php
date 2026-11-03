<?php
/**
 * Admin Settings - AJAX endpoints of the in-browser Tailwind compiler
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Tailwind;

/**
 * The AJAX actions the browser compiler posts to. The settings screen is
 * React (src/admin/screens/TailwindScreen.tsx) over includes/API/Admin/TailwindRoutes.php.
 */
class AdminSettings
{
    /**
     * AJAX action prefix
     */
    private const ACTION_PREFIX = 'proto_blocks_tailwind_';

    /**
     * Nonce action
     */
    private const NONCE_ACTION = 'proto_blocks_tailwind_nonce';

    /**
     * AJAX actions still served here (after ACTION_PREFIX): the two the
     * browser compiler (src/tailwind/compiler.ts) posts to. Everything else
     * the Tailwind screen does goes through the admin/tailwind REST routes.
     */
    public const KEPT_AJAX_ACTIONS = ['get_compile_inputs', 'store_css'];

    /**
     * Manager instance
     */
    private Manager $manager;

    /**
     * Constructor
     */
    public function __construct(Manager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Initialize hooks
     */
    public function init(): void
    {
        // get_compile_inputs => handleGetCompileInputs, store_css => handleStoreCss
        foreach (self::KEPT_AJAX_ACTIONS as $action) {
            $handler = 'handle' . str_replace('_', '', ucwords($action, '_'));
            \add_action('wp_ajax_' . self::ACTION_PREFIX . $action, [$this, $handler]);
        }
    }

    /**
     * Return the inputs the browser compiler needs: the generated input.css
     * and the aggregated block content to scan. Pure PHP (no exec).
     */
    public function handleGetCompileInputs(): void
    {
        $this->verifyNonce();

        $scanner = $this->manager->getScanner();
        $scanner->refresh();

        $content = $scanner->scanAllBlocks();
        // The browser scans `content` directly, so reference it by a stable
        // virtual name rather than a real file path.
        $inputCss = $this->manager->getConfigEditor()->generateInputCss('proto-blocks-content.html');

        \wp_send_json_success([
            'inputCss' => $inputCss,
            'content' => $content,
            'hash' => $scanner->getContentHash(),
        ]);
    }

    /**
     * Receive browser-compiled CSS, scope + save it, and record the hash.
     */
    public function handleStoreCss(): void
    {
        $this->verifyNonce();

        // CSS can contain characters WP slashes on input; unslash before use.
        $css = isset($_POST['css']) ? \wp_unslash((string) $_POST['css']) : '';
        $hash = isset($_POST['hash']) ? \sanitize_text_field((string) $_POST['hash']) : '';

        // Size cap + content checks: this file is served to every visitor and
        // injected into the editor, so refuse anything that is not plain CSS.
        $rejection = BrowserCompiler::validate($css);
        if ($rejection !== null) {
            \wp_send_json_error(['message' => $rejection]);
            return;
        }

        $compiler = new BrowserCompiler(
            $this->manager->getScoper(),
            $this->manager->getCache()
        );

        if (!$compiler->store($css)) {
            \wp_send_json_error([
                'message' => $compiler->getLastError() ?? \__('No CSS was produced by the browser compiler.', 'proto-blocks'),
            ]);
            return; // wp_send_json_error exits, but be explicit (and testable).
        }

        // Record the content hash the same way the CLI path does, so
        // Manager::needsRecompilation() (which compares settings['content_hash'])
        // stays consistent across both compile engines.
        if ($hash !== '') {
            $this->manager->getCache()->saveHash($hash);
        }
        $this->manager->updateSettings([
            'last_compiled' => time(),
            'content_hash' => $hash !== '' ? $hash : null,
        ]);

        $cache = $this->manager->getCache();
        \wp_send_json_success([
            'message' => \__('Tailwind CSS compiled in the browser and saved.', 'proto-blocks'),
            'css_size' => $cache->getSize(),
            'css_size_formatted' => $cache->getFormattedSize(),
        ]);
    }

    /**
     * Verify nonce
     */
    private function verifyNonce(): void
    {
        if (!\current_user_can('manage_options')) {
            \wp_send_json_error(['message' => \__('Permission denied.', 'proto-blocks')]);
        }

        $nonce = $_POST['nonce'] ?? '';
        if (!\wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            \wp_send_json_error(['message' => \__('Security check failed.', 'proto-blocks')]);
        }
    }

    /**
     * Get nonce action name
     */
    public static function getNonceAction(): string
    {
        return self::NONCE_ACTION;
    }
}
