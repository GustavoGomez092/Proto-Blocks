<?php
/**
 * Public one-liner for declaring a settings page from a theme or plugin.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

if (!function_exists('proto_options_page')) {
    /**
     * Declare a settings page in PHP.
     *
     * The page slug comes from the title (or `$args['slug']`). The declaration
     * is only recorded (as a Phase 1 PHP declaration of kind `options`, so a
     * `proto-options/<slug>/page.jsonc` file of the same slug wins);
     * validation happens when the registry loads it, so a bad declaration never
     * fatals the site at include time. Declare before `init` priority 9, like
     * proto_post_type().
     *
     * @param string $title Page title.
     * @param array  $args  Same keys as a page.jsonc file (menu, capability, fields, ...).
     */
    function proto_options_page(string $title, array $args = []): void
    {
        $slug = isset($args['slug']) && is_string($args['slug'])
            ? $args['slug']
            : \ProtoBlocks\OptionsPages\PageDefinition::slugFromTitle($title);

        if ($slug === null) {
            if (function_exists('_doing_it_wrong')) {
                _doing_it_wrong(
                    __FUNCTION__,
                    sprintf('Could not derive a page slug from the title "%s"; pass a "slug" argument.', $title),
                    '1.0.0'
                );
            }
            return;
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
        $source = isset($trace[0]['file']) ? $trace[0]['file'] . ':' . ($trace[0]['line'] ?? 0) : 'unknown:0';

        \ProtoBlocks\ContentModel\PhpDeclarations::add(
            \ProtoBlocks\ContentModel\Declaration::OPTIONS,
            $title,
            ['slug' => $slug] + $args,
            $source
        );
    }
}
