<?php
/**
 * DashboardRoutes - REST routes for the Blocks screen
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\API\Admin;

use ProtoBlocks\Admin\AdminPage;
use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\Core\Plugin;
use ProtoBlocks\Tailwind\Manager as TailwindManager;
use WP_Error;
use WP_REST_Request;

/**
 * GET admin/dashboard, POST admin/example-blocks (writes an option only) and
 * POST admin/demos/install|remove (write theme files, so they sit behind
 * the write permission). The demos routes are not exposed in the UI since 2.15.
 */
final class DashboardRoutes
{
    private ?AdminPage $page;

    /**
     * Plugin only constructs AdminPage under is_admin(), which a REST request
     * is not, so it is built here when none is injected.
     */
    public function __construct(?AdminPage $page = null)
    {
        $this->page = $page;
    }

    public function registerRoutes(string $namespace, callable $permission, callable $writePermission): void
    {
        $base = '/' . AdminRestController::BASE;

        register_rest_route($namespace, $base . '/dashboard', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get'],
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

        register_rest_route($namespace, $base . '/example-blocks', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'setExampleBlocks'],
                'permission_callback' => $permission,
                'args' => [
                    'enabled' => [
                        'type' => 'boolean',
                        'required' => true,
                    ],
                ],
            ],
        ]);

        register_rest_route($namespace, $base . '/demos/install', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'install'],
                'permission_callback' => $writePermission,
                'args' => [
                    'type' => [
                        'type' => 'string',
                        'required' => true,
                    ],
                ],
            ],
        ]);

        register_rest_route($namespace, $base . '/demos/remove', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'remove'],
                'permission_callback' => $writePermission,
            ],
        ]);
    }

    /** @return array<string,mixed> */
    public function get(): array
    {
        return $this->page()->dashboardData();
    }

    /** @return array<string,mixed>|WP_Error */
    public function setExampleBlocks(WP_REST_Request $request)
    {
        if (AdminRestController::exampleBlocksState(
            defined('PROTO_BLOCKS_EXAMPLE_BLOCKS_FROM_CONFIG') && \PROTO_BLOCKS_EXAMPLE_BLOCKS_FROM_CONFIG,
            defined('PROTO_BLOCKS_EXAMPLE_BLOCKS') && \PROTO_BLOCKS_EXAMPLE_BLOCKS,
            null
        )['locked']) {
            return new WP_Error(
                'proto_blocks_locked',
                __('Example blocks are managed from the configuration constant.', 'proto-blocks'),
                ['status' => 409]
            );
        }

        update_option(AdminPage::OPTION_EXAMPLE_BLOCKS, (bool) $request->get_param('enabled'));
        // Block registration depends on this value; clear cached templates so the change takes effect.
        Plugin::getInstance()->getCache()->clear();

        // Rescan before building the response, or it lists the old blocks.
        $page = $this->page();
        $page->refreshDiscovery();

        return $page->dashboardData();
    }

    /**
     * Clears the compiled template cache.
     *
     * @return array<string,mixed>
     */
    public function clearCache(): array
    {
        $cleared = Plugin::getInstance()->getCache()->clear();

        return [
            'cleared' => $cleared,
            'data' => $this->page()->dashboardData(),
        ];
    }

    /**
     * The installed count and skipped names, plus the refreshed screen data
     * under `data`.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function install(WP_REST_Request $request)
    {
        if (!AdminPage::fileModsAllowed()) {
            return AdminRestController::fileModsError();
        }

        $type = (string) $request->get_param('type');
        $invalid = AdminRestController::validateDemoType($type, TailwindManager::getInstance()->isEnabled());
        if ($invalid instanceof WP_Error) {
            return $invalid;
        }

        $page = $this->page();
        $result = $page->installDemoBlocks($type);
        if ($result instanceof WP_Error) {
            return self::withStatus($result);
        }

        return [
            'installed' => $result,
            'skipped' => $page->getSkippedDemoBlocks(),
            'data' => $page->dashboardData(),
        ];
    }

    /**
     * The removed count and kept names, plus the refreshed screen data under
     * `data`.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function remove()
    {
        if (!AdminPage::fileModsAllowed()) {
            return AdminRestController::fileModsError();
        }

        $page = $this->page();
        $result = $page->removeDemoBlocks();
        if ($result instanceof WP_Error) {
            return self::withStatus($result);
        }

        return [
            'removed' => $result,
            'skipped' => $page->getSkippedDemoBlocks(),
            'data' => $page->dashboardData(),
        ];
    }

    /** The error from AdminPage with a proto_blocks_ code and HTTP status 400. */
    private static function withStatus(WP_Error $error): WP_Error
    {
        $code = (string) $error->get_error_code();
        if (strpos($code, 'proto_blocks_') !== 0) {
            $code = 'proto_blocks_' . $code;
        }

        return new WP_Error($code, $error->get_error_message(), ['status' => 400]);
    }

    private function page(): AdminPage
    {
        return $this->page ??= new AdminPage(Plugin::getInstance()->getCache());
    }
}
