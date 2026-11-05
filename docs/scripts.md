# Scripts

> **At a glance**
> - **What:** head, body-open and body-close snippets (tracking tags, verification metas, chat widgets, consent scripts) declared as files in the theme and output on every front-end page.
> - **Where:** `your-theme/proto-scripts/<slug>/script.jsonc`, plus `head.html`, `body-open.html` and `body-close.html` beside it.
> - **PHP:** `proto_script('GTM', ['head' => '<script>...</script>']);` in `functions.php`.
> - **Admin:** Proto Blocks > Scripts (`admin.php?page=proto-blocks-scripts`) lists scripts and edits them with code editors.
> - **CLI:** `wp proto-blocks script list|create|validate|enable|disable|delete`.

Each snippet is output exactly as stored, at one of three places: `<head>`, right after `<body>` and before `</body>`.
A snippet can be limited to parts of the site, to logged-in or logged-out visitors, and to roles.
The module is independent: switch it off with the `scripts` key of the `proto_blocks_modules` filter.

Related: [content model files](content-model-files.md), [redirects](redirects.md), [REST API](rest-api.md#scripts), [CLI](cli.md#wp-proto-blocks-script), [trust and security](trust-and-security.md#scripts-raw-output).

## Declare a script

### In PHP

```php
// functions.php
proto_script('Google Tag Manager', [
    'head'      => '<script>/* GTM loader */</script>',
    'bodyOpen'  => '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-XXXX"></iframe></noscript>',
    'where'     => ['exclude' => ['page:privacy-policy']],
]);
```

```php
proto_script(string $title, array $args): void
```

- `$args` takes the same keys as `script.jsonc`, plus an optional `slug`. Without it the slug is the slugified title. An invalid slug is `invalid_slug`.
- Locations are inline strings only. A `{ "file": ... }` value is refused with `invalid_snippet_file`. `php_in_snippet` and `snippet_too_large` apply here too.
- The script's origin is "PHP". The admin lists it read-only (reason `php`).
- A second call with the same slug replaces the first, with a `duplicate_php_declaration` warning.
- A file folder with the same slug wins whole, with the `double_declaration` warning and badge.
- Declarations are sealed at `template_redirect` priority 1. A call after that is ignored with a `declared_too_late` warning.

### In a file

```jsonc
// proto-scripts/gtm/script.jsonc
{
  "title": "Google Tag Manager",
  "enabled": true,
  "priority": 10,
  "head": { "file": "head.html" },
  "bodyOpen": { "file": "body-open.html" },
  "bodyClose": "",
  "priorities": { "head": 1, "bodyClose": 99 },
  "where": {
    "include": ["all"],
    "exclude": ["page:privacy-policy", "url:/landing/*"],
    "loggedIn": null,
    "roles": [],
    "excludeRoles": ["administrator"]
  }
}
```

```html
<!-- proto-scripts/gtm/head.html -->
<script>(function(w,d,s,l,i){/* GTM loader */})(window,document,'script','dataLayer','GTM-XXXX');</script>
```

- The folder name is the slug: `^[a-z][a-z0-9_-]*$`, 32 characters at most (`invalid_slug` otherwise). A child theme's folder beats the parent's.
- Only the three fixed file names `head.html`, `body-open.html` and `body-close.html` are ever read or written. Each is capped at 256 KB, and so is `script.jsonc`.
- Deleting a script deletes the folder only when it holds a valid `script.jsonc` and nothing but the three snippet files. Otherwise only `script.jsonc` goes, the folder and whatever else is in it stay, and the response (and the CLI) list what was kept.
- A script with any error is skipped whole (fail closed). A broken child-theme file also drops the parent theme's file and the PHP declaration of the same slug (`broken_override`).

## Every key

| Key | Type | Default | Meaning |
|---|---|---|---|
| `title` | string | none, **required** | 1 to 100 characters, shown in the UI. `<` and `>` are refused. In PHP it is the first argument. |
| `enabled` | bool | `true` | |
| `priority` | integer, -100000 to 100000 | `10` | Within each location. Lower runs first. |
| `priorities` | object | none | Per-location override, for example `{ "head": 1, "bodyClose": 99 }`. |
| `head` | string or `{ "file": "head.html" }` | none | Snippet for `<head>`. |
| `bodyOpen` | string or `{ "file": "body-open.html" }` | none | Snippet right after `<body>`. |
| `bodyClose` | string or `{ "file": "body-close.html" }` | none | Snippet before `</body>`. |
| `where.include` | list of targets | `["all"]` | A page is in scope when it matches any entry. An explicit `[]` matches nowhere (`never_matches` warning). |
| `where.exclude` | list of targets | `[]` | A match here removes the page from scope. |
| `where.loggedIn` | `null`, `true`, `false` | `null` | `null` anyone, `true` only logged-in, `false` only logged-out. |
| `where.roles` | list of role slugs | `[]` | When not empty, only users with one of these roles. Implies logged-in. Together with `loggedIn: false` it is the error `conflicting_where`. |
| `where.excludeRoles` | list of role slugs | `[]` | Logged-in users with one of these roles never get the script. It never excludes logged-out visitors. |

- Unknown keys are refused (`schema_invalid`) at the top level and inside `where` and `priorities`. Only `$schema` is allowed besides the keys above.
- A location is a string (inline) or `{ "file": "<one of the three names>" }`. Any location may name any of the three files. An empty string or an absent key means nothing at that location.
- A `file` value that is not one of the three names is `invalid_snippet_file`.
- A referenced file that does not exist is a `snippet_file_missing` warning and outputs nothing.
- An inline snippet or a snippet file over 256 KB is `snippet_too_large`.
- Role lists and target lists hold 100 entries at most. A target is 200 characters at most. An unknown target is `schema_invalid`.
- A `<?php`, `<?=` or `<?` followed by whitespace in any snippet is `php_in_snippet`. Snippets are text, never included.
- A key that names PHP code, or an object-pollution key such as `__proto__`, is stripped with an `unsafe_key` warning.
- The UI always writes files and reads both forms.

## Placement targets

`include` and `exclude` take these strings.
A page is in scope when it matches any `include` entry and no `exclude` entry.

| Target | True when |
|---|---|
| `all` | every front-end page |
| `front_page` | `is_front_page()` |
| `home` | `is_home()` (the blog index) |
| `search` | `is_search()` |
| `404` | `is_404()` |
| `archive` | `is_archive()` |
| `singular` | `is_singular()` |
| `singular:<post_type>` | `is_singular(type)` |
| `post_type:<type>` | `is_singular(type)` or `is_post_type_archive(type)` |
| `page:<slug, path or id>` | `is_page(x)`. An all-digit value is an ID. `page:0` never matches. |
| `post:<slug or id>` | `is_single(x)`. An all-digit value is an ID. `post:0` never matches. |
| `taxonomy:<tax>` | `is_tax(tax)`, or `is_category()` / `is_tag()` for those two |
| `term:<tax>:<slug>` | the term archive, or a singular post that has that term |
| `url:<pattern>` | the request path matches the glob. The pattern must start with `/`. `*` is any run of characters, including none and including `/`. Case-insensitive, compared against the decoded path without the query. A trailing slash is ignored on both sides, so `/blog/*` also matches `/blog`. On an install in a sub-folder the home path is stripped first. |

```jsonc
"where": { "include": ["singular:post", "taxonomy:category"], "exclude": ["term:category:private", "url:/landing/*"] }
```

## Output order and markers

The module hooks `wp_head`, `wp_body_open` and `wp_footer` once each, at priority 1.
Inside each hook it runs the enabled, in-scope scripts sorted by `priority`, then slug.
Each snippet is echoed exactly as stored, wrapped in markers:

```html

<!-- proto-blocks: gtm -->
<script>...</script>

```

- The `proto_blocks_scripts_markers` filter (bool, default true) drops the markers. It receives the slug and the location (`head`, `bodyOpen` or `bodyClose`).
- `priorities` on a script overrides `priority` for one location.
- Snippets are never printed in the admin, ajax, cron, REST, iframe requests (the block editor and customizer frames), the customizer preview, feeds or embeds.
- The `proto_blocks_scripts_enabled` filter (bool, receives the location) can only switch output off. It cannot switch it on in the contexts above.
- Front end only. There is no admin-area or login-page injection.
- When an in-scope script has a `bodyOpen` snippet and the theme never fires `wp_body_open`, the footer records it in the option `proto_blocks_scripts_body_open` so the admin can show `missing_body_open`.

## Trust rules

- Reading the screen needs `manage_options`.
- Writing any script file needs `manage_options` **and** `unfiltered_html`. Without it a write is refused with `403 proto_blocks_unfiltered_html` ("Only users who may post unfiltered HTML can edit scripts.") and the screen is read-only with the reason `unfiltered_html`.
- The generic `admin/schema-files/script/*` write and delete routes need `unfiltered_html` too, so they are not a way around it.
- File-mods and multisite rules apply as everywhere. Write reasons are reported in the order `file_mods`, `multisite`, `unfiltered_html`. `DISALLOW_UNFILTERED_HTML` therefore disables writes site-wide.
- WP-CLI does not check `unfiltered_html`: the shell user already owns the theme files. The file-mods rule still applies.
- Output is raw by design. Anyone who can write these files can run JavaScript on the front end, exactly like someone who can edit the theme.
- No PHP runs. `<?php` in a snippet is refused (`php_in_snippet`).
- Unsafe-key stripping applies to `script.jsonc` as to every file.

See [trust and security](trust-and-security.md#scripts-raw-output).

## The Scripts screen

Proto Blocks > Scripts.

- **List:** title, enabled switch, locations present (H, B, F badges), a summary of `where`, priority, origin badge (File, PHP, Declared twice) and actions. A script declared in PHP is listed read-only (View, not Edit). The switch saves only `enabled`, through the same write route.
- **Add or edit** (`?new=1` or `?slug=`): Title, Enabled, Priority, three code editors (head, body open, body close) and a placement builder: for include and exclude a select for the kind of target plus a value input, with chips for the entries added; a logged-in choice; and the roles as two checkbox groups, "Only these roles" and "Never for these roles".
- Save writes `script.jsonc` with comment-preserving edits and the snippet files in one request carrying the file hash. The server writes `script.jsonc` first, then each posted snippet. An empty snippet deletes its file.
- Read-only hosts and users without `unfiltered_html` see a banner with the reason and disabled controls.

## REST routes

Under `proto-blocks/v1`. Full shapes: [REST API](rest-api.md#scripts).

| Route | Purpose |
|---|---|
| `GET admin/scripts` | list with `moduleEnabled`, `createTarget`, `canWrite`, `writeReason`, `roles`, `items`, `issues` |
| `GET admin/scripts/{slug}` | the schema-files result (`exists`, `path`, `text`, `hash`, `data`, `writable`, `reason`) plus `snippets`, `item`, `canWrite`, `writeReason`. A slug with no file returns the commented template. |
| `POST admin/scripts/{slug}` | `{ text, hash, snippets }`. `snippets` may be partial and an empty string deletes that file. Returns `{ hash, path, created, warnings }`. |
| `DELETE admin/schema-files/script/{slug}?confirm={slug}` | delete through the shared file route |

Errors: `proto_blocks_unfiltered_html` (403), `proto_blocks_php_in_snippet` (400), `proto_blocks_file_too_large` (413), `proto_blocks_module_disabled` (404), besides the usual file-write errors.

## CLI

```bash
wp proto-blocks script list [--format=<table|json|csv>]
wp proto-blocks script create "Google Tag Manager" [--slug=gtm] [--priority=10]
wp proto-blocks script validate [--format=<table|json|csv>]
wp proto-blocks script enable gtm
wp proto-blocks script disable gtm
wp proto-blocks script delete gtm --confirm=gtm
```

- `delete` warns about the files it kept.
- The commands skip the `unfiltered_html` check and work while the module is off.

Details: [CLI](cli.md#wp-proto-blocks-script).

## What is stored in the database

Scripts are files. The module keeps one bookkeeping option:

| Name | Kind | Holds |
|---|---|---|
| `proto_blocks_scripts_body_open` | option | `{ "<theme>": true }` while the active theme was last seen not firing `wp_body_open`. Autoloaded while set, deleted otherwise. |

## Troubleshooting

| Symptom or code | Cause | Fix |
|---|---|---|
| Nothing appears at the body-open location | The theme does not call `wp_body_open()`. The issues list shows `missing_body_open` when any script uses `bodyOpen`. | Add `wp_body_open();` right after the opening `<body>` tag in the theme. |
| A snippet does not appear anywhere | It is disabled, out of scope, or the request is an admin, ajax, cron, feed, REST, iframe, embed or preview request. | Check `where`, `enabled` and the `proto_blocks_scripts_enabled` filter (it can only switch output off). |
| `php_in_snippet` | A `<?php`, `<?=` or `<?` followed by whitespace, in a file, an inline snippet or a PHP declaration. | Remove it. Snippets are text. |
| `invalid_snippet_file` | A `file` value is not `head.html`, `body-open.html` or `body-close.html`, or a PHP declaration uses `{file}`. | Use one of the three names, or an inline string in PHP. |
| `snippet_file_missing` | The referenced file does not exist. | Create it or remove the key. |
| `snippet_too_large` (`file_too_large`, 413, on a save) | A snippet is over 256 KB. | Load the big part from an external file. |
| `schema_invalid` | An unknown key or target, a missing `title`, or a priority outside -100000 to 100000. | Fix the named path. |
| `conflicting_where` | `roles` together with `loggedIn: false` can never match. | Remove one of them. |
| `never_matches` (warning) | `include` is an empty list. | Remove the key to run everywhere, or add targets. |
| `invalid_slug` | The folder or PHP slug is not `^[a-z][a-z0-9_-]*$`, 32 characters at most. | Rename it. |
| `invalid_jsonc`, `unreadable`, `file_too_large`, `path_outside_root` | `script.jsonc` cannot be parsed or read, is over 256 KB, or resolves outside the theme. The script is skipped. | Fix or replace the file. |
| `both_extensions` (warning) | `script.jsonc` and `script.json` both exist. The `.jsonc` is used. | Delete one. |
| `unsafe_key` (warning) | A key that names PHP code (or a reserved key such as `__proto__`) was stripped from the file. | Remove it. |
| `broken_override` | A child-theme file is broken, so the parent file and PHP declaration of the same slug were dropped too. | Fix the child file. |
| `duplicate_php_declaration` (warning) | `proto_script()` was called twice with the same slug. | Keep one call. |
| `proto_blocks_unfiltered_html` (403) | A write without `unfiltered_html`. | Edit as an administrator who has it. |
| `proto_blocks_module_disabled` (404) | The module is off. | Remove the `scripts` switch-off from the `proto_blocks_modules` filter. |
| `double_declaration` | The same slug is in PHP and in a file. | Delete the PHP call. |
| `declared_too_late` | `proto_script()` after `template_redirect` priority 1. | Call it in `functions.php`. |
| The screen is read-only with `unfiltered_html` | The user lacks `unfiltered_html`, or `DISALLOW_UNFILTERED_HTML` is set. | Edit as an administrator, or edit the files in the theme. |
| The HTML comment markers are unwanted | Markers are on by default. | Return false from `proto_blocks_scripts_markers`. |

## Related

[Content model files](content-model-files.md), [redirects](redirects.md), [REST API](rest-api.md#scripts), [CLI](cli.md#wp-proto-blocks-script), [trust and security](trust-and-security.md#scripts-raw-output).
