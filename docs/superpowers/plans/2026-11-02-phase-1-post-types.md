# Phase 1: Post Types and Taxonomies Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `proto_post_type('Product');` and `proto-types/product/type.jsonc` each register a complete post type (and `proto_taxonomy()` / `proto-taxonomies/<slug>/taxonomy.jsonc` a taxonomy) with the spec's defaults, override core and plugin types, and are listed, created, edited and deleted from two new admin screens that write the same JSONC files.

**Architecture:** A pure `ContentModel` layer (JSONC decoder, JSON Schema subset checker, naming, labels, icon resolver, file loader, PHP declaration store, registry with precedence, args builders, file writer, guardrails, template scaffolder) carries all logic and is unit-tested without WordPress. A thin `Modules` layer (`Modules\Manager`, `Modules\PostTypes`, `Modules\Taxonomies`) hooks it into WordPress: registration on `init` 10, overrides through `register_post_type_args` / `register_taxonomy_args`, rewrite flush on hash change. REST route groups and two React screens (on the Phase 0 shell) read the registry and write files through the writer; WP-CLI commands use the same services.

**Tech Stack:** PHP 8.1, WordPress 6.3+, PHPUnit 10 without WordPress, React 18, TypeScript, shadcn/ui, react-hook-form + zod, `jsonc-parser` (new dependency), Jest 30 + Testing Library, Playwright on `@wordpress/env`, WP-CLI.

**Spec:** `docs/superpowers/specs/2026-11-02-post-types-fields-settings-design.md` (sections 1, 2, 3, 4, 6, 8, 9 as they apply to post types and taxonomies; section 10 "Phase 1").

**Execution order.** The `**Parallel group:**` letter names the gate a task waits for (same convention as the Phase 2 plan); inside a group, `**Depends on:**` orders the tasks and any task whose dependencies are merged may run concurrently in its own worktree.
- **Group A (Tasks 1–9)** is PHP-only and needs nothing from Phase 0. Waves: {1, 2, 3} → {4} → {5, 7} → {6, 8} → {9}.
- **Group B (Task 10)** needs Phase 0 Tasks 2–3 merged (`AdminApp::SCREENS`, `AdminApp::renderScreen()`, `AdminRestController::ROUTE_GROUPS`, `tests/php/Admin/wp-stubs.php`) and Tasks 6–8.
- **Group C (Tasks 11–13)** needs Phase 0 Tasks 1–3 merged (toolchain, shell components, `api.ts`, `helpers.tsx`, the `src/admin/screens/index.ts` registry). Task 11 first, then 12 and 13 in parallel.
- **Group D (Task 14)** needs every other task. It uses the E2E harness already on `feat/content-model` (`.wp-env.json`, `playwright.config.ts`, `tests/e2e/global-setup.ts`, scripts `wp-env` and `test:e2e`) and creates none of it.

## Global Constraints

- PHP 8.1+; WordPress floor stays 6.3 in this phase.
- Files are the only store for the content model. The only database write this phase adds is the option `proto_blocks_rewrite_hash` (array keyed by stylesheet), never declaration data.
- Declaration files are data: never `include`/`require`/`eval` them; only `.jsonc` and `.json` are read or written; 262144 bytes (256 KB) maximum per file.
- Folders and files: `proto-types/<slug>/type.jsonc`, `proto-taxonomies/<slug>/taxonomy.jsonc`; `.json` accepted; `.jsonc` wins when both exist (warning `both_extensions`).
- Slugs: post type `^[a-z][a-z0-9_-]*$`, max 20 chars; taxonomy same pattern, max 32 chars; derived with `sanitize_title` of the singular.
- `manage_options` on every screen and route; writes also need `AdminPage::fileModsAllowed()`, `is_writable()` on the target, the REST nonce and realpath confinement to the active stylesheet or template directory.
- Every user-visible string goes through `__()` (PHP) or `@wordpress/i18n` (JS), text domain `proto-blocks`.
- REST errors: `new WP_Error('proto_blocks_<code>', __(...,'proto-blocks'), ['status' => N])`; exception text only when `PROTO_BLOCKS_DEBUG`.
- Module independence: `ContentModel` classes never call block classes; `Modules\PostTypes` and `Modules\Taxonomies` each register only their own hooks.
- Commit messages `<type>(<scope>): <subject>`, no attribution lines; each task commits on its own worktree branch and the controller merges it into `feat/content-model`.
- PHPUnit runs every test in one process, so the first definition of a stub wins: every new stub is `function_exists`/`class_exists`-guarded and has the same shape as the existing ones (`apply_filters` iterates `$GLOBALS['pb_test_filters'][$hook][]` like `tests/php/Fields/wp-stubs.php`; `get_option` is the `tests/php/bootstrap.php` one reading `$GLOBALS['pb_test_options']`; `sanitize_title` is `tests/php/CLI/wp-stubs.php`'s).
- An object key `__proto__` anywhere in a declaration file is refused on both sides (PHP `Jsonc::decode` throws, TS `parseJsonc` returns an error), so a file can never poison the client editor. `constructor` and `prototype` are ordinary keys.
- UI layout and copy follow `.superpowers/sdd/2026-11-02-phase-0-admin-rebuild/design/phase-1-3/design-guide.md` (screens A and B, mockups `post-types.html`, `taxonomies.html`); where the guide and this plan disagree on behaviour the plan wins, on pixels the guide wins.
- `composer test` and `npm run test:js` pass after every task.

## Review Focus

1. **Path traversal and symlink escape** (a slug `../x`, `a/b` or `%2e%2e` in a route or CLI argument; a `proto-types/` or `proto-types/<slug>/` folder or `type.jsonc` that is a symlink leaving the theme; a `proto_blocks_schema_paths` root targeted for writing): writes, deletes and scaffolds refuse with 400 `proto_blocks_invalid_slug` or 403 `proto_blocks_path_outside_root` and touch nothing outside the theme. A theme root that is itself a symlink (a developer's linked theme) still works, because roots are compared by realpath. Tests: Task 4 (`testSymlinkedThemeRootResolved`, `testSymlinkedFileOutsideRootSkipped`), Task 7 (`testWriteRejectsTraversalSlug`, `testWriteRefusesSymlinkedKindFolder`, `testWriteRefusesSymlinkedFolder`, `testWriteRefusesSymlinkedFile`, `testWriteRefusesFilterRoot`, `testSymlinkedThemeRootIsWritable`, `testDeleteRefusesSymlinkedFolder`), Task 8 (`testSymlinkedTemplatesDirRefused`), Task 10 (`testSlugRegexRejectsTraversal`).
2. **A hostile SVG menu icon** (`<script>`, `onload=`, `<foreignObject>`, `href="javascript:..."`, `<!DOCTYPE`/`<!ENTITY`, a 2 MB file, a `.svg` symlinked out of the theme): scripts and handlers are stripped before the data URI is built; oversized, unparseable or escaping files fall back to the default icon with warning `icon_invalid`. Tests: Task 3.
3. **A broken or hostile declaration file** (invalid JSONC, wrong types, a reserved slug such as `author`, a `type.php` in the folder, a `"__proto__"` key, a 10 MB file, a folder name that differs from the `slug` key): that one declaration is skipped with an error the screens, the admin notice and `validate` show; every other type still registers; nothing is executed; the 10 MB file is refused by size before it is read or decoded, and a 10 MB save is refused with 413 before decoding. Tests: Task 1 (`testProtoKeyRejected`), Task 4 (`testInvalidFileIsSkippedOthersLoad`, `testPhpFileInFolderIsIgnored`, `testTenMegabyteFileRejectedUnread`, `testProtoKeyFileSkipped`, `testFolderSlugMismatch`, `testReservedSlugIsError`), Task 7 (`testOversize`), Task 11 (`parseJsonc` refuses `__proto__`).
4. **Read-only hosts** (`DISALLOW_FILE_MODS`, a theme folder that is not writable): every list and edit screen still renders with one banner naming the reason and the path, every write control is disabled, and the routes answer 403 `proto_blocks_file_mods` / `proto_blocks_not_writable` if called directly. Tests: Task 7 (`testFileModsDisabled`, `testNotWritableRootRefused`), Task 8 (`testFileModsDisabledRefused`), Task 10 (`testWriteRoutesUseWritePermission`, `testCreateTargetReportsNotWritable`), Task 12 and Task 13 (read-only tests), Task 14 (read-only E2E).
5. **The double declaration** (same slug in PHP and in a file): the file wins entirely and the warning, naming both locations, appears in the admin notice on Proto-Blocks screens, as the `Declared twice` badge, and in `wp proto-blocks validate`. Tests: Task 4 (`testFileWinsOverPhpAndIsReportedDouble`), Task 6 (`DoubleDeclarationNoticeTest`), Task 9 (`validationRows`), Task 11 (`OriginBadge`), Task 12 (list test), Task 14 (double-declaration E2E).
6. **Direct REST calls that skip the UI** (a non-admin, a missing nonce, a body valid as JSON but failing the schema, a save that drops `editor` from `supports` on a type with posts, a delete of a type with posts without `confirm`): the server enforces each rule itself (401/403, 400 `proto_blocks_schema_invalid`, 409 `proto_blocks_needs_confirmation`, 409 `proto_blocks_confirm_required`). Tests: Task 7 (Guardrails), Task 10 (route tests), Task 14 (unauthenticated 401).
7. **The file changed on disk after the screen loaded it**: the save is rejected with 409 `proto_blocks_conflict` and the screen offers to reload instead of overwriting; comments and key order survive every UI save. Tests: Task 7 (`testWriteRejectsStaleHash`), Task 11 (comment and order preservation), Task 12 (conflict dialog).

---

## File structure

**PHP (create)** under `includes/`
- `ContentModel/Jsonc.php`, `ContentModel/JsoncException.php` — comment- and trailing-comma-tolerant decoder.
- `ContentModel/SchemaCheck.php` — JSON Schema subset validator.
- `ContentModel/schemas/content-model.schema.json` — the one JSON Schema (`$defs.type`, `$defs.taxonomy`), shared with the client.
- `ContentModel/Inflector.php`, `ContentModel/Labels.php`, `ContentModel/Slug.php` — naming.
- `ContentModel/MenuIcon.php`, `Security/SvgSanitizer.php` — icon rules.
- `ContentModel/Issue.php`, `ContentModel/Declaration.php`, `ContentModel/SchemaPaths.php`, `ContentModel/FileLoader.php`, `ContentModel/PhpDeclarations.php`, `ContentModel/Registry.php` — declarations and precedence.
- `ContentModel/PostTypeArgs.php`, `ContentModel/TaxonomyArgs.php` — args builders and override merge.
- `ContentModel/RewriteHash.php`, `ContentModel/OriginTracker.php`, `ContentModel/DoubleDeclarationNotice.php`.
- `ContentModel/SafeFile.php`, `ContentModel/SchemaFileWriter.php`, `ContentModel/WriteException.php`, `ContentModel/Guardrails.php`, `ContentModel/DeclarationTemplate.php`.
- `ContentModel/TemplateScaffolder.php`.
- `Modules/ModuleInterface.php`, `Modules/Manager.php`, `Modules/PostTypes.php`, `Modules/Taxonomies.php`.
- `CLI/ContentModelCommands.php`.
- `API/Admin/PostTypesRoutes.php`, `API/Admin/TaxonomiesRoutes.php`, `API/Admin/SchemaFilesRoutes.php`, `Admin/ContentModelMenu.php`.
- `content-model.php` — global `proto_post_type()` and `proto_taxonomy()`.

**PHP (modify, one-line registrations)**
- `proto-blocks.php` — `require_once` of `includes/content-model.php` beside `helpers.php`.
- `includes/Core/Plugin.php` — construct `Modules\Manager` and hook its `boot()` on `after_setup_theme`, expose `getContentModel(): Registry` and `getModules(): Manager` (Task 6); hook `ContentModelMenu` (Task 10).
- `includes/CLI/Commands.php` — register the two command groups; `validate` appends the schema-file report.
- `includes/API/AdminRestController.php` (Phase 0) — three entries in `ROUTE_GROUPS`.
- `includes/Admin/AdminApp.php` (Phase 0) — `'post-types', 'taxonomies'` in `SCREENS`.

**JS (modify, Task 11 only)** `src/admin/api.ts` (`ApiError.data`), `src/admin/bootstrap.ts` (`Screen` union), `src/admin/screens/index.ts` (two `SCREENS` entries), `package.json` (`jsonc-parser`).

**Tests (create)** under `tests/php/ContentModel/` and `tests/php/Modules/`: one test class per PHP class above, `stubs/theme.php` (theme directory and filter stubs), `stubs/hooks.php` (hook, registration and option recorders), fixtures under `tests/php/fixtures/content-model/` (recon originals and sample theme trees).

**JS (create)** under `src/admin/`
- `content-model/types.ts`, `content-model/jsonc.ts`, `content-model/schema.ts`, `content-model/useDeclarationFile.ts`.
- `components/content-model/{OriginBadge,IssueList,DeclarationShell,DeleteDeclarationDialog,GuardrailDialog,ConflictDialog,LabelsEditor,MenuIconField}.tsx`.
- `screens/PostTypesScreen.tsx`, `screens/post-types/{PostTypeList,PostTypeForm}.tsx`, `screens/TaxonomiesScreen.tsx`, `screens/taxonomies/{TaxonomyList,TaxonomyForm}.tsx`.
- `__tests__/content-model/*.test.ts(x)`.

**E2E (create)** `tests/e2e/fixtures/themes/pb-e2e-block/` (block theme with declarations), `tests/e2e/fixtures/themes/pb-e2e-classic/`, `tests/e2e/fixtures/parity.php`, `tests/e2e/content-model-utils.ts`, `tests/e2e/post-types.spec.ts`, `tests/e2e/taxonomies.spec.ts`, `tests/e2e/content-model-visual.spec.ts`. **E2E (modify)** `.wp-env.json` (one `mappings` entry).

---

### Task 1: JSONC decoder and JSON Schema subset checker

**Parallel group:** A
**Depends on:** nothing

**Files:**
- Create: `includes/ContentModel/Jsonc.php`, `includes/ContentModel/JsoncException.php`, `includes/ContentModel/SchemaCheck.php`, `includes/ContentModel/schemas/content-model.schema.json`
- Test: `tests/php/ContentModel/JsoncTest.php`, `tests/php/ContentModel/SchemaCheckTest.php`

**Interfaces:**
- Produces:
  ```php
  final class Jsonc {
      public static function decode(string $text): array;   // object root only; throws JsoncException
      public static function strip(string $text): string;   // comments and trailing commas removed, strings untouched
  }
  final class JsoncException extends \RuntimeException {
      public function __construct(string $message, public readonly int $line, public readonly int $column);
  }
  final class SchemaCheck {
      public static function schema(): array;               // decoded content-model.schema.json, memoised
      /** @return list<array{path: string, message: string}> */
      public static function validate(mixed $data, array $schema, string $ref = '#'): array;
  }
  ```
  `decode` throws when any object key at any depth is `__proto__` (message names the key and its line).
  Supported keywords (the client in Task 11 supports exactly the same): `type` (string or list of `object|array|string|boolean|integer|number|null`), `enum`, `pattern`, `minLength`, `maxLength`, `minimum`, `maximum`, `properties`, `required`, `additionalProperties` (bool or schema; default true), `items`, `uniqueItems`, `anyOf`, `$ref` (local `#/$defs/<name>` only). Error paths are JSON Pointers (`/rewrite/slug`, `/supports/2`).
- Schema `$defs.type`: `name`, `plural` (string 1–100); `slug` (`^[a-z][a-z0-9_-]{0,19}$`); `labels` (object of strings); `public`, `show_in_rest`, `hierarchical`, `exclude_from_search`, `publicly_queryable`, `show_ui`, `show_in_menu`, `show_in_nav_menus`, `show_in_admin_bar`, `map_meta_cap` (boolean); `has_archive` (boolean or string `^[a-z0-9][a-z0-9/_-]*$`); `rewrite` (false or object with `slug` string, `with_front`, `feeds`, `pages` boolean); `supports` (false or unique array of strings); `menu_position` (integer 0–10000); `menu_icon` (string ≤ 2048); `template` (array of arrays); `template_lock` (enum `false`, `"all"`, `"insert"`, `"contentOnly"`); `capability_type` (string or array of 2 strings); `taxonomies` (array of slug strings or `$ref: #/$defs/taxonomy`); `fields` (array of objects, unchecked this phase); `$schema` (string). `$defs.taxonomy`: `name`, `plural`, `slug` (`^[a-z][a-z0-9_-]{0,31}$`), `labels`, `post_types` (array of strings), `public`, `show_in_rest`, `show_admin_column`, `hierarchical`, `show_ui`, `show_tagcloud`, `show_in_quick_edit` (boolean), `rewrite` (false or object with `slug`, `with_front`, `hierarchical`), `fields`. Both allow additional properties (unknown WordPress keys pass through).

- [ ] **Step 1: Write the failing tests**

`JsoncTest`:
- `testLineAndBlockCommentsAreStripped`: `"{ // a\n \"a\": 1, /* b */ \"b\": 2 }"` → `['a' => 1, 'b' => 2]`.
- `testCommentMarkersInsideStringsSurvive`: `'{"u": "https://x.test/a//b", "c": "/* no */"}'` → both values unchanged.
- `testEscapedQuoteInString`: `'{"q": "say \"//hi\""}'` → `say "//hi"`.
- `testTrailingCommasTolerated`: `'{"a": [1, 2,], "b": {"c": 1,},}'` decodes.
- `testSyntaxErrorReportsLineAndColumn`: `"{\n  \"a\": 1\n  \"b\": 2\n}"` throws `JsoncException` with `line === 3`.
- `testNonObjectRootRejected`: `'[1]'` and `'"x"'` throw.
- `testEmptyObjectStaysArray`: `'{"labels": {}}'` decodes `labels` to `[]` (assoc decode).
- `testProtoKeyRejected`: `'{"name": "X", "labels": {"__proto__": {"a": 1}}}'` throws `JsoncException` whose message contains `__proto__`; `'{"constructor": 1}'` decodes.

`SchemaCheckTest` (uses `SchemaCheck::schema()`):
- `testMinimalTypeIsValid`: `['name' => 'Product']` against `#/$defs/type` → `[]`.
- `testWrongTypesReported`: `['name' => 'X', 'public' => 'yes', 'menu_position' => '22']` → paths `/public` and `/menu_position`.
- `testHasArchiveAcceptsBoolOrSlug`: `true`, `false`, `'resources'` valid; `'Bad Slug'` invalid at `/has_archive`.
- `testRewriteFalseOrObject`: `false` valid; `['slug' => 'resources', 'with_front' => false]` valid; `'resources'` invalid.
- `testInlineTaxonomyChecked`: `['name' => 'X', 'taxonomies' => ['category', ['name' => 'Kind', 'hierarchical' => 'no']]]` → error at `/taxonomies/1/hierarchical`.
- `testUnknownKeysPass`: `['name' => 'X', 'delete_with_user' => true]` → `[]`.
- `testSlugPattern`: type slug `'Product'` and a 21-char slug are errors; taxonomy slug of 32 chars valid.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/php/ContentModel/JsoncTest.php tests/php/ContentModel/SchemaCheckTest.php`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement `Jsonc`, `JsoncException`, `SchemaCheck` and write the schema file**

`strip()` is a single-pass scanner with an in-string flag that honours backslash escapes, replaces comments with spaces (newlines kept so line numbers survive) and drops a comma whose next non-space character is `}` or `]`. `decode()` calls `json_decode($stripped, true, 64, JSON_THROW_ON_ERROR)`; on failure locate the line/column by re-scanning for the first offending offset (a token-level scan is enough; `json_last_error_msg()` is the message). `SchemaCheck` is a recursive function over the keywords above; unknown keywords are ignored.

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/phpunit tests/php/ContentModel` → PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/ContentModel tests/php/ContentModel
git commit -m "feat(content-model): JSONC decoder and schema checker for declaration files"
```

---

### Task 2: Names, plurals, labels and slugs

**Parallel group:** A
**Depends on:** nothing

**Files:**
- Create: `includes/ContentModel/Inflector.php`, `includes/ContentModel/Labels.php`, `includes/ContentModel/Slug.php`
- Test: `tests/php/ContentModel/InflectorTest.php`, `tests/php/ContentModel/LabelsTest.php`, `tests/php/ContentModel/SlugTest.php` (require `tests/php/CLI/wp-stubs.php` for `sanitize_title`)

**Interfaces:**
- Produces:
  ```php
  final class Inflector { public static function plural(string $singular): string; }
  final class Labels {
      /** @return array<string,string> */ public static function postType(string $singular, string $plural): array;
      /** @return array<string,string> */ public static function taxonomy(string $singular, string $plural): array;
  }
  final class Slug {
      public const TYPE_MAX = 20; public const TAXONOMY_MAX = 32;
      public const OVERRIDABLE_TYPES = ['post', 'page', 'attachment'];
      public const OVERRIDABLE_TAXONOMIES = ['category', 'post_tag'];
      public static function fromName(string $name, int $max): string;            // sanitize_title, truncated, trailing '-' trimmed
      public static function error(string $slug, string $kind): ?string;          // null or one of 'pattern', 'length', 'reserved'
  }
  ```
  `Slug::error` with kind `'type'` or `'taxonomy'`. Reserved list: WordPress reserved terms (`attachment`, `attachment_id`, `author`, `author_name`, `calendar`, `cat`, `category_name`, `cpage`, `day`, `embed`, `error`, `feed`, `hour`, `m`, `minute`, `monthnum`, `name`, `order`, `orderby`, `p`, `page_id`, `paged`, `pagename`, `post_type`, `preview`, `s`, `second`, `static`, `tag`, `tag_id`, `taxonomy`, `term`, `theme`, `type`, `w`, `withcomments`, `year`, and the rest of the codex list) plus core internal types (`revision`, `nav_menu_item`, `custom_css`, `customize_changeset`, `oembed_cache`, `user_request`, `wp_block`, `wp_template`, `wp_template_part`, `wp_global_styles`, `wp_navigation`, `wp_font_family`, `wp_font_face`, `action`) and taxonomies (`nav_menu`, `link_category`, `post_format`, `wp_theme`, `wp_template_part_area`, `wp_pattern_category`). The overridable built-ins are **not** errors: a declaration with that slug is an override.

- [ ] **Step 1: Write the failing tests**

`InflectorTest` (data provider): `Resource→Resources`, `Case Study→Case Studies`, `Day→Days`, `Box→Boxes`, `Church→Churches`, `Bus→Buses`, `Person→People`, `Child→Children`, `Media→Media`, `News→News`, `Series→Series`, `Staff→Staff`, `Media Type→Media Types`, `Leaf→Leaves`, `Hero→Heroes`, `Photo→Photos`. Only the last word inflects; casing of the input is kept.

`LabelsTest`:
- `testPostTypeLabelsMatchRecon`: `postType('Resource', 'Resources')` has exactly: `name` `Resources`, `singular_name` `Resource`, `menu_name` `Resources`, `name_admin_bar` `Resource`, `add_new` `Add New`, `add_new_item` `Add New Resource`, `new_item` `New Resource`, `edit_item` `Edit Resource`, `view_item` `View Resource`, `view_items` `View Resources`, `all_items` `All Resources`, `search_items` `Search Resources`, `not_found` `No resources found.`, `not_found_in_trash` `No resources found in Trash.`, `archives` `Resource Archives`, `attributes` `Resource Attributes`, `parent_item_colon` `Parent Resource:`, `featured_image` `Featured image`, `set_featured_image` `Set featured image`, `remove_featured_image` `Remove featured image`, `use_featured_image` `Use as featured image`, `insert_into_item` `Insert into resource`, `uploaded_to_this_item` `Uploaded to this resource`, `filter_items_list` `Filter resources list`, `items_list_navigation` `Resources list navigation`, `items_list` `Resources list`, `item_published` `Resource published.`, `item_updated` `Resource updated.`, `item_link` `Resource Link`.
- `testTaxonomyLabels`: `taxonomy('Resource Type', 'Resource Types')` has `name`, `singular_name`, `search_items` `Search Resource Types`, `all_items` `All Resource Types`, `edit_item` `Edit Resource Type`, `view_item` `View Resource Type`, `update_item` `Update Resource Type`, `add_new_item` `Add New Resource Type`, `new_item_name` `New Resource Type`, `parent_item` `Parent Resource Type`, `not_found` `No resource types found.`, `menu_name` `Resource Types`, `back_to_items` `← Go to Resource Types`.
- `testLowercaseVariantKeepsAcronyms`: `postType('FAQ', 'FAQs')['not_found'] === 'No FAQs found.'` (a word that is all caps stays as is).

`SlugTest`:
- `fromName('Product', 20) === 'product'`; `fromName('Case Study', 20) === 'case-study'`; `fromName('A Very Long Post Type Name', 20) === 'a-very-long-post-typ'`.
- `error('product', 'type') === null`; `error('Product', 'type') === 'pattern'`; `error('1x', 'type') === 'pattern'`; `error(str_repeat('a', 21), 'type') === 'length'`; `error('author', 'type') === 'reserved'`; `error('wp_block', 'type') === 'reserved'`; `error('post', 'type') === null`; `error('category', 'taxonomy') === null`; `error('post_format', 'taxonomy') === 'reserved'`; `error('oit_resource', 'type') === null`.

- [ ] **Step 2: Run to verify failure** — `vendor/bin/phpunit tests/php/ContentModel/InflectorTest.php tests/php/ContentModel/LabelsTest.php tests/php/ContentModel/SlugTest.php` → FAIL.

- [ ] **Step 3: Implement the three classes**

`Inflector`: uncountables set, irregulars map, then suffix rules in order (`(?<![aeiou])y$`→`ies`, `(s|x|z|ch|sh)$`→`es`, `(?<!f)fe?$` for `leaf/knife`-style words limited to an explicit list `leaf, knife, life, wife, half, shelf, wolf, calf, loaf, thief`, `o$` → `es` only for `hero, potato, tomato, echo, veto`, else `s`). Every label goes through `__()` with `sprintf` placeholders (`/* translators: %s: singular name */`).

- [ ] **Step 4: Run to verify pass** → PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/ContentModel tests/php/ContentModel
git commit -m "feat(content-model): pluralise names and generate post type and taxonomy labels"
```

