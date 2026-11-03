# Phase 3: Settings Pages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a theme declare WordPress settings pages in one PHP line or a `proto-options/<slug>/page.jsonc` file (menu placement, capability, icon, tabs, child pages, fields), render them with the same React field stack as the Phase 2 meta boxes, save values through REST, read them in templates with `proto_field($name, 'option:<page>')`, and manage the declarations from an Options Pages admin screen that writes JSONC through the Phase 1 file writer.

**Architecture:** A pure-PHP `OptionsPages` layer (`PageDefinition` normaliser, `ProtectedOptions`, `OptionsStore`, `MenuRegistrar`) does the work that needs nothing from other phases and starts first. Discovery, precedence, double-declaration warnings, the schema-file write route and the CLI `validate` report are Phase 1's (`ContentModel\Registry`, `FileLoader`, `SchemaFileWriter`), extended by one declaration kind `options`. Values go through Phase 2's field model (`GroupNormalizer`, `TypeRegistry`, `ValueValidator`, `BoxPayload`) behind `GET|POST proto-blocks/v1/options/<slug>`, guarded by the page's own capability; the getter's `option:` context is Phase 2's `FieldReader`, fed this phase's groups and array-aware store through two filters. The rendered page is the Phase 0 admin bundle mounted as screen `options-page` and hosting Phase 2's `FieldsForm`; the declaration editor is screen `options-pages`. `Modules\OptionsPages` wires the hooks and is the only file that knows all the pieces.

**Tech Stack:** PHP 8.1, WordPress 6.5+ (floor raised in Phase 2), PHPUnit 10 without WordPress, React 18, TypeScript, react-hook-form + zod, shadcn/ui, `jsonc-parser`, Jest 30, Playwright on `@wordpress/env`.

**Spec:** `docs/superpowers/specs/2026-11-02-post-types-fields-settings-design.md` (section 7 in full; 1, 4, 6, 8, 9 where they touch options pages; 5 "Storage" and "Getter" for the `option:` context; 10 "Phase 3").

**Needs other phases merged first:**
- Nothing: Tasks 1, 2, 3 (group A, start immediately).
- Phase 0 (route groups) + Phase 1 (content-model registry, writer, schema-file routes, CLI, client libraries): Tasks 4, 8, 10 (group B).
- Phase 2 PHP (field model, types, validator, stores, reader, `BoxPayload`, `FieldsModule`): Tasks 5, 6 (group C).
- Phase 0 shell + Phase 1 TS + Phase 2 React (`FieldsForm`, builder pieces): Tasks 7, 9 (group D).
- Everything above plus the E2E harness on `feat/content-model` (`.wp-env.json`, `playwright.config.ts`, `tests/e2e/global-setup.ts`): Tasks 11–14 (group E).

## Cross-phase contracts (consumed, not defined here)

The names below are taken from the Phase 0, 1 and 2 plans as written on 2026-11-02. **Before dispatching group B, C or D, the coordinator diffs this table against the merged Phase 1 and Phase 2 code and renames here, not there.** Rows marked **(added here)** are small extensions this plan makes to an earlier phase's file, inside the named task; if the earlier phase already shipped an equivalent, use it and update this table instead.

| From | Name used here | Role |
|---|---|---|
| Phase 0 | `AdminApp::renderScreen(string $screen): void`, `AdminApp::SCREENS`, `AdminApp::MOUNT_ID`, `AdminApp::PORTAL_ID` | mount and bootstrap |
| Phase 0 | `includes/API/Admin/<Name>Routes.php` with `registerRoutes(string $namespace, callable $permission, callable $writePermission): void`; `AdminRestController::ROUTE_GROUPS` | admin routes |
| Phase 0 | `adminGet/adminPost/adminDelete`, `ApiError { code; status }` (**added here**, Task 7: `data?: unknown` copied from the apiFetch rejection, if Phase 1 has not added it), `getBootstrap()`, `Screen` union in `src/admin/bootstrap.ts`, `SCREENS` in `src/admin/screens/index.ts`, shell `PageHeader, StatCard, ReadOnlyBanner, ConfirmDialog, PortalProvider, ScreenError`, Jest `mockBootstrap`, `mockApi` | admin UI |
| Phase 0 | `Assets::enqueueAdminAssets(string $hook)` | admin bundle enqueue |
| Phase 1 | Namespace `ProtoBlocks\ContentModel`: `Jsonc::decode(string $text): array` (throws `JsoncException` with `line`, `column`) | server JSONC parse |
| Phase 1 | `SchemaCheck::schema(): array`, `SchemaCheck::validate(mixed $data, array $schema, string $ref = '#'): list<array{path: string, message: string}>`; schema file `includes/ContentModel/schemas/content-model.schema.json` (**added here**, Task 4: `$defs.options`) | validation |
| Phase 1 | `FileLoader::FOLDERS`, `FileLoader::BASENAMES`, `FileLoader::MAX_BYTES` (**added here**, Task 4: `'options' => 'proto-options'`, `'options' => 'page'`); `SchemaPaths::roots()`, `SchemaPaths::writableRoots()` | discovery |
| Phase 1 | `Slug::fromName(string $name, int $max): string`, `Slug::error(string $slug, string $kind): ?string` (**added here**, Task 4: kind `options`, `Slug::OPTIONS_MAX = 64`, `Slug::OPTIONS_RESERVED`) | slugs |
| Phase 1 | `PhpDeclarations::add(string $kind, string $name, array $args, string $source): void` | PHP declarations |
| Phase 1 | `Registry::get(string $kind, string $slug): ?Declaration`, `Registry::doubles(): list<array{kind, slug, file, php}>`, `Registry::issues(): list<Issue>`, `Registry::invalidate(): void`, (**added here**, Task 4, unless Phase 2 added it for its C4) `Registry::all(string $kind): array<string, Declaration>`; `Declaration { kind, slug, data, origin: 'file'|'php', source, root }`; `Issue { level, code, message, source, slug, kind }` | precedence, file wins, double-declaration warning |
| Phase 1 | `MenuIcon::resolve(string $value, string $themeDir, string $themeUrl): array{icon: string, warning: ?string}` | menu icon rules |
| Phase 1 | `SchemaFileWriter::{target, write, delete}`, `WriteException { errorCode, status, data }`, `DeclarationTemplate::render(string $kind, string $name, ?string $slug = null): string` (**added here**, Task 4: `options` branch delegating to `PageTemplate::render`) | file writes |
| Phase 1 | REST `schema-files/(?P<kind>type\|taxonomy)/(?P<slug>…)` GET / EDITABLE / DELETE in `includes/API/Admin/SchemaFilesRoutes.php` (**added here**, Task 4: kind `options`, slug quantifier widened to `{0,63}`) | declaration read/write route |
| Phase 1 | `OriginTracker::classify(string $file, string $pluginDir, string $muPluginDir, array $themeDirs, string $protoDir): array{type, name}` | external pages' origin |
| Phase 1 | `ProtoBlocks\Modules\ModuleInterface { id(): string; boot(): void }`; `Modules\Manager` (`DEFAULTS`, `isEnabled(string)`, `states()`), filter `proto_blocks_modules` (array id => bool) | module layer |
| Phase 1 | `ContentModelCommands::validationRows(Registry $registry, array $moduleIssues): array` called from `Commands::validate()` | CLI validate |
| Phase 1 | TS `src/admin/content-model/types.ts` (`Kind` (**added here**, Task 4: `'options'`), `Issue`, `FileResult`), `jsonc.ts` (`parseJsonc(text): { data; errors }`, `applyDeclarationEdits(text, before, after): string`), `schema.ts` (`toZod(schema, root?)`; **added here**, Task 4: `optionsSchema`), `useDeclarationFile(kind, slug, name?)` (`file`, `save(next, acknowledge?)`, `remove(confirm?)`, `reload()`); components `OriginBadge`, `IssueList`, `DeclarationShell`, `ConflictDialog`, `MenuIconField` in `src/admin/components/content-model/` | declaration editing UI |
| Phase 2 | Namespace `ProtoBlocks\Modules\Fields`: `Definition\GroupNormalizer::normalize(array $raw, string $source, ?string $path = null, ?array $impliedLocation = null): array{group: ?FieldGroup, report: Report}`; `Definition\Field`, `Definition\FieldGroup` (`key`, `fields`, `raw`, `field(name)`) | normalise |
| Phase 2 | `Types\TypeRegistry::withCoreTypes()`, `->for(Field): FieldTypeInterface` (`normalize`, `sanitize` — `null` means refuse, keep the stored value — `escape`, `emptyValue`, `stores()`) | trust boundary per type |
| Phase 2 | `Validation\ValueValidator::validate(array $fields, array $input, ?callable $choiceResolver = null, ?callable $maxRows = null): array{valid: array<string, mixed>, errors: array<string, string>, hidden: list<string>}` and its fixed message copy (`This field is required.`, `Choose one of the listed options.`, …) | validation, conditional logic |
| Phase 2 | `Storage\ObjectRef::option(string $page)`, `Storage\Store { get(ObjectRef, Field): mixed; set(ObjectRef, Field, mixed): void; has(ObjectRef, Field): bool }`, `Storage\OptionStore` (option `<page>_<name>`) | storage contract |
| Phase 2 | `Registry\GroupIndex(list<FieldGroup>)` with `forRef(ObjectRef, array $ctx)`, `fieldFor(ObjectRef, string)`; `Read\FieldReader(GroupIndex, TypeRegistry, array $stores)` with `get`, `all`, `rows`; `Read\ContextResolver::resolve('option:<page>')` | getter |
| Phase 2 | `Edit\BoxPayload(TypeRegistry, FieldReader, ErrorStore)->build(FieldGroup, ObjectRef, int $userId): array`; `Edit\ErrorStore` | page payload |
| Phase 2 | `FieldsModule::instance()->index()`; filter `proto_blocks_declaration_errors(array $errors, string $kind, array $data)` run by the Phase 1 writer before every write and by `validate` (Phase 2 Task 8/15; **added here**, Task 4: a fourth argument `string $slug`); (**added here**, Task 6) filters `proto_blocks_field_groups(list<FieldGroup>)` and `proto_blocks_field_stores(array<string, Store>)` inside `FieldsModule` | integration points |
| Phase 2 | TS `FieldsForm({ payload: BoxPayload })` in `src/fields/box/FieldsForm.tsx`; `BoxPayload`, `FieldDef` in `src/fields/model/types.ts`; `inputName`, `PRESENT_NAME` in `src/fields/box/names.ts`; builder pieces `FieldTree`, `FieldSettings`, `AddFieldMenu`, `ConditionBuilder` in `src/admin/screens/fields/`, `reduce`, `RawGroup`, `BuilderAction` in `src/admin/screens/fields/builder-state.ts` | form and builder |

## Global Constraints

