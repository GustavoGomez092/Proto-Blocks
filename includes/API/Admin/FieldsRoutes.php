<?php
/**
 * FieldsRoutes - the Fields screen's list, detail and validate routes
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\API\Admin;

use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\CLI\Commands;
use ProtoBlocks\CLI\FieldsCommand;
use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\ContentModel\JsoncException;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\SchemaPaths;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\Definition\Report;
use ProtoBlocks\Modules\Fields\FieldsModule;
use ProtoBlocks\Modules\Fields\Sources\AcfBridge;
use ProtoBlocks\Modules\Fields\Sources\DeclarationGroups;
use ProtoBlocks\Modules\Manager;
use WP_Error;
use WP_REST_Request;

/**
 * admin/fields: what the Fields screen reads. Writes are not here: the
 * builder saves through admin/schema-files/<kind>/<slug> (SchemaFilesRoutes
 * and SchemaFileWriter, kind `field-group` added with extendKind, or `type` /
 * `taxonomy` for a group inside a declaration file), so size, JSONC, hash,
 * confinement, symlink, atomic-swap, file-mods and multisite rules all apply.
 *
 * - GET admin/fields (permission) → {moduleEnabled, acfActive, createTarget,
 *   groups: GroupRow[], issues: Issue[]}; GroupRow {key, title, origin
 *   'file'|'php'|'type-file'|'taxonomy-file'|'acf', file {kind, slug, index?,
 *   path} | null, source, location, fieldCount, position, status
 *   'active'|'unrenderable'|'skipped-acf', issues [{level, code, path,
 *   message}], doubleDeclared, editable, readOnlyReason
 *   'file_mods'|'multisite'|'not_writable'|'acf'|'inline'|null}
 * - GET admin/fields/<key> (permission) → {group, issues, file: FileResult &
 *   {kind, slug, index} | null, postTypes, taxonomies, roles, optionsPages,
 *   pageTemplates} (each a list of {slug, label}); 404 proto_blocks_not_found
 * - POST admin/fields/validate (permission), body {text, kind:
 *   'field-group'|'type'|'taxonomy', index?} → {errors, warnings} (each
 *   {path, code, message}); 400 proto_blocks_invalid_request,
 *   400 proto_blocks_invalid_jsonc {line, column}, 413 proto_blocks_file_too_large
 *
 * Paths are relative to the theme root (`proto-fields/events/group.jsonc`);
 * no payload carries a stored field value, only declarations.
 *
 * A `code` field may be declared by any user who may write declaration files
 * (manage_options, file mods on, network admin on multisite), with or without
 * `unfiltered_html`: a declaration is a theme file, and the per-user write
 * trust (TrustCallbacks) applies to the values stored in such a field, not to
 * declaring it.
 */
final class FieldsRoutes extends ContentModelRoutes
{
    public const KIND = 'field-group';

    /** `[A-Za-z0-9_-]+` as the route has it; `D` so `$` refuses a trailing newline. */
    private const KEY_PATTERN = '/^[A-Za-z0-9_-]{1,191}$/D';

    private const KINDS = [self::KIND, Declaration::TYPE, Declaration::TAXONOMY];

    /** @var array<string, array<string|int, mixed>|null> decoded group files by path, per request */
    private array $decoded = [];

    public function __construct(
        ?Registry $registry = null,
        ?Manager $modules = null,
        ?SchemaFileWriter $writer = null,
        private ?FieldsModule $fields = null
    ) {
        parent::__construct($registry, $modules, $writer);
    }

    public function registerRoutes(string $namespace, callable $permission, callable $writePermission): void
    {
        $base = '/' . AdminRestController::BASE . '/fields';
        register_rest_route($namespace, $base, [
            ['methods' => 'GET', 'callback' => [$this, 'list'], 'permission_callback' => $permission],
        ]);
        // Before the key route: `validate` is also a valid key.
        register_rest_route($namespace, $base . '/validate', [
            ['methods' => 'POST', 'callback' => [$this, 'validate'], 'permission_callback' => $permission],
        ]);
        register_rest_route($namespace, $base . '/(?P<key>[A-Za-z0-9_-]+)', [
            ['methods' => 'GET', 'callback' => [$this, 'detail'], 'permission_callback' => $permission],
        ]);
    }

    private function fields(): FieldsModule
    {
        return $this->fields ??= FieldsModule::instance();
    }

