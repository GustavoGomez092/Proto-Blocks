<?php
/**
 * AdminRestController - REST routes behind the React admin
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\API;

use ProtoBlocks\Admin\AdminPage;
use ProtoBlocks\API\Admin\DashboardRoutes;
use ProtoBlocks\API\Admin\PreviewsRoutes;
use ProtoBlocks\API\Admin\SystemRoutes;
use ProtoBlocks\API\Admin\WizardRoutes;
use WP_Error;

/**
 * Registers the admin/* routes of the proto-blocks/v1 namespace. Each
 * screen's routes live in their own class under includes/API/Admin, listed
 * in ROUTE_GROUPS; every route is gated on manage_options, and routes that
 * write files additionally on AdminPage::fileModsAllowed() and, on
 * multisite, on the user being a super admin (canWriteCode()).
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
        WizardRoutes::class,
        DashboardRoutes::class,
        Admin\TailwindRoutes::class,
        PreviewsRoutes::class,
        Admin\PostTypesRoutes::class,
        Admin\TaxonomiesRoutes::class,
        Admin\SchemaFilesRoutes::class,
        Admin\OptionsPagesRoutes::class,
        Admin\FieldsRoutes::class,
        Admin\RedirectsRoutes::class,
        Admin\ScriptsRoutes::class,
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

    /**
     * Routes that write files: also blocked by DISALLOW_FILE_MODS /
     * DISALLOW_FILE_EDIT, and on multisite limited to super admins. A
     * missing capability answers false (core's rest_forbidden); disabled
     * file mods and the multisite refusal answer the proto_blocks_file_mods /
     * proto_blocks_multisite errors, which WordPress passes through to the
     * client as-is.
     */
    public function writePermission(): bool|WP_Error
    {
        if (!$this->permission()) {
            return false;
        }
        return self::writeReason() === null ? true : self::writeBlockedError();
    }

    /**
     * Why file writes are off for this request: 'file_mods' (DISALLOW_FILE_MODS
     * / DISALLOW_FILE_EDIT), 'multisite' (not a network administrator), or
     * null when allowed. File mods win when both apply.
     *
     * @return 'file_mods'|'multisite'|null
     */
    public static function writeReason(): ?string
    {
        if (!AdminPage::fileModsAllowed()) {
            return 'file_mods';
        }
        return self::canWriteCode() ? null : 'multisite';
    }

    /** The error for a refused file write: proto_blocks_multisite or proto_blocks_file_mods by writeReason(). */
    public static function writeBlockedError(): WP_Error
    {
        if (self::writeReason() === 'multisite') {
            return new WP_Error(
                'proto_blocks_multisite',
                __('Only network administrators can write theme files on a multisite network.', 'proto-blocks'),
                ['status' => 403]
            );
        }

        return self::fileModsError();
    }

    /**
     * Whether the current user may write code files (theme declarations,
     * templates). On a single site manage_options is enough (checked by the
     * caller); on multisite a sub-site Administrator has manage_options but
     * the theme is shared by the network, so only a super admin may.
     */
    public static function canWriteCode(): bool
    {
        return !is_multisite() || is_super_admin();
    }

    /**
     * File writes allowed for this request: file mods are on and the user
     * may write code (canWriteCode()).
     */
    public static function fileWritesAllowed(): bool
    {
        return AdminPage::fileModsAllowed() && self::canWriteCode();
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

    /**
     * The Example Blocks switch: locked when wp-config.php defines it, in
     * which case the constant wins over the stored option.
     *
     * @param mixed $optionValue Stored option value.
     * @return array{enabled: bool, locked: bool}
     */
    public static function exampleBlocksState(bool $fromConfig, bool $constantValue, mixed $optionValue): array
    {
        return [
            'enabled' => $fromConfig ? $constantValue : (bool) $optionValue,
            'locked' => $fromConfig,
        ];
    }

    /**
     * Null when $type is a demo set that can be installed now, otherwise the
     * error to return.
     */
    public static function validateDemoType(string $type, bool $tailwindEnabled): ?WP_Error
    {
        if ($type !== 'vanilla' && $type !== 'tailwind') {
            return new WP_Error(
                'proto_blocks_invalid_type',
                __('Unknown demo block type.', 'proto-blocks'),
                ['status' => 400]
            );
        }

        if ($type === 'tailwind' && !$tailwindEnabled) {
            return new WP_Error(
                'proto_blocks_tailwind_disabled',
                __('Turn on Tailwind CSS before installing the Tailwind demo blocks.', 'proto-blocks'),
                ['status' => 409]
            );
        }

        return null;
    }
}
