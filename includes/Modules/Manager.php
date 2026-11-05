<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules;

use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\DoubleDeclarationNotice;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\OriginTracker;
use ProtoBlocks\ContentModel\PhpDeclarations;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\RewriteHash;
use ProtoBlocks\Modules\Fields\FieldsModule;
use ProtoBlocks\Modules\Redirects\RedirectsModule;
use ProtoBlocks\Modules\Scripts\ScriptsModule;

/**
 * Boots the content-model modules the `proto_blocks_modules` filter leaves
 * enabled, flushes rewrite rules when the registered slugs change, and wires
 * the origin tracker and the double-declaration notice.
 *
 * Booted on `after_setup_theme` priority 1, so a theme's functions.php can
 * filter the module states, and still before core's `init` 0 re-registration
 * of the built-in types.
 */
final class Manager
{
    public const FILTER = 'proto_blocks_modules';

    public const DEFAULTS = ['post_types' => true, 'taxonomies' => true, 'fields' => true, 'options_pages' => true, 'redirects' => true, 'scripts' => true];

    /** @var array<string, bool> */
    private array $states = self::DEFAULTS;

    private ?PostTypes $postTypes = null;
    private ?Taxonomies $taxonomies = null;
    private ?FieldsModule $fields = null;
    private ?OptionsPages $optionsPages = null;
    private ?RedirectsModule $redirects = null;
    private ?ScriptsModule $scripts = null;
    private ?OriginTracker $originTracker = null;
    private bool $booted = false;

    public function __construct(private readonly Registry $registry)
    {
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $filtered = apply_filters(self::FILTER, self::DEFAULTS);
        $filtered = is_array($filtered) ? $filtered : [];
        foreach (self::DEFAULTS as $id => $default) {
            $this->states[$id] = array_key_exists($id, $filtered) ? (bool) $filtered[$id] : $default;
        }

        if ($this->states['post_types']) {
            $this->postTypes = new PostTypes($this->registry);
            $this->postTypes->boot();
        }
        if ($this->states['taxonomies']) {
            $this->taxonomies = new Taxonomies($this->registry);
            $this->taxonomies->boot();
        }
        if ($this->states['options_pages']) {
            // Before the Fields module: its boot() takes the stores map, which
            // the options bridge filters.
            $this->optionsPages = new OptionsPages($this->registry, $this);
            $this->optionsPages->boot();
        }
        if ($this->states['fields']) {
            $this->fields = FieldsModule::wired($this->registry);
            FieldsModule::setInstance($this->fields);
            $this->fields->boot();
        } else {
            // FieldsModule::instance() must not hand out a live module.
            FieldsModule::setInstance(FieldsModule::disabled());
        }
        if ($this->states['redirects']) {
            $this->redirects = new RedirectsModule($this->registry);
            RedirectsModule::setInstance($this->redirects);
            $this->redirects->boot();
        } else {
            RedirectsModule::setInstance(null);
        }
        if ($this->states['scripts']) {
            $this->scripts = new ScriptsModule($this->registry);
            ScriptsModule::setInstance($this->scripts);
            $this->scripts->boot();
        } else {
            ScriptsModule::setInstance(null);
        }
        if ($this->postTypes !== null || $this->taxonomies !== null) {
            // After the modules' own wp_loaded work (pending overrides resolve at 10).
            add_action('wp_loaded', [$this, 'maybeFlush'], 20);
        }

        if (OriginTracker::wanted()) {
            $this->originTracker = new OriginTracker();
            $this->originTracker->boot();
        }
        if (is_admin()) {
            add_action('admin_notices', [new DoubleDeclarationNotice($this->registry), 'render']);
        }
    }

    public function isEnabled(string $id): bool
    {
        return $this->states[$id] ?? false;
    }

    /** @return array<string, bool> */
    public function states(): array
    {
        return $this->states;
    }

    /** Null when the module is disabled. */
    public function postTypes(): ?PostTypes
    {
        return $this->postTypes;
    }

    /** Null when the module is disabled. */
    public function taxonomies(): ?Taxonomies
    {
        return $this->taxonomies;
    }

    /** Null when the module is disabled. */
    public function fields(): ?FieldsModule
    {
        return $this->fields;
    }

