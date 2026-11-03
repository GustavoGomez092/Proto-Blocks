<?php
/**
 * FieldsMenu - Fields submenu
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Admin;

/**
 * Adds the Fields screen (field groups and the visual builder) under the
 * Proto-Blocks menu. It exists even when the Fields module is turned off:
 * the screen then shows the disabled state from admin/fields' moduleEnabled.
 * Hooked to `admin_menu` priority 11, after AdminPage adds the parent menu.
 */
final class FieldsMenu
{
    public const CAPABILITY = 'manage_options';
    public const SLUG = 'proto-blocks-fields';
    public const SCREEN = 'fields';

    public function __construct(private readonly ?AdminApp $app = null)
    {
    }

    public function register(): void
    {
        $title = __('Fields', 'proto-blocks');
        $hook = add_submenu_page(
            AdminPage::SLUG,
            $title,
            $title,
            self::CAPABILITY,
            self::SLUG,
            function (): void {
                $this->app?->renderScreen(self::SCREEN);
            }
        );
        if (is_string($hook)) {
            $this->app?->addScreenHook($hook, self::SCREEN);
        }
    }
}
