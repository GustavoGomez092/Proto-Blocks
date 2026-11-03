<?php

declare(strict_types=1);

// Transcribed from cadco-theme/inc/cadco-resources.php (live site), labels as plain strings.
return [
    'slug' => 'resource',
    'args' => [
        'labels' => [
            'name'          => 'Resources',
            'singular_name' => 'Resource',
            'add_new_item'  => 'Add New Resource',
            'edit_item'     => 'Edit Resource',
            'search_items'  => 'Search Resources',
            'not_found'     => 'No resources found',
            'all_items'     => 'All Resources',
        ],
        'public'        => true,
        'show_in_rest'  => true,
        'menu_icon'     => 'dashicons-media-document',
        'menu_position' => 22,
        'has_archive'   => false,
        'rewrite'       => ['slug' => 'resources', 'with_front' => false],
        'supports'      => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions'],
    ],
];
