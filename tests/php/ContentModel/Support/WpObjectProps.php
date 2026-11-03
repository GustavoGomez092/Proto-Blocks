<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel\Support;

/**
 * Test helper (does not ship): the properties WordPress would derive for a
 * registered post type or taxonomy from its args, following
 * WP_Post_Type::set_props() and WP_Taxonomy::set_props(). Two args arrays
 * that produce the same result here register the same object. Labels are
 * dropped (compared separately) and `supports` is sorted (order is not
 * meaningful to WordPress).
 */
final class WpObjectProps
{
    private const EP_PERMALINK = 1;
    private const EP_NONE = 0;

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function postType(array $args): array
    {
        $props = $args + [
            'description'         => '',
            'public'              => false,
            'hierarchical'        => false,
            'exclude_from_search' => null,
            'publicly_queryable'  => null,
            'show_ui'             => null,
            'show_in_menu'        => null,
            'show_in_nav_menus'   => null,
            'show_in_admin_bar'   => null,
            'menu_position'       => null,
            'menu_icon'           => null,
            'capability_type'     => 'post',
            'capabilities'        => [],
            'map_meta_cap'        => null,
            'supports'            => [],
            'taxonomies'          => [],
            'has_archive'         => false,
            'rewrite'             => true,
            'query_var'           => true,
            'can_export'          => true,
            'delete_with_user'    => null,
            'show_in_rest'        => false,
            'template'            => [],
            'template_lock'       => false,
        ];

        $props['publicly_queryable'] ??= $props['public'];
        $props['show_ui'] ??= $props['public'];
        $props['show_in_menu'] ??= $props['show_ui'];
        $props['show_in_admin_bar'] ??= (bool) $props['show_in_menu'];
        $props['show_in_nav_menus'] ??= $props['public'];
        $props['exclude_from_search'] ??= !$props['public'];
        if ($props['map_meta_cap'] === null && in_array($props['capability_type'], ['post', 'page'], true)) {
            $props['map_meta_cap'] = true;
        }

        if ($props['rewrite'] !== false) {
            $rewrite = is_array($props['rewrite']) ? $props['rewrite'] : [];
            $props['rewrite'] = $rewrite + [
                'with_front' => true,
                'pages'      => true,
                'feeds'      => (bool) $props['has_archive'],
                'ep_mask'    => self::EP_PERMALINK,
            ];
            ksort($props['rewrite']);
        }

        return self::finish($props);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function taxonomy(array $args): array
    {
        $props = $args + [
            'description'        => '',
            'public'             => true,
            'publicly_queryable' => null,
            'hierarchical'       => false,
            'show_ui'            => null,
            'show_in_menu'       => null,
            'show_in_nav_menus'  => null,
            'show_tagcloud'      => null,
            'show_in_quick_edit' => null,
            'show_admin_column'  => false,
            'capabilities'       => [],
            'rewrite'            => true,
            'query_var'          => true,
            'show_in_rest'       => false,
        ];

        $props['publicly_queryable'] ??= $props['public'];
        $props['show_ui'] ??= $props['public'];
        $props['show_in_menu'] ??= $props['show_ui'];
        $props['show_in_nav_menus'] ??= $props['public'];
        $props['show_tagcloud'] ??= $props['show_ui'];
        $props['show_in_quick_edit'] ??= $props['show_ui'];

        if ($props['rewrite'] !== false) {
            $rewrite = is_array($props['rewrite']) ? $props['rewrite'] : [];
            $props['rewrite'] = $rewrite + [
                'with_front'   => true,
                'hierarchical' => false,
                'ep_mask'      => self::EP_NONE,
            ];
            ksort($props['rewrite']);
        }

        return self::finish($props);
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private static function finish(array $props): array
    {
        unset($props['labels']);
        if (is_array($props['supports'] ?? null)) {
            sort($props['supports']);
        }
        ksort($props);

        return $props;
    }
}
