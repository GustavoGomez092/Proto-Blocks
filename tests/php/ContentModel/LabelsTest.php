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

    public function testTaxonomyLabels(): void
    {
        $expected = [
            'name' => 'Resource Types', 'singular_name' => 'Resource Type',
            'search_items' => 'Search Resource Types', 'all_items' => 'All Resource Types',
            'edit_item' => 'Edit Resource Type', 'view_item' => 'View Resource Type',
            'update_item' => 'Update Resource Type', 'add_new_item' => 'Add New Resource Type',
            'new_item_name' => 'New Resource Type', 'parent_item' => 'Parent Resource Type',
            'not_found' => 'No resource types found.', 'menu_name' => 'Resource Types',
            'back_to_items' => '← Go to Resource Types',
        ];
        $this->assertSame($expected, Labels::taxonomy('Resource Type', 'Resource Types'));
    }

    public function testLowercaseVariantKeepsAcronyms(): void
    {
        $this->assertSame('No FAQs found.', Labels::postType('FAQ', 'FAQs')['not_found']);
    }
}
