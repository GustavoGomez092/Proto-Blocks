<?php
/**
 * ContentModelMenu - Post Types and Taxonomies submenus
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Admin;

/**
 * Adds the Post Types and Taxonomies screens under the Proto-Blocks menu.
 * Both are React screens; they exist even when a module is disabled (the
 * screen shows the disabled state from the list route's moduleEnabled).
 * Hooked to `admin_menu` priority 11, after AdminPage adds the parent menu.
 */
final class ContentModelMenu
{
    public const CAPABILITY = 'manage_options';
    public const POST_TYPES_SLUG = 'proto-blocks-post-types';
    public const TAXONOMIES_SLUG = 'proto-blocks-taxonomies';

    public function __construct(private readonly ?AdminApp $app)
    {
    }

    public function register(): void
    {
        $pages = [
            [self::POST_TYPES_SLUG, __('Post Types', 'proto-blocks'), 'post-types'],
            [self::TAXONOMIES_SLUG, __('Taxonomies', 'proto-blocks'), 'taxonomies'],
            ['proto-blocks-options-pages', __('Options Pages', 'proto-blocks'), 'options-pages'],
        ];

        foreach ($pages as [$slug, $title, $screen]) {
            $hook = add_submenu_page(
                AdminPage::SLUG,
                $title,
                $title,
                self::CAPABILITY,
                $slug,
                function () use ($screen): void {
                    $this->app?->renderScreen($screen);
                }
            );
            if (is_string($hook)) {
                $this->app?->addScreenHook($hook, $screen);
            }
        }
    }
}
