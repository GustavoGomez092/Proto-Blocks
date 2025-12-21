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

        // Sanitize attributes
        if (is_array($attributes)) {
            $attributes = $this->sanitizeAttributes($attributes);
        } else {
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

        try {
            $html = $this->engine->renderPreview($templatePath, $attributes, $metadata);
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
     * Recursively sanitize attributes
     * Note: We preserve the original key names (including camelCase) because
     * WordPress block attributes commonly use camelCase naming convention
     */
    private function sanitizeAttributes(array $attributes): array
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
                $sanitized[$sanitizedKey] = $this->sanitizeAttributes($value);
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
