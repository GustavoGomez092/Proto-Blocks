<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * Slug derivation and validation for declared post types, taxonomies and
 * options pages.
 */
final class Slug
{
    public const TYPE_MAX = 20;
    public const TAXONOMY_MAX = 32;
    public const OVERRIDABLE_TYPES = ['post', 'page', 'attachment'];
    public const OVERRIDABLE_TAXONOMIES = ['category', 'post_tag'];
    public const OPTIONS_MAX = 64;
    /** Admin page slugs an options page may not take (core screens, this plugin, WooCommerce). */
    public const OPTIONS_RESERVED = ['proto-blocks', 'index', 'general', 'writing', 'reading', 'discussion', 'media', 'permalink', 'privacy', 'options', 'options-general', 'themes', 'plugins', 'users', 'tools', 'profile', 'upload', 'menus', 'widgets', 'customize', 'woocommerce'];

    private const RESERVED = [
        // WordPress reserved terms (codex list).
        'attachment', 'attachment_id', 'author', 'author_name', 'calendar', 'cat', 'category',
        'category__and', 'category__in', 'category__not_in', 'category_name', 'comments_per_page',
        'comments_popup', 'custom', 'customize_messenger_channel', 'customized', 'cpage', 'day', 'debug',
        'embed', 'error', 'exact', 'feed', 'fields', 'hour', 'link_category', 'm', 'minute', 'monthnum',
        'more', 'name', 'nav_menu', 'nonce', 'nopaging', 'offset', 'order', 'orderby', 'p', 'page',
        'page_id', 'paged', 'pagename', 'pb', 'perm', 'post', 'post__in', 'post__not_in', 'post_format',
        'post_mime_type', 'post_status', 'post_tag', 'post_type', 'posts', 'posts_per_archive_page',
        'posts_per_page', 'preview', 'robots', 's', 'search', 'second', 'sentence', 'showposts', 'static',
        'subpost', 'subpost_id', 'tag', 'tag__and', 'tag__in', 'tag__not_in', 'tag_id', 'tag_slug__and',
        'tag_slug__in', 'taxonomy', 'tb', 'term', 'terms', 'theme', 'themes', 'title', 'type', 'types',
        'w', 'withcomments', 'withoutcomments', 'year',
        // Core internal post types.
        'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request',
        'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
        'wp_font_family', 'wp_font_face', 'action',
        // Core internal taxonomies.
        'wp_theme', 'wp_template_part_area', 'wp_pattern_category',
    ];

    public static function fromName(string $name, int $max): string
    {
        $slug = substr(sanitize_title($name), 0, max(0, $max));
        return rtrim($slug, '-');
    }

    /** Longest slug for $kind. */
    public static function max(string $kind): int
    {
        return match ($kind) {
            'type' => self::TYPE_MAX,
            'options' => self::OPTIONS_MAX,
            default => self::TAXONOMY_MAX,
        };
    }

    /**
     * @param string $kind 'type', 'taxonomy' or 'options'
     * @return string|null null when valid, else 'pattern', 'length' or 'reserved'
     */
    public static function error(string $slug, string $kind): ?string
    {
        if ($kind === 'options') {
            return self::optionsError($slug);
        }

        if (!preg_match('/^[a-z][a-z0-9_-]*$/D', $slug)) {
            return 'pattern';
        }

        $isType = $kind === 'type';
        if (strlen($slug) > ($isType ? self::TYPE_MAX : self::TAXONOMY_MAX)) {
            return 'length';
        }

        $overridable = $isType ? self::OVERRIDABLE_TYPES : self::OVERRIDABLE_TAXONOMIES;
        if (in_array($slug, $overridable, true)) {
            return null;
        }

        return in_array($slug, self::RESERVED, true) ? 'reserved' : null;
    }

    /**
     * Options page slugs are admin page slugs: lowercase letters, digits and
     * hyphens, never a core screen or one of this plugin's own pages.
     */
    private static function optionsError(string $slug): ?string
    {
        if (!preg_match('/^[a-z][a-z0-9-]*$/D', $slug)) {
            return 'pattern';
        }
        if (strlen($slug) > self::OPTIONS_MAX) {
            return 'length';
        }
        if (in_array($slug, self::OPTIONS_RESERVED, true) || str_starts_with($slug, 'proto-blocks-')) {
            return 'reserved';
        }

        return null;
    }
}