    // --- GET admin/fields --------------------------------------------------

    /** @return array<string, mixed> */
    public function list(): array
    {
        $module = $this->fields();
        $acfActive = AcfBridge::active();
        $createTarget = SchemaFileWriter::isExtended(self::KIND)
            ? $this->createTarget(self::KIND)
            : ['path' => '', 'writable' => false, 'reason' => null];

        if (!$module->isEnabled()) {
            return ['moduleEnabled' => false, 'acfActive' => $acfActive, 'createTarget' => $createTarget, 'groups' => [], 'issues' => []];
        }

        $groups = $module->index()->all();
        $issues = $module->issues();
        $skipped = $module->acf()->skipped();
        $rows = FieldsCommand::rows($groups, $skipped);
        $acfGroups = [];
        foreach ($module->acf()->acfGroups() as $acf) {
            $acfGroups[$acf['key']] = $acf;
        }

        $out = [];
        $listed = [];
        $claimed = [];
        foreach ($groups as $i => $group) {
            $out[] = $this->groupRow($group, $rows[$i], $issues, $claimed);
            $listed[$group->key] = true;
        }
        foreach ($skipped as $j => $one) {
            $row = $rows[count($groups) + $j];
            $own = $this->issuesAt($issues, $one['source'], $claimed);
            $out[] = [
                'key' => $row['key'],
                'title' => $row['title'],
                'origin' => 'acf',
                'file' => null,
                'source' => $this->themePath($one['source']),
                'location' => $acfGroups[$one['key']]['locationSummary'] ?? '',
                'fieldCount' => self::acfFieldCount($one['key']),
                'position' => 'normal',
                'status' => FieldsCommand::STATUS_SKIPPED_ACF,
                'issues' => $own,
                'doubleDeclared' => false,
                'editable' => false,
                'readOnlyReason' => 'acf',
            ];
            $listed[$one['key']] = true;
        }
        foreach ($acfGroups as $key => $acf) {
            if (isset($listed[$key])) {
                continue;
            }
            $out[] = [
                'key' => Commands::safeArg($key),
                'title' => Commands::safeArg($acf['title']),
                'origin' => 'acf',
                'file' => null,
                'source' => null,
                'location' => Commands::safeArg($acf['locationSummary']),
                'fieldCount' => self::acfFieldCount($key),
                'position' => 'normal',
                'status' => FieldsCommand::STATUS_ACTIVE,
                'issues' => [],
                'doubleDeclared' => false,
                'editable' => false,
                'readOnlyReason' => 'acf',
            ];
        }

        $loose = [];
        foreach ($issues as $n => $issue) {
            if (!isset($claimed[$n])) {
                $loose[] = self::issueArray($issue);
            }
        }

        return [
            'moduleEnabled' => true,
            'acfActive' => $acfActive,
            'createTarget' => $createTarget,
            'groups' => $out,
            'issues' => $loose,
        ];
    }

    /**
     * @param array{key: string, title: string, source: string, path: string, fields: int, location: string, status: string} $row
     * @param list<Issue> $issues
     * @param array<int, true> $claimed
     * @return array<string, mixed>
     */
    private function groupRow(FieldGroup $group, array $row, array $issues, array &$claimed): array
    {
        [$origin, $file, $reason] = $this->origin($group);
        $path = $group->path;

        return [
            'key' => $row['key'],
            'title' => $row['title'],
            'origin' => $origin,
            'file' => $file,
            'source' => $path !== null ? $this->themePath($path) : null,
            'location' => $row['location'],
            'fieldCount' => $row['fields'],
            'position' => $group->position,
            'status' => $row['status'],
            'issues' => $path !== null ? $this->issuesAt($issues, $path, $claimed) : [],
            'doubleDeclared' => self::doubleDeclared($group, $issues),
            'editable' => $reason === null,
            'readOnlyReason' => $reason,
        ];
    }

