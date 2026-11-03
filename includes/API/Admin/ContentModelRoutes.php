<?php
/**
 * ContentModelRoutes - shared plumbing for the post type, taxonomy and
 * schema-file REST routes
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\API\Admin;

use ProtoBlocks\Admin\AdminPage;
use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Guardrails;
use ProtoBlocks\ContentModel\Inflector;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\PostTypeArgs;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\SchemaPaths;
use ProtoBlocks\ContentModel\Slug;
use ProtoBlocks\ContentModel\TaxonomyArgs;
use ProtoBlocks\ContentModel\TemplateScaffolder;
use ProtoBlocks\ContentModel\WriteException;
use ProtoBlocks\Core\Plugin;
use ProtoBlocks\Modules\Manager;
use WP_Error;
use WP_REST_Request;

/**
 * Services, list items, error mapping and path display shared by
 * PostTypesRoutes, TaxonomiesRoutes and SchemaFilesRoutes.
 *
 * Every constructor parameter is optional: AdminRestController builds the
 * groups with `new $class()` on every REST request (which is not
 * is_admin()), so each service is resolved from Plugin on first use; tests
 * inject doubles. No response carries an absolute path: paths go through
 * displayPath() and messages through scrub().
 */
abstract class ContentModelRoutes
{
    /** WP_REST_Server::EDITABLE (POST, PUT, PATCH); spelled out so registering needs no core class. */
    protected const EDITABLE = 'POST, PUT, PATCH';

    /** A slug used only to ask the writer where a new declaration would go. */
    private const PROBE_SLUG = 'proto-blocks-new';

    public function __construct(
        private ?Registry $registry = null,
        private ?Manager $modules = null,
        private ?SchemaFileWriter $writer = null,
        private ?Guardrails $guardrails = null,
        private ?TemplateScaffolder $scaffolder = null
    ) {
    }

    abstract public function registerRoutes(string $namespace, callable $permission, callable $writePermission): void;

    // --- services --------------------------------------------------------

    protected function registry(): Registry
    {
        return $this->registry ??= Plugin::getInstance()->getContentModel();
    }

    protected function modules(): Manager
    {
        return $this->modules ??= Plugin::getInstance()->getModules();
    }

    protected function writer(): SchemaFileWriter
    {
        return $this->writer ??= new SchemaFileWriter(SchemaPaths::writableRoots(), AdminPage::fileModsAllowed());
    }

    protected function guardrails(): Guardrails
    {
        return $this->guardrails ??= new Guardrails(
            static fn (string $type): int => self::countPosts($type),
            static fn (string $taxonomy): int => self::countTerms($taxonomy)
        );
    }

    protected function scaffolder(): TemplateScaffolder
    {
        return $this->scaffolder ??= new TemplateScaffolder(
            (string) get_stylesheet_directory(),
            (string) get_template_directory(),
            (bool) wp_is_block_theme(),
            AdminPage::fileModsAllowed()
        );
    }

    /** Posts of $type in every status except auto-draft. */
    public static function countPosts(string $type): int
    {
        $total = 0;
        foreach ((array) wp_count_posts($type) as $status => $count) {
            if ($status !== 'auto-draft') {
                $total += (int) $count;
            }
        }

        return $total;
    }

    /** Terms of $taxonomy, empty ones included; 0 when it is not registered. */
    public static function countTerms(string $taxonomy): int
    {
        if (!taxonomy_exists($taxonomy)) {
            return 0;
        }
        $count = wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);

