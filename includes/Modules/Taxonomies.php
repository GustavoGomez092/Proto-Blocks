<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules;

use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\PostTypeArgs;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\Slug;
use ProtoBlocks\ContentModel\TaxonomyArgs;

/**
 * Registers declared taxonomies (standalone and inline in a type) and
 * applies override declarations to taxonomies registered by core or other
 * plugins. Same lifecycle as PostTypes, booted after it so its `init` 10
 * run comes second:
 *
 * - `register_taxonomy_args`: merge an override onto someone else's args.
 * - `init` 10: register declared taxonomies whose slug is free, with their
 *   `post_types` as object types; otherwise an override, applied to the
 *   object directly (LATE_KEYS) when the filter could not reach it.
 * - `init` 20: attach an override's declared `post_types` with
 *   register_taxonomy_for_object_type().
 * - `wp_loaded` 10: a name-less declaration nobody registered is `name_required`.
 *
 * An inline taxonomy of a PHP type that fails its schema check is skipped
 * silently: the type's own check already reports the same error.
 */
final class Taxonomies implements ModuleInterface
{
    /** Keys that still take effect on an already-registered taxonomy object. */
    public const LATE_KEYS = ['labels', 'show_admin_column', 'show_in_quick_edit', 'description'];

    /** @var array<string, true> */
    private array $registered = [];

    /** @var array<string, true> */
    private array $overridden = [];

    /** @var array<string, true> */
    private array $filtered = [];

    /** @var array<string, true> */
    private array $pending = [];

    /** @var array<string, true> */
    private array $attached = [];

    /** @var array<string, array<string, mixed>> */
    private array $args = [];

    /** @var array<string, Issue> */
    private array $issues = [];

    private bool $registering = false;

    public function __construct(private readonly Registry $registry)
    {
    }

    public function id(): string
    {
        return 'taxonomies';
    }

    public function boot(): void
    {
        add_filter('register_taxonomy_args', [$this, 'filterArgs'], 10, 2);
        add_action('init', [$this, 'seal'], 9);
        add_action('init', [$this, 'register'], 10);
        add_action('init', [$this, 'attachObjectTypes'], 20);
        add_action('wp_loaded', [$this, 'resolvePending'], 10);
    }

    /** Hooked to `init` 9 (also sealed by PostTypes when that module is on). */
    public function seal(): void
    {
        Manager::sealDeclarations($this->registry);
    }

    /** Hooked to `init` 10. */
    public function register(): void
    {
        $this->seal();

        foreach ($this->registry->taxonomies() as $slug => $declaration) {
            $slug = (string) $slug;
            if ($this->parentFailsSchema($declaration)) {
                continue;
            }
            if (taxonomy_exists($slug) || in_array($slug, Slug::OVERRIDABLE_TAXONOMIES, true)) {
                $this->overridden[$slug] = true;
                if (!isset($this->filtered[$slug]) && taxonomy_exists($slug)) {
                    $this->applyLate($declaration);
                }
                continue;
            }

            if ($declaration->name() === null) {
                $this->pending[$slug] = true;
                continue;
            }

            $result = TaxonomyArgs::build($declaration);
            if (!$this->record($result['issues'])) {
                continue;
            }

            $this->registering = true;
            try {
                $object = register_taxonomy($slug, $result['objectTypes'], $result['args']);
            } finally {
                $this->registering = false;
            }
            if (class_exists('WP_Error') && $object instanceof \WP_Error) {
                $this->addIssue(new Issue(Issue::ERROR, 'register_failed', $object->get_error_message(), $declaration->source, $slug, Declaration::TAXONOMY));
                continue;
            }
            $this->registered[$slug] = true;
            $this->args[$slug] = $result['args'];
        }
    }

    /**
     * Hooked to `register_taxonomy_args`.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function filterArgs(array $args, string $slug): array
    {
        if ($this->registering) {
            return $args;
        }
        $declaration = $this->registry->get(Declaration::TAXONOMY, $slug);
        if ($declaration === null || $this->parentFailsSchema($declaration)) {
            return $args;
        }

        $result = TaxonomyArgs::override($args, $declaration);
        if (!$this->record($result['issues'])) {
            return $args;
        }

        $this->filtered[$slug] = true;
        $this->overridden[$slug] = true;
        $this->args[$slug] = $result['args'];

        return $result['args'];
    }

    /** Hooked to `init` 20 (and run again from resolvePending()). */
    public function attachObjectTypes(): void
    {
        foreach (array_keys($this->overridden) as $slug) {
            if (isset($this->attached[$slug]) || !taxonomy_exists($slug)) {
                continue;
            }
            $declaration = $this->registry->get(Declaration::TAXONOMY, $slug);
            if ($declaration === null || PostTypeArgs::schemaIssues($declaration, '#/$defs/taxonomy') !== []) {
                continue;
            }
            $this->attached[$slug] = true;
            $types = $declaration->data['post_types'] ?? [];
            foreach (is_array($types) ? $types : [] as $type) {
                if (is_string($type)) {
                    register_taxonomy_for_object_type($slug, $type);
                }
            }
        }
    }

