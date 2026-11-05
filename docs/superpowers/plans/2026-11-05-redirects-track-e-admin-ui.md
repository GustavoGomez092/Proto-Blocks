# Redirects — Track E: admin UI (React)

Spec: `docs/superpowers/specs/2026-11-05-redirects-design.md` (§1, §2, §3, §6, §9, §11, §14 Jest).
Contract: `docs/superpowers/plans/2026-11-05-redirects-interfaces.md` ("Track E — Admin UI").

## Files (all new unless noted)

- `src/admin/screens/RedirectsScreen.tsx` (replaces the placeholder): header, banners, tabs, `?tab=` / `?slug=` in the URL.
- `src/admin/redirects/types.ts` — Rule, Group, Settings, `GET admin/redirects` and the other §11 responses.
- `src/admin/redirects/schema.ts` — zod mirror of `redirects.schema.json` ($defs rule, group, settings) plus the cross-field rule checks with the PHP error codes.
- `src/admin/redirects/edits.ts` — JSONC text edits that keep comments: addRule, updateRule, removeRule, moveRule, setRuleEnabled, setGroupField, newGroupText, settings edits.
- `src/admin/redirects/api.ts` — index/log/404 loaders, group-file writer (GET text + hash, edit, POST), settings file hook, downloads.
- `src/admin/redirects/{RedirectsTab,NotFoundTab,LogTab,ImportExportTab,SettingsTab}.tsx`, `RuleDialog.tsx`, `TestBox.tsx`, `GroupMenu.tsx`, `codes.ts` (code meanings, condition labels), `shared.tsx` (banners, pagers, download button).
- Tests: `src/admin/__tests__/redirects/*.test.tsx|ts`.

## Tasks

1. Plan (this file).
2. `types.ts`, `schema.ts` + `schema.test.ts` (every rule error code, settings ranges).
3. `edits.ts` + `edits.test.ts` (fixture with `//` comments between rules; snapshot of the enable toggle).
4. `api.ts` (group write = delete-then-insert for moves, each with its hash; conflict when the listed hash is stale).
5. Screen + RedirectsTab + RuleDialog + TestBox + group menu + bulk actions; tests: tab render, dialog validation, toggle text, move order, read-only, coexistence, test box.
6. NotFoundTab + LogTab; tests: prefill + deletion after save, ignore rule.
7. ImportExportTab; tests: preview statuses, duplicate mode, plugin importer + deactivate, export download.
8. SettingsTab; test: save payload.
9. tsc + full redirects jest run; report.

## Rulings

- **Writes do not use `useDeclarationFile().save`.** Its diff is top-level, so any rule change would rewrite the whole `rules` array and lose comments between rules (spec anti-goal). `api.ts` reads the same `admin/schema-files/<kind>/<slug>` route, edits the text with `edits.ts` and posts `{text, hash, acknowledge}` back, resolving `saved | conflict | confirm` exactly like `useDeclarationFile`. Conflict and guardrail dialogs are the shared `ConflictDialog` / `GuardrailDialog`.
- **Stale list = conflict.** Before writing a group the client GETs the file; when the hash differs from the `hash` in the last `GET admin/redirects`, the write stops with the conflict dialog (reload). Assumes Track F returns the same sha256 in both routes.
- **Rule edits are text splices, not `modify`, for one-line rules.** jsonc-parser `modify` reformats the touched array element onto many lines and drops trailing comments when removing; `edits.ts` locates each rule element with `parseTree` and splices its text (one rule per line, the serializer's layout). Rules written over several lines, or with comments inside, are edited key by key with `modify`. Moving swaps the two elements' text; comments stay on their lines.
- **Rule lookup** is by `id`; a file rule without an `id` is found by its `index` and gets its derived id written on the first edit (the UI always writes one).
- **Flags omitted when equal to the group default**, `enabled`/`log` omitted when true, `code` omitted when it is the action default (301 / 404), `action` omitted when `url`, like the serializer.
- **Default group** for "Ignore" and 404 "Add redirect": the dialog's group select defaults to the first writable file group by position; when none exists the dialog offers a new group "Redirects" (`redirects`), created on save.
- **404 deletion after save**: no exact-URL delete exists in §11, so the client lists that URL's individual rows (`search=<url>`, `per_page=200`), keeps exact matches and sends `DELETE admin/redirects/404?ids[]=…`.
- **Import parse body** is `{format, text, filename}`; `format` is detected from the file name (`.csv` → csv, `.json` → json, `.htaccess`/`.txt` → apache, `_redirects` → redirects). Duplicate handling for the chosen group is done client-side over the group's text (skip / replace / add) since the client writes the file.
- **Plugin importer shapes** (not fixed by §11): `GET import/plugins` → `{importers: [{id, label, cleanupHint, plugin}]}` or a bare list; `POST import/plugins/{id}/preview` → `{groups: {slug: rawGroup}}`; `POST import/deactivate` body `{id}`.
- **Log/404 rows** are camelCase (`id, created, url, target, code, group, ruleId, redirectBy, method, ip, agent, referrer`); lists are `{rows, total}`.
- **JS regex check**: PCRE-only syntax (`(?P<`, possessive quantifiers, `\A`, `\z`, inline `(?i)`) is translated before `new RegExp`; the server's compile is authoritative.
- **`?slug=`** filters the Redirects tab to one group.
