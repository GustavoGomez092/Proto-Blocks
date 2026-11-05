<?php

declare(strict_types=1);

namespace ProtoBlocks\API\Admin;

use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\SchemaPaths;
use ProtoBlocks\ContentModel\WriteException;
use ProtoBlocks\Modules\Scripts\Definition\ScriptNormalizer;
use ProtoBlocks\Modules\Scripts\Definition\ScriptValidator;
use ProtoBlocks\Modules\Scripts\Definition\Targets;
use ProtoBlocks\Modules\Scripts\ScriptsModule;
use ProtoBlocks\Modules\Scripts\SnippetFiles;
use WP_Error;
use WP_REST_Request;

/**
 * REST routes of the scripts admin screen (spec §7).
 *
 * - GET admin/scripts (permission) → {moduleEnabled, createTarget, canWrite,
 *   writeReason 'file_mods'|'multisite'|'unfiltered_html'|null, roles
 *   [{slug, name}], items: ScriptRow[], issues: Issue[]}
 * - GET admin/scripts/<slug> (permission) → FileResult of
 *   admin/schema-files/script/<slug> (exists, path, text, hash, data,
 *   writable, reason) + {snippets: {head, bodyOpen, bodyClose}, item:
 *   ScriptRow|null, canWrite, writeReason}; query `name` seeds the template
 * - POST admin/scripts/<slug> (writePermission AND unfiltered_html), body
 *   {text: string, hash: string|null, snippets?: {head?, bodyOpen?,
 *   bodyClose?: string}} → {hash, path, created, warnings: Issue[]}. Writes
 *   script.jsonc through SchemaFileWriter (validation, hash, confinement,
 *   atomic swap), then each posted snippet through SnippetFiles (an empty
 *   string removes the file). Every snippet is checked before anything is
 *   written. Errors: 400 proto_blocks_invalid_slug / _invalid_request /
 *   _invalid_jsonc / _schema_invalid / _php_in_snippet {location},
 *   403 proto_blocks_unfiltered_html / _file_mods / _multisite /
 *   _path_outside_root / _not_writable, 409 proto_blocks_conflict {hash},
 *   413 proto_blocks_file_too_large {location?}, 500 proto_blocks_write_failed,
 *   404 proto_blocks_module_disabled.
 *
 * DELETE and plain metadata reads go through admin/schema-files/script/<slug>
 * (ScriptsModule guards those for unfiltered_html and removes the snippet
 * files with the folder).
 *
 * ScriptRow {slug, title, enabled, priority, priorities, locations (the
 * locations with code: 'head'|'bodyOpen'|'bodyClose'), files {head:
 * 'head.html'|null, ...}, where, whereSummary, origin 'file'|'php',
 * originLabel, source, double {file, php}|null, editable, readOnlyReason,
 * issues: Issue[]}. Paths are never absolute.
 */
final class ScriptsRoutes extends ContentModelRoutes
{
    private const SLUG_ROUTE = '(?P<slug>[a-z][a-z0-9_-]{0,63})';

    private bool $moduleResolved;

    public function __construct(
        private ?ScriptsModule $module = null,
        ?SchemaFileWriter $writer = null,
        private ?SnippetFiles $snippets = null,
        ?Registry $registry = null
    ) {
        parent::__construct($registry, null, $writer);
        $this->moduleResolved = $module !== null;
    }

    public function registerRoutes(string $namespace, callable $permission, callable $writePermission): void
    {
        $base = '/' . AdminRestController::BASE . '/scripts';
        $write = static function () use ($writePermission) {
            $allowed = $writePermission();
            if ($allowed !== true) {
                return $allowed;
            }

            return current_user_can('unfiltered_html') ? true : ScriptsModule::unfilteredHtmlError();
        };

        register_rest_route($namespace, $base, [
            ['methods' => 'GET', 'callback' => [$this, 'index'], 'permission_callback' => $permission],
        ]);
        register_rest_route($namespace, $base . '/' . self::SLUG_ROUTE, [
            ['methods' => 'GET', 'callback' => [$this, 'show'], 'permission_callback' => $permission],
            ['methods' => 'POST', 'callback' => [$this, 'save'], 'permission_callback' => $write],
        ]);
    }

    // --- GET admin/scripts ---------------------------------------------------

    /** @return array<string, mixed> */
    public function index(): array
    {
        $module = $this->module();
        $writeReason = ScriptsModule::writeReason();
        $createTarget = SchemaFileWriter::isExtended(ScriptsModule::KIND)
            ? $this->createTarget(ScriptsModule::KIND)
            : ['path' => '', 'writable' => false, 'reason' => null];
        $base = [
            'moduleEnabled' => $module !== null,
            'createTarget' => $createTarget,
            'canWrite' => $writeReason === null && $createTarget['writable'],
            'writeReason' => $writeReason,
            'roles' => self::roles(),
        ];
        if ($module === null) {
            return $base + ['items' => [], 'issues' => []];
        }

        $issues = $module->issues();
        $items = [];
        foreach ($module->scripts()->all() as $script) {
            $items[] = $this->row($script, $issues, $writeReason);
        }

        return $base + [
            'items' => $items,
            'issues' => array_map([self::class, 'issueArray'], $issues),
        ];
    }