        return is_numeric($count) ? (int) $count : 0;
    }

    // --- request helpers ---------------------------------------------------

    /**
     * A route placeholder. Read from the URL parameters only: get_param()
     * prefers the JSON body and the query string, which must not be able to
     * retarget a request.
     */
    protected static function urlParam(WP_REST_Request $request, string $key): mixed
    {
        if (method_exists($request, 'get_url_params')) {
            $params = $request->get_url_params();
            if (is_array($params) && array_key_exists($key, $params)) {
                return $params[$key];
            }
        }

        return $request->get_param($key);
    }

    /**
     * Null when $kind/$slug may be used, else a 400. WordPress matches route
     * patterns case-insensitively, so `Product` or `TYPE` reach the handler.
     * With $registered, a reserved slug is accepted (core's own types are
     * readable; nothing is written for them).
     */
    protected static function slugError(mixed $kind, mixed $slug, bool $registered = false): ?WP_Error
    {
        if (!is_string($kind) || !isset(FileLoader::FOLDERS[$kind])) {
            return new WP_Error('proto_blocks_invalid_slug', __('Unknown declaration kind.', 'proto-blocks'), ['status' => 400]);
        }
        if (!is_string($slug)) {
            return new WP_Error('proto_blocks_invalid_slug', __('Invalid slug.', 'proto-blocks'), ['status' => 400]);
        }
        $error = Slug::error($slug, $kind);
        if ($error === null || ($error === 'reserved' && $registered)) {
            return null;
        }

        return new WP_Error(
            'proto_blocks_invalid_slug',
            FileLoader::slugMessage($error, $slug, $kind),
            ['status' => 400, 'reason' => $error]
        );
    }

    protected static function notFound(string $kind): WP_Error
    {
        return new WP_Error(
            'proto_blocks_not_found',
            $kind === Declaration::TYPE
                ? __('No post type with that slug exists.', 'proto-blocks')
                : __('No taxonomy with that slug exists.', 'proto-blocks'),
            ['status' => 404]
        );
    }

    /** A refused or failed write as a REST error; its message carries no absolute path. */
    public static function writeError(WriteException $e): WP_Error
    {
        $data = $e->data;
        unset($data['status']);

        return new WP_Error('proto_blocks_' . $e->errorCode, self::scrub($e->getMessage()), ['status' => $e->status] + $data);
    }

    // --- paths -----------------------------------------------------------------

    /**
     * $path (or "<file>:<line>") for display: relative to ABSPATH, else to
     * the folder above wp-content, else `<theme folder>/...` for a path in a
     * schema root, else its last three segments. Never absolute.
     */
    public static function displayPath(string $path): string
    {
        if ($path === '' || !self::isAbsolute($path)) {
            return $path;
        }
        foreach (self::displayBases() as $base) {
            $relative = AdminRestController::relativePath($path, $base);
            if ($relative !== $path) {
                return $relative;
            }
        }
        $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $path)), static fn (string $p): bool => $p !== ''));

        return implode('/', array_slice($parts, -3));
    }

    /** $text with every known base folder removed from the paths it mentions. */
    public static function scrub(string $text): string
    {
        foreach (self::displayBases() as $base) {
            $text = str_replace(rtrim(str_replace('\\', '/', $base), '/') . '/', '', $text);
        }

        return $text;
    }

    /** @return list<string> */
    private static function displayBases(): array
    {
        $bases = [];
        if (defined('ABSPATH')) {
            $bases[] = (string) \ABSPATH;
        }
        if (defined('WP_CONTENT_DIR')) {
            $bases[] = dirname((string) \WP_CONTENT_DIR);
        }
        if (function_exists('get_stylesheet_directory') && function_exists('get_template_directory')) {
            foreach ([...SchemaPaths::roots(), ...SchemaPaths::writableRoots()] as $root) {
                $bases[] = dirname($root);
            }
        }

        $out = [];
        foreach ($bases as $base) {
            $base = rtrim(str_replace('\\', '/', $base), '/');
            if ($base !== '' && !in_array($base, $out, true)) {
                $out[] = $base;
            }
        }

        return $out;
    }

    private static function isAbsolute(string $path): bool
    {
        return $path[0] === '/' || $path[0] === '\\' || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }

    // --- shapes ------------------------------------------------------------------

    /** @return array{level: string, code: string, message: string, source: string, slug: ?string, kind: ?string} */
    protected static function issueArray(Issue $issue): array
    {
        $out = $issue->toArray();
        $out['source'] = self::displayPath($out['source']);
        $out['message'] = self::scrub($out['message']);

        return $out;
    }

    /** @return list<Issue> registry and module issues, each once */
    protected function allIssues(): array
    {
        return $this->modules()->issues();
    }

    /**
     * Issues of $kind/$slug from the current registry.
     *
     * @return list<array<string, mixed>>
     */
    protected function issuesFor(string $kind, string $slug): array
    {
        $out = [];
        foreach ($this->registry()->issues() as $issue) {
            if ($issue->kind === $kind && $issue->slug === $slug) {
                $out[] = self::issueArray($issue);
            }
        }

        return $out;
    }

    /**
     * Where a new declaration of $kind would be created: the kind folder of
     * the first writable root.
     *
     * @return array{path: string, writable: bool, reason: ?string}
     */
    protected function createTarget(string $kind): array
    {
        $target = $this->writer()->target($kind, self::PROBE_SLUG, null);

        return [
            'path' => $target['path'] === '' ? '' : self::displayPath(dirname($target['path'], 2)),
            'writable' => $target['writable'],
            'reason' => $target['reason'],
        ];
    }

    /**
     * Whether (and where) a save for $declaration can go. An inline taxonomy
     * is edited through its type's file.
     *
     * @return array{path: string, writable: bool, reason: ?string}
     */
    protected function saveTarget(string $kind, string $slug, ?Declaration $declaration): array
    {
        if ($declaration !== null && $declaration->parentType !== null) {
            $parent = $this->registry()->get(Declaration::TYPE, $declaration->parentType);

            return $this->writer()->target(Declaration::TYPE, $declaration->parentType, self::fileDeclaration($parent));
        }

        return $this->writer()->target($kind, $slug, self::fileDeclaration($declaration));
    }

    /** $declaration when it is a standalone file of its own, else null. */
    protected static function fileDeclaration(?Declaration $declaration): ?Declaration
    {
        if ($declaration === null || $declaration->origin !== Declaration::ORIGIN_FILE || $declaration->parentType !== null) {
            return null;
        }

        return $declaration;
    }

    /**
     * Registered objects of $kind, by slug.
     *
     * @return array<string, object>
     */
    protected static function registeredObjects(string $kind): array
    {
        $objects = $kind === Declaration::TYPE ? get_post_types([], 'objects') : get_taxonomies([], 'objects');
        $out = [];
        foreach (is_array($objects) ? $objects : [] as $slug => $object) {
            if (is_object($object)) {
                $out[(string) $slug] = $object;
            }
        }

        return $out;
    }

    protected static function registeredObject(string $kind, string $slug): ?object
    {
        $object = $kind === Declaration::TYPE ? get_post_type_object($slug) : get_taxonomy($slug);

        return is_object($object) ? $object : null;
    }

    /**
     * ListResult for $kind.
     *
     * @return array{moduleEnabled: bool, createTarget: array<string, mixed>, items: list<array<string, mixed>>, issues: list<array<string, mixed>>}
     */
    protected function listResult(string $kind): array
    {
        $objects = self::registeredObjects($kind);
        $declarations = $kind === Declaration::TYPE ? $this->registry()->postTypes() : $this->registry()->taxonomies();
        $issues = $this->allIssues();

        $slugs = [];
        foreach ($objects as $slug => $object) {
            if (isset($declarations[$slug]) || !empty($object->show_ui)) {
                $slugs[$slug] = true;
            }
        }
        foreach (array_keys($declarations) as $slug) {
            $slugs[(string) $slug] = true;
        }

        $items = [];
        foreach (array_keys($slugs) as $slug) {
            $slug = (string) $slug;
            $items[] = $this->listItem($kind, $slug, $objects[$slug] ?? null, $declarations[$slug] ?? null, $issues);
        }
        usort($items, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']) ?: strcmp($a['slug'], $b['slug']));

        $loose = [];
        foreach ($issues as $issue) {
            if (($issue->kind === $kind || $issue->kind === null) && ($issue->slug === null || !isset($slugs[$issue->slug]))) {
                $loose[] = self::issueArray($issue);
            }
        }

        return [
            'moduleEnabled' => $this->modules()->isEnabled($kind === Declaration::TYPE ? 'post_types' : 'taxonomies'),
            'createTarget' => $this->createTarget($kind),
            'items' => $items,
            'issues' => $loose,
        ];
    }

    /**
     * One ListItem. $issues defaults to every current issue.
     *
     * @param list<Issue>|null $issues
     * @return array<string, mixed>
     */
    protected function listItem(string $kind, string $slug, ?object $object, ?Declaration $declaration, ?array $issues = null): array
    {
        $module = $kind === Declaration::TYPE ? $this->modules()->postTypes() : $this->modules()->taxonomies();
        $ownRegistration = in_array($slug, $module?->registered() ?? [], true);
        $override = $declaration !== null
            && (in_array($slug, $module?->overridden() ?? [], true) || ($object !== null && !$ownRegistration));

        [$origin, $originLabel] = $declaration !== null && !$override
            ? self::declarationOrigin($declaration)
            : $this->registrantOrigin($kind, $slug, $object);
        if ($origin === null) {
            [$origin, $originLabel] = $declaration !== null
                ? self::declarationOrigin($declaration)
                : ['plugin', __('Unknown', 'proto-blocks')];
        }

        $double = null;
        foreach ($this->registry()->doubles() as $entry) {
            if ($entry['kind'] === $kind && $entry['slug'] === $slug) {
                $double = ['file' => self::displayPath($entry['file']), 'php' => self::displayPath($entry['php'])];
            }
        }

        if ($declaration === null) {
            $editable = false;
            $reason = 'not_proto';
        } else {
            $target = $this->saveTarget($kind, $slug, $declaration);
            $editable = $target['writable'];
            $reason = $target['reason'];
        }

        $own = [];
        foreach ($issues ?? $this->allIssues() as $issue) {
            if ($issue->kind === $kind && $issue->slug === $slug) {
                $own[] = self::issueArray($issue);
            }
        }

        return [
            'slug' => $slug,
            'label' => self::label($slug, $object, $declaration),
            'origin' => $origin,
            'originLabel' => $originLabel,
            'source' => $declaration !== null ? self::displayPath($declaration->source) : null,
            'override' => $override,
            'double' => $double,
            'count' => $this->guardrails()->contentFor($kind, $slug, null)['count'],
            'editable' => $editable,
            'readOnlyReason' => $editable ? null : $reason,
            'issues' => $own,
        ];
    }

    /** @return array{0: string, 1: string} */
    private static function declarationOrigin(Declaration $declaration): array
    {
        return $declaration->origin === Declaration::ORIGIN_FILE
            ? ['file', __('Proto-Blocks file', 'proto-blocks')]
            : ['php', __('Proto-Blocks PHP', 'proto-blocks')];
    }

    /**
     * Who registered $slug: core, a plugin or a theme; [null, ''] when unknown.
     *
     * @return array{0: ?string, 1: string}
     */
    private function registrantOrigin(string $kind, string $slug, ?object $object): array
    {
        if ($object !== null && !empty($object->_builtin)) {
            return ['core', __('Core', 'proto-blocks')];
        }
        $tracker = $this->modules()->originTracker();
        $origin = $tracker?->originOf($kind, $slug) ?? ['type' => 'unknown', 'name' => null];
        $name = is_string($origin['name']) ? $origin['name'] : '';

        return match ($origin['type']) {
            'core' => ['core', __('Core', 'proto-blocks')],
            /* translators: %s: plugin name */
            'plugin' => ['plugin', sprintf(__('Plugin: %s', 'proto-blocks'), self::pluginName($name))],
            /* translators: %s: theme name */
            'theme' => ['theme', sprintf(__('Theme: %s', 'proto-blocks'), self::themeName($name))],
            default => [null, ''],
        };
    }

    /** The Plugin Name header of the plugin in folder (or single file) $folder; $folder when unknown. */
    private static function pluginName(string $folder): string
    {
        if ($folder === '') {
            return __('Unknown', 'proto-blocks');
        }
        if (!function_exists('get_plugins') && defined('ABSPATH') && is_file(\ABSPATH . 'wp-admin/includes/plugin.php')) {
            require_once \ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $lists = [];
        if (function_exists('get_plugins')) {
            $lists[] = get_plugins();
        }
        if (function_exists('get_mu_plugins')) {
            $lists[] = get_mu_plugins();
        }
        foreach ($lists as $plugins) {
            foreach (is_array($plugins) ? $plugins : [] as $file => $data) {
                $file = (string) $file;
                if (($file === $folder . '.php' || str_starts_with($file, $folder . '/')) && is_string($data['Name'] ?? null) && $data['Name'] !== '') {
                    return $data['Name'];
                }
            }
        }

        return $folder;
    }

    private static function themeName(string $folder): string
    {
        if ($folder !== '' && function_exists('wp_get_theme')) {
            $theme = wp_get_theme($folder);
            $name = $theme->exists() ? $theme->get('Name') : '';
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return $folder !== '' ? $folder : __('Unknown', 'proto-blocks');
    }

    private static function label(string $slug, ?object $object, ?Declaration $declaration): string
    {
        if ($object !== null) {
            if (is_string($object->label ?? null) && $object->label !== '') {
                return $object->label;
            }
            $labels = (array) ($object->labels ?? []);
            if (is_string($labels['name'] ?? null) && $labels['name'] !== '') {
                return $labels['name'];
            }
        }
        if ($declaration !== null) {
            $plural = $declaration->data['plural'] ?? null;
            if (is_string($plural) && $plural !== '') {
                return $plural;
            }
            $name = $declaration->name();
            if ($name !== null && $name !== '') {
                return Inflector::plural($name);
            }
        }

        return $slug;
    }

    /**
     * The resolved args: the registered object's properties (labels
     * included), else what the declaration would register.
     *
     * @return array<string, mixed>|\stdClass
     */
    protected static function effective(string $kind, ?object $object, ?Declaration $declaration): array|\stdClass
    {
        $args = [];
        if ($object !== null) {
            $args = self::plain(get_object_vars($object));
        } elseif ($declaration !== null) {
            if ($kind === Declaration::TYPE) {
                $root = $declaration->root ?? (string) get_stylesheet_directory();
                $args = self::plain(PostTypeArgs::build($declaration, $root, '')['args']);
            } else {
                $args = self::plain(TaxonomyArgs::build($declaration)['args']);
            }
        }

        return is_array($args) && $args !== [] ? $args : new \stdClass();
    }

    /**
     * JSON-safe copy: arrays and plain objects recurse, any other object
     * (closures, controllers) becomes null.
     */
    protected static function plain(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            return array_map([self::class, 'plain'], $value);
        }

        return is_object($value) ? null : $value;
    }

    /**
     * An empty map as a JSON object.
     *
     * @param array<string, mixed> $map
     * @return array<string, mixed>|\stdClass
     */
    protected static function map(array $map): array|\stdClass
    {
        return $map === [] ? new \stdClass() : $map;
    }
}
