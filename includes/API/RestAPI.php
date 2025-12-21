<?php
/**
 * REST API - Handles REST endpoints for Proto-Blocks
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\API;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;
use ProtoBlocks\Template\Engine;
use ProtoBlocks\Template\Cache;
use ProtoBlocks\Blocks\Registrar;

/**
 * REST API endpoints for Proto-Blocks
 */
class RestAPI
{
    /**
     * API namespace
     */
    public const NAMESPACE = 'proto-blocks/v1';

    /**
     * Template engine
     */
    private Engine $engine;

    /**
     * Block registrar
     */
    private Registrar $registrar;

    /**
     * Cache
     */
    private Cache $cache;

    /**
     * Constructor
     */
    public function __construct(Engine $engine, Registrar $registrar, Cache $cache)
    {
        $this->engine = $engine;
        $this->registrar = $registrar;
        $this->cache = $cache;
    }

    /**
     * Register REST routes
     */
    public function registerRoutes(): void
    {
        // Get all blocks
        register_rest_route(self::NAMESPACE, '/blocks', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getBlocks'],
            'permission_callback' => [$this, 'canEditPosts'],
        ]);

        // Generate preview
        register_rest_route(self::NAMESPACE, '/preview', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'generatePreview'],
            'permission_callback' => [$this, 'canEditPosts'],
            'args' => [
                'template' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'attributes' => [
                    'required' => true,
                    'type' => 'object',
                ],
            ],
        ]);

        // Get block settings
        register_rest_route(self::NAMESPACE, '/blocks/(?P<name>[a-zA-Z0-9-]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getBlockSettings'],
            'permission_callback' => [$this, 'canEditPosts'],
            'args' => [
                'name' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Cache management
        register_rest_route(self::NAMESPACE, '/cache', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'getCacheStats'],
                'permission_callback' => [$this, 'canManageOptions'],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'clearCache'],
                'permission_callback' => [$this, 'canManageOptions'],
            ],
        ]);
    }

    /**
     * Get all blocks
     */
    public function getBlocks(WP_REST_Request $request): WP_REST_Response
    {
        $blocks = $this->registrar->getBlocksData();

        return new WP_REST_Response($blocks, 200);
    }

    /**
     * Generate preview HTML
     */
    public function generatePreview(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $templateName = $request->get_param('template');
        $attributes = $request->get_param('attributes');

        // Find the block
        $registeredBlocks = $this->registrar->getRegisteredBlocks();

        if (!isset($registeredBlocks[$templateName])) {
            return new WP_Error(
                'proto_blocks_not_found',
                __('Block template not found.', 'proto-blocks'),
                ['status' => 404]
            );
        }

        $block = $registeredBlocks[$templateName];
        $templatePath = $block['schema']['protoBlocks']['templatePath'] ??
                       $block['path'] . '/' . $templateName . '.php';

        if (!file_exists($templatePath)) {
            return new WP_Error(
                'proto_blocks_template_not_found',
                __('Template file not found.', 'proto-blocks'),
                ['status' => 404]
            );
        }

        try {
            $html = $this->engine->renderPreview($templatePath, $attributes, $block['schema']);

            return new WP_REST_Response([
                'html' => $html,
            ], 200);
        } catch (\Throwable $e) {
            return new WP_Error(
                'proto_blocks_preview_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get block settings
     */
    public function getBlockSettings(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $name = $request->get_param('name');
        $registeredBlocks = $this->registrar->getRegisteredBlocks();

        if (!isset($registeredBlocks[$name])) {
            return new WP_Error(
                'proto_blocks_not_found',
                __('Block not found.', 'proto-blocks'),
                ['status' => 404]
            );
        }

        $block = $registeredBlocks[$name];

        return new WP_REST_Response([
            'name' => $name,
            'path' => $block['path'],
            'schema' => $block['schema'],
            'attributes' => $block['attributes'],
        ], 200);
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(WP_REST_Request $request): WP_REST_Response
    {
        $stats = $this->cache->getStats();

        return new WP_REST_Response($stats, 200);
    }

    /**
     * Clear the cache
     */
    public function clearCache(WP_REST_Request $request): WP_REST_Response
    {
        $count = $this->cache->clear();

        return new WP_REST_Response([
            'success' => true,
            'cleared' => $count,
            'message' => sprintf(
                _n(
                    '%d cached template cleared.',
                    '%d cached templates cleared.',
                    $count,
                    'proto-blocks'
                ),
                $count
            ),
        ], 200);
    }

    /**
     * Check if user can edit posts
     */
    public function canEditPosts(): bool
    {
        return current_user_can('edit_posts');
    }

    /**
     * Check if user can manage options
     */
    public function canManageOptions(): bool
    {
        return current_user_can('manage_options');
    }
}
