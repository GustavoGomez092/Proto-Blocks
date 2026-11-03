<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules;

use ProtoBlocks\Admin\AdminApp;
use ProtoBlocks\API\OptionsValuesRoutes;
use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\MenuIcon;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\Core\Plugin;
use ProtoBlocks\Modules\Fields\FieldsModule;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;
use ProtoBlocks\OptionsPages\ExternalMenus;
use ProtoBlocks\OptionsPages\FieldsBridge;
use ProtoBlocks\OptionsPages\InvalidDeclaration;
use ProtoBlocks\OptionsPages\MenuRegistrar;
use ProtoBlocks\OptionsPages\OptionsFieldStore;
use ProtoBlocks\OptionsPages\OptionsPagesRegistry;
use ProtoBlocks\OptionsPages\OptionsStore;
use ProtoBlocks\OptionsPages\OptionsValues;
use ProtoBlocks\OptionsPages\PageDefinition;
use ProtoBlocks\OptionsPages\PageRenderer;

/**
 * Settings pages (`proto-options/<slug>/page.jsonc`, proto_options_page()).
 *
 * - `proto_blocks_declaration_errors` 10: the options-page checks the writer
 *   and `wp proto-blocks validate` run (protected option names, reserved
 *   child slugs, a `shared_storage` warning).
 * - `wp_loaded` 5: pick up proto_options_page() calls made after the
 *   registry was built at `init` 9, on every request type (REST saves and
 *   proto_field() need them). Outside the admin this also seals the kind.
 * - `admin_menu` 10: seal proto_options_page() on admin requests (a call on
 *   `admin_menu` priority 9 still reaches the menu, but not REST saves or
 *   proto_field() on other requests).
 * - `admin_menu` 20: register the menus; each page renders the React
 *   `options-page` screen, gated on the page's own capability.
 * - `proto_blocks_admin_app_hooks`: the pages' hook suffixes, so the admin
 *   bundle loads on them under any parent menu.
 * - `rest_api_init`: `proto-blocks/v1/options/<slug>`.
 * - `admin_menu` PHP_INT_MAX (admin only): ExternalMenus capture, which
 *   itself does nothing outside the Options Pages screen.
 * - With the Fields module on: `proto_blocks_field_groups` and
 *   `proto_blocks_field_stores` (FieldsBridge), so `proto_field('x',
 *   'option:<page>')` reads the pages. The Manager boots this module before
 *   the Fields module, whose boot() takes the stores map.
 *
 * Values, rendering and the REST routes use Phase 2's library classes
 * directly, so pages render and save with the Fields module switched off.
 */
final class OptionsPages implements ModuleInterface
{
    public const ID = 'options_pages';

    private OptionsPagesRegistry $pages;
    private ?TypeRegistry $types = null;
    private ?OptionsValues $values = null;
    private ?OptionsFieldStore $fieldStore = null;
    private ?OptionsValuesRoutes $routes = null;
    private ?MenuRegistrar $menus = null;
    private ?PageRenderer $renderer = null;
    private bool $booted = false;
    private bool $menusRegistered = false;

    public function __construct(private readonly Registry $declarations, private readonly Manager $manager)
    {
        $this->pages = new OptionsPagesRegistry($declarations);
    }

    public function id(): string
    {
        return self::ID;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        add_filter('proto_blocks_declaration_errors', [$this, 'declarationErrors'], 10, 4);
        add_action('wp_loaded', [$this, 'loaded'], 5);
        add_action('admin_menu', [$this, 'seal'], 10);
        add_action('admin_menu', [$this, 'registerMenus'], 20);
        add_filter('proto_blocks_admin_app_hooks', [$this, 'appHooks']);
        add_action('rest_api_init', [$this->routes(), 'registerRoutes']);

        if (function_exists('is_admin') && is_admin()) {
            (new ExternalMenus())->boot();
        }

        if ($this->fieldsEnabled()) {
            $bridge = new FieldsBridge($this->pages, $this->fieldStore(), $this->types());
            add_filter('proto_blocks_field_groups', [$bridge, 'filterGroups']);
            add_filter('proto_blocks_field_stores', [$bridge, 'filterStores']);
        }
    }

    public function registry(): OptionsPagesRegistry
    {
        return $this->pages;
    }

    public function values(): OptionsValues
    {
        return $this->values ??= new OptionsValues(
            $this->pages,
            new OptionsStore(),
            $this->types(),
            fn (string $slug): array => $this->fieldsEnabled()
                ? FieldsModule::instance()->index()->forRef(ObjectRef::option($slug), ['options_page' => $slug])
                : []
        );
    }