---

### Task 3: Menu icon rules and the server SVG sanitiser

**Parallel group:** A
**Depends on:** nothing

**Files:**
- Create: `includes/Security/SvgSanitizer.php`, `includes/ContentModel/MenuIcon.php`
- Test: `tests/php/Security/SvgSanitizerTest.php`, `tests/php/ContentModel/MenuIconTest.php`, fixtures `tests/php/fixtures/content-model/icons/{clean.svg,hostile.svg,photo.png}`

**Interfaces:**
- Produces:
  ```php
  final class SvgSanitizer {
      public const MAX_BYTES = 32768;
      public static function sanitize(string $svg): ?string;   // null when not parseable as a single <svg> root or too large
  }
  final class MenuIcon {
      public const DEFAULT_ICON = 'data:image/svg+xml;base64,...';   // base64 of the same glyph as $icon_svg in AdminPage::addMenuPage(), fill="black"
      /** @return array{icon: string, warning: ?string} */
      public static function resolve(string $value, string $themeDir, string $themeUrl): array;
  }
  ```
  Rules, in order: `''` → default, no warning; `^dashicons-[a-z0-9-]+$` → as is; `^https://` → `esc_url_raw` result; a relative path ending `.svg` → realpath must stay under `realpath($themeDir)` and size ≤ `MAX_BYTES`, then `'data:image/svg+xml;base64,' . base64_encode(SvgSanitizer::sanitize(...))`; `.png`, `.jpg`, `.jpeg`, `.webp` → confined the same way, then `rtrim($themeUrl, '/') . '/' . $relative`; anything else (`http://`, `data:`, `../x.svg`, missing file) → default with warning code `icon_invalid`.

- [ ] **Step 1: Write the failing tests**

`SvgSanitizerTest` (same rules as `src/editor/utils/sanitize-svg.ts`):
- `testScriptAndForeignObjectRemovedWithContent`: output contains neither `<script` nor `foreignObject` nor `alert(`.
- `testEventHandlersDropped`: `<svg onload="x()"><path onclick="y()" d="M0 0"/></svg>` → no `onload`, no `onclick`, `d="M0 0"` kept.
- `testUnsafeHrefDropped`: `<use href="javascript:x"/>` and `<use xlink:href="data:text/html,..."/>` lose the attribute; `<use href="#a"/>` keeps it.
- `testSmilRetargetingDropped`: `<animate attributeName="href" .../>` removed.
- `testUnsafeStyleDropped`: `style="background:url(javascript:x)"` removed.
- `testOversizeReturnsNull`: a 40 KB string → `null`; a non-SVG root (`<html>`) → `null`.
- `testDoctypeAndEntitiesRejected`: input with `<!DOCTYPE` or `<!ENTITY` → `null` (no XXE).

