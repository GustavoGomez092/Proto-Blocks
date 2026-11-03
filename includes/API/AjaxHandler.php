<?php
/**
 * AJAX Handler - Handles AJAX requests for faster preview
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\API;

use ProtoBlocks\Template\Engine;
use ProtoBlocks\Blocks\Discovery;
use ProtoBlocks\Core\Plugin;
use ProtoBlocks\Security\AttributeSanitizer;

/**
 * Handles AJAX requests for Proto-Blocks
 */
class AjaxHandler
{
    /**
     * Template engine
     */
    private Engine $engine;

    /**
     * Constructor
     */
    public function __construct(Engine $engine)
    {
        $this->engine = $engine;
    }

    /**
     * Handle preview AJAX request
     */
    public function handlePreview(): void
    {
        // Verify capabilities
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Permission denied.', 'proto-blocks'), 403);
            return;
        }

        // Verify nonce
        $nonce = isset($_POST['nonce']) ? sanitize_key($_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'proto_blocks_preview')) {
            wp_send_json_error(__('Security check failed.', 'proto-blocks'), 403);
            return;
        }

        // Get and validate template name
        $templateName = isset($_POST['template']) ? sanitize_text_field(wp_unslash($_POST['template'])) : '';
        if (empty($templateName)) {
            wp_send_json_error(__('Template name is required.', 'proto-blocks'), 400);
            return;
        }

        // Get and parse attributes
        $attributesRaw = isset($_POST['attributes']) ? wp_unslash($_POST['attributes']) : '{}';
        $attributes = json_decode($attributesRaw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(__('Invalid JSON in attributes.', 'proto-blocks'), 400);
            return;
        }

        if (!is_array($attributes)) {
            $attributes = [];
        }

        // Find the block
        $discovery = Plugin::getInstance()->getDiscovery();
        $blockPath = $discovery->getBlockPath($templateName);

        if (!$blockPath) {
            wp_send_json_error(
                sprintf(__('Block "%s" not found.', 'proto-blocks'), $templateName),
                404
            );
            return;
        }

        // Get template path - try template.php first, then {name}.php
        $templatePath = $blockPath . '/template.php';
        if (!file_exists($templatePath)) {
            $templatePath = $blockPath . '/' . $templateName . '.php';
        }

        if (!file_exists($templatePath)) {
            wp_send_json_error(__('Template file not found.', 'proto-blocks'), 404);
            return;
        }

        // Get JSON path - try block.json first, then {name}.json
        $jsonPath = $blockPath . '/block.json';
        if (!file_exists($jsonPath)) {
            $jsonPath = $blockPath . '/' . $templateName . '.json';
        }

        // Load metadata
        $metadata = [];
        if (file_exists($jsonPath)) {
            $content = file_get_contents($jsonPath);
            $metadata = json_decode($content ?: '{}', true) ?: [];
        }

        // Sanitize attributes (shared with the REST /preview route so both
        // paths are identical).
        $attributes = self::sanitizePreviewAttributes($attributes, $metadata);

        // Optional post context, validated like the REST route's postId
        // (digits only, edit_post, post exists); ignored otherwise.
        $postId = isset($_POST['post_id']) ? wp_unslash($_POST['post_id']) : null;

        try {
            $html = self::renderWithPreviewPost(
                $postId,
                fn(): string => $this->engine->renderPreview($templatePath, $attributes, $metadata)
            );
            wp_send_json_success(['html' => $html]);
        } catch (\Throwable $e) {
            if (PROTO_BLOCKS_DEBUG) {
                wp_send_json_error($e->getMessage(), 500);
            } else {
                wp_send_json_error(__('Failed to generate preview.', 'proto-blocks'), 500);
            }
        }
    }

    /**
     * Run $render with the preview post (if the user may edit it) as the
     * global post, restoring the previous global afterwards. Shared by the
     * admin-ajax preview and the REST /preview route.
     */
    public static function renderWithPreviewPost(mixed $postId, callable $render): mixed
    {
        $previousPost = $GLOBALS['post'] ?? null;
        $postSet = RestAPI::setupPreviewPost($postId);

        try {
            return $render();
        } finally {
            if ($postSet) {
                $GLOBALS['post'] = $previousPost;
                if ($previousPost instanceof \WP_Post) {
                    setup_postdata($previousPost);
                } elseif (function_exists('wp_reset_postdata')) {
                    wp_reset_postdata();
                }
            }
        }
    }

    /**
     * Sanitize attributes posted to a preview endpoint.
     *
     * Shared by the admin-ajax preview and the REST /preview route so the two
     * twins apply exactly the same rules: every string is run through
     * wp_kses_post() regardless of capability (the editor path never needs
     * raw markup), and users who lack `unfiltered_html` additionally get the
     * type-keyed AttributeSanitizer pass that save and render apply.
     *
     * @param array $attributes Decoded attributes from the request.
     * @param array $metadata   The block's schema (block.json), used for its protoBlocks config.
     */
    public static function sanitizePreviewAttributes(array $attributes, array $metadata = []): array
    {
        $attributes = self::ksesAttributes($attributes);

        if (AttributeSanitizer::shouldSanitizeForUser((int) get_current_user_id())) {
            $protoConfig = $metadata['protoBlocks'] ?? [];
            $attributes  = AttributeSanitizer::sanitize($attributes, is_array($protoConfig) ? $protoConfig : []);
        }

        return $attributes;
    }

    /**
     * Recursively sanitize attributes
     * Note: We preserve the original key names (including camelCase) because
     * WordPress block attributes commonly use camelCase naming convention
     */
    private static function ksesAttributes(array $attributes): array
    {
        $sanitized = [];

        foreach ($attributes as $key => $value) {
            // Handle numeric keys (from arrays/repeaters) - keep as-is
            if (is_int($key)) {
                $sanitizedKey = $key;
            } else {
                // Only allow valid attribute name characters (alphanumeric, underscore, dash)
                // but preserve case (don't use sanitize_key which lowercases)
                $sanitizedKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $key);
            }

            if (is_array($value)) {
                $sanitized[$sanitizedKey] = self::ksesAttributes($value);
            } elseif (is_string($value)) {
                // Allow HTML for content fields
                $sanitized[$sanitizedKey] = wp_kses_post($value);
            } elseif (is_bool($value)) {
                $sanitized[$sanitizedKey] = $value;
            } elseif (is_numeric($value)) {
                $sanitized[$sanitizedKey] = $value;
            } else {
                $sanitized[$sanitizedKey] = sanitize_text_field((string) $value);
            }
        }

        return $sanitized;
    }
}
