<?php

declare(strict_types=1);

namespace ProtoBlocks\OptionsPages;

use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\Slug;

/**
 * The options pages of this request: `proto-options/<slug>/page.jsonc` files
 * and proto_options_page() calls, as Phase 1's Registry merged them (a file
 * beats a PHP declaration of the same slug), each turned into a
 * PageDefinition.
 *
 * Nothing here throws on a bad declaration: an invalid file or definition is
 * skipped and reported in warnings() / issues(). A file that fails to load
 * also suppresses a PHP declaration of the same slug, so a typo in the file
 * never silently hands the page to the PHP call's (possibly wider) capability.
 * Built on first use and memoised until refresh().
 */
final class OptionsPagesRegistry
{
    public const KIND = Declaration::OPTIONS;

    /** @var array{pages: array<string, PageDefinition>, issues: list<Issue>}|null */
    private ?array $state = null;

    public function __construct(private readonly Registry $declarations)
    {
    }

    /**
     * Every valid page by slug; a child page appears under its compound slug
     * (`<parent>-<child>`) after the top-level pages.
     *
     * @return array<string, PageDefinition>
     */
    public function all(): array
    {
        return $this->state()['pages'];
    }

    public function get(string $slug): ?PageDefinition
    {
        return $this->state()['pages'][$slug] ?? null;
    }

    /**
     * @return list<array{slug: string, source: ?string, message: string}>
     */
    public function warnings(): array
    {
        return array_map(
            static fn (Issue $issue): array => [
                'slug' => (string) $issue->slug,
                'source' => $issue->source === '' ? null : $issue->source,
                'message' => $issue->message,
            ],
            $this->state()['issues']
        );
    }

    /**
     * The warnings as Phase 1 issues (kind `options`), for `wp proto-blocks validate`.
     *
     * @return list<Issue>
     */
    public function issues(): array
    {
        return $this->state()['issues'];
    }

    public function refresh(): void
    {
        $this->declarations->invalidate();
        $this->state = null;
    }

    /**
     * `proto_blocks_declaration_errors` callback: for kind `options`, appends
     * what PageDefinition refuses (protected or over-long option names,
     * duplicate field names, bad children...) to $errors. Other kinds pass
     * through unchanged.
     *
     * @param list<array{path: string, message: string}> $errors
     * @param array<string|int, mixed>                    $data
     * @return list<array{path: string, message: string}>
     */
    public static function declarationErrors(array $errors, string $kind, array $data, string $slug = ''): array
    {
        if ($kind !== self::KIND) {
            return $errors;
        }

        try {
            // Without a slug, any valid one: only the slug-derived checks then go unchecked.
            PageDefinition::fromArray($slug === '' ? 'page' : $slug, $data, 'file');
        } catch (InvalidDeclaration $e) {
            foreach ($e->errors() as $error) {
                $errors[] = ['path' => $error['path'], 'message' => $error['message']];
            }
        }

        // Each child's compound slug must be a usable admin page slug too.
        $children = $data['children'] ?? null;
        if ($slug !== '' && is_array($children) && array_is_list($children)) {
            foreach ($children as $i => $child) {
                $childSlug = is_array($child) ? ($child['slug'] ?? null) : null;
                if (is_string($childSlug) && Slug::error($slug . '-' . $childSlug, self::KIND) === 'reserved') {
                    $errors[] = ['path' => '/children/' . $i . '/slug', 'message' => self::childSlugMessage($slug . '-' . $childSlug, $slug)];
                }
            }
        }

        return $errors;
    }

    /**
     * @return array{pages: array<string, PageDefinition>, issues: list<Issue>}
     */
    private function state(): array
    {
        return $this->state ??= $this->build();
    }