`MenuIconTest` (theme dir = a temp copy of the fixtures folder, URL `https://example.test/wp-content/themes/t`):
- `dashicons-portfolio` → same string, warning null.
- `https://cdn.test/i.png` → passes through.
- `icons/clean.svg` → starts with `data:image/svg+xml;base64,` and decodes to markup containing `<path`.
- `icons/hostile.svg` → decoded data URI contains no `<script` and no `onload`.
- `icons/photo.png` → `https://example.test/wp-content/themes/t/icons/photo.png`.
- `../outside.svg`, `http://x.test/i.png`, `icons/missing.svg`, `data:image/svg+xml;base64,AAA` → `DEFAULT_ICON` and warning `icon_invalid`.
- a symlink `icons/link.svg` pointing to a file outside the theme dir → default and `icon_invalid`.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement**

`SvgSanitizer` loads with `DOMDocument::loadXML($svg, LIBXML_NONET)` after rejecting `<!DOCTYPE`/`<!ENTITY`, walks the tree with the same `DROP_ELEMENTS`, `ANIMATION_ELEMENTS`, `on*`, URL-attribute and `UNSAFE_STYLE` rules as the TS file, and serialises the root with `saveXML($root)`. A DOM allowlist rather than `wp_kses`, because kses does not understand SVG namespaces; the rule set is the one the client already ships.

- [ ] **Step 4: Run to verify pass** → PASS (the existing `tests/php/Security/*` still pass).

- [ ] **Step 5: Commit**

```bash
git add includes/Security/SvgSanitizer.php includes/ContentModel/MenuIcon.php tests/php
git commit -m "feat(content-model): menu icon rules with a server-side SVG sanitiser"
```

---

### Task 4: Declarations — file loader, PHP declarations and the registry

**Parallel group:** A
**Depends on:** Task 1, Task 2

**Files:**
- Create: `includes/ContentModel/{Issue,Declaration,SchemaPaths,FileLoader,PhpDeclarations,Registry}.php`, `includes/content-model.php`, `tests/php/ContentModel/stubs/theme.php`
- Modify: `proto-blocks.php` (one `require_once PROTO_BLOCKS_DIR . 'includes/content-model.php';` after the helpers block)
- Test: `tests/php/ContentModel/{FileLoaderTest,PhpDeclarationsTest,RegistryTest,SchemaPathsTest}.php`, fixture trees `tests/php/fixtures/content-model/themes/{parent,child}/...`

**Interfaces:**
- Consumes: `Jsonc::decode`, `SchemaCheck::validate`, `Slug::error`, `Slug::fromName`.
- Produces:
  ```php
  final class Issue {
      public function __construct(public readonly string $level /* 'error'|'warning' */, public readonly string $code, public readonly string $message, public readonly string $source, public readonly ?string $slug = null, public readonly ?string $kind = null);
      public function toArray(): array;
  }
  final class Declaration {
      public const TYPE = 'type'; public const TAXONOMY = 'taxonomy';
      public function __construct(
          public readonly string $kind, public readonly string $slug, public readonly array $data,
          public readonly string $origin,        // 'file' | 'php'
          public readonly string $source,        // absolute file path, or "<file>:<line>" of the PHP call
          public readonly ?string $root,         // schema root the file was found in; null for PHP
          public readonly ?string $parentType = null   // set for a taxonomy declared inline in a type file
      );
      public function name(): ?string;     // data['name'] ?? null
  }
  final class SchemaPaths {
      /** @return list<string> realpaths, parent theme first, child next, then filter additions */
      public static function roots(): array;           // filter 'proto_blocks_schema_paths' receives and returns the list
      /** @return list<string> roots the UI may write to: stylesheet and template dirs only */
      public static function writableRoots(): array;
  }
  final class FileLoader {
      public const MAX_BYTES = 262144;
      public const FOLDERS = ['type' => 'proto-types', 'taxonomy' => 'proto-taxonomies'];
      public const BASENAMES = ['type' => 'type', 'taxonomy' => 'taxonomy'];
      /** @return array{declarations: array<string, array<string, Declaration>>, issues: list<Issue>} keyed kind => slug */
      public function load(array $roots): array;
      public static function fileFor(string $dir, string $kind): ?string;   // .jsonc, else .json, else null
  }
  final class PhpDeclarations {
      public static function add(string $kind, string $name, array $args, string $source): void;
      /** @return array<string, array<string, Declaration>> */ public static function all(): array;
      /** @return list<Issue> */ public static function issues(): array;
      public static function seal(): void;    // called by PostTypes::register(); later add() calls record warning `declared_too_late` and are ignored
      public static function reset(): void;   // tests only (also unseals)
  }
  final class Registry {
      public function __construct(FileLoader $loader, \Closure $roots /* fn(): list<string> */);
      /** @return array<string, Declaration> */ public function postTypes(): array;
      /** @return array<string, Declaration> */ public function taxonomies(): array;   // standalone + inline
      public function get(string $kind, string $slug): ?Declaration;
      /** @return list<array{kind: string, slug: string, file: string, php: string}> */ public function doubles(): array;
      /** @return list<Issue> */ public function issues(): array;
      public function invalidate(): void;
  }
  ```
  Global functions in `includes/content-model.php` (guarded by `function_exists`):
  ```php
  function proto_post_type(string $name, array $args = []): void;   // PhpDeclarations::add('type', ...), source from debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)
  function proto_taxonomy(string $name, array $args = []): void;
  ```
  Rules fixed here:
  - A file's slug is its folder name. A `slug` key in the file must equal it (error `slug_mismatch`). A PHP declaration's slug is `$args['slug'] ?? Slug::fromName($name, max)`.
  - Invalid JSONC (`invalid_jsonc`, message with line/column), schema errors (`schema_invalid`, one Issue per error path), oversize (`file_too_large`), `Slug::error` (`slug_<pattern|length|reserved>`): the declaration is skipped, other files load.
  - Only `type.jsonc`/`type.json` (resp. `taxonomy.*`) are read; any other file in the folder is ignored. Symlinked declaration files whose realpath leaves the root are skipped (`path_outside_root`).
  - Roots are processed in order; a later root replaces an earlier one's declaration of the same slug with no warning (child theme wins).
  - File beats PHP for the same kind and slug, entirely (no merge); recorded in `doubles()` and as a warning `double_declaration` whose message names both locations.
  - A second PHP call for the same slug replaces the first; warning `duplicate_php`.
  - Roots are realpaths, so a theme directory that is itself a symlink resolves to its target and is treated as that root; confinement compares realpaths against these.
  - The size check uses `filesize()` before any read: a file over `MAX_BYTES` is never opened.
  - Inline taxonomies: object entries of a type's `taxonomies` become taxonomy Declarations with `parentType` = the type slug, `origin` and `source` of the type file, `data['post_types']` pre-filled with the type slug (merged with any listed). A standalone declaration (file or PHP) of the same taxonomy slug wins over the inline one, with warning `double_declaration`.
  - `Registry` memoises the merged result for the request; `invalidate()` clears it (the writer calls it).

- [ ] **Step 1: Write the failing tests**

`stubs/theme.php`: guarded `get_template_directory()`, `get_stylesheet_directory()`, `get_stylesheet_directory_uri()`, `get_template_directory_uri()` reading `$GLOBALS['pb_test_theme']`, `apply_filters` with the shared shape (Global Constraints), `wp_is_block_theme()` reading `$GLOBALS['pb_test_block_theme'] ?? true`.

`FileLoaderTest` (fixture trees copied to a temp dir per test):
- `testLoadsJsoncAndJson`: `parent/proto-types/product/type.jsonc` and `parent/proto-taxonomies/genre/taxonomy.json` both load; slugs `product`, `genre`; origin `file`.
- `testJsoncWinsOverJsonWithWarning`: both extensions present → `.jsonc` data used, warning `both_extensions`.
- `testInvalidFileIsSkippedOthersLoad`: `broken/type.jsonc` with a missing brace → error `invalid_jsonc` mentioning `line`; `product` still loads.
- `testPhpFileInFolderIsIgnored`: a `type.php` beside `type.jsonc` is never read (assert with a `type.php` that would throw if included, and that `get_included_files()` does not contain it).
- `testOversizeFileIsRejected`: 300 KB file → `file_too_large`, not decoded.
- `testTenMegabyteFileRejectedUnread`: a 10 MB file made with `ftruncate` → `file_too_large`; `memory_get_peak_usage()` grows by less than 2 MB across `load()`.
- `testProtoKeyFileSkipped`: a file with a `"__proto__"` key → error `invalid_jsonc`; other files load.
- `testFolderSlugMismatch`: `proto-types/product/type.jsonc` with `"slug": "item"` → `slug_mismatch`.
- `testReservedSlugIsError`: `proto-types/author/type.jsonc` → error `slug_reserved`, skipped; `proto-types/post/type.jsonc` loads (override).
- `testChildRootReplacesParent`: same slug in parent and child → child data, no warning.
- `testSymlinkedFileOutsideRootSkipped`: `proto-types/evil/type.jsonc` symlinked to a file outside the root → skipped with `path_outside_root`.

`PhpDeclarationsTest`:
- `proto_post_type('Case Study')` → slug `case-study`, data `['name' => 'Case Study']`, source ends with `PhpDeclarationsTest.php:<line>`.
- `proto_post_type('Resource', ['slug' => 'oit_resource'])` → slug `oit_resource`.
- calling twice with `Product` → second wins, warning `duplicate_php`.
- `proto_post_type('Author')` → error `slug_reserved`, not stored.
- after `PhpDeclarations::seal()`, `proto_post_type('Late')` → not stored, warning `declared_too_late` whose message names the call site.

`RegistryTest`:
- `testFileWinsOverPhpAndIsReportedDouble`: PHP `product` with `has_archive => false`, file `product` without it → `get('type','product')->origin === 'file'`, data has no `has_archive`; `doubles()` has one entry naming the file path and the PHP `file:line`.
- `testInlineTaxonomyGetsParentType`: type file with `"taxonomies": [{"name": "Genre"}]` → taxonomy `genre` with `parentType === 'book'` and `post_types === ['book']`.
- `testStandaloneTaxonomyBeatsInline` with warning `double_declaration`.
- `testInvalidateReloads`: change the file on disk, `invalidate()`, `get()` sees the new data.

`SchemaPathsTest`: parent and child set → `[parent, child]`; same dir → one entry; a filter adding `/extra` → appended; `writableRoots()` never contains `/extra`; `testSymlinkedThemeRootResolved`: a stylesheet dir that is a symlink → `roots()` and `writableRoots()` contain its realpath.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement the classes and `includes/content-model.php`; add the `require_once` to `proto-blocks.php`**

The global functions must be available before the theme's `functions.php` loads (plugins load first), so the file is required at file scope of `proto-blocks.php`, not from `boot()`.

