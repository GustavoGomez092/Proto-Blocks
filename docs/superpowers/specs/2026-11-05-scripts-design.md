# Scripts (head, body-open, body-close snippets) — Design

**Date:** 2026-11-05
**Status:** Approved for build by the developer ("have another sub agent write a module to add header, body and end of body scripts"). Decisions are the author's unless marked.
**Release:** ships with Redirects in v2.16.0.

## Intent

Sites paste tracking tags, verification metas, chat widgets and consent scripts into "Insert Headers and Footers"-style plugins, which store them in the database. This module declares those snippets as **git-tracked files in the theme**, outputs them at three places on every front-end page (`<head>`, right after `<body>`, before `</body>`), lets the developer scope each snippet to parts of the site, and edits them from a Proto-Blocks admin screen that writes those same files. Same rules as every other content-model kind: files are the only store.

## Goals

1. `proto_script('GTM', ['head' => '<script>…</script>']);` works; a folder in the theme does the same with every option.
2. Three locations: `head` (`wp_head`), `bodyOpen` (`wp_body_open`), `bodyClose` (`wp_footer`), each with its own priority.
3. Placement rules: include/exclude site targets, logged-in state, roles.
4. Admin screen with code editors, list, enable toggle, origin badges, read-only mode, declared-twice badge.
5. CLI, docs, skill reference, unit + Jest + E2E coverage.

## Non-goals

- Admin-area or login-page injection. Front end only.
- Device targeting, scheduling, A/B, consent-manager integration.
- PHP snippets. Snippets are HTML/JS/CSS text; a `<?php` sequence is refused at validation (`php_in_snippet`).
- Sanitising snippet output. The point is raw output; see Trust.

## Constraints

Same stack and trust boundary as the Redirects design (`docs/superpowers/specs/2026-11-05-redirects-design.md`, Constraints). Independent module `scripts` in the `Modules` manager.

## Decisions

### 1. Files

```
theme/
  proto-scripts/<slug>/script.jsonc      metadata and placement
  proto-scripts/<slug>/head.html         optional, raw snippet for <head>
  proto-scripts/<slug>/body-open.html    optional
  proto-scripts/<slug>/body-close.html   optional
```

```jsonc
// proto-scripts/gtm/script.jsonc
{
  "title": "Google Tag Manager",
  "enabled": true,
  "priority": 10,                  // within each location; lower runs first; default 10
  "head": { "file": "head.html" }, // or an inline string: "head": "<meta name=\"x\" content=\"y\">"
  "bodyOpen": { "file": "body-open.html" },
  "bodyClose": "",                 // empty or absent = nothing at that location
  "where": {
    "include": ["all"],            // targets, section 2; default ["all"]
    "exclude": ["page:privacy-policy", "url:/landing/*"],
    "loggedIn": null,              // null = anyone, true = only logged in, false = only logged out
    "roles": [],                   // when non-empty, only users with one of these roles (implies loggedIn)
    "excludeRoles": ["administrator"]
  }
}
```

- Kind registered with `SchemaFileWriter::extendKind('script', 'proto-scripts', 'script', …)`. The sibling `.html` files are written by a small `SnippetFiles` helper through `SafeFile::writeAtomic` with the same confinement and size cap (256 KB each); only the three fixed basenames are ever read or written; a `file` value must be exactly one of them (`invalid_snippet_file`).
- Slug grammar as every kind. Child theme wins on the same slug. Deleting a script deletes the folder only when it contains a valid `script.jsonc` and nothing but the four known files.
- Each location value is either a string (inline) or `{ "file": "<one of the three names>" }`. The UI always writes files and reads both.

### 2. Placement targets

`include`/`exclude` are arrays of strings. A page is in scope when it matches any `include` entry and no `exclude` entry.

| target | true when |
|---|---|
| `all` | every front-end page |
| `front_page` | `is_front_page()` |
| `home` | `is_home()` (blog index) |
| `search` | `is_search()` |
| `404` | `is_404()` |
| `archive` | `is_archive()` |
| `singular` | `is_singular()` |
| `singular:<post_type>` | `is_singular(type)` |
| `post_type:<type>` | `is_singular(type)` or `is_post_type_archive(type)` |
| `page:<slug or id>` | `is_page(x)` |
| `post:<slug or id>` | `is_single(x)` |
| `taxonomy:<tax>` | `is_tax(tax)` or `is_category()`/`is_tag()` for those two |
| `term:<tax>:<slug>` | the term archive or a singular post having that term |
| `url:<pattern>` | request path matches the glob (`*` = any run of characters, `**` not needed); compared against the decoded path without query |

Unknown targets are `schema_invalid`. Lists are capped at 100 entries, each ≤ 200 characters.

### 3. Output