    /**
     * Registry findings (except `shared_storage`, which the declaration
     * errors filter reports for each page involved), menu warnings from this
     * request's `admin_menu`, and standalone fields a page dropped.
     *
     * @return list<Issue>
     */
    public function issues(): array
    {
        $out = [];
        foreach ($this->pages->issues() as $issue) {
            if ($issue->code !== 'shared_storage') {
                $out[] = $issue;
            }
        }

        $all = $this->pages->all();
        foreach ($this->menus?->warnings() ?? [] as $warning) {
            if ($warning['level'] !== Issue::WARNING) {
                continue; // info entries (child_of_submenu) are not findings
            }
            $out[] = new Issue(Issue::WARNING, $warning['code'], $warning['message'], (string) ($all[$warning['slug']]->source ?? ''), $warning['slug'], Declaration::OPTIONS);
        }

        foreach ($all as $slug => $page) {
            foreach ($this->values()->warnings($page) as $message) {
                $out[] = new Issue(Issue::WARNING, 'field_skipped', $message, (string) $page->source, (string) $slug, Declaration::OPTIONS);
            }
        }

        return $out;
    }

    /**
     * Hooked to `admin_menu` 10: later proto_options_page() calls are ignored
     * with a warning. A page declared after the registry was built is picked
     * up here.
     */
    public function seal(): void
    {
        if (Manager::sealDeclarations($this->declarations, Declaration::OPTIONS)) {
            $this->refreshPages();
        }
    }

    /** The pages, and the Fields index that bridges them to proto_field(). */
    private function refreshPages(): void
    {
        $this->pages->refresh();
        $this->manager->fields()?->index()->reload();
    }

    /**
     * Hooked to `wp_loaded` 5: the registry reflects every page declared so
     * far. Admin requests stay open until `admin_menu` 10; other requests
     * (REST, front end, cron) are sealed here.
     */
    public function loaded(): void
    {
        if (function_exists('is_admin') && is_admin()) {
            if (Manager::refreshDeclarations($this->declarations)) {
                $this->refreshPages();
            }
            return;
        }
        $this->seal();
    }

    /** Hooked to `admin_menu` 20. */
    public function registerMenus(): void
    {
        $this->menusRegistered = true;
        $this->menus()->register($this->pages->all());
    }

    /**
     * `proto_blocks_admin_app_hooks` callback.
     *
     * @param mixed $hooks
     * @return list<string>
     */
    public function appHooks($hooks): array
    {
        $hooks = is_array($hooks) ? array_values($hooks) : [];
        if (!$this->menusRegistered) {
            return $hooks;
        }

        return array_values(array_unique([...$hooks, ...array_values($this->menus()->hookSuffixes())]));
    }

    /**
     * `proto_blocks_declaration_errors` callback: options-page findings for
     * kind `options`; other kinds pass through.
     *
     * @param mixed $errors
     * @param mixed $kind
     * @param mixed $data
     * @param mixed $slug
     * @return list<array<string, mixed>>
     */
    public function declarationErrors($errors, $kind = '', $data = [], $slug = ''): array
    {
        $errors = is_array($errors) ? array_values($errors) : [];
        if ($kind !== OptionsPagesRegistry::KIND || !is_array($data)) {
            return $errors;
        }
        $slug = is_string($slug) ? $slug : '';
        $count = count($errors);
        $errors = OptionsPagesRegistry::declarationErrors($errors, OptionsPagesRegistry::KIND, $data, $slug);
        if (count($errors) !== $count || $slug === '') {
            return $errors;
        }

        return [...$errors, ...$this->sharedStorage($slug, $data)];
    }

    /** The menu callback of every page (PageRenderer checks the page capability). */
    public function renderPage(PageDefinition $page): void
    {
        $this->renderer()->render($page);
    }

    // --- services ------------------------------------------------------------

    private function fieldsEnabled(): bool
    {
        return $this->manager->isEnabled('fields');
    }

    private function types(): TypeRegistry
    {
        return $this->types ??= TypeRegistry::withCoreTypes();
    }

    private function fieldStore(): OptionsFieldStore
    {
        return $this->fieldStore ??= new OptionsFieldStore($this->pages, new OptionsStore());
    }

    private function routes(): OptionsValuesRoutes
    {
        return $this->routes ??= new OptionsValuesRoutes($this->pages, $this->values());
    }

