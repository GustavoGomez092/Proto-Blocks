<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Fields;

use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\ContentModel\JsoncException;
use ProtoBlocks\ContentModel\Registry;
use ProtoBlocks\ContentModel\SchemaPaths;
use ProtoBlocks\Modules\Fields\Bindings\FieldBindingSource;
use ProtoBlocks\Modules\Fields\Definition\FieldGroup;
use ProtoBlocks\Modules\Fields\Definition\GroupNormalizer;
use ProtoBlocks\Modules\Fields\Definition\Report;
use ProtoBlocks\Modules\Fields\Edit\BoxPayload;
use ProtoBlocks\Modules\Fields\Edit\ChoiceResolver;
use ProtoBlocks\Modules\Fields\Edit\EditRoutes;
use ProtoBlocks\Modules\Fields\Edit\ErrorStore;
use ProtoBlocks\Modules\Fields\Edit\MetaBoxes;
use ProtoBlocks\Modules\Fields\Edit\SaveHandler;
use ProtoBlocks\Modules\Fields\Edit\TermForms;
use ProtoBlocks\Modules\Fields\Edit\UserForms;
use ProtoBlocks\Modules\Fields\Read\Aliases;
use ProtoBlocks\Modules\Fields\Read\FieldReader;
use ProtoBlocks\Modules\Fields\Registry\GroupIndex;
use ProtoBlocks\Modules\Fields\Registry\MetaRegistrar;
use ProtoBlocks\Modules\Fields\Registry\TrustCallbacks;
use ProtoBlocks\Modules\Fields\Sources\AcfBridge;
use ProtoBlocks\Modules\Fields\Sources\DeclarationGroups;
use ProtoBlocks\Modules\Fields\Sources\GroupFiles;
use ProtoBlocks\Modules\Fields\Sources\PhpGroups;
use ProtoBlocks\Modules\Fields\Storage\ObjectRef;
use ProtoBlocks\Modules\Fields\Storage\OptionStore;
use ProtoBlocks\Modules\Fields\Storage\PostMetaStore;
use ProtoBlocks\Modules\Fields\Storage\Store;
use ProtoBlocks\Modules\Fields\Storage\TermMetaStore;
use ProtoBlocks\Modules\Fields\Storage\UserMetaStore;
use ProtoBlocks\Modules\Fields\Types\TypeRegistry;
use ProtoBlocks\Modules\ModuleInterface;

/**
 * The Fields module: field groups from PHP (proto_field_group()), from the
 * `fields` of post type and taxonomy declarations, and from
 * `proto-fields/<slug>/group.json(c)` files, in that order; one meta row per
 * stored field; the getter, the ACF aliases and the block bindings source.
 *
 * Services are built once per request, lazily. boot() touches no file: the
 * group index loads on its first use after `init` 10 (ACF has loaded its
 * local groups by then, and themes have declared their PHP groups), at the
 * latest when meta is registered on `init` 20. When the module is turned off
 * by the `proto_blocks_modules` filter, boot() never runs: no meta is
 * registered, no bindings source exists, and proto_field() returns null.
 *
 * Same group `key` from two sources: the file wins (a proto-fields file over
 * a type/taxonomy file over PHP), with a `duplicate_group_key` warning naming
 * both. A key Advanced Custom Fields already registers is skipped (AcfBridge).
 * A broken or refused proto-fields file never lets a parent theme's file of
 * the same slug back in (GroupFiles `broken_override`); since its key is
 * unknown, a `lower_rank_in_effect` warning says a PHP or type/taxonomy group
 * with that key may be in effect instead.
 *
 * Re-typing a field from `text` (or any type) to `code` makes values stored
 * under the old type come back raw: `code` is returned as stored, whoever
 * wrote it (see CodeType).
 *
 * instance() is what other code uses: the manager registers either the
 * booted module or, when the module is off, disabled(), whose index is empty
 * and whose services are never hooked.
 */
final class FieldsModule implements ModuleInterface
{
    public const ID = 'fields';

    /** Kinds whose data the declaration-errors filter checks. */
    public const KINDS = ['type', 'taxonomy', 'field-group'];

    private static ?self $instance = null;

    private readonly ?\Closure $roots;
    private readonly ?\Closure $decode;
    private readonly ?\Closure $declarations;

