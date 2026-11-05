# Scripts module — implementation plan

Spec: `docs/superpowers/specs/2026-11-05-scripts-design.md`. Module id `scripts`, kind `script`,
folder `proto-scripts`, basename `script`. Namespace `ProtoBlocks\Modules\Scripts\`.

## Tasks

1. **Schema + definition** — `includes/ContentModel/schemas/scripts.schema.json` ($defs `script`, `location`,
   `where`, `target`); `Definition\Targets` (grammar + evaluation + `url:` glob), `Definition\ScriptNormalizer`,
   `Definition\ScriptValidator`. Tests `tests/php/Modules/Scripts/Definition/*`.
2. **Sources + registry** — `Sources\ScriptFiles` (roots, child wins, broken_override, confinement, html files,
   `snippet_file_missing`, `php_in_snippet`, UntrustedKeys), `Sources\PhpDeclarations` + `proto_script()` in
   `functions.php` (sealed at `template_redirect` 1), `Registry` (file wins whole, `double_declaration`).
   Tests `Sources/*`, `RegistryTest`.
3. **SnippetFiles** — read/write/delete of `head.html`, `body-open.html`, `body-close.html`; confinement; 256 KB;
   folder delete safety. Tests `SnippetFilesTest`.
4. **Output** — `RequestContext`, `Output` (hooks, ordering, markers, veto contexts, `missing_body_open`).
   Tests `OutputTest`, `TargetsTest`.
5. **Module** — `ScriptsModule::boot()`, `registerFileKind()` (extendKind + template `ScriptTemplate`),
   schema-files trust guard for kind `script` (unfiltered_html) and snippet cleanup after a schema-files DELETE,
   `issues()`. Tests `ScriptsModuleTest`, `ScriptKindTest` (OptionsKindTest-style).
6. **REST** — `API\Admin\ScriptsRoutes`: `GET admin/scripts`, `GET|POST admin/scripts/{slug}`.
   Tests `tests/php/Modules/Scripts/Api/ScriptsRoutesTest.php`.
7. **CLI** — `CLI\ScriptCommand` list|create|validate|enable|disable|delete. Tests `Cli/ScriptCommandTest.php`.
8. **React** — `src/admin/screens/ScriptsScreen.tsx`, `src/admin/scripts/*` (types, schema, edits, list, editor,
   placement builder, code editor, read-only banner). Jest under `src/admin/__tests__/scripts/`.

## Rulings (calls made while building; open to objection)

- Slug grammar: `SchemaFileWriter::EXTENDED_SLUG_PATTERN` (`^[a-z][a-z0-9_-]{0,31}$`).
- `title` required in files (1–100 chars, no `<`/`>`); `priority` integer −100000…100000, default 10.
- `where.include` absent → `["all"]`; an explicit `[]` matches nowhere (warning `never_matches`).
- `roles` non-empty together with `loggedIn: false` can never match → error `conflicting_where`.
- `excludeRoles` only excludes logged-in users having one of the roles.
- `url:` globs: pattern must start with `/`; `*` = any run (also empty, also across `/`); compared
  case-insensitively against the rawurldecoded request path without query, with the home path prefix
  (WordPress in a sub-folder) removed; a trailing slash is ignored on both sides, so `/blog/*` also matches `/blog`.
- `page:`/`post:` values that are all digits are passed as ints (ids), else as slugs/paths.
- `term:<tax>:<slug>`: the term archive (`is_tax`, or `is_category`/`is_tag` for those two) or
  `is_singular() && has_term()`.
- Veto contexts (admin, feed, embed, customizer preview, REST, `IFRAME_REQUEST`, cron, ajax) cannot be undone by
  `proto_blocks_scripts_enabled`; the filter can only switch output off.
- A script with any error (schema, `php_in_snippet`, `invalid_snippet_file`) is skipped whole (fail closed); a
  broken child-theme file also drops the parent's file and the PHP declaration of the same slug.
- `missing_body_open`: detected on a front-end `wp_footer` when an in-scope script has a bodyOpen snippet and
  `did_action('wp_body_open') === 0`; remembered in option `proto_blocks_scripts_body_open` (written only when it
  changes) so the admin list can show it.
- Writes: REST write routes need `manage_options` + `unfiltered_html` (+ file mods, multisite super admin).
  The same gate is applied to `admin/schema-files/script/*` writes and deletes through
  `rest_request_before_callbacks`. WP-CLI writes do not check `unfiltered_html` (the shell user owns the files).
- Delete stays on `DELETE admin/schema-files/script/<slug>?confirm=<slug>`; when the folder held a valid
  `script.jsonc` and nothing but the three known snippet files, a `rest_request_after_callbacks` hook removes the
  snippets and the folder; otherwise the snippet files are kept and listed in `kept`.
- PHP declarations: `proto_script($title, $args)`; slug `$args['slug']` or the slugified title; a `{file}` value
  is `invalid_snippet_file`; `php_in_snippet` applies to PHP declarations too.
- `GET admin/scripts/{slug}` returns the schema-files FileResult plus `snippets` and `item`.
- The UI always writes files (an empty snippet removes its file and its key in script.jsonc).