    private function menus(): MenuRegistrar
    {
        return $this->menus ??= new MenuRegistrar(
            [$this, 'resolveIcon'],
            [$this, 'renderPage'],
            [self::class, 'capabilityKnown']
        );
    }

    private function renderer(): PageRenderer
    {
        return $this->renderer ??= new PageRenderer(
            $this->values(),
            $this->fieldStore(),
            $this->types(),
            Plugin::getInstance()->getAdminApp() ?? new AdminApp(null)
        );
    }

    /**
     * MenuRegistrar's icon resolver: Phase 1's MenuIcon rules against the
     * folder the declaration came from. No icon: the generic dashicon.
     *
     * @internal
     * @return array{value: string, warning: ?string}
     */
    public function resolveIcon(PageDefinition $page): array
    {
        $icon = trim((string) $page->icon);
        if ($icon === '') {
            return ['value' => '', 'warning' => null];
        }
        [$dir, $url] = self::iconBase($page->root);
        $resolved = MenuIcon::resolve($icon, $dir, $url);
        if ($resolved['warning'] !== null) {
            return [
                'value' => '',
                'warning' => sprintf(
                    /* translators: %s: declared icon value */
                    __('The menu icon "%s" could not be used; the generic icon is shown. Use a dashicon or an SVG file inside the theme.', 'proto-blocks'),
                    $icon
                ),
            ];
        }

        return ['value' => $resolved['icon'], 'warning' => null];
    }

    /**
     * Whether any role grants $capability (`exist` is held by every user).
     * Unknown capabilities only warn: WordPress denies them, so the page
     * fails closed.
     *
     * @internal
     */
    public static function capabilityKnown(string $capability): bool
    {
        if ($capability === 'exist' || !function_exists('wp_roles')) {
            return true;
        }
        $roles = wp_roles()->roles ?? [];
        foreach (is_array($roles) ? $roles : [] as $role) {
            if (is_array($role) && !empty($role['capabilities'][$capability])) {
                return true;
            }
        }

        return false;
    }

    // --- internals -----------------------------------------------------------

    /**
     * One `shared_storage` warning per option name the declaration would
     * write that another page (not this one, not its children) also writes.
     *
     * @param array<string|int, mixed> $data
     * @return list<array{path: string, message: string, level: string, code: string}>
     */
    private function sharedStorage(string $slug, array $data): array
    {
        try {
            $page = PageDefinition::fromArray($slug, $data, Declaration::ORIGIN_FILE);
        } catch (InvalidDeclaration) {
            return [];
        }

        $owners = [];
        foreach ($this->pages->all() as $other) {
            if ($other->slug === $slug || $other->parentSlug === $slug) {
                continue;
            }
            foreach (self::optionNames($other) as $name) {
                $owners[strtolower($name)] ??= $other->slug;
            }
        }

        $out = [];
        foreach ([$page, ...$page->children] as $own) {
            foreach (self::optionNames($own) as $name) {
                $owner = $owners[strtolower($name)] ?? null;
                if ($owner === null) {
                    continue;
                }
                $out[] = [
                    'path' => '/storage',
                    'message' => sprintf(
                        /* translators: 1: page slug, 2: option name, 3: other page slug */
                        __('The page "%1$s" stores values in the option "%2$s", which the page "%3$s" also uses. Saving one page may overwrite fields of the same name on the other; give each page its own option.', 'proto-blocks'),
                        $own->slug,
                        $name,
                        $owner
                    ),
                    'level' => Issue::WARNING,
                    'code' => 'shared_storage',
                ];
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function optionNames(PageDefinition $page): array
    {
        if ($page->storageMode === PageDefinition::MODE_ARRAY && $page->storageOption !== null) {
            return [$page->storageOption];
        }

        return array_values(array_unique(array_map(
            static fn (string|int $field): string => $page->optionName((string) $field),
            array_keys($page->fields())
        )));
    }

    /**
     * Folder and URL an icon path resolves against (as PostTypes does).
     *
     * @return array{0: string, 1: string}
     */
    private static function iconBase(?string $root): array
    {
        $stylesheet = (string) get_stylesheet_directory();
        if ($root === null) {
            return [$stylesheet, (string) get_stylesheet_directory_uri()];
        }
        if ($root === self::real($stylesheet)) {
            return [$root, (string) get_stylesheet_directory_uri()];
        }
        if ($root === self::real((string) get_template_directory())) {
            return [$root, (string) get_template_directory_uri()];
        }

        return [$root, ''];
    }

    private static function real(string $path): ?string
    {
        $real = $path === '' ? false : realpath($path);

        return $real === false ? null : $real;
    }
}
