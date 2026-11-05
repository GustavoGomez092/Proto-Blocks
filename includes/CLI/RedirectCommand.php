<?php
/**
 * WP-CLI: wp proto-blocks redirect <subcommand> (spec §12).
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\CLI;

use ProtoBlocks\API\Admin\RedirectsRoutes;
use ProtoBlocks\API\AdminRestController;
use ProtoBlocks\ContentModel\Declaration;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\Jsonc;
use ProtoBlocks\ContentModel\JsoncException;
use ProtoBlocks\ContentModel\SchemaFileWriter;
use ProtoBlocks\ContentModel\SchemaPaths;
use ProtoBlocks\ContentModel\WriteException;
use ProtoBlocks\Modules\Redirects\Definition\GroupValidator;
use ProtoBlocks\Modules\Redirects\Definition\RuleNormalizer;
use ProtoBlocks\Modules\Redirects\Engine\Matcher;
use ProtoBlocks\Modules\Redirects\Import\Formats;
use ProtoBlocks\Modules\Redirects\Import\Merger;
use ProtoBlocks\Modules\Redirects\Import\ParsedRow;
use ProtoBlocks\Modules\Redirects\Import\PluginImporterInterface;
use ProtoBlocks\Modules\Redirects\Import\PluginImporters;
use ProtoBlocks\Modules\Redirects\RedirectsModule;
use ProtoBlocks\Modules\Redirects\Registry;
use ProtoBlocks\Modules\Redirects\Serializer;
use ProtoBlocks\Modules\Redirects\Sources\GroupFiles;
use ProtoBlocks\Modules\Redirects\Storage\Hits;
use ProtoBlocks\Modules\Redirects\Storage\Installer;
use ProtoBlocks\Modules\Redirects\Storage\NotFoundLog;
use ProtoBlocks\Modules\Redirects\Storage\RedirectLog;
use ProtoBlocks\Modules\Redirects\Storage\Retention;
use WP_CLI;
use WP_CLI_Command;

/**
 * Manage redirect rules (theme files under proto-redirects/), their logs and tables.
 *
 * Writes go through the same writer as the admin screen (file mods,
 * confinement, hash, size, validation). They serialise the whole group file,
 * so hand-written comments inside that file are not kept (the file header
 * says so); the admin screen keeps them.
 */
final class RedirectCommand extends WP_CLI_Command
{
    public const DEFAULT_GROUP = 'redirects';

    private const KIND = GroupFiles::KIND;
    private const LIST_COLUMNS = ['id', 'group', 'from', 'to', 'code', 'action', 'when', 'enabled', 'origin', 'hits'];
    private const LOG_COLUMNS = ['id', 'created', 'url', 'target', 'code', 'group', 'ruleId', 'redirectBy', 'ip'];
    private const NOT_FOUND_COLUMNS = ['id', 'created', 'url', 'method', 'ip', 'referrer'];

    private readonly \Closure $importers;
    private readonly \Closure $as404;

    /**
     * @param (\Closure(): list<PluginImporterInterface>)|null $importers default PluginImporters::all()
     * @param (\Closure(\Closure): mixed)|null                 $as404     see RedirectsRoutes::as404()
     */
    public function __construct(
        private ?Registry $registry = null,
        private ?SchemaFileWriter $writer = null,
        private ?Hits $hits = null,
        private ?RedirectLog $redirectLog = null,
        private ?NotFoundLog $notFoundLog = null,
        private ?Installer $installer = null,
        ?\Closure $importers = null,
        ?\Closure $as404 = null
    ) {
        $this->importers = $importers ?? static fn(): array => PluginImporters::all();
        $this->as404 = $as404 ?? RedirectsRoutes::as404(...);
    }

    // --- services ----------------------------------------------------------

    private function registry(): Registry
    {
        return $this->registry ??= RedirectsModule::instance()?->registry() ?? new Registry();
    }

    private function writer(): SchemaFileWriter
    {
        return $this->writer ??= new SchemaFileWriter(SchemaPaths::writableRoots(), AdminRestController::fileWritesAllowed());
    }

    private function hits(): Hits
    {
        return $this->hits ??= new Hits($this->registry()->settings());
    }

    private function redirectLog(): RedirectLog
    {
        return $this->redirectLog ??= new RedirectLog($this->registry()->settings());
    }

    private function notFoundLog(): NotFoundLog
    {
        return $this->notFoundLog ??= new NotFoundLog($this->registry()->settings());
    }

    private function installer(): Installer
    {
        return $this->installer ??= new Installer();
    }

    // --- list --------------------------------------------------------------

