# Phase 2: Custom Fields Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ACF-class custom fields on post types, options pages, taxonomy terms and users: ACF-compatible field-group files, sixteen field types stored one meta row per field, React editing boxes in every editor, two-sided validation and conditional logic, an escaping getter with ACF aliases, a Block Bindings source, and a full visual field-group builder that writes the same files.

**Architecture:** A new `Fields` module (`includes/Modules/Fields/`) turns raw field-group arrays (from `proto_field_group()`, the `fields` key of type and taxonomy files, and standalone `proto-fields/<slug>/group.json(c)` files) into normalised definitions, registers one meta key per top-level field with REST schemas and per-user trust callbacks, renders meta boxes that mount a separate React entry (`fields-box`) with real `name` attributes, and saves through the ordinary post, term and user form submit. Reading goes through one `FieldReader` used by `proto_field()`, the ACF aliases, `$template->field()` and the `proto-blocks/field` bindings source. Validation and conditional logic are written once per side (PHP authority, zod mirror) and pinned together by a shared JSON case corpus both test suites run. Sub-phase 2B (Tasks 15–18) builds the Fields admin screen on top, after storage, boxes and getter work from hand-written files.

**Tech Stack:** PHP 8.1, WordPress 6.5+ (floor raised in Task 7), React 18 via `@wordpress/scripts` 37, TypeScript, react-hook-form + zod, shadcn/ui on Radix, Tailwind CSS 4, `@dnd-kit/core` + `@dnd-kit/sortable` (already in `package.json`), `jsonc-parser`, Jest 30 + Testing Library, PHPUnit 10 without WordPress, Playwright on `@wordpress/env`.

**Spec:** `docs/superpowers/specs/2026-11-02-post-types-fields-settings-design.md` (sections "5. Custom fields" in full, "6. Security", "8. Modules", "9. CLI" `fields list|validate`, "10. Phases": Phase 2).

**Execution order and dependencies.** Group A tasks (1–9, 14; Task 8 Steps 1–5 only) need nothing from Phase 0 beyond its merged Task 1 toolchain (Jest already runs) and nothing from Phase 1; they start immediately. Inside group A: 1 → {2, 4}; 2 → 3 → 5 → 6 → 7 → 8 → {9 (also needs 4), 14}; Task 4 runs beside 2, 3 and 5; 9 and 14 run beside each other. Group B tasks (10–13) need **Phase 0 Tasks 1–3 merged** (toolchain, shell, `ROUTE_GROUPS`, `src/admin/screens/index.ts`); Task 13 also needs Task 8 Step 6, hence Phase 1. Group C (Task 8 Step 6, Tasks 15–19) needs **Phase 1 merged** (module manager, declaration registry, schema-file writer and routes, client JSONC helpers). Sub-phase 2B (15–18) starts only after Task 13's E2E suite is green. Task 19 closes the phase.

## Phase 1 contract (consumed, reconcile at preflight)

Phase 1 is planned in parallel (`docs/superpowers/plans/2026-11-02-phase-1-post-types.md`). The names below are taken from that plan. **If the merged Phase 1 code uses different names, the controller renames here, not in Phase 1.** Items marked *Phase 2 adds* do not exist in Phase 1; the named Phase 2 task adds them as the smallest change to Phase 1's file.

- **C1** `ProtoBlocks\Modules\ModuleInterface { public function id(): string; public function boot(): void; }`; `ProtoBlocks\Modules\Manager` with `DEFAULTS = ['post_types' => true, 'taxonomies' => true]`, filter `proto_blocks_modules` (array id ⇒ bool), `isEnabled(string $id)`, `states()`, booted from `Core\Plugin::initializeServices()`. *Phase 2 adds* (Task 8 Step 6): `'fields' => true` in `DEFAULTS`, one boot branch `FieldsModule::instance()->boot()` when enabled, accessor `fields(): ?FieldsModule`.
- **C2** `ProtoBlocks\ContentModel\Jsonc::decode(string $text): array` (object root only; strips `//`, `/* */`, trailing commas; throws `ProtoBlocks\ContentModel\JsoncException` with public `int $line`, `int $column`).
- **C3** `ProtoBlocks\ContentModel\SchemaPaths::roots(): list<string>` (realpaths: parent theme, child theme, then `proto_blocks_schema_paths` additions) and `SchemaPaths::writableRoots(): list<string>` (stylesheet and template dirs only).
- **C4** `ProtoBlocks\ContentModel\Registry` from `Core\Plugin::getInstance()->getContentModel()`: `postTypes(): array<string, Declaration>`, `taxonomies(): array<string, Declaration>`; `Declaration { string $kind; string $slug; array $data; string $origin /* 'file'|'php' */; string $source /* absolute file path, or "<file>:<line>" */; ?string $root; ?string $parentType }`. `data['fields']` holds the raw group arrays from the file or from `proto_post_type(..., ['fields' => ...])` (Phase 1 does not validate them).
- **C5** `ProtoBlocks\ContentModel\SchemaFileWriter::write(string $kind, string $slug, string $text, ?string $expectedHash, ?Declaration $existing): array{path, hash, created, data}` and `delete(string $kind, string $slug, Declaration $existing): array{deleted, kept}`, throwing `WriteException { errorCode, status, data }`; REST `GET|EDITABLE|DELETE proto-blocks/v1/schema-files/(?P<kind>type|taxonomy)/(?P<slug>[a-z][a-z0-9_-]{0,31})`, write body `{text, hash, acknowledge}` → `{path, hash, created, warnings}`, errors `proto_blocks_{file_mods 403, file_too_large 413, invalid_jsonc 400, schema_invalid 400, invalid_slug 400, conflict 409, path_outside_root 403, not_writable 403}`. *Phase 2 adds* (Task 15): `SchemaFileWriter::extendKind(string $kind, string $folder, string $basename, \Closure $validate /* fn(array $data): list<array{path: string, message: string}> */): void`, used for `extendKind('field-group', 'proto-fields', 'group', …GroupValidator…)`; for an extended kind `write()` calls `$validate` instead of `SchemaCheck` and skips `Guardrails`; the route kind regex is built from `type|taxonomy` plus extended kinds. Extended kinds are **not** added to `FileLoader::FOLDERS` (the Registry stays type/taxonomy only). *Phase 2 adds* (Task 8 Step 6): `apply_filters('proto_blocks_declaration_errors', array $errors, string $kind, array $data)` called in `write()` after `SchemaCheck` (non-empty → `schema_invalid` 400) and in the `wp proto-blocks validate` schema-file report.
- **C6** `src/admin/content-model/jsonc.ts`: `parseJsonc(text: string): { data: Record<string, unknown>; errors: { offset: number; message: string }[] }`. Phase 1 has no path setter; Phase 2's own `src/admin/screens/fields/jsonc-edit.ts` (Task 15) provides `setAtPath(text: string, path: (string|number)[], value: unknown): string` (`jsonc-parser` `modify` + `applyEdits`, `formattingOptions: { insertSpaces: true, tabSize: 2 }`, `-1` as the last segment appends to an array, `undefined` removes) and `lineColumn(text: string, offset: number): { line: number; column: number }` (1-based).
- **C7** Phase 1 types `FileResult { exists, path, text, hash, data, writable, reason }` and `ConflictDialog({path, onReload})` from `src/admin/content-model/types.ts` and `src/admin/components/content-model/`; Phase 0 `adminGet/adminPost/adminDelete` and `ApiError {code, status, message, data?}` from `src/admin/api.ts`. Phase 2's own `src/admin/screens/fields/schema-files.ts` (Task 15) wraps them: `type SchemaKind = 'type' | 'taxonomy' | 'field-group'`; `getSchemaFile(kind: SchemaKind, slug: string): Promise<FileResult>`; `putSchemaFile(kind: SchemaKind, slug: string, text: string, hash: string | null): Promise<{ path: string; hash: string; created: boolean }>` (body `{text, hash, acknowledge: []}`); `deleteSchemaFile(kind: SchemaKind, slug: string): Promise<{ deleted: string; kept: string[] }>`.

## Global Constraints

- PHP 8.1+; WordPress floor rises to **6.5** in this phase (Task 7): plugin header `Requires at least: 6.5` and the runtime `version_compare($wp_version, '6.5', '<')` guard in `proto-blocks.php`.
- Field groups mirror ACF local JSON: `key`, `title`, `fields[]` with `key`, `label`, `name`, `type`, `required`, `default_value`, `instructions`, `placeholder`, `conditional_logic`, `sub_fields`, `layouts`, plus group `location`, `position`, `style`, `menu_order`. ACF-only keys are preserved and ignored, never an error.
- Location grammar: `post_type`, `post_status`, `post_taxonomy`, `page_template`, `post`, `taxonomy`, `user_role`, `user_form`, `options_page`; operators `==` and `!=`. A group with any other rule type is **not rendered** and raises a validation warning naming the rule.
- When ACF is active and its registry holds a group with the same `key`, Proto-Blocks skips its copy and shows a notice.
- Storage: one meta row per top-level field; meta key = field `name`; `single => true`; group/repeater/flexible content stored as one JSON-typed value (`object`/`array` REST schema). Terms `register_term_meta`, users `register_meta('user', ...)`, options one option per field named `<page_slug>_<field_name>`, autoloaded unless the page sets `autoload: false`. Post meta gets `revisions_enabled => true` wherever the post type supports `revisions` (WordPress refuses it otherwise). Core exposes post meta in REST only for types that support `custom-fields`, so every post type that receives a field gets that support, and every registered field key is marked protected (`is_protected_meta` filter) so core's raw "Custom Fields" panel never lists or edits it (Task 5).
- Trust is decided at write time per user: `AttributeSanitizer::shouldSanitizeForUser(get_current_user_id())` true → sanitise per type; false → store as given. `auth_callback` denies only the `code` field to users without `unfiltered_html`. The `_proto_blocks_unfiltered` post flag is not involved.
- Getter escapes by default; `$raw = true` returns the stored value. `get_field`, `the_field`, `get_fields` are defined only when ACF is not active.
- Repeater rows capped by `apply_filters('proto_blocks_repeater_max_items', RepeaterField::DEFAULT_MAX_ITEMS /* 500 */, $name, $field)`; a field's own `max` applies when lower. The cap applies to every writer, trusted or not (it is a size limit, not a content filter).
- No PHP is executed from any declaration file; files are decoded data only.
- Every builder write goes through the Phase 1 writer (C5): `manage_options`, REST nonce, `AdminPage::fileModsAllowed()`, `is_writable()`, realpath-prefix path confinement, JSON Schema validation, atomic write.
- Every user-visible string through `__()` / `@wordpress/i18n`, text domain `proto-blocks`. REST errors `new WP_Error('proto_blocks_<code>', __(...), ['status' => N])`.
- Commit messages `<type>(<scope>): <subject>`, no attribution lines of any kind.
- `composer test` and `npm run test:js` pass after every task; `npm run build` after every task touching `src/`.
- PHPUnit stubs for this phase live in `tests/php/Modules/Fields/wp-stubs.php` (owned by Task 1), which first `require_once`s `tests/php/Security/wp-stubs.php` (that file already pulls in `tests/php/Fields/wp-stubs.php`) so the whole process sees one kses/esc emulation; every stub is `function_exists`/`class_exists` guarded and uses the existing globals `pb_test_caps`, `pb_test_current_user`, `pb_test_filters`, `pb_test_options`. A later task that needs a stub Task 1 did not list creates its own `tests/php/Modules/Fields/stubs/<Area>.php` and requires it from its own test; no task after Task 1 edits `wp-stubs.php`.
- `tests/php/bootstrap.php` stubs `esc_html` as identity and `sanitize_text_field` as `trim`; Task 1 replaces both with faithful stand-ins (`htmlspecialchars(…, ENT_QUOTES, 'UTF-8', false)`; strip `<script>`/`<style>` with content, then `strip_tags`, collapse whitespace, trim) and gives the `WP_Block` stub `public array $context = []`. The existing 286 tests pass unchanged with these stubs (checked during plan review). Every escaping assertion in this plan relies on them.

## Performance target (spec: "a post with 50 fields must stay interactive")

Fixture `tests/fixtures/fields/perf-50.json`: one group with exactly 50 top-level fields — 30 `text`, 6 `select`, 4 `true_false`, 2 `number`, 1 `textarea`, 1 `wysiwyg`, 1 `image`, 1 `gallery`, 1 `relationship`, 1 `date_picker`, 1 `repeater` (3 sub-fields, seeded with 5 rows), 1 `flexible_content` (2 layouts, seeded with 3 rows). Measured by Playwright on wp-env, Chromium, block editor, no CPU throttling:

1. **Mount:** `performance.measure('proto-fields:mount', 'proto-fields:start', 'proto-fields:mounted')` ≤ **600 ms**.
2. **Input latency:** typing 40 characters into the 25th text field, each keystroke's time from `keydown` to the next animation frame after the `input` event; **p95 ≤ 50 ms**, max ≤ 100 ms.
3. **Lazy pickers:** no request for the `fields-picker-relationship`, `fields-picker-gallery` or `fields-wysiwyg` chunks before the user opens that field.
4. **Initial bundle:** `assets/js/fields-box.js` ≤ **150 KB** gzip (checked by `scripts/check-fields-bundle-size.mjs` after `npm run build`, locally and as a CI step, Task 12).

The structural guarantee behind (2) is a Jest test: typing in one field re-renders no other field component.

## Review Focus

