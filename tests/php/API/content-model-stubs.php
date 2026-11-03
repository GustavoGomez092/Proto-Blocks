<?php

declare(strict_types=1);

// Stand-ins for the content-model REST route tests (PostTypesRoutesTest,
// TaxonomiesRoutesTest, SchemaFilesRoutesTest). Loaded after the Admin and
// ContentModel stubs; every stub is guarded so the first definition wins.
//
// Globals read here:
//   pb_test_post_types[$slug]  registered post type objects (ContentModel/stubs/hooks.php)
//   pb_test_taxonomies[$slug]  registered taxonomy objects (ContentModel/stubs/hooks.php)

if (!function_exists('get_post_types')) {
    function get_post_types($args = [], $output = 'names', $operator = 'and')
    {
        $objects = $GLOBALS['pb_test_post_types'] ?? [];
        $names = array_keys($objects);
        return $output === 'objects' ? $objects : array_combine($names, $names);
    }
}
if (!function_exists('get_taxonomies')) {
    function get_taxonomies($args = [], $output = 'names', $operator = 'and')
    {
        $objects = $GLOBALS['pb_test_taxonomies'] ?? [];
        $names = array_keys($objects);
        return $output === 'objects' ? $objects : array_combine($names, $names);
    }
}
if (!class_exists('WP_REST_Server')) {
    /** The method constants of core's WP_REST_Server, with core's values. */
    class WP_REST_Server
    {
        public const READABLE = 'GET';
        public const CREATABLE = 'POST';
        public const EDITABLE = 'POST, PUT, PATCH';
        public const DELETABLE = 'DELETE';
        public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
    }
}
