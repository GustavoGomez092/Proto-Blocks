<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * Writes block theme templates for a declared post type into the active theme.
 *
 * Never overwrites: a template present in the stylesheet or template directory
 * is skipped and reported. Seeds are copied verbatim from the theme's own
 * single.html / archive.html; nothing from a declaration is interpolated
 * except the slug in the file name.
 */
final class TemplateScaffolder
{
    private const MAX_SEED_BYTES = 262144;

    public function __construct(
        private readonly string $stylesheetDir,
        private readonly string $templateDir,
        private readonly bool $isBlockTheme,
        private readonly bool $fileModsAllowed
    ) {
    }

    /**
     * @param list<string> $taxonomySlugs
     * @return list<string>
     */
    public function plan(string $slug, bool $hasArchive, array $taxonomySlugs, bool $withTaxonomies): array
    {
        return array_keys($this->entries($slug, $hasArchive, $taxonomySlugs, $withTaxonomies));
    }

    /**
     * @param list<string> $taxonomySlugs
     * @return array{written: list<string>, skipped: list<string>}
     * @throws WriteException
     */
    public function scaffold(string $slug, bool $hasArchive, array $taxonomySlugs, bool $withTaxonomies): array
    {
        if (!$this->isBlockTheme) {
            throw new WriteException(
                'classic_theme',
                409,
                __('This is a classic theme. Template scaffolding needs a block theme; build single and archive templates in your theme.', 'proto-blocks')
            );
        }
        if (!$this->fileModsAllowed) {
            throw new WriteException('file_mods', 403, __('File modifications are disabled on this site.', 'proto-blocks'));
        }

        $entries = $this->entries($slug, $hasArchive, $taxonomySlugs, $withTaxonomies);

        $root = realpath($this->stylesheetDir);
        $outside = static fn (): WriteException => new WriteException(
            'path_outside_root',
            403,
            __('Templates can only be written inside the active theme.', 'proto-blocks')
        );
        if ($root === false) {
            throw $outside();
        }
        $dir = $root . '/templates';
        if (!SafeFile::isConfined($dir, [$root])) {
            throw $outside();
        }
        if (!is_dir($dir)) {
            if (!is_writable($root) || !@mkdir($dir, 0755) || !SafeFile::isConfined($dir, [$root])) {
                throw self::notWritable($dir);
            }
        }
        if (!is_writable($dir)) {
            throw self::notWritable($dir);
        }

        $written = [];
        $skipped = [];
        foreach ($entries as $name => $kind) {
            if ($this->exists($name)) {
                $skipped[] = $name;
                continue;
            }
            $target = $dir . '/' . $name;
            if (!SafeFile::isConfined($target, [$root])) {
                throw $outside();
            }
            SafeFile::writeAtomic($target, $this->seed($kind === 'single' ? 'single' : 'archive') ?? self::minimal($kind));
            $written[] = $name;
        }

        return ['written' => $written, 'skipped' => $skipped];
    }

    /**
     * @param list<string> $taxonomySlugs
     * @return array<string, string> file name => single|archive|taxonomy
     * @throws WriteException
     */
    private function entries(string $slug, bool $hasArchive, array $taxonomySlugs, bool $withTaxonomies): array
    {
        $this->assertSlug($slug, 'type');
        $out = ['single-' . $slug . '.html' => 'single'];
        if ($hasArchive) {
            $out['archive-' . $slug . '.html'] = 'archive';
        }
        if ($withTaxonomies) {
            foreach ($taxonomySlugs as $tax) {
                $this->assertSlug((string) $tax, 'taxonomy');
                $out['taxonomy-' . $tax . '.html'] = 'taxonomy';
            }
        }

        return $out;
    }

    private function assertSlug(string $slug, string $kind): void
    {
        if (Slug::error($slug, $kind) !== null) {
            throw new WriteException('invalid_slug', 400, __('Invalid slug.', 'proto-blocks'));
        }
    }

    private function exists(string $name): bool
    {
        foreach (array_unique([$this->stylesheetDir, $this->templateDir]) as $base) {
            $path = $base . '/templates/' . $name;
            if (file_exists($path) || is_link($path)) {
                return true;
            }
        }

        return false;
    }

    private function seed(string $which): ?string
    {
        foreach (array_unique([$this->stylesheetDir, $this->templateDir]) as $base) {
            $path = $base . '/templates/' . $which . '.html';
            if (is_link($path) || !is_file($path) || !is_readable($path) || filesize($path) > self::MAX_SEED_BYTES) {
                continue;
            }
            $content = file_get_contents($path);
            if (is_string($content) && $content !== '') {
                return $content;
            }
        }

        return null;
    }

    private static function minimal(string $kind): string
    {
        $header = '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->';
        $footer = '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
        if ($kind === 'single') {
            $main = <<<'HTML'
<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->
<main class="wp-block-group"><!-- wp:post-title {"level":1} /-->

<!-- wp:post-content {"layout":{"type":"constrained"}} /--></main>
<!-- /wp:group -->
HTML;
        } else {
            $main = <<<'HTML'
<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->
<main class="wp-block-group"><!-- wp:query {"queryId":0,"query":{"inherit":true}} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:post-title {"isLink":true} /-->

<!-- wp:post-excerpt /-->
<!-- /wp:post-template -->

<!-- wp:query-pagination -->
<!-- wp:query-pagination-previous /-->

<!-- wp:query-pagination-numbers /-->

<!-- wp:query-pagination-next /-->
<!-- /wp:query-pagination --></div>
<!-- /wp:query --></main>
<!-- /wp:group -->
HTML;
        }

        return $header . "\n\n" . $main . "\n\n" . $footer . "\n";
    }

    private static function notWritable(string $path): WriteException
    {
        return new WriteException(
            'not_writable',
            403,
            /* translators: %s: path */
            sprintf(__('The theme folder is not writable: %s', 'proto-blocks'), $path)
        );
    }
}
