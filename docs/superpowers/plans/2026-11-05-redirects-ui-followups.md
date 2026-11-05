# Redirects — UI follow-ups against the real server

Spec: `docs/superpowers/specs/2026-11-05-redirects-design.md` §6, §11. Builds on tracks E (admin UI) and F (REST + CLI); the server code is the reference: `includes/API/Admin/RedirectsRoutes.php`, `includes/Modules/Redirects/Definition/{RuleValidator,GroupValidator,SettingsValidator,Patterns}.php`, `includes/ContentModel/schemas/redirects.schema.json`.

## Tasks

1. **Validation parity** — `src/admin/redirects/schema.ts` mirrors the schema (types, enums, `maxLength`/`minLength`, ranges) and the validators (codes `schema_invalid`, `missing_source`, `invalid_source`, `use_when_server`, `invalid_regex`, `invalid_target`, `invalid_else`, `invalid_code`, `self_redirect`, `invalid_header`, `invalid_condition`; settings `invalid_header`, `invalid_host`, `invalid_url`, `invalid_ip`; warning `unanchored_regex`). A refused save (`proto_blocks_schema_invalid`, `data.errors[]` of `{path, code?, message}`) is mapped back onto the dialog/settings fields. Tests: `schema.test.ts`, `rules-tab.test.tsx`, `settings.test.tsx`.
2. **Route shapes** — `DELETE admin/redirects/hits?group=&ruleId=` after a rule delete (404 ignored); `DELETE admin/redirects/404?url=` for "Add redirect" and per-URL delete; `?url=` for the expanded hits; list types carry `page, perPage, pages, groupBy`; `{importers: [...]}` with `plugins/active/canDeactivate`; plugin preview `skipped` rows rendered; parse rows show `message`; the test box shows `stage`, `reason`, `loggedIn`, `note`, per-step `stage`, and can test as a visitor (`loggedIn=0`). Tests: `rules-tab`, `logs`, `import-export`.
3. **Screen state** — coexistence banner (verified), a "tables missing" alert with "Create tables" (`POST admin/redirects/db/install`), per-group `readOnlyReason`, monitor issues (`monitor_*`) in their own alert. Tests: `screen.test.tsx`.
4. **Downloads** — `download()` (fetch + `X-WP-Nonce` + `credentials: 'same-origin'`, `Content-Disposition` filename, `_redirects` for Netlify, toast on non-2xx) for rule, log and 404 exports. Tests: `import-export`, `logs`.
5. Jest (redirects, then whole suite), `npm run build` once (assets not committed).

## Rulings

- **Server codes are the UI codes.** The browser checks report the server's code for each problem, with a message close to the server's; a refused save shows the server's own message (it is authoritative) next to the field its JSON pointer names. Only the dialog's own rule (`/rules/<i>` of the rule being saved, located by id in the text that was posted) is mapped to fields; findings for other rules or the group go to the dialog's root error.
- **Schema checks win**: as on the server, a rule that fails the schema (`schema_invalid`: lengths, id pattern, code range, condition value list sizes) is not cross-checked further.
- **`code` may be missing** from the refusal entries: `RedirectsModule::registerFileKinds()` keeps only `path` and `message`, and `SchemaFileWriter::extendedErrors()` drops any other key. The mapping works by path; `code` is used when present (see report).
- **Hit rows by query string**: `adminDelete` sends no body, and WP reads `group`/`ruleId` from the query as well, so `DELETE admin/redirects/hits?group=<slug>&ruleId=<id>`. Only file rules that were deleted; a 404 (no row) is ignored, other failures toast a warning without undoing the delete.
- **404 exact delete** uses `?url=` (server `deleteByUrl`), and the expanded hits list uses the exact `url` filter instead of `search` + client filtering.
- **Read-only file groups** (`readOnlyReason` `read_only`, `not_writable`, `file_mods`, `multisite`) keep their rules locked; the reason is shown in the group menu and on the group cell. PHP rules (`readOnlyReason: php`) stay editable through the copy-into-a-file-group dialog.
- **Tables missing**: a screen-level alert (every tab: hits, logs and 404s all need the tables) with "Create tables"; the Settings button stays.
- **Hit rows are only cleared for server-shaped ids** (`r_` + 8 hex): the route refuses anything else with 400, so a rule id that does not match is not sent. Nothing is sent when `db.tables` is false or the file write failed.
- **`readOnlyReason` wins** over a stale `writable: true`; an older response without it is inferred (`php` for PHP groups, `not_writable` for `writable: false`).
- **Monitor issues** (`code` starting `monitor_`) are pulled out of the declarations list into their own "post monitor" alert, so a monitor problem is not read as a broken declaration.
- **Deactivate after import** is offered only when the importer names a plugin, the user has `activate_plugins` (`canDeactivate`) and the plugin is active; a network-activated plugin (`skipped[].reason = network`) warns instead of claiming success.
- **Download names**: the `Content-Disposition` name is reduced to a bare file name (no folders, no control characters) before it reaches `a.download`; a network failure or a non-JSON error page toasts like any other non-2xx.