1. **Untrusted author submits markup and scripts** (`<script>` in text, `<img src=x onerror=…>` in wysiwyg, `javascript:` in url and in a link's `url`, `style=` attributes) through the post form, the term form, the user form and the REST `meta` property: each is sanitised per type; a trusted administrator's identical submission is stored as given and still escaped by the getter. Tests: Task 5 (`testAuthorTextScriptStripped`, `testAuthorWysiwygOnerrorStripped`, `testAuthorUrlAndLinkJavascriptEmptied`, `testTrustedStoredAsGiven`), Task 6 (`get('stream_url')` escapes a trusted `javascript:` value), Task 9 (`SaveHandler` with author caps), Task 13 (E2E as an author).
2. **REST `meta` write by a contributor** (own draft, `POST /wp/v2/<type>/<id>` with `meta`): text fields are sanitised by `sanitize_callback`, a `code` key fails the whole request with 403 `rest_cannot_update`, a repeater over its cap is rejected by the REST schema's `maxItems` (400) and truncated by `normalize` if it reaches the callback. Tests: Task 5 (`testContributorRestTextSanitised`, `testContributorRestCodeDenied`, `testTrustedRepeaterStillCapped`), Task 13 (E2E `contributor REST meta`).
3. **Code field and untrusted users:** an author cannot create, change or clear a `code` value through the form (box renders it read-only and does not submit it) or through REST (`auth_callback` false); an editor saving the post leaves an administrator's stored code untouched; the bindings source never returns a code value. Tests: Task 5 (`testContributorRestCodeDenied`, `testCodeSanitizeKeepsPrevious`), Task 7 (`testCodeFieldNeverBound`), Task 9 (`testUntrustedSaveLeavesCodeUntouched`), Task 12 (read-only render, no `name` attribute), Task 13 (E2E).
4. **Unknown ACF location rule** (`post_format`, `nav_menu_item`, `widget`, an `options_page` rule naming no declared page): the group renders on no screen, registers no meta, and reports a warning naming the rule; it never falls open onto every post. Tests: Task 1 (`testUnknownLocationRuleMakesGroupUnrenderable`), Task 5 (`testUnsupportedNeverMatches`, `MetaRegistrarTest` unknown-rule case), Task 14 (`fields validate` warning).
5. **Oversized repeater** (10 000 rows from a crafted form post, a REST call, or a trusted admin): validation reports `Add no more than %d rows.` before anything is written on the form path; REST is refused by `maxItems`; the stored value never exceeds `maxRows` for any user. Tests: Task 3 (`testRepeaterCappedByFilter`, `testRepeaterNormalizeCapsForEveryUser`), Task 4 (`repeater-over-max-rows`), Task 5 (`testTrustedRepeaterStillCapped`), Task 9 (`testRepeaterOverCapReportsError`).
6. **A failing value never silently disappears:** required-empty, pattern mismatch, out-of-range number, unknown choice, repeater over its cap — the post is saved, the invalid field is not written, its stored value stays, and the submitted value comes back in the box with the error, in the classic editor (reload) and the block editor (after the meta-box save). Tests: Task 4 (corpus), Task 9 (error store round trip), Task 12 (block-editor error fetch), Task 13 (E2E in both editors).
7. **Conditional logic hides a field, and cannot be bypassed:** a hidden field is not validated and not written (its stored value stays), on client and server alike; the server recomputes visibility from submitted values overlaid on stored ones and ignores a value posted for a hidden field (crafted form, JavaScript off); a rule that references a missing field key or carries an invalid regex is false (its AND-group fails, other OR-groups still count) and never throws. Tests: Task 4 (corpus `hidden-required-skipped`, `missing-field-ref`, `invalid-regex-rule`), Task 9 (`testConditionalBypassIgnored`, `testVisibilityUsesStoredSiblingWhenNotSubmitted`), Task 10 (client).
8. **Read paths do not leak:** the bindings source resolves only declared, non-`code` fields, only for posts the visitor may read, only options fields whose page is declared, and honours `"bindings": false`; `proto_field()` on an undeclared name returns `null`. Tests: Task 6, Task 7.
9. **File-declared groups and builder writes stay confined:** a `proto-fields/` folder whose name is not a valid slug (`..`, `Evil Name`), a symlinked `proto-fields/<slug>` pointing outside the theme, a file over 256 KB are skipped at discovery with an error; a builder write with slug `../x`, an absolute path, a symlinked folder, an oversize or invalid group is refused with the Phase 1 error codes and nothing is written; with file mods disabled the builder is read-only. Tests: Task 8 (`testInvalidFolderSlugSkipped`, symlink and size cases), Task 15 (route regex + `FieldGroupWriteTest`), Task 18 (E2E read-only).

---

## File structure

**PHP (create)** — namespace `ProtoBlocks\Modules\Fields`, folder `includes/Modules/Fields/`
- `FieldsModule.php` — `ModuleInterface` (C1) implementation; wires every service below on `boot()`.
- `Definition/Field.php`, `Definition/FieldGroup.php`, `Definition/Location.php` — readonly value objects.
- `Definition/GroupNormalizer.php` — raw ACF-shaped array → `FieldGroup` + warnings; ACF type aliases; preserved extra keys.
- `Definition/GroupValidator.php` — structural errors for a raw group (mirrors `schemas/field-group.schema.json`).
- `Definition/Report.php` — `errors`, `warnings` lists of `{path, code, message}`.
- `Types/FieldTypeInterface.php`, `Types/TypeRegistry.php`, and type classes: `TextType.php` (text, textarea, number, email, url, password), `WysiwygType.php`, `ChoiceType.php` (select, radio, checkbox, button_group), `TrueFalseType.php`, `ColorType.php`, `DateType.php` (date, time, datetime), `LinkType.php`, `CodeType.php`, `ImageType.php`, `FileType.php`, `GalleryType.php`, `VideoType.php`, `TaxonomyType.php`, `RelationshipType.php`, `GroupType.php`, `RepeaterType.php`, `FlexibleContentType.php`, `LayoutType.php` (tab, accordion, message: no storage).
- `Validation/ConditionEvaluator.php`, `Validation/ValueValidator.php`.
- `Storage/ObjectRef.php`, `Storage/Store.php` (interface), `Storage/PostMetaStore.php`, `Storage/TermMetaStore.php`, `Storage/UserMetaStore.php`, `Storage/OptionStore.php`.
- `Registry/GroupIndex.php` — every active group, by location kind and by field name.
- `Registry/LocationMatcher.php`, `Registry/MetaRegistrar.php`, `Registry/TrustCallbacks.php`.
- `Sources/PhpGroups.php` (`proto_field_group()` store), `Sources/DeclarationGroups.php` (type and taxonomy files, C4), `Sources/GroupFiles.php` (`proto-fields/` discovery, C3), `Sources/AcfBridge.php` (ACF detection, same-key skip, ACF groups for listing).
- `Read/ContextResolver.php`, `Read/FieldReader.php`, `Read/Escaper.php`, `Read/Aliases.php` (conditional loader for `aliases.php`).
- `Bindings/FieldBindingSource.php`.
- `Edit/SaveHandler.php`, `Edit/ErrorStore.php`, `Edit/MetaBoxes.php`, `Edit/TermForms.php`, `Edit/UserForms.php`, `Edit/BoxPayload.php`, `Edit/EditRoutes.php` (non-admin REST: errors, relationship search).
- `functions.php` — `proto_field`, `proto_fields`, `proto_rows` (Task 6), `proto_field_group` (Task 8); required from `proto-blocks.php` so a theme can call them before `init`; `aliases.php` — `get_field`, `the_field`, `get_fields`.
- `includes/API/Admin/FieldsRoutes.php` — Fields screen routes (Phase 0 route group); `includes/Admin/FieldsMenu.php` — the "Fields" submenu.
- `includes/CLI/FieldsCommand.php` — `wp proto-blocks fields list|validate`.
- `includes/Template/TemplateHelper.php` — the `$template` object, extracted from `Renderer::executeTemplate`, gains `field()`.

**Shared data (create)**
- `schemas/field-group.schema.json` — JSON Schema (draft 2020-12) for one group or an array of groups.
- `schemas/field-type-aliases.json` — `{ "date_picker": "date", "time_picker": "time", "date_time_picker": "datetime", "color_picker": "color", "checkbox": "select", "button_group": "radio", "post_object": "relationship", "page_link": "relationship", "oembed": "video", "user": "select" }` read by PHP and TS.
- `tests/fixtures/fields/acf-export-realistic.json`, `tests/fixtures/fields/perf-50.json`, `tests/fixtures/fields/validation-cases.json`, `tests/fixtures/fields/groups-invalid/*.json`.

**PHP (modify)** — `tests/php/bootstrap.php` (faithful `esc_html`/`sanitize_text_field`, `WP_Block::$context`; Task 1), `includes/Template/Renderer.php` (use `TemplateHelper`), `includes/API/RestAPI.php` (preview accepts optional `postId`), `proto-blocks.php` (require `functions.php`, Task 6; header and runtime guard 6.5, Task 7), Phase 1 `Modules\Manager` (C1) and `SchemaFileWriter` (C5 additions), `includes/CLI/Commands.php` (one `add_command` line), `includes/Core/Plugin.php` (one `admin_menu` line for `FieldsMenu`), `AdminApp::SCREENS` (add `fields`), `AdminRestController::ROUTE_GROUPS` (add `FieldsRoutes`).

**TS (create)**
- `src/fields/model/types.ts` — `FieldDef`, `LayoutDef`, `GroupDef`, `ConditionRule`, `BoxPayload`; `src/fields/model/normalize.ts` — raw ACF field → `FieldDef` (Task 10).
- `src/fields/validation/conditions.ts`, `src/fields/validation/value-schema.ts`.
- `src/fields/box/index.tsx` (entry), `FieldsForm.tsx`, `names.ts`, `registry.tsx`, `inputs/*.tsx` (one per type family), `pickers/{RelationshipPicker,GalleryPicker,WysiwygEditor}.tsx` (lazy), `editor-save.ts` (block-editor meta-box save hook), `styles.css`.
- `src/admin/screens/FieldsScreen.tsx`, `src/admin/screens/fields/{GroupList,Builder,FieldTree,FieldSettings,AddFieldMenu,ConditionBuilder,LocationBuilder,GroupSettings,JsoncPane}.tsx`, `src/admin/screens/fields/{builder-state,field-settings-spec,jsonc-edit,schema-files}.ts`.
- Tests under `src/fields/__tests__/` and `src/admin/__tests__/fields/`.

**Build (modify/create)** — `webpack.config.js` (entry `fields-box`), `package.json` (`ajv@^8` dev dependency, Task 1; `jsonc-parser` comes from Phase 1), `src/admin/styles/admin.css` → token block moved to `src/admin/styles/tokens.css` (Task 10), `scripts/check-fields-bundle-size.mjs` and one step in `.github/workflows/test.yml` after the build step (Task 12).

**E2E (create)** — `tests/e2e/fields-boxes.spec.ts`, `tests/e2e/fields-frontend.spec.ts`, `tests/e2e/fields-perf.spec.ts`, `tests/e2e/fields-builder.spec.ts`, `tests/e2e/fixtures/themes/pb-e2e-fields/` (block child theme added to `.wp-env.json` `themes` and activated per spec with `requestUtils.activateTheme`, holding `proto-fields/acf-export/group.json`, `proto-fields/perf/group.json`, a `proto-types/event/type.jsonc`, and a Proto-Blocks block whose template calls `$template->field()`), `tests/e2e/fixtures/mu-plugins/proto-classic-editor.php` (Task 12, mapped through `.wp-env.json` `mappings`).

---

### Task 1: Field-group model, ACF normaliser, JSON Schema and fixtures

**Parallel group:** A
**Depends on:** nothing

**Files:**
- Create: `includes/Modules/Fields/Definition/{Field,FieldGroup,Location,GroupNormalizer,GroupValidator,Report}.php`, `schemas/field-group.schema.json`, `schemas/field-type-aliases.json`, `tests/fixtures/fields/acf-export-realistic.json`, `tests/fixtures/fields/perf-50.json`, `tests/fixtures/fields/groups-invalid/*.json`, `tests/php/Modules/Fields/wp-stubs.php`, `tests/php/Modules/Fields/GroupNormalizerTest.php`, `tests/php/Modules/Fields/GroupValidatorTest.php`, `tests/php/Template/StubFidelityTest.php`, `src/fields/model/types.ts`, `src/fields/__tests__/schema.test.ts`
- Modify: `tests/php/bootstrap.php` (see Global Constraints: `esc_html`, `sanitize_text_field`, `WP_Block::$context`), `package.json` + `package-lock.json` (`ajv@^8` dev dependency)

**Interfaces:**
- Produces (PHP):
  ```php
  final class Field {   // readonly public props
      public string $key; public string $name; public string $label; public string $type /* canonical */;
      public string $declaredType /* as written, e.g. date_picker */; public bool $required;
      public mixed $default; public string $instructions; public string $placeholder;
      public array $conditions /* list<list<array{field:string,operator:string,value:mixed}>> */;
      public array $subFields /* list<Field> */; public array $layouts /* list<array{key,name,label,subFields:list<Field>,min?,max?}> */;
      public array $settings /* every other key, ACF-only ones included, untouched */;
      public function setting(string $key, mixed $default = null): mixed;
      public function toArray(): array;   // the FieldDef JSON shape: {key,name,label,type,declaredType,required,default,instructions,placeholder,conditions,subFields,layouts,settings}, recursive
  }
  final class Location { public array $or /* list<list<array{param,operator,value}>> */; public bool $implied; public array $unsupported /* rule params not in the grammar */; public function isRenderable(): bool; }
  final class FieldGroup { public string $key; public string $title; public array $fields; public Location $location;
      public string $position /* normal|side|acf_after_title */; public string $style /* default|seamless */; public int $menuOrder;
      public string $source /* 'php'|'type:<slug>'|'taxonomy:<slug>'|'file:<slug>' */; public ?string $path; public array $raw;
      public function field(string $name): ?Field; public function fieldByKey(string $key): ?Field /* searches nested */;
      public function toArray(): array; }   // {key,title,position,style,fields: FieldDef[]}
  final class GroupNormalizer {
      public const LOCATION_PARAMS = ['post_type','post_status','post_taxonomy','page_template','post','taxonomy','user_role','user_form','options_page'];
      public const PARAM_KINDS = ['post_type'=>'post','post_status'=>'post','post_taxonomy'=>'post','page_template'=>'post','post'=>'post','taxonomy'=>'term','user_role'=>'user','user_form'=>'user','options_page'=>'option'];
      public static function aliases(): array;   // decoded schemas/field-type-aliases.json, memoised
      public static function normalize(array $raw, string $source, ?string $path = null, ?array $impliedLocation = null): array{group: ?FieldGroup, report: Report};
      public static function normalizeMany(array $rawOneOrList, string $source, ?string $path = null, ?array $impliedLocation = null): array{groups: list<FieldGroup>, report: Report}; // accepts one group or an ACF Tools export array
  }
  final class GroupValidator { public static function validate(array $raw, bool $locationImplied): Report; }
  final class Report { public array $errors; public array $warnings; public function addError(string $path, string $code, string $message): void; public function addWarning(...): void; public function merge(Report $o): void; public function ok(): bool; }
  ```
  Canonical types: `text textarea number email url password wysiwyg image file gallery video link select radio true_false color date time datetime taxonomy relationship group repeater flexible_content code tab accordion message`. Aliases from `field-type-aliases.json`; `checkbox` becomes `select` with `settings.multiple = true`; `post_object` and `page_link` become `relationship` with `settings.max = 1` unless `multiple` is truthy; `user` becomes `select` with `settings.optionsSource = 'wp:users'`. An unknown type keeps `type = 'unknown'`, the field is skipped (no storage, no render) and a warning `unknown_type` names it; the rest of the group stays valid.
  Implied location: `['post_type' => 'product']` becomes `[[['param'=>'post_type','operator'=>'==','value'=>'product']]]` and `implied = true`; an explicit `location` in a type file is ignored with warning `location_ignored`. An AND-branch whose supported params map (through `PARAM_KINDS`) to more than one kind gets warning `mixed_location_kinds`; Task 5's `LocationMatcher` makes such a branch match nothing.
- Produces (TS) `src/fields/model/types.ts`: `FieldDef` (exactly `Field::toArray()`), `LayoutDef`, `GroupDef` (exactly `FieldGroup::toArray()`), `ConditionRule = { field: string; operator: '=='|'!='|'==contains'|'==empty'|'!=empty'|'==pattern'; value?: string }`, `BoxPayload` (shape fixed in Task 10).

- [ ] **Step 1: Write the fixture**

`tests/fixtures/fields/acf-export-realistic.json`: an ACF 6 Tools export (top-level array with one group, `key: "group_6543a1b2c3d4e"`, `title: "Event details"`, `location: [[{"param":"post_type","operator":"==","value":"event"}]]`, `position: "acf_after_title"`, `style: "default"`, `label_placement`, `instruction_placement`, `hide_on_screen: ""`, `active: true`, `description`, `show_in_rest: 0`, `modified: 1730000000`). Fields (each with ACF's full key set including `wrapper`, `aria-label`, `return_format`, `ui`, etc.): `tab` "Basics"; `text` `subtitle` with `maxlength: 120`; `textarea` `summary`; `date_picker` `start_date` required with `display_format`/`return_format`; `time_picker` `start_time`; `true_false` `is_online` with `ui: 1`; `url` `stream_url` with `conditional_logic: [[{"field":"<is_online key>","operator":"==","value":"1"}]]`; `select` `audience` with `choices` and `multiple: 1`; `image` `hero` with `return_format: "array"`; `gallery` `photos` with `max: 12`; `link` `register_link`; `color_picker` `accent`; `taxonomy` `topics` with `taxonomy: "category"`, `field_type: "checkbox"`, `save_terms: 1`, `load_terms: 0`; `relationship` `speakers` with `post_type: ["post"]`, `filters: ["search","taxonomy"]`; `repeater` `agenda` with `min: 0`, `max: 20`, `layout: "table"`, `button_label`, sub-fields `time` (time_picker), `title` (text, required), `notes` (wysiwyg); `flexible_content` `sections` with layouts `text_block` (`body` wysiwyg) and `quote` (`quote` textarea, `cite` text), each layout with ACF's `display` and `min`/`max`. 18 fields in all.

`tests/fixtures/fields/groups-invalid/`: `missing-key.json`, `bad-name.json` (`"name": "_hidden"` and `"name": "has space"`), `duplicate-name.json`, `unknown-rule.json` (`param: "post_format"`), `layouts-on-text.json`, `repeater-without-subfields.json`.

`tests/fixtures/fields/perf-50.json`: one group `group_perf50`, location `post_type == post`, with exactly the 50 fields listed under "Performance target", names `f01`…`f50` in that order (the 25th is a `text`), each with a label and no conditions.

- [ ] **Step 2: Write the failing PHPUnit tests**

`tests/php/Modules/Fields/GroupNormalizerTest.php`:
- `testAcfExportLoadsWithoutErrors`: `normalizeMany(json_decode(file(...acf-export-realistic.json)), 'file:acf')` → one group, `report->errors === []`, `warnings === []`; 18 fields; `field('start_date')->type === 'date'`, `->declaredType === 'date_picker'`; `field('audience')->type === 'select'` and `setting('multiple')` truthy; `field('accent')->type === 'color'`.
- `testAcfOnlyKeysPreserved`: `field('hero')->setting('return_format') === 'array'`; `group->raw['hide_on_screen'] === ''`; `field('subtitle')->setting('wrapper')` is the original array.
- `testNestedFieldsNormalised`: `field('agenda')->subFields[1]->name === 'title'` and `->required === true`; `field('sections')->layouts[1]['name'] === 'quote'` with 2 sub-fields; `fieldByKey(<agenda.title key>)` finds the nested field.
- `testUnknownLocationRuleMakesGroupUnrenderable`: `unknown-rule.json` → `location->isRenderable() === false`, `unsupported === ['post_format']`, a warning with code `unsupported_location_rule` whose message contains `post_format`.
- `testImpliedLocationOverridesFileLocation`: a group with an explicit `location` normalised with `impliedLocation ['post_type' => 'product']` → `location->implied === true`, rule value `product`, warning `location_ignored`.
- `testUnknownFieldTypeSkippedWithWarning`: `type: "google_map"` → field absent from `fields`, warning `unknown_type` naming `google_map`, group still returned.
- `testSingleObjectAndArrayBothAccepted`: one group object and `[group]` both yield one group.
- `testMixedLocationKindsWarned`: a branch `[post_type == post AND user_role == all]` → warning `mixed_location_kinds`; a branch `[post_type == post AND post_status == publish]` → none.
- `testToArrayShape`: `field('agenda')->toArray()` has exactly the keys `key,name,label,type,declaredType,required,default,instructions,placeholder,conditions,subFields,layouts,settings` and `subFields[1]['name'] === 'title'`.

`tests/php/Template/StubFidelityTest.php` (guards the bootstrap change): `esc_html('<b>"x"</b>') === '&lt;b&gt;&quot;x&quot;&lt;/b&gt;'`; `esc_html('&amp;')` stays `&amp;`; `sanitize_text_field("<b>x</b><script>a</script>  y ")` === `'x y'`.

`tests/php/Modules/Fields/GroupValidatorTest.php`, data provider over `groups-invalid/*.json` with expected codes: `missing_key`, `invalid_name` (names must match `^[A-Za-z][A-Za-z0-9_-]{0,63}$`), `duplicate_name` (same name twice at one level), `unsupported_location_rule` (warning, not error), `layouts_not_allowed`, `sub_fields_required`; plus `testRealisticExportValid` (no errors).

- [ ] **Step 3: Run to verify failure**

Run: `vendor/bin/phpunit tests/php/Modules/Fields` → FAIL, classes not found.

- [ ] **Step 4: Implement the model, normaliser, validator and stubs**

Update `tests/php/bootstrap.php` as Global Constraints states and add `tests/php/Template/StubFidelityTest.php`; run the whole suite (`composer test`) to confirm no existing test changes outcome.

`tests/php/Modules/Fields/wp-stubs.php`: `require_once __DIR__ . '/../../Security/wp-stubs.php';` then guarded stubs used by Tasks 1–9: `register_meta`, `register_post_meta`, `register_term_meta` (collect into `$GLOBALS['pb_test_meta_reg']`), `get_post_meta`/`update_post_meta`/`delete_post_meta` (update calls the registered `sanitize_callback` like core's `sanitize_meta`), term and user equivalents, `get_option`/`update_option` (on `$GLOBALS['pb_test_options']`), `user_can`, `current_user_can` (reading `$GLOBALS['pb_test_caps'][get_current_user_id()]`), `get_current_user_id`, `is_user_logged_in`, `get_post`, `get_post_type`, `get_post_status`, `get_page_template_slug`, `get_queried_object`, `get_the_ID`, `get_term`, `wp_get_object_terms`, `wp_set_object_terms` (collect), `get_userdata`, `is_post_publicly_viewable`, `post_type_supports`/`add_post_type_support` (on `$GLOBALS['pb_test_supports']`), `use_block_editor_for_post`, `wp_get_attachment_image_src`, `wp_get_attachment_url`, `get_post_mime_type`, `wp_get_attachment_metadata`, `wp_verify_nonce`, `wp_nonce_field`, `wp_json_encode`, `set_transient`/`get_transient`/`delete_transient`, `add_meta_box` (collect), `register_block_bindings_source` (collect), `add_action`/`add_filter` (record `$GLOBALS['pb_test_hooks'][$hook][] = ['callback' => …, 'priority' => …, 'args' => …]`; if Phase 1's `tests/php/ContentModel/stubs/hooks.php` is merged and records another shape, require it instead and adapt the assertions), `get_bloginfo`, `sanitize_hex_color`, `sanitize_textarea_field`, `sanitize_email`, `is_email`, `absint`.

Normaliser keeps every unrecognised key in `settings` (field) or `raw` (group); never throws on bad input — structural problems go to `Report`.

- [ ] **Step 5: Write the JSON Schema and its Jest test**

`schemas/field-group.schema.json`: `oneOf` [group, array of groups]; group requires `key` (`^group_[A-Za-z0-9_]+$` or any non-empty string — ACF keys vary; require non-empty) and `title` and `fields`; field requires `key`, `name`, `type`; `name` pattern `^[A-Za-z][A-Za-z0-9_-]{0,63}$` (empty name allowed only for `tab`, `accordion`, `message`); `additionalProperties: true` everywhere so ACF-only keys pass; `location` array of arrays of `{param, operator: enum ['==','!='], value}` with `param` a free string (unknown params are a warning, not a schema error); documented Proto extensions: `pattern`, `minlength`, `optionsSource`, `bindings` (boolean), `autoload` on options groups.

`src/fields/__tests__/schema.test.ts`: compile the schema with `ajv/dist/2020` (dev dependency, add `ajv@^8` if absent) and assert the realistic export validates, each `groups-invalid/*.json` with an error code (not warning) in Step 2's provider fails, and `unknown-rule.json` passes (warning-only case).

- [ ] **Step 6: Run everything** → `composer test && npm run test:js` PASS.

- [ ] **Step 7: Commit**

```bash
git add includes/Modules/Fields/Definition schemas tests/fixtures/fields tests/php/Modules tests/php/bootstrap.php tests/php/Template/StubFidelityTest.php src/fields package.json package-lock.json
git commit -m "feat(fields): ACF-compatible field-group model, normaliser and JSON Schema"
```

---

### Task 2: Field type registry and scalar types

**Parallel group:** A
**Depends on:** Task 1

**Files:**
- Create: `includes/Modules/Fields/Types/{FieldTypeInterface,TypeRegistry,TextType,WysiwygType,ChoiceType,TrueFalseType,ColorType,DateType,LinkType,CodeType,LayoutType}.php`, `tests/php/Modules/Fields/ScalarTypesTest.php`

**Interfaces:**
- Produces:
  ```php
  interface FieldTypeInterface {
      /** canonical names this class handles */
      public function names(): array;
      public function stores(): bool;                                   // false for tab/accordion/message
      public function restSchema(Field $field): array;                  // JSON Schema for show_in_rest + meta 'type'
      public function sanitize(mixed $value, Field $field): mixed;      // untrusted-user sanitiser; returns the storable shape
      public function normalize(mixed $value, Field $field): mixed;     // shape coercion for every user (types, array keys); no content filtering
      public function escape(mixed $stored, Field $field): mixed;       // getter output
      public function emptyValue(Field $field): mixed;
  }
  final class TypeRegistry {
      public function __construct(iterable $types);
      public static function withCoreTypes(): self;                     // all 16 families incl. Task 3's classes
      public function for(Field $field): FieldTypeInterface;            // throws \InvalidArgumentException for 'unknown'
  }
  ```
  Per type (sanitise / REST `type` / escape):
  - text, password: `sanitize_text_field` / `string` / `esc_html`. textarea: `sanitize_textarea_field` / `string` / `esc_html` then `nl2br` only when `settings.new_lines === 'br'`. number: numeric cast (int when `step` is integral, else float), `''` → `null` / `number` / numeric value. email: `sanitize_email` / `string` with `format: email` / `esc_html`. url: `AttributeSanitizer::sanitizeUrl` / `string` `format: uri` / `esc_url`.
  - wysiwyg: `wp_kses_post` / `string` / `wp_kses_post`.
  - select, radio: membership in `choices` keys (or provider values when `optionsSource`, checked in Task 4) after `sanitize_text_field`; multiple → `list<string>` / `string` (with `enum` = the static `choices` keys when the field has static choices and no `optionsSource`) or `array` of such `string` / `esc_html` per item.
  - true_false: bool / `boolean` / bool.
  - color: `proto_blocks_sanitize_color` / `string` / `proto_blocks_sanitize_color`.
  - date `Y-m-d`, time `H:i:s`, datetime `Y-m-d H:i:s`: parsed with `DateTimeImmutable::createFromFormat` strict round-trip, else `''` / `string` with `format` / `esc_html`.
  - link: `{url, title, target}` with `url` via `sanitizeUrl`, `title` via `sanitize_text_field`, `target` in `['', '_blank']` / `object` / `{url: esc_url, title: esc_html, target: esc_attr, rel: 'noopener noreferrer' when _blank}` (same rel rule as `LinkField::relTokens`).
  - code: `sanitize` returns `null` — the sentinel meaning "refuse" (Task 5 turns it into "keep previous") / `string` / unchanged (a stored code value can only have been written by an `unfiltered_html` user, spec §5 "Trust for field values").
  - tab, accordion, message: `stores() === false`.

- [ ] **Step 1: Write the failing test**

`ScalarTypesTest` with a data provider `[type, settings, input, expectedSanitized, expectedEscaped]`, including: text `<b>x</b><script>a</script>` → sanitised `x` (Task 1's bootstrap stub), escaped `&lt;b&gt;x&lt;/b&gt;&lt;script&gt;a&lt;/script&gt;`; url `javascript:alert(1)` → `''`; email `a@b.co ` → `a@b.co`; number `'12'` step 1 → `12`, `'1.5'` step 0.5 → `1.5`, `''` → `null`; wysiwyg `<p onclick="x">a</p><script>b</script>` → `<p>a</p>b`; select `['a','zz']` with choices `{a,b}` multiple → `['a']`; true_false `'1'` → `true`, `''` → `false`; color `#FFF` → `#FFF`, `red;}` → `''`; date `2026-02-30` → `''`, `2026-02-28` kept; time `25:00:00` → `''`; link `{url:'javascript:x', title:'<i>t</i>', target:'_top'}` → `{url:'', title:'t', target:''}`; code anything → `null`.
- `testRestSchemaTypes`: `restSchema` of number is `['type' => 'number']`, select multiple with choices `{a, b}` is `['type'=>'array','items'=>['type'=>'string','enum'=>['a','b']]]`, select with `optionsSource` has no `enum`, link is an `object` with `properties` url/title/target and `additionalProperties: false`.
- `testLinkEscapeAddsRelForBlank`: `{url:'https://x.test', title:'T', target:'_blank'}` escapes with `rel === 'noopener noreferrer'`; target `''` has no `rel`.
- `testRegistryRejectsUnknown`: `for()` on a field of type `unknown` throws `\InvalidArgumentException`.

- [ ] **Step 2: Run to verify failure** — `vendor/bin/phpunit tests/php/Modules/Fields/ScalarTypesTest.php` → FAIL.

- [ ] **Step 3: Implement the interface, registry and scalar types.** `withCoreTypes()` lists this task's classes; Task 3 appends its own.

- [ ] **Step 4: Run** → PASS; `composer test` PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/Modules/Fields/Types tests/php/Modules/Fields/ScalarTypesTest.php
git commit -m "feat(fields): field type registry with scalar type sanitisers and escapers"
```

---

### Task 3: Media, relational and nested types

**Parallel group:** A
**Depends on:** Task 2

**Files:**
- Create: `includes/Modules/Fields/Types/{ImageType,FileType,GalleryType,VideoType,TaxonomyType,RelationshipType,GroupType,RepeaterType,FlexibleContentType}.php`, `tests/php/Modules/Fields/StructuredTypesTest.php`
- Modify: `includes/Modules/Fields/Types/TypeRegistry.php` (append the nine classes to `withCoreTypes()`)

**Interfaces:**
- Consumes: `FieldTypeInterface`, `TypeRegistry` (Task 2).
- Produces: nested types take the registry in their constructor and recurse into sub-fields: `GroupType::sanitize` sanitises each sub-field by its type and drops keys that are not sub-field names; `RepeaterType` does the same per row. `RepeaterType::maxRows(Field $f): int` = `min($f->setting('max') ?: PHP_INT_MAX, (int) apply_filters('proto_blocks_repeater_max_items', RepeaterField::DEFAULT_MAX_ITEMS, $f->name, $f->settings))`; truncation to `maxRows` happens in `normalize()` (which runs for every user, trusted or not) and is the last line of defence — Task 4 reports the excess as an error before anything is written on the form path; flexible content uses the same cap. `FlexibleContentType` rows are `{layout: string, ...values}`, a row whose `layout` is not a declared layout name is dropped.
  Stored shapes and escapes:
  - image: `int` / `integer` / `{id, url, alt, width, height, sizes: {<size>: {url,width,height}}}` with `esc_url` on every URL and `esc_attr` on `alt`; missing attachment → `null`.
  - file: `int` / `integer` / `{id, url, filename, mime, size}`.
  - gallery: `list<int>` (unique, positive) / `array` of `integer` / list of image arrays; cap `settings.max`.
  - video: `int|string` (`absint` or `sanitizeUrl`) / `['type' => ['integer','string']]` / `{id?, url}`.
  - taxonomy: `list<int>` / `array` of `integer` / list of `{id, name: esc_html, slug, url: esc_url}`; terms not in `settings.taxonomy` dropped at escape time.
  - relationship: `list<int>` order kept / `array` of `integer` / list of `{id, title: esc_html, url: esc_url, type}` for posts the current visitor may read (`is_post_publicly_viewable` or `current_user_can('read_post', $id)`); single (`max === 1`) returns one item or `null`.
  - group: `object` with `properties` from sub-fields, `additionalProperties: false`.
  - repeater: `array` of that object, `maxItems` = `maxRows`.
  - flexible_content: `array` of `oneOf` per layout with `layout` `const`.

- [ ] **Step 1: Write the failing test**

`StructuredTypesTest`:
- `testRepeaterSanitisesRowsAndDropsUnknownKeys`: sub-fields `title` (text) and `link` (url); row `{title:'<b>A</b>', link:'javascript:1', extra:'x'}` → `{title:'A', link:''}`.
- `testRepeaterCappedByFilter`: filter returns 3, field `max` 20, 5 rows in → 3 out; field `max` 2 → 2 out.
- `testRepeaterNormalizeCapsForEveryUser`: `normalize()` (no sanitising) of 600 rows with no filter and no field `max` → 500 rows.
- `testFlexibleDropsUndeclaredLayout`.
- `testGroupEscapeRecurses`: group with wysiwyg and url sub-fields escapes both.
- `testImageEscapeShape` with stubbed attachment 7: keys `id,url,alt,width,height,sizes`; URL passed through `esc_url`.
- `testRelationshipHidesUnreadablePosts`: IDs `[3, 4]` where 4 is a private post and the visitor lacks `read_post` → only post 3 escaped.
- `testGalleryUniquePositiveCapped`: `[5, '5', -1, 'x', 6]` with `max: 1` → `[5]`.
- `testRestSchemaRepeater`: `maxItems` equals `maxRows`, `items.additionalProperties === false`.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement the nine classes.** Reuse `ImageType` escape for gallery items.

- [ ] **Step 4: Run** → PASS; `composer test` PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/Modules/Fields/Types tests/php/Modules/Fields/StructuredTypesTest.php
git commit -m "feat(fields): media, relational and nested field types"
```

---

### Task 4: Value validation and conditional logic (PHP) with the shared case corpus

**Parallel group:** A
**Depends on:** Task 1

**Files:**
- Create: `includes/Modules/Fields/Validation/{ConditionEvaluator,ValueValidator}.php`, `tests/fixtures/fields/validation-cases.json`, `tests/php/Modules/Fields/ValidationCorpusTest.php`

**Interfaces:**
- Produces:
  ```php
  final class ConditionEvaluator {
      /** ACF semantics: OR of AND-groups. Empty conditions → visible. A rule whose field key is unknown is false. */
      public static function visible(Field $field, array $siblingValuesByKey): bool;
      public static function rule(string $operator, mixed $actual, mixed $expected): bool;
      // operators: '==' equals (arrays: contains), '!=' , '==contains' substring/member, '==empty', '!=empty', '==pattern' (PCRE built as '/' . str_replace('/', '\/', $v) . '/u', invalid pattern → false)
  }
  final class ValueValidator {
      /** @param array $input name => submitted value (already type-normalised) @return array{valid: array<string,mixed>, errors: array<string,string>, hidden: list<string>} */
      public static function validate(array $fields /* list<Field> */, array $input, ?callable $choiceResolver = null, ?callable $maxRows = null): array;
  }
  ```
  Rules: `required` (empty string, `null`, `[]`, `false` for true_false all count empty); `maxlength`, `minlength` (Proto ext.), `pattern` (Proto ext.), `min`/`max` on number, `min`/`max` rows on repeater/flexible/gallery/relationship, choice membership (static `choices`, or `$choiceResolver($field)` returning allowed values for `optionsSource`), email via `is_email`, url must survive `sanitizeUrl` unchanged, date/time formats, repeater row count over `maxRows`. Nested errors use dotted paths: `agenda.2.title`. A field hidden by its conditions is in `hidden`, not validated and not in `valid`. Error messages are fixed strings (copy below) so Jest can assert the same text.
  Copy: `This field is required.`, `Enter at most %d characters.`, `Enter at least %d characters.`, `The value does not match the required format.`, `Enter a number no less than %s.`, `Enter a number no greater than %s.`, `Add at least %d rows.`, `Add no more than %d rows.`, `Choose one of the listed options.`, `Enter a valid email address.`, `Enter a valid URL.`, `Enter a valid date.`.
- Produces (data) `validation-cases.json`: `[{ "id": string, "fields": [raw ACF field], "input": {...}, "maxRows"?: {name: int}, "choices"?: {name: [allowed values]}, "valid": [names], "errors": {path: message}, "hidden": [names] }]` (`maxRows`/`choices` feed the two optional callables; absent → field `max` / static `choices` only), consumed again by Task 10's Jest test.

- [ ] **Step 1: Write the corpus** — at least 30 cases, ids including `required-empty-string`, `required-false-truefalse`, `maxlength-120`, `pattern-sku`, `number-min-max`, `select-unknown-choice`, `select-multiple-partial`, `email-bad`, `url-javascript`, `date-feb-30`, `repeater-min-rows`, `repeater-over-max-rows`, `repeater-nested-required` (error path `agenda.1.title`), `flexible-unknown-layout`, `hidden-required-skipped`, `shown-required-enforced`, `contains-operator`, `empty-operator`, `not-empty-operator`, `pattern-operator`, `invalid-regex-rule`, `missing-field-ref`, `or-groups`, `and-group`, `nested-condition-in-repeater-row` (sibling scope is the row).

- [ ] **Step 2: Write the failing test** — `ValidationCorpusTest` data provider over the corpus: normalise `fields` with `GroupNormalizer`, run `ValueValidator::validate`, assert `array_keys(valid)`, `errors`, `hidden` equal the case exactly.

- [ ] **Step 3: Run to verify failure** → FAIL.

- [ ] **Step 4: Implement both classes.**

- [ ] **Step 5: Run** → PASS; `composer test` PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/Modules/Fields/Validation tests/fixtures/fields/validation-cases.json tests/php/Modules/Fields/ValidationCorpusTest.php
git commit -m "feat(fields): server-side value validation and conditional logic with a shared case corpus"
```

---

### Task 5: Stores, location matching, meta registration and trust callbacks

**Parallel group:** A
**Depends on:** Tasks 1, 2, 3

**Files:**
- Create: `includes/Modules/Fields/Storage/{ObjectRef,Store,PostMetaStore,TermMetaStore,UserMetaStore,OptionStore}.php`, `includes/Modules/Fields/Registry/{GroupIndex,LocationMatcher,MetaRegistrar,TrustCallbacks}.php`, `tests/php/Modules/Fields/{LocationMatcherTest,MetaRegistrarTest,TrustCallbacksTest,StoresTest}.php`

**Interfaces:**
- Produces:
  ```php
  final class ObjectRef { public string $kind /* post|term|user|option */; public int $id; public string $subtype /* post type, taxonomy, '' */; public string $page /* options page slug */;
      public static function post(int $id): self; public static function term(int $id, string $taxonomy): self; public static function user(int $id): self; public static function option(string $page): self; }
  interface Store { public function get(ObjectRef $ref, Field $f): mixed; public function set(ObjectRef $ref, Field $f, mixed $value): void; public function has(ObjectRef $ref, Field $f): bool; }
  // OptionStore: option name "<page>_<name>", autoload from FieldGroup->raw['autoload'] ?? true
  final class GroupIndex {
      public function __construct(array $groups /* list<FieldGroup> */);
      public function all(): array;
      public function forRef(ObjectRef $ref, array $ctx = []): array;   // renderable groups matching the object; $ctx as LocationMatcher::matches (built from $ref when empty)
      public function fieldFor(ObjectRef $ref, string $name): ?array{field: Field, group: FieldGroup};
      public function subtypesFor(FieldGroup $g, string $kind): array;                  // ['product'] or [''] (all)
  }
  final class LocationMatcher {
      /** $ctx keys: post_type, post_status, post_id, page_template, terms (taxonomy => slugs), taxonomy, user_roles, user_form ('add'|'edit'|'register'), options_page */
      public static function matches(Location $loc, array $ctx): bool;   // false when !isRenderable()
      public static function kinds(Location $loc): array;                // subset of post|term|user|option the rules can match
  }
  final class TrustCallbacks {
      public function __construct(TypeRegistry $types);
      public function sanitizeCallback(Field $f, Store $store): callable;   // fn($value, $metaKey, $objectType, ...) 
      public function authCallback(Field $f, string $kind): callable;       // fn($allowed, $metaKey, $objectId, $userId)
  }
  final class MetaRegistrar {
      public function __construct(GroupIndex $index, TypeRegistry $types, TrustCallbacks $trust);
      public function register(): void;          // on init priority 20
      public function registered(): array;       // [kind, subtype, metaKey] triples, for tests and the CLI
  }
  ```
  Location rules: `post_type` against the post's type; `post_status`; `post_taxonomy` value `category:news` (taxonomy:slug) against the post's terms; `page_template` (`default` = none); `post` (ID); `taxonomy` against a term's taxonomy (`all` matches any); `user_role` (`all` matches any); `user_form` (`all|add|edit|register`); `options_page` (a page slug not passed in `$ctx` matches nothing). `kinds()` maps each branch through `GroupNormalizer::PARAM_KINDS` (Task 1); a branch mixing kinds matches nothing (Task 1 already reports `mixed_location_kinds`).
  Registration per stored top-level field and per subtype: post → `register_post_meta($subtype, $name, $args)` (subtype `''` when a branch has no `post_type ==` rule), term → `register_term_meta($taxonomy, ...)`, user → `register_meta('user', ...)`; `$args = ['type' => <scalar REST type or 'object'/'array'>, 'single' => true, 'default' => <default_value normalised>, 'show_in_rest' => ['schema' => restSchema], 'sanitize_callback' => …, 'auth_callback' => …]` plus, for post meta, `'revisions_enabled' => $subtype !== '' && post_type_supports($subtype, 'revisions')`. For each concrete post subtype that receives a field, `add_post_type_support($subtype, 'custom-fields')` when missing (core only exposes post meta in REST for such types; a `''` subtype follows each type's own support). One `is_protected_meta` filter returns `true` for every registered field name (by meta type), so core's raw Custom Fields panel never lists or edits these keys. Options fields are not meta; `OptionStore` handles them. Fields named the same in two groups on the same subtype: the first group (by `menu_order`, then source order) wins and a warning `duplicate_meta_key` is reported.
  `sanitizeCallback`: `$user = get_current_user_id(); $v = $type->normalize($value, $f); return AttributeSanitizer::shouldSanitizeForUser($user) ? $type->sanitize($v, $f) ?? <previous stored value> : $v;` — for `code` the `null` sentinel returns the previous stored value, so no write path can change it.
  `authCallback`: code → `user_can($userId, 'unfiltered_html') && <edit cap>`; others → the edit cap (`edit_post`, `edit_term`, `edit_user`, `manage_options` for options) for `$objectId`.

- [ ] **Step 1: Write the failing tests**

- `LocationMatcherTest`: table-driven over each rule param with `==` and `!=`; `post_taxonomy` `category:news`; `user_form` `register` matches `register` and not `edit`; `testUnsupportedNeverMatches` (location with `post_format` and a matching `post_type` branch → false); `testOptionsPageUnknownNeverMatches`; `testMixedKindsBranchNeverMatches`; `testOrOfAnd`.
- `MetaRegistrarTest`: from the realistic export, with `event` supporting `revisions` → `register_post_meta('event', 'subtitle', …)` with `type: string`, `single: true`, `revisions_enabled: true`; with `event` not supporting `revisions` → `revisions_enabled: false`; `add_post_type_support('event', 'custom-fields')` recorded; `apply_filters('is_protected_meta', false, 'subtitle', 'post') === true` and `… 'unrelated_key' …` stays `false`; `agenda` registered with `type: array` and the repeater schema; `tab` field not registered; group from `unknown-rule.json` registers nothing; a `taxonomy == product_cat` group registers term meta; a `user_role == all` group registers `user` meta; `testDuplicateNameFirstWins`.
- `TrustCallbacksTest` (author = user 2 with caps `['edit_posts','edit_post']`; contributor = user 3 with `['edit_posts','edit_post']` on its own draft; admin = user 1 with `unfiltered_html`):
  - `testAuthorTextScriptStripped`: text callback as author on `<b>Hi</b><script>x()</script>` → `Hi`.
  - `testAuthorWysiwygOnerrorStripped`: wysiwyg `<p>a</p><img src="x" onerror="alert(1)">` as author → result contains `<p>a</p>` and no `onerror`, no `<script`.
  - `testAuthorUrlAndLinkJavascriptEmptied`: url `javascript:alert(1)` → `''`; link `{url:'javascript:alert(1)', title:'<i>t</i>', target:'_blank'}` → `{url:'', title:'t', target:'_blank'}`.
  - `testTrustedStoredAsGiven`: the same three inputs as admin come back byte-identical.
  - `testCodeSanitizeKeepsPrevious`: `code` sanitize callback as author with previous stored value `'<script>ok()</script>'` returns that previous value; as admin returns the new value.
  - `testContributorRestTextSanitised`: `authCallback(text)(false, 'subtitle', 10, 3)` true; sanitize callback as user 3 strips `<script>`.
  - `testContributorRestCodeDenied`: `authCallback(code)` false for users 2 and 3, true for user 1.
  - `testTrustedRepeaterStillCapped`: filter cap 3, repeater sanitize callback as admin with 4 rows → 3 rows.
- `StoresTest`: `OptionStore` writes `theme-settings_phone` with autoload true, and false when `raw['autoload'] === false`; post/term/user stores round-trip arrays unchanged.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement.** `GroupIndex` memoises per request; nothing here touches the filesystem.

- [ ] **Step 4: Run** → PASS; `composer test` PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/Modules/Fields/Storage includes/Modules/Fields/Registry tests/php/Modules/Fields
git commit -m "feat(fields): location matching, one-row meta registration and write-time trust"
```

---

### Task 6: Getter API, ACF aliases and `$template->field()`

**Parallel group:** A
**Depends on:** Task 5

**Files:**
- Create: `includes/Modules/Fields/Read/{ContextResolver,FieldReader,Escaper,Aliases}.php`, `includes/Modules/Fields/functions.php`, `includes/Modules/Fields/aliases.php`, `includes/Template/TemplateHelper.php`, `tests/php/Modules/Fields/{FieldReaderTest,AliasesTest}.php`, `tests/php/Template/TemplateHelperTest.php`
- Modify: `includes/Template/Renderer.php` (replace the anonymous `$template` class with `new TemplateHelper($attributes, $block)`), `includes/API/RestAPI.php` (`/preview` accepts optional integer `postId`; when present and `current_user_can('edit_post', $postId)`, the preview renders with `setup_postdata`/global `$post` for that ID), `proto-blocks.php` (one `require_once PROTO_BLOCKS_DIR . 'includes/Modules/Fields/functions.php';` after the `Core/Autoloader.php` require, so themes can call the functions at any time)

**Interfaces:**
- Produces:
  ```php
  final class ContextResolver { public static function resolve(mixed $context): ?ObjectRef; }
  // null → queried object (singular post → post; term archive → term; author archive → user), int|WP_Post → post,
  // 'option:<page>', 'term:<id>', 'user:<id>', 'post:<id>'; WP_Term/WP_User objects accepted; anything else → null
  final class FieldReader {
      public function __construct(GroupIndex $index, TypeRegistry $types, array $stores /* kind => Store */);
      public function get(string $name, mixed $context = null, bool $raw = false): mixed;  // undeclared name → null (+ _doing_it_wrong when WP_DEBUG)
      public function all(mixed $context = null, bool $raw = false): array;                // every stored field of every matching group, name => value
      public function rows(string $name, mixed $context = null): iterable;                 // repeater/flexible rows, each escaped; [] for other types
      public static function setShared(?FieldReader $reader): void;                        // set by FieldsModule::boot() (Task 8); tests set it directly
      public static function shared(): ?FieldReader;                                       // null when the Fields module is off or not booted
  }
  final class Aliases {
      /** requires aliases.php once when !$acfActive() and get_field is undefined; returns whether it loaded */
      public static function load(callable $acfActive): bool;
  }
  final class TemplateHelper {
      public function __construct(array $attrs, ?\WP_Block $block = null);
      public function has_value(string $name): bool; public function get(string $name, mixed $default = null): mixed;  // unchanged behaviour
      public function field(string $name, bool $raw = false): mixed;   // context: $block->context['postId'] ?? get_the_ID() ?: null
  }
  ```
  `functions.php` (each guarded by `function_exists`): `proto_field(string $name, $context = null, bool $raw = false)`, `proto_fields($context = null, bool $raw = false): array`, `proto_rows(string $name, $context = null): iterable`, each forwarding to `FieldReader::shared()` and returning `null` / `[]` / `[]` when it is null. Task 8 appends `proto_field_group()` to this file. Unset value → the field's `default_value` normalised and escaped.
  `aliases.php` defines, inside `if (!function_exists('get_field'))` blocks: `get_field($selector, $post_id = false, $format_value = true)` → `proto_field($selector, $post_id ?: null, !$format_value)`; `the_field($selector, $post_id = false)` → echoes the escaped value (scalars as is, lists of scalars joined with `, `, arrays with a `url` key echo the URL, other arrays nothing); `get_fields($post_id = false, $format_value = true)`. ACF's `$post_id` strings `option`/`options` map to `null` + `_doing_it_wrong` (no default page), `term_<id>`/`<tax>_<id>` → `term:<id>`, `user_<id>` → `user:<id>`. Task 8 calls `Aliases::load([AcfBridge::class, 'active'])` on `plugins_loaded` priority 20.

- [ ] **Step 1: Write the failing tests**

- `FieldReaderTest` (index from the realistic export, post 10 of type `event`, meta preset in `$GLOBALS`): `get('subtitle', 10)` of stored `<b>Hi</b>` → `&lt;b&gt;Hi&lt;/b&gt;`; `get('subtitle', 10, true)` → `<b>Hi</b>`; `get('stream_url', 10)` of `javascript:x` stored by a trusted user → `''` (escape still applies); `get('hero', 10)` → array with `url`; `get('nope', 10)` → `null`; `rows('agenda', 10)` yields 2 rows with escaped `title`; `rows('subtitle', 10)` yields nothing; context strings `option:theme-settings`, `term:5`, `user:3` route to the matching store; unset `is_online` returns its `default_value` `false`.
- `AliasesTest` (`@runInSeparateProcess`, `FieldReader::setShared()` with the test reader): `Aliases::load(fn() => false)` returns true and defines `get_field`; `get_field('subtitle', 10, false)` equals raw; `the_field('audience', 10)` echoes `a, b`; `Aliases::load(fn() => true)` returns false and defines nothing; with `get_field` pre-defined by a stub, `Aliases::load(fn() => false)` returns false (no fatal) and the stub's return is unchanged; with `FieldReader::setShared(null)`, `proto_field('subtitle', 10)` returns `null` and `proto_rows('agenda', 10)` is empty.
- `TemplateHelperTest`: `field('subtitle')` with a `WP_Block` stub whose `context['postId'] = 10` returns the escaped value; without a block falls back to `get_the_ID()` stub; `get()`/`has_value()` behave exactly as before (port the existing assertions from `tests/php/Template/BlockContextTest.php` if they cover them).

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement**, then run the existing `tests/php/Template/*` to prove the Renderer change is behaviour-preserving.

- [ ] **Step 4: Run** → `composer test` PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/Modules/Fields/Read includes/Modules/Fields/functions.php includes/Modules/Fields/aliases.php includes/Template includes/API/RestAPI.php proto-blocks.php tests/php
git commit -m "feat(fields): escaping getter, ACF aliases and \$template->field()"
```

---

### Task 7: Block Bindings source `proto-blocks/field` and the 6.5 floor

**Parallel group:** A
**Depends on:** Task 6

**Files:**
- Create: `includes/Modules/Fields/Bindings/FieldBindingSource.php`, `tests/php/Modules/Fields/FieldBindingSourceTest.php`, `src/fields/bindings/register.ts` (editor-side source registration so the editor shows the bound label), `src/fields/__tests__/bindings.test.ts`
- Modify: `proto-blocks.php` (header `Requires at least: 6.5`; the runtime guard `version_compare($wp_version, '6.3', '<')` and its notice text become `6.5`), `README.md` requirements line, `src/editor/index.tsx` (one import of `register.ts`)

**Interfaces:**
- Produces:
  ```php
  final class FieldBindingSource {
      public const NAME = 'proto-blocks/field';
      public function __construct(FieldReader $reader, GroupIndex $index);
      public function register(): void;   // register_block_bindings_source(NAME, ['label' => __('Proto-Blocks field','proto-blocks'), 'get_value_callback' => [$this,'value'], 'uses_context' => ['postId','postType']]) on init
      public function value(array $args, \WP_Block $block, string $attribute): ?string;
  }
  ```
  `$args`: `name` (required), `context` (optional, same grammar as the getter; default `$block->context['postId']`), `key` (optional sub-key for array values, e.g. `alt`). Resolution: declared field only; `code` never; field setting `bindings === false` → `null`; post context → only when `is_post_publicly_viewable($id)` or `current_user_can('read_post', $id)`; options context → only fields declared on that options page. Attribute mapping: image/file values → `url` for `url`, `alt` for `alt`, `id` for `id`; link → `url` for `url`, `title` for `text`/`content`; scalars → the escaped string; arrays without a mapping → `null`.
  `register.ts`: `registerBlockBindingsSource({ name: 'proto-blocks/field', label, getValues: ({ bindings }) => placeholders "{name}" })` guarded by `typeof registerBlockBindingsSource === 'function'` (WordPress 6.7+ editor API; on 6.5–6.6 the server source still works).

- [ ] **Step 1: Write the failing tests**

`FieldBindingSourceTest`: `register()` records the source with `uses_context ['postId','postType']`; paragraph `content` bound to `subtitle` (stored `<b>Hi</b>`) on public post 10 → `&lt;b&gt;Hi&lt;/b&gt;`; same on private post 11 for a visitor → `null`, for user with `read_post` → value; `testCodeFieldNeverBound`: a `code` field with a stored value → `null` for an administrator too; field with `bindings: false` → `null`; image `hero` bound to `core/image` `url` → URL, `alt` → alt; `context: 'term:5'` with a field from a `taxonomy == category` group → the term meta value; `context: 'option:theme-settings'` with a field declared on that page → value, a meta field name not on that page → `null`; undeclared name → `null`. `testVersionGuard`: `proto-blocks.php` text contains `Requires at least: 6.5` and `'6.5', '<'` (a string assertion on the file).

`bindings.test.ts`: with a mocked `@wordpress/blocks` exposing `registerBlockBindingsSource`, the source is registered once with the right name; when the function is absent nothing throws.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement**; bump the header, the runtime guard and README.

- [ ] **Step 4: Run** → `composer test && npm run test:js && npm run build` PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/Modules/Fields/Bindings proto-blocks.php README.md src/fields src/editor/index.tsx tests/php
git commit -m "feat(fields): proto-blocks/field block bindings source; require WordPress 6.5"
```

---

### Task 8: Group sources, ACF bridge and the Fields module

**Parallel group:** A (Steps 1–5), C (Step 6)
**Depends on:** Tasks 1, 5, 6, 7; Step 6 on Phase 1 merged (C1–C5)

**Files:**
- Create: `includes/Modules/Fields/FieldsModule.php`, `includes/Modules/Fields/Sources/{PhpGroups,DeclarationGroups,GroupFiles,AcfBridge}.php`, `tests/php/Modules/Fields/{SourcesTest,AcfBridgeTest,FieldsModuleTest}.php`, `tests/php/Modules/Fields/fixtures/theme/proto-fields/{acf-export,commented}/group.json(c)`
- Modify: `includes/Modules/Fields/functions.php` (append `proto_field_group`); Step 6 only: Phase 1 `includes/Modules/Manager.php` (C1 addition), Phase 1 `includes/ContentModel/SchemaFileWriter.php` and the `validate` schema-file report in `includes/CLI/` (the `proto_blocks_declaration_errors` call, C5 addition)

**Interfaces:**
- Consumes: Steps 1–5 consume nothing from Phase 1 — every Phase 1 service is injected as a callable and tests pass fakes (a test-local comment-stripping decoder, `stdClass` declarations). Step 6 consumes C1–C5.
- Produces:
  ```php
  final class PhpGroups {   // static store, like Phase 1's PhpDeclarations, so theme calls before boot are kept
      public static function add(string $target, array $group, string $source): void;   // $source "<file>:<line>" from debug_backtrace
      public static function all(): array;   // list<array{target, group, source}> raw; normalised by the module
      public static function reset(): void;  // tests only
  }
  // $target: a post type slug (implied post_type location) or 'options:<page>', 'taxonomy:<tax>', or '' when the group carries its own location
  // functions.php gains: proto_field_group(string $target, array $group): void → PhpGroups::add($target, $group, <caller file:line>)
  final class DeclarationGroups {
      /** @param callable(): iterable<object{kind: string, slug: string, data: array, origin: string, source: string}> $declarations  C4 Declarations */
      public function __construct(callable $declarations);
      public function all(): array;   // list<FieldGroup>; kind 'type' → implied post_type == slug, source 'type:<slug>'; kind 'taxonomy' → implied taxonomy == slug, source 'taxonomy:<slug>'; path = declaration source when origin 'file'
  }
  final class GroupFiles {
      public const FOLDER = 'proto-fields';
      public const MAX_BYTES = 262144;   // 256 KB per file
      public const SLUG_PATTERN = '/^[a-z][a-z0-9_-]{0,31}$/';   // same as the Phase 1 route slug regex
      public function __construct(callable $roots /* C3 */, callable $decode /* C2: fn(string): array, throws */);
      public function all(): array;                          // list<FieldGroup>, child root wins on same folder slug
      public function errors(): Report;                      // invalid folder slugs, decode errors, oversize files, symlinks escaping the root
  }
  final class AcfBridge {
      public static function active(): bool;                 // class_exists('ACF') && function_exists('acf_get_field_group')
      public function filterDuplicates(array $groups): array; // drops groups whose key ACF already knows, records them for the notice
      public function skipped(): array;
      public function acfGroups(): array;                    // [{key,title,locationSummary}] for the Fields list, read-only
  }
  final class FieldsModule /* implements ModuleInterface — added in Step 6, when C1 exists */ {
      public function __construct(?callable $roots = null, ?callable $decode = null, ?callable $declarations = null);   // a null source is skipped
      public static function instance(): self;   // Steps 1–5: new self() (PHP groups only); Step 6: wired to C3, C2, C4
      public function id(): string;   // 'fields'
      public function boot(): void;   // FieldReader::setShared(reader); hooks MetaRegistrar::register (init 20), FieldBindingSource::register (init 10), Aliases::load([AcfBridge::class,'active']) (plugins_loaded 20), the AcfBridge notice (admin_notices); adds the 'proto_blocks_declaration_errors' filter for kinds 'type', 'taxonomy', 'field-group'. Tasks 9 and 12 each add their own hook lines here.
      public function index(): GroupIndex; public function reader(): FieldReader; public function types(): TypeRegistry; public function stores(): array; public function report(): Report;
  }
  ```
  Group order in the index: PHP groups, then type/taxonomy file groups, then `proto-fields` files; same group `key` from two sources → the file wins, warning `duplicate_group_key` naming both sources (mirrors the spec's double-declaration rule). The index is built lazily on first use after `init` 10 (ACF has loaded its local groups by then) and cached for the request; no persistent cache in this phase (files are few and decoded once per request; Task 13 measures it).
  File handling in `GroupFiles`: only `<root>/proto-fields/<slug>/group.jsonc` or `group.json` (`.jsonc` wins); a folder name failing `SLUG_PATTERN` is skipped with error `invalid_slug`; `realpath()` of the folder and of the file must start with `realpath($root) . DIRECTORY_SEPARATOR`; files over `MAX_BYTES` skipped with error `file_too_large`; decode through the injected decoder only — never `include`.

- [ ] **Step 1: Write the failing tests**

- `SourcesTest`: `PhpGroups::add('product', [...], 'functions.php:3')` → implied `post_type == product`, source `php`; `GroupFiles` over the fixture theme finds both groups, decodes the commented `.jsonc`; a symlink `proto-fields/evil -> <tmp>/outside` (created in `setUp`) is skipped with an error; `testInvalidFolderSlugSkipped`: folders `Evil Name` and `x..y` (created in `setUp`) are skipped with `invalid_slug`; a 300 KB file is skipped with `file_too_large`; `DeclarationGroups` with a fake list (`[(object) ['kind' => 'type', 'slug' => 'event', 'origin' => 'file', 'source' => '/t/proto-types/event/type.jsonc', 'data' => ['fields' => [group]]]]`) yields one group with implied location `event` and `path` `/t/proto-types/event/type.jsonc`; `testFileBeatsPhpOnSameKey` with warning `duplicate_group_key`.
- `AcfBridgeTest`: without ACF `active()` false and nothing filtered; with stub `class ACF {}` and `acf_get_field_group($key)` returning a group for `group_6543a1b2c3d4e` → that group dropped and listed in `skipped()`.
- `FieldsModuleTest`: `boot()` with stubs registers `init` (20, MetaRegistrar), `init` (10, bindings), `plugins_loaded` (20); `FieldReader::shared()` is the module's reader; `id() === 'fields'`; the `proto_blocks_declaration_errors` filter adds an error for a type declaration whose `fields[0]` lacks `key`, and a warning for `unknown-rule.json` as a `field-group`; `proto_field_group('product', [...])` after `PhpGroups::reset()` makes `index()->all()` contain the group.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement the sources, bridge, `proto_field_group` and module** (module constructs services lazily; `boot()` never touches the filesystem before `init`).

- [ ] **Step 4: Run** → PASS; `composer test` PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/Modules/Fields tests/php/Modules/Fields
git commit -m "feat(fields): field group sources, ACF bridge and the Fields module"
```

- [ ] **Step 6 (group C, after Phase 1 merges): Wire Phase 1 and register the module**

Tests first, in `FieldsModuleTest` and Phase 1's `tests/php/Modules/ManagerTest.php`: `Manager::DEFAULTS['fields'] === true`; a `proto_blocks_modules` filter returning `['fields' => false]` → `fields() === null`, no `init` 20 hook from Fields, `FieldReader::shared() === null`, and `post_types` still boots; `FieldsModule::instance()` sources read `SchemaPaths::roots()`, decode with `Jsonc::decode`, and list `getContentModel()->postTypes() + taxonomies()`; `SchemaFileWriter::write('type', …)` with a `fields[0]` lacking `key` throws `WriteException` `schema_invalid` (the filter call). Then add `implements \ProtoBlocks\Modules\ModuleInterface` to `FieldsModule`, and implement the C1 and C5 additions named in the contract section (Manager default + boot branch + `fields()`; the filter call in `write()` and in the validate report), and the admin notice for `AcfBridge::skipped()` on Proto-Blocks screens and post edit screens: `Proto-Blocks skipped the field group "%s" because Advanced Custom Fields already registers the same key.` Verify on wp-env: `wp proto-blocks validate` runs and a `proto-fields/` group appears in `wp eval 'var_dump(count(\ProtoBlocks\Modules\Fields\FieldsModule::instance()->index()->all()));'`. `composer test` PASS. Commit `feat(fields): wire the Fields module into the module manager and the declaration writer`.

---

### Task 9: Save path, error store and edit-time REST routes

**Parallel group:** A
**Depends on:** Tasks 4, 5, 8 (Steps 1–5)

**Files:**
- Create: `includes/Modules/Fields/Edit/{SaveHandler,ErrorStore,EditRoutes,ChoiceResolver}.php`, `tests/php/Modules/Fields/{SaveHandlerTest,ErrorStoreTest,EditRoutesTest}.php`
- Modify: `includes/Modules/Fields/FieldsModule.php` (hook lines for `SaveHandler` and `EditRoutes`), `tests/php/Modules/Fields/FieldsModuleTest.php` (one test method for those hooks)

**Interfaces:**
- Consumes: `ValueValidator::validate` (Task 4), `GroupIndex`, `TypeRegistry`, `Store`, `ObjectRef` (Task 5), `FieldsModule` (Task 8), the existing `Controls\OptionsProviders::resolve(string $name, array $args): list<array{key,label}>` via `Core\Plugin::getInstance()->getOptionsProviders()`.
- Produces:
  ```php
  final class ChoiceResolver {   // the one place optionsSource choices are resolved, reused by BoxPayload (Task 12)
      public function __construct(callable $resolve /* fn(string $source, array $args): list<array{key,label}> */);
      public function options(Field $f): ?array;     // list<array{key,label}> for optionsSource fields (args = setting 'sourceArgs'), null for static choices; unknown source → []
      public function allowed(Field $f): array;      // keys only; the $choiceResolver passed to ValueValidator
  }
  final class SaveHandler {
      public const NONCE_ACTION = 'proto_fields_save';
      public const NONCE_FIELD = '_proto_fields_nonce';
      public const INPUT = 'proto_fields';          // $_POST['proto_fields'][<group key>][<name>] ...
      public const PRESENT = 'proto_fields_present'; // $_POST['proto_fields_present'][<group key>][] = <name> for every rendered, editable field
      public function __construct(GroupIndex $index, TypeRegistry $types, array $stores, ErrorStore $errors, ChoiceResolver $choices);
      public function savePost(int $postId, \WP_Post $post): void;   // save_post, priority 10
      public function saveTerm(int $termId, int $ttId, string $taxonomy): void;   // created_term, edited_term
      public function saveUser(int $userId): void;                   // user_register, personal_options_update, edit_user_profile_update
      public function save(ObjectRef $ref, array $groups, array $input, array $present, array $ctx): array{written: list<string>, errors: array<string,string>};  // shared core, also used by Phase 3 options pages
  }
  final class ErrorStore {
      public function put(ObjectRef $ref, int $userId, array $errors, array $submitted): void;   // transient "proto_fields_err_<kind>_<id|page>_<user>", 300 s
      public function take(ObjectRef $ref, int $userId): ?array{errors: array, submitted: array};  // read and delete
      public function peek(ObjectRef $ref, int $userId): ?array;
  }
  final class EditRoutes {
      public function registerRoutes(): void;
      // GET proto-blocks/v1/fields/errors?object=post:<id>  → peek() then take(); permission edit_post for that id
      // GET proto-blocks/v1/fields/relationship?group=<key>&field=<name>&search=&page=1 → {items:[{id,title,type,status}], total, pages}
      //     filters read from the declaration only (post_type list → each must be a registered type with show_ui; taxonomy filter; per_page 20);
      //     permission: edit_posts; results limited to posts the user can read_post
  }
  ```
  `save()` algorithm: for each group matching `$ctx` and each top-level field listed in `$present[group key]` (fields not present are untouched — this is how an untrusted user's read-only code field and fields of non-rendered groups survive): normalise the submitted value by type (`''`/missing nested arrays → `emptyValue`); build the validator input as **stored values overlaid with the submitted present values**, so visibility is recomputed on the server from what the post will hold, never from a client flag; run `ValueValidator::validate($fields, $input, [$choices, 'allowed'], [RepeaterType::class, 'maxRows'])` per group; hidden fields are skipped (not validated, not written, even when a value was posted for them); invalid fields are not written; `taxonomy` fields with `save_terms` call `wp_set_object_terms($postId, $ids, $taxonomy)` when `current_user_can($taxonomy->cap->assign_terms)`; valid fields are written through `Store::set` (meta functions, so the registered `sanitize_callback` runs). Errors plus the submitted values for the invalid fields go to `ErrorStore`. `savePost` returns early on autosave, revision, missing/invalid nonce, or `!current_user_can('edit_post', $postId)`; `saveTerm` needs a valid nonce and `edit_term`; `saveUser` needs a valid nonce and `edit_user`, except on `user_register` from the public registration form (`!is_user_logged_in()`), where only groups whose location matches `user_form == register` (or `all`) are saved and every value is sanitised (user 0 is untrusted).
  `FieldsModule::boot()` gains: `save_post` (10, 2), `created_term`/`edited_term` (10, 3), `user_register`/`personal_options_update`/`edit_user_profile_update` (10, 1), `rest_api_init` → `EditRoutes::registerRoutes`; `ChoiceResolver` built from `Core\Plugin::getInstance()->getOptionsProviders()->resolve(...)`.

- [ ] **Step 1: Write the failing tests**

`SaveHandlerTest` (realistic export group, post 10 type `event`, valid nonce stub):
- `testValidSubmissionWritesEachFieldOnce`: `update_post_meta` called once per present field with the meta key = name.
- `testInvalidFieldNotWrittenPostStillSaved`: `start_date` required and empty → no write for `start_date`, other fields written, `ErrorStore` holds `start_date => 'This field is required.'` and the submitted `''`.
- `testHiddenFieldSkipped`: `is_online = 0`, `stream_url = 'not a url'` → no error, `stream_url` not written, previous value stays.
- `testConditionalBypassIgnored`: `is_online = 0` and `stream_url = 'https://evil.test'` both present (a crafted post) → `stream_url` not written, stored value unchanged.
- `testVisibilityUsesStoredSiblingWhenNotSubmitted`: stored `is_online = true`, `is_online` absent from `PRESENT`, `stream_url = 'not a url'` present → error `Enter a valid URL.` (the field is visible because of the stored sibling); with stored `is_online = false` → no error, not written.
- `testFieldsNotPresentUntouched`: a field omitted from `PRESENT` keeps its stored value.
- `testOptionsSourceChoiceEnforced`: a select with `optionsSource: 'wp:posts'` and a `ChoiceResolver` fake returning keys `['3','4']` → `'4'` written, `'99'` → `Choose one of the listed options.`
- `testRegistrationFormSavesSanitised`: logged out, valid nonce, `saveUser(7)` from `user_register` with a `user_form == register` group → text value written with `<script>` stripped; a `user_role == all` group without a `user_form` rule is not written.
- `testUntrustedSaveLeavesCodeUntouched`: author user, group with a `code` field present in input with `<script>evil()</script>` and stored `<script>ok()</script>` → stored value unchanged.
- `testRepeaterOverCapReportsError`: filter cap 3, 4 rows → error `Add no more than 3 rows.`, nothing written for `agenda`.
- `testSaveTermsAssignsTerms`: `topics` `[4, 5]` → `wp_set_object_terms(10, [4,5], 'category')` recorded.
- `testNonceAndCapsGuard`: bad nonce or missing `edit_post` → nothing written; autosave → nothing written.
- `testTermAndUserPaths`: `saveTerm` writes term meta for a `taxonomy == category` group; `saveUser` writes user meta for `user_role == all`.

`ErrorStoreTest`: `put` then `peek` returns, `take` returns and clears, keyed per user (user 2 cannot read user 1's).

`EditRoutesTest`: route registration paths and methods; `relationship` with a declaration whose `post_type` is `['post']` ignores a client-supplied `post_type=page` param; `errors` for a post the user cannot edit → 403 `proto_blocks_forbidden`.

`FieldsModuleTest::testSaveHooks`: after `boot()`, `save_post`, `edited_term`, `user_register` and `rest_api_init` are hooked.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement**, including the hook lines in `FieldsModule::boot()`.

- [ ] **Step 4: Run** → PASS; `composer test` PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/Modules/Fields/Edit includes/Modules/Fields/FieldsModule.php tests/php/Modules/Fields
git commit -m "feat(fields): post, term and user save path with validation errors that survive the save"
```

---

### Task 10: Box runtime — form, scalar inputs, names, client validation and conditions

**Parallel group:** B
**Depends on:** Phase 0 Tasks 1–2 merged; Task 4 (corpus); Task 1 (`types.ts`)

**Files:**
- Create: `src/fields/model/normalize.ts`, `src/fields/validation/{conditions,value-schema}.ts`, `src/fields/box/{index.tsx,FieldsForm.tsx,names.ts,registry.tsx,styles.css}`, `src/fields/box/inputs/{TextInputs,ChoiceInputs,TrueFalseInput,ColorInput,DateInputs,LinkInput,CodeInput,LayoutFields}.tsx`, `src/admin/styles/tokens.css`, `src/fields/__tests__/{normalize,corpus,conditions,names,form,render-isolation}.test.tsx`
- Modify: `webpack.config.js` (entry `'fields-box': './src/fields/box/index.tsx'`), `src/admin/styles/admin.css` (move the shadcn token block into `tokens.css` and `@import` it; selectors become `#proto-blocks-admin, #proto-blocks-admin-portal, .proto-blocks-fields`)

Look and copy: `.superpowers/sdd/2026-11-02-phase-0-admin-rebuild/design/phase-1-3/field-boxes.html` (with its `mock.js`) and the tokens and rules in `design/phase-0/design-guide.md`; behaviour in this plan wins over the mockup.

**Interfaces:**
- Consumes: Phase 0 shadcn components `@/admin/components/ui/*`, `PortalProvider`, react-hook-form + zod; `validation-cases.json`; `schemas/field-type-aliases.json`.
- Produces:
  ```ts
  // normalize.ts — the client twin of GroupNormalizer's field rules (aliases, checkbox → select multiple, post_object/page_link → relationship max 1, user → select wp:users, unknown → skipped)
  export function normalizeField(raw: Record<string, unknown>): FieldDef | null;
  // conditions.ts
  export function isVisible(field: FieldDef, siblingsByKey: Record<string, unknown>): boolean;
  // value-schema.ts
  export function buildValueSchema(fields: FieldDef[], opts?: { maxRows?: (f: FieldDef) => number; choices?: (f: FieldDef) => string[] | null }): z.ZodType;  // uses superRefine so hidden fields are skipped and error paths/messages match PHP exactly
  export const MESSAGES: Record<string, string>;  // the Task 4 copy, same strings
  // names.ts
  export function inputName(groupKey: string, path: (string|number)[]): string;   // ['agenda', 2, 'title'] → 'proto_fields[group_x][agenda][2][title]'
  export const PRESENT_NAME = (groupKey: string) => `proto_fields_present[${groupKey}][]`;
  // registry.tsx
  export type FieldInput = React.ComponentType<{ field: FieldDef; path: (string|number)[]; groupKey: string; readOnly?: boolean }>;
  export function inputFor(type: string): FieldInput | React.LazyExoticComponent<FieldInput>;
  // FieldsForm.tsx
  export function FieldsForm(props: { payload: BoxPayload }): JSX.Element;
  // index.tsx: finds every [data-proto-fields-box] element, reads its <script type="application/json"> payload, mounts FieldsForm; performance.mark('proto-fields:start') before, 'proto-fields:mounted' after the last root commits
  ```
  `BoxPayload = { groupKey: string; fields: FieldDef[]; values: Record<string, unknown>; display: Record<string, unknown> /* pre-resolved media/relationship/term data, Task 12 */; choices: Record<string, { key: string; label: string }[]> /* optionsSource fields, by name */; errors: Record<string, string>; canUnfiltered: boolean; maxRows: Record<string, number>; restRoot: string; restNonce: string; object: string /* 'post:10' */ }`.
  Every input is uncontrolled-by-default via `register`/`useController` scoped to its own path and renders a real `name` attribute from `inputName`; `true_false` renders a hidden `0` before the checkbox `1`; empty multi-value fields render a hidden empty input so the key is submitted. Hidden-by-condition fields stay mounted but `hidden` (so their values remain in the form) and their `PRESENT` entry is removed. `code` with `canUnfiltered === false` renders a read-only `<pre>` with no `name` and no `PRESENT` entry. Validation runs `onBlur` and on submit attempt; errors show inline under the input (`role="alert"`, `aria-describedby`); server `errors` from the payload show on mount. The box never blocks the form submit.
  Watching for conditions: `useWatch` only on the field keys referenced by some `conditional_logic`, never the whole form.

- [ ] **Step 1: Write the failing tests**

- `normalize.test.ts`: over `acf-export-realistic.json`, `start_date` → `type 'date'`, `declaredType 'date_picker'`; `audience` → `select` with `settings.multiple` truthy; the `tab` field kept as `tab`; `google_map` → `null`; the result for `agenda` deep-equals the keys of PHP's `Field::toArray()` (`key,name,label,type,declaredType,required,default,instructions,placeholder,conditions,subFields,layouts,settings`).
- `corpus.test.tsx`: for every case in `tests/fixtures/fields/validation-cases.json`, `buildValueSchema(case.fields.map(normalizeField), { maxRows, choices })` (`maxRows` and `choices` callables built from `case.maxRows` / `case.choices`) parse result yields exactly `case.errors` (path joined with `.` → message) and treats `case.hidden` as skipped.
- `conditions.test.ts`: the six operators, OR/AND, unknown key false, invalid regex false.
- `names.test.ts`: nested repeater and flexible paths; `_layout` key for flexible rows.
- `form.test.tsx`: payload with text `subtitle` → input has `name="proto_fields[group_x][subtitle]"` and a hidden `proto_fields_present[group_x][]` = `subtitle`; toggling `is_online` shows `stream_url` and adds its present entry; blur on empty required `start_date` shows `This field is required.`; payload `errors: {start_date: 'Enter a valid date.'}` shows on mount; `code` with `canUnfiltered: false` renders the stored text, no element with `name` containing `code`; `true_false` submits `0` when unchecked (assert via `new FormData(form)`).
- `render-isolation.test.tsx`: 50-field payload from `perf-50.json`; a render counter injected through `registry` test override; typing 10 characters into field 25 increments only field 25's counter.

- [ ] **Step 2: Run to verify failure** — `npm run test:js -- src/fields` → FAIL.

- [ ] **Step 3: Implement** the modules and the token move; `styles.css` imports `tailwindcss/theme.css`, `tailwindcss/utilities.css` (important), `../../admin/styles/tokens.css`, `@source "../**/*.{ts,tsx}"` and `@source "../../admin/components/ui/*.tsx"`; base rules scoped to `.proto-blocks-fields`, no preflight.

- [ ] **Step 4: Run** → `npm run test:js && npm run build` PASS; `assets/js/fields-box.js` and `assets/css/fields-box.css` emitted; Phase 0 admin Jest suites still pass after the token move.

- [ ] **Step 5: Commit**

```bash
git add src/fields src/admin/styles webpack.config.js
git commit -m "feat(fields): React field box runtime with scalar inputs, client validation and conditions"
```

---

### Task 11: Box runtime — media, relational and nested inputs with lazy pickers

**Parallel group:** B
**Depends on:** Task 10; Task 9 (`fields/relationship` route)

**Files:**
- Create: `src/fields/box/inputs/{MediaInputs,TaxonomyInput,RelationshipInput,GroupInput,RepeaterInput,FlexibleInput,WysiwygInput}.tsx`, `src/fields/box/pickers/{RelationshipPicker,GalleryPicker,WysiwygEditor}.tsx`, `src/fields/__tests__/{nested,pickers}.test.tsx`
- Modify: `src/fields/box/registry.tsx` (map the new types)

**Interfaces:**
- Consumes: `FieldInput`, `inputName`, `buildValueSchema` (Task 10); `GET fields/relationship` (Task 9).
- Produces: lazy chunks with webpack names `fields-picker-relationship`, `fields-picker-gallery`, `fields-wysiwyg` (`import(/* webpackChunkName: "..." */ ...)`), loaded on first open/focus/visibility-click only.
  - image/file/video: `wp.media` frame (single), thumbnail + remove; video also accepts a URL text input. Hidden input carries the ID or URL.
  - gallery: thumbnails (from `payload.display` pre-resolved by the server, Task 12), sortable with `@dnd-kit/sortable`; "Add images" opens `GalleryPicker` (lazy, `wp.media` multiple); one hidden `name="...[photos][]"` per ID.
  - relationship: selected list (sortable, one hidden input per ID), "Add" opens `RelationshipPicker` (lazy): debounced (250 ms) search against the route, paged, keyboard navigable, respects `max`.
  - taxonomy: checkbox list or select by `field_type`, from `payload.display` pre-resolved terms (no request); relationship and image/file selections also start from `payload.display`.
  - group: sub-fields inline. repeater: rows with add (disabled at `maxRows`), remove, drag reorder (`@dnd-kit/sortable`), collapse; `useFieldArray`; `min` rows rendered initially. flexible_content: "Add section" menu of layouts, rows labelled by layout, `_layout` hidden input per row.
  - wysiwyg: renders the stored HTML in a sandboxed preview (`dangerouslySetInnerHTML` of the server-escaped value from the payload) with an "Edit" button; on click lazy-loads `WysiwygEditor`, which calls `wp.editor.initialize(id, { tinymce: { wpautop: true }, quicktags: true, mediaButtons: true })` and syncs the textarea (real `name`) on change; `wp.editor.remove(id)` on unmount.

- [ ] **Step 1: Write the failing tests**

- `nested.test.tsx`: repeater add/remove/reorder produces contiguous bracket names (`[agenda][0]`, `[agenda][1]`) in `FormData`; add disabled at `maxRows.agenda`; flexible "Add section → Quote" yields `[sections][0][_layout]=quote`; nested required `agenda.1.title` error renders under that row's input.
- `pickers.test.tsx`: no dynamic import of the relationship picker until "Add" is clicked (spy on a mocked module factory); picker search calls `apiFetch` with `path: '/proto-blocks/v1/fields/relationship?group=...&field=speakers&search=ab&page=1'` once after 250 ms (fake timers); selecting two results adds two hidden inputs in order; `max: 1` replaces instead of appending; wysiwyg: `wp.editor.initialize` is not called until "Edit" is clicked (global stub).

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement.**

- [ ] **Step 4: Run** → `npm run test:js && npm run build` PASS; build output contains the three named chunks.

- [ ] **Step 5: Commit**

```bash
git add src/fields
git commit -m "feat(fields): media, relationship, repeater and flexible inputs with lazy pickers"
```

---

### Task 12: Meta boxes, term and user forms, block-editor save integration

**Parallel group:** B
**Depends on:** Tasks 9, 10; runs beside Task 11 (no shared file); the Step 4 manual check runs after Task 11 merges

**Files:**
- Create: `includes/Modules/Fields/Edit/{MetaBoxes,TermForms,UserForms,BoxPayload}.php`, `src/fields/box/editor-save.ts`, `tests/php/Modules/Fields/{MetaBoxesTest,BoxPayloadTest}.php`, `src/fields/__tests__/editor-save.test.ts`, `scripts/check-fields-bundle-size.mjs`, `tests/e2e/fixtures/mu-plugins/proto-classic-editor.php` (filters `use_block_editor_for_post` to `false` while option `proto_test_classic_editor` is `1`)
- Modify: `includes/Modules/Fields/FieldsModule.php` (hook the three classes), `tests/php/Modules/Fields/FieldsModuleTest.php` (one test method), `.github/workflows/test.yml` (one step `node scripts/check-fields-bundle-size.mjs` after the build step), `.wp-env.json` (`mappings`: `wp-content/mu-plugins/proto-classic-editor.php` → the fixture)

**Interfaces:**
- Consumes: `ChoiceResolver`, `ErrorStore`, `SaveHandler::{NONCE_ACTION,NONCE_FIELD}` (Task 9); `FieldReader` (Task 6); `BoxPayload` TS shape (Task 10).
- Produces:
  ```php
  final class BoxPayload {
      public function __construct(TypeRegistry $types, FieldReader $reader, ErrorStore $errors, ChoiceResolver $choices);
      public function build(FieldGroup $g, ObjectRef $ref, int $userId): array;   // BoxPayload JSON shape (Task 10): fields = FieldDef[] via Field::toArray(); values raw for trusted users, sanitised-for-display for untrusted; display = pre-resolved image/gallery/relationship/taxonomy data; choices = ChoiceResolver::options() for optionsSource fields; submitted values from ErrorStore override stored ones for invalid fields
  }
  final class MetaBoxes {
      public function register(string $postType, \WP_Post $post): void;   // add_meta_boxes: one box per matching group, id 'proto-fields-<group key>'; position normal → context 'normal', side → 'side'; acf_after_title → context 'normal' priority 'high' when use_block_editor_for_post($post), else context 'proto_after_title' rendered by do_meta_boxes(null, 'proto_after_title', $post) on edit_form_after_title
      public function render(\WP_Post $post, array $box): void;           // <div class="proto-blocks-fields" data-proto-fields-box><script type="application/json">payload</script><noscript>…</noscript></div> + nonce once per screen
      public function enqueue(string $hook): void;                         // only on post.php/post-new.php with ≥1 box: fields-box script+style, wp_enqueue_media(), wp_enqueue_editor() when a wysiwyg exists; prints #proto-blocks-admin-portal once in admin_footer
  }
  final class TermForms { public function register(): void; }  // {$tax}_add_form_fields, {$tax}_edit_form_fields (table row layout), enqueue on edit-tags.php/term.php
  final class UserForms { public function register(): void; }  // show_user_profile, edit_user_profile, user_new_form, register_form (user_form rule decides); enqueue on profile.php/user-edit.php/user-new.php and, for register-form groups, login_enqueue_scripts
  ```
  Payload JSON is printed with `wp_json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)`. The `<noscript>` fallback lists the group title and "JavaScript is required to edit these fields."; it contains no inputs (so a no-JS submit leaves values untouched because `PRESENT` is absent).
  `editor-save.ts`: `subscribeToMetaBoxSave(onSaved: () => void)` uses `wp.data.subscribe` on `select('core/edit-post').isSavingMetaBoxes()` true→false; on completion fetches `GET fields/errors?object=post:<id>` and pushes errors into each mounted form; also re-reads values from the response so the box shows what was stored. On the term add form, listen for jQuery `ajaxSuccess` of action `add-tag` and remount empty forms.

- [ ] **Step 1: Write the failing tests**

- `MetaBoxesTest`: on an `event` post in the block editor the realistic group (`acf_after_title`) registers one `add_meta_box` with id `proto-fields-group_6543a1b2c3d4e`, context `normal`, priority `high`; with `use_block_editor_for_post` false the same group uses context `proto_after_title` and an `edit_form_after_title` hook is added; a `side` group uses `side`; an unsupported-location group registers none; with `AcfBridge` skipping the key, none; `render()` output contains `data-proto-fields-box`, one `_proto_fields_nonce`, and, when a value contains `</script>`, the payload script contains `</script>` and no literal `</script>` before the closing tag.
- `BoxPayloadTest`: author user + stored code → payload `canUnfiltered: false` and the code value present for display; error store entry → `errors` and `values.start_date` equal the submitted invalid value; image field payload carries `display.hero = {id, url, alt}`; a select with `optionsSource: 'wp:posts'` gets `choices.<name>` from a `ChoiceResolver` fake.
- `FieldsModuleTest::testBoxHooks`: after `boot()`, `add_meta_boxes`, `admin_enqueue_scripts`, `category_add_form_fields` (for a category group) and `show_user_profile` are hooked.
- `editor-save.test.ts`: simulated store transition calls the errors endpoint once and dispatches errors to the form registry; no request when no box is mounted.
- `scripts/check-fields-bundle-size.mjs` (a script, not a `*.test.mjs`, because `test:scripts` runs before the build in CI): exits 1 with the measured size when `assets/js/fields-box.js` is missing or its gzip size exceeds 150 × 1024 bytes, else prints the size and exits 0.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement**, including the mu-plugin fixture, the `.wp-env.json` mapping and the CI step.

- [ ] **Step 4: Run** → `composer test && npm run test:js && npm run build && node scripts/check-fields-bundle-size.mjs` PASS. Manual on wp-env: with `npx wp-env run tests-cli wp option update proto_test_classic_editor 1` (classic) and `0` (block editor), both save the realistic group; the term and user screens mount and save a hand-written group.

- [ ] **Step 5: Commit**

```bash
git add includes/Modules/Fields src/fields tests/php/Modules/Fields scripts/check-fields-bundle-size.mjs tests/e2e/fixtures/mu-plugins .github/workflows/test.yml .wp-env.json
git commit -m "feat(fields): meta boxes, term and user forms mounting the React field box"
```

---

### Task 13: E2E, performance and visual tests for boxes and the front end (done-criterion check, part 1)

**Parallel group:** B
**Depends on:** Tasks 7, 11, 12; Task 8 Step 6; Phase 0 E2E infrastructure

**Files:**
- Create: `tests/e2e/fixtures/themes/pb-e2e-fields/**` (block child theme, `Template: twentytwentyfive`: `style.css`, `functions.php` with `proto_field_group('event', [...one side group "Developer" with a `code` field `snippet`...])`, `proto-fields/acf-export/group.json` = byte-for-byte copy of `tests/fixtures/fields/acf-export-realistic.json`, `proto-fields/perf/group.json` = copy of `perf-50.json`, `proto-fields/term-extra/group.json` (`taxonomy == category`, one text `term_note`), `proto-fields/user-extra/group.json` (`user_role == all`, one text `user_note`), `proto-types/event/type.jsonc` declaring `Event` with no inline fields, `proto-blocks/event-card/{block.json,template.php}` whose template prints `$template->field('subtitle')` and loops `proto_rows('agenda')`, `templates/single-event.html` with a `core/paragraph` bound to `proto-blocks/field` `{name: 'subtitle'}`), `tests/e2e/fields-boxes.spec.ts`, `tests/e2e/fields-frontend.spec.ts`, `tests/e2e/fields-perf.spec.ts`
- Modify: `.wp-env.json` (add `./tests/e2e/fixtures/themes/pb-e2e-fields` to `themes`; `wp-env` has no `--config` flag), `tests/e2e/README.md` (the Fields specs activate `pb-e2e-fields` themselves)

**Interfaces:**
- Consumes: everything from Tasks 1–12; `@wordpress/e2e-test-utils-playwright` `admin`, `editor`, `requestUtils`, `page`.

- [ ] **Step 1: Write the specs**

Every Fields spec file activates the theme in `beforeAll` with `requestUtils.activateTheme('pb-e2e-fields')` and restores the previous theme in `afterAll`.

`fields-boxes.spec.ts`:
- `ACF export loads and edits unchanged (block editor)`: assert the fixture file in the theme equals the repo fixture (sha1 via `wp-env run tests-cli sha1sum`), create an `event`, fill `subtitle`, `start_date`, toggle `is_online` and see `stream_url` appear, add two `agenda` rows, add a `quote` section, pick an image via the media modal (upload a fixture PNG through `requestUtils.uploadMedia` first), save; reload; every value is back; `wp post meta get <id> agenda --format=json` shows two rows.
- `same, classic editor` with the mu-plugin toggle.
- `validation error survives the save`: clear required `start_date`, save → post saved (status published/draft persists), inline `This field is required.` shown in the box after the meta-box save; in classic editor after reload.
- `author cannot store markup or change code`: create user `author1`; as admin create an `event` authored by `author1` with `snippet` = `<script>ok()</script>`; log in as `author1`, type `<script>alert(1)</script>Hi` into `subtitle` and `javascript:alert(1)` into `stream_url`, save → stored `subtitle` (via `wp post meta get`) contains no `<script`, `stream_url` empty; the Developer box shows `<script>ok()</script>` read-only and `snippet` is unchanged after save.
- `contributor REST meta`: create user `contributor1` and a draft `event` they own; with their application password, `POST /wp/v2/event/<id>` with `meta: { subtitle: '<script>x</script>ok' }` → 200 and stored `subtitle` contains no `<script`; with `meta: { snippet: 'x' }` → 403 `rest_cannot_update`, stored `snippet` unchanged; with `meta: { agenda: <21 rows> }` (the fixture's `max` is 20, so the schema's `maxItems` is 20) → 400 `rest_invalid_param`.
- `term and user forms`: the `term-extra` group renders on `edit-tags.php?taxonomy=category` (add form, AJAX add stores `term_note`, the form resets) and on `term.php`; the `user-extra` group renders on `profile.php` and saves `user_note`.
- Visual: `toHaveScreenshot('fields-box-block-editor.png')` of the realistic box (mask the media thumbnail), `fields-box-classic.png`, `fields-term-form.png`.

`fields-frontend.spec.ts`:
- the single `event` page shows the `subtitle` through the bound paragraph and the `event-card` block shows `subtitle` and both agenda titles, all HTML-escaped (a stored `<b>` appears as text, verified by `textContent` including `<b>`).
- a private event's bound paragraph shows nothing to a logged-out visitor.
- with the `perf` group: `get_field('subtitle', <id>)` via `wp eval` equals `proto_field` output (ACF inactive).

`fields-perf.spec.ts` — the four targets from "Performance target": read `performance.getEntriesByName('proto-fields:mount')[0].duration` ≤ 600; keystroke latency measured with `page.evaluate` installing `keydown`/`input` listeners and `requestAnimationFrame` timestamps, p95 ≤ 50 and max ≤ 100 over 40 keystrokes in field 25; `page.on('request')` records no URL containing `fields-picker-relationship`, `fields-picker-gallery` or `fields-wysiwyg` until the relationship "Add" button is clicked, after which exactly the relationship chunk loads. Retries: the perf spec runs with `test.describe.configure({ retries: 2 })` and records the measured numbers via `test.info().annotations` so a regression shows the values.

- [ ] **Step 2: Run** — `npm run build && npm run wp-env start && npm run test:e2e -- tests/e2e/fields-*.spec.ts` (first run with `--update-snapshots` to create the baselines; commit them). Expected: all pass. If a perf bound fails, fix the cause in Tasks 10–12 code (profile with the React profiler) rather than loosening the number; a bound change needs the user's approval.

- [ ] **Step 3: Commit**

```bash
git add tests/e2e
git commit -m "test(e2e): field boxes, front-end reads, trust boundary and editing performance"
```

---

### Task 14: CLI `wp proto-blocks fields list|validate`

**Parallel group:** A (Steps 1–3, 5); the wp-env check in Step 4 runs after Task 8 Step 6
**Depends on:** Tasks 1, 8 (Steps 1–5)

**Files:**
- Create: `includes/CLI/FieldsCommand.php`, `tests/php/CLI/FieldsCommandTest.php`
- Modify: `includes/CLI/Commands.php` (one line: `WP_CLI::add_command('proto-blocks fields', FieldsCommand::class);`)

**Interfaces:**
- Produces:
  ```php
  final class FieldsCommand {
      public static function rows(array $groups /* list<FieldGroup> */, array $skipped): array;   // [{key, title, source, path, fields, location, status}] status: active|unrenderable|skipped-acf
      public static function validatePaths(array $paths, callable $decode /* C2 Jsonc::decode in production; a test-local stripper in tests */, ?Report $moduleReport = null): array{errors: int, warnings: int, lines: list<array{level, file, path, code, message}>};
      public function list($args, $assoc): void;       // --format=table|json|csv
      public function validate($args, $assoc): void;   // [<path>...] defaults to every discovered group file and declaration; exit 1 on any error; warnings do not fail
  }
  ```
  `list` and `validate` read `FieldsModule::instance()->index()` and `->report()` (Task 8). `validate` runs `GroupValidator` + `GroupNormalizer` (warnings: `unsupported_location_rule`, `unknown_type`, `location_ignored`, `duplicate_group_key`, `duplicate_meta_key`, `mixed_location_kinds`) on every file; output lines `ERROR|WARNING <file> <json path> <code>: <message>`.

- [ ] **Step 1: Write the failing test** — `FieldsCommandTest` using `tests/php/CLI/wp-stubs.php`: `rows()` for the realistic group → source `file:acf-export`, fields `18`, location `post_type == event`, status `active`; unknown-rule group → `unrenderable`; `validatePaths` over `groups-invalid/` → `errors` equal the count of error-coded fixtures, warning line for `post_format` contains `unsupported_location_rule`; realistic export → zero errors and zero warnings.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement.**

- [ ] **Step 4: Run** → `composer test` PASS; on wp-env `npx wp-env run cli wp proto-blocks fields list` prints the fixture groups.

- [ ] **Step 5: Commit**

```bash
git add includes/CLI tests/php/CLI/FieldsCommandTest.php
git commit -m "feat(cli): wp proto-blocks fields list and validate"
```

---

## Sub-phase 2B: the visual field-group builder

Starts after Task 13 is green. Layout and copy follow the Phase 1–3 mockup `.superpowers/sdd/2026-11-02-phase-0-admin-rebuild/design/phase-1-3/field-builder.html` (with its `mock.js`) and the tokens and rules in `design/phase-0/design-guide.md`; where the mockup and this plan disagree on behaviour, this plan wins; on pixels, the mockup wins.

### Task 15: Field-group file kind, Fields REST routes, client file helpers and the Fields list screen

**Parallel group:** C
**Depends on:** Phase 0 Task 3; Phase 1 merged (C2–C7); Tasks 8 (incl. Step 6), 14; Task 13 green (sub-phase gate)

**Files:**
- Create: `includes/API/Admin/FieldsRoutes.php`, `includes/Admin/FieldsMenu.php`, `tests/php/API/{FieldsRoutesTest,FieldGroupWriteTest}.php`, `src/admin/screens/fields/{jsonc-edit,schema-files}.ts`, `src/admin/screens/FieldsScreen.tsx`, `src/admin/screens/fields/GroupList.tsx`, `src/admin/__tests__/fields/{jsonc-edit,list}.test.ts(x)`
- Modify: Phase 1 `includes/ContentModel/SchemaFileWriter.php` and `includes/API/Admin/SchemaFilesRoutes.php` (the C5 `extendKind` addition), `includes/Modules/Fields/FieldsModule.php` (one line: `SchemaFileWriter::extendKind('field-group', GroupFiles::FOLDER, 'group', …)` on `init`), `AdminApp::SCREENS` (add `'fields'`), `AdminRestController::ROUTE_GROUPS` (add `FieldsRoutes::class`), `src/admin/screens/index.ts` (one line), `src/admin/bootstrap.ts` `Screen` union (add `'fields'`), `includes/Core/Plugin.php` (one line `add_action('admin_menu', [new FieldsMenu(), 'register'], 11)` beside Phase 1's `ContentModelMenu`)

**Interfaces:**
- Consumes: C2, C5, C6 (`parseJsonc`), C7 (`FileResult`, `ConflictDialog`, `adminGet/adminPost/adminDelete`); `FieldsModule::instance()->{index,report}()`, `AcfBridge::{active,skipped,acfGroups}` (Task 8); `FieldsCommand::rows()` (Task 14) for the list rows.
- Produces (PHP):
  - `SchemaFileWriter::extendKind(...)` as specified in C5. For kind `field-group`: slug `^[a-z][a-z0-9_-]{0,31}$`, folder `proto-fields`, basename `group`, validator `fn(array $data) => array_map(fn($e) => ['path' => $e['path'], 'message' => $e['message']], GroupValidator::validate($data, false)->errors)`; every other `write()` rule (size, JSONC, hash conflict, realpath confinement, symlink refusal, atomic rename) is Phase 1's, unchanged. `delete()` for `field-group` needs no `Declaration`: Phase 1's `$existing` parameter receives a minimal `Declaration('field-group', $slug, [], 'file', <path>, <root>)` built by the route from `GroupFiles`.
  - `FieldsMenu::register(): void` — submenu `proto-blocks-fields` "Fields" under the Proto-Blocks menu, capability `manage_options`, callback `AdminApp::renderScreen('fields')`; exists even when the module is off (the screen shows the disabled state).
  - `FieldsRoutes::registerRoutes(string $namespace, callable $permission, callable $writePermission): void`:
    - `GET admin/fields` → `{ moduleEnabled: bool, acfActive: bool, groups: [{key, title, origin: 'file'|'php'|'type-file'|'taxonomy-file'|'acf', file: {kind: 'field-group'|'type'|'taxonomy', slug, index?: int, path /* relative to the theme root */} | null, location: string /* human summary */, fieldCount, status: 'active'|'unrenderable'|'skipped-acf', issues: list<{level, code, path, message}>, doubleDeclared: bool}] }`.
    - `GET admin/fields/(?P<key>[A-Za-z0-9_-]+)` → `{ group: raw, issues, file: FileResult & {kind, slug, index} | null, postTypes: [{slug,label}], taxonomies: [...], roles: [...], optionsPages: [...], pageTemplates: [...] }` (choices for the location builder; `file.hash` feeds `putSchemaFile`).
    - `POST admin/fields/validate` body `{ text: string, kind: 'field-group'|'type'|'taxonomy', index?: int }` → `{ errors, warnings }` using `Jsonc::decode` + `GroupValidator` + `GroupNormalizer`; 400 `proto_blocks_invalid_jsonc` with `data: {line, column}` from `JsoncException`.
    - Writes are **not** here: the client uses `putSchemaFile`; the server enforces confinement and validation (C5 + the Task 8 errors filter).
    - Permission: `$permission` (manage_options) on all three.
- Produces (TS): `jsonc-edit.ts` `setAtPath`, `lineColumn` and `schema-files.ts` `getSchemaFile`, `putSchemaFile`, `deleteSchemaFile`, `SchemaKind` exactly as C6/C7 state. `FieldsScreen()` routes between `GroupList` and `Builder` by `?group=<key>` / `?new=1` in the URL (history `pushState`, no reload); `GroupList` shows origin badges (`Proto-Blocks file`, `Proto-Blocks PHP`, `Plugin: Advanced Custom Fields`), status badges, the double-declaration badge, issue count, "Add field group" (disabled in read-only mode, `ReadOnlyBanner`), delete with `ConfirmDialog` naming the path (`proto-fields` groups → `deleteSchemaFile('field-group', slug)`; groups inside a type file → `setAtPath(text, ['fields', index], undefined)` then `putSchemaFile('type', slug, text, hash)`); ACF and PHP groups have no delete and an explanation tooltip; `moduleEnabled: false` → a notice "The Fields module is turned off by the proto_blocks_modules filter." and no write controls.

- [ ] **Step 1: Write the failing PHPUnit tests**

`FieldGroupWriteTest` (temp theme root, Phase 1 writer with `extendKind` applied): a valid group text → file at `<root>/proto-fields/speaker/group.jsonc`, atomic; slug `../x`, `a/b`, `Speaker` → `invalid_slug` and nothing written; a body failing `GroupValidator` (`"name": "_x"`) → `schema_invalid` 400 with `data.errors[0].path` and nothing written; with `fileModsAllowed` false → `file_mods` 403; `proto-fields/x` a symlink to a directory outside the root → `path_outside_root` 403; a 300 KB body → `file_too_large` 413; `preg_match` of the registered `schema-files` route pattern accepts `field-group/speaker` and rejects `field-group/../x`.

`FieldsRoutesTest` (stubs from `tests/php/Admin/wp-stubs.php` + Fields stubs): routes registered with `$permission`; list entry for the realistic file group has `origin: 'file'`, `file.kind: 'field-group'`, `file.path` relative to the theme root; a PHP group has `file: null`; `validate` with `text` = `'{ "key": "g", // c\n "title": "T", "fields": [ { "key": "f", "name": "_x", "type": "text" } ] }'` → error code `invalid_name`; malformed text → 400 `proto_blocks_invalid_jsonc` with `data.line`; `GET admin/fields/../etc` does not match the route regex.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement** `extendKind` and the route regex change in Phase 1's files, the `FieldsModule` registration line, `FieldsRoutes`, `FieldsMenu` and the one-line registrations. Run Phase 1's own writer and route tests too → PASS.

- [ ] **Step 4: Write the failing Jest tests**
- `jsonc-edit.test.ts`: `setAtPath('{\n  // keep\n  "fields": [1]\n}', ['fields', -1], 2)` → parses to `{fields: [1, 2]}` and still contains `// keep`; `setAtPath(text, ['fields', 0], undefined)` removes the element; `lineColumn('{\n  "a": 1,\n  x\n}', <offset of x>)` → `{line: 3, column: 3}`.
- `list.test.tsx` with `mockApi({'admin/fields': {...}})`: three origin badges render; `skipped-acf` row shows `Skipped: ACF registers this key`; `unrenderable` row shows the warning text naming `post_format`; `fileModsAllowed: false` → banner, "Add field group" and delete disabled; delete of a file group opens `ConfirmDialog` mentioning `proto-fields/events/group.jsonc` and confirms through `deleteSchemaFile` (mocked); `moduleEnabled: false` → the module-off notice.

- [ ] **Step 5: Run to verify failure, implement, run** → `composer test && npm run test:js && npm run build` PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/API includes/Admin includes/ContentModel/SchemaFileWriter.php includes/Modules/Fields/FieldsModule.php includes/Core/Plugin.php src/admin tests/php
git commit -m "feat(admin): field-group file kind, Fields routes and the Fields list screen"
```

---

### Task 16: Builder core — field tree, drag and drop, add-field menu, per-field settings, nesting

**Parallel group:** C
**Depends on:** Task 15

**Files:**
- Create: `src/admin/screens/fields/{Builder,FieldTree,FieldSettings,AddFieldMenu}.tsx`, `src/admin/screens/fields/builder-state.ts`, `src/admin/screens/fields/field-settings-spec.ts`, `src/admin/__tests__/fields/{builder-state,field-tree,field-settings}.test.tsx`

**Interfaces:**
- Consumes: `GET admin/fields/<key>` (Task 15); `parseJsonc` (C6); `setAtPath` (Task 15 `jsonc-edit.ts`); `normalizeField` (Task 10) for type labels; `schemas/field-type-aliases.json`.
- Produces:
  ```ts
  // builder-state.ts — pure reducer over the RAW group object (ACF-shaped, unknown keys kept)
  export type FieldPath = number[];                  // indexes through fields / sub_fields / layouts[i].sub_fields; layouts addressed as [fieldIdx, 'layouts', layoutIdx, ...]
  export type BuilderAction =
    | { type: 'add'; parent: FieldPath | null; layout?: number; fieldType: string; at?: number }
    | { type: 'move'; from: FieldPath; to: FieldPath }   // within or across parents (repeater ↔ group allowed; a field cannot move into itself)
    | { type: 'update'; path: FieldPath; patch: Record<string, unknown> }
    | { type: 'remove'; path: FieldPath }
    | { type: 'duplicate'; path: FieldPath }
    | { type: 'addLayout' | 'removeLayout' | 'updateLayout'; path: FieldPath; layout?: number; patch?: Record<string, unknown> }
    | { type: 'group'; patch: Record<string, unknown> };   // title, location, position, style, menu_order, ACF-only group keys untouched
  export function reduce(group: RawGroup, action: BuilderAction): RawGroup;
  export function newFieldKey(): string;      // 'field_' + 13 lowercase hex chars, crypto.getRandomValues
  export function slugifyName(label: string): string;  // 'Start date' → 'start_date'; must satisfy the schema pattern
  export function toFileText(original: string, group: RawGroup, pointer: (string|number)[]): string;  // setAtPath per changed top-level key so comments elsewhere survive
  // field-settings-spec.ts
  export const COMMON_SETTINGS: SettingSpec[];   // label, name, type, instructions, required, default_value, placeholder, wrapper.width/class
  export const TYPE_SETTINGS: Record<string, SettingSpec[]>;  // per canonical type: text maxlength/minlength/pattern; number min/max/step; select choices (key:value editor) + multiple + optionsSource; true_false ui/message; date display/return format (preserved); image/file return_format, mime_types; gallery min/max; relationship post_type[], taxonomy[], filters[], min/max; taxonomy taxonomy, field_type, save_terms; repeater min/max/layout/button_label; flexible_content layouts; code language
  export type SettingSpec = { key: string; label: string; control: 'text'|'textarea'|'number'|'switch'|'select'|'multiselect'|'choices'|'postTypes'|'taxonomies'; options?: {value:string;label:string}[]; help?: string };
  ```
  UI: left column `FieldTree` (sortable list per parent with `@dnd-kit/sortable` + `DndContext` with `closestCenter`, keyboard sensor for accessible reorder, nested lists for group/repeater sub-fields and flexible layouts, drag handle per row, collapse/expand, row shows label, name, type badge, required marker), right column `FieldSettings` (react-hook-form + zod built from the spec for the selected field; edits dispatch `update` on blur/change debounced 150 ms), `AddFieldMenu` (command-palette style list of the 16 type families grouped Basic/Content/Choice/Relational/Layout, plus ACF aliases shown as "also accepts"), label → name auto-slug until the name is edited manually. Renaming an existing field's `name` shows the spec's warning inline: `Stored values stay under the old meta key "%s".`

- [ ] **Step 1: Write the failing tests**

- `builder-state.test.ts`: `add` text at root gets a unique `field_` key and `name` from label; `move` from `[2]` to `[5, 0]` (into repeater `agenda` sub-fields) and back; moving a repeater into its own sub-fields is a no-op; `update` keeps ACF-only keys (`wrapper`, `aria-label`); `duplicate` gives new keys recursively and suffix `_copy` names; `remove` of the field referenced by another field's `conditional_logic` also removes those rules (and returns a notice list); `toFileText` on a JSONC original with a `// comment` above `fields` keeps the comment after an update.
- `field-tree.test.tsx`: keyboard reorder (focus handle, Space, ArrowDown, Space) dispatches `move`; nested repeater renders its sub-fields indented with their own "Add field" button.
- `field-settings.test.tsx`: selecting a `select` field shows a choices editor; entering `red : Red` lines writes `choices: {red: 'Red'}`; name field rejects `_x` with the schema message; renaming a saved field shows the rename warning; relationship settings list post types from the GET payload.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement.**

- [ ] **Step 4: Run** → `npm run test:js && npm run build` PASS.

- [ ] **Step 5: Commit**

```bash
git add src/admin/screens/fields src/admin/__tests__/fields
git commit -m "feat(admin): field-group builder with drag-and-drop tree, add menu and per-type settings"
```

---

### Task 17: Conditional logic and location rule builders, group settings

**Parallel group:** C
**Depends on:** Task 16 (`BuilderAction`, `reduce`)

**Files:**
- Create: `src/admin/screens/fields/{ConditionBuilder,LocationBuilder,GroupSettings}.tsx`, `src/admin/__tests__/fields/{condition-builder,location-builder}.test.tsx`
- Modify: `src/admin/screens/fields/{Builder,FieldSettings}.tsx` (mount points)

**Interfaces:**
- Consumes: `reduce`, the `GET admin/fields/<key>` choice lists.
- Produces:
  ```ts
  export function ConditionBuilder(props: { group: RawGroup; path: FieldPath; value: ConditionRule[][] | 0; onChange(v: ConditionRule[][] | 0): void }): JSX.Element;
  // "Show this field if" — OR groups of AND rows; field picker lists only fields that precede-or-are-siblings in scope (ACF scope: same parent level and ancestors' siblings), excluding itself and layout types;
  // operator list by target type: true_false → == / != ; select/radio → ==, !=, ==empty, !=empty ; text-like → all six; value input is a select of the target's choices when it has choices, a switch for true_false, else text; "Remove all" writes 0 (ACF's off value)
  export function LocationBuilder(props: { value: LocationRule[][]; choices: LocationChoices; onChange(v: LocationRule[][]): void; implied?: string }): JSX.Element;
  // rows: param select (the nine params), operator (== / !=), value select fed by choices (post types, statuses, 'taxonomy:term' via search, templates, roles, user forms all/add/edit/register, options pages);
  // an existing unsupported param renders as a locked red row "Not supported by Proto-Blocks: <param>. This group will not be shown." with a remove button; when implied (group lives in a type/taxonomy file) the builder shows "Shown on <Type> — set by the file it lives in" instead of rows
  export function GroupSettings(props: { group: RawGroup; dispatch(a: BuilderAction): void }): JSX.Element;
  // title, position (normal | side | after title), style (default | seamless), menu order, options-group autoload switch when the location is options_page
  ```

- [ ] **Step 1: Write the failing tests**

- `condition-builder.test.tsx`: adding a rule on `stream_url` lists `is_online` and not `stream_url`; choosing `is_online` offers only `==`/`!=` with a switch value; "or" adds a second group; output equals `[[{field: <key>, operator: '==', value: '1'}]]`; remove all → `0`.
- `location-builder.test.tsx`: a `post_format` rule renders the locked row with the exact text; choosing param `taxonomy` swaps the value list to taxonomies; `user_form` values are `all, add, edit, register`; implied mode shows the note and no rows.

- [ ] **Step 2: Run to verify failure** → FAIL.

- [ ] **Step 3: Implement** and mount them in `Builder` (conditions inside `FieldSettings` as a tab "Conditional logic"; location and group settings in a "Group settings" panel above the tree).

- [ ] **Step 4: Run** → `npm run test:js && npm run build` PASS.

- [ ] **Step 5: Commit**

```bash
git add src/admin/screens/fields src/admin/__tests__/fields
git commit -m "feat(admin): conditional logic and location rule builders"
```

---

### Task 18: JSONC view, save to file, new groups, read-only mode; builder E2E and visual tests (done-criterion check, part 2)

**Parallel group:** C
**Depends on:** Tasks 16, 17

**Files:**
- Create: `src/admin/screens/fields/JsoncPane.tsx`, `src/admin/__tests__/fields/save.test.tsx`, `tests/e2e/fields-builder.spec.ts`
- Modify: `src/admin/screens/fields/Builder.tsx`

**Interfaces:**
- Consumes: `parseJsonc` (C6); `setAtPath`, `lineColumn`, `getSchemaFile`, `putSchemaFile`, `SchemaKind` (Task 15); `ConflictDialog`, `FileResult` (C7); `POST admin/fields/validate` (Task 15); `toFileText`, `reduce` (Task 16).
- Produces: `Builder` header actions `Save` (writes; disabled while client validation fails or read-only; label `Saving…` during the request), `Discard changes`, a `Builder | JSONC` toggle. `JsoncPane`: a monospace textarea (no code-editor dependency) showing the whole file text, live parse errors from `parseJsonc` shown as `Line <l>, column <c>` via `lineColumn`, server validation via `POST admin/fields/validate` debounced 400 ms, "Apply to builder" re-parses into the builder state; edits in either view keep the other in sync (builder → text via `toFileText`; text → builder on apply). New group: dialog asking title and destination — "Own file (`proto-fields/<slug>/group.jsonc`)" (default) or "Inside a post type file" (Phase 1 type list, file origin only) — then creates via `putSchemaFile('field-group', slug, text, null)` with a commented scaffold header (`// Field group: <title>. Edited by the Proto-Blocks Fields screen; comments are kept.`), or inserts into the type file's `fields` with `setAtPath(text, ['fields', -1], group)` and `putSchemaFile('type', slug, text, hash)`. Every save sends the `hash` the file was loaded with; a 409 `proto_blocks_conflict` opens `ConflictDialog` (reload, no overwrite). Server errors from the PUT show in a dismissible alert listing each `{path, message}` and keep the unsaved state. Toast on success: `Field group saved to <path>.` Leaving with unsaved changes prompts (`beforeunload` + in-app confirm).

- [ ] **Step 1: Write the failing Jest test** — `save.test.tsx`: saving an edited realistic group calls `putSchemaFile('field-group', 'acf-export', text, <hash from the GET>)` where `text` parses to the edited group and still contains every ACF-only key of the fixture; a JSONC syntax error disables Save and shows `Line 3, column 5`; a 400 from the PUT with `{errors: [{path: 'fields[0].name', message: '...'}]}` renders the list and keeps the dirty state; a 409 `proto_blocks_conflict` opens `ConflictDialog` and does not retry; `fileModsAllowed: false` → toggle and JSONC view available, Save and Add disabled, banner shown; an ACF-origin group opens read-only with `This group is registered by Advanced Custom Fields.`

- [ ] **Step 2: Run to verify failure, implement, run** → `npm run test:js && npm run build` PASS.

- [ ] **Step 3: Write the E2E spec** `tests/e2e/fields-builder.spec.ts` (activates `pb-e2e-fields` from Task 13 like the other Fields specs):
- `ACF export round-trips unchanged`: open the `Event details` group, open JSONC view, Save without edits → the theme file's decoded JSON deep-equals the fixture (compare `wp eval` decode of both) — the done criterion's "loads … without changes".
- `build a group visually`: Add field group "Speaker" (own file), add Text "Company", Image "Photo", Repeater "Links" with sub-field URL "Url", drag "Photo" above "Company", set location `post_type == post`, add a condition on "Company" (show if Photo `!=empty`), Save → `proto-fields/speaker/group.jsonc` exists, its fields order is Photo, Company, Links; open a new post → the Speaker box renders with that order; the conditional field is hidden until an image is chosen.
- `rename warning` appears when renaming a saved field's name.
- `read-only`: with `DISALLOW_FILE_MODS` set via `wp-env run tests-cli wp config set DISALLOW_FILE_MODS true --raw`, the banner shows and Save is disabled; unset afterwards.
- Visual: `toHaveScreenshot` of the Fields list (`fields-list.png`), the builder with the realistic group (`fields-builder.png`), a field's settings with the condition tab open (`fields-builder-conditions.png`), the location builder with an unsupported rule (`fields-builder-location.png`), the JSONC view (`fields-builder-jsonc.png`).

- [ ] **Step 4: Run** — `npm run build && npm run test:e2e -- tests/e2e/fields-builder.spec.ts --update-snapshots` once, then without the flag → PASS. Re-run `tests/e2e/fields-*.spec.ts` in full → PASS.

- [ ] **Step 5: Commit**

```bash
git add src/admin tests/e2e
git commit -m "feat(admin): JSONC view and save-to-file for the field-group builder, with E2E and visual tests"
```

---

### Task 19: Docs, changelog and phase sign-off

**Parallel group:** C
**Depends on:** all tasks

**Files:**
- Modify: `README.md` (Fields section: declaring groups three ways, the type table with stored shapes, getter and aliases, bindings, trust rules, ACF import, performance note), `CHANGELOG.md` (Unreleased: custom fields, WordPress 6.5 floor)
- Create: `docs/fields.md` (the repo keeps per-feature user docs in `docs/`, e.g. `docs/repeater-control.md`; README links to it).

- [ ] **Step 1: Write the docs** from the shipped behaviour (copy examples from the E2E fixture theme so they are known to work).

- [ ] **Step 2: Sign-off run** — `composer test && npm run test:js && npm run test:scripts && npm run build && node scripts/check-fields-bundle-size.mjs && npm run test:e2e` → all PASS; the perf spec's annotations (mount ms, p95 ms) are pasted into the phase report.

- [ ] **Step 3: Commit**

```bash
git add README.md CHANGELOG.md docs
git commit -m "docs(fields): custom fields guide and changelog"
```

---

## Self-review notes

- **Spec coverage (section 5):** ACF shape and preserved keys (T1); JSON Schema + `validate` (T1, T14); location grammar, fail-closed unknown rules (T1, T5); same-key ACF skip (T8, T12); declaration via `proto_field_group()`, type-file `fields`, standalone groups (T8); sixteen types with stored shapes and sanitisers (T2, T3); one-row storage with REST schemas, `revisions_enabled`, term and user meta, options one-per-field with autoload (T5); boxes in block/classic/Elementor-classic editors, term and user forms, real names, post-form save with nonce (T10–T12, T9); lazy pickers (T11); two-sided validation from one definition, no silent drops (T4, T10, T9, T12); conditional logic both sides (T4, T10); getter, raw flag, escaping, aliases (T6); `$template->field()` (T6); bindings source and 6.5 floor (T7); trust per user at write time, code gating (T5, T9, T12); builder with dnd, add menu, settings, condition and location builders, nesting, JSONC view, write to file (T15–T18); measured performance (target section, T10 isolation test, T12 bundle size, T13 Playwright); E2E and visual for boxes, front end and builder (T13, T18). Section 8: Fields is a module with `boot()` (T8). Section 9: `fields list|validate` (T14).
- **Rulings on spec gaps (also reported):** (1) Groups for terms, users, several post types or external types without a type file live in `proto-fields/<slug>/group.json(c)` (new folder, single group or ACF Tools export array); type and taxonomy files keep inline groups with implied locations. (2) Options pages: Phase 2 delivers `OptionStore`, the `options_page` rule, the `option:` getter context and `SaveHandler::save()` for Phase 3 to call; the rendered options page stays in Phase 3. (3) ACF type aliases (`date_picker`, `color_picker`, `checkbox`, `button_group`, `post_object`, `page_link`, `oembed`, `user`) map onto the sixteen; `tab`/`accordion`/`message` render as layout only; unknown types skip the field with a warning (field-level, not group-level, fail-closed). (4) Invalid values are not written; they come back through a per-user 5-minute error store; conditional-hidden fields are neither validated nor written. (5) `after title` maps to `normal`/high in the block editor. (6) Bindings expose only declared non-code fields, readable posts and declared options fields, with a `"bindings": false` opt-out. (7) `fieldSource` for repeaters in block templates is deferred; templates use `$template->field()` and `proto_rows()`. (8) Undeclared names return `null` from the getter. (9) Performance target as stated above. (10) Post types that receive fields gain `custom-fields` support (core's precondition for REST meta) and the field keys are protected meta, so the raw Custom Fields panel never edits them; this overrides Phase 1's "`custom-fields` only on request" default for exactly those types. (11) `revisions_enabled` is set only where the type supports revisions. (12) The repeater row cap applies to trusted writers too (size limit, not content filter). (13) The public registration form saves only `user_form == register|all` groups, always sanitised.
- **Names used across tasks:** `FieldGroup`, `Field`, `Location`, `GroupNormalizer::{normalize,normalizeMany,aliases}`, `GroupValidator::validate`, `Report`, `FieldTypeInterface::{names,stores,restSchema,sanitize,normalize,escape,emptyValue}`, `TypeRegistry::{withCoreTypes,for}`, `ConditionEvaluator::{visible,rule}`, `ValueValidator::validate`, `ObjectRef`, `Store`, `GroupIndex::{forRef,fieldFor,subtypesFor}`, `LocationMatcher::{matches,kinds}`, `TrustCallbacks::{sanitizeCallback,authCallback}`, `MetaRegistrar`, `FieldReader::{get,all,rows,setShared,shared}`, `Aliases::load`, `PhpGroups::{add,all,reset}`, `GroupFiles::{FOLDER,MAX_BYTES,SLUG_PATTERN}`, `FieldBindingSource::NAME`, `FieldsModule::{instance,index,reader,types,stores,report}`, `ChoiceResolver::{options,allowed}`, `SaveHandler::{save,INPUT,PRESENT,NONCE_FIELD,NONCE_ACTION}`, `ErrorStore::{put,take,peek}`, `BoxPayload::build`, `FieldsMenu::register`, `SchemaFileWriter::extendKind` (C5 addition); TS `normalizeField`, `inputName`, `buildValueSchema`, `isVisible`, `setAtPath`, `lineColumn`, `getSchemaFile`, `putSchemaFile`, `deleteSchemaFile`, `SchemaKind`, `reduce`, `toFileText` — each defined once in an Interfaces block and referenced by that name afterwards.
- **Proportion:** no task body transcribes code; signatures, test assertions and spec values only. Phase 1 names are isolated in the contract section so a rename touches one place.
- **Group A file ownership:** each group A file has one creating task; later edits are sequential by dependency and limited to one-line registrations (`withCoreTypes()` in Task 3; `FieldsModule::boot()` hook lines and one `FieldsModuleTest` method each in Tasks 9 and 12; `functions.php` gains `proto_field_group` in Task 8). `tests/php/Modules/Fields/wp-stubs.php` is Task 1's alone; `GroupNormalizer.php` is Task 1's alone (the `mixed_location_kinds` check moved there from Task 5). `package.json` is touched only by Task 1 in this phase.