- [ ] **Step 4: Run to verify pass** → `vendor/bin/phpunit tests/php/ContentModel` PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/ContentModel includes/content-model.php proto-blocks.php tests/php
git commit -m "feat(content-model): discover JSONC declarations and proto_post_type/proto_taxonomy"
```

---

### Task 5: Args builders, overrides and the parity test (done criterion)

**Parallel group:** A
**Depends on:** Task 2, Task 3, Task 4

**Files:**
- Create: `includes/ContentModel/PostTypeArgs.php`, `includes/ContentModel/TaxonomyArgs.php`
- Test: `tests/php/ContentModel/PostTypeArgsTest.php`, `tests/php/ContentModel/TaxonomyArgsTest.php`, `tests/php/ContentModel/ParityTest.php`, `tests/php/ContentModel/Support/WpObjectProps.php`, fixtures `tests/php/fixtures/content-model/recon/{cadco-resource.php,oit-resource.php,cadco-taxonomies.php}`

**Interfaces:**
- Consumes: `Declaration`, `Labels`, `Inflector`, `MenuIcon::resolve`, `Slug`.
- Produces:
  ```php
  final class PostTypeArgs {
      public const PLUGIN_KEYS = ['name', 'plural', 'slug', 'taxonomies', 'fields', '$schema'];
      /** Full args for a type Proto-Blocks registers. @return array{args: array, issues: list<Issue>} */
      public static function build(Declaration $d, string $themeDir, string $themeUrl): array;
      /** Declared keys merged onto someone else's args (override file). @return array{args: array, issues: list<Issue>} */
      public static function override(array $existing, Declaration $d, string $themeDir, string $themeUrl): array;
  }
  final class TaxonomyArgs {
      public const PLUGIN_KEYS = ['name', 'plural', 'slug', 'post_types', 'fields', '$schema'];
      /** @return array{objectTypes: list<string>, args: array, issues: list<Issue>} */
      public static function build(Declaration $d): array;
      public static function override(array $existing, Declaration $d): array;
  }
  ```
  `build` defaults (spec section 2): `labels` = `Labels::postType(name, plural ?? Inflector::plural(name))` with declared `labels` merged over; `public` true; `show_in_rest` true; `has_archive` true; `rewrite` `['slug' => <slug>, 'with_front' => false]` with a declared array merged over it (so `['slug' => 'resources']` keeps `with_front false`) and a declared `false` replacing it; `supports` `['title', 'editor', 'thumbnail', 'excerpt', 'revisions']` replaced wholesale by a declared list; `menu_position` 22; `menu_icon` from `MenuIcon::resolve` (default icon when absent); `hierarchical` false; `capability_type` `'post'`; `taxonomies` = the string entries of the declared `taxonomies`; every other declared key passed through unchanged; `PLUGIN_KEYS` never reach WordPress. Taxonomy defaults: `labels` from `Labels::taxonomy` (`menu_name` = plural) merged; `public`, `show_in_rest`, `show_admin_column` true; `hierarchical` false; `rewrite` `['slug' => <slug>, 'with_front' => false]` merged the same way; `objectTypes` = `post_types` (inline ones already pre-filled). `override` applies only declared keys: `labels` merged over existing labels (array or object cast), `rewrite` merged when both arrays, everything else replaced, `PLUGIN_KEYS` stripped, `menu_icon` resolved.

- [ ] **Step 1: Write the recon fixtures**

Each fixture returns `['slug' => ..., 'args' => [...]]` transcribing the developer's live registrations (from the recon of `cadco-theme/inc/cadco-resources.php` and `optimizedit/inc/oit-resources-cpt.php`), labels as plain strings:
- `cadco-resource.php`: slug `resource`; labels `name` Resources, `singular_name` Resource, `add_new_item` Add New Resource, `edit_item` Edit Resource, `search_items` Search Resources, `not_found` No resources found, `all_items` All Resources; `public` true, `show_in_rest` true, `menu_icon` `dashicons-media-document`, `menu_position` 22, `has_archive` false, `rewrite` `['slug' => 'resources', 'with_front' => false]`, `supports` `['title','editor','excerpt','thumbnail','revisions']`.
- `oit-resource.php`: slug `oit_resource`; 20 labels: `name`, `singular_name`, `menu_name`, `name_admin_bar`, `add_new`, `add_new_item`, `new_item`, `edit_item`, `view_item`, `view_items`, `all_items`, `search_items`, `not_found`, `not_found_in_trash`, `archives`, `attributes` with exactly the Task 2 generated values, plus `featured_image` Cover Image, `set_featured_image` Set cover image, `remove_featured_image` Remove cover image, `use_featured_image` Use as cover image; `public`, `publicly_queryable`, `show_ui`, `show_in_menu`, `show_in_nav_menus`, `show_in_admin_bar`, `show_in_rest` true; `menu_position` 22; `menu_icon` `dashicons-portfolio`; `capability_type` post; `hierarchical` false; `has_archive` `resources`; `rewrite` `['slug' => 'resources', 'with_front' => false]`; `query_var` true; `supports` the five defaults plus `custom-fields`; `template` the 10-block list (`proto-blocks/oit-breadcrumbs-eyebrow-title`, `proto-blocks/oit-video-preview`, `core/spacer {height 48px}`, `proto-blocks/oit-text-image`, spacer 48px, `proto-blocks/oit-form`, spacer 48px, `proto-blocks/oit-related-resources`, spacer 80px, `proto-blocks/oit-link-cards`); `template_lock` false.
- `cadco-taxonomies.php`: `resource_media` (Media Types / Media Type / All Media Types; public, show_in_rest, hierarchical true, show_admin_column, rewrite `media-type` with_front false) and `resource_product` (Products / Product / All Products; hierarchical false; rewrite `resource-product`), both on `['resource']`.

- [ ] **Step 2: Write the failing tests**

`Support/WpObjectProps::postType(array $args): array` and `::taxonomy(array $args): array` reproduce the property defaults `WP_Post_Type::set_props()` / `WP_Taxonomy::set_props()` derive (`publicly_queryable`, `show_ui`, `show_in_menu`, `show_in_nav_menus`, `show_in_admin_bar` and `exclude_from_search` from `public`; `query_var` true; `capability_type` post; `map_meta_cap` true; `hierarchical` false; `show_admin_column` false; `show_in_quick_edit` = `show_ui`; missing `taxonomies` = `[]`), drop `labels`, and sort `supports`. Test helper only; it does not ship.

`ParityTest` (the spec's done criterion):
- `testCadcoResourceOneLine`: `PostTypeArgs::build()` of the PHP declaration
  `proto_post_type('Resource', ['has_archive' => false, 'menu_icon' => 'dashicons-media-document', 'rewrite' => ['slug' => 'resources']]);`
  → `WpObjectProps::postType($built)` equals `WpObjectProps::postType($fixture['args'])`; the declared slug equals `resource`; and for every label key in the fixture, the built label equals the fixture's after `rtrim($label, '.')` on both sides (the only accepted difference is terminal punctuation, CADCO's `No resources found` vs the generated `No resources found.`).
- `testOitResourceOneLine`: `proto_post_type('Resource', ['slug' => 'oit_resource', 'has_archive' => 'resources', 'rewrite' => ['slug' => 'resources'], 'menu_icon' => 'dashicons-portfolio', 'supports' => [...6], 'template' => OIT_TEMPLATE, 'template_lock' => false, 'labels' => [the four cover-image labels]])` → same three assertions against `oit-resource.php`.
- `testCadcoTaxonomies`: `proto_taxonomy('Media Type', ['slug' => 'resource_media', 'post_types' => ['resource'], 'hierarchical' => true, 'rewrite' => ['slug' => 'media-type']])` and `proto_taxonomy('Product', ['slug' => 'resource_product', 'post_types' => ['resource'], 'rewrite' => ['slug' => 'resource-product']])` match `cadco-taxonomies.php` the same way (object types too).

`PostTypeArgsTest`:
- `testBareDeclarationDefaults`: `build(Product)` → `public` true, `show_in_rest` true, `has_archive` true, `rewrite` `['slug' => 'product', 'with_front' => false]`, `supports` the five defaults, `menu_position` 22, `menu_icon === MenuIcon::DEFAULT_ICON`, `hierarchical` false, `capability_type` post, no key from `PLUGIN_KEYS`.
- `testPluralOverride`: `{name: 'Person', plural: 'Persons'}` → `labels.name === 'Persons'`, `labels.not_found === 'No persons found.'`; without `plural` → `People`.
- `testHasArchiveStringKept`: `has_archive: 'resources'` → `'resources'`; `false` → `false`.
- `testDeclaredLabelsMergeOverGenerated`: `labels: {menu_name: 'Library'}` → `menu_name` Library, `add_new_item` still `Add New Product`.
- `testRewriteFalseReplaces`; `testSupportsReplacedNotMerged` (`['title']` → `['title']`); `testUnknownKeysPassThrough` (`delete_with_user` kept); `testStringTaxonomiesPassed` (`['category', {name: 'Genre'}]` → `taxonomies === ['category']`).
- `testOverrideOnlyDeclaredKeys`: `override(['public' => true, 'labels' => ['name' => 'Posts'], 'supports' => ['title']], post-declaration{template: [[...]], labels: {menu_name: 'Blog'}})` → `public` unchanged, `labels` has both `name` and `menu_name`, `template` set, `supports` unchanged.
- `testInvalidIconFallsBackWithIssue`: `menu_icon` `http://x/i.png` → default icon and an Issue `icon_invalid`.

`TaxonomyArgsTest`: bare `Genre` defaults (`show_admin_column` true, `hierarchical` false, `menu_name` Genres, `rewrite` with_front false); inline declaration keeps `objectTypes === ['book']`.

- [ ] **Step 3: Run to verify failure** — `vendor/bin/phpunit tests/php/ContentModel/ParityTest.php` → FAIL.

- [ ] **Step 4: Implement `PostTypeArgs` and `TaxonomyArgs`**

- [ ] **Step 5: Run to verify pass** → `vendor/bin/phpunit tests/php/ContentModel` PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/ContentModel tests/php
git commit -m "feat(content-model): post type and taxonomy defaults with a parity test against two live sites"
```

---

### Task 6: Modules layer, registration, overrides, rewrite flush, notices

**Parallel group:** A
**Depends on:** Task 4, Task 5

**Files:**
- Create: `includes/Modules/{ModuleInterface,Manager,PostTypes,Taxonomies}.php`, `includes/ContentModel/{RewriteHash,OriginTracker,DoubleDeclarationNotice}.php`, `tests/php/ContentModel/stubs/hooks.php`
- Modify: `includes/Core/Plugin.php` (in `initializeServices()`: `$this->services['modules'] = new \ProtoBlocks\Modules\Manager($this->getContentModel()); add_action('after_setup_theme', [$this->services['modules'], 'boot'], 1);` plus getters `getContentModel(): Registry` (lazy, `new Registry(new FileLoader(), fn() => SchemaPaths::roots())`) and `getModules(): Manager`). `Plugin::boot()` runs on `plugins_loaded`, before the theme's `functions.php`, so booting there would ignore a theme's `proto_blocks_modules` filter; `after_setup_theme` 1 is still before core's `init` 0 re-registration of built-in types.
- Test: `tests/php/Modules/{ManagerTest,PostTypesTest,TaxonomiesTest}.php`, `tests/php/ContentModel/{RewriteHashTest,OriginTrackerTest,DoubleDeclarationNoticeTest}.php`

**Interfaces:**
- Consumes: `Registry`, `PostTypeArgs`, `TaxonomyArgs`, `SchemaPaths`.
- Produces:
  ```php
  interface ModuleInterface { public function id(): string; public function boot(): void; }
  final class Manager {
      public const DEFAULTS = ['post_types' => true, 'taxonomies' => true];   // Phase 2/3 add 'fields', 'options_pages'
      public function __construct(Registry $registry);
      public function boot(): void;                         // applies filter 'proto_blocks_modules' (array id => bool), boots enabled modules, hooks OriginTracker and the notice
      public function isEnabled(string $id): bool;
      /** @return array<string, bool> */ public function states(): array;
      public function postTypes(): ?PostTypes;              // null when disabled
      public function taxonomies(): ?Taxonomies;
  }
  final class PostTypes implements ModuleInterface {
      public const LATE_KEYS = ['labels', 'template', 'template_lock', 'menu_icon', 'menu_position', 'supports', 'description', 'show_in_admin_bar', 'show_in_nav_menus'];
      public function __construct(Registry $registry);
      public function id(): string;                         // 'post_types'
      public function boot(): void;                         // add_filter register_post_type_args (10, 2); add_action init 10 register; add_action wp_loaded maybeFlush
      public function register(): void;
      public function filterArgs(array $args, string $slug): array;
      /** @return list<string> slugs this module registered itself */ public function registered(): array;
      /** @return list<string> slugs applied as overrides */ public function overridden(): array;
      /** @return list<Issue> */ public function issues(): array;
  }
  final class Taxonomies implements ModuleInterface { /* same shape: 'taxonomies', register_taxonomy_args, register(), filterArgs(), registered(), overridden(), issues() */ }
  final class RewriteHash {
      public const OPTION = 'proto_blocks_rewrite_hash';
      public static function compute(array $postTypes, array $taxonomies): string;   // slug => args maps; sha1 over ksorted slug, rewrite, has_archive, public, query_var, hierarchical
      public static function maybeFlush(string $hash, string $themeKey): bool;       // flush_rewrite_rules(false) + update_option when stored hash differs
  }
  final class OriginTracker {
      public function boot(): void;                         // hooks registered_post_type, registered_taxonomy (PHP_INT_MAX)
      /** @return array{type: 'core'|'plugin'|'theme'|'proto'|'unknown', name: ?string} */
      public static function classify(string $file, string $pluginDir, string $muPluginDir, array $themeDirs, string $protoDir): array;
      public function originOf(string $kind, string $slug): array;   // _builtin objects report 'core'
  }
  final class DoubleDeclarationNotice {
      public function __construct(Registry $registry);
      public function render(): void;                       // admin_notices; only when get_current_screen()->id contains 'proto-blocks' and current_user_can('manage_options')
  }
  ```
  Behaviour fixed here:
  - `register()` runs on `init` priority 10 and first calls `PhpDeclarations::seal()`. For each resolved type declaration: if the slug is already registered (`post_type_exists`) or is in `Slug::OVERRIDABLE_TYPES`, it is an **override** (do not register); else it is registered with `PostTypeArgs::build()`. A declaration without `name` is not registered; it stays a pending override (a plugin may register that slug later on `init`, and `filterArgs` then applies it), and only if the slug is still unregistered at `wp_loaded` does it become error `name_required`.
  - Icon base passed to the args builders: the declaration's `root` with its theme URI when the root is the stylesheet or template directory; PHP declarations use the stylesheet directory and URI; a filter root has no URL, so a raster icon there falls back with `icon_invalid` (SVGs are inlined and work). The module sets `$this->registering = true` around its own `register_post_type` call so `filterArgs` leaves its own registrations alone.
  - `filterArgs()` (attached at boot, so it applies to core's `init` 0 re-registration and to plugins registering on `init` 5) returns `PostTypeArgs::override($args, $decl, ...)` when a declaration exists for `$slug` and the call is not the module's own.
  - Overrides for a type already registered before the filter could act (a PHP override declared after `init` 0 for a core type): at `register()` time, apply `LATE_KEYS` directly to the `WP_Post_Type` object (labels merged then `get_post_type_labels()` re-run; `supports` through `add_post_type_support`/`remove_post_type_support`) and add warning `late_override_ignored` naming any other declared key. This is how `proto-types/post/type.jsonc` with a `template` reproduces OptimizedIT's `inc/oit-post-template.php`.
  - Taxonomies register in `init` 10 too (after post types, same priority, booted second); overrides through `register_taxonomy_args`; late override keys `labels`, `show_admin_column`, `show_in_quick_edit`, `description`; object types added with `register_taxonomy_for_object_type`.
  - `maybeFlush` runs on `wp_loaded`, keyed by `get_stylesheet()`, over the args this request registered or overrode. Nothing is flushed when the stored hash matches.
  - The admin notice text: `"%1$s" is declared twice. The file %2$s is used; the PHP declaration at %3$s is ignored. Delete the PHP line when ready.`
  - Hooks that render admin notices or read `get_current_screen()` are added only when `is_admin()`. `OriginTracker` hooks only when `is_admin()`, `WP_CLI`, or the request URI contains `/proto-blocks/v1/` (its backtrace is not paid on front-end requests).

- [ ] **Step 1: Write the failing tests**

`stubs/hooks.php` (guarded): `add_action`, `add_filter` recording into `$GLOBALS['pb_test_hooks']`; `register_post_type`, `register_taxonomy` recording args into `$GLOBALS['pb_test_registered']` and returning a `WP_Post_Type`-like stdClass; `post_type_exists`, `taxonomy_exists`, `get_post_type_object`, `get_post_type_labels` (returns `(object) $labels`), `add_post_type_support`, `remove_post_type_support`, `register_taxonomy_for_object_type`, `update_option` (into `$GLOBALS['pb_test_options']`), `flush_rewrite_rules` (increments `$GLOBALS['pb_test_flushes']`), `get_stylesheet`, `is_admin`.

`ManagerTest`: defaults boot both modules; a filter returning `['post_types' => false]` → `postTypes() === null`, no `register_post_type_args` hook added, `states()['post_types'] === false`, `taxonomies()` still booted; `testPluginBootsManagerAfterSetupTheme`: reading `includes/Core/Plugin.php` source, the manager's `boot` is hooked to `after_setup_theme` (string assertion on `'after_setup_theme', [$this->services['modules'], 'boot'], 1`).

`PostTypesTest`:
- `testRegistersDeclaredTypeOnInit10`: `boot()` adds `init` at priority 10; `register()` records `product` with `has_archive` true.
- `testExistingSlugBecomesOverride`: `$GLOBALS` marks `product` as registered by WooCommerce → `register()` does not call `register_post_type`; `filterArgs(['public' => true], 'product')` with a declaration `{menu_position: 56}` returns `menu_position` 56 and `public` true.
- `testOwnRegistrationNotDoubleFiltered`: during `register()`, `filterArgs` returns its input unchanged.
- `testBuiltInPostTemplateOverride`: declaration `post` with `template` → `overridden()` contains `post`; when `post` already exists as an object, `register()` sets `->template` and `->template_lock` on it.
- `testLateOverrideWarnsForUnappliedKeys`: late override with `rewrite` → issue `late_override_ignored` mentioning `rewrite`.
- `testMissingNameSkipped`: file declaration `widget` without `name` → not registered at `init`; no issue yet; after the `wp_loaded` callback with `widget` still unregistered → issue `name_required`; if `post_type_exists('widget')` is true by then → no issue and `overridden()` contains `widget`.
- `testRegisterSealsPhpDeclarations`: after `register()`, `proto_post_type('Late')` yields warning `declared_too_late`.

`TaxonomiesTest`: inline taxonomy registered with object types `['book']`; `category` declaration with `labels.menu_name` is an override; standalone taxonomy naming an external type `product` registers with `['product']`.

`RewriteHashTest`: hash stable under key order; changes when a rewrite slug changes; `maybeFlush` flushes once and not on the second call with the same hash; keyed per theme (`themeA` stored, `themeB` flushes).

`DoubleDeclarationNoticeTest`: with a Registry holding one double (`product`: file `/t/proto-types/product/type.jsonc`, PHP `/t/functions.php:12`), screen id `toplevel_page_proto-blocks` and `manage_options` → output contains `"product" is declared twice`, `proto-types/product/type.jsonc` and `functions.php:12`; screen id `edit-post` → no output; a user without `manage_options` → no output; no doubles → no output. (`stubs/hooks.php` adds `get_current_screen()` reading `$GLOBALS['pb_test_screen']` and a guarded `current_user_can()` with the same shape as Phase 0's `tests/php/Admin/wp-stubs.php`: `$GLOBALS['pb_test_caps'][get_current_user_id()]`.)

`OriginTrackerTest::classify`: `/wp/wp-content/plugins/woocommerce/includes/class-wc-post-types.php` → `['type' => 'plugin', 'name' => 'woocommerce']`; a theme file → `theme` with the theme folder name; a file under the plugin's own dir → `proto`; `/wp/wp-includes/post.php` → `core`.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement the modules, helpers and the `Plugin.php` wiring**

`OriginTracker` takes the first backtrace frame outside `wp-includes/class-wp-hook.php`, `wp-includes/plugin.php` and `wp-includes/post.php`/`taxonomy.php`; `name` is the plugin or theme folder (the REST layer resolves display names).

- [ ] **Step 4: Run to verify pass** → `composer test` PASS.

- [ ] **Step 5: Manual check on a Local site**

Add `proto_post_type('Product');` to a test theme's `functions.php`: `Products` appears at menu position 22 with the default icon; `/product/<slug>/` resolves without visiting Permalinks; editing the declaration's rewrite slug flushes once.

- [ ] **Step 6: Commit**

```bash
git add includes/Modules includes/ContentModel includes/Core/Plugin.php tests/php
git commit -m "feat(modules): register declared post types and taxonomies with overrides and rewrite flush"
```

---

### Task 7: File writer, guardrails and the declaration template

**Parallel group:** A
**Depends on:** Task 1, Task 4

**Files:**
- Create: `includes/ContentModel/{SafeFile,SchemaFileWriter,WriteException,Guardrails,DeclarationTemplate}.php`
- Test: `tests/php/ContentModel/{SafeFileTest,SchemaFileWriterTest,GuardrailsTest,DeclarationTemplateTest}.php`

**Interfaces:**
- Consumes: `Jsonc`, `SchemaCheck`, `Slug`, `FileLoader::FOLDERS/BASENAMES/MAX_BYTES`, `Declaration`.
- Produces:
  ```php
  final class WriteException extends \RuntimeException {
      public function __construct(public readonly string $errorCode, public readonly int $status, string $message, public readonly array $data = []);
  }
  final class SafeFile {   // shared by SchemaFileWriter and TemplateScaffolder (Task 8)
      /** realpath of the deepest existing ancestor of $path starts with one of $roots . '/' and no existing segment below the root is a symlink */
      public static function isConfined(string $path, array $roots): bool;
      /** temp file in the same folder + rename(), re-checking is_link() on the target before the swap; removes the temp file on failure; throws WriteException('write_failed', 500) */
      public static function writeAtomic(string $target, string $content): void;
  }
  final class SchemaFileWriter {
      /** @param list<string> $writableRoots realpaths; the first is where new declarations are created */
      public function __construct(array $writableRoots, bool $fileModsAllowed);
      public static function hash(string $text): string;      // sha256 hex
      /** @return array{path: string, writable: bool, reason: ?string} reason 'file_mods'|'not_writable'|'outside_root' */
      public function target(string $kind, string $slug, ?Declaration $existing): array;
      /** @return array{path: string, hash: string, created: bool, data: array} */
      public function write(string $kind, string $slug, string $text, ?string $expectedHash, ?Declaration $existing): array;
      /** @return array{deleted: string, kept: list<string>} */
      public function delete(string $kind, string $slug, Declaration $existing): array;
  }
  final class Guardrails {
      public const GUARDED_SUPPORTS = ['title', 'editor', 'thumbnail'];
      public function __construct(\Closure $postCount /* fn(string): int */, \Closure $termCount /* fn(string): int */);
      /** @return list<array{code: 'supports_removed'|'taxonomy_removed'|'field_removed', message: string, count: int, detail: list<string>}> */
      public function checkSave(string $kind, string $slug, ?array $before, array $after): array;
      /** @return array{count: int, taxonomies: array<string, int>} */
      public function contentFor(string $kind, string $slug, ?array $data): array;
  }
  final class DeclarationTemplate {
      public static function render(string $kind, string $name, ?string $slug = null): string;   // commented JSONC
  }
  ```
  `write()` order (each failure a `WriteException` with the code and status shown): file mods disabled → `file_mods` 403; `Slug::error($slug, $kind)` → `invalid_slug` 400 (before any path is built, so `../x` never reaches the filesystem); `strlen($text) > MAX_BYTES` → `file_too_large` 413 (before decoding); `Jsonc::decode` fails → `invalid_jsonc` 400 with `data: {line, column}`; `SchemaCheck` errors → `schema_invalid` 400 with `data: {errors}`; a `slug` key ≠ `$slug` → `slug_mismatch` 400; existing file's current hash ≠ `$expectedHash` (or `$expectedHash` null while a file exists) → `conflict` 409 with `data: {hash}`; target resolution: an existing file in a writable root is edited in place, an existing file in a non-writable root (filter root) → `path_outside_root` 403, otherwise `<writableRoots[0]>/<folder>/<slug>/<basename>.jsonc`; `SafeFile::isConfined()` false → `path_outside_root` 403; not writable → `not_writable` 403. Then create the folder (`wp_mkdir_p`) and `SafeFile::writeAtomic()` (the `Template\Cache::writeAtomic` pattern). An `$existing` declaration of origin `php` is not a file to edit: the write creates the file in `writableRoots[0]` (`created` true), which is the designed double declaration.
  `delete()`: same confinement; removes the declaration file; removes the folder only when every remaining entry is a regular file with extension `json`, `jsonc`, `svg`, `png`, `jpg`, `jpeg`, `webp` (never a symlink, never a subfolder); otherwise keeps the folder and lists what it kept.
  `checkSave()`: `supports_removed` when `$before` supports (or the defaults when absent) contained a `GUARDED_SUPPORTS` member that `$after` lacks and `postCount($slug) > 0`; `taxonomy_removed` when an inline taxonomy object present in `$before` is absent in `$after` and `termCount(tax) > 0`; `field_removed` when a field `name` found in `$before['fields'][*]['fields'][*]` is absent from `$after` and the type has posts (message: stored values stay under the old meta key `<name>`; Phase 1 has no field editor, but the route is shared with Phase 2's builder and with hand-edited files). Taxonomy kind: never returns `supports_removed`.
  `DeclarationTemplate::render('type', 'Product')`: begins `// Proto-Blocks post type declaration.`, contains `"name": "Product"`, a commented-out example line for each of `plural`, `has_archive`, `menu_icon`, `supports`, `rewrite`, `taxonomies`, and `"fields": []`; the taxonomy template contains `"name"` and `"post_types": []`. Both decode with `Jsonc::decode` and validate clean.