    /**
     * Where $group comes from: its origin, the file a save goes to, and why
     * it cannot be saved (null when it can).
     *
     * @return array{0: string, 1: array{kind: string, slug: string, index?: int, path: string}|null, 2: ?string}
     */
    private function origin(FieldGroup $group): array
    {
        [$sourceKind, $slug] = array_pad(explode(':', $group->source, 2), 2, '');

        if ($sourceKind === 'file' && $group->path !== null) {
            $file = ['kind' => self::KIND, 'slug' => $slug];
            $index = $this->indexInFile($group->path, $group->key);
            if ($index !== null) {
                $file['index'] = $index;
            }
            $file['path'] = $this->themePath($group->path);
            $existing = $this->writer()->existing(self::KIND, $slug);
            $own = $existing !== null && $existing->source === $group->path ? $existing : null;

            return ['file', $file, $this->readOnlyReason(self::KIND, $slug, $own)];
        }

        if (($sourceKind === Declaration::TYPE || $sourceKind === Declaration::TAXONOMY) && $group->path !== null) {
            $declaration = $this->registry()->get($sourceKind, $slug);
            if ($declaration === null || $declaration->origin !== Declaration::ORIGIN_FILE || $declaration->parentType !== null) {
                // An inline taxonomy: its groups are edited in the type's file, not addressable from here.
                return [$sourceKind . '-file', null, 'inline'];
            }
            $file = ['kind' => $sourceKind, 'slug' => $slug];
            $index = self::indexIn($declaration->data['fields'] ?? null, $group->key);
            if ($index !== null) {
                $file['index'] = $index;
            }
            $file['path'] = $this->themePath($group->path);

            return [$sourceKind . '-file', $file, $this->readOnlyReason($sourceKind, $slug, $declaration)];
        }

        // proto_field_group() or the `fields` of a PHP declaration: edit the theme's PHP.
        return ['php', null, 'inline'];
    }

    private function readOnlyReason(string $kind, string $slug, ?Declaration $existing): ?string
    {
        $target = $this->writer()->target($kind, $slug, $existing);
        if ($target['writable']) {
            return null;
        }
        $reason = self::targetReason($target['reason']);

        // A file outside the writable roots (a filter root) cannot be saved here either.
        return in_array($reason, ['file_mods', 'multisite'], true) ? $reason : 'not_writable';
    }

    /** Position of the group with $key in a list-root group file; null for an object root. */
    private function indexInFile(string $path, string $key): ?int
    {
        if (!array_key_exists($path, $this->decoded)) {
            $this->decoded[$path] = null;
            $text = is_file($path) ? @file_get_contents($path, false, null, 0, FileLoader::MAX_BYTES + 1) : false;
            if (is_string($text) && strlen($text) <= FileLoader::MAX_BYTES) {
                try {
                    $this->decoded[$path] = FieldsModule::decodeFile($text);
                } catch (JsoncException) {
                    $this->decoded[$path] = null;
                }
            }
        }
        $data = $this->decoded[$path];

        return is_array($data) && $data !== [] && array_is_list($data) ? self::indexIn($data, $key) : null;
    }

    private static function indexIn(mixed $groups, string $key): ?int
    {
        if (!is_array($groups) || !array_is_list($groups)) {
            return null;
        }
        foreach ($groups as $i => $raw) {
            if (is_array($raw) && ($raw['key'] ?? null) === $key) {
                return $i;
            }
        }

        return null;
    }

    /**
     * The issues whose source is $source (a file or a PHP call), marked as
     * claimed so they are not listed again at the top of the screen.
     *
     * @param list<Issue> $issues
     * @param array<int, true> $claimed
     * @return list<array{level: string, code: string, path: string, message: string}>
     */
    private function issuesAt(array $issues, string $source, array &$claimed): array
    {
        $out = [];
        foreach ($issues as $n => $issue) {
            if ($issue->source !== $source) {
                continue;
            }
            $claimed[$n] = true;
            $out[] = [
                'level' => $issue->level,
                'code' => $issue->code,
                'path' => $this->themePath($issue->source),
                'message' => self::scrub($issue->message),
            ];
        }

        return $out;
    }

    /** @param list<Issue> $issues */
    private static function doubleDeclared(FieldGroup $group, array $issues): bool
    {
        $label = $group->path ?? $group->source;
        foreach ($issues as $issue) {
            if ($issue->code === 'duplicate_group_key' && $issue->key === $group->key && $issue->source === $label) {
                return true;
            }
        }

        return false;
    }

    private static function acfFieldCount(string $key): int
    {
        if (!function_exists('acf_get_fields')) {
            return 0;
        }
        $fields = acf_get_fields($key);

        return is_array($fields) ? count($fields) : 0;
    }