    /**
     * List every rule in evaluation order.
     *
     * ## OPTIONS
     *
     * [--group=<slug>]
     * : Only this group.
     *
     * [--format=<format>]
     * : table, json or csv. Default table.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function list(array $args, array $assoc): void
    {
        $only = isset($assoc['group']) ? (string) $assoc['group'] : null;
        $hits = $this->installer()->tablesExist() ? $this->hits()->all() : [];
        $rows = [];
        foreach ($this->registry()->groups() as $group) {
            if ($only !== null && $group['slug'] !== $only) {
                continue;
            }
            foreach ($group['rules'] as $rule) {
                $when = $rule['when'];
                $rows[] = [
                    'id' => $rule['id'],
                    'group' => $group['slug'],
                    'from' => (string) ($rule['from'] ?? ''),
                    'to' => (string) ($rule['to'] ?? ''),
                    'code' => $rule['code'] === null ? '' : (string) $rule['code'],
                    'action' => $rule['action'],
                    'when' => is_array($when) ? $when['type'] . (is_string($when['value']) ? ':' . $when['value'] : (is_array($when['value']) ? ':' . implode(',', $when['value']) : '')) : '',
                    'enabled' => $rule['enabled'] && $group['enabled'] ? 'yes' : 'no',
                    'origin' => $rule['origin'],
                    'hits' => (string) ($hits[$group['slug']][$rule['id']]['hits'] ?? 0),
                ];
            }
        }
        WP_CLI\Utils\format_items((string) ($assoc['format'] ?? 'table'), $rows, self::LIST_COLUMNS);
    }

    // --- add / delete / enable / disable -----------------------------------

    /**
     * Add a rule to a group file (the group is created when missing).
     *
     * ## OPTIONS
     *
     * <from>
     * : Source path, or a pattern with --regex.
     *
     * [<to>]
     * : Target path or http(s) URL. Not for --action=error|nothing.
     *
     * [--code=<code>]
     * : 301 (default), 302, 303, 307, 308; for errors 404, 410, ...
     *
     * [--group=<slug>]
     * : Group slug. Default "redirects".
     *
     * [--regex]
     * : <from> is a regular expression.
     *
     * [--when=<type>]
     * : Condition type: login, role, referrer, agent, header, cookie, ip, language, server, filter, 404.
     *
     * [--when-value=<value>]
     * : Condition value (comma-separated for ip and language).
     *
     * [--when-name=<name>]
     * : Header or cookie name.
     *
     * [--action=<action>]
     * : url (default), error, pass or nothing.
     *
     * [--query=<mode>]
     * : exact, exactOrder, ignore or pass.
     *
     * [--title=<title>]
     * : Description.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function add(array $args, array $assoc): void
    {
        $from = trim((string) ($args[0] ?? ''));
        if ($from === '') {
            WP_CLI::error('Usage: wp proto-blocks redirect add <from> [<to>] [--code=<code>] [--group=<slug>] [--regex] [--when=<type>] [--when-value=<v>] [--action=<action>] [--query=<mode>] [--title=<title>]');
            return;
        }
        $slug = (string) ($assoc['group'] ?? self::DEFAULT_GROUP);

        $rule = [];
        if (isset($assoc['title'])) {
            $rule['title'] = (string) $assoc['title'];
        }
        $type = isset($assoc['when']) ? (string) $assoc['when'] : null;
        if ($type !== '404') {
            $rule['from'] = $from;
        }
        if (isset($assoc['regex']) && $assoc['regex'] !== false) {
            $rule['regex'] = true;
        }
        if (isset($args[1]) && $args[1] !== '') {
            $rule['to'] = (string) $args[1];
        }
        if (isset($assoc['code'])) {
            $rule['code'] = is_numeric($assoc['code']) ? (int) $assoc['code'] : (string) $assoc['code'];
        }
        if (isset($assoc['action'])) {
            $rule['action'] = (string) $assoc['action'];
        }
        if ($type !== null) {
            $when = ['type' => $type];
            if (isset($assoc['when-name'])) {
                $when['name'] = (string) $assoc['when-name'];
            }
            if (isset($assoc['when-value'])) {
                $value = (string) $assoc['when-value'];
                $when['value'] = in_array($type, RuleNormalizer::LIST_TYPES, true)
                    ? array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $v): bool => $v !== ''))
                    : $value;
            }
            $rule['when'] = $when;
        }
        if (isset($assoc['query'])) {
            $rule['query'] = (string) $assoc['query'];
        }

        $file = $this->openGroup($slug, true);
        $data = $file['data'];
        $defaults = $this->effectiveDefaults($data);
        $normal = RuleNormalizer::normalize($rule, $defaults, $slug);
        $taken = [];
        foreach ($data['rules'] as $existing) {
            if (is_array($existing)) {
                $taken[RuleNormalizer::normalize($existing, $defaults, $slug)['id']] = true;
            }
        }
        if (isset($taken[$normal['id']])) {
            WP_CLI::error(sprintf('The group "%s" already has a rule from %s with this condition and action (%s).', $slug, $from, $normal['id']));
            return;
        }
        $rule = ['id' => $normal['id']] + $rule;
        $data['rules'][] = $rule;

        $this->assertValid($slug, $data, count($data['rules']) - 1);
        $this->save($slug, $data, $file);
        WP_CLI::success(sprintf('Added %s to %s.', $rule['id'], $this->relative($file['path'] ?? $slug)));
    }

    /**
     * Delete a rule by id.
     *
     * ## OPTIONS
     *
     * <id>
     * : Rule id (r_xxxxxxxx).
     *
     * [--group=<slug>]
     * : Only look in this group.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function delete(array $args, array $assoc): void
    {
        [$slug, $file, $index, $id] = $this->locate($args, $assoc);
        array_splice($file['data']['rules'], $index, 1);
        $this->save($slug, $file['data'], $file);
        if ($this->installer()->tablesExist()) {
            $this->hits()->delete($slug, $id);
        }
        WP_CLI::success(sprintf('Deleted %s from %s.', $id, $slug));
    }

    /**
     * Enable a rule by id.
     *
     * ## OPTIONS
     *
     * <id>
     * : Rule id.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function enable(array $args, array $assoc): void
    {
        $this->toggle($args, $assoc, true);
    }

    /**
     * Disable a rule by id.
     *
     * ## OPTIONS
     *
     * <id>
     * : Rule id.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function disable(array $args, array $assoc): void
    {
        $this->toggle($args, $assoc, false);
    }

    /**
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    private function toggle(array $args, array $assoc, bool $enabled): void
    {
        [$slug, $file, $index, $id] = $this->locate($args, $assoc);
        $rule = $file['data']['rules'][$index];
        if ($enabled) {
            unset($rule['enabled']);
        } else {
            $rule['enabled'] = false;
        }
        $rule = ['id' => $id] + $rule;
        $file['data']['rules'][$index] = $rule;
        $this->save($slug, $file['data'], $file);
        WP_CLI::success(sprintf('%s %s in %s.', $enabled ? 'Enabled' : 'Disabled', $id, $slug));
    }

    /**
     * The file group holding rule <id> and its position in the raw file.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     * @return array{0: string, 1: array{data: array<string, mixed>, existing: ?Declaration, hash: ?string, path: ?string}, 2: int, 3: string}
     */
    private function locate(array $args, array $assoc): array
    {
        $id = trim((string) ($args[0] ?? ''));
        if (preg_match('/^r_[a-f0-9]{8}\z/D', $id) !== 1) {
            WP_CLI::error('Pass a rule id (r_ followed by 8 hex characters); `wp proto-blocks redirect list` shows them.');
        }
        $only = isset($assoc['group']) ? (string) $assoc['group'] : null;
        foreach ($this->registry()->groups() as $group) {
            if ($only !== null && $group['slug'] !== $only) {
                continue;
            }
            foreach ($group['rules'] as $rule) {
                if ($rule['id'] !== $id) {
                    continue;
                }
                if ($rule['origin'] !== 'file') {
                    WP_CLI::error(sprintf('%s is declared in PHP at %s; change it there.', $id, self::relativeSource((string) $rule['source'])));
                }
                $file = $this->openGroup($group['slug'], false);
                $defaults = $this->effectiveDefaults($file['data']);
                foreach ($file['data']['rules'] as $i => $raw) {
                    if (is_array($raw) && RuleNormalizer::normalize($raw, $defaults, $group['slug'])['id'] === $id) {
                        return [$group['slug'], $file, (int) $i, $id];
                    }
                }
            }
        }
        WP_CLI::error(sprintf('No rule with id %s%s.', $id, $only !== null ? ' in the group "' . $only . '"' : ''));
        throw new \LogicException('unreachable');
    }