- [ ] **Step 1: Write the failing tests** (temp dirs as roots; `fileModsAllowed` passed in)

`SchemaFileWriterTest`:
`SafeFileTest`: `isConfined('<root>/proto-types/x/type.jsonc', [root])` true for a not-yet-existing path; false when `<root>/proto-types` is a symlink to a dir outside; false for `<root>/../x`; `writeAtomic` leaves no `*.tmp*` file after success or after a failed rename.

- `testCreatesNewFileInFirstRoot`: `write('type', 'product', render('type','Product'), null, null)` → file at `<root>/proto-types/product/type.jsonc`, `created` true, `hash` equals `hash(text)`, `data['name'] === 'Product'`.
- `testEditsExistingFileInPlace` (existing in the second writable root, i.e. the parent theme) with the right hash → same path, `created` false.
- `testWriteRejectsStaleHash`: wrong `expectedHash` → `conflict`, file unchanged.
- `testWriteRejectsTraversalSlug`: `write('type', '../evil', ...)` and `write('type', 'a/b', ...)` → `invalid_slug` 400; no file or folder created anywhere under the temp parent.
- `testWriteRefusesSymlinkedKindFolder`: `<root>/proto-types` is a symlink to a dir outside the root → `path_outside_root`, the outside dir stays empty.
- `testWriteRefusesSymlinkedFolder`: `proto-types/product` is a symlink to a dir outside the root → `path_outside_root`, nothing written outside.
- `testSymlinkedThemeRootIsWritable`: the writer built with `realpath()` of a symlinked theme root writes into the link's target.
- `testWriteRefusesSymlinkedFile`: `type.jsonc` is a symlink → `path_outside_root`.
- `testWriteRefusesFilterRoot`: existing declaration whose `root` is not in `writableRoots` → `path_outside_root`.
- `testFileModsDisabled` → `file_mods` 403, nothing written; `testNotWritableRootRefused` (`chmod 0555` on the root; `markTestSkipped` when running as root) → `not_writable` 403; `testInvalidJsonc` → `invalid_jsonc` with `line`; `testSchemaInvalid` (`{"name": "X", "public": "yes"}`) → `schema_invalid` with `/public`; `testSlugMismatch`; `testOversize` (a 10 MB string of invalid JSONC → `file_too_large` 413, not `invalid_jsonc`, which proves the size check runs before decoding).
- `testEditingPhpDeclarationCreatesFile`: `$existing` of origin `php` → file created in the first root, `created` true.
- `testNoTempFilesLeftBehind` after a failed rename (make the target a directory).
- `testDeleteRemovesFolderWithKnownFiles` (`type.jsonc` + `icon.svg` → folder gone); `testDeleteKeepsFolderWithOtherFiles` (`notes.md` → declaration removed, `kept === ['notes.md']`); `testDeleteRefusesSymlinkedFolder`.

`GuardrailsTest`:
- `supports` `['title','editor']` → `['title']` with 3 posts → one `supports_removed` with `detail === ['editor']`, `count === 3`.
- same with 0 posts → `[]`.
- `$before` without `supports` (defaults) → `$after` `['title']` with posts → detail `['editor','thumbnail']`.
- removing inline taxonomy `genre` with 5 terms → `taxonomy_removed`, `detail === ['genre']`.
- a field group whose field `subtitle` is renamed to `strapline`, 2 posts → `field_removed`, `detail === ['subtitle']`, message contains `subtitle`.
- `contentFor('type', 'book', {taxonomies: [{name: 'Genre'}]})` → `['count' => n, 'taxonomies' => ['genre' => m]]`.

`DeclarationTemplateTest`: both templates decode, validate with `SchemaCheck` against their `$defs`, and contain the strings above; `render('type', 'Case Study')` has slug-free content (the folder carries the slug) unless `$slug` is passed, in which case `"slug": "<slug>"` appears.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement the four classes**

