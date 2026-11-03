<?php
/**
 * AdminApp - mount point and bootstrap data for the React admin
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Admin;

use InvalidArgumentException;
use ProtoBlocks\Tailwind\Manager as TailwindManager;

/**
 * Prints the React admin's mount and portal nodes and hands the app its
 * bootstrap data (window.protoBlocksAdmin). The app itself is
 * assets/js/admin.js, enqueued by Assets::enqueueAdminAssets().
 */
final class AdminApp
{
    public const MOUNT_ID = 'proto-blocks-admin';
    public const PORTAL_ID = 'proto-blocks-admin-portal';
    public const SCREENS = ['dashboard', 'tailwind', 'system', 'previews', 'wizard'];

    /** Script handle of the built admin app. */
    public const SCRIPT_HANDLE = 'proto-blocks-admin';

    /** Matches Tailwind\AdminSettings' AJAX action prefix and nonce action. */
    private const TAILWIND_ACTION_PREFIX = 'proto_blocks_tailwind_';
    private const TAILWIND_NONCE_ACTION = 'proto_blocks_tailwind_nonce';

    /** Keeps the JSON safe inside a <script> element. */
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    private ?TailwindManager $tailwind;

    public function __construct(?TailwindManager $tailwind)
    {
        $this->tailwind = $tailwind;
    }

    /**
     * Data the app reads from window.protoBlocksAdmin.
     *
     * @return array<string,mixed>
     * @throws InvalidArgumentException When $screen is not one of SCREENS.
     */
    public function bootstrapData(string $screen): array
    {
        if (!in_array($screen, self::SCREENS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown admin screen "%s".', $screen));
        }

        $tailwind = null;
        if ($this->tailwind !== null) {
            $tailwind = [
                'ajaxUrl' => esc_url_raw(admin_url('admin-ajax.php')),
                'actionPrefix' => self::TAILWIND_ACTION_PREFIX,
                'nonce' => wp_create_nonce(self::TAILWIND_NONCE_ACTION),
            ];
        }

        $pluginUrl = defined('PROTO_BLOCKS_URL') ? (string) \PROTO_BLOCKS_URL : '';

        return [
            'screen' => $screen,
            'restRoot' => esc_url_raw(rest_url('proto-blocks/v1/')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'adminUrl' => esc_url_raw(admin_url('admin.php')),
            'version' => defined('PROTO_BLOCKS_VERSION') ? (string) \PROTO_BLOCKS_VERSION : '',
            'fileModsAllowed' => AdminPage::fileModsAllowed(),
            // Same base the Tailwind browser compiler uses to fetch its wasm.
            'assetBase' => esc_url_raw($pluginUrl . 'assets/js/'),
            'tailwind' => $tailwind,
        ];
    }

    /**
     * Print the mount and portal nodes and the bootstrap data. Prints
     * nothing for users without manage_options.
     *
     * @throws InvalidArgumentException When $screen is not one of SCREENS.
     */
    public function renderScreen(string $screen): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $data = $this->bootstrapData($screen);
        $script = 'window.protoBlocksAdmin = ' . wp_json_encode($data, self::JSON_FLAGS) . ';';

        // wp-header-end tells core where to move admin notices, so they land
        // before the mount node and never inside the React tree.
        printf(
            '<hr class="wp-header-end" /><div id="%1$s" data-screen="%2$s"></div><div id="%3$s"></div>',
            esc_attr(self::MOUNT_ID),
            esc_attr($screen),
            esc_attr(self::PORTAL_ID)
        );

        // The admin script prints in the footer, after this, so 'before'
        // still runs ahead of it. Without the handle (tests, or a screen
        // rendered outside enqueueAdminAssets) print the data directly.
        if (wp_script_is(self::SCRIPT_HANDLE, 'enqueued')) {
            wp_add_inline_script(self::SCRIPT_HANDLE, $script, 'before');
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with JSON_HEX_* flags.
        echo '<script>' . $script . '</script>';
    }
}
