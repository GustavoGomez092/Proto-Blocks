# Redirects — Track F: REST routes and WP-CLI

Spec: `docs/superpowers/specs/2026-11-05-redirects-design.md` §9, §11–§14. Contract: `docs/superpowers/plans/2026-11-05-redirects-interfaces.md` (Track F; consumes A, B, C and D).

## Tasks

1. `includes/API/Admin/RedirectsRoutes.php` — constructor with optional services (Registry, Hits, RedirectLog, NotFoundLog, Installer, SchemaFileWriter, matcher factory, import/export resolvers, upload reader), resolved from `RedirectsModule::instance()` when omitted; every route of §11. Tests: `tests/php/API/RedirectsRoutesTest.php`.
   - list (`GET admin/redirects`), test, log/404 (+DELETE, +export), hits reset/prune, import parse/plugins/preview/deactivate, export, db install.
2. `includes/CLI/RedirectCommand.php` — every subcommand of §12; server-side writes via `Serializer::group()` + `SchemaFileWriter::write()`; static `validationRows(Registry)`. Tests: `tests/php/CLI/RedirectCommandTest.php`.
3. Track D doubles: `tests/php/API/stubs/redirects-import-doubles.php` declares the contract interfaces only when they do not exist (`interface_exists` autoloads Track D's real files once merged) and small doubles implementing them. No file under `Import/` or `Export/`.

## Rulings

- Permissions: reads `manage_options`. File-free mutations (log/404 deletes, hits reset/prune, `db/install`) use `$permission` so a read-only host keeps its logs usable (spec §6). `import/parse` and plugin `preview` are POST but write nothing: `$permission`. `import/deactivate` needs `manage_options` and `activate_plugins` (deactivating a plugin is not a file modification; core allows it under DISALLOW_FILE_MODS). No route of this group writes a theme file; files go through `admin/schema-files/*`.
- `GET admin/redirects` issues: per-rule issues (same group slug and `key` = rule id, or a `/rules/<index>` message prefix) are attached to the rule; the rest are listed at the top (the FieldsRoutes pattern). Paths go through `ContentModelRoutes::displayPath`, messages through `scrub`.
- A file group's `writable` is false when the loaded file is not the writer's own copy (parent theme or filter root), as the monitor decides.
- Test route: evaluates the init stage; when nothing fires, the 404 stage too (`stage: "404"`), trace entries carry `stage`, and the stage-only skips (`404_stage_only`, `not_404_stage`) are left out. `loggedIn=0` evaluates as a visitor (current user 0, restored afterwards); otherwise login/role conditions see the caller; the response `note` says so. Header/cookie/agent/referrer/ip conditions see an empty request. Disabled rules appear in the trace as `disabled`.
- Imported rows: every `new` row is re-checked with `GroupValidator::analyse` (schema + rule checks) and becomes `invalid` with the first error message; a `new` row whose source+regex+condition equals a rule of the target `group` (or any group when none is given) becomes `duplicate`.
- Plugin basenames for deactivate come from an optional `plugins(): list<string>` method on the importer (not in the contract); fallback map by importer id for the known plugins. Network-active plugins are not deactivated from a site.
- CSV export escaping: cells starting with `= + - @` (ASCII and full-width) and also tab / CR get the `[FORMULA] ` prefix.
- File downloads (`log/export`, `404/export`, `export`): a `WP_REST_Response` whose data is the file text plus `Content-Type`, `Content-Disposition` and `X-Proto-Blocks-Download: 1`; a `rest_pre_serve_request` filter prints such a body raw instead of JSON-encoding it.
- Paging: `per_page` is clamped to 5–200 (default 50), `page` to ≥ 1; responses carry `page`, `perPage`, `total`, `pages`.
- CLI writes read the group file's raw data (not the registry's normalised copy, which drops invalid rules) and serialise it; a broken file is refused rather than rewritten. Default group for `add` is `redirects`. `--when-name` added for header/cookie conditions. `test --logged-in` without a WP-CLI `--user` evaluates as the first administrator. `group delete` and `db drop` need `--yes`.
