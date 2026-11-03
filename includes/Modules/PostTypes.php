<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules;

use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\MenuIcon;
use ProtoBlocks\ContentModel\PostTypeArgs;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\Slug;

/**
 * Registers declared post types and applies override declarations to types
 * registered by core or other plugins.
 *
 * - `init` 9: seal PHP declarations.
 * - `register_post_type_args` (attached at boot, so it sees core's `init` 0
 *   re-registration and plugins on early `init`): merge an override
 *   declaration onto someone else's args.
 * - `init` 10: register declared types whose slug is free; a slug that is
 *   already registered (or a built-in) is an override. An override that the
 *   filter could not reach (the type was registered before the declaration
 *   was known) applies LATE_KEYS directly to the type object.
 * - `init` 20: attach a declared override's string `taxonomies` to the type.
 * - `wp_loaded` 10: a name-less declaration whose slug nobody registered
 *   becomes error `name_required`.
 */
final class PostTypes implements ModuleInterface
{
    /** Keys that still take effect on an already-registered type object. */
    public const LATE_KEYS = ['labels', 'template', 'template_lock', 'menu_icon', 'menu_position', 'supports', 'description', 'show_in_admin_bar', 'show_in_nav_menus'];

    /** @var array<string, true> */
    private array $registered = [];

    /** @var array<string, true> */
    private array $overridden = [];

    /** @var array<string, true> slugs the filter has merged an override into */
    private array $filtered = [];

    /** @var array<string, true> name-less declarations waiting for someone to register the slug */
    private array $pending = [];

    /** @var array<string, true> override types whose taxonomies are attached */
    private array $attached = [];

    /** @var array<string, array<string, mixed>> slug => args registered or overridden this request */
    private array $args = [];

    /** @var array<string, Issue> */
    private array $issues = [];

    private bool $registering = false;

    public function __construct(private readonly Registry $registry)
    {
    }

    public function id(): string
    {
        return 'post_types';
    }

    public function boot(): void
    {
        add_filter('register_post_type_args', [$this, 'filterArgs'], 10, 2);
        add_action('init', [$this, 'seal'], 9);
        add_action('init', [$this, 'register'], 10);
        add_action('init', [$this, 'attachTaxonomies'], 20);
        add_action('wp_loaded', [$this, 'resolvePending'], 10);
    }

    /** Hooked to `init` 9: later proto_post_type() calls are ignored with a warning. */
    public function seal(): void
    {
        Manager::sealDeclarations($this->registry);
    }

    /** Hooked to `init` 10. */
    public function register(): void
    {
        $this->seal();

        foreach ($this->registry->postTypes() as $slug => $declaration) {
            $slug = (string) $slug;
            if (post_type_exists($slug) || in_array($slug, Slug::OVERRIDABLE_TYPES, true)) {
                $this->overridden[$slug] = true;
                if (!isset($this->filtered[$slug]) && post_type_exists($slug)) {
                    $this->applyLate($declaration);
                }
                continue;
            }

            if ($declaration->name() === null) {
                $this->pending[$slug] = true;
                continue;
            }

            $result = $this->resolve($declaration, fn (string $dir, string $url) => PostTypeArgs::build($declaration, $dir, $url));
            if ($result === null) {
                continue;
            }

            $this->registering = true;
            try {
                $object = register_post_type($slug, $result);
            } finally {
                $this->registering = false;
            }
            if (class_exists('WP_Error') && $object instanceof \WP_Error) {
                $this->addIssue(new Issue(Issue::ERROR, 'register_failed', $object->get_error_message(), $declaration->source, $slug, Declaration::TYPE));
                continue;
            }
            $this->registered[$slug] = true;
            $this->args[$slug] = $result;
        }
    }