    // --- GET admin/scripts/<slug> ------------------------------------------------

    /** @return array<string, mixed>|WP_Error */
    public function show(WP_REST_Request $request)
    {
        $slug = self::urlParam($request, 'slug');
        $error = self::scriptSlugError($slug);
        if ($error !== null) {
            return $error;
        }
        /** @var string $slug */
        $module = $this->module();
        if ($module === null || !SchemaFileWriter::isExtended(ScriptsModule::KIND)) {
            return self::disabled();
        }

        $inner = new WP_REST_Request('GET');
        $inner->set_param('kind', ScriptsModule::KIND);
        $inner->set_param('slug', $slug);
        $name = $request->get_param('name');
        if (is_string($name)) {
            $inner->set_param('name', $name);
        }
        $file = (new SchemaFilesRoutes($this->registry(), null, $this->writer()))->get($inner);
        if ($file instanceof WP_Error) {
            return $file;
        }

        $script = $module->scripts()->get($slug);
        $writeReason = ScriptsModule::writeReason();

        return $file + [
            'snippets' => $this->snippetsFor($slug, $script, is_array($file['data']) ? $file['data'] : []),
            'item' => $script === null ? null : $this->row($script, $module->issues(), $writeReason),
            'canWrite' => $writeReason === null && (bool) $file['writable'],
            'writeReason' => $writeReason,
        ];
    }

    // --- POST admin/scripts/<slug> -------------------------------------------------

    /** @return array<string, mixed>|WP_Error */
    public function save(WP_REST_Request $request)
    {
        $slug = self::urlParam($request, 'slug');
        $error = self::scriptSlugError($slug);
        if ($error !== null) {
            return $error;
        }
        /** @var string $slug */
        $module = $this->module();
        if ($module === null || !SchemaFileWriter::isExtended(ScriptsModule::KIND)) {
            return self::disabled();
        }
        if (!current_user_can('unfiltered_html')) {
            return ScriptsModule::unfilteredHtmlError();
        }

        $text = $request->get_param('text');
        $hash = $request->get_param('hash');
        $snippets = $request->get_param('snippets') ?? [];
        if (!is_string($text) || ($hash !== null && !is_string($hash)) || !is_array($snippets) || ($snippets !== [] && array_is_list($snippets))) {
            return self::invalidRequest();
        }
        foreach ($snippets as $location => $code) {
            if (!isset(ScriptNormalizer::LOCATIONS[$location]) || !is_string($code)) {
                return self::invalidRequest();
            }
            $finding = ScriptValidator::snippetError($code, '/' . $location);
            if ($finding !== null) {
                $tooLarge = $finding['code'] === 'snippet_too_large';
                return new WP_Error(
                    $tooLarge ? 'proto_blocks_file_too_large' : 'proto_blocks_' . $finding['code'],
                    $finding['message'],
                    ['status' => $tooLarge ? 413 : 400, 'location' => $location]
                );
            }
        }

        $existing = $this->writer()->existing(ScriptsModule::KIND, $slug);
        try {
            $result = $this->writer()->write(ScriptsModule::KIND, $slug, $text, $hash, $existing);
        } catch (WriteException $e) {
            return self::writeError($e);
        }

        $dir = dirname($result['path']);
        foreach ($snippets as $location => $code) {
            try {
                $this->snippetFiles()->write($dir, ScriptNormalizer::LOCATIONS[$location], $code);
            } catch (WriteException $e) {
                $error = self::writeError($e);
                // script.jsonc is already saved: hand back its new hash so the editor does not conflict on retry.
                return new WP_Error(
                    (string) $error->get_error_code(),
                    $error->get_error_message(),
                    ['status' => $e->status, 'location' => $location, 'hash' => $result['hash']]
                );
            }
        }

        $module->scripts()->invalidate();
        $warnings = [];
        foreach ($module->issues() as $issue) {
            if ($issue->slug === $slug && $issue->kind === ScriptsModule::KIND) {
                $warnings[] = self::issueArray($issue);
            }
        }
        foreach (ScriptValidator::validate($result['data'])['warnings'] as $w) {
            $warnings[] = self::issueArray(new Issue(Issue::WARNING, $w['code'], $w['path'] . ': ' . $w['message'], $result['path'], $slug, ScriptsModule::KIND));
        }

        return [
            'hash' => $result['hash'],
            'path' => self::displayPath($result['path']),
            'created' => $result['created'],
            'warnings' => array_values(array_unique($warnings, SORT_REGULAR)),
        ];
    }