    /**
     * $path relative to the theme root that holds it (`proto-fields/x/group.jsonc`),
     * else as ContentModelRoutes::displayPath() shows it; "<file>:<line>" keeps its line.
     */
    private function themePath(string $path): string
    {
        foreach ([...SchemaPaths::writableRoots(), ...SchemaPaths::roots()] as $root) {
            $relative = AdminRestController::relativePath($path, $root);
            if ($relative !== $path) {
                return $relative;
            }
        }

        return self::displayPath($path);
    }

    // --- GET admin/fields/<key> ---------------------------------------------

    /** @return array<string, mixed>|WP_Error */
    public function detail(WP_REST_Request $request)
    {
        $key = self::urlParam($request, 'key');
        $module = $this->fields();
        if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1 || !$module->isEnabled()) {
            return self::groupNotFound();
        }

        $found = null;
        foreach ($module->index()->all() as $group) {
            if ($group->key === $key) {
                $found = $group;
                break;
            }
        }

        $choices = $this->choices();
        if ($found === null) {
            foreach ($module->acf()->acfGroups() as $acf) {
                if ($acf['key'] === $key) {
                    return ['group' => ['key' => $acf['key'], 'title' => $acf['title']], 'issues' => [], 'file' => null, 'readOnlyReason' => 'acf'] + $choices;
                }
            }

            return self::groupNotFound();
        }

        [, $file, $reason] = $this->origin($found);
        $claimed = [];
        $issues = $found->path !== null ? $this->issuesAt($module->issues(), $found->path, $claimed) : [];

        $fileResult = null;
        if ($file !== null) {
            $fileRequest = new WP_REST_Request('GET', '/' . AdminRestController::BASE . '/schema-files/' . $file['kind'] . '/' . $file['slug']);
            $fileRequest->set_param('kind', $file['kind']);
            $fileRequest->set_param('slug', $file['slug']);
            $result = (new SchemaFilesRoutes($this->registry(), null, $this->writer()))->get($fileRequest);
            if (is_array($result)) {
                $fileResult = $result + ['kind' => $file['kind'], 'slug' => $file['slug'], 'index' => $file['index'] ?? null];
            }
        }

