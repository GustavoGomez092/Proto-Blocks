<?php

declare(strict_types=1);

// Transcribed from cadco-theme/inc/cadco-resources.php (live site), labels as plain strings.
return [
    [
        'slug'         => 'resource_media',
        'object_types' => ['resource'],
        'args'         => [
            'labels' => [
                'name'          => 'Media Types',
                'singular_name' => 'Media Type',
                'all_items'     => 'All Media Types',
            ],
            'public'            => true,
            'show_in_rest'      => true,
            'hierarchical'      => true,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'media-type', 'with_front' => false],
        ],
    ],
    [
        'slug'         => 'resource_product',
        'object_types' => ['resource'],
        'args'         => [
            'labels' => [
                'name'          => 'Products',
                'singular_name' => 'Product',
                'all_items'     => 'All Products',
            ],
            'public'            => true,
            'show_in_rest'      => true,
            'hierarchical'      => false,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'resource-product', 'with_front' => false],
        ],
    ],
];