- [ ] **Step 4: Run to verify pass** → PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/ContentModel tests/php/ContentModel
git commit -m "feat(content-model): confined atomic declaration writes, content guardrails and file templates"
```

---

### Task 8: Template scaffolding

**Parallel group:** A
**Depends on:** Task 7 (`WriteException`, `SafeFile`)

**Files:**
- Create: `includes/ContentModel/TemplateScaffolder.php`
- Test: `tests/php/ContentModel/TemplateScaffolderTest.php`

**Interfaces:**
- Consumes: `WriteException`, `SafeFile::{isConfined,writeAtomic}`.
- Produces:
  ```php
  final class TemplateScaffolder {
      public function __construct(string $stylesheetDir, string $templateDir, bool $isBlockTheme, bool $fileModsAllowed);
      /** @return list<string> relative template names that would be written, e.g. ['single-product.html', 'archive-product.html'] */
      public function plan(string $slug, bool $hasArchive, array $taxonomySlugs, bool $withTaxonomies): array;
      /** @return array{written: list<string>, skipped: list<string>} */
      public function scaffold(string $slug, bool $hasArchive, array $taxonomySlugs, bool $withTaxonomies): array;   // throws WriteException
  }
  ```
  Writes into `<stylesheetDir>/templates/`. Refusals: classic theme → `WriteException('classic_theme', 409, 'This is a classic theme. Template scaffolding needs a block theme; build single and archive templates in your theme.')`; file mods disabled → `file_mods` 403; `templates/` a symlink or not writable → `path_outside_root` / `not_writable` 403. A template that exists in the stylesheet **or** the template directory is skipped, never overwritten. Seeds: `single-<slug>.html` from `templates/single.html` (stylesheet dir first, then template dir), `archive-<slug>.html` and `taxonomy-<tax>.html` from `templates/archive.html`; with no seed (spec section 3: only `single.html` / `archive.html` seed), a minimal template: `<!-- wp:template-part {"slug":"header","tagName":"header"} /-->`, a `core/group` `main` holding `core/post-title` + `core/post-content` (single) or a `core/query` with `core/post-template` (`core/post-title` linked, `core/post-excerpt`) and `core/query-pagination` (archive, taxonomy), and the footer part. Seeds are copied verbatim (developer-authored theme files); no declaration data is interpolated except the slug in the file name. Each file is checked with `SafeFile::isConfined($target, [realpath($stylesheetDir)])` and written with `SafeFile::writeAtomic`.

- [ ] **Step 1: Write the failing tests**
- `testWritesSingleAndArchive`: block theme, `hasArchive` true → `written === ['single-product.html', 'archive-product.html']`; `single-product.html` contains `wp:post-content`.
- `testNoArchiveWhenHasArchiveFalse` → only single.
- `testTaxonomiesOnlyOnRequest`: `['genre']` with `withTaxonomies` false → no `taxonomy-genre.html`; true → written.
- `testExistingNeverOverwritten`: pre-existing `single-product.html` with `KEEP` → content still `KEEP`, listed in `skipped`.
- `testExistingInParentCountsAsExisting`.
- `testSeedsFromThemeSingle`: theme `templates/single.html` with marker `<!-- seed -->` → output contains the marker.
- `testClassicThemeRefuses` → `WriteException` code `classic_theme`, nothing written.
- `testFileModsDisabledRefused` → `file_mods` 403, nothing written.
- `testNoSeedUsesMinimalTemplate`: no `single.html`/`archive.html` (an `index.html` present) → `archive-product.html` contains `wp:query` and not the `index.html` marker.
- `testSymlinkedTemplatesDirRefused`: `templates/` symlinked outside the theme → `path_outside_root`, the outside dir stays empty.

- [ ] **Step 2: Run to verify failure** → FAIL.
- [ ] **Step 3: Implement `TemplateScaffolder`.**
- [ ] **Step 4: Run to verify pass** → PASS.
- [ ] **Step 5: Commit**

```bash
git add includes/ContentModel/TemplateScaffolder.php tests/php/ContentModel/TemplateScaffolderTest.php
git commit -m "feat(content-model): scaffold block templates for declared post types"
```

---

### Task 9: WP-CLI commands and the validate report

**Parallel group:** A
**Depends on:** Task 6, Task 7, Task 8

**Files:**
- Create: `includes/CLI/ContentModelCommands.php`
- Modify: `includes/CLI/Commands.php` (register `proto-blocks type` and `proto-blocks taxonomy` beside the existing `add_command` calls; at the end of `validate()`, print `ContentModelCommands::validationRows()` and fail on errors)
- Test: `tests/php/CLI/ContentModelCommandsTest.php`

**Interfaces:**
- Consumes: `Registry`, `Modules\Manager`, `OriginTracker`, `SchemaFileWriter`, `DeclarationTemplate`, `TemplateScaffolder`, `Slug`, `Guardrails`.
- Produces:
  ```php
  final class ContentModelCommands {
      // wp proto-blocks type list [--format=<table|json|csv>]       columns: slug, name, origin, source, override, double, posts
      // wp proto-blocks type create <name> [--slug=<slug>] [--plural=<plural>] [--json]   writes the commented template; refuses existing slug
      // wp proto-blocks type scaffold <slug> [--taxonomies]          prints written and skipped
      // wp proto-blocks type validate [<slug>] [--format=...]       types and taxonomies
      // wp proto-blocks type rename <old> <new> [--yes] [--migrate]
      // wp proto-blocks taxonomy list|create|rename                  same options
      public function type(array $args, array $assoc): void;
      public function taxonomy(array $args, array $assoc): void;
      /** @return list<array{kind: string, slug: string, level: string, code: string, message: string, source: string}> */
      public static function validationRows(Registry $registry, array $moduleIssues): array;
      public static function normalizeSlug(string $raw, string $kind): ?string;   // lowercases, trims, then Slug::error must be null
      /** the testable core of `create`: refuses a slug the Registry already holds (WriteException 'exists', 409), writes DeclarationTemplate::render() through the writer @return array{path: string} */
      public static function createDeclaration(string $kind, string $name, ?string $slug, ?string $plural, SchemaFileWriter $writer, Registry $registry): array;
  }
  ```
  `create` uses the declaration slug rule (`^[a-z][a-z0-9_-]*$`, 20 / 32 chars, reserved check), not the block regex, because underscores are needed (`oit_resource`). `validate` rows include every Registry and module Issue, double declarations as `warning` / `double_declaration` naming both locations, and `name_required` errors. `rename` without `--yes` prints the plan (folder move, `N posts` or `N terms` affected) and exits non-zero; with `--yes` it moves the folder (writer rules apply), and with `--migrate` updates `wp_posts.post_type` (resp. `wp_term_taxonomy.taxonomy`) through `$wpdb->update` and flushes rewrite rules; without `--migrate`, existing content stays under the old slug and the command says so.

- [ ] **Step 1: Write the failing tests** (static helpers only; no WP-CLI runtime)
- `normalizeSlug(' Oit_Resource ', 'type') === 'oit_resource'`; `normalizeSlug('author', 'type') === null`; `normalizeSlug('../x', 'type') === null`; `normalizeSlug(str_repeat('a', 25), 'taxonomy') === str_repeat('a', 25)`; `str_repeat('a', 33)` → null.
- `createDeclaration('type', 'Case Study', null, null, $writer, $registry)` writes `<root>/proto-types/case-study/type.jsonc` that starts with `// Proto-Blocks post type declaration.`; a second call → `WriteException` code `exists`; slug `author` → code `invalid_slug`.
- `validationRows()` over a Registry with one double and one invalid file → two rows, levels `warning` and `error`, codes `double_declaration` and `invalid_jsonc`, `source` relative paths.

- [ ] **Step 2: Run to verify failure** → FAIL.
- [ ] **Step 3: Implement the commands and the two registrations in `Commands.php`.**
- [ ] **Step 4: Run to verify pass** → `composer test` PASS.
- [ ] **Step 5: Manual check** on a Local site: `wp proto-blocks type create "Case Study"` writes `proto-types/case-study/type.jsonc`; `wp proto-blocks type list` shows it with origin `file`; adding `proto_post_type('Case Study');` makes `wp proto-blocks validate` print the double-declaration warning; `wp proto-blocks type scaffold case-study` writes two templates and a second run skips both.
- [ ] **Step 6: Commit**

```bash
git add includes/CLI tests/php/CLI
git commit -m "feat(cli): type and taxonomy commands and declaration validation"
```

---

### Task 10: REST route groups and admin menu entries

**Parallel group:** B
**Depends on:** Phase 0 Tasks 2–3, Task 6, Task 7, Task 8

**Files:**
- Create: `includes/API/Admin/PostTypesRoutes.php`, `includes/API/Admin/TaxonomiesRoutes.php`, `includes/API/Admin/SchemaFilesRoutes.php`, `includes/Admin/ContentModelMenu.php`
- Modify: `includes/API/AdminRestController.php` (three entries in `ROUTE_GROUPS`), `includes/Admin/AdminApp.php` (`'post-types'`, `'taxonomies'` in `SCREENS`), `includes/Core/Plugin.php` (`add_action('admin_menu', [new ContentModelMenu(...), 'register'], 11)`)
- Test: `tests/php/API/PostTypesRoutesTest.php`, `tests/php/API/TaxonomiesRoutesTest.php`, `tests/php/API/SchemaFilesRoutesTest.php` (use `tests/php/Admin/wp-stubs.php` plus `tests/php/ContentModel/stubs/*.php`)

