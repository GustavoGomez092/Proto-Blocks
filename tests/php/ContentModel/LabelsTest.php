<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\ContentModel;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\ContentModel\Labels;

final class LabelsTest extends TestCase
{
    public function testPostTypeLabelsMatchRecon(): void
    {
        $expected = [
            'name' => 'Resources', 'singular_name' => 'Resource', 'menu_name' => 'Resources',
            'name_admin_bar' => 'Resource', 'add_new' => 'Add New', 'add_new_item' => 'Add New Resource',
            'new_item' => 'New Resource', 'edit_item' => 'Edit Resource', 'view_item' => 'View Resource',
            'view_items' => 'View Resources', 'all_items' => 'All Resources', 'search_items' => 'Search Resources',
            'not_found' => 'No resources found.', 'not_found_in_trash' => 'No resources found in Trash.',
            'archives' => 'Resource Archives', 'attributes' => 'Resource Attributes',
            'parent_item_colon' => 'Parent Resource:', 'featured_image' => 'Featured image',
            'set_featured_image' => 'Set featured image', 'remove_featured_image' => 'Remove featured image',
            'use_featured_image' => 'Use as featured image', 'insert_into_item' => 'Insert into resource',
            'uploaded_to_this_item' => 'Uploaded to this resource', 'filter_items_list' => 'Filter resources list',
            'items_list_navigation' => 'Resources list navigation', 'items_list' => 'Resources list',
            'item_published' => 'Resource published.', 'item_updated' => 'Resource updated.',
            'item_link' => 'Resource Link',
        ];
        $this->assertSame($expected, Labels::postType('Resource', 'Resources'));
    }

    private const SLUG_DESCRIPTION = 'The “slug” is the URL-friendly version of the name. It is usually all lowercase and contains only letters, numbers, and hyphens.';

    public function testFlatTaxonomyLabelsUseItsOwnNames(): void
    {
        $expected = [
            'name' => 'Resource Types', 'singular_name' => 'Resource Type',
            'search_items' => 'Search Resource Types', 'all_items' => 'All Resource Types',
            'edit_item' => 'Edit Resource Type', 'view_item' => 'View Resource Type',
            'update_item' => 'Update Resource Type', 'add_new_item' => 'Add New Resource Type',
            'new_item_name' => 'New Resource Type',
            'popular_items' => 'Popular Resource Types',
            'separate_items_with_commas' => 'Separate resource types with commas',
            'add_or_remove_items' => 'Add or remove resource types',
            'choose_from_most_used' => 'Choose from the most used resource types',
            'not_found' => 'No resource types found.', 'no_terms' => 'No resource types',
            'menu_name' => 'Resource Types',
            'items_list_navigation' => 'Resource Types list navigation', 'items_list' => 'Resource Types list',
            'most_used' => 'Most Used',
            'back_to_items' => '← Go to Resource Types',
            'item_link' => 'Resource Type Link', 'item_link_description' => 'A link to a resource type.',
            'name_field_description' => 'The name is how it appears on your site.',
            'slug_field_description' => self::SLUG_DESCRIPTION,
            'desc_field_description' => 'The description is not prominent by default; however, some themes may show it.',
        ];
        $this->assertSame($expected, Labels::taxonomy('Resource Type', 'Resource Types'));
        $this->assertSame($expected, Labels::taxonomy('Resource Type', 'Resource Types', false));
    }

    public function testHierarchicalTaxonomyLabelsUseParentWording(): void
    {
        $expected = [
            'name' => 'Genres', 'singular_name' => 'Genre',
            'search_items' => 'Search Genres', 'all_items' => 'All Genres',
            'edit_item' => 'Edit Genre', 'view_item' => 'View Genre',
            'update_item' => 'Update Genre', 'add_new_item' => 'Add New Genre',
            'new_item_name' => 'New Genre',
            'parent_item' => 'Parent Genre', 'parent_item_colon' => 'Parent Genre:',
            'parent_field_description' => 'Assign a parent genre to create a hierarchy.',
            'filter_by_item' => 'Filter by genre',
            'not_found' => 'No genres found.', 'no_terms' => 'No genres',
            'menu_name' => 'Genres',
            'items_list_navigation' => 'Genres list navigation', 'items_list' => 'Genres list',
            'most_used' => 'Most Used',
            'back_to_items' => '← Go to Genres',
            'item_link' => 'Genre Link', 'item_link_description' => 'A link to a genre.',
            'name_field_description' => 'The name is how it appears on your site.',
            'slug_field_description' => self::SLUG_DESCRIPTION,
            'desc_field_description' => 'The description is not prominent by default; however, some themes may show it.',
        ];
        $this->assertSame($expected, Labels::taxonomy('Genre', 'Genres', true));
    }

    public function testNoTaxonomyLabelCarriesEntitiesOrTagWording(): void
    {
        foreach ([false, true] as $hierarchical) {
            foreach (Labels::taxonomy('Genre', 'Genres', $hierarchical) as $key => $label) {
                $this->assertDoesNotMatchRegularExpression('/&[#a-z0-9]+;/i', $label, "label $key");
                $this->assertDoesNotMatchRegularExpression('/\b(tags?|categor(y|ies))\b/i', $label, "label $key");
            }
        }
    }

    public function testFlatTaxonomyKeepsAcronyms(): void
    {
        $this->assertSame('Separate FAQ topics with commas', Labels::taxonomy('FAQ Topic', 'FAQ Topics')['separate_items_with_commas']);
    }

    public function testLowercaseVariantKeepsAcronyms(): void
    {
        $this->assertSame('No FAQs found.', Labels::postType('FAQ', 'FAQs')['not_found']);
    }
}
