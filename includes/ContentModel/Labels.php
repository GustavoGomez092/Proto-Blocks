<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * Generated label sets for post types and taxonomies.
 */
final class Labels
{
    /**
     * @return array<string,string>
     */
    public static function postType(string $singular, string $plural): array
    {
        $s = $singular;
        $p = $plural;
        $sl = self::lower($singular);
        $pl = self::lower($plural);

        return [
            'name'                  => $p,
            'singular_name'         => $s,
            'menu_name'             => $p,
            'name_admin_bar'        => $s,
            'add_new'               => __('Add New', 'proto-blocks'),
            /* translators: %s: singular name */
            'add_new_item'          => sprintf(__('Add New %s', 'proto-blocks'), $s),
            /* translators: %s: singular name */
            'new_item'              => sprintf(__('New %s', 'proto-blocks'), $s),
            /* translators: %s: singular name */
            'edit_item'             => sprintf(__('Edit %s', 'proto-blocks'), $s),
            /* translators: %s: singular name */
            'view_item'             => sprintf(__('View %s', 'proto-blocks'), $s),
            /* translators: %s: plural name */
            'view_items'            => sprintf(__('View %s', 'proto-blocks'), $p),
            /* translators: %s: plural name */
            'all_items'             => sprintf(__('All %s', 'proto-blocks'), $p),
            /* translators: %s: plural name */
            'search_items'          => sprintf(__('Search %s', 'proto-blocks'), $p),
            /* translators: %s: lowercase plural name */
            'not_found'             => sprintf(__('No %s found.', 'proto-blocks'), $pl),
            /* translators: %s: lowercase plural name */
            'not_found_in_trash'    => sprintf(__('No %s found in Trash.', 'proto-blocks'), $pl),
            /* translators: %s: singular name */
            'archives'              => sprintf(__('%s Archives', 'proto-blocks'), $s),
            /* translators: %s: singular name */
            'attributes'            => sprintf(__('%s Attributes', 'proto-blocks'), $s),
            /* translators: %s: singular name */
            'parent_item_colon'     => sprintf(__('Parent %s:', 'proto-blocks'), $s),
            'featured_image'        => __('Featured image', 'proto-blocks'),
            'set_featured_image'    => __('Set featured image', 'proto-blocks'),
            'remove_featured_image' => __('Remove featured image', 'proto-blocks'),
            'use_featured_image'    => __('Use as featured image', 'proto-blocks'),
            /* translators: %s: lowercase singular name */
            'insert_into_item'      => sprintf(__('Insert into %s', 'proto-blocks'), $sl),
            /* translators: %s: lowercase singular name */
            'uploaded_to_this_item' => sprintf(__('Uploaded to this %s', 'proto-blocks'), $sl),
            /* translators: %s: lowercase plural name */
            'filter_items_list'     => sprintf(__('Filter %s list', 'proto-blocks'), $pl),
            /* translators: %s: plural name */
            'items_list_navigation' => sprintf(__('%s list navigation', 'proto-blocks'), $p),
            /* translators: %s: plural name */
            'items_list'            => sprintf(__('%s list', 'proto-blocks'), $p),
            /* translators: %s: singular name */
            'item_published'        => sprintf(__('%s published.', 'proto-blocks'), $s),
            /* translators: %s: singular name */
            'item_updated'          => sprintf(__('%s updated.', 'proto-blocks'), $s),
            /* translators: %s: singular name */
            'item_link'             => sprintf(__('%s Link', 'proto-blocks'), $s),
        ];
    }