    /** Null when the module is disabled. */
    public function optionsPages(): ?OptionsPages
    {
        return $this->optionsPages;
    }

    /** Null when the module is disabled. */
    public function redirects(): ?RedirectsModule
    {
        return $this->redirects;
    }

    /** Null when the module is disabled. */
    public function scripts(): ?ScriptsModule
    {
        return $this->scripts;
    }

    /** Null on requests that do not show origins (see OriginTracker::wanted()). */
    public function originTracker(): ?OriginTracker
    {
        return $this->originTracker;
    }

    /**
     * Hooked to `wp_loaded` 20: flush rewrite rules once when the args this
     * request registered or overrode differ from the stored hash.
     */
    public function maybeFlush(): void
    {
        RewriteHash::maybeFlush(
            RewriteHash::compute($this->postTypes?->appliedArgs() ?? [], $this->taxonomies?->appliedArgs() ?? []),
            (string) get_stylesheet()
        );
    }

    /**
     * Declaration issues from the registry and the modules (field group
     * sources included), each reported once.
     *
     * @return list<Issue>
     */
    public function issues(): array
    {
        $out = [];
        $all = [...$this->registry->issues(), ...($this->postTypes?->issues() ?? []), ...($this->taxonomies?->issues() ?? []), ...($this->fields?->issues() ?? []), ...($this->redirects?->issues() ?? []), ...($this->scripts?->issues() ?? [])];
        foreach ($all as $issue) {
            $out[self::issueKey($issue)] ??= $issue;
        }

        return array_values($out);
    }

    /**
     * Seal PHP declarations and make sure the registry reflects every one of
     * them (it may have been built at `init` 0, before a later
     * proto_post_type() call). Idempotent; called at `init` 9 and again at the
     * start of each module's register(). Without $kind it seals post types
     * and taxonomies; the options module seals Declaration::OPTIONS on
     * `admin_menu`.
     *
     * @internal Shared by the modules.
     * @return bool whether the registry was invalidated
     */
    public static function sealDeclarations(Registry $registry, ?string $kind = null): bool
    {
        PhpDeclarations::seal($kind);

        return self::refreshDeclarations($registry);
    }

    /**
     * Like sealDeclarations() without sealing: invalidate the registry when
     * a PHP declaration is missing from it.
     *
     * @internal Shared by the modules.
     * @return bool whether the registry was invalidated
     */
    public static function refreshDeclarations(Registry $registry): bool
    {
        if (!self::reflectsPhpDeclarations($registry)) {
            $registry->invalidate();
            return true;
        }

        return false;
    }

    /** @internal Shared by the modules. */
    public static function issueKey(Issue $issue): string
    {
        return implode("\0", [$issue->level, $issue->code, $issue->source, (string) $issue->slug, (string) $issue->kind, $issue->message]);
    }

    /**
     * Whether every PHP declaration is already in the registry: either as the
     * same object, or beaten by a file with the double recorded.
     */
    private static function reflectsPhpDeclarations(Registry $registry): bool
    {
        $doubles = [];
        foreach ($registry->doubles() as $double) {
            $doubles[$double['kind'] . ':' . $double['slug']] = $double['php'];
        }

        foreach (PhpDeclarations::all() as $kind => $declarations) {
            foreach ($declarations as $slug => $php) {
                $current = $registry->get($kind, $slug);
                if ($current === $php) {
                    continue;
                }
                if (
                    $current !== null && $current->origin === Declaration::ORIGIN_FILE
                    && ($doubles[$kind . ':' . $slug] ?? null) === $php->source
                ) {
                    continue;
                }
                if ($current !== null && self::sameApartFromPostTypes($current, $php)) {
                    continue; // a PHP taxonomy that an inline one added a post type to
                }

                return false;
            }
        }

        return true;
    }

    private static function sameApartFromPostTypes(Declaration $current, Declaration $php): bool
    {
        if ($current->origin !== Declaration::ORIGIN_PHP || $current->source !== $php->source || $current->parentType !== null) {
            return false;
        }
        $a = $current->data;
        $b = $php->data;
        unset($a['post_types'], $b['post_types']);

        return $a === $b;
    }
}