`ScriptsModule` hooks `wp_head`, `wp_body_open` and `wp_footer` once each at priority 1 and, inside, iterates enabled, in-scope scripts sorted by `priority` then slug, echoing each snippet wrapped in `\n<!-- proto-blocks: <slug> -->\n … \n` markers (filter `proto_blocks_scripts_markers` to drop them). Each snippet is echoed exactly as stored. Per-location priority override: `"priorities": { "head": 1, "bodyClose": 99 }` optional on the script. Snippets never render in `is_admin()`, feeds, REST, embeds (`is_embed()`), the customizer preview, or the block-editor iframe (`proto_blocks_scripts_enabled` filter, bool, can veto per request). Themes without `wp_body_open()` get a `missing_body_open` warning in the issues list when any script uses `bodyOpen`.

### 4. PHP declarations

`proto_script(string $title, array $args)` with the same keys; inline strings only (no `file` form in PHP). Virtual origin "PHP", `double_declaration` when a file folder has the same slug, file wins whole. Sealed at `wp_head`-time (`template_redirect` priority 1) with `declared_too_late` after. Defined in `includes/Modules/Scripts/functions.php`.

### 5. Trust

- Reading the screen: `manage_options`. **Writing** any script file requires `manage_options` AND `unfiltered_html`; otherwise the screen is read-only with reason `unfiltered_html` ("Only users who may post unfiltered HTML can edit scripts"). File-mods and multisite rules as everywhere (`DISALLOW_UNFILTERED_HTML` therefore disables writes site-wide).
- Output is raw by design: anyone who can write these files can run JavaScript on the front end, exactly as someone who can edit the theme. `docs/trust-and-security.md` says so.
- No PHP execution: `<?php`, `<?=` and `<?` followed by whitespace in any snippet → `php_in_snippet` error. Snippets are never `include`d.
- `UntrustedKeys` stripping applies to `script.jsonc` as to every file. `title` refuses `<`/`>`.

### 6. Admin UI

Submenu **Scripts** under Proto-Blocks, hook `proto-blocks-scripts`, screen `scripts`, registered like the others. List: title, enabled switch, locations present (H / B / F badges), where summary, priority, origin badge, actions. Add/edit page (`?slug=` / `?new=1`): Title, Enabled, Priority, three code editors (monospace textarea with line numbers via the existing code field component if one exists, else a plain `<textarea class="font-mono">`), Placement builder (target picker: select of target kinds + value input, chips for include/exclude; logged-in radio; roles multiselect from `wp_roles` passed in bootstrap), Save/Delete. Saves write `script.jsonc` with `jsonc-parser` edits (comments preserved) plus the three `.html` files through `admin/scripts/{slug}/snippets` in one request with the JSONC hash; the server writes the four files and returns the new hash.

### 7. REST and CLI

- `GET admin/scripts` → `{ moduleEnabled, createTarget, canWrite, writeReason, roles, items:[{slug,title,enabled,priority,locations:[...],where,origin,double,issues}], issues }`.
- `GET admin/scripts/{slug}` → metadata file (via schema-files) plus `{ snippets: { head, bodyOpen, bodyClose } }` text; `POST admin/scripts/{slug}` `{ text, hash, snippets }` → `{ hash, path, warnings }`; `DELETE` through schema-files.
- `wp proto-blocks script list|create <title>|validate|enable <slug>|disable <slug>|delete <slug> --confirm=<slug>`.

### 8. Tests

- PHPUnit: target evaluator for every target (conditional-tag stubs in bootstrap: `is_front_page`, `is_home`, `is_search`, `is_404`, `is_archive`, `is_singular`, `is_post_type_archive`, `is_page`, `is_single`, `is_tax`, `is_category`, `is_tag`, `has_term`, `is_embed`, `is_feed`, `is_customize_preview`, `wp_roles`), include/exclude precedence, loggedIn/roles, `url:` glob edge cases (`*` across segments, trailing slash, encoded chars, query ignored); output order by priority then slug, markers, per-location priority; disabled/absent locations; PHP declaration, double, sealing; file kind (loader, validator every code incl. `php_in_snippet`, `invalid_snippet_file`, inline vs file form, missing referenced file → `snippet_file_missing` warning and empty output), snippet writer confinement, delete safety; `unfiltered_html` write gate; REST shapes; CLI.
- Jest: list, editor form validation, placement builder, read-only (`unfiltered_html` reason), save payload snapshot.
- Playwright: fixture script in the theme → head/body-open/body-close markers in the right places on the front page and not on an excluded page; create via UI and read the four files back; PHP badge and declared-twice; read-only when the test user lacks `unfiltered_html` (create an editor with `wp user create`); screenshot baselines.

### 9. Docs and skill

`docs/scripts.md` (house pattern), README (features, content-model table, screens, filters), `docs/content-model-files.md`, `docs/cli.md`, `docs/rest-api.md`, `docs/trust-and-security.md`, CHANGELOG; skill reference `skills/protoblocks/references/scripts.md` + pointers (ships in skill 2.5.0 together with redirects).

## Calls made by the author, open to objection

- Snippets as sibling `.html` files with fixed names; inline strings allowed for short metas.
- `unfiltered_html` required to write; raw output with HTML comment markers.
- Include/exclude target grammar above; `url:` globs rather than regex.
- Front end only; priority 1 hooks with internal ordering.