**Interfaces:**
- Consumes: Phase 0 `registerRoutes(string $namespace, callable $permission, callable $writePermission): void` contract; `Registry`, `Manager`, `OriginTracker`, `SchemaFileWriter`, `Guardrails`, `TemplateScaffolder`, `DeclarationTemplate`.
- Produces (shared JSON shapes, mirrored in Task 11's `types.ts`):
  ```
  ListItem    { slug, label, origin: 'file'|'php'|'plugin'|'theme'|'core', originLabel, source: string|null (relative to ABSPATH),
                override: bool, double: {file, php}|null, count: int, editable: bool,
                readOnlyReason: 'file_mods'|'not_writable'|'not_proto'|'outside_root'|null, issues: Issue[] }
  ListResult  { moduleEnabled: bool, createTarget: {path, writable, reason}, items: ListItem[], issues: Issue[] }
  TypeDetail  { item: ListItem, effective: object (resolved args, labels included), content: {count, taxonomies: {slug: int}},
                blockTheme: bool, templates: {name: string, exists: bool}[] }
  TaxDetail   { item: ListItem, effective: object, objectTypes: string[], content: {count} }
  FileResult  { exists: bool, path, text, hash: string|null, data: object, writable: bool, reason: string|null }
  ```
  Each route-group class has a constructor whose parameters are all optional (`?Registry $registry = null, ?Manager $modules = null, ?SchemaFileWriter $writer = null, ?Guardrails $guardrails = null, ?TemplateScaffolder $scaffolder = null`), resolving each `null` lazily from `Plugin::getInstance()` (writer: `new SchemaFileWriter(SchemaPaths::writableRoots(), AdminPage::fileModsAllowed())`; guardrails: closures over `wp_count_posts` / `wp_count_terms`), so `AdminRestController` can instantiate the classes listed in `ROUTE_GROUPS` and tests can inject doubles.
  Routes (namespace `proto-blocks/v1`):
  - `GET admin/types` → `ListResult` (every registered type from `get_post_types([], 'objects')` merged with declarations that failed to register; `originLabel` `Proto-Blocks file` / `Proto-Blocks PHP` / `Plugin: <Plugin Name>` / `Theme: <Theme Name>` / `Core`; `count` = sum of `wp_count_posts()` excluding `auto-draft`; internal types without `show_ui` omitted).
  - `GET admin/types/(?P<slug>[a-z][a-z0-9_-]{0,19})` → `TypeDetail`; 404 `proto_blocks_not_found`.
  - `POST admin/types/(?P<slug>...)/scaffold` (writePermission) body `{taxonomies: bool}` → `{written, skipped}`; `WriteException` mapped.
  - `GET admin/taxonomies`, `GET admin/taxonomies/(?P<slug>[a-z][a-z0-9_-]{0,31})` → `ListResult`, `TaxDetail` (`count` via `wp_count_terms`).
  - `GET schema-files/(?P<kind>type|taxonomy)/(?P<slug>...)` (permission) query `name?` → `FileResult`; when no file exists, `text` is `DeclarationTemplate::render(kind, name ?? ucwords(slug), slug?)` and `hash` null.
  - `PUT|POST schema-files/(?P<kind>type|taxonomy)/(?P<slug>...)` (writePermission; `methods => WP_REST_Server::EDITABLE` so Phase 0's `adminPost` works) body `{text: string, hash: string|null, acknowledge: string[]}` → `{path, hash, created, warnings: Issue[]}`. Before writing: `Guardrails::checkSave()` against the current declaration's data; any finding whose `code` is not in `acknowledge` → 409 `proto_blocks_needs_confirmation` with `data.guardrails`. After writing: `Registry::invalidate()`.
  - `DELETE schema-files/(?P<kind>...)/(?P<slug>...)` (writePermission) query arg `confirm` (Phase 0's `adminDelete(path)` sends no body; read it with `$request->get_param('confirm')`) → `{deleted, kept}`; when `Guardrails::contentFor()` reports content and `confirm !== slug` → 409 `proto_blocks_confirm_required` with `data.content`; declarations from PHP only → 409 `proto_blocks_not_a_file`.
  - Every handler also runs `Slug::error($slug, $kind)` first → 400 `proto_blocks_invalid_slug` (WordPress matches route patterns case-insensitively, `@^...$@i`, so `Product` reaches the handler).
  - `WriteException` → `WP_Error('proto_blocks_' . $e->errorCode, $message, ['status' => $e->status] + $e->data)`.
  - `ContentModelMenu::register()` adds submenus `proto-blocks-post-types` ("Post Types") and `proto-blocks-taxonomies` ("Taxonomies") under the Proto-Blocks menu, capability `manage_options`, callbacks `AdminApp::renderScreen('post-types')` / `renderScreen('taxonomies')`. Menus exist even when a module is disabled; the screens show the disabled state from `moduleEnabled`.

- [ ] **Step 1: Write the failing PHPUnit tests**

`SchemaFilesRoutesTest`:
- `testRoutesRegistered`: after `registerRoutes('proto-blocks/v1', ...)`, `$GLOBALS['pb_test_routes']` has `schema-files/(?P<kind>type|taxonomy)/(?P<slug>[a-z][a-z0-9_-]{0,31})` with GET, EDITABLE and DELETE, and the write methods use `$writePermission`.
- `testSlugRegexRejectsTraversal`: matching the way WordPress does (`preg_match('@^' . $route . '$@i', $path)`), `schema-files/type/../x`, `schema-files/type/a/b`, `schema-files/type/%2e%2e`, `schema-files/plugin/x` all fail; `schema-files/type/Product` matches, and its GET and PUT handlers return 400 `proto_blocks_invalid_slug`.
- `testWriteRoutesUseWritePermission`: the PUT/POST and DELETE entries of `schema-files/...` and the POST of `admin/types/.../scaffold` carry the `$writePermission` callable passed in; every GET carries `$permission`.
- `testPutValidCreatesAndInvalidates`: handler with a valid template → 200 shape, file on disk, registry `invalidate` called.
- `testPutSchemaInvalid` → `proto_blocks_schema_invalid`, status 400, `errors[0].path === '/public'`.
- `testPutNeedsConfirmationForSupportsRemoval`: current file supports default, 2 posts, new text `supports: ["title"]`, `acknowledge: []` → 409 `proto_blocks_needs_confirmation`; with `acknowledge: ['supports_removed']` → 200.
- `testDeleteNeedsConfirmWithContent`: 4 posts, no `confirm` → 409 `proto_blocks_confirm_required`; `confirm: 'product'` → 200.
- `testPutFileModsOffMapsTo403`: handler with a writer constructed with `fileModsAllowed` false → `proto_blocks_file_mods`, status 403 (defence in depth behind `writePermission`).
- `testCreateTargetReportsNotWritable`: `GET admin/types` with the first writable root `chmod 0555` → `createTarget.writable === false`, `reason === 'not_writable'` (skipped as root).
- `testDeleteReadsConfirmFromQuery`: a request with query `confirm=product` and no body passes the 4-post guard.

`PostTypesRoutesTest`: list contains a file declaration with `origin 'file'`, a PHP one with `'php'`, a shadowed pair with `double` set and `originLabel 'Proto-Blocks file'`, a core `post` with `origin 'core'` and `editable` false (no file) until an override file exists; `moduleEnabled` false when the manager reports the module off; scaffold on a classic theme → 409 `proto_blocks_classic_theme`.

`TaxonomiesRoutesTest`: inline taxonomy lists `source` = the type file and `readOnlyReason` `null`; filter-root declaration → `editable` false, `readOnlyReason 'outside_root'`.

- [ ] **Step 2: Run to verify failure** → FAIL.
- [ ] **Step 3: Implement the three route groups, the menu class and the one-line registrations.**
- [ ] **Step 4: Run to verify pass** → `composer test` PASS.
- [ ] **Step 5: Commit**

```bash
git add includes/API includes/Admin includes/Core/Plugin.php tests/php/API
git commit -m "feat(api): post type, taxonomy and schema-file routes with server-side guardrails"
```

---

### Task 11: Client libraries, shared components and screen registration

**Parallel group:** C
**Depends on:** Phase 0 Tasks 1–3, Task 1 (schema file); Task 10's shapes (contract only, mocked)

**Files:**
- Modify: `package.json` (`npm i jsonc-parser@^3.3`); `src/admin/api.ts` (`ApiError` gains `data`, one field plus one assignment in the existing rejection mapping); `src/admin/bootstrap.ts` (`'post-types' | 'taxonomies'` added to `Screen`); `src/admin/screens/index.ts` (two lines: `'post-types': lazy(() => import('./PostTypesScreen'))`, `taxonomies: lazy(() => import('./TaxonomiesScreen'))`)
- Create: `src/admin/content-model/{types,jsonc,schema,useDeclarationFile}.ts`, `src/admin/components/content-model/{OriginBadge,IssueList,DeclarationShell,DeleteDeclarationDialog,GuardrailDialog,ConflictDialog,LabelsEditor,MenuIconField}.tsx`, placeholder `src/admin/screens/PostTypesScreen.tsx` and `src/admin/screens/TaxonomiesScreen.tsx` (each a default export rendering only `<PageHeader title=… />`; Tasks 12 and 13 replace them, so neither touches a shared file)
- Test: `src/admin/__tests__/content-model/{jsonc,schema,components,useDeclarationFile,api-data}.test.ts(x)`

**Interfaces:**
- Consumes: Phase 0 `adminGet`, `adminPost`, `adminDelete`, `ApiError`, `getBootstrap`, `ReadOnlyBanner`, `ConfirmDialog`, `PageHeader`, `mockBootstrap`, `mockApi`.
- Produces:
  ```ts
  // api.ts (Phase 0 file, one addition)
  export class ApiError extends Error { code: string; status: number; data: Record<string, unknown> }   // data = the WP_Error data object minus status, {} when absent
  // types.ts — mirrors Task 10 exactly
  export type Origin = 'file'|'php'|'plugin'|'theme'|'core';
  export interface Issue { level: 'error'|'warning'; code: string; message: string; source: string; slug?: string; kind?: string }
  export interface ListItem { slug: string; label: string; origin: Origin; originLabel: string; source: string|null; override: boolean; double: { file: string; php: string } | null; count: number; editable: boolean; readOnlyReason: 'file_mods'|'not_writable'|'not_proto'|'outside_root'|null; issues: Issue[] }
  export interface ListResult { moduleEnabled: boolean; createTarget: { path: string; writable: boolean; reason: string|null }; items: ListItem[]; issues: Issue[] }
  export interface FileResult { exists: boolean; path: string; text: string; hash: string|null; data: Record<string, unknown>; writable: boolean; reason: string|null }
  export interface Guardrail { code: 'supports_removed'|'taxonomy_removed'|'field_removed'; message: string; count: number; detail: string[] }
  export type Kind = 'type'|'taxonomy';
  // jsonc.ts
  export function parseJsonc(text: string): { data: Record<string, unknown>; errors: { offset: number; message: string }[] };   // walks parseTree first: any "__proto__" key → error, data {}
  export function applyDeclarationEdits(text: string, before: Record<string, unknown>, after: Record<string, unknown>): string;
  // schema.ts
  export function toZod(schema: JsonSchema, root?: JsonSchema): z.ZodTypeAny;   // same keyword subset as SchemaCheck
  export const typeSchema: z.ZodTypeAny; export const taxonomySchema: z.ZodTypeAny;   // from includes/ContentModel/schemas/content-model.schema.json
  // useDeclarationFile.ts
  export function useDeclarationFile(kind: Kind, slug: string, name?: string): {
    file: FileResult | null; loading: boolean; error: ApiError | null;
    save(next: Record<string, unknown>, acknowledge?: string[]): Promise<{ status: 'saved' } | { status: 'conflict' } | { status: 'confirm'; guardrails: Guardrail[] }>;
    remove(confirm?: string): Promise<{ status: 'deleted'; kept: string[] } | { status: 'confirm'; content: { count: number; taxonomies?: Record<string, number> } }>;
    reload(): Promise<void>;
  };
  ```
  `applyDeclarationEdits` compares top-level keys; the `labels` and `rewrite` objects are compared per key (so a comment beside one label survives); every other value, arrays included (`supports`, `template`, `taxonomies`), is replaced whole. Each changed path is one `jsonc-parser` `modify(text, path, value, { formattingOptions: { insertSpaces: true, tabSize: 2 } })` (`value` `undefined` removes the key) followed by `applyEdits`. `save(next)` expects only the values the user changed merged over `file.data` (screens pass `{...file.data, ...dirtyValues}`), posts `{ text, hash, acknowledge }` to `schema-files/<kind>/<slug>` with `adminPost`, maps `proto_blocks_conflict` → `conflict` and `proto_blocks_needs_confirmation` → `confirm` with `guardrails` from `error.data.guardrails`, and toasts `Saved to <path>.` on success. `remove(confirm)` calls `adminDelete('schema-files/<kind>/<slug>' + (confirm ? '?confirm=' + encodeURIComponent(confirm) : ''))` and maps `proto_blocks_confirm_required` → `confirm` with `content` from `error.data.content`.
  Components: `OriginBadge({origin, label, double})` (double → second badge `Declared twice` with a tooltip naming both locations); `IssueList({issues})`; `DeclarationShell({title, readOnlyReason, path, children, actions})` wrapping `ReadOnlyBanner` (`file_mods` → `reason="file_mods"`; `not_writable` → `reason="not_writable" path`); `DeleteDeclarationDialog({slug, path, content, onConfirm})` (requires typing the slug when `content.count > 0`, names the path, says content is kept but becomes inaccessible); `GuardrailDialog({guardrails, onConfirm, onCancel})`; `ConflictDialog({path, onReload})`; `LabelsEditor` (shows generated labels as placeholders, writes only overridden keys); `MenuIconField` (dashicon picker input + path/URL text, preview, warning text for non-https URLs).

- [ ] **Step 1: Write the failing tests**

`jsonc.test.ts`:
- preserves comments and key order: input `// header\n{\n  // the name\n  "name": "Product",\n  /* archive */ "has_archive": true,\n}` with `after = {...before, has_archive: 'shop'}` → output still contains `// header`, `// the name`, `/* archive */`, `"has_archive": "shop"`, and `name` precedes `has_archive`.
- adds a missing key at the end; removes a key without touching neighbouring comments.
- `labels.menu_name` change edits only that key.
- `supports` change replaces the array.
- `parseJsonc` on a trailing-comma file returns no errors.

`schema.test.ts`: `typeSchema.safeParse({ name: 'X', public: 'yes' })` fails at path `['public']`; `{ has_archive: 'resources' }` passes; `{ rewrite: false }` passes; inline taxonomy with `hierarchical: 'no'` fails at `['taxonomies', 0, 'hierarchical']`; unknown keys pass; parity check: a table of 10 sample inputs gives the same valid/invalid verdict as the PHP `SchemaCheckTest` cases (copy the inputs).

`jsonc.test.ts` also: `parseJsonc('{"labels": {"__proto__": {"polluted": true}}}')` returns one error mentioning `__proto__`, `data` deep-equals `{}`, and `({} as any).polluted` is `undefined`.

`api-data.test.ts`: a rejected apiFetch `{ code: 'proto_blocks_needs_confirmation', message: 'm', data: { status: 409, guardrails: [{ code: 'supports_removed' }] } }` → `ApiError` with `status === 409` and `data.guardrails` of length 1; a rejection without `data` → `data` deep-equals `{}`.

`components.test.tsx`: `OriginBadge` with `double` shows `Declared twice`; `DeleteDeclarationDialog` with `count 3` keeps Delete disabled until `product` is typed; with `count 0` Delete is enabled; `DeclarationShell` with `readOnlyReason 'file_mods'` renders the Phase 0 banner text.

`useDeclarationFile.test.ts` (`mockApi`): save posts `{ text, hash, acknowledge: [] }` where `text` is the comment-preserving edit; a mocked 409 `proto_blocks_conflict` resolves `{ status: 'conflict' }`; a 409 `proto_blocks_needs_confirmation` with `data.guardrails` resolves `confirm`.

- [ ] **Step 2: Run to verify failure** — `npm run test:js -- src/admin/__tests__/content-model` → FAIL.
- [ ] **Step 3: Install `jsonc-parser`; implement the modules, components, the `ApiError.data` field, the `Screen` union members, the two `SCREENS` entries and the placeholder screens.** Import the schema with a relative path from `includes/ContentModel/schemas/content-model.schema.json` (`resolveJsonModule` is already on).
- [ ] **Step 4: Run to verify pass** → PASS.
- [ ] **Step 5: Commit**

```bash
git add package.json package-lock.json src/admin/api.ts src/admin/bootstrap.ts src/admin/screens src/admin/content-model src/admin/components/content-model src/admin/__tests__/content-model
git commit -m "feat(admin): comment-preserving JSONC edits and shared content-model components"
```

---

### Task 12: Post Types screen

**Parallel group:** C
**Depends on:** Task 11; Task 10 for manual and E2E checks (Jest mocks the API)

**Files:**
- Create: `src/admin/screens/post-types/{PostTypeList,PostTypeForm,CreatePostTypeDialog,TemplatesCard}.tsx`, `src/admin/__tests__/content-model/post-types.test.tsx`
- Replace: `src/admin/screens/PostTypesScreen.tsx` (Task 11's placeholder; no shared file is touched)
- Read first: the design guide sections 2 and 3 (screen A) and `design/phase-1-3/post-types.html`

**Interfaces:**
- Consumes: `adminGet`, `adminPost`, `adminDelete`, `getBootstrap`, `PageHeader`, `ConfirmDialog`, `ScreenError` (load failure), everything from Task 11; routes from Task 10.
- Produces: `PostTypesScreen()`; view state in the URL query (`&type=<slug>` edit, `&new=1` create) via `history.replaceState`, so links are shareable and Back works.
  - **List:** `PageHeader` "Post Types" with an `Add post type` button (opens `CreatePostTypeDialog`, guide A1: singular name → live plural, slug and "Will create `proto-types/<slug>/type.jsonc`" → `Create post type`, which GETs the template, POSTs it and routes to `&type=<slug>`); a table of `items` with columns Name, Slug, Origin (`OriginBadge`), Source, Posts (`count`), and an `Edit` / `Override` action (`Override` for `core`/`plugin`/`theme` items without a file; it opens the edit form in create mode for that slug with `name` omitted). Module disabled → a card "The Post Types module is disabled by the proto_blocks_modules filter." and no actions. `issues` → `IssueList` above the table.
  - **Edit/create form:** react-hook-form with `zodResolver(typeSchema)`; sections General (`name`, `plural`, slug read-only when editing, editable on create with live `Slug` preview and the server `slug_*` errors), Visibility (`public`, `show_in_rest`, `hierarchical`), Archive and URLs (`has_archive` as off / on / custom slug, `rewrite.slug`, `rewrite.with_front`), Editor (`supports` checkboxes for title, editor, excerpt, thumbnail, revisions, custom-fields, page-attributes, comments, author; `template_lock`), Menu (`menu_position`, `MenuIconField`), Labels (`LabelsEditor`), Taxonomies (inline list: name, slug, hierarchical; plus string slugs of existing taxonomies), and a read-only "Effective settings" panel from `effective`. Only fields the user changed are written: the form submits `{...file.data, ...dirtyValues}` (react-hook-form `dirtyFields`), so a bare `{ "name": "Product" }` file stays bare. The submit button reads `Save to file` (guide 2.4), `Create override file` for core/plugin/theme items without a file.
  - **Slug guardrail:** when `content.count > 0` the slug field shows `This type has N posts. Rename it with wp proto-blocks type rename <slug> <new> --migrate.`
  - **Save flow:** `confirm` → `GuardrailDialog` → resend with `acknowledge`; `conflict` → `ConflictDialog` → `reload()`.
  - **Delete:** `DeleteDeclarationDialog` (only for `origin 'file'`); PHP-only items show "Declared in PHP at <source>; remove the line from your theme."
  - **Templates card:** lists `templates` with exists/missing, a `Scaffold templates` button (`adminPost('admin/types/<slug>/scaffold', { taxonomies })` with a checkbox "Also taxonomy templates"), disabled with a tooltip when `blockTheme` is false ("Classic theme: build templates in your theme") or in read-only mode; result toast `Wrote 2 templates, skipped 1.`
  - **Read-only:** `fileModsAllowed` false or `readOnlyReason` set → `DeclarationShell` banner, every input disabled, Save/Delete/Scaffold disabled.

- [ ] **Step 1: Write the failing tests** (`mockBootstrap({ screen: 'post-types' })`, `mockApi` routes)
- list renders `Product` with `Proto-Blocks file`, `Resource` with `Proto-Blocks PHP` and `Declared twice`, and `Posts` with `Core`.
- `moduleEnabled: false` shows the disabled card and no `Add post type` button.
- dirty-only save: file `{ "name": "Product" }`, change only Menu position to 30, `Save to file` → posted text decodes to exactly `{ name: 'Product', menu_position: 30 }`.
- create flow: typing `Case Study` in the dialog shows slug `case-study`; `Create post type` calls `adminGet('schema-files/type/case-study?name=Case%20Study')` then `adminPost('schema-files/type/case-study', { text: <contains "name": "Case Study">, hash: null, acknowledge: [] })`.
- edit flow keeps the developer's comment: file text `// keep me\n{ "name": "Product" }`, toggling Archive off posts text containing `// keep me` and `"has_archive": false`.
- removing `editor` from supports on a type with `content.count 3`: the API answers 409 `proto_blocks_needs_confirmation`; the dialog shows `3`; confirming resends with `acknowledge: ['supports_removed']`.
- a 409 `proto_blocks_conflict` opens `ConflictDialog`; `Reload` refetches the file.
- slug field disabled with the rename hint when `content.count > 0`.
- `fileModsAllowed: false` → banner shown, Save/Delete/Scaffold disabled.
- Scaffold disabled with the classic-theme tooltip when `blockTheme: false`; enabled otherwise and posts `{ taxonomies: true }` when the checkbox is ticked.
- Delete of a type with posts requires typing the slug and calls `adminDelete` with `confirm` (Phase 0's `adminDelete` takes no body: send `?confirm=<slug>` as a query arg; Task 10's DELETE reads `confirm` from the request params, which covers both).

- [ ] **Step 2: Run to verify failure** → FAIL.
- [ ] **Step 3: Implement the screen and the registration line.**
- [ ] **Step 4: Run** `npm run test:js && npm run build` → PASS.
- [ ] **Step 5: Manual check** on a Local site: create, edit (comments survive in the file on disk), override `post` with a template, scaffold, delete.
- [ ] **Step 6: Commit**

```bash
git add src/admin
git commit -m "feat(admin): Post Types screen writing JSONC declarations"
```

---

### Task 13: Taxonomies screen

**Parallel group:** C
**Depends on:** Task 11; Task 10 for manual and E2E checks

**Files:**
- Create: `src/admin/screens/taxonomies/{TaxonomyList,TaxonomyForm}.tsx`, `src/admin/__tests__/content-model/taxonomies.test.tsx`
- Replace: `src/admin/screens/TaxonomiesScreen.tsx` (Task 11's placeholder; no shared file is touched)
- Read first: the design guide sections 2 and 4 (screen B) and `design/phase-1-3/taxonomies.html`

**Interfaces:**
- Consumes: as Task 12.
- Produces: `TaxonomiesScreen()`, URL state `&taxonomy=<slug>` / `&new=1`.
  - **List:** Name, Slug, Origin, Attached to (object types), Terms (`count`), Source. Inline taxonomies show origin `Proto-Blocks file` with source = the type file and an `Edit in post type` link to `?page=proto-blocks-post-types&type=<parentType>` instead of an editor here (editing an inline taxonomy edits its type file).
  - **Form** over `taxonomySchema`: `name`, `plural`, slug, `post_types` (multi-select of registered types from `adminGet('admin/types')`), `hierarchical`, `public`, `show_in_rest`, `show_admin_column`, `rewrite.slug`, `rewrite.with_front`, `LabelsEditor`. Standalone files only.
  - Guardrails: slug locked with the CLI hint when `count > 0`; delete requires typing the slug when terms exist; saving a removal of an object type that has terms is not guarded (terms survive; the spec guards unregistering, which is delete).
  - Read-only and conflict behaviour as Task 12.

- [ ] **Step 1: Write the failing tests**: list shows `Genre` attached to `book` with an `Edit in post type` link to `?page=proto-blocks-post-types&type=book`; create `Media Type` posts text with `"name": "Media Type"` and `"post_types": ["resource"]` to `schema-files/taxonomy/media-type`; `count 5` locks the slug and shows `5`; delete with `count 5` keeps the destructive button disabled until `media-type` is typed and then calls `adminDelete('schema-files/taxonomy/media-type?confirm=media-type')`; `fileModsAllowed: false` shows the `File modifications are disabled on this site` banner and disables `Save to file`, `Add taxonomy` and Delete; a 409 `proto_blocks_conflict` opens `ConflictDialog`.
- [ ] **Step 2: Run to verify failure** → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `npm run test:js && npm run build` → PASS.
- [ ] **Step 5: Commit**

```bash
git add src/admin
git commit -m "feat(admin): Taxonomies screen writing JSONC declarations"
```

---

### Task 14: End-to-end, parity in WordPress, visual checks and docs

**Parallel group:** D
**Depends on:** Tasks 1–13; the E2E harness already on `feat/content-model`

**Files:**
- Create: `tests/e2e/fixtures/themes/pb-e2e-block/` (`style.css`, `theme.json`, `templates/{index,single,archive}.html`, `parts/{header,footer}.html`, `functions.php` with `proto_post_type('Event', ['menu_icon' => 'icons/event.svg']);`, `icons/event.svg`, `proto-types/book/type.jsonc` with a `// fixture comment` line and an inline `Genre` taxonomy, `proto-types/post/type.jsonc` with an OptimizedIT-style `template`, `proto-taxonomies/topic/taxonomy.jsonc` attached to `book` and `post`), `tests/e2e/fixtures/themes/pb-e2e-classic/` (`style.css`, `index.php`), `tests/e2e/fixtures/parity.php`, `tests/e2e/content-model-utils.ts`, `tests/e2e/post-types.spec.ts`, `tests/e2e/taxonomies.spec.ts`, `tests/e2e/content-model-visual.spec.ts`; baselines land under `tests/e2e/__screenshots__/` through the existing `snapshotPathTemplate`
- Modify: `.wp-env.json` (one key: `"mappings": { "wp-content/pb-e2e-fixtures": "./tests/e2e/fixtures" }`; nothing else in the harness changes — `playwright.config.ts`, `global-setup.ts` and the scripts stay as they are), `README.md` (a "Post types and taxonomies" section: one-liners, file layout, precedence, overrides, icons, CLI), `CHANGELOG.md` (Unreleased)

**Interfaces:**
- Consumes: the harness as it is: `import { test, expect } from '@wordpress/e2e-test-utils-playwright'` (fixtures `admin`, `page`, `requestUtils`), the tests site on `:8889` with the stored admin session, `npx wp-env run tests-cli …` for WP-CLI (always `tests-cli`, never `cli`: the suite runs against the tests environment).
- Produces `tests/e2e/content-model-utils.ts`:
  ```ts
  export function wpCli(cmd: string): string;                    // execSync(`npx wp-env run tests-cli ${cmd}`, { cwd: repo root }).toString()
  export function installFixtureTheme(name: 'pb-e2e-block'|'pb-e2e-classic'): void;
  // rm -rf wp-content/themes/<name> && cp -r wp-content/pb-e2e-fixtures/themes/<name> wp-content/themes/<name> && chmod -R a+rwX wp-content/themes/<name>
  // The UI writes into the theme, so tests work on a fresh copy inside the container, never on the mapped repo folder.
  export function themeFile(name: string, rel: string): string;  // cat of a file in the installed copy
  export function writeThemeFile(name: string, rel: string, text: string): void;   // mkdir -p + write via `sh -c` with the text base64-encoded (no shell quoting of JSON); removeThemePath(name, rel) deletes it
  export function removeThemePath(name: string, rel: string): void;
  ```
- `tests/e2e/fixtures/parity.php` (run with `wpCli('wp eval-file wp-content/pb-e2e-fixtures/parity.php')`): for each recon fixture from Task 5, registers the original args, snapshots `get_object_vars(get_post_type_object($slug))` minus `labels`, `cap`, and `rewrite` endpoints, unregisters, registers through `PostTypeArgs::build()` of the one-line declaration, snapshots again, and prints JSON `{ "<slug>": { "equal": bool, "diff": {...} } }` with labels compared on the original's keys modulo terminal `.`. Exits 1 on any difference.

- [ ] **Step 1: Write the failing E2E specs**

`post-types.spec.ts` — `beforeAll`: `wpCli("wp rewrite structure '/%postname%/'")` (this flushes once, before the theme exists), then `installFixtureTheme('pb-e2e-block')` and `requestUtils.activateTheme('pb-e2e-block')`; no further manual flush anywhere, so a working `/book/` proves the hash flush.
- **front end:** a published `book` created with `requestUtils.rest({ path: '/wp/v2/book', method: 'POST', data: { title: 'Dune', status: 'publish' } })`; `/book/dune/` returns 200 and contains `Dune`; `/book/` lists it.
- **override:** `admin.createNewPost()` pre-fills the `post` template: `page.evaluate(() => wp.data.select('core/block-editor').getBlocks()[0].name)` equals the first block of `proto-types/post/type.jsonc`.
- **icon:** the `#menu-posts-event .wp-menu-image` `background-image` starts with `url("data:image/svg+xml;base64,`.
- **list:** `admin.visitAdminPage('admin.php', 'page=proto-blocks-post-types')` shows `Book` (`Proto-Blocks file`), `Event` (`Proto-Blocks PHP`), `Posts` (`Core`).
- **double declaration:** `writeThemeFile('pb-e2e-block', 'proto-types/event/type.jsonc', '{ "name": "Event" }')`; reload: the `Declared twice` badge on Event and an admin notice containing both `proto-types/event/type.jsonc` and `functions.php`; `wpCli('wp proto-blocks validate')` output contains `double_declaration`; `removeThemePath('pb-e2e-block', 'proto-types/event')` in `finally`.
- **edit keeps comments:** set Book's archive to off and `Save to file`; `themeFile('pb-e2e-block', 'proto-types/book/type.jsonc')` still contains `// fixture comment` and `"has_archive": false`; `/book/` now returns 404.
- **create and delete:** create `Case Study` from `Add post type` → `proto-types/case-study/type.jsonc` exists in the installed copy and the admin menu shows `Case Studies`; delete it (no posts) → the folder is gone.
- **guardrail:** with the published book, unchecking `Editor` and `Save to file` opens the confirmation naming `1`.
- **scaffold:** `Scaffold templates` on Book writes `templates/single-book.html` and `templates/archive-book.html` (check with `wpCli('ls wp-content/themes/pb-e2e-block/templates')`); a second click reports both skipped. Then `installFixtureTheme('pb-e2e-classic')`, activate it: the button is disabled with the classic-theme help text. `beforeAll` records `wpCli('wp theme list --status=active --field=name')` and `afterAll` re-activates it, so the existing smoke specs are unaffected.
- **read-only:** `wpCli('wp config set DISALLOW_FILE_MODS true --raw')`; the screen shows `File modifications are disabled on this site`, `Save to file` is disabled, and a direct `requestUtils.rest({ path: '/proto-blocks/v1/schema-files/type/book', method: 'POST', data: {...} })` fails with 403; `wp config delete DISALLOW_FILE_MODS` in `finally`.
- **parity:** `JSON.parse(wpCli('wp eval-file wp-content/pb-e2e-fixtures/parity.php'))`: `resource.equal` and `oit_resource.equal` are `true`.

`taxonomies.spec.ts` (same `beforeAll`): `Genre` listed with `Edit in post type`; a `topic` term created with `requestUtils.rest` on a book, `/topic/<term>/` returns 200; editing `topic` to drop `post` from `post_types` saves; an unauthenticated `request.newContext()` (no cookies, no nonce) `GET /wp-json/proto-blocks/v1/admin/taxonomies` → 401; a browser context logged in as an `editor` created with `requestUtils.createUser` sees `Sorry, you are not allowed to access this page.` on `admin.php?page=proto-blocks-taxonomies`.

`content-model-visual.spec.ts`: `toHaveScreenshot` for `post-types-list.png`, `post-types-edit.png` (Book), `post-types-readonly.png`, `taxonomies-list.png`, `taxonomies-edit.png` (Topic), each masking the admin bar and counts (`mask: [page.locator('#wpadminbar'), page.locator('[data-testid=count]')]`); the counts carry `data-testid="count"` in Tasks 12–13.

- [ ] **Step 2: Run to verify failure** — `npm run build && npm run wp-env start && npm run test:e2e -- tests/e2e/post-types.spec.ts` → FAIL (fixture theme missing).
- [ ] **Step 3: Add the fixture themes, `content-model-utils.ts`, the parity script and the `.wp-env.json` mapping;** restart with `npm run wp-env start -- --update` so the mapping is mounted.
- [ ] **Step 4: Run** `npx playwright test tests/e2e/content-model-visual.spec.ts --update-snapshots`, review each PNG by eye (layout, no clipped text, badges legible), then `npm run test:e2e` → all PASS, including the existing `admin-smoke` and `frontend-smoke` specs.
- [ ] **Step 5: Write the README section and the CHANGELOG entry** (Unreleased: post types and taxonomies from one line of PHP or a JSONC file, overrides of core and plugin types, Post Types and Taxonomies screens, `wp proto-blocks type|taxonomy` commands).
- [ ] **Step 6: Run everything** `composer test && npm run test:js && npm run build && npm run test:e2e` → PASS.
- [ ] **Step 7: Commit**

```bash
git add tests/e2e .wp-env.json README.md CHANGELOG.md
git commit -m "test(e2e): post types and taxonomies end to end, parity and visual checks"
```

---

## Self-review notes

- **Spec coverage (Phase 1):** one-line PHP (T4, T5); JSONC files with comments, trailing commas, `.json`, child wins, `proto_blocks_schema_paths` (T1, T4); defaults table, `plural` override and labels (T2, T5); icon rules with server SVG sanitising (T3); file-over-PHP with notice, badge and validate warning (T4, T6 notice test, T9, T11–T12); external and built-in overrides through both args filters, including OptimizedIT's `post` template (T6, T14); rewrite flush on hash change (T6, T14); template scaffold from CLI and UI, never overwrite, classic refusal (T8, T9, T10, T12, T14); list screens with origins, edit forms over the schema, comment-preserving client edits, server validation, confinement, atomic writes, read-only mode, content guardrails (T7, T10–T13); CLI `type list|create|scaffold|validate`, `taxonomy list|create`, plus `rename` because section 4 routes slug changes to it (T9); Modules layer with `boot()` and `proto_blocks_modules` (T6); E2E and visual (T14); done criterion as a unit parity test (T5) and an in-WordPress parity script (T14).
- **Rulings on spec gaps:** (1) declarations are flat: plugin keys `name`, `plural`, `slug`, `taxonomies`, `fields`, `post_types` plus any `register_post_type`/`register_taxonomy` key; the spec's "its `args`" means those keys, no nested `args` object. (2) A declaration is an override when its slug is already registered at `init` 10 or is `post`/`page`/`attachment`/`category`/`post_tag`; override files apply only their declared keys, no defaults. (3) `rewrite` arrays merge over the default (keeps `with_front false`), `labels` merge, `supports` replaces. (4) Child theme beating parent on the same slug is not a double declaration; a standalone taxonomy beating an inline one is. (5) Taxonomy slugs may be 32 chars; CLI uses the declaration slug rule (underscores allowed) rather than the block regex, because `oit_resource` needs it. (6) One schema file with `$defs` serves PHP and the client; the PHP checker and `toZod` implement the same keyword subset (no Composer runtime dependency). (7) The server SVG pass is a DOM allowlist mirroring the TS sanitiser rather than `wp_kses`. (8) The UI writes only to the stylesheet/template directories; filter roots are read-only; new files go to the stylesheet (child) theme. (9) Writes route accepts PUT and POST (`EDITABLE`) since Phase 0's client has `adminPost` only (no `adminPut` is added); DELETE takes `confirm` as a query arg because `adminDelete` sends no body; `ApiError` gains `data` (Task 11) so guardrail and conflict payloads reach the screens; a hash precondition gives 409 on stale saves. (10) Delete removes the folder only if it holds nothing but declaration and image files. (11) Origin labels add `Theme: <name>`, because the surveyed sites register types in themes. (12) Parity treats labels as equal modulo terminal punctuation, and OptimizedIT's override carries its `template` and its four "cover image" labels, which are content, not boilerplate. (13) Phase 1 adds no persistent schema cache; the registry memoises per request and `invalidate()` clears it. (14) `Modules\Manager::boot()` runs on `after_setup_theme` 1, not `plugins_loaded`, so a theme's `proto_blocks_modules` filter counts; `proto_post_type()` after `init` 10 is a `declared_too_late` warning (spec: "any time before init priority 9"). (15) `__proto__` keys are refused in files on both sides. (16) A `field_removed` guardrail on the shared write route covers the spec's field-rename warning before Phase 2's builder exists. (17) Template seeds are only `single.html` / `archive.html` (spec section 3), never `index.html`.
- **Names used across tasks:** `Jsonc::decode`, `SchemaCheck::validate`, `Slug::{fromName,error,OVERRIDABLE_TYPES}`, `Labels::{postType,taxonomy}`, `MenuIcon::resolve`, `Declaration`, `Issue`, `FileLoader::{load,MAX_BYTES}`, `Registry::{postTypes,taxonomies,get,doubles,issues,invalidate}`, `PostTypeArgs::{build,override}`, `TaxonomyArgs::{build,override}`, `Manager::{isEnabled,states,postTypes,taxonomies}`, `SafeFile::{isConfined,writeAtomic}`, `SchemaFileWriter::{target,write,delete,hash}`, `WriteException::{errorCode,status,data}`, `PhpDeclarations::seal`, `ContentModelCommands::createDeclaration`, `Guardrails::{checkSave,contentFor}`, `TemplateScaffolder::{plan,scaffold}`, `DeclarationTemplate::render`; TS `ApiError.data`, `parseJsonc`, `applyDeclarationEdits`, `toZod`, `typeSchema`, `taxonomySchema`, `useDeclarationFile` — each defined once and referenced by the same name afterwards.