        return ['group' => self::map($found->raw), 'issues' => $issues, 'file' => $fileResult, 'readOnlyReason' => $reason] + $choices;
    }

    private static function groupNotFound(): WP_Error
    {
        return new WP_Error('proto_blocks_not_found', __('No field group with that key exists.', 'proto-blocks'), ['status' => 404]);
    }

    /**
     * Choices for the location rule builder.
     *
     * @return array{postTypes: list<array{slug: string, label: string}>, taxonomies: list<array{slug: string, label: string}>, roles: list<array{slug: string, label: string}>, optionsPages: list<array{slug: string, label: string}>, pageTemplates: list<array{slug: string, label: string}>}
     */
    private function choices(): array
    {
        $roles = [];
        if (function_exists('wp_roles')) {
            foreach ((array) (wp_roles()->roles ?? []) as $slug => $role) {
                $name = is_array($role) && is_string($role['name'] ?? null) ? $role['name'] : (string) $slug;
                $roles[] = ['slug' => (string) $slug, 'label' => function_exists('translate_user_role') ? translate_user_role($name) : $name];
            }
        }

        $optionsPages = [];
        foreach ($this->registry()->all(Declaration::OPTIONS) as $slug => $declaration) {
            $title = $declaration->data['title'] ?? null;
            $optionsPages[] = ['slug' => (string) $slug, 'label' => is_string($title) && $title !== '' ? $title : (string) $slug];
        }

        $templates = [];
        if (function_exists('wp_get_theme')) {
            $theme = wp_get_theme();
            $list = is_object($theme) && method_exists($theme, 'get_page_templates') ? $theme->get_page_templates() : [];
            foreach (is_array($list) ? $list : [] as $file => $name) {
                $templates[] = ['slug' => (string) $file, 'label' => is_string($name) ? $name : (string) $file];
            }
        }

        return [
            'postTypes' => self::objectChoices(get_post_types(['show_ui' => true], 'objects')),
            'taxonomies' => self::objectChoices(get_taxonomies(['show_ui' => true], 'objects')),
            'roles' => $roles,
            'optionsPages' => $optionsPages,
            'pageTemplates' => $templates,
        ];
    }

    /** @return list<array{slug: string, label: string}> */
    private static function objectChoices(mixed $objects): array
    {
        $out = [];
        foreach (is_array($objects) ? $objects : [] as $slug => $object) {
            if (!is_object($object)) {
                continue;
            }
            $label = is_string($object->label ?? null) && $object->label !== '' ? $object->label : (string) $slug;
            $out[] = ['slug' => (string) $slug, 'label' => $label];
        }

        return $out;
    }

    // --- POST admin/fields/validate -----------------------------------------

    /** @return array{errors: list<array{path: string, code: string, message: string}>, warnings: list<array{path: string, code: string, message: string}>}|WP_Error */
    public function validate(WP_REST_Request $request)
    {
        $text = $request->get_param('text');
        $kind = $request->get_param('kind');
        $index = $request->get_param('index');
        if (is_string($index) && preg_match('/^\d{1,6}\z/D', $index) === 1) {
            $index = (int) $index;
        }
        if (!is_string($text) || !in_array($kind, self::KINDS, true) || ($index !== null && (!is_int($index) || $index < 0))) {
            return new WP_Error(
                'proto_blocks_invalid_request',
                __('Send {text: string, kind: "field-group", "type" or "taxonomy", index?: number}.', 'proto-blocks'),
                ['status' => 400]
            );
        }
        if (strlen($text) > FileLoader::MAX_BYTES) {
            return new WP_Error(
                'proto_blocks_file_too_large',
                /* translators: %d: maximum size in kilobytes */
                sprintf(__('A declaration file may not be larger than %d KB.', 'proto-blocks'), intdiv(FileLoader::MAX_BYTES, 1024)),
                ['status' => 413]
            );
        }

        try {
            $data = $kind === self::KIND ? FieldsModule::decodeFile($text) : Jsonc::decode($text);
        } catch (JsoncException $e) {
            return new WP_Error(
                'proto_blocks_invalid_jsonc',
                sprintf(
                    /* translators: 1: line number, 2: column number, 3: parser message */
                    __('Invalid JSONC at line %1$d, column %2$d: %3$s', 'proto-blocks'),
                    $e->line,
                    $e->column,
                    $e->getMessage()
                ),
                ['status' => 400, 'line' => $e->line, 'column' => $e->column]
            );
        }

        $report = new Report();
        if ($kind === self::KIND) {
            $report = GroupNormalizer::normalizeMany($data, 'file:validate')['report'];
        } else {
            $groups = $data['fields'] ?? [];
            if (!is_array($groups) || !array_is_list($groups)) {
                $report->addError('fields', 'invalid_fields', __('"fields" must be a list of field groups.', 'proto-blocks'));
            } else {
                $implied = DeclarationGroups::implied((object) ['kind' => $kind, 'slug' => is_string($data['slug'] ?? null) ? $data['slug'] : 'declaration']);
                foreach ($groups as $i => $raw) {
                    if ($index !== null && $i !== $index) {
                        continue;
                    }
                    $prefix = "fields[$i]";
                    if (!is_array($raw)) {
                        $report->addError($prefix, 'invalid_field', __('A field group must be an object.', 'proto-blocks'));
                        continue;
                    }
                    self::prefixed($report, GroupNormalizer::normalize($raw, $kind . ':validate', null, $implied)['report'], $prefix);
                }
                if ($index !== null && !array_key_exists($index, $groups)) {
                    $report->addError($prefix ?? 'fields', 'invalid_field', __('There is no field group at that position.', 'proto-blocks'));
                }
            }
        }

        return ['errors' => self::entries($report->errors), 'warnings' => self::entries($report->warnings)];
    }

    private static function prefixed(Report $into, Report $from, string $prefix): void
    {
        $at = static fn(string $path): string => $path === '' ? $prefix : (str_starts_with($path, '[') ? $prefix . $path : $prefix . '.' . $path);
        foreach ($from->errors as $e) {
            $into->addError($at($e['path']), $e['code'], $e['message']);
        }
        foreach ($from->warnings as $w) {
            $into->addWarning($at($w['path']), $w['code'], $w['message']);
        }
    }

    /**
     * @param list<array{path: string, code: string, message: string}> $entries
     * @return list<array{path: string, code: string, message: string}>
     */
    private static function entries(array $entries): array
    {
        return array_map(static fn(array $e): array => ['path' => $e['path'], 'code' => $e['code'], 'message' => self::scrub($e['message'])], $entries);
    }
}