    private bool $booted = false;
    private bool $disabled = false;
    private bool $building = false;
    private ?GroupIndex $index = null;
    private ?TypeRegistry $types = null;
    /** @var array<string, Store>|null */
    private ?array $stores = null;
    private ?FieldReader $reader = null;
    private ?TrustCallbacks $trust = null;
    private ?MetaRegistrar $registrar = null;
    private ?FieldBindingSource $bindings = null;
    private ?ChoiceResolver $choices = null;
    private ?ErrorStore $errorStore = null;
    private ?SaveHandler $saveHandler = null;
    private ?EditRoutes $editRoutes = null;
    private ?BoxPayload $boxPayload = null;
    private ?MetaBoxes $metaBoxes = null;
    private ?TermForms $termForms = null;
    private ?UserForms $userForms = null;
    private AcfBridge $acf;

    /** @var list<Issue> filled when the index loads */
    private array $issues = [];

    /**
     * A null source is skipped.
     *
     * @param callable(): iterable<string>|null $roots        theme roots for proto-fields/ (SchemaPaths::roots)
     * @param callable(string): array|null     $decode       JSONC decoder for group files; throws on invalid input
     * @param callable(): iterable<object>|null $declarations post type and taxonomy declarations (Phase 1 Registry)
     */
    public function __construct(?callable $roots = null, ?callable $decode = null, ?callable $declarations = null)
    {
        $this->roots = $roots !== null ? \Closure::fromCallable($roots) : null;
        $this->decode = $decode !== null ? \Closure::fromCallable($decode) : null;
        $this->declarations = $declarations !== null ? \Closure::fromCallable($declarations) : null;
        $this->acf = new AcfBridge();
    }

    /**
     * The module the manager registered at boot (a disabled() one when the
     * module is off). Before the manager boots: one wired to the plugin's
     * content model.
     */
    public static function instance(): self
    {
        return self::$instance ??= self::wired();
    }

    /**
     * The stand-in for a module turned off by `proto_blocks_modules`: no
     * sources, an empty index, boot() and registerMeta() do nothing, and no
     * trust callback is ever hooked.
     */
    public static function disabled(): self
    {
        $module = new self();
        $module->disabled = true;

        return $module;
    }

    public function isEnabled(): bool
    {
        return !$this->disabled;
    }

    /** The manager registers the module it boots; tests reset with null. */
    public static function setInstance(?self $module): void
    {
        self::$instance = $module;
    }

    /**
     * Sources wired to Phase 1: SchemaPaths::roots(), the JSONC decoder, and
     * the post type and taxonomy declarations of $registry (default: the
     * plugin's content model).
     */
    public static function wired(?Registry $registry = null): self
    {
        return new self(
            static fn(): array => SchemaPaths::roots(),
            [self::class, 'decodeFile'],
            static function () use ($registry): array {
                $registry ??= \ProtoBlocks\Core\Plugin::getInstance()->getContentModel();

                // array_values: a type and a taxonomy may share a slug.
                return [...array_values($registry->postTypes()), ...array_values($registry->taxonomies())];
            }
        );
    }

    /**
     * Decodes a group file: JSONC with an object root (one group) or a list
     * root (an ACF Tools export). Never executes anything.
     *
     * @return array<string|int, mixed>
     * @throws JsoncException
     */
    public static function decodeFile(string $text): array
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        $stripped = trim(Jsonc::strip($text));
        if (!str_starts_with($stripped, '[')) {
            return Jsonc::decode($text);
        }
        if (!str_ends_with($stripped, ']')) {
            // `[ ... ], "x": 1` would otherwise become a valid wrapped object.
            throw new JsoncException('A field group list must end with "]".', 1, 1);
        }
        // Jsonc::decode() wants an object root: wrap the list on the same
        // first line (line numbers stay right) and close it on a new line (a
        // trailing // comment cannot swallow the brace).
        $groups = Jsonc::decode('{"groups":' . $text . "\n}")['groups'] ?? null;
        if (!is_array($groups)) {
            throw new JsoncException('The root of a field group file must be an object or a list of objects.', 1, 1);
        }