    /** Hooked to `wp_loaded` 10. */
    public function resolvePending(): void
    {
        foreach (array_keys($this->pending) as $slug) {
            unset($this->pending[$slug]);
            if (taxonomy_exists($slug)) {
                $this->overridden[$slug] = true;
                continue;
            }
            $declaration = $this->registry->get(Declaration::TAXONOMY, $slug);
            $this->addIssue(new Issue(
                Issue::ERROR,
                'name_required',
                sprintf(
                    /* translators: %s: taxonomy slug */
                    __('"%s" has no name and no taxonomy with that slug is registered, so nothing was registered. Add a "name" to register it, or keep it as an override for a taxonomy a plugin registers.', 'proto-blocks'),
                    $slug
                ),
                $declaration !== null ? $declaration->source : $slug,
                $slug,
                Declaration::TAXONOMY
            ));
        }
        $this->attachObjectTypes();
    }

    /** @return list<string> */
    public function registered(): array
    {
        return array_keys($this->registered);
    }

    /** @return list<string> */
    public function overridden(): array
    {
        return array_keys($this->overridden);
    }

    /** @return list<Issue> */
    public function issues(): array
    {
        return array_values($this->issues);
    }

    /** @return array<string, array<string, mixed>> */
    public function appliedArgs(): array
    {
        return $this->args;
    }

    /**
     * An inline taxonomy of a PHP type that fails its schema check: the type
     * is skipped and reports the error, so its taxonomy is skipped quietly.
     */
    private function parentFailsSchema(Declaration $declaration): bool
    {
        if ($declaration->parentType === null || $declaration->origin !== Declaration::ORIGIN_PHP) {
            return false;
        }
        $type = $this->registry->get(Declaration::TYPE, $declaration->parentType);

        return $type !== null && PostTypeArgs::schemaIssues($type, '#/$defs/type') !== [];
    }

    private function applyLate(Declaration $declaration): void
    {
        $object = get_taxonomy($declaration->slug);
        if (!is_object($object)) {
            return;
        }
        $result = TaxonomyArgs::override([], $declaration);
        if (!$this->record($result['issues'])) {
            return;
        }

        $ignored = [];
        foreach ($result['args'] as $key => $value) {
            if ($key === 'labels') {
                $object->labels = PostTypeArgs::mergeLabels($object->labels ?? [], is_array($value) ? $value : []);
                $object->labels = get_taxonomy_labels($object);
                if (is_object($object->labels) && isset($object->labels->name)) {
                    $object->label = $object->labels->name;
                }
            } elseif (in_array($key, self::LATE_KEYS, true)) {
                $object->{$key} = $value;
            } else {
                $ignored[] = (string) $key;
            }
        }

        if ($ignored !== []) {
            $this->addIssue(new Issue(
                Issue::WARNING,
                'late_override_ignored',
                sprintf(
                    /* translators: 1: taxonomy slug, 2: comma-separated keys */
                    __('"%1$s" was registered before its override could be applied, so these keys were ignored: %2$s. Declare the override before init priority 0, for example in a declaration file.', 'proto-blocks'),
                    $declaration->slug,
                    implode(', ', $ignored)
                ),
                $declaration->source,
                $declaration->slug,
                Declaration::TAXONOMY
            ));
        }
    }

    /**
     * Record issues; false when one is an error.
     *
     * @param list<Issue> $issues
     */
    private function record(array $issues): bool
    {
        $ok = true;
        foreach ($issues as $issue) {
            $this->addIssue($issue);
            $ok = $ok && $issue->level !== Issue::ERROR;
        }

        return $ok;
    }

    private function addIssue(Issue $issue): void
    {
        $this->issues[Manager::issueKey($issue)] ??= $issue;
    }
}
