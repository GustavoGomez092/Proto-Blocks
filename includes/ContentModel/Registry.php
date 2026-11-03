<?php

declare(strict_types=1);

namespace ProtoBlocks\ContentModel;

/**
 * The merged set of declarations for this request.
 *
 * Precedence: a file beats a PHP declaration of the same kind and slug
 * entirely (warning `double_declaration`); a standalone taxonomy (file or
 * PHP) beats one declared inline in a type (warning `double_declaration`),
 * and the inline taxonomy's type joins the winner's `post_types`. Two types
 * that inline the same taxonomy with the same data apart from `post_types`
 * share it with no warning; any other difference is a `double_declaration`.
 * The result is memoised until invalidate().
 */
final class Registry
{
    private ?array $state = null;

    /**
     * @param \Closure(): list<string> $roots
     */
    public function __construct(private readonly FileLoader $loader, private readonly \Closure $roots)
    {
    }

    /** @return array<string, Declaration> */
    public function postTypes(): array
    {
        return $this->state()['declarations'][Declaration::TYPE];
    }

    /** @return array<string, Declaration> standalone and inline */
    public function taxonomies(): array
    {
        return $this->state()['declarations'][Declaration::TAXONOMY];
    }

    public function get(string $kind, string $slug): ?Declaration
    {
        return $this->state()['declarations'][$kind][$slug] ?? null;
    }

    /** @return list<array{kind: string, slug: string, file: string, php: string}> */
    public function doubles(): array
    {
        return $this->state()['doubles'];
    }

    /**
     * File and precedence issues from the last load, plus every PHP
     * declaration issue so far (a `declared_too_late` warning can arrive
     * after the load).
     *
     * @return list<Issue>
     */
    public function issues(): array
    {
        return [...$this->state()['issues'], ...PhpDeclarations::issues()];
    }

    public function invalidate(): void
    {
        $this->state = null;
    }

    /**
     * @return array{declarations: array<string, array<string, Declaration>>, doubles: list<array{kind: string, slug: string, file: string, php: string}>, issues: list<Issue>}
     */
    private function state(): array
    {
        return $this->state ??= $this->build();
    }

    private function build(): array
    {
        $loaded = $this->loader->load(($this->roots)());
        $files = $loaded['declarations'];
        $php = PhpDeclarations::all();
        $issues = $loaded['issues'];
        $doubles = [];

        $merged = [];
        foreach ([Declaration::TYPE, Declaration::TAXONOMY] as $kind) {
            $merged[$kind] = $php[$kind] ?? [];
            foreach ($files[$kind] ?? [] as $slug => $file) {
                $phpDeclaration = $merged[$kind][$slug] ?? null;
                if ($phpDeclaration !== null) {
                    $doubles[] = ['kind' => $kind, 'slug' => $slug, 'file' => $file->source, 'php' => $phpDeclaration->source];
                    $issues[] = self::doubleIssue($kind, $slug, $file->source, $phpDeclaration->source);
                }
                $merged[$kind][$slug] = $file;
            }
        }

        foreach ($merged[Declaration::TYPE] as $type) {
            foreach ($this->inlineTaxonomies($type, $issues) as $slug => $inline) {
                $existing = $merged[Declaration::TAXONOMY][$slug] ?? null;
                if ($existing === null) {
                    $merged[Declaration::TAXONOMY][$slug] = $inline;
                    continue;
                }
                // The existing declaration (standalone, or an earlier type's
                // inline one) wins, and the losing type is attached to it.
                $merged[Declaration::TAXONOMY][$slug] = self::withPostType($existing, (string) $inline->parentType);
                $sharedInline = $existing->parentType !== null
                    && self::sameApartFromPostTypes($existing->data, $inline->data);
                if (!$sharedInline) {
                    $issues[] = self::inlineDoubleIssue($slug, $existing->source, $inline->source, (string) $inline->parentType);
                }
            }
        }

        return ['declarations' => $merged, 'doubles' => $doubles, 'issues' => $issues];
    }

    /**
     * Object entries of a type's `taxonomies` key, as taxonomy declarations.
     * String entries name existing taxonomies and are not declarations.
     *
     * @param list<Issue> $issues
     * @return array<string, Declaration>
     */
    private function inlineTaxonomies(Declaration $type, array &$issues): array
    {
        $entries = $type->data['taxonomies'] ?? [];
        if (!is_array($entries)) {
            return [];
        }

        $out = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['name'] ?? null)) {
                continue;
            }
            $slug = $entry['slug'] ?? Slug::fromName($entry['name'], Slug::TAXONOMY_MAX);
            $slug = is_string($slug) ? $slug : '';
            $slugError = Slug::error($slug, Declaration::TAXONOMY);
            if ($slugError !== null) {
                $issues[] = new Issue(
                    Issue::ERROR,
                    'slug_' . $slugError,
                    FileLoader::slugMessage($slugError, $slug, Declaration::TAXONOMY),
                    $type->source,
                    $slug === '' ? null : $slug,
                    Declaration::TAXONOMY
                );
                continue;
            }

            $listed = is_array($entry['post_types'] ?? null) ? $entry['post_types'] : [];
            $entry['post_types'] = array_values(array_unique([$type->slug, ...$listed]));

            $out[$slug] = new Declaration(
                Declaration::TAXONOMY,
                $slug,
                $entry,
                $type->origin,
                $type->source,
                $type->root,
                $type->slug
            );
        }

        return $out;
    }

    private static function withPostType(Declaration $declaration, string $type): Declaration
    {
        $listed = is_array($declaration->data['post_types'] ?? null) ? $declaration->data['post_types'] : [];
        if (in_array($type, $listed, true)) {
            return $declaration;
        }
        $data = $declaration->data;
        $data['post_types'] = [...array_values($listed), $type];

        return new Declaration(
            $declaration->kind,
            $declaration->slug,
            $data,
            $declaration->origin,
            $declaration->source,
            $declaration->root,
            $declaration->parentType
        );
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function sameApartFromPostTypes(array $a, array $b): bool
    {
        unset($a['post_types'], $b['post_types']);

        return self::canonical($a) === self::canonical($b);
    }

    /** Sort object keys recursively so key order does not count as a difference. */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map([self::class, 'canonical'], $value);
    }

    private static function inlineDoubleIssue(string $slug, string $winner, string $loser, string $type): Issue
    {
        return new Issue(
            Issue::WARNING,
            'double_declaration',
            sprintf(
                /* translators: 1: taxonomy slug, 2: location that is used, 3: location that is ignored, 4: post type slug */
                __('"%1$s" is declared twice: %2$s is used and %3$s is ignored; the post type "%4$s" was added to its post types. Remove one of them.', 'proto-blocks'),
                $slug,
                $winner,
                $loser,
                $type
            ),
            $winner,
            $slug,
            Declaration::TAXONOMY
        );
    }

    private static function doubleIssue(string $kind, string $slug, string $winner, string $loser): Issue
    {
        return new Issue(
            Issue::WARNING,
            'double_declaration',
            sprintf(
                /* translators: 1: slug, 2: location that is used, 3: location that is ignored */
                __('"%1$s" is declared twice: %2$s is used and %3$s is ignored. Remove one of them.', 'proto-blocks'),
                $slug,
                $winner,
                $loser
            ),
            $winner,
            $slug,
            $kind
        );
    }
}