    // --- group ---------------------------------------------------------------

    /**
     * Manage groups: list, create <title>, enable|disable|delete <slug>.
     *
     * ## OPTIONS
     *
     * <action>
     * : list, create, enable, disable or delete.
     *
     * [<value>]
     * : The title (create) or slug.
     *
     * [--slug=<slug>]
     * : Slug for create; defaults to the slugified title.
     *
     * [--position=<n>]
     * : Position for create. Default 10.
     *
     * [--yes]
     * : Confirm delete.
     *
     * [--format=<format>]
     * : table, json or csv (list).
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function group(array $args, array $assoc): void
    {
        $action = (string) ($args[0] ?? 'list');
        $value = trim((string) ($args[1] ?? ''));
        switch ($action) {
            case 'list':
                $rows = [];
                foreach ($this->registry()->groups() as $group) {
                    $rows[] = [
                        'slug' => $group['slug'],
                        'title' => $group['title'],
                        'enabled' => $group['enabled'] ? 'yes' : 'no',
                        'position' => (string) $group['position'],
                        'rules' => (string) count($group['rules']),
                        'origin' => $group['origin'],
                    ];
                }
                WP_CLI\Utils\format_items((string) ($assoc['format'] ?? 'table'), $rows, ['slug', 'title', 'enabled', 'position', 'rules', 'origin']);
                return;

            case 'create':
                if ($value === '') {
                    WP_CLI::error('Usage: wp proto-blocks redirect group create <title> [--slug=<slug>] [--position=<n>]');
                }
                $slug = isset($assoc['slug']) ? (string) $assoc['slug'] : self::slugify($value);
                if ($this->writer()->existing(self::KIND, $slug) !== null || $this->registry()->group($slug) !== null) {
                    WP_CLI::error(sprintf('The group "%s" already exists.', $slug));
                }
                $file = $this->openGroup($slug, true);
                $file['data']['title'] = $value;
                if (isset($assoc['position'])) {
                    $file['data']['position'] = (int) $assoc['position'];
                }
                $this->assertValid($slug, $file['data'], null);
                $this->save($slug, $file['data'], $file);
                WP_CLI::success(sprintf('Created the group "%s".', $slug));
                return;

            case 'enable':
            case 'disable':
                $file = $this->openGroup($this->groupArg($value), false);
                $file['data']['enabled'] = $action === 'enable';
                $this->save($value, $file['data'], $file);
                WP_CLI::success(sprintf('%s the group "%s".', $action === 'enable' ? 'Enabled' : 'Disabled', $value));
                return;

            case 'delete':
                $file = $this->openGroup($this->groupArg($value), false);
                if (!isset($assoc['yes'])) {
                    WP_CLI::error(sprintf('Deleting the group "%s" removes its file and %d rule(s). Pass --yes to confirm.', $value, count($file['data']['rules'])));
                }
                try {
                    $this->writer()->delete(self::KIND, $value, $file['existing']);
                } catch (WriteException $e) {
                    WP_CLI::error($e->getMessage());
                }
                $this->registry()->invalidate();
                WP_CLI::success(sprintf('Deleted the group "%s".', $value));
                return;
        }
        WP_CLI::error('Unknown group action: ' . Commands::safeArg($action) . '. Use list, create, enable, disable or delete.');
    }

    private function groupArg(string $slug): string
    {
        if ($slug === '') {
            WP_CLI::error('Pass a group slug.');
        }

        return $slug;
    }

    private static function slugify(string $title): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $title), '-'));
        if ($slug === '' || !ctype_alpha($slug[0])) {
            $slug = 'group-' . $slug;
        }

        return substr($slug, 0, 60);
    }

    // --- validate / test -------------------------------------------------------

    /**
     * Validate every redirect group and the settings file.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json or csv. Default table.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function validate(array $args, array $assoc): void
    {
        $rows = self::validationRows($this->registry());
        $format = (string) ($assoc['format'] ?? 'table');
        if ($format === 'json') {
            WP_CLI::line((string) json_encode($rows, JSON_UNESCAPED_SLASHES));
        } elseif ($rows !== []) {
            WP_CLI\Utils\format_items($format, $rows, ['kind', 'slug', 'level', 'code', 'message', 'source']);
        }
        if (ContentModelCommands::hasErrors($rows)) {
            WP_CLI::error('Validation failed for one or more redirect files.');
        }
        WP_CLI::success('All redirect groups and settings are valid.');
    }

    /**
     * The redirects module's findings as `wp proto-blocks validate` rows
     * (the ContentModelCommands::validationRows shape), each once.
     *
     * @return list<array{kind: string, slug: string, level: string, code: string, message: string, source: string}>
     */
    public static function validationRows(Registry $registry): array
    {
        $rows = [];
        foreach ($registry->issues() as $issue) {
            $row = [
                'kind' => $issue->kind ?? GroupFiles::KIND,
                'slug' => $issue->slug ?? '',
                'level' => $issue->level,
                'code' => $issue->code,
                'message' => $issue->message,
                'source' => self::relativeSource($issue->source),
            ];
            $rows[implode("\0", $row)] ??= $row;
        }

        return array_values($rows);
    }