        return $groups;
    }

    public function id(): string
    {
        return self::ID;
    }

    /**
     * Sets the shared reader behind proto_field(), the bindings source
     * (`init` 10), meta registration (`init` 20), the ACF aliases
     * (`after_setup_theme` 100), the ACF-skip notice (admin only), the
     * declaration errors filter, the post/term/user save path (SaveHandler),
     * the edit-time REST routes (EditRoutes) and the field boxes (MetaBoxes,
     * TermForms, UserForms). Idempotent; reads no file.
     *
     * Why after_setup_theme 100 for the aliases: modules boot on
     * `after_setup_theme` 1, after `plugins_loaded` has fired, so a
     * `plugins_loaded` hook would never run; and a theme that bundles ACF
     * includes it from functions.php, which loads before after_setup_theme.
     * Running late on that hook sees such a copy (Aliases::load() then does
     * nothing) and still defines get_field() before any template runs.
     */
    public function boot(): void
    {
        if ($this->booted || $this->disabled) {
            return;
        }
        $this->booted = true;

        FieldReader::setShared($this->reader());
        $this->bindings()->register();
        add_action('init', [$this, 'registerMeta'], 20);
        if (self::after('after_setup_theme')) {
            $this->loadAliases();
        } else {
            add_action('after_setup_theme', [$this, 'loadAliases'], 100);
        }
        if (function_exists('is_admin') && is_admin()) {
            add_action('admin_notices', [$this, 'renderNotices']);
        }
        add_filter('proto_blocks_declaration_errors', [$this, 'declarationErrors'], 10, 4);
        add_action('save_post', [$this->saveHandler(), 'savePost'], 10, 2);
        add_action('created_term', [$this->saveHandler(), 'saveTerm'], 10, 3);
        add_action('edited_term', [$this->saveHandler(), 'saveTerm'], 10, 3);
        add_action('user_register', [$this->saveHandler(), 'saveUser'], 10, 1);
        add_action('personal_options_update', [$this->saveHandler(), 'saveUser'], 10, 1);
        add_action('edit_user_profile_update', [$this->saveHandler(), 'saveUser'], 10, 1);
        add_action('rest_api_init', [$this->editRoutes(), 'registerRoutes']);
        // Field boxes: post meta boxes, term forms (hooked per taxonomy on
        // admin_init, once groups can load), user forms.
        add_action('add_meta_boxes', [$this->metaBoxes(), 'addMetaBoxes'], 10, 2);
        add_action('admin_enqueue_scripts', [$this->metaBoxes(), 'enqueue']);
        add_action('admin_footer', [MetaBoxes::class, 'printPortal']);
        add_action('admin_init', [$this->termForms(), 'register']);
        $this->userForms()->register();
    }

    public function index(): GroupIndex
    {
        return $this->index ??= $this->disabled ? new GroupIndex([]) : new GroupIndex(fn(): ?array => $this->build());
    }

    public function reader(): FieldReader
    {
        return $this->reader ??= new FieldReader($this->index(), $this->types(), $this->stores());
    }

    public function types(): TypeRegistry
    {
        return $this->types ??= TypeRegistry::withCoreTypes();
    }

    /** @return array<string, Store> kind (ObjectRef::POST, ...) => store */
    public function stores(): array
    {
        if ($this->stores !== null) {
            return $this->stores;
        }
        $stores = [
            ObjectRef::POST => new PostMetaStore(),
            ObjectRef::TERM => new TermMetaStore(),
            ObjectRef::USER => new UserMetaStore(),
            ObjectRef::OPTION => new OptionStore($this->index()),
        ];
        // FieldReader (and Task 9's SaveHandler/BoxPayload) take the stores from here.
        $stores = apply_filters('proto_blocks_field_stores', $stores);

        return $this->stores = array_filter(is_array($stores) ? $stores : [], static fn(mixed $s): bool => $s instanceof Store);
    }

    public function trust(): TrustCallbacks
    {
        return $this->trust ??= new TrustCallbacks($this->types());
    }

    public function metaRegistrar(): MetaRegistrar
    {
        return $this->registrar ??= new MetaRegistrar($this->index(), $this->types(), $this->trust());
    }

    public function bindings(): FieldBindingSource
    {
        return $this->bindings ??= new FieldBindingSource($this->reader(), $this->index());
    }

    /** optionsSource choices through the block controls' options providers (resolved on first use). */
    public function choices(): ChoiceResolver
    {
        return $this->choices ??= new ChoiceResolver(
            static fn(string $source, array $args): array => \ProtoBlocks\Core\Plugin::getInstance()->getOptionsProviders()->resolve($source, $args)
        );
    }

    public function errorStore(): ErrorStore
    {
        return $this->errorStore ??= new ErrorStore();
    }

    public function saveHandler(): SaveHandler
    {
        return $this->saveHandler ??= new SaveHandler($this->index(), $this->types(), $this->stores(), $this->errorStore(), $this->choices(), $this->trust());
    }

    public function editRoutes(): EditRoutes
    {
        return $this->editRoutes ??= new EditRoutes($this->index(), $this->errorStore());
    }

    public function boxPayload(): BoxPayload
    {
        return $this->boxPayload ??= new BoxPayload($this->types(), $this->reader(), $this->errorStore(), $this->choices());
    }

    public function metaBoxes(): MetaBoxes
    {
        return $this->metaBoxes ??= new MetaBoxes($this->index(), $this->boxPayload());
    }

    public function termForms(): TermForms
    {
        return $this->termForms ??= new TermForms($this->index(), $this->boxPayload());
    }

    public function userForms(): UserForms
    {
        return $this->userForms ??= new UserForms($this->index(), $this->boxPayload(), $this->types(), $this->choices());
    }

    public function acf(): AcfBridge
    {
        return $this->acf;
    }

    /** Hooked on `init` 20. */
    public function registerMeta(): void
    {
        if ($this->disabled) {
            return;
        }
        $this->metaRegistrar()->register();
    }

    /** Hooked on `after_setup_theme` 100 (see boot()). Whether the aliases were defined. */
    public function loadAliases(): bool
    {
        return Aliases::load([AcfBridge::class, 'active']);
    }

    /** admin_notices: groups skipped because ACF registers the same key. */
    public function renderNotices(): void
    {
        if ($this->index()->ready()) {
            $this->acf->renderNotice();
        }
    }

    /**
     * Source findings as Phase 1 issues (for `wp proto-blocks validate` and
     * the content-model screen): discovery errors keep their code; group
     * errors and unknown field types are `declaration_invalid` errors;
     * normaliser warnings keep their code. Empty until the index has loaded.
     *
     * @return list<Issue>
     */
    public function issues(): array
    {
        return $this->index()->ready() ? $this->issues : [];
    }

    /** Every finding as one Report (path = the source file or "<file>:<line>"), meta registration warnings included. */
    public function report(): Report
    {
        $report = new Report();
        foreach ($this->issues() as $issue) {
            if ($issue->level === Issue::ERROR) {
                $report->addError($issue->source, $issue->code, $issue->message);
            } else {
                $report->addWarning($issue->source, $issue->code, $issue->message);
            }
        }
        if ($this->registrar !== null) {
            $report->merge($this->registrar->report());
        }

        return $report;
    }

    /**
     * `proto_blocks_declaration_errors` callback (kind, data, slug): appends
     * this module's findings for a type or taxonomy declaration's `fields`
     * and for a `field-group` file. Errors carry no `level` (they refuse a
     * write); warnings carry `'level' => 'warning'` (reported, never block).
     *
     * @param mixed $errors
     * @param mixed $data
     * @param mixed $slug
     * @return list<mixed>
     */
    public function declarationErrors($errors, $kind = '', $data = [], $slug = ''): array
    {
        $errors = is_array($errors) ? array_values($errors) : [];
        if (!is_string($kind) || !is_array($data)) {
            return $errors;
        }

        return [...$errors, ...self::declarationFindings($kind, $data, is_string($slug) ? $slug : '')];
    }

    /**
     * Field-group findings for one declaration, paths as JSON pointers into
     * its data (`/fields/0/key`). Unknown field types are errors: such a field
     * is skipped at load time, never coerced to text.
     *
     * @param array<string|int, mixed> $data
     * @return list<array{path: string, code: string, message: string, level?: string}>
     */
    public static function declarationFindings(string $kind, array $data, string $slug): array
    {
        $out = [];
        if ($kind === 'field-group') {
            self::collect(GroupNormalizer::normalizeMany($data, 'file:' . $slug)['report'], '', $out);
            return $out;
        }
        if (($kind !== 'type' && $kind !== 'taxonomy') || !array_key_exists('fields', $data)) {
            return $out;
        }
        $fields = $data['fields'];
        if (!is_array($fields) || !array_is_list($fields)) {
            $out[] = ['path' => '/fields', 'code' => 'invalid_fields', 'message' => __('"fields" must be a list of field groups.', 'proto-blocks')];
            return $out;
        }
        $implied = DeclarationGroups::implied((object) ['kind' => $kind, 'slug' => $slug]);
        foreach ($fields as $i => $raw) {
            if (!is_array($raw)) {
                $out[] = ['path' => "/fields/$i", 'code' => 'invalid_field', 'message' => __('A field group must be an object.', 'proto-blocks')];
                continue;
            }
            self::collect(GroupNormalizer::normalize($raw, "$kind:$slug", null, $implied)['report'], "/fields/$i", $out);
        }

        return $out;
    }

    /** @param list<array<string, string>> $out */
    private static function collect(Report $report, string $prefix, array &$out): void
    {
        foreach ($report->errors as $e) {
            $out[] = ['path' => $prefix . self::pointer($e['path']), 'code' => $e['code'], 'message' => $e['message']];
        }
        foreach ($report->warnings as $w) {
            $finding = ['path' => $prefix . self::pointer($w['path']), 'code' => $w['code'], 'message' => $w['message']];
            if ($w['code'] !== 'unknown_type') {
                $finding['level'] = 'warning';
            }
            $out[] = $finding;
        }
    }

    /** `fields[3].sub_fields[1].name` → `/fields/3/sub_fields/1/name`. */
    private static function pointer(string $path): string
    {
        $parts = array_filter(
            explode('.', (string) preg_replace('/\[([^\]]*)\]/', '.$1', $path)),
            static fn(string $p): bool => $p !== ''
        );

        return $parts === [] ? '' : '/' . implode('/', $parts);
    }

    /**
     * Loads every source once init has reached priority 10; null before, and
     * null for a call made while the sources load (a filter or decoder that
     * reads a field must not recurse into the build).
     *
     * @return list<FieldGroup>|null
     */
    private function build(): ?array
    {
        if ($this->building || !self::initReached()) {
            return null;
        }
        $this->building = true;
        try {
            return $this->load();
        } finally {
            $this->building = false;
        }
    }

    /** @return list<FieldGroup> */
    private function load(): array
    {
        $issues = [];
        /** @var list<array{group: FieldGroup, rank: int, label: string}> $entries */
        $entries = [];

        $php = PhpGroups::normalized();
        foreach ($php['reports'] as $one) {
            self::issuesFrom(self::findingsOf($one['report']), $one['source'], null, 'field-group', $issues);
        }
        foreach ($php['groups'] as $group) {
            $entries[] = ['group' => $group, 'rank' => 0, 'label' => (string) ($group->path ?? 'PHP')];
        }

        if ($this->declarations !== null) {
            $declarations = new DeclarationGroups($this->declarations);
            foreach ($declarations->declarations() as $declaration) {
                $source = is_string($declaration->source ?? null) ? $declaration->source : '';
                self::issuesFrom(self::declarationFindings($declaration->kind, $declaration->data, $declaration->slug), $source, $declaration->slug, $declaration->kind, $issues);
            }
            foreach ($declarations->all() as $group) {
                $entries[] = ['group' => $group, 'rank' => $group->path !== null ? 1 : 0, 'label' => $group->path ?? $group->source];
            }
        }

        if ($this->roots !== null && $this->decode !== null) {
            $files = new GroupFiles($this->roots, $this->decode);
            $discovery = $files->errors();
            foreach ([Issue::ERROR => $discovery->errors, Issue::WARNING => $discovery->warnings] as $level => $found) {
                foreach ($found as $e) {
                    $slug = preg_match('/[\/\\\\]group\.jsonc?\z/D', $e['path']) === 1 ? basename(dirname($e['path'])) : basename($e['path']);
                    $issues[] = new Issue($level, $e['code'], $e['message'], $e['path'], $slug, 'field-group');
                }
            }
            $broken = $files->broken();
            foreach ($files->files() as $file) {
                $findings = self::declarationFindings('field-group', $file['data'], $file['slug']);
                self::issuesFrom($findings, $file['path'], $file['slug'], 'field-group', $issues);
                foreach ($findings as $finding) {
                    if (($finding['level'] ?? null) !== 'warning') {
                        $broken[$file['slug']] = $file['path']; // a group of it may have been dropped
                        break;
                    }
                }
            }
            $lowerRank = array_filter($entries, static fn(array $e): bool => $e['rank'] < 2) !== [];
            foreach ($lowerRank ? $broken : [] as $slug => $path) {
                $issues[] = new Issue(Issue::WARNING, 'lower_rank_in_effect', sprintf(
                    /* translators: %s: broken file */
                    __('%s is broken or invalid; if it declared the key of a group from PHP or from a post type or taxonomy file, that group is in effect instead.', 'proto-blocks'),
                    $path
                ), $path, (string) $slug, 'field-group');
            }
            foreach ($files->all() as $group) {
                $entries[] = ['group' => $group, 'rank' => 2, 'label' => (string) $group->path];
            }
        }

        $groups = $this->acf->filterDuplicates(self::dedupe($entries, $issues));
        $groups = apply_filters('proto_blocks_field_groups', $groups);
        $groups = array_values(array_filter(is_array($groups) ? $groups : [], static fn(mixed $g): bool => $g instanceof FieldGroup));
        foreach ($this->acf->skipped() as $skipped) {
            $issues[] = new Issue(Issue::WARNING, 'acf_duplicate_key', sprintf(
                /* translators: %s: field group title */
                __('Proto-Blocks skipped the field group "%s" because Advanced Custom Fields already registers the same key.', 'proto-blocks'),
                $skipped['title'] !== '' ? $skipped['title'] : $skipped['key']
            ), $skipped['source'], null, 'field-group');
        }
        $this->issues = $issues;

        return $groups;
    }

    /**
     * One group per key: the higher rank (file over PHP) wins, else the first;
     * the winner keeps its own position.
     *
     * @param list<array{group: FieldGroup, rank: int, label: string}> $entries
     * @param list<Issue> $issues
     * @return list<FieldGroup>
     */
    private static function dedupe(array $entries, array &$issues): array
    {
        $winners = [];
        foreach ($entries as $i => $entry) {
            $key = $entry['group']->key;
            if (!isset($winners[$key])) {
                $winners[$key] = $i;
                continue;
            }
            $current = $entries[$winners[$key]];
            [$winner, $loser] = $entry['rank'] > $current['rank'] ? [$entry, $current] : [$current, $entry];
            if ($winner === $entry) {
                $winners[$key] = $i;
            }
            $issues[] = new Issue(Issue::WARNING, 'duplicate_group_key', sprintf(
                /* translators: 1: group key, 2: losing source, 3: winning source */
                __('The field group key "%1$s" is declared in %2$s and in %3$s; the one in %3$s is used.', 'proto-blocks'),
                $key,
                $loser['label'],
                $winner['label']
            ), $winner['label'], null, 'field-group');
        }
        $keep = array_flip(array_values($winners));
        $out = [];
        foreach ($entries as $i => $entry) {
            if (isset($keep[$i])) {
                $out[] = $entry['group'];
            }
        }

        return $out;
    }

    /** @return list<array{path: string, code: string, message: string, level?: string}> */
    private static function findingsOf(Report $report): array
    {
        $out = [];
        self::collect($report, '', $out);

        return $out;
    }

    /**
     * Findings as issues, in the shape `wp proto-blocks validate` gives the
     * same findings from the filter (so its row dedupe merges them).
     *
     * @param list<array{path: string, code: string, message: string, level?: string}> $findings
     * @param list<Issue> $issues
     */
    private static function issuesFrom(array $findings, string $source, ?string $slug, string $kind, array &$issues): void
    {
        foreach ($findings as $f) {
            $message = ($f['path'] === '' ? '/' : $f['path']) . ': ' . $f['message'];
            $issues[] = ($f['level'] ?? null) === 'warning'
                ? new Issue(Issue::WARNING, $f['code'], $message, $source, $slug, $kind)
                : new Issue(Issue::ERROR, 'declaration_invalid', $message, $source, $slug, $kind);
        }
    }

    /** `init` has fired and, while it is running, has reached priority 10. */
    private static function initReached(): bool
    {
        if (!function_exists('did_action') || did_action('init') === 0) {
            return false;
        }
        if (function_exists('doing_action') && doing_action('init')) {
            $hook = $GLOBALS['wp_filter']['init'] ?? null;
            if (is_object($hook) && method_exists($hook, 'current_priority')) {
                $priority = $hook->current_priority();

                return $priority === false || $priority >= 10;
            }
        }

        return true;
    }

    /** $hook has fired and is not running now. */
    private static function after(string $hook): bool
    {
        return function_exists('did_action') && did_action($hook) > 0
            && !(function_exists('doing_action') && doing_action($hook));
    }
}
