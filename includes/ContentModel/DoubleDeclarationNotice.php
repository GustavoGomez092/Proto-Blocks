<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

use ProtoBlocks\API\Admin\ContentModelRoutes;

/**
 * The admin notice for a slug declared both in a file and in PHP: names both
 * locations, on Proto-Blocks screens only, to users who can manage options.
 * Paths are shown theme- or site-relative, as the REST routes show them,
 * never absolute.
 */
final class DoubleDeclarationNotice
{
    public function __construct(private readonly Registry $registry)
    {
    }

    /** Hooked to `admin_notices` (admin requests only). */
    public function render(): void
    {
        if (!function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        $id = is_object($screen) && is_string($screen->id ?? null) ? $screen->id : '';
        if (!str_contains($id, 'proto-blocks') || !current_user_can('manage_options')) {
            return;
        }

        $doubles = $this->registry->doubles();
        if ($doubles === []) {
            return;
        }

        /* translators: 1: post type or taxonomy slug, 2: declaration file path, 3: "<file>:<line>" of the PHP call */
        $format = __('"%1$s" is declared twice. The file %2$s is used; the PHP declaration at %3$s is ignored. Delete the PHP line when ready.', 'proto-blocks');
        // Text-node context: quotes are harmless, markup in a translation is not.
        $format = htmlspecialchars($format, ENT_NOQUOTES, 'UTF-8', false);

        $lines = [];
        foreach ($doubles as $double) {
            $lines[] = sprintf(
                $format,
                esc_html($double['slug']),
                '<code>' . esc_html(ContentModelRoutes::displayPath($double['file'])) . '</code>',
                '<code>' . esc_html(ContentModelRoutes::displayPath($double['php'])) . '</code>'
            );
        }

        echo '<div class="notice notice-warning"><p>' . implode('</p><p>', $lines) . '</p></div>';
    }
}
