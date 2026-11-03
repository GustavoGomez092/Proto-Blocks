<?php
/**
 * OptionsPagesRoutes - REST routes for the Options Pages screen
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\API\Admin;

use ProtoBlocks\Admin\AdminPage;
use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\SchemaPaths;
use ProtoBlocks\Core\Plugin;
use ProtoBlocks\OptionsPages\ExternalMenus;
use ProtoBlocks\OptionsPages\OptionsPagesRegistry;
use ProtoBlocks\OptionsPages\PageDefinition;
use WP_Error;
use WP_REST_Request;

/**
 * admin/options-pages: the settings pages declared in proto-options files and
 * proto_options_page() calls, plus the admin menu around them.
 *
 * - GET admin/options-pages          → list, external menu entries, warnings
 * - GET admin/options-pages/<slug>   → one page, its declaration, menu parents, role capabilities
 *
 * Read only, and only about declared pages: the detail route resolves a slug
 * through the registry and never accepts an option name, so it cannot be used
 * to read an option; no option value is returned at all. Paths are shown
 * relative to ABSPATH, never absolute.
 */
final class OptionsPagesRoutes
{
    private const SLUG_ROUTE = '(?P<slug>[a-z][a-z0-9-]{0,63})';
    private const SLUG_RE = '/^[a-z][a-z0-9-]{0,63}\z/D';

    /** A slug used only to ask the writer where a new declaration would go. */
    private const PROBE_SLUG = 'new-options-page';

    private ?OptionsPagesRegistry $pages;
    private ?Registry $declarations;
    private ?SchemaFileWriter $writer;
    private ?ExternalMenus $external;
    private bool $pagesResolved;

    public function __construct(
        ?OptionsPagesRegistry $registry = null,
        ?Registry $declarations = null,
        ?SchemaFileWriter $writer = null,
        ?ExternalMenus $external = null
    ) {
        $this->pages = $registry;
        $this->pagesResolved = $registry !== null;
        $this->declarations = $declarations;
        $this->writer = $writer;
        $this->external = $external;
    }