    /** `proto-redirects/...` for a group or settings file; otherwise as the content-model commands show it. */
    public static function relativeSource(string $source): string
    {
        $normalized = str_replace('\\', '/', $source);
        $at = strrpos($normalized, '/' . GroupFiles::FOLDER . '/');

        return $at !== false ? substr($normalized, $at + 1) : ContentModelCommands::relativeSource($source);
    }

    /**
     * Show which rule fires for a URL, without redirecting.
     *
     * ## OPTIONS
     *
     * <url>
     * : A path (/old) or an absolute URL.
     *
     * [--logged-in]
     * : Evaluate login and role conditions as a logged-in user (the --user, else the first administrator).
     *
     * [--format=<format>]
     * : table or json. Default table.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function test(array $args, array $assoc): void
    {
        $url = trim((string) ($args[0] ?? ''));
        if ($url === '' || ($url[0] !== '/' && preg_match('~^https?://~i', $url) !== 1) || str_starts_with($url, '//')) {
            WP_CLI::error('Pass a path starting with "/" or an http(s) URL.');
        }
        $switched = false;
        if (isset($assoc['logged-in']) && get_current_user_id() === 0) {
            $users = function_exists('get_users') ? get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']) : [];
            $user = (int) ($users[0] ?? 0);
            if ($user === 0 || !function_exists('wp_set_current_user')) {
                WP_CLI::error('--logged-in needs a user: pass --user=<login>.');
            }
            wp_set_current_user($user);
            $switched = true;
        }
        try {
            $result = RedirectsRoutes::evaluate(
                $this->registry(),
                static fn(array $rules, array $settings): Matcher => new Matcher($rules, $settings),
                $url,
                $this->as404
            );
        } finally {
            if ($switched) {
                wp_set_current_user(0);
            }
        }

        if (($assoc['format'] ?? 'table') === 'json') {
            WP_CLI::line((string) json_encode($result, JSON_UNESCAPED_SLASHES));
            return;
        }
        if ($result['trace'] !== []) {
            WP_CLI\Utils\format_items('table', $result['trace'], ['stage', 'group', 'ruleId', 'result', 'reason']);
        }
        if (!$result['matched']) {
            WP_CLI::log('No rule fires for ' . $url . '.');
            return;
        }
        WP_CLI::success(sprintf(
            '%s (%s) fires%s: %s%s%s',
            $result['ruleId'],
            $result['group'],
            $result['stage'] === '404' ? ' when the page is not found' : '',
            $result['action'],
            $result['code'] !== null ? ' ' . $result['code'] : '',
            $result['target'] !== null ? ' → ' . $result['target'] : ''
        ));
    }

    // --- import ----------------------------------------------------------------

    /**
     * Import rules from a CSV, JSON, Apache or _redirects file.
     *
     * ## OPTIONS
     *
     * <file>
     * : The file to read (5 MB at most).
     *
     * [--format=<format>]
     * : csv, json, apache or redirects. Detected when left out.
     *
     * [--group=<slug>]
     * : Put every rule in this group. Without it, a JSON export keeps its groups; everything else goes to "imported".
     *
     * [--duplicates=<mode>]
     * : skip (default), replace or add.
     *
     * [--dry-run]
     * : Report what would change without writing.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function import(array $args, array $assoc): void
    {
        $path = (string) ($args[0] ?? '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            WP_CLI::error('Pass a readable file.');
        }
        if ((int) filesize($path) > Formats::MAX_BYTES) {
            WP_CLI::error('Import files may be at most 5 MB.');
        }
        $text = (string) file_get_contents($path);
        $id = isset($assoc['format']) ? (string) $assoc['format'] : null;
        $format = $id !== null ? Formats::byId($id) : Formats::detect(basename($path), $text);
        if ($format === null) {
            WP_CLI::error($id !== null ? 'Unknown format: ' . Commands::safeArg($id) . '. Use csv, json, apache or redirects.' : 'The format could not be recognised; pass --format.');
            return;
        }
        $mode = $this->mode($assoc);

        $target = isset($assoc['group']) ? (string) $assoc['group'] : null;
        $groups = $target === null && method_exists($format, 'parseGroups') ? $format->parseGroups($text) : [];
        if ($groups !== []) {
            $plan = [];
            foreach ($groups as $slug => $group) {
                $plan[(string) $slug] = ['meta' => $group, 'rows' => self::rowsOf(is_array($group['rules'] ?? null) ? $group['rules'] : [])];
            }
            foreach ($format->parse($text) as $row) {
                if (($row['status'] ?? null) === ParsedRow::INVALID) {
                    $this->warnRow($row);
                }
            }
        } else {
            $plan = [$target ?? 'imported' => ['meta' => [], 'rows' => $format->parse($text)]];
        }
        $this->applyPlan($plan, $mode, isset($assoc['dry-run']));
    }

    /**
     * Import the rules of another redirect plugin.
     *
     * ## OPTIONS
     *
     * <id>
     * : redirection, simple-301-redirects, safe-redirect-manager, rank-math, yoast or old-slugs.
     *
     * [--group=<slug>]
     * : Put every rule in this group instead of the plugin's own groups.
     *
     * [--duplicates=<mode>]
     * : skip (default), replace or add.
     *
     * [--dry-run]
     * : Report what would change without writing.
     *
     * @subcommand import-plugin
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function import_plugin(array $args, array $assoc): void
    {
        $id = (string) ($args[0] ?? '');
        $importer = null;
        $ids = [];
        foreach (($this->importers)() as $candidate) {
            $ids[] = $candidate->id();
            if ($candidate->id() === $id) {
                $importer = $candidate;
            }
        }
        if ($importer === null) {
            WP_CLI::error('Unknown importer: ' . Commands::safeArg($id) . '. Use one of: ' . implode(', ', $ids) . '.');
            return;
        }
        if (!$importer->available()) {
            WP_CLI::error(sprintf('%s has no redirects on this site.', $importer->label()));
        }
        $groups = $importer->preview();
        if (method_exists($importer, 'skipped')) {
            foreach ((array) $importer->skipped() as $row) {
                if (is_array($row)) {
                    $this->warnRow($row);
                }
            }
        }
        $target = isset($assoc['group']) ? (string) $assoc['group'] : null;
        $plan = [];
        foreach ($groups as $slug => $group) {
            $key = $target ?? (string) $slug;
            $plan[$key] ??= ['meta' => $target === null ? $group : [], 'rows' => []];
            array_push($plan[$key]['rows'], ...self::rowsOf(is_array($group['rules'] ?? null) ? $group['rules'] : []));
        }
        if ($plan === []) {
            WP_CLI::warning(sprintf('%s returned no rules.', $importer->label()));
            return;
        }
        $this->applyPlan($plan, $this->mode($assoc), isset($assoc['dry-run']));
        $hint = $importer->cleanupHint();
        if ($hint !== null && !isset($assoc['dry-run'])) {
            WP_CLI::log($hint);
        }
    }

    /** @param array<string, mixed> $assoc */
    private function mode(array $assoc): string
    {
        $mode = (string) ($assoc['duplicates'] ?? 'skip');
        if (!in_array($mode, Merger::MODES, true)) {
            WP_CLI::error('--duplicates must be skip, replace or add.');
        }

        return $mode;
    }