    /**
     * The full label set for a taxonomy. A hierarchical one gets the
     * category-style parent labels; a flat one the tag-style labels (popular
     * items, comma-separated entry, most used). Each is worded with the
     * taxonomy's own names, so editors never read "tags" or "categories".
     *
     * @return array<string,string>
     */
    public static function taxonomy(string $singular, string $plural, bool $hierarchical = false): array
    {
        $s = $singular;
        $p = $plural;
        $sl = self::lower($singular);
        $pl = self::lower($plural);

        $labels = [
            'name'          => $p,
            'singular_name' => $s,
            /* translators: %s: plural name */
            'search_items'  => sprintf(__('Search %s', 'proto-blocks'), $p),
            /* translators: %s: plural name */
            'all_items'     => sprintf(__('All %s', 'proto-blocks'), $p),
            /* translators: %s: singular name */
            'edit_item'     => sprintf(__('Edit %s', 'proto-blocks'), $s),
            /* translators: %s: singular name */
            'view_item'     => sprintf(__('View %s', 'proto-blocks'), $s),
            /* translators: %s: singular name */
            'update_item'   => sprintf(__('Update %s', 'proto-blocks'), $s),
            /* translators: %s: singular name */
            'add_new_item'  => sprintf(__('Add New %s', 'proto-blocks'), $s),
            /* translators: %s: singular name */
            'new_item_name' => sprintf(__('New %s', 'proto-blocks'), $s),
        ];

        if ($hierarchical) {
            $labels += [
                /* translators: %s: singular name */
                'parent_item'              => sprintf(__('Parent %s', 'proto-blocks'), $s),
                /* translators: %s: singular name */
                'parent_item_colon'        => sprintf(__('Parent %s:', 'proto-blocks'), $s),
                /* translators: %s: lowercase singular name */
                'parent_field_description' => sprintf(__('Assign a parent %s to create a hierarchy.', 'proto-blocks'), $sl),
                /* translators: %s: lowercase singular name */
                'filter_by_item'           => sprintf(__('Filter by %s', 'proto-blocks'), $sl),
            ];
        } else {
            $labels += [
                /* translators: %s: plural name */
                'popular_items'              => sprintf(__('Popular %s', 'proto-blocks'), $p),
                /* translators: %s: lowercase plural name */
                'separate_items_with_commas' => sprintf(__('Separate %s with commas', 'proto-blocks'), $pl),
                /* translators: %s: lowercase plural name */
                'add_or_remove_items'        => sprintf(__('Add or remove %s', 'proto-blocks'), $pl),
                /* translators: %s: lowercase plural name */
                'choose_from_most_used'      => sprintf(__('Choose from the most used %s', 'proto-blocks'), $pl),
            ];
        }

        return $labels + [
            /* translators: %s: lowercase plural name */
            'not_found'              => sprintf(__('No %s found.', 'proto-blocks'), $pl),
            /* translators: %s: lowercase plural name */
            'no_terms'               => sprintf(__('No %s', 'proto-blocks'), $pl),
            'menu_name'              => $p,
            /* translators: %s: plural name */
            'items_list_navigation'  => sprintf(__('%s list navigation', 'proto-blocks'), $p),
            /* translators: %s: plural name */
            'items_list'             => sprintf(__('%s list', 'proto-blocks'), $p),
            /* translators: Tab heading when selecting from the most used terms. */
            'most_used'              => _x('Most Used', 'terms', 'proto-blocks'),
            /* translators: %s: plural name */
            'back_to_items'          => sprintf(__('← Go to %s', 'proto-blocks'), $p),
            /* translators: %s: singular name */
            'item_link'              => sprintf(__('%s Link', 'proto-blocks'), $s),
            /* translators: %s: lowercase singular name */
            'item_link_description'  => sprintf(__('A link to a %s.', 'proto-blocks'), $sl),
            'name_field_description' => __('The name is how it appears on your site.', 'proto-blocks'),
            // Core writes the quotes as &#8220;/&#8221;, which render as
            // curly quotes. The admin UI shows labels as text, where entities
            // would appear raw, so the quote characters are written directly.
            'slug_field_description' => __('The “slug” is the URL-friendly version of the name. It is usually all lowercase and contains only letters, numbers, and hyphens.', 'proto-blocks'),
            'desc_field_description' => __('The description is not prominent by default; however, some themes may show it.', 'proto-blocks'),
        ];
    }

    /**
     * Lowercase each word, except words with two or more capitals (acronyms).
     */
    private static function lower(string $name): string
    {
        $words = preg_split('/(\s+)/u', $name, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$name];
        foreach ($words as $i => $word) {
            if (preg_match_all('/\p{Lu}/u', $word) >= 2) {
                continue;
            }
            $words[$i] = mb_strtolower($word);
        }
        return implode('', $words);
    }
}
