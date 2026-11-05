<?php

declare(strict_types=1);

namespace ProtoBlocks\API\Admin;

use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\SchemaPaths;
use ProtoBlocks\Modules\Redirects\Coexistence;
use ProtoBlocks\Modules\Redirects\Definition\GroupValidator;
use ProtoBlocks\Modules\Redirects\Definition\RuleNormalizer;
use ProtoBlocks\Modules\Redirects\Engine\Matcher;
use ProtoBlocks\Modules\Redirects\Engine\Request;
use ProtoBlocks\Modules\Redirects\Export\ExporterInterface;
use ProtoBlocks\Modules\Redirects\Export\Exporters;
use ProtoBlocks\Modules\Redirects\Export\JsonExporter;
use ProtoBlocks\Modules\Redirects\Import\FormatInterface;
use ProtoBlocks\Modules\Redirects\Import\Formats;
use ProtoBlocks\Modules\Redirects\Import\Merger;
use ProtoBlocks\Modules\Redirects\Import\ParsedRow;
use ProtoBlocks\Modules\Redirects\Import\PluginImporterInterface;
use ProtoBlocks\Modules\Redirects\Import\PluginImporters;
use ProtoBlocks\Modules\Redirects\RedirectsModule;
use ProtoBlocks\Modules\Redirects\Registry;
use ProtoBlocks\Modules\Redirects\Sources\GroupFiles;
use ProtoBlocks\Modules\Redirects\Storage\Hits;
use ProtoBlocks\Modules\Redirects\Storage\Installer;
use ProtoBlocks\Modules\Redirects\Storage\NotFoundLog;
use ProtoBlocks\Modules\Redirects\Storage\RedirectLog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST routes of the redirects admin screen (proto-blocks/v1 admin/redirects/*, spec §11).
 *
 * - GET    admin/redirects                              groups, rules with hits and issues, settings, db state
 * - GET    admin/redirects/test?url=&loggedIn=          which rule would fire (never redirects)
 * - GET    admin/redirects/log | admin/redirects/404    paged log rows (404: group_by=url)
 * - DELETE admin/redirects/log | admin/redirects/404    ids[] or all=1 (+ the current filters); 404 also ?url=<exact url>
 * - GET    admin/redirects/log/export | 404/export      CSV download, formula-escaped
 * - DELETE admin/redirects/hits                         {group, ruleId}: the row goes
 * - POST   admin/redirects/hits/reset | hits/prune
 * - POST   admin/redirects/import/parse                 multipart `file` or {format, text, filename}
 * - GET    admin/redirects/import/plugins               importers whose data is on this site
 * - POST   admin/redirects/import/plugins/<id>/preview
 * - POST   admin/redirects/import/deactivate            activate_plugins
 * - GET    admin/redirects/export?format=&group=        file download
 * - POST   admin/redirects/db/install
 *
 * No route here writes a theme file: rule and settings files are saved by
 * the client through admin/schema-files/redirects/<slug> and
 * admin/schema-files/redirect-settings/settings. Every mutation here is on
 * the database (or a plugin's active state), so it needs `manage_options`
 * but not file modifications: a read-only host keeps its logs usable.
 * No response carries an absolute path.
 */
final class RedirectsRoutes
{
    public const MAX_IMPORT_BYTES = Formats::MAX_BYTES;
    public const MIN_PER_PAGE = 5;
    public const MAX_PER_PAGE = 200;
    public const DEFAULT_PER_PAGE = 50;

    /** Response header marking a download body that is printed raw, not JSON-encoded. */
    public const DOWNLOAD_HEADER = 'X-Proto-Blocks-Download';

    public const FORMULA_PREFIX = '[FORMULA] ';

    public const TEST_NOTE = 'Evaluated without redirecting. Login and role conditions are checked against you (the signed-in user) unless loggedIn=0 is passed; header, cookie, agent, referrer and IP conditions see an empty request.';

    private const KIND = GroupFiles::KIND;
    private const PROBE_SLUG = 'new-group';
    private const RULE_ID = '/^r_[a-f0-9]{8}\z/D';
    private const IMPORTER_ROUTE = '(?P<id>[a-z0-9_-]{1,64})';

    /**
     * The plugin basenames each importer reads from: the only plugins
     * import/deactivate may deactivate. An importer may declare its own with
     * a plugins(): list<string> method instead.
     */
    public const PLUGINS = [
        'redirection' => ['redirection/redirection.php'],
        'simple-301-redirects' => ['simple-301-redirects/wp-simple-301-redirects.php'],
        'safe-redirect-manager' => ['safe-redirect-manager/safe-redirect-manager.php'],
        'rank-math' => ['seo-by-rank-math/rank-math.php'],
        'yoast' => ['wordpress-seo-premium/wp-seo-premium.php'],
    ];

    private const LOG_COLUMNS = ['created', 'url', 'target', 'code', 'group', 'ruleId', 'redirectBy', 'method', 'ip', 'agent', 'referrer'];
    private const NOT_FOUND_COLUMNS = ['created', 'url', 'method', 'ip', 'agent', 'referrer'];

    private static bool $serveFilterAdded = false;

    private readonly bool $injectedRegistry;
    private readonly \Closure $matcherFactory;
    private readonly \Closure $formatResolver;
    private readonly \Closure $importerList;
    private readonly \Closure $exporterResolver;
    private readonly \Closure $uploadReader;
    private readonly \Closure $as404;

    /**
     * Every service is optional: AdminRestController builds the group with
     * `new RedirectsRoutes()`; the module's registry is used then.
     *
     * @param (\Closure(list<array<string, mixed>>, array<string, mixed>): Matcher)|null $matcher
     * @param (\Closure(?string, string, string): ?FormatInterface)|null $formats (format id, filename, text); default Formats::byId / detect
     * @param (\Closure(): list<PluginImporterInterface>)|null  $importers default PluginImporters::all()
     * @param (\Closure(string, array<string, mixed>, bool): ?ExporterInterface)|null $exporters (format, settings, all groups); default exporter()
     * @param (\Closure(array<string, mixed>): (string|WP_Error))|null $upload reads one $_FILES entry
     * @param (\Closure(\Closure): mixed)|null $as404 runs the 404-stage evaluation while is_404() is true
     */
    public function __construct(
        private ?Registry $registry = null,
        private ?Hits $hits = null,
        private ?RedirectLog $redirectLog = null,
        private ?NotFoundLog $notFoundLog = null,
        private ?Installer $installer = null,
        ?\Closure $matcher = null,
        private ?SchemaFileWriter $writer = null,
        ?\Closure $formats = null,
        ?\Closure $importers = null,
        ?\Closure $exporters = null,
        ?\Closure $upload = null,
        ?\Closure $as404 = null
    ) {
        $this->as404 = $as404 ?? self::as404(...);
        $this->injectedRegistry = $registry !== null;
        $this->matcherFactory = $matcher ?? static fn(array $rules, array $settings): Matcher => new Matcher($rules, $settings);
        $this->formatResolver = $formats ?? static fn(?string $id, string $filename, string $text): ?FormatInterface
            => $id !== null ? Formats::byId($id) : Formats::detect($filename, $text);
        $this->importerList = $importers ?? static fn(): array => PluginImporters::all();
        $this->exporterResolver = $exporters ?? self::exporter(...);
        $this->uploadReader = $upload ?? self::readUpload(...);
    }

    public function registerRoutes(string $namespace, callable $permission, callable $writePermission): void
    {
        $base = '/' . AdminRestController::BASE . '/redirects';
        $get = static fn(callable $cb): array => ['methods' => 'GET', 'callback' => $cb, 'permission_callback' => $permission];
        $post = static fn(callable $cb, ?callable $perm = null): array => ['methods' => 'POST', 'callback' => $cb, 'permission_callback' => $perm ?? $permission];
        $delete = static fn(callable $cb): array => ['methods' => 'DELETE', 'callback' => $cb, 'permission_callback' => $permission];

        register_rest_route($namespace, $base, [$get([$this, 'index'])]);
        register_rest_route($namespace, $base . '/test', [$get([$this, 'test'])]);
        register_rest_route($namespace, $base . '/log', [$get([$this, 'log']), $delete([$this, 'deleteLog'])]);
        register_rest_route($namespace, $base . '/log/export', [$get([$this, 'exportLog'])]);
        register_rest_route($namespace, $base . '/404', [$get([$this, 'notFound']), $delete([$this, 'deleteNotFound'])]);
        register_rest_route($namespace, $base . '/404/export', [$get([$this, 'exportNotFound'])]);
        register_rest_route($namespace, $base . '/hits', [$delete([$this, 'deleteHits'])]);
        register_rest_route($namespace, $base . '/hits/reset', [$post([$this, 'resetHits'])]);
        register_rest_route($namespace, $base . '/hits/prune', [$post([$this, 'pruneHits'])]);
        register_rest_route($namespace, $base . '/import/parse', [$post([$this, 'parseImport'])]);
        register_rest_route($namespace, $base . '/import/plugins', [$get([$this, 'importPlugins'])]);
        register_rest_route($namespace, $base . '/import/plugins/' . self::IMPORTER_ROUTE . '/preview', [$post([$this, 'previewPlugin'])]);
        register_rest_route($namespace, $base . '/import/deactivate', [$post(
            [$this, 'deactivatePlugin'],
            static fn(): bool => (bool) $permission() && current_user_can('activate_plugins')
        )]);
        register_rest_route($namespace, $base . '/export', [$get([$this, 'export'])]);
        register_rest_route($namespace, $base . '/db/install', [$post([$this, 'installDb'])]);

        if (!self::$serveFilterAdded) {
            add_filter('rest_pre_serve_request', [self::class, 'serveDownload'], 10, 4);
            self::$serveFilterAdded = true;
        }
    }

    // --- services --------------------------------------------------------

    private function registry(): Registry
    {
        return $this->registry ??= RedirectsModule::instance()?->registry() ?? new Registry();
    }

    private function moduleEnabled(): bool
    {
        return $this->injectedRegistry || RedirectsModule::instance() !== null;
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return $this->registry()->settings();
    }

    private function hits(): Hits
    {
        return $this->hits ??= new Hits($this->settings());
    }

    private function redirectLog(): RedirectLog
    {
        return $this->redirectLog ??= new RedirectLog($this->settings());
    }

    private function notFoundLog(): NotFoundLog
    {
        return $this->notFoundLog ??= new NotFoundLog($this->settings());
    }

    private function installer(): Installer
    {
        return $this->installer ??= new Installer();
    }

    private function writer(): SchemaFileWriter
    {
        return $this->writer ??= new SchemaFileWriter(SchemaPaths::writableRoots(), AdminRestController::fileWritesAllowed());
    }

    /** @return list<Issue> */
    private function issues(): array
    {
        $module = RedirectsModule::instance();

        return !$this->injectedRegistry && $module !== null ? $module->issues() : $this->registry()->issues();
    }

    // --- GET admin/redirects ---------------------------------------------

    /** @return array<string, mixed> */
    public function index(): array
    {
        $createTarget = $this->createTarget();
        if (!$this->moduleEnabled()) {
            return [
                'moduleEnabled' => false,
                'paused' => false,
                'createTarget' => $createTarget,
                'groups' => [],
                'settings' => new \stdClass(),
                'settingsFile' => ['exists' => false, 'hash' => null, 'writable' => false],
                'issues' => [],
                'db' => ['tables' => false, 'orphans' => 0],
            ];
        }

        $registry = $this->registry();
        $settings = $registry->settings();
        $tables = $this->installer()->tablesExist();
        $hits = $tables ? $this->hits()->all() : [];
        $issues = $this->issues();
        $claimed = [];

        $groups = [];
        $known = [];
        foreach ($registry->groups() as $group) {
            $groups[] = $this->groupRow($group, $hits, $issues, $claimed);
            foreach ($group['rules'] as $rule) {
                $known[$group['slug'] . '/' . $rule['id']] = true;
            }
        }
        $orphans = 0;
        foreach ($hits as $slug => $rules) {
            foreach (array_keys($rules) as $id) {
                if (!isset($known[$slug . '/' . $id])) {
                    $orphans++;
                }
            }
        }

        $loose = [];
        foreach ($issues as $n => $issue) {
            if (!isset($claimed[$n])) {
                $loose[] = self::issueArray($issue);
            }
        }

        return [
            'moduleEnabled' => true,
            'paused' => Coexistence::paused($settings),
            'createTarget' => $createTarget,
            'groups' => $groups,
            'settings' => $settings,
            'settingsFile' => $this->settingsFile(),
            'issues' => $loose,
            'db' => ['tables' => $tables, 'orphans' => $orphans],
        ];
    }

    /**
     * @param array<string, mixed> $group
     * @param array<string, array<string, array{hits: int, lastHit: ?string}>> $hits
     * @param list<Issue> $issues
     * @param array<int, true> $claimed
     * @return array<string, mixed>
     */
    private function groupRow(array $group, array $hits, array $issues, array &$claimed): array
    {
        $slug = (string) $group['slug'];
        [$writable, $hash, $reason] = $this->fileState($group);

        $rules = [];
        foreach ($group['rules'] as $rule) {
            $own = [];
            foreach ($issues as $n => $issue) {
                if (self::issueIsFor($issue, $slug, $rule)) {
                    $claimed[$n] = true;
                    $own[] = self::issueArray($issue);
                }
            }
            $count = $hits[$slug][$rule['id']] ?? null;
            $row = $rule;
            $row['source'] = ContentModelRoutes::displayPath((string) ($rule['source'] ?? ''));
            $row['hits'] = $count['hits'] ?? 0;
            $row['lastHit'] = $count['lastHit'] ?? null;
            $row['issues'] = $own;
            $rules[] = $row;
        }

        $double = $group['double'] ?? null;
        if (is_array($double)) {
            $double = array_map(static fn($p): string => ContentModelRoutes::displayPath((string) $p), $double);
        }

        return [
            'slug' => $slug,
            'title' => $group['title'],
            'enabled' => $group['enabled'],
            'position' => $group['position'],
            'defaults' => $group['defaults'],
            'origin' => $group['origin'],
            'source' => ContentModelRoutes::displayPath((string) $group['source']),
            'double' => $double,
            'writable' => $writable,
            'readOnlyReason' => $reason,
            'hash' => $hash,
            'rules' => $rules,
        ];
    }

    /**
     * Whether the group's file can be saved from the screen, its hash, and why not.
     * A PHP group has no file; a file outside the writer's roots (a parent
     * theme, a filter root) cannot be saved either: a save would shadow it.
     *
     * @param array<string, mixed> $group
     * @return array{0: bool, 1: ?string, 2: ?string}
     */
    private function fileState(array $group): array
    {
        if (($group['origin'] ?? 'file') !== 'file') {
            return [false, null, 'php'];
        }
        $source = (string) ($group['source'] ?? '');
        $hash = self::fileHash($source);
        $writer = $this->writer();
        $existing = $writer->existing(self::KIND, (string) $group['slug']);
        $target = $writer->target(self::KIND, (string) $group['slug'], $existing);
        if (!$target['writable']) {
            return [false, $hash, self::reason($target['reason'])];
        }
        if (!self::sameFile($source, $existing)) {
            return [false, $hash, 'read_only'];
        }

        return [true, $hash, null];
    }

    /** The hash the schema-files route reports for $path (SchemaFileWriter::hash of its text), or null. */
    private static function fileHash(string $path): ?string
    {
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $text = file_get_contents($path);

        return is_string($text) ? SchemaFileWriter::hash($text) : null;
    }

    private static function sameFile(string $source, ?Declaration $existing): bool
    {
        if ($existing === null || $source === '') {
            return false;
        }
        $a = realpath($source);

        return $a !== false && $a === realpath($existing->source);
    }

    private static function reason(?string $reason): string
    {
        if ($reason === 'file_mods' && AdminRestController::writeReason() === 'multisite') {
            return 'multisite';
        }

        return in_array($reason, ['file_mods', 'multisite'], true) ? $reason : 'not_writable';
    }

    /** @return array{exists: bool, hash: ?string, writable: bool, path: string} */
    private function settingsFile(): array
    {
        $path = $this->registry()->settingsPath();
        $exists = $path !== null && is_file($path);
        $writer = $this->writer();
        $existing = $writer->existing(GroupFiles::SETTINGS_KIND, GroupFiles::SETTINGS_SLUG);
        $target = $writer->target(GroupFiles::SETTINGS_KIND, GroupFiles::SETTINGS_SLUG, $existing);
        $writable = $target['writable'] && (!$exists || self::sameFile((string) $path, $existing));

        return [
            'exists' => $exists,
            'hash' => $exists ? self::fileHash((string) $path) : null,
            'writable' => $writable,
            'path' => ContentModelRoutes::displayPath($exists ? (string) $path : $target['path']),
        ];
    }

    /** @return array{path: string, writable: bool, reason: ?string} */
    private function createTarget(): array
    {
        if (!SchemaFileWriter::isExtended(self::KIND)) {
            return ['path' => '', 'writable' => false, 'reason' => null];
        }
        $target = $this->writer()->target(self::KIND, self::PROBE_SLUG, null);

        return [
            'path' => $target['path'] === '' ? '' : ContentModelRoutes::displayPath(dirname($target['path'], 2)),
            'writable' => $target['writable'],
            'reason' => $target['writable'] ? null : self::reason($target['reason']),
        ];
    }

    /** @param array<string, mixed> $rule */
    private static function issueIsFor(Issue $issue, string $slug, array $rule): bool
    {
        if ($issue->kind !== self::KIND || $issue->slug !== $slug) {
            return false;
        }
        if ($issue->key !== null) {
            return $issue->key === $rule['id'];
        }
        $index = $rule['index'] ?? null;

        return is_int($index) && preg_match('~^/rules/' . $index . '(?:/|:)~', $issue->message) === 1;
    }

    /** @return array<string, mixed> */
    public static function issueArray(Issue $issue): array
    {
        $out = $issue->toArray();
        $out['source'] = ContentModelRoutes::displayPath($out['source']);
        $out['message'] = ContentModelRoutes::scrub($out['message']);

        return $out;
    }

    // --- GET admin/redirects/test ----------------------------------------

    /** @return array<string, mixed>|WP_Error */
    public function test(WP_REST_Request $request)
    {
        $url = $request->get_param('url');
        if (!is_string($url) || ($url = trim($url)) === '' || strlen($url) > 2000
            || ($url[0] !== '/' && preg_match('~^https?://~i', $url) !== 1) || str_starts_with($url, '//')) {
            return new WP_Error('proto_blocks_invalid_url', __('Pass a path starting with "/" or an http(s) URL.', 'proto-blocks'), ['status' => 400]);
        }
        $loggedIn = $request->get_param('loggedIn');
        $asVisitor = $loggedIn !== null && in_array(strtolower((string) (is_bool($loggedIn) ? ($loggedIn ? '1' : '0') : $loggedIn)), ['0', 'false', 'no'], true);

        $previous = get_current_user_id();
        if ($asVisitor && $previous !== 0 && function_exists('wp_set_current_user')) {
            wp_set_current_user(0);
        }
        try {
            $result = self::evaluate($this->registry(), $this->matcherFactory, $url, $this->as404);
        } finally {
            if ($asVisitor && $previous !== 0 && function_exists('wp_set_current_user')) {
                wp_set_current_user($previous);
            }
        }

        return $result + [
            'loggedIn' => !$asVisitor && $previous !== 0,
            'note' => __(self::TEST_NOTE, 'proto-blocks'),
        ];
    }

    /**
     * Which rule fires for $url: the init stage, then (when nothing fired)
     * the 404 stage. Shared with `wp proto-blocks redirect test`.
     *
     * @param \Closure(list<array<string, mixed>>, array<string, mixed>): Matcher $factory
     * @param (\Closure(\Closure): mixed)|null $as404 runs a callback as if WordPress found no page (default as404())
     * @return array{matched: bool, stage: ?string, group: ?string, ruleId: ?string, action: ?string, code: ?int, target: ?string, reason: ?string, trace: list<array<string, string>>}
     */
    public static function evaluate(Registry $registry, \Closure $factory, string $url, ?\Closure $as404 = null): array
    {
        $as404 ??= self::as404(...);
        $server = [];
        $home = function_exists('home_url') ? wp_parse_url((string) home_url('/')) : null;
        if (is_array($home) && isset($home['host'])) {
            $server['HTTP_HOST'] = (string) $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '');
            $server['HTTPS'] = ($home['scheme'] ?? 'http') === 'https' ? 'on' : '';
        }
        $request = Request::fromUrl($url, $server);
        $matcher = $factory($registry->rules(), $registry->settings());

        $trace = [];
        $match = null;
        $stage = null;
        foreach ([['init', false], ['404', true]] as [$name, $notFound]) {
            $run = static fn(): array => [$matcher->trace($request, $notFound), $matcher->match($request, $notFound)];
            [$steps, $match] = $notFound ? $as404($run) : $run();
            foreach ($steps as $step) {
                if (in_array($step['reason'], ['404_stage_only', 'not_404_stage'], true)) {
                    continue;
                }
                $trace[] = $step + ['stage' => $name];
            }
            if ($match !== null) {
                $stage = $name;
                break;
            }
        }

        return [
            'matched' => $match !== null,
            'stage' => $stage,
            'group' => $match !== null ? (string) ($match['rule']['group'] ?? '') : null,
            'ruleId' => $match !== null ? (string) ($match['rule']['id'] ?? '') : null,
            'action' => $match['action'] ?? null,
            'code' => $match['code'] ?? null,
            'target' => $match['target'] ?? null,
            'reason' => $match['reason'] ?? null,
            'trace' => $trace,
        ];
    }

    /**
     * Runs $fn with the main query flagged as a 404 (the `404` condition
     * reads is_404()), then puts the query back as it was.
     */
    public static function as404(\Closure $fn): mixed
    {
        $query = $GLOBALS['wp_query'] ?? null;
        if (!is_object($query) || !property_exists($query, 'is_404')) {
            return $fn();
        }
        $was = $query->is_404;
        $query->is_404 = true;
        try {
            return $fn();
        } finally {
            $query->is_404 = $was;
        }
    }

    // --- logs --------------------------------------------------------------

    /** @return array<string, mixed> */
    public function log(WP_REST_Request $request): array
    {
        [$page, $perPage] = self::paging($request);
        $result = $this->redirectLog()->query(self::logFilters($request), $page, $perPage);

        return self::page($result, $page, $perPage);
    }

    /** @return array<string, mixed> */
    public function notFound(WP_REST_Request $request): array
    {
        [$page, $perPage] = self::paging($request);
        $filters = self::notFoundFilters($request);
        $grouped = $request->get_param('group_by') === 'url';
        $result = $grouped
            ? $this->notFoundLog()->grouped($filters, $page, $perPage)
            : $this->notFoundLog()->query($filters, $page, $perPage);

        return self::page($result, $page, $perPage) + ['groupBy' => $grouped ? 'url' : null];
    }

    /** @return array<string, int>|WP_Error */
    public function deleteLog(WP_REST_Request $request)
    {
        return self::deleteRows($this->redirectLog(), $request, self::logFilters($request));
    }

    /** @return array<string, int>|WP_Error */
    public function deleteNotFound(WP_REST_Request $request)
    {
        $url = $request->get_param('url');
        $all = $request->get_param('all');
        if (is_string($url) && $url !== '' && $request->get_param('ids') === null && ($all === null || in_array((string) $all, ['', '0', 'false'], true))) {
            return ['deleted' => $this->notFoundLog()->deleteByUrl($url)];
        }

        return self::deleteRows($this->notFoundLog(), $request, self::notFoundFilters($request));
    }

    public function exportLog(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($error = self::csvOnly($request)) !== null) {
            return $error;
        }

        return self::download(
            self::csv(self::LOG_COLUMNS, $this->redirectLog()->exportRows(self::logFilters($request))),
            'text/csv; charset=utf-8',
            'proto-redirect-log-' . gmdate('Y-m-d') . '.csv'
        );
    }

    public function exportNotFound(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($error = self::csvOnly($request)) !== null) {
            return $error;
        }

        return self::download(
            self::csv(self::NOT_FOUND_COLUMNS, $this->notFoundLog()->exportRows(self::notFoundFilters($request))),
            'text/csv; charset=utf-8',
            'proto-404-log-' . gmdate('Y-m-d') . '.csv'
        );
    }

    private static function csvOnly(WP_REST_Request $request): ?WP_Error
    {
        $format = $request->get_param('format');
        if ($format === null || $format === '' || $format === 'csv') {
            return null;
        }

        return new WP_Error('proto_blocks_invalid_format', __('Logs export as CSV only.', 'proto-blocks'), ['status' => 400]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{deleted: int}|WP_Error
     */
    private static function deleteRows(\ProtoBlocks\Modules\Redirects\Storage\LogTable $table, WP_REST_Request $request, array $filters)
    {
        $ids = $request->get_param('ids');
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        if (is_array($ids) && $ids !== []) {
            return ['deleted' => $table->delete(array_values($ids))];
        }
        if (in_array((string) $request->get_param('all'), ['1', 'true'], true) || $request->get_param('all') === true) {
            return ['deleted' => $table->clear($filters)];
        }

        return new WP_Error('proto_blocks_nothing_to_delete', __('Pass ids[] or all=1.', 'proto-blocks'), ['status' => 400]);
    }

    /** @return array{0: int, 1: int} page ≥ 1, per page 5–200 */
    private static function paging(WP_REST_Request $request): array
    {
        $page = $request->get_param('page');
        $perPage = $request->get_param('per_page');
        $page = is_numeric($page) ? max(1, (int) $page) : 1;
        $perPage = is_numeric($perPage) ? (int) $perPage : self::DEFAULT_PER_PAGE;

        return [$page, max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, $perPage))];
    }

    /**
     * @param array{rows: list<array<string, mixed>>, total: int} $result
     * @return array<string, mixed>
     */
    private static function page(array $result, int $page, int $perPage): array
    {
        return [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
            'pages' => (int) ceil($result['total'] / $perPage),
        ];
    }

    /** @return array<string, string> */
    private static function logFilters(WP_REST_Request $request): array
    {
        $filters = self::dateSearch($request);
        $code = $request->get_param('code');
        if (is_numeric($code)) {
            $filters['code'] = (string) (int) $code;
        }
        $group = $request->get_param('group');
        if (is_string($group) && preg_match(GroupValidator::SLUG_PATTERN, $group) === 1) {
            $filters['group'] = $group;
        }
        $rule = $request->get_param('rule');
        if (is_string($rule) && $rule !== '') {
            if (str_contains($rule, '/')) {
                [$ruleGroup, $rule] = explode('/', $rule, 2);
                if (preg_match(GroupValidator::SLUG_PATTERN, $ruleGroup) === 1) {
                    $filters['group'] = $ruleGroup;
                }
            }
            if (preg_match(self::RULE_ID, $rule) === 1) {
                $filters['ruleId'] = $rule;
            }
        }

        return $filters;
    }

    /** @return array<string, string> */
    private static function notFoundFilters(WP_REST_Request $request): array
    {
        $filters = self::dateSearch($request);
        $url = $request->get_param('url');
        if (is_string($url) && $url !== '') {
            $filters['url'] = $url;
        }

        return $filters;
    }

    /** @return array<string, string> */
    private static function dateSearch(WP_REST_Request $request): array
    {
        $filters = [];
        foreach (['search', 'from', 'to'] as $key) {
            $value = $request->get_param($key);
            if (is_string($value) && trim($value) !== '') {
                $filters[$key] = mb_substr(trim($value), 0, 200);
            }
        }

        return $filters;
    }

    // --- CSV and downloads ---------------------------------------------------

    /**
     * A cell safe to open in a spreadsheet: text starting with = + - @
     * (ASCII or full-width), a tab or a carriage return gets "[FORMULA] ".
     */
    public static function csvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            $value = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $text = (string) $value;
        if (is_string($value) && preg_match('/^(?:[=+\-@\t\r]|\x{FF1D}|\x{FF0B}|\x{FF0D}|\x{FF20})/u', $text) === 1) {
            return self::FORMULA_PREFIX . $text;
        }

        return $text;
    }

    /**
     * @param list<string>     $columns
     * @param iterable<array<string, mixed>> $rows
     */
    public static function csv(array $columns, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        fputcsv($handle, $columns, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn(string $c): string => self::csvCell($row[$c] ?? null), $columns), ',', '"', '');
        }
        rewind($handle);
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return $text;
    }

    private static function download(string $body, string $mime, string $filename): WP_REST_Response
    {
        $filename = (string) preg_replace('/[^A-Za-z0-9._-]/', '-', $filename);
        $response = new WP_REST_Response($body, 200);
        $response->header('Content-Type', $mime);
        $response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('Cache-Control', 'no-store');
        $response->header(self::DOWNLOAD_HEADER, '1');

        return $response;
    }

    /**
     * `rest_pre_serve_request`: print a download body as is (the headers were
     * already sent by the server) instead of JSON-encoding it.
     *
     * @param mixed $served
     * @param mixed $result
     */
    public static function serveDownload($served, $result, $request = null, $server = null): mixed
    {
        if ($served || !$result instanceof WP_REST_Response) {
            return $served;
        }
        $headers = $result->get_headers();
        if (($headers[self::DOWNLOAD_HEADER] ?? null) !== '1' || !is_string($result->get_data())) {
            return $served;
        }
        echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a file download (CSV cells formula-escaped), sent as an attachment.

        return true;
    }

    // --- hits ------------------------------------------------------------------

    /** @return array{reset: bool}|WP_Error */
    public function resetHits(WP_REST_Request $request)
    {
        $key = self::hitKey($request);
        if ($key instanceof WP_Error) {
            return $key;
        }
        $this->hits()->reset($key[0], $key[1]);

        return ['reset' => true];
    }

    /** @return array{deleted: bool}|WP_Error */
    public function deleteHits(WP_REST_Request $request)
    {
        $key = self::hitKey($request);
        if ($key instanceof WP_Error) {
            return $key;
        }
        $this->hits()->delete($key[0], $key[1]);

        return ['deleted' => true];
    }

    /** @return array{0: string, 1: string}|WP_Error */
    private static function hitKey(WP_REST_Request $request): array|WP_Error
    {
        $group = $request->get_param('group');
        $ruleId = $request->get_param('ruleId');
        if (!is_string($group) || preg_match(GroupValidator::SLUG_PATTERN, $group) !== 1 || !is_string($ruleId) || preg_match(self::RULE_ID, $ruleId) !== 1) {
            return new WP_Error('proto_blocks_invalid_rule', __('Pass a group slug and a rule id (r_ + 8 hex characters).', 'proto-blocks'), ['status' => 400]);
        }

        return [$group, $ruleId];
    }

    /** @return array{deleted: int} */
    public function pruneHits(): array
    {
        return ['deleted' => $this->hits()->prune(self::knownRules($this->registry()))];
    }

    /** @return list<string> "group/ruleId" of every rule the registry knows (disabled ones too) */
    public static function knownRules(Registry $registry): array
    {
        $known = [];
        foreach ($registry->groups() as $group) {
            foreach ($group['rules'] as $rule) {
                $known[] = $group['slug'] . '/' . $rule['id'];
            }
        }

        return $known;
    }

    // --- import ------------------------------------------------------------------

    /** @return array<string, mixed>|WP_Error */
    public function parseImport(WP_REST_Request $request)
    {
        $files = method_exists($request, 'get_file_params') ? $request->get_file_params() : [];
        $file = is_array($files['file'] ?? null) ? $files['file'] : null;
        if ($file !== null) {
            $text = ($this->uploadReader)($file);
            if ($text instanceof WP_Error) {
                return $text;
            }
            $filename = is_string($file['name'] ?? null) ? basename($file['name']) : '';
        } else {
            $text = $request->get_param('text');
            if (!is_string($text) || $text === '') {
                return new WP_Error('proto_blocks_import_empty', __('Upload a file or pass its text.', 'proto-blocks'), ['status' => 400]);
            }
            if (strlen($text) > self::MAX_IMPORT_BYTES) {
                return self::tooLarge();
            }
            $filename = is_string($request->get_param('filename')) ? basename((string) $request->get_param('filename')) : '';
        }

        $id = $request->get_param('format');
        $id = is_string($id) && $id !== '' ? $id : null;
        $format = ($this->formatResolver)($id, $filename, $text);
        if ($format instanceof WP_Error) {
            return $format;
        }
        if (!$format instanceof FormatInterface) {
            return new WP_Error(
                'proto_blocks_unknown_format',
                $id === null
                    ? __('The file format could not be recognised. Choose it explicitly.', 'proto-blocks')
                    : __('Unknown import format.', 'proto-blocks'),
                ['status' => 400]
            );
        }

        $group = $request->get_param('group');
        $group = is_string($group) && preg_match(GroupValidator::SLUG_PATTERN, $group) === 1 ? $group : null;
        $rows = self::checkRows($format->parse($text), $this->registry(), $group);

        $out = ['format' => $format->id(), 'rules' => $rows];
        if (method_exists($format, 'parseGroups')) {
            $groups = $format->parseGroups($text);
            if (is_array($groups) && $groups !== []) {
                $out['groups'] = $groups;
            }
        }

        return $out;
    }

    /**
     * Parsed rows re-checked for the preview: a `new` row that fails the
     * file validator becomes `invalid` (reason = the validator's code), and
     * Merger::mark() turns a `new` row whose source, regex flag and condition
     * are already in the target group (every group when $group is null), or
     * in an earlier row, into `duplicate`. Each row gains `message`.
     *
     * @param list<array<string, mixed>> $rows ParsedRow
     * @return list<array{rule: array<string, mixed>, status: string, reason: ?string, line: int, message: ?string}>
     */
    public static function checkRows(array $rows, Registry $registry, ?string $group): array
    {
        $defaults = $registry->settings()['defaults'] ?? RuleNormalizer::BUILTIN_DEFAULTS;
        $existing = ['slug' => $group ?? '', 'defaults' => is_array($defaults) ? $defaults : [], 'rules' => []];
        foreach ($registry->groups() as $g) {
            if ($group === null || $g['slug'] === $group) {
                if ($group !== null) {
                    $existing['defaults'] = $g['defaults'];
                }
                array_push($existing['rules'], ...$g['rules']);
            }
        }

        $out = [];
        $messages = [];
        foreach (array_values($rows) as $n => $row) {
            $rule = is_array($row['rule'] ?? null) ? $row['rule'] : [];
            $status = in_array($row['status'] ?? null, [ParsedRow::NEW, ParsedRow::DUPLICATE, ParsedRow::INVALID], true) ? $row['status'] : ParsedRow::INVALID;
            $reason = is_string($row['reason'] ?? null) ? $row['reason'] : null;
            $message = $reason !== null ? ParsedRow::message($reason) : null;
            if ($status === ParsedRow::NEW) {
                $analysis = GroupValidator::analyse(['rules' => [$rule]], $group ?? 'import', $existing['defaults'], null);
                if (isset($analysis['invalid'][0]) || !isset($analysis['rules'][0])) {
                    $status = ParsedRow::INVALID;
                    $finding = $analysis['errors'][0] ?? null;
                    $reason = is_array($finding) ? (string) $finding['code'] : 'invalid_rule';
                    $message = is_array($finding) ? self::findingText($finding) : __('The rule is not valid.', 'proto-blocks');
                } elseif ($analysis['warnings'] !== []) {
                    $message = self::findingText($analysis['warnings'][0]);
                }
            }
            $out[$n] = ['rule' => $rule, 'status' => $status, 'reason' => $reason, 'line' => (int) ($row['line'] ?? 0)];
            $messages[$n] = $message;
        }
        foreach (Merger::mark($existing, $out) as $n => $row) {
            if ($row['status'] === ParsedRow::DUPLICATE && $out[$n]['status'] === ParsedRow::NEW) {
                $messages[$n] = ParsedRow::message('duplicate');
            }
            $out[$n] = $row + ['message' => $messages[$n]];
        }

        return array_values($out);
    }

    /** @param array{path: string, message: string} $finding */
    private static function findingText(array $finding): string
    {
        $path = (string) preg_replace('~^/rules/0~', '', $finding['path']);

        return ($path !== '' ? ltrim($path, '/') . ': ' : '') . $finding['message'];
    }

    /** @return array{importers: list<array<string, mixed>>} */
    public function importPlugins(): array
    {
        $out = [];
        foreach (($this->importerList)() as $importer) {
            if (!$importer->available()) {
                continue;
            }
            $plugins = self::pluginsOf($importer);
            $out[] = [
                'id' => (string) $importer->id(),
                'label' => (string) $importer->label(),
                'cleanupHint' => $importer->cleanupHint(),
                'plugin' => $plugins[0] ?? null,
                'plugins' => $plugins,
                'active' => array_values(array_filter($plugins, static fn(string $p): bool => function_exists('is_plugin_active') && is_plugin_active($p))),
                'canDeactivate' => current_user_can('activate_plugins'),
            ];
        }

        return ['importers' => $out];
    }

    /** @return array<string, mixed>|WP_Error */
    public function previewPlugin(WP_REST_Request $request)
    {
        $importer = $this->importer($request->get_param('id'));
        if ($importer === null || !$importer->available()) {
            return self::importerNotFound();
        }
        $groups = $importer->preview();

        $skipped = [];
        if (method_exists($importer, 'skipped')) {
            foreach ((array) $importer->skipped() as $row) {
                if (is_array($row)) {
                    $reason = is_string($row['reason'] ?? null) ? $row['reason'] : null;
                    $skipped[] = $row + ['message' => $reason !== null ? ParsedRow::message($reason) : null];
                }
            }
        }

        return [
            'id' => $importer->id(),
            'label' => $importer->label(),
            'groups' => $groups === [] ? new \stdClass() : $groups,
            'skipped' => $skipped,
            'cleanupHint' => $importer->cleanupHint(),
        ];
    }

    /** @return array<string, mixed>|WP_Error */
    public function deactivatePlugin(WP_REST_Request $request)
    {
        if (!current_user_can('activate_plugins')) {
            return new WP_Error('proto_blocks_forbidden', __('Deactivating plugins needs the activate_plugins capability.', 'proto-blocks'), ['status' => 403]);
        }
        $importer = $this->importer($request->get_param('id'));
        if ($importer === null) {
            return self::importerNotFound();
        }
        $declared = self::pluginsOf($importer);
        $asked = $request->get_param('plugin');
        if (is_string($asked) && $asked !== '') {
            if (!in_array($asked, $declared, true)) {
                return new WP_Error('proto_blocks_invalid_plugin', __('That plugin does not belong to this importer.', 'proto-blocks'), ['status' => 400]);
            }
            $declared = [$asked];
        }
        if (!function_exists('deactivate_plugins') && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/plugin.php')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $deactivated = [];
        $skipped = [];
        foreach ($declared as $plugin) {
            if (!function_exists('is_plugin_active') || !is_plugin_active($plugin)) {
                $skipped[] = ['plugin' => $plugin, 'reason' => 'inactive'];
                continue;
            }
            if (is_multisite() && function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($plugin)) {
                $skipped[] = ['plugin' => $plugin, 'reason' => 'network'];
                continue;
            }
            deactivate_plugins($plugin);
            $deactivated[] = $plugin;
        }

        return ['deactivated' => $deactivated, 'skipped' => $skipped];
    }

    private function importer(mixed $id): ?PluginImporterInterface
    {
        if (!is_string($id) || $id === '') {
            return null;
        }
        foreach (($this->importerList)() as $importer) {
            if ($importer->id() === $id) {
                return $importer;
            }
        }

        return null;
    }

    /** @return list<string> plugin basenames the importer reads from */
    public static function pluginsOf(PluginImporterInterface $importer): array
    {
        if (method_exists($importer, 'plugins')) {
            $plugins = $importer->plugins();

            return is_array($plugins) ? array_values(array_filter($plugins, 'is_string')) : [];
        }

        return self::PLUGINS[$importer->id()] ?? [];
    }

    private static function importerNotFound(): WP_Error
    {
        return new WP_Error('proto_blocks_importer_not_found', __('No importer with that id has data on this site.', 'proto-blocks'), ['status' => 404]);
    }

    private static function tooLarge(): WP_Error
    {
        return new WP_Error(
            'proto_blocks_import_too_large',
            /* translators: %d: megabytes */
            sprintf(__('Import files may be at most %d MB.', 'proto-blocks'), intdiv(self::MAX_IMPORT_BYTES, 1024 * 1024)),
            ['status' => 413]
        );
    }

    /**
     * One uploaded file's text: no upload error, at most 5 MB, a real upload.
     *
     * @param array<string, mixed> $file
     */
    private static function readUpload(array $file): string|WP_Error
    {
        $error = $file['error'] ?? UPLOAD_ERR_OK;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return self::tooLarge();
        }
        $tmp = $file['tmp_name'] ?? '';
        if ($error !== UPLOAD_ERR_OK || !is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) {
            return new WP_Error('proto_blocks_upload_failed', __('The file could not be uploaded.', 'proto-blocks'), ['status' => 400]);
        }
        $size = filesize($tmp);
        if ($size === false || $size > self::MAX_IMPORT_BYTES) {
            return self::tooLarge();
        }
        $text = file_get_contents($tmp, false, null, 0, self::MAX_IMPORT_BYTES + 1);
        if (!is_string($text) || $text === '') {
            return new WP_Error('proto_blocks_import_empty', __('The uploaded file is empty.', 'proto-blocks'), ['status' => 400]);
        }

        return $text;
    }

    // --- export ------------------------------------------------------------------

    public function export(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $format = $request->get_param('format');
        $format = is_string($format) && $format !== '' ? $format : 'csv';
        $slug = $request->get_param('group');
        $slug = is_string($slug) && $slug !== '' ? $slug : null;
        $exporter = preg_match('/^[a-z]{1,20}\z/D', $format) === 1 ? ($this->exporterResolver)($format, $this->settings(), $slug === null) : null;
        if (!$exporter instanceof ExporterInterface) {
            return new WP_Error('proto_blocks_invalid_format', __('Unknown export format.', 'proto-blocks'), ['status' => 400]);
        }
        $groups = self::exportGroups($this->registry(), $slug);
        if ($groups === null) {
            return new WP_Error('proto_blocks_group_not_found', __('No redirect group with that slug exists.', 'proto-blocks'), ['status' => 404]);
        }
        $hits = $this->installer()->tablesExist() ? $this->hits()->all() : [];

        return self::download($exporter->render($groups, $hits), $exporter->mime(), self::exportFilename($exporter, $slug));
    }

    /**
     * The exporter for $format; a JSON export of every group carries the
     * settings too (a full backup).
     *
     * @param array<string, mixed> $settings
     */
    public static function exporter(string $format, array $settings = [], bool $allGroups = false): ?ExporterInterface
    {
        if ($format === 'json' && $allGroups) {
            return new JsonExporter($settings);
        }

        return Exporters::byFormat($format);
    }

    /** `proto-redirects-<group|all>-<date>.<ext>`; Netlify's file is always `_redirects`. */
    public static function exportFilename(ExporterInterface $exporter, ?string $slug): string
    {
        $extension = ltrim($exporter->extension(), '.');
        if ($extension === '') {
            return '_redirects';
        }

        return 'proto-redirects-' . ($slug ?? 'all') . '-' . gmdate('Y-m-d') . '.' . $extension;
    }

    /**
     * The groups to export: every group, or the one named. Null when it does not exist.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function exportGroups(Registry $registry, ?string $slug): ?array
    {
        if ($slug === null) {
            return $registry->groups();
        }
        $group = $registry->group($slug);

        return $group === null ? null : [$group];
    }

    // --- db ----------------------------------------------------------------------

    /** @return array{tables: bool}|WP_Error */
    public function installDb()
    {
        if (!$this->installer()->install()) {
            return new WP_Error('proto_blocks_db_install_failed', __('The redirect tables could not be created. Check the database user\'s CREATE privilege.', 'proto-blocks'), ['status' => 500]);
        }

        return ['tables' => true];
    }
}