    public function registerRoutes(string $namespace, callable $permission, callable $writePermission): void
    {
        $base = '/' . AdminRestController::BASE . '/options-pages';

        register_rest_route($namespace, $base, [
            [
                'methods' => 'GET',
                'callback' => [$this, 'index'],
                'permission_callback' => $permission,
            ],
        ]);
        register_rest_route($namespace, $base . '/' . self::SLUG_ROUTE, [
            [
                'methods' => 'GET',
                'callback' => [$this, 'show'],
                'permission_callback' => $permission,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public function index(): array
    {
        $registry = $this->pageRegistry();
        $pages = $registry?->all() ?? [];
        $external = $this->external()->get();
        $titles = self::parentTitles($pages, $external['parents']);

        $rows = [];
        foreach ($pages as $page) {
            $rows[] = $this->rowFor($page, $titles);
        }

        $externalRows = array_values(array_filter(
            $external['pages'],
            static fn (array $row): bool => !isset($pages[$row['slug']])
        ));

        return [
            'moduleEnabled' => $registry !== null,
            'createTarget' => $this->createTarget(),
            'pages' => $rows,
            'external' => $externalRows,
            'warnings' => $this->warnings(),
        ];
    }

    /** @return array<string, mixed>|WP_Error */
    public function show(WP_REST_Request $request)
    {
        // WordPress matches route patterns case-insensitively: re-check the case.
        $slug = self::urlParam($request, 'slug');
        if (!is_string($slug) || preg_match(self::SLUG_RE, $slug) !== 1) {
            return new WP_Error('proto_blocks_invalid_slug', __('Invalid slug.', 'proto-blocks'), ['status' => 400]);
        }
        $registry = $this->pageRegistry();
        $page = $registry?->get($slug);
        if ($registry === null || $page === null) {
            return new WP_Error('proto_blocks_options_page_not_found', __('No options page with that slug is declared.', 'proto-blocks'), ['status' => 404]);
        }

        $external = $this->external()->get();
        $titles = self::parentTitles($registry->all(), $external['parents']);

        // A child page is declared inside its parent: the parent's declaration is the one to edit.
        $declaration = $this->declarations()->get(Declaration::OPTIONS, $page->parentSlug ?? $page->slug);

        $parents = [];
        foreach ($titles as $parentSlug => $title) {
            if ((string) $parentSlug !== $page->slug) {
                $parents[] = ['slug' => (string) $parentSlug, 'title' => $title];
            }
        }

        return [
            'page' => $this->rowFor($page, $titles),
            'declaration' => $declaration !== null ? $declaration->data : new \stdClass(),
            'parents' => $parents,
            'capabilities' => self::capabilities(),
        ];
    }

    /**
     * One OptionsPageRow.
     *
     * A page declared in PHP is never editable (there is no file to write);
     * its readOnlyReason is then the writer's reason for a new file, or null
     * when "Create file" would work.
     *
     * @param array{file: string, php: string}|null                $double
     * @param array{path: string, writable: bool, reason: ?string} $target       From SchemaFileWriter::target('options', slug, declaration).
     * @param array<string, string>                                $parentTitles Menu parent slug => title, for menuLabel().
     * @return array<string, mixed>
     */
    public static function row(PageDefinition $p, ?array $double, array $target, array $parentTitles = []): array
    {
        $isFile = $p->origin === Declaration::ORIGIN_FILE;
        $editable = $isFile && $target['writable'];

        return [
            'slug' => $p->slug,
            'title' => $p->title,
            'menu' => $p->menu,
            'menuLabel' => self::menuLabel($p->menu, $parentTitles),
            'capability' => $p->capability,
            'storage' => $p->storageMode === PageDefinition::MODE_ARRAY ? 'Array: ' . (string) $p->storageOption : 'Per field',
            'origin' => $isFile ? 'file' : 'php',
            'originLabel' => $isFile ? __('Proto-Blocks file', 'proto-blocks') : __('Proto-Blocks PHP', 'proto-blocks'),
            'double' => $double === null ? null : [
                'file' => ContentModelRoutes::displayPath($double['file']),
                'php' => ContentModelRoutes::displayPath($double['php']),
            ],
            'source' => $p->source === null ? null : ContentModelRoutes::displayPath($p->source),
            'editable' => $editable,
            'readOnlyReason' => $editable ? null : $target['reason'],
            'children' => array_map(static fn (PageDefinition $c): string => $c->slug, $p->children),
            'warnings' => [],
            'url' => self::url($p),
        ];
    }

    /**
     * Where a page sits in the menu, in words.
     *
     * @param array<string, string> $parentTitles Menu parent slug => title
     */
    public static function menuLabel(string $menu, array $parentTitles): string
    {
        if ($menu === 'top') {
            return __('Top level', 'proto-blocks');
        }
        if (isset($parentTitles[$menu])) {
            /* translators: %s: title of the menu the page sits under */
            return sprintf(__('Under %s', 'proto-blocks'), $parentTitles[$menu]);
        }

        /* translators: %s: menu slug that no longer exists */
        return sprintf(__('Under %s (missing)', 'proto-blocks'), $menu);
    }

    // --- internals ---------------------------------------------------------

    /**
     * @param array<string, string> $titles
     * @return array<string, mixed>
     */
    private function rowFor(PageDefinition $page, array $titles): array
    {
        $owner = $page->parentSlug ?? $page->slug;
        $declaration = $this->declarations()->get(Declaration::OPTIONS, $owner);
        $fileDeclaration = $declaration !== null && $declaration->origin === Declaration::ORIGIN_FILE ? $declaration : null;
        $target = $this->writer()->target(Declaration::OPTIONS, $owner, $fileDeclaration);

        $double = null;
        foreach ($this->declarations()->doubles() as $entry) {
            if ($entry['kind'] === Declaration::OPTIONS && $entry['slug'] === $owner) {
                $double = ['file' => $entry['file'], 'php' => $entry['php']];
            }
        }

        $row = self::row($page, $double, $target, $titles);
        foreach ($this->pageRegistry()?->warnings() ?? [] as $warning) {
            if ($warning['slug'] === $page->slug || $warning['slug'] === $owner) {
                $row['warnings'][] = ContentModelRoutes::scrub($warning['message']);
            }
        }
        $row['warnings'] = array_values(array_unique($row['warnings']));

        return $row;
    }

    /** @return list<array{slug: string, source: ?string, message: string}> */
    private function warnings(): array
    {
        $out = [];
        foreach ($this->pageRegistry()?->warnings() ?? [] as $warning) {
            $out[] = [
                'slug' => $warning['slug'],
                'source' => $warning['source'] === null ? null : ContentModelRoutes::displayPath($warning['source']),
                'message' => ContentModelRoutes::scrub($warning['message']),
            ];
        }

        return $out;
    }

    /** @return array{path: string, writable: bool, reason: ?string} */
    private function createTarget(): array
    {
        $target = $this->writer()->target(Declaration::OPTIONS, self::PROBE_SLUG, null);
        // The path is <root>/proto-options/<slug>/page.jsonc: two levels up is the kind folder.

        return [
            'path' => $target['path'] === '' ? '' : ContentModelRoutes::displayPath(dirname($target['path'], 2)),
            'writable' => $target['writable'],
            'reason' => $target['reason'],
        ];
    }

    /**
     * Menu parents by slug: the top-level menus WordPress has, then the
     * declared pages.
     *
     * @param array<string, PageDefinition>            $pages
     * @param list<array{slug: string, title: string}> $externalParents
     * @return array<string, string>
     */
    private static function parentTitles(array $pages, array $externalParents): array
    {
        $titles = [];
        foreach ($externalParents as $parent) {
            $titles[$parent['slug']] = $parent['title'];
        }
        foreach ($pages as $slug => $page) {
            $titles[(string) $slug] ??= $page->title;
        }

        return $titles;
    }

    /** The admin URL of a page. */
    private static function url(PageDefinition $p): string
    {
        $parent = $p->menu;
        if ($parent !== 'top' && str_contains($parent, '.php')) {
            return admin_url($parent . (str_contains($parent, '?') ? '&' : '?') . 'page=' . rawurlencode($p->slug));
        }

        return admin_url('admin.php?page=' . rawurlencode($p->slug));
    }

    /** @return list<string> every capability any role has, sorted */
    private static function capabilities(): array
    {
        if (!function_exists('wp_roles')) {
            return [];
        }
        $roles = wp_roles();
        $caps = [];
        foreach (is_object($roles) && is_array($roles->roles ?? null) ? $roles->roles : [] as $role) {
            foreach (is_array($role) && is_array($role['capabilities'] ?? null) ? $role['capabilities'] : [] as $cap => $granted) {
                if ($granted && $cap !== '') {
                    $caps[(string) $cap] = true;
                }
            }
        }
        $names = array_map('strval', array_keys($caps));
        sort($names, SORT_STRING);

        return $names;
    }

    /** A route placeholder, read from the URL parameters only (never the body or query string). */
    private static function urlParam(WP_REST_Request $request, string $key): mixed
    {
        if (method_exists($request, 'get_url_params')) {
            $params = $request->get_url_params();
            if (is_array($params) && array_key_exists($key, $params)) {
                return $params[$key];
            }
        }

        return $request->get_param($key);
    }

    // --- services ------------------------------------------------------------

    /** Null when the options pages module is disabled. */
    private function pageRegistry(): ?OptionsPagesRegistry
    {
        if (!$this->pagesResolved) {
            $this->pagesResolved = true;
            $modules = Plugin::getInstance()->getModules();
            // Task 11 adds Manager::optionsPages(); until then build it over the content model.
            $this->pages = method_exists($modules, 'optionsPages')
                ? $modules->optionsPages()
                : new OptionsPagesRegistry($this->declarations());
        }

        return $this->pages;
    }

    private function declarations(): Registry
    {
        return $this->declarations ??= Plugin::getInstance()->getContentModel();
    }

    private function writer(): SchemaFileWriter
    {
        return $this->writer ??= new SchemaFileWriter(SchemaPaths::writableRoots(), AdminPage::fileModsAllowed());
    }

    private function external(): ExternalMenus
    {
        return $this->external ??= new ExternalMenus();
    }
}