    /**
     * Hooked to `register_post_type_args`: merge the declaration for $slug
     * onto args someone else is registering. The module's own registrations
     * pass through untouched.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function filterArgs(array $args, string $slug): array
    {
        if ($this->registering) {
            return $args;
        }
        $declaration = $this->registry->get(Declaration::TYPE, $slug);
        if ($declaration === null) {
            return $args;
        }

        $result = $this->resolve($declaration, fn (string $dir, string $url) => PostTypeArgs::override($args, $declaration, $dir, $url));
        if ($result === null) {
            return $args;
        }

        $this->filtered[$slug] = true;
        $this->overridden[$slug] = true;
        $this->args[$slug] = $result;

        return $result;
    }

    /** Hooked to `init` 20 (and run again from resolvePending()). */
    public function attachTaxonomies(): void
    {
        foreach (array_keys($this->overridden) as $slug) {
            if (isset($this->attached[$slug]) || !post_type_exists($slug)) {
                continue;
            }
            $declaration = $this->registry->get(Declaration::TYPE, $slug);
            if ($declaration === null || PostTypeArgs::schemaIssues($declaration, '#/$defs/type') !== []) {
                continue;
            }
            $this->attached[$slug] = true;
            $taxonomies = $declaration->data['taxonomies'] ?? [];
            foreach (is_array($taxonomies) ? $taxonomies : [] as $taxonomy) {
                if (is_string($taxonomy)) {
                    register_taxonomy_for_object_type($taxonomy, $slug);
                }
            }
        }
    }

    /**
     * Hooked to `wp_loaded` 10: a name-less declaration is an override of a
     * type someone registered by now, or an error.
     */
    public function resolvePending(): void
    {
        foreach (array_keys($this->pending) as $slug) {
            unset($this->pending[$slug]);
            if (post_type_exists($slug)) {
                $this->overridden[$slug] = true;
                continue;
            }
            $declaration = $this->registry->get(Declaration::TYPE, $slug);
            $this->addIssue(new Issue(
                Issue::ERROR,
                'name_required',
                sprintf(
                    /* translators: %s: post type slug */
                    __('"%s" has no name and no post type with that slug is registered, so nothing was registered. Add a "name" to register it, or keep it as an override for a type a plugin registers.', 'proto-blocks'),
                    $slug
                ),
                $declaration !== null ? $declaration->source : $slug,
                $slug,
                Declaration::TYPE
            ));
        }
        $this->attachTaxonomies();
    }

    /** @return list<string> slugs this module registered itself */
    public function registered(): array
    {
        return array_keys($this->registered);
    }

    /** @return list<string> slugs applied as overrides */
    public function overridden(): array
    {
        return array_keys($this->overridden);
    }

    /** @return list<Issue> */
    public function issues(): array
    {
        return array_values($this->issues);
    }

    /**
     * Args this request registered or overrode, for the rewrite hash.
     *
     * @return array<string, array<string, mixed>>
     */
    public function appliedArgs(): array
    {
        return $this->args;
    }

    /**
     * Run an args builder with the declaration's icon base; record its
     * issues; null when it reported an error.
     *
     * @param \Closure(string, string): array{args: array<string, mixed>, issues: list<Issue>} $builder
     * @return array<string, mixed>|null
     */
    private function resolve(Declaration $declaration, \Closure $builder): ?array
    {
        [$dir, $url] = self::iconBase($declaration);
        $result = $builder($dir, $url);
        $issues = $result['issues'];
        $args = $result['args'];

        // A filter-added root has no URL: a raster icon there cannot be served.
        if (
            $url === '' && array_key_exists('menu_icon', $declaration->data)
            && is_string($args['menu_icon'] ?? null) && str_starts_with($args['menu_icon'], '/')
        ) {
            $args['menu_icon'] = MenuIcon::DEFAULT_ICON;
            $issues[] = new Issue(
                Issue::WARNING,
                'icon_invalid',
                sprintf(
                    /* translators: %s: declared menu_icon value */
                    __('The menu icon "%s" is an image in a folder added by the proto_blocks_schema_paths filter, which has no URL. Use an SVG or a dashicon there. The default icon is used.', 'proto-blocks'),
                    (string) $declaration->data['menu_icon']
                ),
                $declaration->source,
                $declaration->slug,
                Declaration::TYPE
            );
        }

        $failed = false;
        foreach ($issues as $issue) {
            $this->addIssue($issue);
            $failed = $failed || $issue->level === Issue::ERROR;
        }

        return $failed ? null : $args;
    }