    // --- shapes ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $script
     * @param list<Issue>          $issues
     * @return array<string, mixed>
     */
    private function row(array $script, array $issues, ?string $writeReason): array
    {
        $slug = (string) $script['slug'];
        $isFile = $script['origin'] === 'file';
        $existing = $isFile ? $this->writer()->existing(ScriptsModule::KIND, $slug) : null;
        $target = $this->writer()->target(ScriptsModule::KIND, $slug, $existing);
        $reason = $writeReason ?? ($isFile ? ($target['writable'] ? null : self::targetReason($target['reason'])) : 'php');

        $files = [];
        foreach (ScriptNormalizer::LOCATIONS as $location => $name) {
            $files[$location] = $script['locations'][$location]['file'];
        }
        $own = [];
        foreach ($issues as $issue) {
            if ($issue->slug === $slug && $issue->kind === ScriptsModule::KIND) {
                $own[] = self::issueArray($issue);
            }
        }

        return [
            'slug' => $slug,
            'title' => (string) $script['title'],
            'enabled' => (bool) $script['enabled'],
            'priority' => (int) $script['priority'],
            'priorities' => $script['priorities'],
            'locations' => ScriptNormalizer::present($script),
            'files' => $files,
            'where' => $script['where'],
            'whereSummary' => Targets::summary($script['where']),
            'origin' => $isFile ? 'file' : 'php',
            'originLabel' => $isFile ? __('Proto-Blocks file', 'proto-blocks') : __('Proto-Blocks PHP', 'proto-blocks'),
            'source' => self::displayPath((string) $script['source']),
            'double' => $script['double'] === null ? null : [
                'file' => self::displayPath((string) $script['double']['file']),
                'php' => self::displayPath((string) $script['double']['php']),
            ],
            'editable' => $isFile && $reason === null,
            'readOnlyReason' => $reason,
            'issues' => $own,
        ];
    }

    /**
     * The editor's text for each location: an inline string from the file,
     * the referenced snippet file (or the location's own file name when the
     * key is missing, so an orphaned snippet is not lost), or, for a script
     * declared only in PHP, its code.
     *
     * @param array<string, mixed>|null $script
     * @param array<string, mixed>      $data   decoded script.jsonc ([] when none)
     * @return array{head: string, bodyOpen: string, bodyClose: string}
     */
    private function snippetsFor(string $slug, ?array $script, array $data): array
    {
        $out = ['head' => '', 'bodyOpen' => '', 'bodyClose' => ''];
        $existing = $this->writer()->existing(ScriptsModule::KIND, $slug);
        if ($existing === null) {
            if ($script !== null) {
                foreach (array_keys($out) as $location) {
                    $out[$location] = (string) $script['locations'][$location]['code'];
                }
            }
            return $out;
        }

        $dir = dirname($existing->source);
        foreach (ScriptNormalizer::LOCATIONS as $location => $default) {
            $value = $data[$location] ?? null;
            if (is_string($value)) {
                $out[$location] = $value;
                continue;
            }
            $name = is_array($value) && is_string($value['file'] ?? null) && SnippetFiles::isSnippetName($value['file']) ? $value['file'] : $default;
            [$text] = SnippetFiles::read($dir, $name);
            $out[$location] = $text ?? '';
        }

        return $out;
    }

    /** @return list<array{slug: string, name: string}> */
    private static function roles(): array
    {
        if (!function_exists('wp_roles')) {
            return [];
        }
        $roles = wp_roles();
        $names = is_object($roles) && method_exists($roles, 'get_names') ? $roles->get_names() : [];
        $out = [];
        foreach (is_array($names) ? $names : [] as $slug => $name) {
            $out[] = ['slug' => (string) $slug, 'name' => (string) $name];
        }

        return $out;
    }

    private static function scriptSlugError(mixed $slug): ?WP_Error
    {
        // The grammar is checked here, not via slugError(): with the module off the kind is not registered.
        return is_string($slug) && preg_match(SchemaFileWriter::EXTENDED_SLUG_PATTERN, $slug) === 1 ? null : self::badSlug($slug);
    }

    private static function badSlug(mixed $slug): WP_Error
    {
        return new WP_Error(
            'proto_blocks_invalid_slug',
            SchemaFileWriter::slugMessage(ScriptsModule::KIND, is_string($slug) ? $slug : '', 'pattern'),
            ['status' => 400, 'reason' => 'pattern']
        );
    }

    private static function invalidRequest(): WP_Error
    {
        return new WP_Error(
            'proto_blocks_invalid_request',
            __('Send {text: string, hash: string|null, snippets: {head?, bodyOpen?, bodyClose?: string}}.', 'proto-blocks'),
            ['status' => 400]
        );
    }

    private static function disabled(): WP_Error
    {
        return new WP_Error('proto_blocks_module_disabled', __('The Scripts module is disabled by the proto_blocks_modules filter.', 'proto-blocks'), ['status' => 404]);
    }

    // --- services ------------------------------------------------------------------

    private function module(): ?ScriptsModule
    {
        if (!$this->moduleResolved) {
            $this->moduleResolved = true;
            $this->module = ScriptsModule::instance();
        }

        return $this->module;
    }

    private function snippetFiles(): SnippetFiles
    {
        return $this->snippets ??= new SnippetFiles(SchemaPaths::writableRoots(), AdminRestController::fileWritesAllowed());
    }
}