- PHP 8.1+, WordPress 6.5+. Text domain `proto-blocks` for every user-visible string (`__()` / `@wordpress/i18n`).
- Declarations are files or PHP only; nothing about an options page's *declaration* is written to the database. Only field *values* are options.
- Folder `proto-options/<slug>/page.jsonc` (`.json` accepted); the folder name is the page slug and the WordPress menu slug; slug grammar `^[a-z][a-z0-9-]*$`, at most 64 characters.
- `menu`: `"top"` | any parent menu slug (including `edit.php?post_type=<type>` and `proto-blocks`); default `"top"`.
- `capability` defaults to `manage_options` when the key is absent; any capability string matching `^[a-z0-9_]+$` is accepted and used verbatim (never widened, never replaced by a default). Editing *declarations* always requires `manage_options` (+ `AdminPage::fileModsAllowed()` and a writable theme for writes).
- Storage default (spec section 5): one option per field named `<page_slug>_<field_name>` exactly (`theme-settings` + `phone` → `theme-settings_phone`, the same name Phase 2's `OptionStore` writes), autoloaded; `"autoload": false` on the page turns autoload off for its options. `"storage": {"mode": "array", "option": "<name>"}` keeps every field of the page in one array option, also autoloaded per `autoload`.
- Values REST: `GET|POST proto-blocks/v1/options/<slug>` with the `wp_rest` nonce and `permission_callback` = `current_user_can($page->capability)`, nothing else.
- Getter context `'option:<slug>'`; values escaped for their type by default, `$raw = true` returns stored values.
- Security (spec section 6): per-type sanitising for users without `unfiltered_html` through Phase 2's `TypeRegistry`; `code` fields writable only with `unfiltered_html`; only declared field names are ever written; protected core option names are never written; no PHP execution from declaration files; schema validation before any file write (Phase 1 writer).
- Out of scope: adding fields to existing core settings screens (the O-Flex reading-page toggle stays in PHP).
- REST errors: `new WP_Error('proto_blocks_<code>', __(..., 'proto-blocks'), ['status' => N])`.
- PHPUnit stubs for this phase live in `tests/php/OptionsPages/wp-stubs.php`; every stub is `function_exists`/`class_exists` guarded and uses the same globals and semantics as Phase 1's `tests/php/ContentModel/stubs/*.php` and Phase 2's `tests/php/Modules/Fields/wp-stubs.php` (`$GLOBALS['pb_test_options']`, `pb_test_caps`, `pb_test_hooks`, `pb_test_filters`, `pb_test_routes`), and from Task 4 on it `require_once`s those files first when they exist, so one process sees one emulation.
- Commit messages `<type>(<scope>): <subject>` with no attribution lines. `composer test` and `npm run test:js` pass after every task.

## Review Focus

1. **Adopting an existing array option** (CADCO): saving from the new page keeps keys the declaration does not list and keeps the stored scalar format of booleans (`'1'`/`''` stays string, `1`/`0` stays int), so the theme's existing PHP reads still work. Tests: Task 2 (`testArrayModePreservesUndeclaredKeys`, `testTrueFalseKeepsExistingFormat`), Task 13.
2. **An untrusted page user** (has the page capability, lacks `unfiltered_html`) posts `<script>` into a text field and an undeclared key: the text is sanitised, the undeclared key is dropped; the front end prints trusted markup escaped. Tests: Task 5 (`testUntrustedValuesAreSanitised`, `testUndeclaredKeysDropped`), Task 6 (`testOptionContextEscapesByDefault`), Task 12.
3. **A conditionally hidden field** (CADCO's heading while placement is `related`): not validated, not written, its stored value kept; revealed again it shows the stored text. Tests: Task 5 (`testHiddenFieldNeitherValidatedNorWritten`), Task 7, Task 13.
4. **Capability boundaries in the UI:** a user without the page capability gets no menu item and 403 on `options/<slug>`; a child page without its own capability inherits the parent's; a `manage_woocommerce`-only user sees the page but not the "Edit declaration" link and gets 403 on `admin/options-pages`. Tests: Task 3, Task 5 (`testPermissionUsesPageCapability`), Task 7, Task 8 (`testRoutesUseManageOptions`), Task 13.
5. **A broken declaration or parent** (invalid JSONC, schema errors, `menu` pointing at a parent that does not exist, duplicate field names across tabs): no fatal, the page is skipped or registered as a hidden page, and the problem shows as a warning on the Options Pages screen and in `wp proto-blocks validate`. Tests: Task 1 (`testDuplicateFieldNamesAcrossTabsRejected`), Task 3 (`testMissingParentRecordsWarning`), Task 4 (`testInvalidFileIsSkippedWithWarning`, `testBrokenFileDoesNotFallBackToPhp`).

## Security Review Focus

1. **A `manage_woocommerce` user saving to a protected option name** (`"storage": {"option": "default_role"}`, or slug + field deriving `users_can_register`, `siteurl`, `<prefix>user_roles`, `_transient_*`, a salt): the declaration is refused when loaded and when written, and the save path refuses again at the lowest layer even if the deny-list changed after load or a standalone group adds a field that derives a protected name. Tests: Task 1 (`testProtectedOptionNamesAreRefused`), Task 2 (`testSaveRefusesProtectedOptionName`), Task 4 (`testDeclarationErrorsFilterRefusesProtectedOption`), Task 5 (`testProtectedOptionRefusedAtSave`, `testStandaloneFieldDerivingProtectedNameDropped`), Task 10 (`testCreateRefusesProtectedStorageOption`).
2. **An options-page `code` field saved by a user without `unfiltered_html`** (through the form or a hand-made REST body): the stored value is untouched and the box renders it read-only with no input. Tests: Task 5 (`testUntrustedCodeFieldUntouched`), Task 7 (read-only render), Task 12 (`shopmanager` E2E incl. direct REST).
3. **A REST save without a nonce** (cookie-only request): WordPress treats it as logged out, `permission` is false, 401, nothing written. Tests: Task 5 (`testPermissionDeniedForLoggedOut`), Task 12 (fresh request context without `X-WP-Nonce`).
4. **A page capability typo** (`manage_woocomerce`, `Manage_Options`, `""`): fails closed — a grammar error refuses the page; a well-formed unknown capability is passed through verbatim (nobody but super admins can open it), warned about, and never replaced by `manage_options` or `read`. Tests: Task 1 (`testInvalidCapabilityRejected`, `testCapabilityKeptVerbatim`), Task 3 (`testUnknownCapabilityKeptAndWarned`), Task 5 (`testUnknownCapabilityFailsClosed`).
5. **Path traversal in the page slug** (`../x`, `a/b`, `A`, a 65-character slug) in a PHP declaration, a folder name, the REST routes and the CLI: refused by grammar before any path is built; writes stay inside the theme. Tests: Task 1 (`testSlugGrammarEnforced`), Task 4 (`testSchemaFileRoutePatternRejectsTraversal`), Task 5 (`testRoutesRegistered`), Task 8 (`testDetailRoutePatternRejectsTraversal`), Task 10 (`testCreateWritesConfinedFile`).

---

## File structure

**PHP (create)** under `includes/OptionsPages/` (namespace `ProtoBlocks\OptionsPages`)
- `PageDefinition.php`, `InvalidDeclaration.php` — immutable value object and normaliser for one page (and its children): slug, title, menu, capability, icon, position, autoload, storage, raw groups and tabs, children, option names.
- `ProtectedOptions.php` — the deny-list check for option names.
- `OptionsStore.php` — reads and writes values for a `PageDefinition` in per-field or array mode.
- `MenuRegistrar.php` — `admin_menu` registration of pages and child pages; collects hook suffixes and warnings.
- `OptionsPagesRegistry.php` — `PageDefinition`s built from Phase 1 `Registry` declarations of kind `options`; warnings; the `proto_blocks_declaration_errors` callback.
- `PageTemplate.php` — the commented `page.jsonc` template (CLI, schema-file GET for a new page).
- `OptionsValues.php` — groups, read, validate, sanitise and save pipeline used by REST and the renderer.
- `OptionsFieldStore.php` — Phase 2 `Store` for kind `option`, aware of array mode.
- `FieldsBridge.php` — callbacks for `proto_blocks_field_groups` and `proto_blocks_field_stores`.
- `PageRenderer.php` — the menu callback: capability re-check, page payload inline script, `AdminApp::renderScreen('options-page')`.
- `ExternalMenus.php` — captures non-Proto-Blocks settings pages from `$menu`/`$submenu` for the list screen.
- `functions.php` — `proto_options_page()`.
- `includes/API/OptionsValuesRoutes.php` — `options/<slug>` routes.
- `includes/API/Admin/OptionsPagesRoutes.php` — `admin/options-pages` routes (route group).
- `includes/CLI/OptionsCommand.php` — `wp proto-blocks options list|create`.
- `includes/Modules/OptionsPages.php` — module wiring.

**PHP (modify)**
- `includes/helpers.php` — one `require_once __DIR__ . '/OptionsPages/functions.php';` (Task 1).
- Phase 1 files (Task 4): `includes/ContentModel/FileLoader.php` (one entry each in `FOLDERS`, `BASENAMES`), `includes/ContentModel/Slug.php` (kind `options`), `includes/ContentModel/PhpDeclarations.php` (slug max by kind), `includes/ContentModel/Registry.php` (`all(string $kind)`), `includes/ContentModel/schemas/content-model.schema.json` (`$defs.options`), `includes/ContentModel/DeclarationTemplate.php` (one `options` branch), `includes/ContentModel/SchemaFileWriter.php` and `includes/CLI/ContentModelCommands.php` (pass `$slug` to `proto_blocks_declaration_errors`), `includes/API/Admin/SchemaFilesRoutes.php` (kind alternation, slug quantifier), `tests/php/ContentModel/stubs/hooks.php` (`update_option` records autoload).
- Phase 2 files: `tests/php/Modules/Fields/wp-stubs.php` (`update_option` records autoload; Task 5), `includes/Modules/Fields/FieldsModule.php` (two `apply_filters` lines; Task 6).
- `includes/Modules/Manager.php` — `DEFAULTS` += `'options_pages' => true`, construct/boot `OptionsPages`, accessor `optionsPages(): ?OptionsPages` (Task 11).
- `includes/API/AdminRestController.php` — `OptionsPagesRoutes::class` in `ROUTE_GROUPS` (Task 8).
- `includes/Admin/AdminApp.php` — `'options-page'` (Task 7), `'options-pages'` (Task 9) in `SCREENS`.
- `includes/Admin/AdminPage.php` — one `add_submenu_page` for `proto-blocks-options-pages` (Task 9).
- `includes/Admin/Assets.php` — `enqueueAdminAssets` also accepts hooks returned by `apply_filters('proto_blocks_admin_app_hooks', [])` (Task 7).
- `includes/CLI/Commands.php` — `WP_CLI::add_command('proto-blocks options', OptionsCommand::class);` (Task 10); module issues passed to `validationRows` (Task 11).

**Tests (create)**
- `tests/php/OptionsPages/wp-stubs.php`, `tests/php/OptionsPages/{PageDefinitionTest,OptionsStoreTest,MenuRegistrarTest,OptionsKindTest,OptionsPagesRegistryTest,PageTemplateTest,OptionsValuesTest,OptionContextTest,PageRendererTest,CadcoAcceptanceTest,ModuleTest}.php`, `tests/php/API/OptionsPagesRoutesTest.php`, `tests/php/CLI/OptionsCommandTest.php`.
- `tests/fixtures/options-pages/` — `cadco-display-settings/page.jsonc`, `theme-settings/page.jsonc` (tabs + children), `invalid/{bad-jsonc,unknown-mode,missing-title}.jsonc`; shared by PHPUnit and Jest.
- `src/admin/__tests__/{options-page,options-pages,options-schema,options-declaration,options-form-data}.test.ts(x)`.
- `tests/e2e/{options-page,options-pages-screen,cadco-display-settings}.spec.ts`, `tests/e2e/fixtures/mu-plugins/pb-e2e-options.php`, `tests/e2e/fixtures/proto-options/{theme-settings,shop-notices,cadco-display-settings}/page.jsonc`, `tests/e2e/fixtures/blocks/options-probe/{block.json,template.php}`.

**TS (create)**
- `src/admin/screens/OptionsPageScreen.tsx` (rendered page), `src/admin/screens/OptionsPagesScreen.tsx` (list + edit), `src/admin/options-pages/{OptionsPageList,OptionsPageEditor,TabsEditor,ChildPagesEditor,MenuPlacementField,CapabilityField,StorageField,PageFieldsBuilder}.tsx`, `src/admin/options-pages/types.ts`, `src/admin/options-pages/formData.ts` (form → REST body), `src/admin/options-pages/declaration.ts` (form ↔ declaration data).

---

### Task 1: PageDefinition, option names and `proto_options_page()`

**Parallel group:** A
**Depends on:** none

**Files:**
- Create: `includes/OptionsPages/PageDefinition.php`, `includes/OptionsPages/InvalidDeclaration.php`, `includes/OptionsPages/ProtectedOptions.php`, `includes/OptionsPages/functions.php`, `tests/php/OptionsPages/wp-stubs.php`, `tests/php/OptionsPages/PageDefinitionTest.php`, `tests/fixtures/options-pages/cadco-display-settings/page.jsonc`, `tests/fixtures/options-pages/theme-settings/page.jsonc`
- Modify: `includes/helpers.php` (one `require_once`)

**Interfaces:**
- Produces:
  ```php
  final class PageDefinition {
      public const MODE_FIELDS = 'fields';
      public const MODE_ARRAY = 'array';
      public readonly string $slug; public readonly string $title; public readonly string $menuTitle;
      public readonly string $menu;          // 'top' or parent slug
      public readonly string $capability;    // 'manage_options' when the key is absent; verbatim otherwise; children inherit the parent's
      public readonly ?string $icon; public readonly ?int $position;
      public readonly bool $autoload;        // default true
      public readonly string $storageMode;   // MODE_FIELDS | MODE_ARRAY
      public readonly ?string $storageOption;
      public readonly array $groups;         // untabbed raw ACF-shaped groups (rendered above the tab strip)
      public readonly array $tabs;           // list<array{title: string, groups: list<array>}>
      public readonly array $children;       // list<PageDefinition>, slug "<parent>-<child>"
      public readonly ?string $parentSlug;
      public readonly string $origin;        // 'file' | 'php'
      public readonly ?string $source;       // absolute file path, or "<file>:<line>" of the PHP call
      public readonly ?string $root;         // schema root the file was found in (icon paths resolve against it); null for PHP
      public static function fromArray(string $slug, array $data, string $origin, ?string $source = null, ?string $root = null, ?PageDefinition $parent = null): self; // throws InvalidDeclaration
      public static function slugFromTitle(string $title): ?string;  // sanitize_title, '_' → '-', grammar ^[a-z][a-z0-9-]*$, max 64, else null
      public function fields(): array;               // every top-level raw field across groups and tabs, in order, keyed by name
      public function optionName(string $field): string;  // per-field: $slug . '_' . $field (hyphens kept); array mode: storageOption
      public function toClientArray(): array;        // JSON-safe shape (no source, no root)
  }
  final class InvalidDeclaration extends \InvalidArgumentException { public function errors(): array; } // list<array{path: string, message: string}>
  final class ProtectedOptions { public static function isProtected(string $option): bool; }
  function proto_options_page(string $title, array $args = []): void;  // until Task 4: $GLOBALS['proto_blocks_php_options_pages'][$slug] = ['data' => $args + ['title' => $title], 'source' => "<file>:<line>"]; Task 4 routes it to PhpDeclarations::add('options', ...)
  ```
  Field-group shape (spec section 7: fields reuse the Phase 2 schema): `fields`, `tabs[].fields` and `children[].fields` entries are either ACF field groups (have a `fields` key and no `type`) or bare fields (have `type`). Consecutive bare fields are wrapped in an implicit group `{key: "group_<slug_underscored>_<n>", title: <page or tab title>, fields: [...]}` (`<n>` counts implicit groups from 1 in page order). A bare field without `key` gets `field_<slug_underscored>_<name>`. The `location` of a group inside a page is implied (`options_page == <slug>`) and may be omitted.

- [ ] **Step 1: Write the fixtures**

`tests/fixtures/options-pages/cadco-display-settings/page.jsonc` (comments included; this is the done-criterion file):
```jsonc
{
  // CADCO product display settings, adopted from the theme's hand-written page.
  "title": "Display Settings",
  "menu": "edit.php?post_type=product",
  "capability": "manage_woocommerce",
  "storage": { "mode": "array", "option": "cadco_display_settings" },
  "fields": [
    { "key": "field_cadco_hide_children", "name": "hide_children", "label": "Hide child products", "type": "true_false" },
    { "key": "field_cadco_children_placement", "name": "children_placement", "label": "Child product placement", "type": "radio",
      "choices": { "related": "Related products", "section": "Own section" }, "default_value": "related" },
    { "key": "field_cadco_children_heading", "name": "children_heading", "label": "Section heading", "type": "text",
      "conditional_logic": [[ { "field": "field_cadco_children_placement", "operator": "==", "value": "section" } ]] },
  ],
}
```
`theme-settings/page.jsonc`: `menu: "top"`, `icon: "dashicons-admin-generic"`, `autoload: false`, two tabs (`General` with a `text` field `phone`, `Tracking` with a `code` field `head_scripts`), one child `{ "slug": "footer", "title": "Footer", "fields": [ { "name": "copyright", "type": "text" } ] }`.

- [ ] **Step 2: Write the stubs**

`tests/php/OptionsPages/wp-stubs.php` (guarded, per the Global Constraints): `get_option`/`update_option` on `$GLOBALS['pb_test_options']` with `update_option` recording its third argument in `$GLOBALS['pb_test_autoload'][$name]`, `delete_option`, `add_menu_page`/`add_submenu_page` recording `['fn', 'parent', 'page_title', 'menu_title', 'capability', 'slug', 'icon', 'position']` into `$GLOBALS['pb_test_menus']` and returning `'hook_' . $slug`, globals `$menu`, `$submenu`, `$admin_page_hooks` seeded from `$GLOBALS['pb_test_parent_menus']`, `current_user_can`/`user_can` reading `$GLOBALS['pb_test_caps'][<user id>]`, `get_current_user_id` reading `$GLOBALS['pb_test_user_id'] ?? 0`, `sanitize_title`, `__`, `add_action`/`add_filter` recording into `$GLOBALS['pb_test_hooks']`, `apply_filters` applying callables in `$GLOBALS['pb_test_filters'][$tag]`, `_doing_it_wrong` recorder, `wp_die` recorder (throws a test exception carrying the status), `WP_Error`, `WP_REST_Request`, `WP_REST_Response`, `register_rest_route` recording into `$GLOBALS['pb_test_routes']`, and a `$wpdb` object with `prefix = 'wp_'`. Tasks 2 and 3 use this file unchanged.

- [ ] **Step 3: Write the failing tests**

`tests/php/OptionsPages/PageDefinitionTest.php` (decode fixtures with a local comment-stripping helper in the test, not Phase 1's):
- `testDefaults`: `fromArray('my-page', ['title' => 'My Page'], 'php')` → `menu === 'top'`, `capability === 'manage_options'`, `autoload === true`, `storageMode === 'fields'`, `menuTitle === 'My Page'`.
- `testSlugFromTitle`: `'Theme Settings'` → `theme-settings`; `'My_Page'` → `my-page`; `'9 Lives'` → null; a 70-char title → null.
- `testSlugGrammarEnforced`: `fromArray('../x', …)`, `fromArray('a/b', …)`, `fromArray('A', …)`, a 65-character slug, and a child `{"slug": "../evil", "title": "X"}` each → `InvalidDeclaration`.
- `testOptionNamePerField`: `theme-settings` page → `optionName('phone') === 'theme-settings_phone'`.
- `testOptionNameArrayMode`: CADCO fixture → `optionName('hide_children') === 'cadco_display_settings'`, `storageMode === 'array'`.
- `testBareFieldsAreWrappedAndKeyed`: CADCO `fields()` has keys `hide_children`, `children_placement`, `children_heading` in order; `groups[0]['key'] === 'group_cadco_display_settings_1'`; `theme-settings` `phone` field gets `key === 'field_theme_settings_phone'`.
- `testTabsAndUntabbedGroups`: `theme-settings` → `count($tabs) === 2`, `$tabs[1]['title'] === 'Tracking'`, `fields()` contains `head_scripts`.
- `testChildrenInheritCapabilityAndGetCompoundSlug`: parent capability `edit_theme_options`, child without capability → child `slug === 'theme-settings-footer'`, `capability === 'edit_theme_options'`, `parentSlug === 'theme-settings'`, `menu === 'theme-settings'`, child `storageMode === 'fields'`, `optionName('copyright') === 'theme-settings-footer_copyright'`.
- `testDuplicateFieldNamesAcrossTabsRejected`: same `name` in two tabs → `InvalidDeclaration` whose `errors()` has a `message` equal to `Field name "phone" is used twice on page "theme-settings".`
- `testInvalidCapabilityRejected`: `'capability' => 'manage options'`, `'Manage_Options'` and `''` each → `InvalidDeclaration` (an empty string is not "absent" and is never defaulted).
- `testCapabilityKeptVerbatim`: `'capability' => 'manage_woocomerce'` (typo, well-formed) → `capability === 'manage_woocomerce'`.
- `testInvalidMenuRejected`: `'menu' => 'javascript:alert(1)'` → `InvalidDeclaration`; `'edit.php?post_type=product'`, `'proto-blocks'`, `'options-general.php'` accepted (grammar `^[a-z0-9_.?=&-]+$`).
- `testArrayModeRequiresOptionName`: `storage: {mode: 'array'}` without `option` → `InvalidDeclaration`.
- `testProtectedOptionNamesAreRefused`: storage option `default_role`, `users_can_register`, `siteurl`, `wp_user_roles`, `_transient_x`, `_site_transient_x`, `cron`, `auth_salt`, `theme_mods_x` each → `InvalidDeclaration`; derived per-field slug `users` + field `can_register` → refused (derives `users_can_register`); `cadco_display_settings` accepted.
- `testOptionNameLengthCapped`: a derived name longer than 191 characters → `InvalidDeclaration`.
- `testProtoOptionsPageRecordsPhpDeclaration`: `proto_options_page('Theme Settings', ['menu' => 'themes.php'])` records slug `theme-settings` with `title`, `menu` and a `source` ending in `PageDefinitionTest.php:<line>`.

- [ ] **Step 4: Run to verify failure**

Run: `vendor/bin/phpunit tests/php/OptionsPages/PageDefinitionTest.php`
Expected: FAIL, class `ProtoBlocks\OptionsPages\PageDefinition` not found.

- [ ] **Step 5: Implement `PageDefinition`, `InvalidDeclaration`, `ProtectedOptions`, `proto_options_page()`**

`ProtectedOptions::isProtected` returns true for: the union of WordPress's `allowed_options` groups (`general`, `discussion`, `media`, `reading`, `writing`, `options`, `privacy`) hard-coded as a list, plus `siteurl`, `home`, `active_plugins`, `template`, `stylesheet`, `cron`, `rewrite_rules`, `db_version`, `initial_db_version`, `auto_core_update_notified`, `recently_activated`, `uninstall_plugins`, `sidebars_widgets`, `user_count`, `WPLANG`, the salts and keys (`auth_key`, `auth_salt`, `logged_in_key`, `logged_in_salt`, `nonce_key`, `nonce_salt`, `secure_auth_key`, `secure_auth_salt`, `secret_key`), `$wpdb->prefix . 'user_roles'`; any name starting `_transient`, `_site_transient`, `theme_mods_`, `widget_` or `proto_blocks_`; the list is filtered through `proto_blocks_protected_options`. `fromArray` collects every error then throws once; `path` is a JSON Pointer to the offending key where there is one, else `''`. No WordPress call happens at construction except `sanitize_title`, `__()` and the protected-options filter.

- [ ] **Step 6: Run to verify pass**

Run: `vendor/bin/phpunit tests/php/OptionsPages/PageDefinitionTest.php && composer test` → PASS.

- [ ] **Step 7: Commit**

```bash
git add includes/OptionsPages includes/helpers.php tests/php/OptionsPages tests/fixtures/options-pages
git commit -m "feat(options): options page definition, option naming and proto_options_page()"
```

---

### Task 2: OptionsStore (per-field and array storage)

**Parallel group:** A
**Depends on:** Task 1

**Files:**
- Create: `includes/OptionsPages/OptionsStore.php`, `tests/php/OptionsPages/OptionsStoreTest.php`

**Interfaces:**
- Consumes: `PageDefinition`, `ProtectedOptions` (Task 1).
- Produces:
  ```php
  final class OptionsStore {
      public function has(PageDefinition $page, string $name): bool;     // per-field: the option exists; array: the key exists in the array
      public function read(PageDefinition $page, string $name): mixed;   // stored value, null when absent (defaults are the reader's job)
      /** @param array<string, mixed> $values exactly the names to write @param list<string> $booleanNames true_false fields among them */
      public function save(PageDefinition $page, array $values, array $booleanNames = []): void; // throws \DomainException when a target option name is protected; writes nothing then
      public static function coerceLike(mixed $existing, bool $value): mixed; // keep the stored scalar format of a boolean
  }
  ```
  The caller decides which names are declared (Task 5); the store writes exactly what it is given.

- [ ] **Step 1: Write the failing tests**

`OptionsStoreTest` (options in `$GLOBALS['pb_test_options']`, autoload flags in `$GLOBALS['pb_test_autoload']`):
- `testPerFieldReadReturnsNullWhenMissing`: no option stored → `has($themeSettings, 'phone') === false`, `read(...) === null`.
- `testPerFieldSaveWritesOneOptionEach`: `save(themeSettings, ['phone' => '555'])` → `pb_test_options['theme-settings_phone'] === '555'` and autoload recorded `false` (fixture has `autoload: false`).
- `testArrayModeReadsExistingOption`: `cadco_display_settings = ['hide_children' => '1', 'children_placement' => 'section', 'children_heading' => 'More in this range']` → `read(cadco, 'hide_children') === '1'`, `has(cadco, 'legacy_sort') === false` until the key is stored.
- `testArrayModePreservesUndeclaredKeys`: existing array also has `'legacy_sort' => 'menu_order'`; `save(cadco, ['children_placement' => 'related'])` → stored array still has `legacy_sort === 'menu_order'`, `children_placement === 'related'`, and the other two keys unchanged; exactly one `update_option` call.
- `testTrueFalseKeepsExistingFormat`: `coerceLike('1', false) === ''`, `coerceLike('', true) === '1'`, `coerceLike(0, true) === 1`, `coerceLike('yes', false) === 'no'`, `coerceLike(true, false) === false`, `coerceLike(null, true) === true`; `save(cadco, ['hide_children' => false], ['hide_children'])` with stored `'1'` writes `''`.
- `testArrayModeAutoloadDefaultTrue`: `save(cadco, …)` records autoload `true` for `cadco_display_settings`.
- `testSaveRefusesProtectedOptionName`: a filter on `proto_blocks_protected_options` adding `cadco_display_settings` after the page was built → `save(cadco, ['children_placement' => 'related'])` throws `\DomainException` and the stored array is unchanged; a per-field page `users` with `save($users, ['can_register' => '1'])` throws and `users_can_register` is never written.

- [ ] **Step 2: Run to verify failure** — `vendor/bin/phpunit tests/php/OptionsPages/OptionsStoreTest.php` → FAIL, class not found.

- [ ] **Step 3: Implement `OptionsStore`**

`save` checks every target option name with `ProtectedOptions::isProtected` before the first write, then calls `update_option($name, $value, $page->autoload)` per write. For names in `$booleanNames` the value written is `coerceLike($existing, (bool) $value)` where `$existing` is the currently stored scalar; other values are written as given. Array mode reads the current option once, overlays the given keys, writes once. `coerceLike` maps `'1'/''`, `1/0`, `'yes'/'no'`, `'on'/'off'`, `'true'/'false'`, `true/false`; anything else (including `null`) yields a PHP bool.

- [ ] **Step 4: Run to verify pass** — `vendor/bin/phpunit tests/php/OptionsPages && composer test` → PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/OptionsPages/OptionsStore.php tests/php/OptionsPages
git commit -m "feat(options): per-field and array option storage with legacy-format preservation"
```

---

### Task 3: MenuRegistrar

**Parallel group:** A
**Depends on:** Task 1

**Files:**
- Create: `includes/OptionsPages/MenuRegistrar.php`, `tests/php/OptionsPages/MenuRegistrarTest.php`

**Interfaces:**
- Consumes: `PageDefinition`.
- Produces:
  ```php
  final class MenuRegistrar {
      public const GENERIC_ICON = 'dashicons-admin-generic';
      /**
       * @param callable(PageDefinition): array{value: string, warning: ?string} $iconResolver
       * @param callable(PageDefinition): void $render
       * @param callable(string): bool $capabilityKnown  true when some role grants the capability
       */
      public function __construct(callable $iconResolver, callable $render, callable $capabilityKnown);
      /** @param array<string, PageDefinition> $pages */
      public function register(array $pages): void;   // hooked on admin_menu priority 20
      public function hookSuffixes(): array;            // slug => hook suffix, filtered into proto_blocks_admin_app_hooks
      public function warnings(): array;                // list<array{slug: string, message: string}>
  }
  ```

- [ ] **Step 1: Write the failing tests**

`MenuRegistrarTest` (menus recorded by the Task 1 stubs; `$GLOBALS['pb_test_parent_menus']` lists parent slugs that "exist"; `$capabilityKnown` returns true for `manage_options`, `manage_woocommerce`, `edit_theme_options`):
- `testTopLevelPage`: theme-settings → one `add_menu_page` with capability `manage_options` (fixture default), slug `theme-settings`, icon from the resolver (`dashicons-admin-generic`).
- `testSubmenuUnderPostTypeMenu`: CADCO → `add_submenu_page('edit.php?post_type=product', 'Display Settings', 'Display Settings', 'manage_woocommerce', 'cadco-display-settings', …)`.
- `testChildrenRegisteredAfterParentUnderParentSlug`: theme-settings child → `add_submenu_page('theme-settings', 'Footer', …, 'theme-settings-footer')` recorded after the parent's call, plus WordPress's first-submenu duplicate (`add_submenu_page('theme-settings', 'Theme Settings', 'Theme Settings', cap, 'theme-settings')`) so the parent keeps its own label.
- `testPagesWithMenuPointingAtAnotherDeclaredPageRegisterAfterIt`: page B with `menu: 'page-a'` given before A in the input → A's `add_menu_page` precedes B's `add_submenu_page`.
- `testMissingParentRecordsWarning`: `menu: 'edit.php?post_type=nope'` not in `pb_test_parent_menus` → still registered (WordPress makes it a hidden page) and `warnings()` contains `['slug' => 'x', 'message' => 'Parent menu "edit.php?post_type=nope" does not exist; the page is reachable only by URL.']`.
- `testIconWarningIsRecorded`: resolver returning `['value' => 'dashicons-admin-generic', 'warning' => 'Icon not found: icons/x.svg']` → warning recorded for the slug.
- `testUnknownCapabilityKeptAndWarned`: capability `manage_woocomerce` → the recorded call carries exactly `manage_woocomerce` (not `manage_options`, not `read`) and `warnings()` contains `Capability "manage_woocomerce" is not granted to any role; only super admins can open this page.`
- `testHookSuffixesCollected`: `hookSuffixes()['cadco-display-settings'] === 'hook_cadco-display-settings'`.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement `MenuRegistrar`**

Parent existence check reads `$GLOBALS['menu']` and `$GLOBALS['submenu']` keys plus `$GLOBALS['admin_page_hooks']`. Order: topological over `menu` pointing at declared slugs; a cycle is a warning and every page in it registers top-level. The icon is resolved only for `menu === 'top'`.

- [ ] **Step 4: Run to verify pass** → `vendor/bin/phpunit tests/php/OptionsPages && composer test` PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/OptionsPages/MenuRegistrar.php tests/php/OptionsPages
git commit -m "feat(options): register options pages and child pages in the admin menu"
```

---

### Task 4: The `options` declaration kind in Phase 1, registry and page template

**Parallel group:** B
**Depends on:** Task 1; Phase 1 merged (content-model classes, `SchemaFilesRoutes`, client libraries); Phase 2 Task 8/15 merged for the `proto_blocks_declaration_errors` filter (if absent, this task adds the filter call to `SchemaFileWriter::write()` and `ContentModelCommands::validationRows()` itself)

**Files:**
- Create: `includes/OptionsPages/OptionsPagesRegistry.php`, `includes/OptionsPages/PageTemplate.php`, `tests/php/OptionsPages/{OptionsKindTest,OptionsPagesRegistryTest,PageTemplateTest}.php`, `tests/fixtures/options-pages/invalid/{bad-jsonc,unknown-mode,missing-title}.jsonc`, `src/admin/__tests__/options-schema.test.ts`
- Modify (Phase 1): `includes/ContentModel/FileLoader.php` (`FOLDERS['options'] = 'proto-options'`, `BASENAMES['options'] = 'page'`), `includes/ContentModel/Slug.php` (kind `options`), `includes/ContentModel/PhpDeclarations.php` (slug max by kind: `options` → `Slug::OPTIONS_MAX`), `includes/ContentModel/Registry.php` (`all(string $kind)` if absent), `includes/ContentModel/schemas/content-model.schema.json` (`$defs.options`), `includes/ContentModel/DeclarationTemplate.php` (`options` → `PageTemplate::render($name, ['slug' => $slug])`), `includes/ContentModel/SchemaFileWriter.php` and `includes/CLI/ContentModelCommands.php` (pass `$slug` as the fourth `proto_blocks_declaration_errors` argument), `includes/API/Admin/SchemaFilesRoutes.php` (kind alternation `type|taxonomy|options` plus any Phase 2 kinds, slug `[a-z][a-z0-9_-]{0,63}`), `tests/php/ContentModel/stubs/hooks.php` (`update_option` also records `$GLOBALS['pb_test_autoload'][$name]`), `src/admin/content-model/types.ts` (`Kind` += `'options'`), `src/admin/content-model/schema.ts` (one `export const optionsSchema`)
- Modify: `includes/OptionsPages/functions.php` (route `proto_options_page()` to `PhpDeclarations::add('options', $title, $args, $source)`), `tests/php/OptionsPages/PageDefinitionTest.php` (`testProtoOptionsPageRecordsPhpDeclaration` asserts `PhpDeclarations::all()['options']['theme-settings']` instead of the global), `tests/php/OptionsPages/wp-stubs.php` (`require_once` the Phase 1 stubs first when present)

**Interfaces:**
- Consumes: Phase 1 rows of the contracts table; `PageDefinition::fromArray`, `ProtectedOptions`.
- Produces:
  ```php
  // Slug (Phase 1 file)
  public const OPTIONS_MAX = 64;
  public const OPTIONS_RESERVED = ['proto-blocks', 'index', 'general', 'writing', 'reading', 'discussion', 'media', 'permalink', 'privacy', 'options', 'options-general', 'themes', 'plugins', 'users', 'tools', 'profile', 'upload', 'menus', 'widgets', 'customize', 'woocommerce'];
  // Slug::error($slug, 'options'): 'pattern' unless ^[a-z][a-z0-9-]*$, 'length' over 64, 'reserved' for OPTIONS_RESERVED or any slug starting 'proto-blocks-'
  final class OptionsPagesRegistry {
      public const KIND = 'options';
      public function __construct(\ProtoBlocks\ContentModel\Registry $declarations);
      public function all(): array;                  // slug => PageDefinition, children included under their compound slug
      public function get(string $slug): ?PageDefinition;
      public function warnings(): array;             // list<array{slug: string, source: ?string, message: string}>
      public function issues(): array;               // the same as Phase 1 Issue objects (kind 'options'), for validate
      public function refresh(): void;               // Registry::invalidate() + clear the memo
      /** proto_blocks_declaration_errors callback (4 args); for kind 'options' appends PageDefinition::fromArray errors */
      public static function declarationErrors(array $errors, string $kind, array $data, string $slug = ''): array;
  }
  final class PageTemplate {
      public static function render(string $title, array $opts = []): string;   // commented JSONC; $opts keys slug, menu, capability, storage-option
  }
  ```
  `$defs.options` in `content-model.schema.json` (Phase 1's keyword subset only, local `$ref`s): required `title` (string 1–200); `menu` string pattern `^[a-z0-9_.?=&-]+$`; `capability` pattern `^[a-z0-9_]+$`; `icon` string ≤ 2048; `position` integer; `menu_title` string; `autoload` boolean; `storage` `anyOf` [`{mode: enum ["fields"]}`, `{mode: enum ["array"], option: string pattern ^[a-z0-9_]{1,191}$}` with `required` both]; `fields` array of objects; `tabs` array of `{title (required), fields}`; `children` array of `{slug (required, ^[a-z][a-z0-9-]*$), title (required), capability, fields, tabs, storage, autoload}`; `additionalProperties: true` (unknown keys tolerated, like ACF keys). Field and group contents are checked by `PageDefinition` and Phase 2's `GroupValidator` through `proto_blocks_declaration_errors`, not by `SchemaCheck`.

- [ ] **Step 1: Write the failing PHPUnit tests**

`OptionsKindTest`:
- `testFileLoaderKnowsOptionsKind`: `FileLoader::FOLDERS['options'] === 'proto-options'`, `FileLoader::BASENAMES['options'] === 'page'`.
- `testSlugRulesForOptions`: `Slug::error('theme-settings', 'options') === null`; `'theme_settings'` → `'pattern'`; `'../x'` → `'pattern'`; 65 chars → `'length'`; `'proto-blocks'` and `'proto-blocks-fields'` → `'reserved'`.
- `testSchemaAcceptsFixturesAndRejectsBadStorage`: both fixtures validate against `#/$defs/options` with `[]`; `invalid/unknown-mode.jsonc` (`storage.mode: "table"`) → an error whose `path` starts with `/storage`; `invalid/missing-title.jsonc` → error at `/title`.
- `testSchemaFileRoutePatternRejectsTraversal`: after Phase 1's `SchemaFilesRoutes::registerRoutes('proto-blocks/v1', …)`, the recorded route pattern matches `options/theme-settings` and fails `options/../x`, `options/a/b`, `options/A`.
- `testSchemaFileGetServesOptionsFileWithComments`: Phase 1's GET handler for `options/cadco-display-settings` over a temp theme holding the fixture → `text` equals the fixture byte for byte; for a missing slug, `text` is `PageTemplate::render(...)` and `hash` null.
- `testDeclarationErrorsFilterRefusesProtectedOption`: `OptionsPagesRegistry::declarationErrors([], 'options', ['title' => 'X', 'storage' => ['mode' => 'array', 'option' => 'siteurl']], 'x')` → non-empty; for kind `type` → returns `[]` unchanged; through Phase 1's writer, a PUT of that text answers 400 and writes nothing.

`OptionsPagesRegistryTest` (Phase 1 theme stubs; `$GLOBALS['pb_test_theme']` points the stylesheet dir at a temp copy of `tests/fixtures/options-pages/..` arranged as `proto-options/<slug>/page.jsonc`):
- `testDiscoversFixtureFiles`: `all()` has `cadco-display-settings`, `theme-settings`, `theme-settings-footer`; each `origin === 'file'` and `source` ends with `page.jsonc`.
- `testPhpDeclarationRegistered`: `proto_options_page('Shop Notices', ['menu' => 'woocommerce'])` → `get('shop-notices')->origin === 'php'`.
- `testFileWinsOverPhpAndRecordsDoubleDeclaration`: `proto_options_page('Display Settings', ['slug' => 'cadco-display-settings', 'capability' => 'manage_options'])` → `get(...)->capability === 'manage_woocommerce'` and Phase 1 `Registry::doubles()` has one entry with kind `options`, the file path and the PHP `file:line`.
- `testInvalidFileIsSkippedWithWarning`: `invalid/bad-jsonc.jsonc` placed as `proto-options/broken/page.jsonc` → `get('broken') === null`, `warnings()` has one entry with `slug: 'broken'` whose message contains `line`; no exception escapes; other pages still load.
- `testDefinitionErrorsBecomeWarnings`: a file with duplicate field names across tabs → page skipped, warning carries `is used twice`.
- `testBrokenFileDoesNotFallBackToPhp`: PHP `proto_options_page('Broken', ['capability' => 'read'])` plus an invalid `proto-options/broken/page.jsonc` → `get('broken') === null` and a warning `The file <path> is invalid; the PHP declaration at <file:line> is not used either.`
- `testIssuesForValidate`: `issues()` returns Phase 1 `Issue` objects with `kind === 'options'` for each warning.

`PageTemplateTest`: `PageTemplate::render('Display Settings', ['menu' => 'edit.php?post_type=product', 'capability' => 'manage_woocommerce', 'storage-option' => 'cadco_display_settings'])` decodes with `Jsonc::decode` to `title`, `menu`, `capability`, `storage.mode === 'array'`, `fields === []`, validates clean against `#/$defs/options`, and contains the comment `// Where the page appears`; `DeclarationTemplate::render('options', 'Shop Notices')` returns the same text as `PageTemplate::render('Shop Notices')`.

- [ ] **Step 2: Write the failing Jest parity test**

`src/admin/__tests__/options-schema.test.ts`: `optionsSchema` (built by Phase 1's `toZod` from `$defs.options`) accepts every `tests/fixtures/options-pages/*/page.jsonc` (parsed with `parseJsonc`) and rejects every `invalid/*.jsonc` except `bad-jsonc`, matching the PHP verdicts above.

- [ ] **Step 3: Run to verify failure** — `vendor/bin/phpunit tests/php/OptionsPages/OptionsKindTest.php tests/php/OptionsPages/OptionsPagesRegistryTest.php tests/php/OptionsPages/PageTemplateTest.php` and `npm run test:js -- options-schema` → FAIL.

- [ ] **Step 4: Implement the Phase 1 extensions, the registry and the template**

Registry build: `Registry::all('options')` → for each `Declaration` `PageDefinition::fromArray($d->slug, $d->data, $d->origin, $d->source, $d->root)`; `InvalidDeclaration` becomes one warning per error and the slug is skipped. Phase 1 `Issue`s with `kind === 'options'` (invalid JSONC, schema errors, oversize, slug errors, double declarations) are copied into `warnings()`. An `error`-level issue whose source is a file suppresses a PHP declaration of the same slug, so a broken file never silently changes the capability. Children are flattened into `all()`. Lazy: built on first `all()`. Phase 1's writer and `validate` already cover the new kind once `FOLDERS`, `BASENAMES`, `Slug` and `$defs.options` know it; if either picks the schema `$def` from an explicit kind map rather than `'#/$defs/' . $kind`, add `options` to that map.

- [ ] **Step 5: Run to verify pass** — `composer test && npm run test:js` → PASS (Phase 1's own suites included).

- [ ] **Step 6: Commit**

```bash
git add includes/OptionsPages includes/ContentModel includes/CLI/ContentModelCommands.php includes/API/Admin/SchemaFilesRoutes.php src/admin/content-model tests/php tests/fixtures/options-pages src/admin/__tests__/options-schema.test.ts
git commit -m "feat(options): proto-options declaration kind, discovery and page template"
```

---

### Task 5: Values service and `options/<slug>` REST routes

**Parallel group:** C
**Depends on:** Tasks 1, 2, 4; Phase 2 PHP merged

**Files:**
- Create: `includes/OptionsPages/OptionsValues.php`, `includes/API/OptionsValuesRoutes.php`, `tests/php/OptionsPages/OptionsValuesTest.php`
- Modify: `tests/php/Modules/Fields/wp-stubs.php` (Phase 2; `update_option` also records `$GLOBALS['pb_test_autoload'][$name]`), `tests/php/OptionsPages/wp-stubs.php` (`require_once` the Phase 2 stubs first when present)

**Interfaces:**
- Consumes: Phase 2 rows of the contracts table; `OptionsPagesRegistry`, `OptionsStore`, `ProtectedOptions`.
- Produces:
  ```php
  final class OptionsValues {
      /** @param \Closure(string): list<FieldGroup> $extraGroups standalone groups with an options_page rule for the slug ([] when the Fields module is off) */
      public function __construct(OptionsPagesRegistry $registry, OptionsStore $store, TypeRegistry $types, \Closure $extraGroups);
      public function pageGroups(PageDefinition $page): array;  // list<FieldGroup>: the page's own groups, GroupNormalizer::normalize($raw, 'options:' . $slug, $page->source, ['options_page' => $slug])
      public function groupsFor(PageDefinition $page): array;   // pageGroups + $extraGroups($slug) not already present by key; a standalone field whose name is already used on the page, or whose option name is protected, is dropped with a warning
      public function fields(PageDefinition $page): array;      // name => Field, top-level stored fields of groupsFor
      public function warnings(PageDefinition $page): array;    // list<string> from normalisation and dropping
      public function read(PageDefinition $page): array;        // name => stored value, or the field's normalised default_value when absent
      /** @param array<string, array<string, mixed>> $input groupKey => name => submitted @param array<string, list<string>> $present groupKey => rendered editable names */
      public function save(PageDefinition $page, array $input, array $present, int $userId): array|\WP_Error; // read() after a successful save
  }
  final class OptionsValuesRoutes {
      public function __construct(OptionsPagesRegistry $registry, OptionsValues $values);
      public function registerRoutes(): void;   // GET|POST proto-blocks/v1/options/(?P<slug>[a-z][a-z0-9-]{0,63})
      public function permission(\WP_REST_Request $r): bool|\WP_Error;
  }
  ```
  `GET` → `{ values: read(), trusted: !AttributeSanitizer::shouldSanitizeForUser(uid) }`. `POST` body `{ fields: {groupKey: {name: value}}, present: {groupKey: [name]} }` (the shape of the post form's `proto_fields` / `proto_fields_present`, so client and server reuse Phase 2's names) → `{ values }`, or 422 `proto_blocks_invalid_values` with `data: { status: 422, errors: {name: message} }`, or 403 `proto_blocks_protected_option`. Unknown slug → 404 `proto_blocks_options_page_not_found`.

- [ ] **Step 1: Write the failing tests**

`OptionsValuesTest` (Phase 2 classes real; `$GLOBALS['pb_test_caps'] = [1 => ['manage_woocommerce'], 2 => ['manage_woocommerce', 'unfiltered_html'], 3 => ['manage_options']]`; CADCO group key `group_cadco_display_settings_1`, written `G` below):
- `testPermissionUsesPageCapability`: user 1 → `permission()` true for `cadco-display-settings`, false for `theme-settings` (manage_options); unknown slug → `WP_Error` code `proto_blocks_options_page_not_found`, status 404.
- `testPermissionDeniedForLoggedOut`: user 0 (what a cookie request without a nonce becomes) → false for every page.
- `testUnknownCapabilityFailsClosed`: a page with capability `manage_woocomerce` → false for users 1 and 3 (no fallback to `manage_options`).
- `testSaveValidValues`: user 1 posts `fields[G] = {hide_children: '1', children_placement: 'section', children_heading: 'More'}`, `present[G]` = the three names → stored array has `hide_children` in the stored format, `children_placement === 'section'`, `children_heading === 'More'`; response `values.children_heading === 'More'`.
- `testInvalidChoiceRejectedAndNothingSaved`: `children_placement: 'sidebar'` → `WP_Error` 422 with `errors.children_placement === 'Choose one of the listed options.'`; the stored option is unchanged.
- `testHiddenFieldNeitherValidatedNorWritten`: `children_heading` declared `required: true`, stored `'Old'`; post `children_placement: 'related'`, `children_heading: ''` → no error, stored `children_heading` stays `'Old'`.
- `testFieldsNotPresentUntouched`: a field omitted from `present[G]` keeps its stored value even when `fields[G]` carries a value for it.
- `testUntrustedValuesAreSanitised`: user 1 posts `children_heading: '<script>x</script>Hi'` (placement `section`) → stored value has no `<script`.
- `testTrustedValuesStoredAsGiven`: user 2 same input → stored as given.
- `testUntrustedCodeFieldUntouched`: theme-settings with stored `theme-settings_head_scripts = '<script>ok()</script>'`; user 3 (`manage_options`, no `unfiltered_html`) posts `head_scripts: '<img onerror=x>'` with `head_scripts` in `present` → stored value unchanged; response `values.head_scripts` is the stored value.
- `testUndeclaredKeysDropped`: posting `{admin_email: 'a@b.c'}` under `G`, and anything under an unknown group key, writes nothing named `admin_email` (per-field page: no `theme-settings_admin_email` either).
- `testStandaloneGroupAttachesByLocation`: `$extraGroups` returning a normalised group with location `[[{param: 'options_page', operator: '==', value: 'theme-settings'}]]` adds its field to `fields(theme-settings)`; saving it writes `theme-settings_<name>`.
- `testStandaloneFieldDerivingProtectedNameDropped`: a page `users` and a standalone group field `can_register` → not in `fields()`, `warnings()` names `users_can_register`.
- `testProtectedOptionRefusedAtSave`: `proto_blocks_protected_options` filter adds `cadco_display_settings` after load → `save()` returns 403 `proto_blocks_protected_option`; the option is unchanged.
- `testRoutesRegistered`: `registerRoutes()` records `options/(?P<slug>[a-z][a-z0-9-]{0,63})` with `GET` and `POST` and `permission_callback` `[$routes, 'permission']`; the pattern fails `../x`, `a/b`, `A`.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement**

Pipeline in `save`, per group of `groupsFor` (spec section 5 "Trust for field values", mirroring Phase 2's `TrustCallbacks::sanitizeCallback` and `SaveHandler::save`):
1. Candidate names: `present[group key]` ∩ the group's top-level stored fields.
2. For each: `$v = $types->for($f)->normalize($input[$key][$name] ?? null, $f)`; a `code` field when `!user_can($userId, 'unfiltered_html')` is dropped (stored value kept); when `AttributeSanitizer::shouldSanitizeForUser($userId)`, `$v = sanitize($v)` and a `null` result drops the field.
3. `ValueValidator::validate($group->fields, <stored values merged with the remaining submitted values>, $choiceResolver, $maxRows)` with the same resolver and cap callables Phase 2's `SaveHandler` passes (reuse its factory; if it is private, build them the same way: provider values for `optionsSource`, `apply_filters('proto_blocks_repeater_max_items', …)`). Errors on candidate names are collected; names in `hidden` are not written.
4. Any error anywhere → 422 and no write. Otherwise one `OptionsStore::save($page, $values, $booleanNames)`; a `\DomainException` becomes 403 `proto_blocks_protected_option`.
The route's `permission` resolves the page from the `slug` param and returns `current_user_can($page->capability)` and nothing else; REST cookie auth enforces the `wp_rest` nonce.

- [ ] **Step 4: Run to verify pass** → `composer test` PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/OptionsPages/OptionsValues.php includes/API/OptionsValuesRoutes.php tests/php
git commit -m "feat(options): REST save for options pages behind the page capability"
```

---

### Task 6: Getter context `option:<page>` through Phase 2's reader

**Parallel group:** C
**Depends on:** Tasks 2, 4, 5; Phase 2 PHP merged

**Files:**
- Create: `includes/OptionsPages/OptionsFieldStore.php`, `includes/OptionsPages/FieldsBridge.php`, `tests/php/OptionsPages/OptionContextTest.php`
- Modify: `includes/Modules/Fields/FieldsModule.php` (Phase 2; two lines: `$groups = apply_filters('proto_blocks_field_groups', $groups);` after normalisation and the ACF filter, before `new GroupIndex`; `$stores = apply_filters('proto_blocks_field_stores', $stores);` where the kind => `Store` map is built, before `FieldReader`, `SaveHandler` and `BoxPayload` receive it)

**Interfaces:**
- Produces:
  ```php
  final class OptionsFieldStore implements \ProtoBlocks\Modules\Fields\Storage\Store {
      public function __construct(OptionsPagesRegistry $registry, OptionsStore $store);
      public function get(ObjectRef $ref, Field $f): mixed;   // page declared here → OptionsStore::read; otherwise get_option($ref->page . '_' . $f->name), as Phase 2's OptionStore
      public function has(ObjectRef $ref, Field $f): bool;
      public function set(ObjectRef $ref, Field $f, mixed $value): void;  // OptionsStore::save($page, [$f->name => $value], true_false ? [$f->name] : [])
  }
  final class FieldsBridge {
      public function __construct(OptionsPagesRegistry $registry, OptionsValues $values, OptionsFieldStore $store);
      public function filterGroups(array $groups): array;  // appends OptionsValues::pageGroups() of every page whose group key is not already present
      public function filterStores(array $stores): array;  // ['option' => $this->store] + $stores
  }
  ```

- [ ] **Step 1: Write the failing tests**

`OptionContextTest` (reader built the way `FieldsModule` builds it: `$reader = new FieldReader(new GroupIndex($bridge->filterGroups([])), TypeRegistry::withCoreTypes(), $bridge->filterStores(['option' => new OptionStore()]))`):
- `testContextResolves`: `ContextResolver::resolve('option:cadco-display-settings')` is an `ObjectRef` with `kind === 'option'`, `page === 'cadco-display-settings'`.
- `testReadsArrayOption`: stored `cadco_display_settings = ['children_heading' => 'More & more', 'hide_children' => '1']` → `$reader->get('children_heading', 'option:cadco-display-settings') === 'More &amp; more'`; `$reader->get('hide_children', 'option:cadco-display-settings') === true`.
- `testOptionContextEscapesByDefault`: stored heading `'<b>x</b>'` → `'&lt;b&gt;x&lt;/b&gt;'`; with `$raw = true` → `'<b>x</b>'`.
- `testDefaultWhenUnset`: no option → `get('children_placement', 'option:cadco-display-settings') === 'related'`.
- `testPerFieldPageAndChildContext`: `theme-settings-footer_copyright = '© ACME'` → `get('copyright', 'option:theme-settings-footer') === '© ACME'`.
- `testAllReturnsDeclaredFields`: `$reader->all('option:cadco-display-settings')` has exactly the three declared keys.
- `testUnknownPageReturnsNull`: `get('x', 'option:nope') === null`, no exception.
- `testUndeclaredFieldReturnsNull`: `get('legacy_sort', 'option:cadco-display-settings') === null` even though the array holds it.
- `testFieldsModuleAppliesBridgeFilters`: with `FieldsBridge` callbacks registered on the two filters, `FieldsModule::instance()->index()->fieldFor(ObjectRef::option('cadco-display-settings'), 'children_heading')` is not null.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement** `OptionsFieldStore`, `FieldsBridge` and the two `apply_filters` lines (skip the lines and use Phase 2's equivalent if it already exposes one; record its name in the contracts table). The module (Task 11) hooks the bridge only when the Fields module is enabled; the test hooks it directly.

- [ ] **Step 4: Run to verify pass** → `composer test` PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/OptionsPages/OptionsFieldStore.php includes/OptionsPages/FieldsBridge.php includes/Modules/Fields/FieldsModule.php tests/php/OptionsPages/OptionContextTest.php
git commit -m "feat(options): proto_field() option:<page> context with array-mode storage"
```

---

### Task 7: Rendered options page (PageRenderer + OptionsPageScreen)

**Parallel group:** D
**Depends on:** Tasks 3, 5, 6; Phase 0 shell (`renderScreen`, `api.ts`, `SCREENS`); Phase 2 React (`FieldsForm`, `BoxPayload`)

**Files:**
- Create: `includes/OptionsPages/PageRenderer.php`, `tests/php/OptionsPages/PageRendererTest.php`, `src/admin/screens/OptionsPageScreen.tsx`, `src/admin/options-pages/types.ts`, `src/admin/options-pages/formData.ts`, `src/admin/__tests__/options-page.test.tsx`, `src/admin/__tests__/options-form-data.test.ts`
- Modify: `includes/Admin/AdminApp.php` (`SCREENS` += `'options-page'`), `src/admin/bootstrap.ts` (`Screen` union += `'options-page'`), `src/admin/screens/index.ts` (one line), `includes/Admin/Assets.php` (`enqueueAdminAssets` honours `proto_blocks_admin_app_hooks`), `src/admin/api.ts` (`ApiError.data` if absent; one line)

**Interfaces:**
- Produces (PHP):
  ```php
  final class PageRenderer {
      public function __construct(OptionsValues $values, OptionsFieldStore $store, TypeRegistry $types, \ProtoBlocks\Admin\AdminApp $app);
      public function pageData(PageDefinition $page, int $userId): array;  // { slug, title, groups: BoxPayload[], tabs: [{title, groups: BoxPayload[]}], trusted, editUrl: ?string }
      public function render(PageDefinition $page): void;  // wp_die(..., 403) unless current_user_can($page->capability); enqueue; inline script window.protoBlocksOptionsPage = pageData; $app->renderScreen('options-page')
  }
  ```
  Payloads: `new BoxPayload($types, new FieldReader(new GroupIndex($values->groupsFor($page)), $types, ['option' => $store]), new ErrorStore())->build($group, ObjectRef::option($page->slug), $userId)` per group; untabbed page groups first, then standalone groups, then tabs. `editUrl` is `admin_url('admin.php?page=proto-blocks-options-pages&slug=<slug>')` only for `manage_options`, else null. The inline JSON uses `wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)`. `render` also enqueues Phase 2's `fields-box` stylesheet, `wp_enqueue_media()` when any image/file/gallery/video field is present, and `wp_enqueue_editor()` when a wysiwyg is.
- Produces (TS):
  ```ts
  // types.ts
  export interface OptionsPageData { slug: string; title: string; groups: BoxPayload[]; tabs: { title: string; groups: BoxPayload[] }[]; trusted: boolean; editUrl: string | null }
  // formData.ts — parses Phase 2's real input names
  export function submissionFromForm(form: HTMLFormElement): { fields: Record<string, Record<string, unknown>>; present: Record<string, string[]> };
  // OptionsPageScreen.tsx
  export default function OptionsPageScreen(): JSX.Element;   // reads window.protoBlocksOptionsPage
  ```
  `submissionFromForm` reads `new FormData(form)`: `proto_fields[<group>][a][0][b]` becomes nested objects/arrays; a repeated scalar name keeps the last value (so a checked `true_false` wins over its hidden `0`); `proto_fields_present[<group>][]` collects names.

- [ ] **Step 1: Write the failing PHPUnit test**

`PageRendererTest`: `pageData(cadco, 1)` for a `manage_woocommerce` user has `editUrl === null`, `trusted === false`, keys `slug, title, groups, tabs, trusted, editUrl`, and `groups[0]['groupKey'] === 'group_cadco_display_settings_1'` with `object === 'option:cadco-display-settings'`; for a `manage_options` user `editUrl` ends with `page=proto-blocks-options-pages&slug=cadco-display-settings`. `render()` for a user without the capability calls the `wp_die` stub with status 403 and echoes nothing.

- [ ] **Step 2: Write the failing Jest tests**

`options-form-data.test.ts`: a form holding `proto_fields[g][agenda][0][title]=A`, `proto_fields[g][agenda][1][title]=B`, `proto_fields[g][on]=0`, `proto_fields[g][on]=1`, `proto_fields_present[g][]=agenda`, `proto_fields_present[g][]=on` → `{ fields: { g: { agenda: [{ title: 'A' }, { title: 'B' }], on: '1' } }, present: { g: ['agenda', 'on'] } }`.

`options-page.test.tsx` (`mockBootstrap({ screen: 'options-page' })`, `window.protoBlocksOptionsPage` = CADCO data with `children_placement: 'related'`, real `FieldsForm`):
- renders heading `Display Settings`, the checkbox `Hide child products`, the radio group `Child product placement`, and **no** visible `Section heading` input.
- choosing `Own section` reveals `Section heading`; choosing `Related products` hides it again.
- with `Own section` chosen and `More` typed, `Save settings` calls `adminPost('options/cadco-display-settings', …)` whose body has `fields.group_cadco_display_settings_1` equal to `{ hide_children: '0', children_placement: 'section', children_heading: 'More' }` and `present.group_cadco_display_settings_1` containing exactly those three names, then toasts `Settings saved.`
- a 422 `ApiError` with `data.errors.children_heading = 'This field is required.'` shows that text under the field and does not toast success.
- theme-settings data with tabs: two tab triggers `General`, `Tracking`; a 422 error on `head_scripts` (Tracking) switches to the Tracking tab and shows a `1` error count on its trigger.
- `trusted: false` (payload `canUnfiltered: false`) shows the stored `head_scripts` text and renders no element whose `name` contains `head_scripts`.
- `editUrl` null → no `Edit declaration` link; non-null → link with that `href`.

- [ ] **Step 3: Run to verify failure** — `vendor/bin/phpunit tests/php/OptionsPages/PageRendererTest.php` and `npm run test:js -- options-page options-form-data` → FAIL.

- [ ] **Step 4: Implement**

One `<form>` for the whole page; untabbed groups above shadcn `Tabs`; every `TabsContent` uses `forceMount` (hidden when inactive) so all inputs stay in the form. Each group renders `<div className="proto-blocks-fields"><FieldsForm key={…} payload={…} /></div>`. Submit: `preventDefault`, `adminPost('options/<slug>', submissionFromForm(form))`. On success, re-mount each `FieldsForm` (key bump) with `values` from the response and toast `Settings saved.`; on 422, re-mount with `errors` from `error.data.errors` and the submitted values, then activate the first tab holding an error and show per-tab error counts. Validation on blur comes from `FieldsForm` itself (Phase 2's zod mirror); the server is the authority. `Assets::enqueueAdminAssets($hook)` also enqueues when `in_array($hook, apply_filters('proto_blocks_admin_app_hooks', []), true)`.

- [ ] **Step 5: Run to verify pass** — `composer test && npm run test:js && npm run build` → PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/OptionsPages/PageRenderer.php includes/Admin/AdminApp.php includes/Admin/Assets.php src/admin tests/php/OptionsPages
git commit -m "feat(options): render options pages with the shared field form"
```

---

### Task 8: Admin routes for the Options Pages screen

**Parallel group:** B
**Depends on:** Task 4; Phase 0 route groups; Phase 1 (`Registry`, `SchemaFileWriter::target`, `OriginTracker`)

**Files:**
- Create: `includes/API/Admin/OptionsPagesRoutes.php`, `includes/OptionsPages/ExternalMenus.php`, `tests/php/API/OptionsPagesRoutesTest.php`
- Modify: `includes/API/AdminRestController.php` (`ROUTE_GROUPS` += one line)

**Interfaces:**
- Produces:
  ```php
  final class OptionsPagesRoutes {
      // dependencies default to Plugin::getInstance()->getModules()->optionsPages() / getContentModel() when null (Task 11 provides them)
      public function __construct(?OptionsPagesRegistry $registry = null, ?\ProtoBlocks\ContentModel\Registry $declarations = null, ?\ProtoBlocks\ContentModel\SchemaFileWriter $writer = null, ?ExternalMenus $external = null);
      public function registerRoutes(string $namespace, callable $permission, callable $writePermission): void;
      // GET admin/options-pages                              → { moduleEnabled, createTarget: {path, writable, reason}, pages: OptionsPageRow[], external: ExternalRow[], warnings }
      // GET admin/options-pages/(?P<slug>[a-z][a-z0-9-]{0,63}) → { page: OptionsPageRow, declaration: object, parents: ParentChoice[], capabilities: string[] }
      public static function row(PageDefinition $p, ?array $double, array $target): array;   // $target from SchemaFileWriter::target('options', slug, decl)
      public static function menuLabel(string $menu, array $parentTitles): string;
  }
  final class ExternalMenus {
      public const TRANSIENT = 'proto_blocks_external_menus';
      public function capture(): void;   // admin_menu PHP_INT_MAX, only when $_GET['page'] === 'proto-blocks-options-pages'; stores {pages: ExternalRow[], parents: ParentChoice[]} for 1 hour
      public function get(): array;
  }
  ```
  `OptionsPageRow = { slug, title, menu, menuLabel, capability, storage: 'Per field' | 'Array: <option>', origin: 'file'|'php', originLabel: 'Proto-Blocks file'|'Proto-Blocks PHP', double: {file, php} | null, source (relative to ABSPATH), editable: bool, readOnlyReason: 'file_mods'|'not_writable'|'outside_root'|null, children: string[], warnings: string[], url }` — the Phase 1 `ListItem` field names, so Phase 1's `OriginBadge` takes it as is. `ExternalRow = { slug, title, parent, origin: 'core'|'plugin'|'theme', originLabel }`, origin from `OriginTracker::classify()` of the menu callback's file (reflection), built-in `options-*.php` pages `core`. `ParentChoice = { slug, title }` from top-level `$menu`. `capabilities` = union of all role capabilities from `wp_roles()`. `declaration` = the winning `Declaration::data` (lets the editor offer "Create file" for a PHP-only page).

- [ ] **Step 1: Write the failing tests**

`OptionsPagesRoutesTest`:
- `testRoutesUseManageOptions`: both routes' `permission_callback` is exactly the passed `$permission` (Phase 0's `manage_options` check), so a `manage_woocommerce`-only user gets 403.
- `testDetailRoutePatternRejectsTraversal`: the detail pattern fails `../x`, `a/b`, `A`.
- `testRowShape`: `row(cadco, null, ['path' => …, 'writable' => true, 'reason' => null])` → `storage === 'Array: cadco_display_settings'`, `capability === 'manage_woocommerce'`, `origin === 'file'`, `originLabel === 'Proto-Blocks file'`, `editable === true`.
- `testDoubleDeclaredRow`: a slug present in `Registry::doubles()` → `double === ['file' => <rel path>, 'php' => 'functions.php:40']`.
- `testMenuLabel`: `menuLabel('top', [])` → `Top level`; `menuLabel('edit.php?post_type=product', ['edit.php?post_type=product' => 'Products'])` → `Under Products`; unknown parent → `Under edit.php?post_type=nope (missing)`.
- `testUnknownSlug404`: → `proto_blocks_options_page_not_found`, 404.
- `testExternalMenusOrigin`: callback defined in a temp `WP_PLUGIN_DIR/acme/acme.php` → `origin 'plugin'` and `originLabel` starting `Plugin: `; `options-general.php` children → `origin 'core'`, `originLabel 'Core'`.

- [ ] **Step 2: Run to verify failure** → FAIL.
- [ ] **Step 3: Implement** and add the one line to `ROUTE_GROUPS`.
- [ ] **Step 4: Run to verify pass** → `composer test` PASS.
- [ ] **Step 5: Commit**

```bash
git add includes/API/Admin/OptionsPagesRoutes.php includes/OptionsPages/ExternalMenus.php includes/API/AdminRestController.php tests/php/API
git commit -m "feat(options): admin REST for listing options page declarations"
```

---

### Task 9: Options Pages admin screen (list + edit)

**Parallel group:** D
**Depends on:** Tasks 4, 8; Phase 0 shell; Phase 1 TS (`useDeclarationFile`, `parseJsonc`, `optionsSchema`, `OriginBadge`, `IssueList`, `DeclarationShell`, `ConflictDialog`, `MenuIconField`); Phase 2 React builder pieces

**Files:**
- Create: `src/admin/screens/OptionsPagesScreen.tsx`, `src/admin/options-pages/{OptionsPageList,OptionsPageEditor,TabsEditor,ChildPagesEditor,MenuPlacementField,CapabilityField,StorageField,PageFieldsBuilder}.tsx`, `src/admin/options-pages/declaration.ts`, `src/admin/__tests__/options-pages.test.tsx`, `src/admin/__tests__/options-declaration.test.ts`
- Modify: `includes/Admin/AdminApp.php` (`SCREENS` += `'options-pages'`), `includes/Admin/AdminPage.php` (one `add_submenu_page('proto-blocks', __('Options Pages', 'proto-blocks'), __('Options Pages', 'proto-blocks'), 'manage_options', 'proto-blocks-options-pages', fn() => Plugin::getInstance()->getAdminApp()->renderScreen('options-pages'))`), `src/admin/bootstrap.ts` (`Screen` += `'options-pages'`), `src/admin/screens/index.ts` (one line)

**Interfaces:**
- Produces (TS):
  ```ts
  // declaration.ts
  export interface OptionsPageForm { title: string; menu: string; menu_title?: string; position?: number; capability: string; icon?: string; autoload: boolean;
    storage: { mode: 'fields' } | { mode: 'array'; option: string }; fields: unknown[]; tabs: { title: string; fields: unknown[] }[];
    children: { slug: string; title: string; capability?: string; fields: unknown[] }[] }
  export const CAPABILITY_PATTERN: RegExp;   // /^[a-z0-9_]+$/
  export function formFromData(data: Record<string, unknown>): OptionsPageForm;                          // defaults for absent keys
  export function dataFromForm(original: Record<string, unknown>, form: OptionsPageForm): Record<string, unknown>; // keeps unknown keys; leaves absent keys absent when the form holds their default
  // PageFieldsBuilder.tsx — composes Phase 2's FieldTree, FieldSettings, AddFieldMenu and ConditionBuilder over builder-state's reduce()
  export function PageFieldsBuilder(props: { group: RawGroup; onChange(next: RawGroup): void; readOnly: boolean }): JSX.Element;
  ```
  A `fields` array of bare fields is edited as one implicit group and written back as bare fields; an array of groups gets one builder per group. Saving goes through `useDeclarationFile('options', slug, title).save(dataFromForm(file.data, form))`, so the text edit is Phase 1's comment-preserving `applyDeclarationEdits` and the write is Phase 1's writer (hash conflict, schema check, `proto_blocks_declaration_errors`, confinement). Routing inside the screen: `?slug=<slug>` → editor, `?new=1` → create, else list.

- [ ] **Step 1: Write the failing pure tests**

`options-declaration.test.ts`:
- `formFromData({ title: 'X' })` → `menu === 'top'`, `capability === 'manage_options'`, `autoload === true`, `storage.mode === 'fields'`.
- `dataFromForm(cadcoData, {...formFromData(cadcoData), capability: 'edit_products'})` changes only `capability`; unknown keys of `cadcoData` survive; `dataFromForm({title: 'X'}, formFromData({title: 'X'}))` deep-equals `{title: 'X'}` (no defaults written).
- `applyDeclarationEdits(cadcoText, cadcoData, dataFromForm(…capability: 'edit_products'))` keeps the `// CADCO product display settings` comment.

- [ ] **Step 2: Write the failing screen tests**

`options-pages.test.tsx` (`mockApi` with `admin/options-pages`: the CADCO file page, a PHP page with `double` set, one external `plugin` row; `schema-files/options/cadco-display-settings` returning the fixture text and hash):
- list shows `Display Settings`, `Under Products`, `manage_woocommerce`, `Array: cadco_display_settings`, origin badge `Proto-Blocks file`, the `Declared twice` badge on the PHP row, and external rows in a separate read-only section `Other settings pages` with a `Plugin: ` label.
- `fileModsAllowed: false` → `ReadOnlyBanner`, `New options page` and every `Save` disabled.
- editor for CADCO: menu placement radio shows `Under an existing menu` with `Products` selected; storage shows `Single array option` with `cadco_display_settings`; changing capability to `read` shows the warning `Anyone who can log in will be able to change these settings.`; capability `Manage Options` shows `Use lowercase letters, digits and underscores.` and disables Save; `Save` posts to `schema-files/options/cadco-display-settings` a `text` containing `"capability": "edit_products"` and `// CADCO product display settings`.
- a 409 `proto_blocks_conflict` answer opens Phase 1's `ConflictDialog`.
- the tabs editor adds a tab `Tracking`, renames and reorders it; the child pages editor adds `{slug: 'footer', title: 'Footer'}` and rejects slug `Footer!` with `Use lowercase letters, digits and hyphens.`
- switching storage from array to per-field on a saved page shows `Stored values stay in cadco_display_settings; the page will read new per-field options.` before saving.
- the fields section renders `PageFieldsBuilder` with the page's implicit group (mocked Phase 2 pieces asserting props).
- `Delete` opens `ConfirmDialog` naming `proto-options/cadco-display-settings/` and stating `Saved values are not deleted.`; confirming sends the DELETE for `schema-files/options/cadco-display-settings`.
- a PHP-only page opens read-only with `Declared in PHP at functions.php:40. Saving creates a file that takes over.` and a `Create file` button that saves the PHP declaration's data as a new file.
- `?new=1`: entering title `Shop Notices` proposes slug `shop-notices`; Save creates through `schema-files/options/shop-notices` with the server template text edited by the form.

- [ ] **Step 3: Run to verify failure** → `npm run test:js -- options-pages options-declaration` FAIL.

- [ ] **Step 4: Implement** the screen per the Phase 1–3 design guide (`.superpowers/sdd/2026-11-02-phase-0-admin-rebuild/design/phase-1-3/options-pages.html`). Form validation with `optionsSchema` (Phase 1 `toZod`). `MenuIconField` only when `menu === 'top'`. `CapabilityField` is a combobox over `capabilities` that accepts free text matching `CAPABILITY_PATTERN` and warns when the text is not in the list (`No role has this capability; only super admins will see the page.`). Read-only state comes from Phase 1's `DeclarationShell` with the row's `readOnlyReason`. A read-only `<pre>` beside the form shows the live file text (`applyDeclarationEdits` over the current form).

- [ ] **Step 5: Run to verify pass** — `npm run test:js && npm run build && composer test` → PASS.

- [ ] **Step 6: Commit**

```bash
git add src/admin includes/Admin/AdminApp.php includes/Admin/AdminPage.php
git commit -m "feat(options): Options Pages admin screen writing page.jsonc"
```

---

### Task 10: CLI `options list|create`

**Parallel group:** B
**Depends on:** Tasks 1, 4; Phase 1 (CLI layout, `SchemaFileWriter`, `validate` over the registry)

**Files:**
- Create: `includes/CLI/OptionsCommand.php`, `tests/php/CLI/OptionsCommandTest.php`
- Modify: `includes/CLI/Commands.php` (one `WP_CLI::add_command('proto-blocks options', OptionsCommand::class);`)

**Interfaces:**
- Produces:
  ```php
  final class OptionsCommand {
      public function __construct(?OptionsPagesRegistry $registry = null, ?\ProtoBlocks\ContentModel\SchemaFileWriter $writer = null);
      public function list(array $args, array $assoc): void;    // columns slug,title,menu,capability,storage,origin; --format=table|json|csv
      public function create(array $args, array $assoc): void;  // <title> [--slug=] [--menu=top] [--capability=manage_options] [--storage-option=]
      public static function validateSlug(string $slug, array $existing): ?string; // error message or null; Slug::error($slug, 'options') + existing check
  }
  ```
  `create` builds the text with `PageTemplate::render($title, $opts)`, checks it with `PageDefinition::fromArray` (so `--capability`, `--menu` and `--storage-option` get the same grammar and protected-name rules as files), and writes with `SchemaFileWriter::write('options', $slug, $text, null, null)` (atomic, confined to the stylesheet directory). `WriteException` → `WP_CLI::error($e->getMessage())`. `wp proto-blocks validate` covers options files through Phase 1's registry issues (Task 4) and this module's issues (Task 11).

- [ ] **Step 1: Write the failing tests**

`OptionsCommandTest` (writer constructed over a temp stylesheet dir; `WP_CLI` stubbed with `error` throwing):
- `testValidateSlug`: `'proto-blocks'` → reserved error; `'Foo'` and `'../evil'` → grammar error; an existing slug → message containing `already exists`; `'shop-notices'` → null.
- `testCreateNeverOverwrites`: with `proto-options/shop-notices/page.jsonc` present, `create(['Shop Notices'])` errors with `proto-options/shop-notices/page.jsonc already exists` and the file is unchanged.
- `testCreateWritesConfinedFile`: `--slug=../evil` → error, nothing written anywhere; a valid create writes `proto-options/shop-notices/page.jsonc` inside the temp dir and nowhere else (`realpath` prefix assertion).
- `testCreateRefusesProtectedStorageOption`: `--storage-option=siteurl` → error naming `siteurl`, nothing written.
- `testCreateRejectsBadCapability`: `--capability="manage options"` → error, nothing written.

- [ ] **Step 2: Run to verify failure** → FAIL.
- [ ] **Step 3: Implement** and the one `add_command` line.
- [ ] **Step 4: Run to verify pass** → `composer test` PASS. Manual on a Local site: `wp proto-blocks options create "Shop Notices" --menu=woocommerce && wp proto-blocks options list && wp proto-blocks validate`.
- [ ] **Step 5: Commit**

```bash
git add includes/CLI tests/php/CLI/OptionsCommandTest.php
git commit -m "feat(cli): wp proto-blocks options list and create"
```

---

### Task 11: `Modules\OptionsPages` wiring

**Parallel group:** E
**Depends on:** Tasks 2–10; Phase 1 (`ModuleInterface`, `Manager`)

**Files:**
- Create: `includes/Modules/OptionsPages.php`, `tests/php/OptionsPages/ModuleTest.php`
- Modify: `includes/Modules/Manager.php` (`DEFAULTS` += `'options_pages' => true`; construct and boot `OptionsPages` beside the other modules; `optionsPages(): ?OptionsPages`), `includes/CLI/Commands.php` (one line: `optionsPages()?->issues() ?? []` added to the module issues `validate` passes to `validationRows`), `CHANGELOG.md` (Unreleased: options pages), `README.md` (one "Settings pages" section: PHP one-liner, file example, `storage` override, option naming `<page_slug>_<field_name>`, getter context)

**Interfaces:**
- Produces:
  ```php
  final class OptionsPages implements \ProtoBlocks\Modules\ModuleInterface {
      public function __construct(\ProtoBlocks\ContentModel\Registry $declarations, Manager $manager);
      public function id(): string;   // 'options_pages'
      public function boot(): void;
      public function registry(): OptionsPagesRegistry;
      public function values(): OptionsValues;
      public function issues(): array;   // list<Issue>: registry warnings + MenuRegistrar warnings + OptionsValues warnings
  }
  ```
  `boot()`: filter `proto_blocks_declaration_errors` (priority 10, 4 args) → `OptionsPagesRegistry::declarationErrors`; `admin_menu` priority 20 → `MenuRegistrar::register($registry->all())` with the icon resolver `MenuIcon::resolve($page->icon, $page->root ?? get_stylesheet_directory(), <matching URL>)` and `$capabilityKnown` over `wp_roles()`; filter `proto_blocks_admin_app_hooks` → `hookSuffixes()`; `rest_api_init` → `OptionsValuesRoutes::registerRoutes()`; `admin_menu` `PHP_INT_MAX` → `ExternalMenus::capture()` (admin only). When `$manager->isEnabled('fields')`: filters `proto_blocks_field_groups` and `proto_blocks_field_stores` → `FieldsBridge`, and `OptionsValues`' `$extraGroups` reads `FieldsModule::instance()->index()->forRef(ObjectRef::option($slug), ['options_page' => $slug])`; otherwise `$extraGroups` returns `[]`. `OptionsValues`, `PageRenderer` and `OptionsValuesRoutes` use Phase 2's library classes directly (`TypeRegistry::withCoreTypes()`), not the Fields module's hooks, so pages render and save with the Fields module switched off (spec goal 6: settings pages beside ACF).

- [ ] **Step 1: Write the failing tests**

`ModuleTest` (Phase 1 hook stubs): after `boot()` the recorded hooks include `admin_menu` (priority 20), `rest_api_init`, filters `proto_blocks_admin_app_hooks` and `proto_blocks_declaration_errors`, and (Fields enabled) `proto_blocks_field_groups`, `proto_blocks_field_stores`; `id() === 'options_pages'`.
- `testWorksWithFieldsModuleDisabled`: `proto_blocks_modules` returning `['fields' => false]` → `Manager::optionsPages()` is booted, `admin_menu` and `rest_api_init` are recorded, `proto_blocks_field_groups` is not, and `values()->fields($cadco)` still returns the three CADCO fields.
- `testDisabledModuleRegistersNothing`: `['options_pages' => false]` → `optionsPages() === null`, `states()['options_pages'] === false`, and no hook from this module is recorded.

- [ ] **Step 2: Run to verify failure** → FAIL.
- [ ] **Step 3: Implement**, register in `Manager`, pass issues to `validate`, write the docs entries.
- [ ] **Step 4: Run to verify pass** → `composer test && npm run test:js && npm run build` PASS.
- [ ] **Step 5: Commit**

```bash
git add includes/Modules includes/CLI/Commands.php tests/php/OptionsPages/ModuleTest.php CHANGELOG.md README.md
git commit -m "feat(options): OptionsPages module"
```

---

### Task 12: E2E — a rendered options page saves and a block template reads it

**Parallel group:** E
**Depends on:** Task 11; the E2E harness on `feat/content-model`

**Files:**
- Create: `tests/e2e/options-page.spec.ts`, `tests/e2e/fixtures/mu-plugins/pb-e2e-options.php`, `tests/e2e/fixtures/proto-options/theme-settings/page.jsonc` (copy of the unit fixture), `tests/e2e/fixtures/proto-options/shop-notices/page.jsonc`, `tests/e2e/fixtures/blocks/options-probe/block.json`, `tests/e2e/fixtures/blocks/options-probe/template.php`
- Modify: `.wp-env.json` (add a `mappings` key if absent, keeping every existing entry: `wp-content/mu-plugins/pb-e2e-options.php` → `./tests/e2e/fixtures/mu-plugins/pb-e2e-options.php`, `wp-content/pb-e2e` → `./tests/e2e/fixtures`)

**Interfaces:**
- `pb-e2e-options.php`: adds `WP_CONTENT_DIR . '/pb-e2e'` to `proto_blocks_schema_paths` and `WP_CONTENT_DIR . '/pb-e2e/blocks'` to `proto_blocks_paths`; registers a `product` post type when WooCommerce is absent; on first load adds `manage_woocommerce` to `administrator` and creates role `pb_shop_manager` (`read`, `manage_woocommerce`, no `unfiltered_html`) with user `shopmanager` / `password`.
- `shop-notices/page.jsonc`: `menu: "top"`, `capability: "manage_woocommerce"`, fields `banner` (text) and `tracking_snippet` (code).
- `options-probe/template.php` echoes `<p class="pb-probe-phone"><?php echo proto_field('phone', 'option:theme-settings'); ?></p>` and the same for `copyright` from `option:theme-settings-footer` (the getter escapes; no extra escaping, to prove it).

- [ ] **Step 1: Write the spec**

`options-page.spec.ts`:
- `admin.visitAdminPage('admin.php', 'page=theme-settings')`: the `Theme Settings` top-level menu item and its `Footer` submenu exist; tabs `General` and `Tracking` render.
- fill `phone` = `<b>555</b> & co`, `Save settings` → toast `Settings saved.`; reload shows the value; `wp-env run tests-cli wp option get theme-settings_phone` prints it.
- create a post with `<!-- wp:proto-blocks/options-probe /-->` via `requestUtils.createPost`, visit it: `.pb-probe-phone` text equals `<b>555</b> & co` literally (no `<b>` element inside).
- Footer child page: set `copyright`, save, front end shows it.
- `shopmanager` → `admin.php?page=theme-settings` answers with the WordPress "not allowed" screen (capability `manage_options`).
- `shopmanager` on `admin.php?page=shop-notices` with `shop-notices_tracking_snippet` seeded to `<script>window.pbOk=1</script>`: the snippet shows read-only with no input named `tracking_snippet`; saving `banner` = `<script>alert(1)</script>Sale` stores a value without `<script`; a direct `window.wp.apiFetch({ path: '/proto-blocks/v1/options/shop-notices', method: 'POST', data: { fields: { group_shop_notices_1: { tracking_snippet: '<img onerror=x>' } }, present: { group_shop_notices_1: ['tracking_snippet'] } } })` from that page leaves the stored snippet unchanged.
- no nonce: a fresh `request.newContext({ baseURL, storageState: process.env.STORAGE_STATE_PATH })` POSTs `{ fields: { group_theme_settings_1: { phone: '1' } }, present: { group_theme_settings_1: ['phone'] } }` to `/wp-json/proto-blocks/v1/options/theme-settings` without `X-WP-Nonce` → status 401; `theme-settings_phone` unchanged.

- [ ] **Step 2: Run** — `npm run build && npm run wp-env start && npm run test:e2e -- options-page` → PASS (a failure means missing wiring; fix it in the owning task).
- [ ] **Step 3: Commit**

```bash
git add tests/e2e .wp-env.json
git commit -m "test(e2e): options page save, trust boundary and front-end read through proto_field"
```

---

### Task 13: Done criterion — CADCO display settings

**Parallel group:** E
**Depends on:** Tasks 11, 12

**Files:**
- Create: `tests/php/OptionsPages/CadcoAcceptanceTest.php`, `tests/e2e/cadco-display-settings.spec.ts`, `tests/e2e/fixtures/proto-options/cadco-display-settings/page.jsonc` (copy of the Task 1 fixture)

- [ ] **Step 1: Confirm the fixture against the live theme**

If `cadco-theme` is available, compare `cadco_display_settings`' real keys, value formats and the page's parent menu and capability with the Task 1 fixture, and correct the fixture, this task's values and Task 1's tests together before continuing. Note the result in the commit body.

- [ ] **Step 2: Write the PHPUnit acceptance test**

`CadcoAcceptanceTest`, with `cadco_display_settings = ['hide_children' => '1', 'children_placement' => 'section', 'children_heading' => 'More in this range', 'legacy_sort' => 'menu_order']` stored before boot:
- the registry loads the fixture with no warnings; `MenuRegistrar` records `add_submenu_page('edit.php?post_type=product', 'Display Settings', 'Display Settings', 'manage_woocommerce', 'cadco-display-settings', …)`.
- `OptionsValues::read()` returns the three stored values unchanged.
- through the Task 6 reader: `get('children_heading', 'option:cadco-display-settings') === 'More in this range'`, `get('hide_children', …) === true`.
- a `manage_woocommerce` user saves `fields[group_cadco_display_settings_1] = {hide_children: '0', children_placement: 'related', children_heading: ''}` with all three present → stored array is exactly `['hide_children' => '', 'children_placement' => 'related', 'children_heading' => 'More in this range', 'legacy_sort' => 'menu_order']` (the hidden heading is not written), so the theme's existing `!empty($opts['hide_children'])` read sees false.
- a user with only `edit_posts` → `permission()` false.

- [ ] **Step 3: Write the E2E spec**

`cadco-display-settings.spec.ts` (seed with `wp-env run tests-cli wp option update cadco_display_settings '{"hide_children":"1","children_placement":"related","legacy_sort":"menu_order"}' --format=json` in `beforeAll`):
- as `shopmanager`: the `edit.php?post_type=product` menu contains `Display Settings`; the page opens; `Hide child products` is checked; `Section heading` is hidden.
- choose `Own section` → `Section heading` appears; fill `Featured range`; save → toast; reload keeps `Own section` and the heading.
- `wp option get cadco_display_settings --format=json` via `tests-cli` still contains `"legacy_sort":"menu_order"` and `"hide_children":"1"`.
- `shopmanager` sees no `Edit declaration` link; as admin the link is present and opens the Options Pages editor for `cadco-display-settings`.

- [ ] **Step 4: Run** — `composer test && npm run test:e2e -- cadco-display-settings` → PASS.
- [ ] **Step 5: Commit**

```bash
git add tests/php/OptionsPages/CadcoAcceptanceTest.php tests/e2e
git commit -m "test(options): CADCO display settings re-declared as a file"
```

---

### Task 14: E2E and visual checks for the Options Pages screen

**Parallel group:** E
**Depends on:** Tasks 9, 11, 12, 13

**Files:**
- Create: `tests/e2e/options-pages-screen.spec.ts`, baseline images under `tests/e2e/__screenshots__/` (generated)

- [ ] **Step 1: Write the spec**

`options-pages-screen.spec.ts` (writes go to the active theme; `afterAll` deletes `proto-options/e2e-notices` through `DELETE /proto-blocks/v1/schema-files/options/e2e-notices`):
- the list shows `Display Settings` (Under Products, `manage_woocommerce`, `Array: cadco_display_settings`) and `Theme Settings` with origin `Proto-Blocks file` (read-only: they live in the `pb-e2e` filter root), and a non-empty `Other settings pages` section containing `General` with `Core`.
- create `E2E Notices` under `Settings` with capability `manage_options`, a tab `Main` and one text field `notice` added through the builder; save → toast; the menu item appears under Settings after reload; `wp-env run tests-cli cat wp-content/themes/<active>/proto-options/e2e-notices/page.jsonc` contains `"capability": "manage_options"` and the template comment `// Where the page appears`.
- edit the capability on `E2E Notices` and save; the template comments in the file survive.
- save a value for `notice` on the new page, then delete `E2E Notices` through the confirm dialog; the menu item disappears; `wp option get e2e-notices_notice` still prints the value.
- read-only: `wp-env run tests-cli wp config set DISALLOW_FILE_MODS true --raw`, reload: the banner `File modifications are disabled on this site` shows and Save/New/Delete are disabled; `wp config delete DISALLOW_FILE_MODS` in a `finally`.

- [ ] **Step 2: Add the visual checks** in the same spec, each `await expect(page.locator('#wpbody-content')).toHaveScreenshot(<name>)` with dynamic times masked:
  `options-pages-list.png`, `options-pages-edit-cadco.png`, `options-page-cadco-related.png`, `options-page-cadco-section.png`, `options-page-theme-settings-tracking-tab.png`, `options-pages-readonly.png`.

- [ ] **Step 3: Generate baselines and run** — `npm run test:e2e -- options-pages-screen --update-snapshots`, inspect each PNG against the design mockup, then `npm run test:e2e` → all green. Baselines are platform-suffixed (see `tests/e2e/README.md`); generate the Linux set for CI separately.
- [ ] **Step 4: Commit**

```bash
git add tests/e2e
git commit -m "test(e2e): Options Pages screen flows and visual baselines"
```

---

## Self-review notes

- **Spec coverage.** Section 7: declaration keys and `proto-options/<slug>/page.jsonc` (T1, T4), top-level and submenu including post type menus (T3), capability default and any capability (T1, T3, T5), icon rules shared with post types via `MenuIcon` (T3, T11), tabs client-side (T7), children as real submenu pages (T1, T3), fields reusing the Phase 2 schema (T1, T5), per-field storage `<page_slug>_<field_name>` autoloaded with the page `autoload` override (T1, T2), the `storage` array override (T1, T2, T13), same React form stack and REST save with nonce under the page capability (T5, T7), "edit this declaration" link for `manage_options` (T7), core settings screens out of scope (Global Constraints). Section 1: `proto_options_page()` (T1, T4), discovery with child-theme precedence and `proto_blocks_schema_paths` (T4 via Phase 1), file wins with the double-declaration warning (T4, badge in T9). Section 4: list with origins including plugins' and core pages (T8, T9), JSONC editing through the Phase 1 writer preserving comments (T9), read-only mode (T9, T14), delete confirmation naming the path (T9). Section 5: `option:` getter context, escaping and raw (T6); conditional logic hidden client-side and skipped server-side (T5, T7). Section 6: Security Review Focus. Section 8: `Modules\OptionsPages` with toggle and independence from the Fields module (T11). Section 9: `options list|create` (T10) and `validate` (T4, T11). Section 10 done criterion: T13.
- **Rulings on spec gaps** (each stated in the task that implements it):
  1. Option names follow the spec literally, `<page_slug>_<field_name>` with the slug's hyphens kept (`theme-settings_phone`), matching Phase 2's `OptionStore` (T1). The earlier hyphen-to-underscore rewrite was dropped: the spec pins the name.
  2. Child pages get slug `<parent>-<child>`, are addressed as `option:<parent>-<child>`, inherit the parent's capability, and default to their own per-field storage (T1).
  3. `fields` and `tabs[].fields` accept field groups or bare fields; consecutive bare fields are wrapped in an implicit group and missing keys default to `field_<slug_underscored>_<name>` (T1).
  4. A save with any validation error writes nothing and returns 422 with per-field errors (T5); fields hidden by conditional logic are neither validated nor written, the same rule as Phase 2's `SaveHandler` (T5).
  5. Array mode never removes keys it does not declare and keeps the stored scalar format of `true_false` values (T2).
  6. Option names are checked against a protected-core deny-list and the 191-character limit when a declaration loads (T1), when it is written (T4, through `proto_blocks_declaration_errors`), and again at save time (T2, T5).
  7. Values REST lives at `options/<slug>` outside `admin/`, because its permission is the page capability, not `manage_options` (T5).
  8. The rendered page is the Phase 0 admin bundle on screen `options-page` hosting Phase 2's `FieldsForm`; extra hooks enqueue it through the new `proto_blocks_admin_app_hooks` filter (T7).
  9. Untabbed `fields` render above the tab strip when both are present (T7).
  10. Standalone field groups with an `options_page` location rule attach to the page (T5); Phase 2 owns the rule grammar, Phase 3 owns storage and rendering.
  11. A file that fails to parse or validate is skipped with a warning and does not fall back to a PHP declaration of the same slug (T4).
  12. Other plugins' and core settings pages appear read-only on the list, captured from the admin menu globals on the Options Pages screen request (T8).
  13. A well-formed capability no role grants is kept verbatim and warned about; a malformed or empty one refuses the page (T1, T3).
- **Names used across tasks:** `PageDefinition::{fromArray,slugFromTitle,fields,optionName,toClientArray}`, `InvalidDeclaration`, `ProtectedOptions::isProtected`, `OptionsStore::{has,read,save,coerceLike}`, `MenuRegistrar::{register,hookSuffixes,warnings}`, `OptionsPagesRegistry::{all,get,warnings,issues,refresh,declarationErrors,KIND}`, `PageTemplate::render`, `OptionsValues::{pageGroups,groupsFor,fields,warnings,read,save}`, `OptionsValuesRoutes`, `OptionsFieldStore`, `FieldsBridge::{filterGroups,filterStores}`, `PageRenderer::{pageData,render}`, `OptionsPagesRoutes::{row,menuLabel}`, `ExternalMenus::{capture,get}`, `OptionsCommand::{list,create,validateSlug}`, `Modules\OptionsPages`, TS `OptionsPageData`, `submissionFromForm`, `OptionsPageForm`, `formFromData`, `dataFromForm`, `CAPABILITY_PATTERN`, `PageFieldsBuilder` — each defined once in its task's Interfaces block. Phase 0/1/2 names are only those in the contracts table.