    /**
     * Apply an override to a type object registered before the filter could
     * act: LATE_KEYS only, with a warning naming every other declared key.
     */
    private function applyLate(Declaration $declaration): void
    {
        $object = get_post_type_object($declaration->slug);
        if (!is_object($object)) {
            return;
        }
        $declared = $this->resolve($declaration, fn (string $dir, string $url) => PostTypeArgs::override([], $declaration, $dir, $url));
        if ($declared === null) {
            return;
        }

        $ignored = [];
        foreach ($declared as $key => $value) {
            switch ($key) {
                case 'labels':
                    $object->labels = PostTypeArgs::mergeLabels($object->labels ?? [], is_array($value) ? $value : []);
                    $object->labels = get_post_type_labels($object);
                    if (is_object($object->labels) && isset($object->labels->name)) {
                        $object->label = $object->labels->name;
                    }
                    break;
                case 'supports':
                    self::applySupports($declaration->slug, $value);
                    break;
                default:
                    if (in_array($key, self::LATE_KEYS, true)) {
                        $object->{$key} = $value;
                    } else {
                        $ignored[] = (string) $key;
                    }
            }
        }

        if ($ignored !== []) {
            $this->addIssue(self::lateIssue($declaration, $ignored));
        }
    }

    /**
     * Make the type's supports exactly the declared list (`false`: none). A
     * string key set to `false` is removed like an unlisted feature.
     */
    private static function applySupports(string $slug, mixed $declared): void
    {
        $wanted = [];
        foreach (is_array($declared) ? $declared : [] as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $wanted[$value] = true;
            } elseif (is_string($key) && $value !== false) {
                $wanted[$key] = $value;
            }
        }

        foreach (array_keys((array) get_all_post_type_supports($slug)) as $feature) {
            if (!array_key_exists($feature, $wanted)) {
                remove_post_type_support($slug, (string) $feature);
            }
        }
        foreach ($wanted as $feature => $args) {
            if ($args === true) {
                add_post_type_support($slug, (string) $feature);
            } else {
                add_post_type_support($slug, (string) $feature, $args);
            }
        }
    }

    /** @param list<string> $ignored */
    private static function lateIssue(Declaration $declaration, array $ignored): Issue
    {
        return new Issue(
            Issue::WARNING,
            'late_override_ignored',
            sprintf(
                /* translators: 1: post type slug, 2: comma-separated keys */
                __('"%1$s" was registered before its override could be applied, so these keys were ignored: %2$s. Declare the override before init priority 0, for example in a declaration file.', 'proto-blocks'),
                $declaration->slug,
                implode(', ', $ignored)
            ),
            $declaration->source,
            $declaration->slug,
            Declaration::TYPE
        );
    }

    /**
     * Directory and URL theme-relative icons resolve against: the
     * declaration's root with its theme URL, the stylesheet directory for a
     * PHP declaration, and no URL for a filter-added root.
     *
     * @return array{0: string, 1: string}
     */
    private static function iconBase(Declaration $declaration): array
    {
        if ($declaration->root === null) {
            return [(string) get_stylesheet_directory(), (string) get_stylesheet_directory_uri()];
        }
        if ($declaration->root === self::real((string) get_stylesheet_directory())) {
            return [$declaration->root, (string) get_stylesheet_directory_uri()];
        }
        if ($declaration->root === self::real((string) get_template_directory())) {
            return [$declaration->root, (string) get_template_directory_uri()];
        }

        return [$declaration->root, ''];
    }

    /** realpath(), except that an empty path is no directory (not the cwd). */
    private static function real(string $path): ?string
    {
        $real = $path === '' ? false : realpath($path);

        return $real === false ? null : $real;
    }

    private function addIssue(Issue $issue): void
    {
        $this->issues[Manager::issueKey($issue)] ??= $issue;
    }
}
