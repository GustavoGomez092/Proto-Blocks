# Redirects module — interface contract between parallel tracks

Spec: `docs/superpowers/specs/2026-11-05-redirects-design.md`. Every track codes against the shapes here; the integration step wires them together. Arrays, not value objects, as everywhere else in the content model. All classes live under `ProtoBlocks\Modules\Redirects\` unless stated. Tests live under `tests/php/Modules/Redirects/<Area>/`; a track may add stubs only in `tests/php/Modules/Redirects/wp-stubs.php` for names no other directory defines (`StubUniquenessTest` enforces it); the shared stubs already in `tests/php/bootstrap.php` include `$wpdb` (recording, `->queue`/`->calls`), `wp_redirect`, `wp_safe_redirect`, `wp_sanitize_redirect`, `wp_validate_redirect`, `status_header`, `nocache_headers`, `home_url`, `is_ssl`, `is_admin`, `is_404` and the other conditional tags (`pb_test_query`), `is_user_logged_in`, `current_user_can`, `get_post`, `get_permalink`, `get_post_types`, `wp_schedule_event` family (`pb_test_cron`), `is_plugin_active`, `deactivate_plugins`, `current_time` (`pb_test_now`), `dbDelta`, `maybe_unserialize`, `wp_parse_url`, `wp_parse_args`, `rest_get_url_prefix`.

## Rule (normalised)

```php
[
  'id' => 'r_8f3a2c1d',                 // ^r_[a-f0-9]{8}$
  'title' => '',
  'enabled' => true,
  'from' => '/old',                     // null only when when.type === '404'
  'regex' => false,
  'to' => '/new',                       // null for error/nothing
  'else' => null,                       // ?string
  'code' => 301,                        // null for pass/nothing
  'action' => 'url',                    // url|error|pass|nothing
  'when' => null,                       // null | ['type' => ..., 'value' => string|list, 'name' => ?string, 'regex' => bool]
  'query' => 'exact',                   // exact|exactOrder|ignore|pass
  'ignoreCase' => true,
  'ignoreTrailingSlash' => true,
  'log' => true,
  'headers' => [],                      // array<string,string>
  // added by the registry, never written to files:
  'group' => 'legacy-site',
  'origin' => 'file',                   // file|php
  'source' => '/abs/path/redirects.jsonc' | 'functions.php:12',
  'index' => 0,                         // position inside the group
]
```

`when.value` is a string for every type except `ip` and `language` (lists). `name` only for `header` and `cookie`. `regex` only for referrer/agent/header/cookie.

## Group (normalised)

```php
[
  'slug' => 'legacy-site', 'title' => 'Legacy site', 'enabled' => true, 'position' => 10,
  'defaults' => ['ignoreCase' => true, 'ignoreTrailingSlash' => true, 'query' => 'exact'],
  'rules' => [ /* Rule */ ],
  'origin' => 'file'|'php', 'source' => string, 'root' => ?string, 'double' => null|['file' => string, 'php' => string],
]
```

Evaluation order: groups by `position` asc then slug; rules by `index`. The virtual PHP group has slug `php`, title "Declared in PHP", position 100.

## Settings (fully defaulted, spec §1)

`Definition\Settings::defaults(): array` returns every key with its default; `Settings::merge(array $fromFile): array` deep-merges and clamps. Shape exactly as the spec's `settings.jsonc` with camelCase keys.

## Track A — Foundation (owns `RedirectsModule::boot()`)

- `Definition\RuleNormalizer::normalize(array $raw, array $groupDefaults, string $groupSlug): array` — fills defaults, derives `id` when absent (`'r_' . substr(sha1($groupSlug . "\0" . from . "\0" . json(when) . "\0" . action), 0, 8)`), reduces same-host absolute `from` to a path.
- `Definition\RuleValidator::validate(array $rule, string $path = ''): list<array{path:string, code:string, message:string, level:'error'|'warning'}>` — every rule-level code from the spec (`invalid_regex`, `unanchored_regex`, `invalid_target`, `invalid_code`, `self_redirect`, `use_when_server`, `invalid_header`, `duplicate_rule` is group-level).
- `Definition\GroupValidator::validate(array $data): array{errors: list<...>, warnings: list<...>}` — schema (`includes/ContentModel/schemas/redirects.schema.json`, `$defs/group`, `$defs/rule`, `$defs/settings`, validated through `ContentModel\SchemaCheck`) plus rule checks, `duplicate_rule`, `redirect_chain` (needs other groups: `validate(array $data, array $otherRules = [])`).
- `Definition\SettingsValidator::validate(array $data): array{errors, warnings}`.
- `Sources\GroupFiles` (folder `proto-redirects`, basename `redirects`, `SLUG_PATTERN`, loads from `SchemaPaths::roots()`, child wins, `settings.jsonc` read from the same folder), `Sources\PhpDeclarations` (+ `functions.php`: `proto_redirect()`, `proto_redirect_group()`, sealing at `init` 9).
- `Registry::__construct()` (resolves sources itself; tests inject via `Registry::fromArrays(list<Group> $fileGroups, list<Group> $phpGroups, array $settings)`): `groups(): list<Group>`, `rules(): list<Rule>` (evaluation order, disabled groups excluded, disabled rules INCLUDED with `enabled => false` so callers decide), `enabledRules(): list<Rule>`, `settings(): array`, `issues(): list<ContentModel\Issue>`, `group(string $slug): ?array`, `invalidate(): void`, `filesHash(): string`.
- `Serializer::group(array $group): string`, `Serializer::settings(array $settings): string` (spec §10), `DeclarationTemplate` entries for `redirects` and `redirect-settings`.
- `RedirectsModule::registerFileKinds()` (two `extendKind` calls), `boot()` wiring with stubs for the other tracks' entry points, `issues()`.
- Coexistence: `Coexistence::paused(array $settings): bool` (true when `class_exists('Redirection_Admin') || defined('REDIRECTION_VERSION')` and `coexist` false).

## Track B — Engine

- `Engine\Request` (readonly props): `path` (decoded, `//` collapsed, leading `/`), `rawQuery`, `query` (parsed array), `host` (lower, no port), `scheme`, `method`, `headers` (lower-case name → value), `cookies`, `remoteAddr`, `userAgent`, `referrer`, `uri` (original). Constructors: `Request::fromGlobals(): self`, `Request::fromUrl(string $url, array $server = [], array $cookies = []): self`. Method `isExcluded(): bool` (spec §3 exclusions, filter `proto_blocks_redirects_excluded`).
- `Engine\Matcher::__construct(list<Rule> $enabledRules, array $settings)`; `match(Request $r, bool $notFoundStage = false): ?array` returning `['rule' => Rule, 'target' => ?string, 'action' => string, 'code' => ?int, 'reason' => string]`; `trace(Request $r, bool $notFoundStage = false): list<array{group, ruleId, result: 'matched'|'skipped', reason}>`.
- `Engine\Conditions\ConditionInterface { public static function type(): string; public function evaluate(array $when, Request $r): bool; }` one class per type; `Engine\Conditions\Factory::for(string $type): ConditionInterface`.
- `Engine\QueryMatcher::matches(array $rule, Request $r): bool`, `QueryMatcher::passThrough(string $target, Request $r, array $rule): string`.
- `Engine\TargetBuilder::build(array $rule, string $chosenTarget, Request $r, array $captures): ?string` (substitution + safety rules 1–4; null = do not fire).
- `Engine\RegexGuard::compile(string $pattern, bool $ignoreCase): ?string` (delimiters, flags, length cap, u modifier) and `RegexGuard::match(string $compiled, string $subject): ?array` (captures or null; checks `preg_last_error`).
- `Engine\Dispatcher::__construct(array $settings, callable $header, callable $exit, ?HitRecorder $hits, ?RedirectLogger $log)` — `$header(string $name, string $value)` and `$exit()` injectable; `dispatch(array $match, Request $r): void`.
- `Engine\Canonical::target(Request $r, array $settings): ?string`.
- `Engine\Headers::site(array $settings): array<string,string>`, `Headers::redirect(array $rule, array $settings): array<string,string>`, `Headers::valid(string $name, string $value): bool`.
- `Engine\FrontController::__construct(Registry|callable $rules, array $settings, Dispatcher $dispatcher)`; `register(): void` adds `init`, `template_redirect`, `redirect_canonical`, `send_headers` hooks; public `onInit()`, `onTemplateRedirect()`, `onRedirectCanonical($redirectUrl)`, `onSendHeaders()`.
- Interfaces consumed from Track C: `Storage\HitRecorder { record(string $group, string $ruleId): void }`, `Storage\RedirectLogger { logRedirect(array $entry): void; log404(Request $r): void; }`. Track B defines these two interfaces in `Engine/` so it does not depend on C's files; C implements them.

