<?php
/**
 * AdminRestController - REST routes behind the React admin
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\API;

use ProtoBlocks\Admin\AdminPage;
use ProtoBlocks\API\Admin\SystemRoutes;
use WP_Error;

/**
 * Registers the admin/* routes of the proto-blocks/v1 namespace. Each
 * screen's routes live in their own class under includes/API/Admin, listed
 * in ROUTE_GROUPS; every route is gated on manage_options, and routes that
 * write files additionally on AdminPage::fileModsAllowed().
 */
final class AdminRestController
{
    /** Route prefix under RestAPI::NAMESPACE. */
    public const BASE = 'admin';

    /**
     * One class per screen. Each has a constructor whose parameters are all
     * optional (it resolves its services from Plugin when they are omitted)
     * and registerRoutes(string $namespace, callable $permission,
     * callable $writePermission): void.
     *
     * @var list<class-string>
     */
    public const ROUTE_GROUPS = [
        SystemRoutes::class,
    ];

    /**
     * Route group instances; tests may inject them, otherwise ROUTE_GROUPS
     * is instantiated on registerRoutes().
     *
     * @var list<object>|null
     */
    private ?array $groups;

    /**
     * @param list<object>|null $groups Route groups to register instead of ROUTE_GROUPS.
     */
    public function __construct(?array $groups = null)
    {
        $this->groups = $groups;
    }

    public function registerRoutes(): void
    {
        $groups = $this->groups ?? array_map(static fn(string $class): object => new $class(), self::ROUTE_GROUPS);

        foreach ($groups as $group) {
            $group->registerRoutes(RestAPI::NAMESPACE, [$this, 'permission'], [$this, 'writePermission']);
        }
    }

    /** Every admin route: the user must be able to manage options. */
    public function permission(): bool
    {
        return current_user_can('manage_options');
    }

    /** Routes that write files: also blocked by DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT. */
    public function writePermission(): bool
    {
        return $this->permission() && AdminPage::fileModsAllowed();
    }

    /**
     * $absolute relative to $root (e.g. ABSPATH) for display; unchanged when
     * it is not inside $root.
     */
    public static function relativePath(string $absolute, string $root): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $absolute);

        if ($root === '' || strpos($path, $root . '/') !== 0) {
            return $absolute;
        }

        return substr($path, strlen($root) + 1);
    }

    /** The error a file-writing route returns when file modifications are disabled. */
    public static function fileModsError(): WP_Error
    {
        return new WP_Error(
            'proto_blocks_file_mods',
            __('File modifications are disabled on this site.', 'proto-blocks'),
            ['status' => 403]
        );
    }
}
