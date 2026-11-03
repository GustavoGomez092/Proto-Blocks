<?php
/**
 * SystemRoutes - REST routes for the System Status screen
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\API\Admin;

use ProtoBlocks\Admin\AdminPage;
use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\Core\Plugin;
use ProtoBlocks\Template\Cache;
use WP_Error;
use WP_REST_Request;

/**
 * GET/POST admin/system: the block category name, the wp-config constants
 * and server details. Writes an option only, never files.
 */
final class SystemRoutes
{
    /** Longest category name accepted, in characters (matches the form). */
    public const CATEGORY_NAME_MAX = 100;

    private ?Cache $cache;

    public function __construct(?Cache $cache = null)
    {
        $this->cache = $cache;
    }

    public function registerRoutes(string $namespace, callable $permission, callable $writePermission): void
    {
        register_rest_route($namespace, '/' . AdminRestController::BASE . '/system', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get'],
                'permission_callback' => $permission,
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'update'],
                'permission_callback' => $permission,
                'args' => [
                    'categoryName' => [
                        'type' => 'string',
                        'required' => true,
                        'validate_callback' => [self::class, 'validateCategoryName'],
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);
    }

    /** @return array<string,mixed> */
    public function get(): array
    {
        return $this->payload();
    }

    /** @return array<string,mixed>|WP_Error */
    public function update(WP_REST_Request $request)
    {
        $name = $request->get_param('categoryName');
        $valid = self::validateCategoryName($name);
        if ($valid instanceof WP_Error) {
            return $valid;
        }

        update_option(AdminPage::OPTION_CATEGORY_NAME, sanitize_text_field((string) $name));

        return $this->payload();
    }

    /**
     * validate_callback for categoryName: a string of at most
     * CATEGORY_NAME_MAX characters. Extra arguments from WordPress
     * (request, param name) are ignored.
     *
     * @param mixed $value
     * @return true|WP_Error
     */
    public static function validateCategoryName($value, ...$unused)
    {
        if (!is_string($value)) {
            return new WP_Error(
                'proto_blocks_invalid_category_name',
                __('The category name must be text.', 'proto-blocks'),
                ['status' => 400]
            );
        }

        if (mb_strlen($value) > self::CATEGORY_NAME_MAX) {
            return new WP_Error(
                'proto_blocks_invalid_category_name',
                __('Use 100 characters or fewer.', 'proto-blocks'),
                ['status' => 400]
            );
        }

        return true;
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        $cacheDir = $this->cache()->getCacheDir();

        return [
            'categoryName' => (string) get_option(AdminPage::OPTION_CATEGORY_NAME, ''),
            'config' => [
                'debug' => defined('PROTO_BLOCKS_DEBUG') && (bool) \PROTO_BLOCKS_DEBUG,
                'cacheEnabled' => defined('PROTO_BLOCKS_CACHE_ENABLED') && (bool) \PROTO_BLOCKS_CACHE_ENABLED,
                'exampleBlocks' => defined('PROTO_BLOCKS_EXAMPLE_BLOCKS') && (bool) \PROTO_BLOCKS_EXAMPLE_BLOCKS,
                'exampleBlocksFromConfig' => defined('PROTO_BLOCKS_EXAMPLE_BLOCKS_FROM_CONFIG') && (bool) \PROTO_BLOCKS_EXAMPLE_BLOCKS_FROM_CONFIG,
            ],
            'system' => [
                'pluginVersion' => defined('PROTO_BLOCKS_VERSION') ? (string) \PROTO_BLOCKS_VERSION : '',
                'wpVersion' => (string) get_bloginfo('version'),
                'phpVersion' => PHP_VERSION,
                'cacheDir' => AdminRestController::relativePath($cacheDir, defined('ABSPATH') ? (string) \ABSPATH : ''),
                'cacheDirWritable' => wp_is_writable($cacheDir),
            ],
        ];
    }

    private function cache(): Cache
    {
        return $this->cache ??= Plugin::getInstance()->getCache();
    }
}
