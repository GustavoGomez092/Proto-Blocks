<?php
/**
 * Block Category - Registers the Proto-Blocks category
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Blocks;

/**
 * Registers the Proto-Blocks block category
 */
class Category
{
    /**
     * Category slug
     */
    public const SLUG = 'proto-blocks';

    /**
     * Register the block category
     */
    public function register(): void
    {
        // Use priority 1 to ensure it runs before other plugins
        add_filter('block_categories_all', [$this, 'addCategory'], 1, 2);
    }

    /**
     * Add the Proto-Blocks category
     *
     * @param array $categories Existing categories
     * @param \WP_Block_Editor_Context $context Editor context
     * @return array Modified categories
     */
    public function addCategory(array $categories, $context): array
    {
        /**
         * Filter the Proto-Blocks category title
         *
         * @param string $title Category title
         */
        $title = apply_filters('proto_blocks_category_title', __('Proto Blocks', 'proto-blocks'));

        /**
         * Filter the Proto-Blocks category icon
         *
         * @param string $icon Category icon (dashicon name without 'dashicons-' prefix)
         */
        $icon = apply_filters('proto_blocks_category_icon', 'layout');

        /**
         * Filter the Proto-Blocks category slug
         *
         * @param string $slug Category slug
         */
        $slug = apply_filters('proto_blocks_category_slug', self::SLUG);

        // Add Proto-Blocks category at the beginning for top priority
        array_unshift($categories, [
            'slug' => $slug,
            'title' => $title,
            'icon' => $icon,
        ]);

        return $categories;
    }

    /**
     * Get the category slug (for external use)
     */
    public static function getSlug(): string
    {
        return apply_filters('proto_blocks_category_slug', self::SLUG);
    }
}