    /**
     * @param list<mixed> $rules raw rules
     * @return list<array<string, mixed>> ParsedRows
     */
    private static function rowsOf(array $rules): array
    {
        $rows = [];
        foreach (array_values($rules) as $i => $rule) {
            $rows[] = is_array($rule) ? ParsedRow::ok($rule, $i + 1) : ParsedRow::invalid('not_an_object', $i + 1);
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function warnRow(array $row): void
    {
        $reason = is_string($row['reason'] ?? null) ? $row['reason'] : 'invalid';
        $message = is_string($row['message'] ?? null) ? $row['message'] : ParsedRow::message($reason);
        WP_CLI::warning(sprintf('Line %d skipped: %s', (int) ($row['line'] ?? 0), $message));
    }

    /**
     * Merge each group's rows into its file (created when missing) and write
     * it, or with $dryRun only report the counts.
     *
     * @param array<string, array{meta: array<string, mixed>, rows: list<array<string, mixed>>}> $plan
     */
    private function applyPlan(array $plan, string $mode, bool $dryRun): void
    {
        $totals = ['added' => 0, 'replaced' => 0, 'skipped' => 0, 'invalid' => 0];
        foreach ($plan as $slug => $entry) {
            $slug = (string) $slug;
            if (preg_match(GroupValidator::SLUG_PATTERN, $slug) !== 1) {
                WP_CLI::warning(sprintf('"%s" is not a valid group slug; its %d rule(s) were not imported. Pass --group.', Commands::safeArg($slug), count($entry['rows'])));
                continue;
            }
            $rows = RedirectsRoutes::checkRows($entry['rows'], $this->registry(), $slug);
            $valid = [];
            foreach ($rows as $row) {
                if ($row['status'] === ParsedRow::INVALID) {
                    $totals['invalid']++;
                    $this->warnRow($row);
                } else {
                    $valid[] = $row['rule'];
                }
            }

            $file = $this->openGroup($slug, true);
            $data = $file['data'];
            if ($file['existing'] === null) {
                foreach (['title', 'enabled', 'position', 'defaults'] as $key) {
                    if (array_key_exists($key, $entry['meta'])) {
                        $data[$key] = $entry['meta'][$key];
                    }
                }
            }
            $merged = Merger::merge(['slug' => $slug, 'defaults' => $this->effectiveDefaults($data)] + $data, $valid, $mode);
            $out = $merged['group'];
            unset($out['slug']);
            if (!array_key_exists('defaults', $data)) {
                unset($out['defaults']);
            }
            foreach (['added', 'replaced', 'skipped'] as $key) {
                $totals[$key] += $merged[$key];
            }
            $summary = sprintf('%s: %d added, %d replaced, %d skipped as duplicates.', $slug, $merged['added'], $merged['replaced'], $merged['skipped']);
            if ($dryRun) {
                WP_CLI::log('Would write ' . $summary);
                continue;
            }
            if ($merged['added'] === 0 && $merged['replaced'] === 0 && $file['existing'] !== null) {
                WP_CLI::log('Unchanged ' . $summary);
                continue;
            }
            $this->assertValid($slug, $out, null);
            $this->save($slug, $out, $file);
            WP_CLI::log('Wrote ' . $summary);
        }
        WP_CLI::success(sprintf(
            '%s%d added, %d replaced, %d skipped as duplicates, %d invalid.',
            $dryRun ? 'Dry run, nothing written: ' : '',
            $totals['added'],
            $totals['replaced'],
            $totals['skipped'],
            $totals['invalid']
        ));
    }

    // --- export ------------------------------------------------------------------

    /**
     * Export rules as CSV, JSON, Apache, Nginx or _redirects.
     *
     * ## OPTIONS
     *
     * [<file>]
     * : Write to this file; "-" or nothing prints to stdout.
     *
     * [--format=<format>]
     * : csv (default), json, apache, nginx or redirects.
     *
     * [--group=<slug>]
     * : Only this group. A JSON export of every group includes the settings.
     *
     * [--force]
     * : Overwrite an existing file.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function export(array $args, array $assoc): void
    {
        $format = (string) ($assoc['format'] ?? 'csv');
        $slug = isset($assoc['group']) ? (string) $assoc['group'] : null;
        $exporter = preg_match('/^[a-z]{1,20}\z/D', $format) === 1 ? RedirectsRoutes::exporter($format, $this->registry()->settings(), $slug === null) : null;
        if ($exporter === null) {
            WP_CLI::error('Unknown format: ' . Commands::safeArg($format) . '. Use csv, json, apache, nginx or redirects.');
            return;
        }
        $groups = RedirectsRoutes::exportGroups($this->registry(), $slug);
        if ($groups === null) {
            WP_CLI::error(sprintf('No redirect group "%s".', Commands::safeArg((string) $slug)));
            return;
        }
        $text = $exporter->render($groups, $this->installer()->tablesExist() ? $this->hits()->all() : []);

        $file = (string) ($args[0] ?? '-');
        if ($file === '-' || $file === '') {
            WP_CLI::line(rtrim($text, "\n"));
            return;
        }
        if (file_exists($file) && !isset($assoc['force'])) {
            WP_CLI::error($file . ' exists; pass --force to overwrite it.');
        }
        if (file_put_contents($file, $text) === false) {
            WP_CLI::error($file . ' could not be written.');
        }
        WP_CLI::success(sprintf('Exported %d group(s) to %s.', count($groups), $file));
    }

    // --- log / prune / db --------------------------------------------------------

    /**
     * Show (or clear) the redirect log or the 404 log.
     *
     * ## OPTIONS
     *
     * [--404]
     * : The 404 log instead of the redirect log.
     *
     * [--limit=<n>]
     * : Rows to show, 1-200. Default 20.
     *
     * [--clear]
     * : Delete every row of the log.
     *
     * [--format=<format>]
     * : table, json or csv.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function log(array $args, array $assoc): void
    {
        $notFound = isset($assoc['404']);
        $table = $notFound ? $this->notFoundLog() : $this->redirectLog();
        $name = $notFound ? '404 log' : 'redirect log';
        if (!$this->installer()->tablesExist()) {
            WP_CLI::error('The redirect tables do not exist; run `wp proto-blocks redirect db install`.');
        }
        if (isset($assoc['clear'])) {
            WP_CLI::success(sprintf('Deleted %d row(s) from the %s.', $table->clear([]), $name));
            return;
        }
        $limit = max(1, min(200, (int) ($assoc['limit'] ?? 20)));
        $result = $table->query([], 1, $limit);
        $columns = $notFound ? self::NOT_FOUND_COLUMNS : self::LOG_COLUMNS;
        $rows = array_map(static function (array $row) use ($columns): array {
            $out = [];
            foreach ($columns as $c) {
                $out[$c] = $row[$c] === null ? '' : (string) $row[$c];
            }
            return $out;
        }, $result['rows']);
        WP_CLI\Utils\format_items((string) ($assoc['format'] ?? 'table'), $rows, $columns);
        WP_CLI::log(sprintf('%d of %d row(s) in the %s.', count($rows), $result['total'], $name));
    }

    /**
     * Delete hit counts of rules that no longer exist and apply log retention now.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function prune(array $args, array $assoc): void
    {
        if (!$this->installer()->tablesExist()) {
            WP_CLI::error('The redirect tables do not exist; run `wp proto-blocks redirect db install`.');
        }
        $hits = $this->hits()->prune(RedirectsRoutes::knownRules($this->registry()));
        $logs = (new Retention($this->registry()->settings(), $this->redirectLog(), $this->notFoundLog()))->run();
        WP_CLI::success(sprintf(
            'Deleted %d orphan hit row(s), %d old redirect log row(s) and %d old 404 log row(s).',
            $hits,
            $logs['redirects'],
            $logs['notFound']
        ));
    }

    /**
     * Manage the redirect tables: install, status, drop.
     *
     * ## OPTIONS
     *
     * <action>
     * : install, status or drop.
     *
     * [--yes]
     * : Confirm drop.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function db(array $args, array $assoc): void
    {
        $action = (string) ($args[0] ?? 'status');
        $installer = $this->installer();
        switch ($action) {
            case 'install':
                if (!$installer->install()) {
                    WP_CLI::error('The redirect tables could not be created. Check the database user\'s CREATE privilege.');
                }
                WP_CLI::success('The redirect tables exist.');
                return;
            case 'status':
                $status = $installer->status();
                WP_CLI::log('Tables: ' . ($status['tables'] ? 'present' : 'missing'));
                WP_CLI::log('Schema version: ' . ($status['version'] ?? 'none'));
                if ($status['tables']) {
                    WP_CLI::log('Orphan hit rows: ' . $this->hits()->orphans(RedirectsRoutes::knownRules($this->registry())));
                }
                return;
            case 'drop':
                if (!isset($assoc['yes'])) {
                    WP_CLI::error('Dropping deletes every hit count and log row (rules are files and stay). Pass --yes to confirm.');
                }
                $installer->drop();
                WP_CLI::success('Dropped the redirect tables.');
                return;
        }
        WP_CLI::error('Unknown db action: ' . Commands::safeArg($action) . '. Use install, status or drop.');
    }

    // --- files -------------------------------------------------------------------

    /**
     * The group file's raw data (read from disk, so rules the registry
     * dropped as invalid are kept), with the writer's view of it. A missing
     * file is a new group when $create, else an error. A group that lives
     * in a root the writer does not write (a parent theme, a filter root) or
     * only in PHP is refused: a write would shadow it.
     *
     * @return array{data: array<string, mixed>, existing: ?Declaration, hash: ?string, path: ?string}
     */
    private function openGroup(string $slug, bool $create): array
    {
        $error = SchemaFileWriter::slugError(self::KIND, $slug);
        if ($error !== null) {
            WP_CLI::error(SchemaFileWriter::slugMessage(self::KIND, $slug, $error));
        }
        $existing = $this->writer()->existing(self::KIND, $slug);
        $loaded = $this->registry()->group($slug);
        if ($loaded !== null && $loaded['origin'] === 'php' && $existing === null) {
            WP_CLI::error(sprintf('The group "%s" is declared in PHP at %s; a file of the same slug would replace it. Use another group.', $slug, self::relativeSource((string) $loaded['source'])));
        }
        if ($loaded !== null && $loaded['origin'] === 'file' && ($existing === null || realpath((string) $loaded['source']) !== realpath($existing->source))) {
            WP_CLI::error(sprintf('The group "%s" is read from %s, which is not in a writable theme folder.', $slug, self::relativeSource((string) $loaded['source'])));
        }
        if ($existing === null) {
            if (!$create) {
                WP_CLI::error(sprintf('No redirect group file "%s".', $slug));
            }
            return ['data' => ['title' => $slug, 'rules' => []], 'existing' => null, 'hash' => null, 'path' => null];
        }
        $text = @file_get_contents($existing->source, false, null, 0, GroupFiles::MAX_BYTES + 1);
        if (!is_string($text)) {
            WP_CLI::error($this->relative($existing->source) . ' could not be read.');
        }
        try {
            $data = Jsonc::decode((string) $text);
        } catch (JsoncException $e) {
            WP_CLI::error(sprintf('%s is not valid JSONC (line %d): fix it before editing it from the command line.', $this->relative($existing->source), $e->line));
            throw $e;
        }
        if (!isset($data['rules']) || !is_array($data['rules'])) {
            $data['rules'] = [];
        }
        $data['rules'] = array_values($data['rules']);

        return ['data' => $data, 'existing' => $existing, 'hash' => SchemaFileWriter::hash((string) $text), 'path' => $existing->source];
    }

    /** @param array<string, mixed> $data */
    private function effectiveDefaults(array $data): array
    {
        $site = $this->registry()->settings()['defaults'];

        return \ProtoBlocks\Modules\Redirects\Definition\Settings::flags(is_array($data['defaults'] ?? null) ? $data['defaults'] : [], is_array($site) ? $site : []);
    }

    /**
     * Refuse a group whose new content would not load: the errors of the
     * rule at $index (or of the whole group) are printed.
     *
     * @param array<string, mixed> $data
     */
    private function assertValid(string $slug, array $data, ?int $index): void
    {
        $analysis = GroupValidator::analyse($data, $slug, $this->registry()->settings()['defaults'], null);
        $errors = $analysis['errors'];
        if ($index !== null) {
            $errors = array_values(array_filter($errors, static fn(array $e): bool => str_starts_with($e['path'], '/rules/' . $index . '/') || $e['path'] === '/rules/' . $index || !str_starts_with($e['path'], '/rules/')));
        }
        foreach ($analysis['warnings'] as $warning) {
            if ($index === null || str_starts_with($warning['path'], '/rules/' . $index)) {
                WP_CLI::warning(self::finding($warning, $index));
            }
        }
        if ($errors !== []) {
            WP_CLI::error(implode("\n", array_map(static fn(array $e): string => self::finding($e, $index), $errors)));
        }
    }

    /** @param array{path: string, message: string} $finding */
    private static function finding(array $finding, ?int $index): string
    {
        $path = $index !== null ? (string) preg_replace('~^/rules/' . $index . '~', '', $finding['path']) : $finding['path'];

        return ($path !== '' ? ltrim($path, '/') . ': ' : '') . $finding['message'];
    }

    /**
     * Serialise and write the group through the writer (file mods, hash, validation).
     *
     * @param array<string, mixed> $data
     * @param array{existing: ?Declaration, hash: ?string, path: ?string} $file
     */
    private function save(string $slug, array $data, array &$file): void
    {
        $text = Serializer::group(['slug' => $slug] + $data, $this->registry()->settings()['defaults']);
        try {
            $written = $this->writer()->write(self::KIND, $slug, $text, $file['hash'], $file['existing']);
        } catch (WriteException $e) {
            $detail = '';
            if (isset($e->data['errors']) && is_array($e->data['errors'])) {
                $detail = "\n" . implode("\n", array_map(static fn($err): string => is_array($err) ? (($err['path'] ?? '') !== '' ? $err['path'] . ': ' : '') . ($err['message'] ?? '') : '', $e->data['errors']));
            }
            WP_CLI::error($e->getMessage() . $detail);
            return;
        }
        $file['path'] = $written['path'];
        $this->registry()->invalidate();
    }

    private function relative(string $path): string
    {
        return self::relativeSource($path);
    }
}