    /**
     * @return array{pages: array<string, PageDefinition>, issues: list<Issue>}
     */
    private function build(): array
    {
        $issues = [];
        $brokenFiles = [];
        foreach ($this->declarations->issues() as $issue) {
            if ($issue->kind !== self::KIND) {
                continue;
            }
            $issues[] = $issue;
            if ($issue->level === Issue::ERROR && $issue->slug !== null && self::isFile($issue->source)) {
                $brokenFiles[$issue->slug] ??= $issue->source;
            }
        }

        $pages = [];
        $children = [];
        foreach ($this->declarations->all(self::KIND) as $slug => $declaration) {
            $slug = (string) $slug;
            if ($declaration->origin === Declaration::ORIGIN_PHP && isset($brokenFiles[$slug])) {
                $issues[] = new Issue(
                    Issue::WARNING,
                    'php_declaration_ignored',
                    sprintf(
                        /* translators: 1: declaration file path, 2: "<file>:<line>" of the PHP call */
                        __('The file %1$s is invalid; the PHP declaration at %2$s is not used either.', 'proto-blocks'),
                        $brokenFiles[$slug],
                        $declaration->source
                    ),
                    $declaration->source,
                    $slug,
                    self::KIND
                );
                continue;
            }

            try {
                $page = PageDefinition::fromArray($slug, $declaration->data, $declaration->origin, $declaration->source, $declaration->root);
            } catch (InvalidDeclaration $e) {
                foreach ($e->errors() as $error) {
                    $issues[] = new Issue(
                        Issue::ERROR,
                        'declaration_invalid',
                        ($error['path'] === '' ? '/' : $error['path']) . ': ' . $error['message'],
                        $declaration->source,
                        $slug,
                        self::KIND
                    );
                }
                continue;
            }

            $pages[$slug] = $page;
            foreach ($page->children as $child) {
                $children[] = $child;
            }
        }

        foreach ($children as $child) {
            // The compound slug is an admin page slug too: never a core screen or one of this plugin's pages.
            if (Slug::error($child->slug, self::KIND) !== null) {
                $issues[] = new Issue(
                    Issue::ERROR,
                    'child_slug_reserved',
                    self::childSlugMessage($child->slug, (string) $child->parentSlug),
                    (string) $child->source,
                    $child->slug,
                    self::KIND
                );
                continue;
            }
            if (isset($pages[$child->slug])) {
                $issues[] = new Issue(
                    Issue::WARNING,
                    'child_slug_taken',
                    sprintf(
                        /* translators: 1: page slug, 2: parent page slug */
                        __('The child page "%1$s" of "%2$s" has the same slug as another page and was skipped. Rename one of them.', 'proto-blocks'),
                        $child->slug,
                        (string) $child->parentSlug
                    ),
                    (string) $child->source,
                    $child->slug,
                    self::KIND
                );
                continue;
            }
            $pages[$child->slug] = $child;
        }

        array_push($issues, ...self::sharedStorage($pages));

        return ['pages' => $pages, 'issues' => $issues];
    }

    /**
     * One `shared_storage` warning per page whose values would land in an
     * option another page already uses (the same array option, or an array
     * option equal to another page's `<slug>_<field>`).
     *
     * @param array<string, PageDefinition> $pages
     * @return list<Issue>
     */
    private static function sharedStorage(array $pages): array
    {
        $owners = [];
        $issues = [];
        foreach ($pages as $slug => $page) {
            $names = $page->storageMode === PageDefinition::MODE_ARRAY && $page->storageOption !== null
                ? [$page->storageOption]
                : array_map(static fn (string|int $field): string => $page->optionName((string) $field), array_keys($page->fields()));
            foreach (array_unique($names) as $name) {
                $key = strtolower($name); // option names compare case-insensitively in MySQL
                $owner = $owners[$key] ?? null;
                if ($owner === null) {
                    $owners[$key] = (string) $slug;
                    continue;
                }
                $issues[] = new Issue(
                    Issue::WARNING,
                    'shared_storage',
                    sprintf(
                        /* translators: 1: page slug, 2: option name, 3: other page slug */
                        __('The page "%1$s" stores values in the option "%2$s", which the page "%3$s" also uses. Saving one page may overwrite fields of the same name on the other; give each page its own option.', 'proto-blocks'),
                        (string) $slug,
                        $name,
                        $owner
                    ),
                    (string) $page->source,
                    (string) $slug,
                    self::KIND
                );
            }
        }

        return $issues;
    }

    private static function childSlugMessage(string $compound, string $parent): string
    {
        return sprintf(
            /* translators: 1: child page slug (parent slug + child slug), 2: parent page slug */
            __('The child page slug "%1$s" of "%2$s" is reserved by WordPress or Proto-Blocks. Rename the child.', 'proto-blocks'),
            $compound,
            $parent
        );
    }

    /** A declaration file path, as opposed to the "<file>:<line>" of a PHP call. */
    private static function isFile(string $source): bool
    {
        return preg_match('/\.jsonc?\z/D', $source) === 1;
    }
}
