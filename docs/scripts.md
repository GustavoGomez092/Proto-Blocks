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

- `$args` takes the same keys as `script.jsonc`. Locations are inline strings only. There is no `file` form in PHP.
- The script's origin is "PHP".
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

- The folder name is the slug. It follows the same slug grammar as every content-model kind. A child theme's folder beats the parent's.
- Only the three fixed file names `head.html`, `body-open.html` and `body-close.html` are ever read or written. Each is capped at 256 KB.
- Deleting a script deletes the folder only when it holds a valid `script.jsonc` and nothing but those four files.

## Every key

| Key | Type | Default | Meaning |
|---|---|---|---|
| `title` | string | the slug | Shown in the UI. `<` and `>` are refused. |
| `enabled` | bool | `true` | |
| `priority` | int | `10` | Within each location. Lower runs first. |
| `priorities` | object | none | Per-location override, for example `{ "head": 1, "bodyClose": 99 }`. |
| `head` | string or `{ "file": "head.html" }` | none | Snippet for `<head>`. |
| `bodyOpen` | string or `{ "file": "body-open.html" }` | none | Snippet right after `<body>`. |
| `bodyClose` | string or `{ "file": "body-close.html" }` | none | Snippet before `</body>`. |
| `where.include` | list of targets | `["all"]` | A page is in scope when it matches any entry. |
| `where.exclude` | list of targets | `[]` | A match here removes the page from scope. |
| `where.loggedIn` | `null`, `true`, `false` | `null` | `null` anyone, `true` only logged-in, `false` only logged-out. |
| `where.roles` | list of role slugs | `[]` | When not empty, only users with one of these roles. Implies logged-in. |
| `where.excludeRoles` | list of role slugs | `[]` | Users with one of these roles never get the script. |

- A location is a string (inline) or `{ "file": "<one of the three names>" }`. An empty string or an absent key means nothing at that location.
- A `file` value that is not one of the three names is `invalid_snippet_file`.
- A referenced file that does not exist is a `snippet_file_missing` warning and outputs nothing.
- The UI always writes files and reads both forms.
- Target lists hold 100 entries at most, each 200 characters at most. An unknown target is `schema_invalid`.
- A `<?php`, `<?=` or `<?` followed by whitespace in any snippet is `php_in_snippet`. Snippets are text, never included.

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
| `page:<slug or id>` | `is_page(x)` |
| `post:<slug or id>` | `is_single(x)` |
| `taxonomy:<tax>` | `is_tax(tax)`, or `is_category()` / `is_tag()` for those two |
| `term:<tax>:<slug>` | the term archive, or a singular post that has that term |
| `url:<pattern>` | the request path matches the glob. `*` is any run of characters. Compared against the decoded path without the query. |

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

- The `proto_blocks_scripts_markers` filter drops the markers.
- `priorities` on a script overrides `priority` for one location.
- Snippets never render in `is_admin()`, feeds, REST, embeds (`is_embed()`), the customizer preview or the block-editor iframe.
- The `proto_blocks_scripts_enabled` filter (bool) can veto output per request.
- Front end only. There is no admin-area or login-page injection.

## Trust rules

- Reading the screen needs `manage_options`.
- Writing any script file needs `manage_options` **and** `unfiltered_html`. Without it the screen is read-only with the reason `unfiltered_html`: "Only users who may post unfiltered HTML can edit scripts."
- File-mods and multisite rules apply as everywhere. `DISALLOW_UNFILTERED_HTML` therefore disables writes site-wide.
- Output is raw by design. Anyone who can write these files can run JavaScript on the front end, exactly like someone who can edit the theme.
- No PHP runs. `<?php` in a snippet is refused (`php_in_snippet`).
- Unsafe-key stripping applies to `script.jsonc` as to every file.

See [trust and security](trust-and-security.md#scripts-raw-output).

## The Scripts screen

Proto Blocks > Scripts.

- **List:** title, enabled switch, locations present (H, B, F badges), a summary of `where`, priority, origin badge (File, PHP, Declared twice) and actions.
- **Add or edit** (`?new=1` or `?slug=`): Title, Enabled, Priority, three code editors (head, body open, body close) and a placement builder: a target picker with chips for include and exclude, a logged-in choice and a roles multiselect.
- Save writes `script.jsonc` with comment-preserving edits and the three `.html` files in one request carrying the file hash. The server writes the four files and returns the new hash.
- Read-only hosts and users without `unfiltered_html` see a banner with the reason and disabled controls.

## REST routes

Under `proto-blocks/v1`. Full shapes: [REST API](rest-api.md#scripts).

| Route | Purpose |
|---|---|
| `GET admin/scripts` | list with `moduleEnabled`, `createTarget`, `canWrite`, `writeReason`, `roles`, `items`, `issues` |
| `GET admin/scripts/{slug}` | the metadata file plus the three snippet texts |
| `POST admin/scripts/{slug}` | `{ text, hash, snippets }`, writes the four files |
| `DELETE admin/schema-files/script/{slug}` | delete through the shared file route |

## CLI

```bash
wp proto-blocks script list
wp proto-blocks script create "Google Tag Manager"
wp proto-blocks script validate
wp proto-blocks script enable gtm
wp proto-blocks script disable gtm
wp proto-blocks script delete gtm --confirm=gtm
```

Details: [CLI](cli.md#wp-proto-blocks-script).

## Troubleshooting

| Symptom or code | Cause | Fix |
|---|---|---|
| Nothing appears at the body-open location | The theme does not call `wp_body_open()`. The issues list shows `missing_body_open` when any script uses `bodyOpen`. | Add `wp_body_open();` right after the opening `<body>` tag in the theme. |
| A snippet does not appear anywhere | It is disabled, out of scope, or the request is an admin, feed, REST, embed or preview request. | Check `where`, `enabled` and the `proto_blocks_scripts_enabled` filter. |
| `php_in_snippet` | A `<?php`, `<?=` or `<?` followed by whitespace. | Remove it. Snippets are text. |
| `invalid_snippet_file` | A `file` value is not `head.html`, `body-open.html` or `body-close.html`. | Use one of the three names. |
| `snippet_file_missing` | The referenced file does not exist. | Create it or remove the key. |
| `double_declaration` | The same slug is in PHP and in a file. | Delete the PHP call. |
| `declared_too_late` | `proto_script()` after `template_redirect` priority 1. | Call it in `functions.php`. |
| The screen is read-only with `unfiltered_html` | The user lacks `unfiltered_html`, or `DISALLOW_UNFILTERED_HTML` is set. | Edit as an administrator, or edit the files in the theme. |
| The HTML comment markers are unwanted | Markers are on by default. | Return false from `proto_blocks_scripts_markers`. |

## Related

[Content model files](content-model-files.md), [redirects](redirects.md), [REST API](rest-api.md#scripts), [CLI](cli.md#wp-proto-blocks-script), [trust and security](trust-and-security.md#scripts-raw-output).
