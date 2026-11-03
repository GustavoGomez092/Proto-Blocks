<?php
/**
 * Global declaration functions for the content model.
 *
 * Loaded at file scope by proto-blocks.php so they exist before the theme's
 * functions.php runs. Call them any time before `init` priority 9.
 *
 * @package ProtoBlocks
 */

defined('ABSPATH') || exit;

if (!function_exists('proto_post_type')) {
    /**
     * Declare a post type by its singular name, e.g. proto_post_type('Product').
     *
     * @param array<string, mixed> $args Any key a proto-types/<slug>/type.jsonc file accepts.
     */
    function proto_post_type(string $name, array $args = []): void
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        \ProtoBlocks\ContentModel\PhpDeclarations::add(
            \ProtoBlocks\ContentModel\Declaration::TYPE,
            $name,
            $args,
            ($caller['file'] ?? 'unknown') . ':' . ($caller['line'] ?? 0)
        );
    }
}

if (!function_exists('proto_taxonomy')) {
    /**
     * Declare a taxonomy by its singular name, e.g. proto_taxonomy('Genre', ['post_types' => ['book']]).
     *
     * @param array<string, mixed> $args Any key a proto-taxonomies/<slug>/taxonomy.jsonc file accepts.
     */
    function proto_taxonomy(string $name, array $args = []): void
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        \ProtoBlocks\ContentModel\PhpDeclarations::add(
            \ProtoBlocks\ContentModel\Declaration::TAXONOMY,
            $name,
            $args,
            ($caller['file'] ?? 'unknown') . ':' . ($caller['line'] ?? 0)
        );
    }
}