## Track C — Storage, retention, monitor

- `Storage\Schema::tables(string $prefix): array<string,string>` (CREATE TABLE statements), `Storage\Installer::ensure(): void` (option `proto_blocks_redirects_db` = `'1'`), `status(): array{tables: bool, version: ?string}`, `drop(): void`.
- `Storage\Hits implements Engine\HitRecorder`: `record()`, `all(): array<string, array<string, array{hits:int, lastHit:?string}>>`, `reset(string $group, string $ruleId): void`, `prune(array $known): int` (`$known` = list of "group/ruleId"), `delete(string $group, string $ruleId): void`.
- `Storage\RedirectLog implements Engine\RedirectLogger` (both methods, honouring `settings['log']`), `query(array $filters, int $page, int $perPage): array{rows: list<array>, total: int}`, `delete(list<int> $ids): int`, `clear(array $filters): int`, `exportRows(array $filters): iterable`. Filters: `search`, `code`, `group`, `ruleId`, `from`, `to` (dates).
- `Storage\NotFoundLog`: same API plus `grouped(array $filters, int $page, int $perPage): array{rows: list<array{url, count, lastSeen, lastReferrer}>, total}`, `deleteByUrl(string $url): int`; de-duplicates URL+IP per minute.
- `Storage\IpResolver::resolve(array $server, array $logSettings): ?string` and `IpResolver::mask(string $ip): string`; `IpResolver::inCidr(string $ip, string $cidr): bool` (shared with Track B's `ip` condition: B calls `Storage\IpResolver::inCidr`).
- `Storage\Retention::schedule(): void`, `unschedule(): void`, `run(): void` (hook `proto_blocks_redirects_prune`).
- `Monitor::__construct(Registry $registry, SchemaFileWriter $writer, array $settings)`; `register(): void`; `onPrePostUpdate(int $postId, array $data)`, `onPostUpdated(int $postId, $after, $before)`, `onTrashed(int $postId)`; writes through `Serializer::group()` + `SchemaFileWriter::write('redirects', $slug, $text, null)`.

## Track D — Import / export

- `Import\ParsedRow = ['rule' => array (raw rule keys), 'status' => 'new'|'duplicate'|'invalid', 'reason' => ?string, 'line' => int]`.
- `Import\FormatInterface { id(): string; detect(string $filename, string $text): bool; parse(string $text): list<ParsedRow>; }` — `CsvFormat`, `JsonFormat` (Proto-Blocks and Redirection shapes; Redirection JSON returns groups via `parseGroups(string): array<slug, Group-raw>`), `ApacheFormat`, `NetlifyFormat`; `Import\Formats::detect(string $filename, string $text): ?FormatInterface`, `Formats::byId(string $id): ?FormatInterface`.
- `Import\Merger::merge(array $group, list<array> $rules, string $mode /* skip|replace|add */): array{group: array, added: int, replaced: int, skipped: int}`.
- `Import\PluginImporterInterface { id(): string; label(): string; available(): bool; preview(): array<string, array> /* slug => raw group */; cleanupHint(): ?string; }`; `Import\PluginImporters::all(): list<PluginImporterInterface>`, `available()`, `byId()`. Classes: `RedirectionImporter`, `Simple301Importer`, `SafeRedirectManagerImporter`, `RankMathImporter`, `YoastImporter`, `OldSlugsImporter`.
- `Export\ExporterInterface { format(): string; mime(): string; extension(): string; render(list<Group> $groups, array $hits): string; }` — `CsvExporter`, `JsonExporter`, `ApacheExporter`, `NginxExporter`, `NetlifyExporter`; `Export\Exporters::byFormat(string): ?ExporterInterface`.
- Pure PHP; fixtures under `tests/php/Modules/Redirects/Import/fixtures/`.

## Track E — Admin UI (React)

- Types in `src/admin/redirects/types.ts` mirror Rule/Group/Settings and the `GET admin/redirects` response (spec §11). Zod schemas in `src/admin/redirects/schema.ts` built from `includes/ContentModel/schemas/redirects.schema.json` the way `content-model/schema.ts` does.
- File edits with `jsonc-parser` through `src/admin/redirects/edits.ts`: `addRule(text, rule)`, `updateRule(text, id, rule)`, `removeRule(text, id)`, `moveRule(text, id, delta)`, `setRuleEnabled(text, id, bool)`, `setGroupField(text, key, value)`, `newGroupText(slug, title)`. Writes go through the existing `useDeclarationFile('redirects', slug)` and `useDeclarationFile('redirect-settings', 'settings')`.
- API endpoints consumed: exactly spec §11. Tests mock them with `mockApi`.
- Screen file `src/admin/screens/RedirectsScreen.tsx` (replace the placeholder), everything else under `src/admin/redirects/`.

## Track F — REST + CLI (starts after A merges; uses B, C, D)

- `API\Admin\RedirectsRoutes` (skeleton exists): constructor with all-optional parameters (`?Registry`, `?Hits`, `?RedirectLog`, `?NotFoundLog`, `?Installer`), routes of spec §11, errors as `WP_Error('proto_blocks_*', msg, ['status' => N])`.
- `CLI\RedirectCommand` (skeleton exists): spec §12.

## Shared rules

- Nobody edits `Manager.php`, `AdminApp.php`, `ContentModelMenu.php`, `AdminRestController.php`, `Commands.php`, `proto-blocks.php`, `jest.setup.ts`, `screens/index.ts`, `bootstrap.ts` or `tests/php/bootstrap.php`; they are wired. Track A alone edits `RedirectsModule.php` and `functions.php`.
- New files only inside the track's folders listed above; a track that must touch another track's file says so in its report instead.
- Every public behaviour has a PHPUnit or Jest test; TDD; commit per task; plain commit messages (no attribution lines of any kind).
