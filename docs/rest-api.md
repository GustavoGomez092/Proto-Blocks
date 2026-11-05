# REST API

> **At a glance**
> - **What:** every route in the `proto-blocks/v1` namespace: the admin screens, declaration files, field editing, settings pages and the block editor.
> - **Where:** `https://<site>/wp-json/proto-blocks/v1/...` (or `?rest_route=/proto-blocks/v1/...`).
> - **PHP:** routes register on `rest_api_init`; nothing to call.
> - **Admin:** every Proto-Blocks screen is a client of these routes.
> - **CLI:** the CLI uses the same writer and validators directly, not HTTP.

Related: [content model files](content-model-files.md), [post types](post-types.md), [taxonomies](taxonomies.md), [fields](fields.md), [settings pages](settings-pages.md), [redirects](redirects.md), [scripts](scripts.md), [trust and security](trust-and-security.md).

## Conventions

- Cookie-authenticated requests send the REST nonce in `X-WP-Nonce` (`wp_create_nonce('wp_rest')`). Without it, core treats the request as logged out.
- A permission callback that answers `false` gives core's `rest_forbidden`: `401` when logged out, `403` when logged in.
- Plugin errors are `WP_Error` objects: `{"code": "proto_blocks_…", "message": "…", "data": {"status": 409, …}}`. Extra data (a `hash`, `guardrails`, `errors`) sits next to `status` in `data`.
- No response carries an absolute server path. Paths are relative to the WordPress root or the theme folder (`wp-content/themes/acme/proto-types/event/type.jsonc`, `proto-fields/event-details/group.jsonc`).
- *Writes files* marks a route that writes into the theme. It also needs file modifications on and, on multisite, a network administrator.

## Permissions

| Group | Permission |
|---|---|
| `admin/*` read routes | `manage_options` |
| `admin/*` routes that write files | `manage_options`, `DISALLOW_FILE_MODS` and `DISALLOW_FILE_EDIT` false, and `is_super_admin()` on multisite |
| `admin/scripts/<slug>` write routes | the file-write gate, and `unfiltered_html` |
| `admin/redirects/import/deactivate` | `activate_plugins` |
| `options/<slug>` | a logged-in user with the page's declared capability |
| `fields/errors` | the object's edit capability (`edit_post`, `edit_term`, `edit_user`, or the page capability) |
| `fields/relationship`, `blocks`, `blocks/<name>`, `preview`, `controls/options` | `edit_posts` |
| `cache` | `manage_options` |
| `preview-capture` | `manage_options` and file modifications on |

A file-writing route refused for file mods answers `403 proto_blocks_file_mods` ("File modifications are disabled on this site.").
On multisite, a non-network administrator gets `403 proto_blocks_multisite` ("Only network administrators can write theme files on a multisite network.").
File mods win when both apply.

## Error codes

| Status | Code | When |
|---|---|---|
| 400 | `proto_blocks_invalid_slug` | the slug fails the kind's rule (`data.reason`: `pattern`, `length`, `reserved`) |
| 400 | `proto_blocks_invalid_request` | the body has the wrong shape |
| 400 | `proto_blocks_invalid_jsonc` | the text is not valid JSONC (`data.line`, `data.column`) |
| 400 | `proto_blocks_schema_invalid` | the declaration fails the schema or a module rule (`data.errors`: `[{path, message}]`) |
| 400 | `proto_blocks_slug_mismatch` | the `"slug"` key differs from the URL slug |
| 403 | `proto_blocks_file_mods` | file modifications are disabled |
| 403 | `proto_blocks_multisite` | multisite and not a network administrator |
| 403 | `proto_blocks_path_outside_root` | the file is outside the active and parent theme |
| 403 | `proto_blocks_not_writable` | the theme folder or file is not writable |
| 403 | `proto_blocks_forbidden` | `fields/errors` for an object the user may not edit |
| 403 | `proto_blocks_protected_option` | a settings page save targets a protected option |
| 404 | `proto_blocks_not_found` | no such type, taxonomy, file or field group |
| 404 | `proto_blocks_options_page_not_found` | no settings page with that slug |
| 409 | `proto_blocks_conflict` | the file changed on disk since it was loaded (`data.hash`: the current hash) |
| 409 | `proto_blocks_needs_confirmation` | a save affects existing content (`data.guardrails`) |
| 409 | `proto_blocks_confirm_required` | a delete needs `confirm=<slug>` (`data.content`) |
| 409 | `proto_blocks_not_a_file` | the declaration is PHP or inline; it cannot be deleted here |
| 409 | `proto_blocks_classic_theme` | template scaffolding on a classic theme |
| 413 | `proto_blocks_file_too_large` | the text is over 256 KB |
| 422 | `proto_blocks_invalid_values` | a settings page save has invalid values; nothing was saved (`data.errors`: `{path: message}`) |
| 500 | `proto_blocks_write_failed` | the file could not be written or deleted |

Guardrail codes, inside `data.guardrails[].code` of a `409 proto_blocks_needs_confirmation`:

| Code | Meaning |
|---|---|
| `supports_removed` | `title`, `editor` or `thumbnail` removed from `supports` while posts exist |
| `taxonomy_removed` | an inline taxonomy with terms removed from a type |
| `field_removed` | a field renamed or removed while content exists; stored values stay under the old key |

## Declaration files

`admin/schema-files/<kind>/<slug>` reads, saves and deletes a declaration file.

| `kind` | File |
|---|---|
| `type` | `proto-types/<slug>/type.jsonc` |
| `taxonomy` | `proto-taxonomies/<slug>/taxonomy.jsonc` |
| `options` | `proto-options/<slug>/page.jsonc` |
| `field-group` | `proto-fields/<slug>/group.jsonc` (present while the Fields module is on) |

The URL pattern accepts `[a-z][a-z0-9_-]{0,63}`; each kind then applies its own slug rule.

### GET `admin/schema-files/<kind>/<slug>`

Permission: `manage_options`.
Query: `name` (optional, at most 100 characters), the name a new file's template starts from.

```json
{
  "exists": true,
  "path": "wp-content/themes/acme/proto-types/event/type.jsonc",
  "text": "{\n  \"name\": \"Event\"\n}\n",
  "hash": "3b0c…",
  "data": { "name": "Event" },
  "writable": true,
  "reason": null
}
```

- For a slug with no file yet, `exists` is `false`, `hash` is `null`, and `text` is the commented new-file template.
- A file the loader rejected (broken JSONC, schema errors) is still served, so it can be fixed.
- `reason` is `file_mods`, `not_writable` or `outside_root` when `writable` is `false`.

### POST (or PUT, PATCH) `admin/schema-files/<kind>/<slug>`

Permission: write (*writes files*).

```json
{ "text": "<the whole file>", "hash": "<hash from GET, or null for a new file>", "acknowledge": ["supports_removed"] }
```

Order of checks:

1. body shape (`400 proto_blocks_invalid_request`),
2. target writable (`403` codes above),
3. hash (`409 proto_blocks_conflict`),
4. content guardrails, unless every finding's code is in `acknowledge` (`409 proto_blocks_needs_confirmation`); `type` and `taxonomy` only,
5. size, JSONC, schema, module rules, slug key, confinement (`413`, `400`, `403`),
6. atomic write (`500 proto_blocks_write_failed`).

Success:

```json
{
  "path": "wp-content/themes/acme/proto-types/event/type.jsonc",
  "hash": "9e41…",
  "created": false,
  "warnings": [
    { "level": "warning", "code": "icon_invalid", "message": "The menu icon …", "source": "…", "slug": "event", "kind": "type" }
  ]
}
```

A guardrail refusal:

```json
{
  "code": "proto_blocks_needs_confirmation",
  "message": "This change affects existing content. Confirm to save it.",
  "data": {
    "status": 409,
    "guardrails": [
      { "code": "supports_removed", "message": "Removing editor hides it on 12 existing event posts. Their content is kept.", "count": 12, "detail": ["editor"] }
    ]
  }
}
```

A schema refusal:

```json
{
  "code": "proto_blocks_schema_invalid",
  "message": "The declaration does not match the schema.",
  "data": { "status": 400, "errors": [ { "path": "/menu_position", "message": "…" } ] }
}
```

### DELETE `admin/schema-files/<kind>/<slug>?confirm=<slug>`

Permission: write (*writes files*).

- `type` / `taxonomy`: `confirm` is required only while the type has posts or its taxonomies have terms (`409 proto_blocks_confirm_required` with `data.content: {count, taxonomies}`).
- `field-group`: `confirm` is always required (stored values become orphaned).
- PHP or inline declarations: `409 proto_blocks_not_a_file`.

```json
{ "deleted": "wp-content/themes/acme/proto-types/event/type.jsonc", "kept": [] }
```

`kept` lists files that kept the folder.
Content is never deleted.

## Post types and taxonomies

### GET `admin/types`, GET `admin/taxonomies`

Permission: `manage_options`.

```json
{
  "moduleEnabled": true,
  "createTarget": { "path": "wp-content/themes/acme/proto-types", "writable": true, "reason": null },
  "items": [
    {
      "slug": "event",
      "label": "Events",
      "origin": "file",
      "originLabel": "Proto-Blocks file",
      "source": "wp-content/themes/acme/proto-types/event/type.jsonc",
      "override": false,
      "double": null,
      "count": 12,
      "editable": true,
      "readOnlyReason": null,
      "issues": []
    }
  ],
  "issues": []
}
```

- `origin`: `file`, `php`, `core`, `plugin` or `theme`; `originLabel` is its display text (`Plugin: WooCommerce`).
- `double`: `{file, php}` when declared twice.
- `count`: posts (every status but `auto-draft`) or terms.
- `readOnlyReason`: see [read-only reasons](content-model-files.md#read-only-reasons).
- Taxonomy items add `readOnlyMessage`, `objectTypes` (attached post types) and `parentType` (the type that declares it inline, or `null`).
- The top-level `issues` are findings not tied to a listed item. Each issue is `{level, code, message, source, slug, kind}`.

### GET `admin/types/<slug>`

Slug pattern `[a-z][a-z0-9_-]{0,19}`.

```json
{
  "item": { "slug": "event", "…": "…" },
  "effective": { "public": true, "has_archive": true, "supports": ["title", "editor", "thumbnail", "excerpt", "revisions"], "…": "…" },
  "content": { "count": 12, "taxonomies": { "event-type": 4 } },
  "blockTheme": true,
  "templates": [ { "name": "single-event.html", "exists": false }, { "name": "archive-event.html", "exists": true } ]
}
```

Errors: `400 proto_blocks_invalid_slug`, `404 proto_blocks_not_found`.

### POST `admin/types/<slug>/scaffold`

Permission: write (*writes files*).
Body: `{"taxonomies": true}` to add `taxonomy-<slug>.html` templates.

```json
{ "written": ["single-event.html"], "skipped": ["archive-event.html"] }
```

Errors: `400 proto_blocks_invalid_slug`, `404 proto_blocks_not_found`, `409 proto_blocks_classic_theme`, `403 proto_blocks_file_mods` / `proto_blocks_path_outside_root` / `proto_blocks_not_writable`.

### GET `admin/taxonomies/<slug>`

Slug pattern `[a-z][a-z0-9_-]{0,31}`.

```json
{ "item": { "slug": "topic", "…": "…" }, "effective": { "…": "…" }, "objectTypes": ["event", "post"], "content": { "count": 7 } }
```

## Fields

### GET `fields/errors?object=<ref>`

The errors the current user's last save left for an object, read once (kept for five minutes).
`object` is `post:<id>`, `term:<id>`, `user:<id>` or `option:<page>`.
Permission: the object's edit capability; `403 proto_blocks_forbidden` otherwise.

```json
{ "errors": { "agenda.1.title": "This field is required." }, "submitted": { "agenda": [ { "title": "" } ] } }
```

`submitted` values are raw, exactly as posted; render them only as form values, never as HTML.

### GET `fields/relationship`

The relationship picker's search.
Permission: `edit_posts`.

| Query | Meaning |
|---|---|
| `group` | the group key |
| `field` | the field name or key |
| `search` | optional, at most 100 characters |
| `page` | optional, 1–1000 |

```json
{ "items": [ { "id": 51, "title": "Ada Lovelace", "type": "speaker", "status": "publish" } ], "page": 1, "hasMore": false }
```

- 20 items per page; no total is given.
- Filters come from the field's declaration only (`post_type`, `taxonomy`); other query parameters are ignored.
- Only published posts and private posts the user may read are returned.
- Titles are raw (`get_the_title()`); render them as text.
- `404 proto_blocks_not_found` when no relationship field matches.

### GET `admin/fields`

Permission: `manage_options`.

```json
{
  "moduleEnabled": true,
  "acfActive": false,
  "createTarget": { "path": "wp-content/themes/acme/proto-fields", "writable": true, "reason": null },
  "groups": [
    {
      "key": "group_event_details",
      "title": "Event details",
      "origin": "file",
      "file": { "kind": "field-group", "slug": "event-details", "path": "proto-fields/event-details/group.jsonc" },
      "source": "proto-fields/event-details/group.jsonc",
      "location": "post_type == event",
      "fieldCount": 6,
      "position": "acf_after_title",
      "status": "active",
      "issues": [],
      "doubleDeclared": false,
      "editable": true,
      "readOnlyReason": null
    }
  ],
  "issues": []
}
```

- `origin`: `file`, `php`, `type-file`, `taxonomy-file` or `acf`.
- `file`: `{kind, slug, index?, path}`, or `null` for PHP and ACF groups. `index` is the group's position in a file's `fields` list or an ACF export.
- `status`: `active`, `unrenderable` or `skipped-acf`.
- `readOnlyReason`: `file_mods`, `multisite`, `not_writable`, `acf`, `inline` or `null`.
- Issues are `{level, code, path, message}`.

### GET `admin/fields/<key>`

Key pattern `[A-Za-z0-9_-]+`.

```json
{
  "group": { "key": "group_event_details", "title": "Event details", "fields": [ "…" ], "location": [ "…" ] },
  "issues": [],
  "file": { "exists": true, "path": "…", "text": "…", "hash": "…", "data": {}, "writable": true, "reason": null,
            "kind": "field-group", "slug": "event-details", "index": null },
  "readOnlyReason": null,
  "postTypes": [ { "slug": "event", "label": "Events" } ],
  "taxonomies": [ { "slug": "topic", "label": "Topics" } ],
  "roles": [ { "slug": "editor", "label": "Editor" } ],
  "optionsPages": [ { "slug": "theme-settings", "label": "Theme Settings" } ],
  "pageTemplates": [ { "slug": "page-event-landing.html", "label": "Event landing" } ]
}
```

`group` is the group exactly as declared, ACF-only keys included.
`404 proto_blocks_not_found` for an unknown key.

### POST `admin/fields/validate`

Validates group text without saving.
Permission: `manage_options`.

```json
{ "text": "<file text>", "kind": "field-group", "index": null }
```

- `kind` is `field-group`, `type` or `taxonomy`. For `type` and `taxonomy` the groups in `fields` are checked, or only the one at `index`.

```json
{ "errors": [ { "path": "fields[2].name", "code": "invalid_name", "message": "…" } ], "warnings": [] }
```

Errors: `400 proto_blocks_invalid_request`, `400 proto_blocks_invalid_jsonc` (`line`, `column`), `413 proto_blocks_file_too_large`.

Saving a group goes through `admin/schema-files/field-group/<slug>` (or `type` / `taxonomy` for an inline group).

### Field values through core

Field values are not written by a plugin route.
They save through the post, term and user forms, and through core's endpoints (`wp/v2/<rest_base>/<id>` with a `meta` object) under the fields' auth and sanitise callbacks.
See [fields: REST](fields.md#rest).

## Settings pages

### GET `options/<slug>`

Permission: a logged-in user with the page capability.

```json
{ "values": { "phone": "+1 555 0100", "head_scripts": null }, "trusted": false, "readOnly": { "head_scripts": "unfiltered_html" } }
```

- `values`: the stored (raw) value of every page field, or its default.
- `trusted`: whether the user has `unfiltered_html`.
- `readOnly`: fields this user may not write, with the reason; their values are `null`.

### POST `options/<slug>`

Permission: as GET.

```json
{
  "fields":  { "group_theme_settings_1": { "phone": "+1 555 0100" } },
  "present": { "group_theme_settings_1": ["phone"] }
}
```

- `fields`: group key → field name → value.
- `present`: group key → the field names to write. Fields not listed are untouched.
- Group and field keys of a page file are generated: `group_<slug_underscored>_<n>` and `field_<slug_underscored>_<name>`; GET `admin/options-pages/<slug>` shows them.

```json
{ "values": { "phone": "+1 555 0100", "head_scripts": null }, "notices": [ { "field": "head_scripts", "message": "Only users with unfiltered HTML may edit this field." } ] }
```

- All or nothing: any invalid value answers `422 proto_blocks_invalid_values` with `data.errors` (`{"phone": "This field is required."}`) and nothing is saved.
- `403 proto_blocks_protected_option` when a target option is protected.
- `404 proto_blocks_options_page_not_found` for an unknown slug.

### GET `admin/options-pages`

Permission: `manage_options`.

```json
{
  "moduleEnabled": true,
  "createTarget": { "path": "wp-content/themes/acme/proto-options", "writable": true, "reason": null },
  "pages": [
    {
      "slug": "theme-settings", "title": "Theme Settings", "menu": "top", "menuLabel": "Top level",
      "capability": "manage_options", "storage": "Per field", "origin": "file", "originLabel": "Proto-Blocks file",
      "double": null, "source": "wp-content/themes/acme/proto-options/theme-settings/page.jsonc",
      "editable": true, "readOnlyReason": null, "children": ["theme-settings-footer"], "warnings": [],
      "url": "https://example.test/wp-admin/admin.php?page=theme-settings"
    }
  ],
  "external": [ "…" ],
  "capabilities": [ "…" ],
  "warnings": [ { "slug": "…", "source": "…", "message": "…" } ]
}
```

`storage` reads `Per field` or `Array: <option>`.
`external` lists other plugins' settings pages captured from the admin menu (slugs and titles only).

### GET `admin/options-pages/<slug>`

```json
{ "page": { "…": "as a list row" }, "declaration": { "title": "Theme Settings", "…": "…" }, "parents": [ { "slug": "options-general.php", "title": "Settings" } ], "capabilities": [ "…" ] }
```

It resolves the slug through the registry only and returns no option values.
`404 proto_blocks_options_page_not_found` for an unknown slug.

## Redirects

Guide: [redirects](redirects.md).
Reads need `manage_options`. Writes to files go through the file-write gate and the existing `admin/schema-files/redirects/<slug>` and `admin/schema-files/redirect-settings/settings` routes.

### GET `admin/redirects`

```json
{
  "moduleEnabled": true,
  "paused": false,
  "createTarget": { "path": "wp-content/themes/acme/proto-redirects", "writable": true, "reason": null },
  "groups": [
    { "slug": "legacy-site", "title": "Legacy site", "enabled": true, "position": 10, "origin": "file", "double": null,
      "writable": true, "hash": "3b0c...",
      "rules": [ { "id": "r_8f3a2c1d", "from": "/about-us", "to": "/about", "hits": 12, "lastHit": "2026-11-05 10:00:00", "issues": [] } ] }
  ],
  "settings": { },
  "settingsFile": { "exists": true, "hash": "9a1d...", "writable": true },
  "issues": [],
  "db": { "tables": true, "orphans": 0 }
}
```

`paused` is true while the Redirection plugin is active and `coexist` is false.
Each rule carries the keys of the file plus `hits`, `lastHit` and `issues`.

### GET `admin/redirects/test?url=`

```json
{ "matched": true, "group": "legacy-site", "ruleId": "r_8f3a2c1d", "action": "url", "code": 301, "target": "/about",
  "trace": [ { "group": "legacy-site", "ruleId": "r_8f3a2c1d", "result": "matched", "reason": "" } ] }
```

It evaluates without redirecting. `login` and `role` conditions use the calling admin's session, and the response says so.

### Logs

| Method | Route | Notes |
|---|---|---|
| GET | `admin/redirects/log`, `admin/redirects/404` | query: `page`, `per_page` (5 to 200), `search`, `code`, `rule`, `from`, `to`, `group_by=url` |
| DELETE | `admin/redirects/log`, `admin/redirects/404` | body or query: `ids[]`, or `all=1` with the current filter |
| GET | `admin/redirects/log/export?format=csv`, `admin/redirects/404/export?format=csv` | CSV, formula-escaped |

### Hits, import, export, tables

| Method | Route | Purpose |
|---|---|---|
| POST | `admin/redirects/hits/reset` | body `{group, ruleId}` |
| POST | `admin/redirects/hits/prune` | remove orphan hit rows |
| POST | `admin/redirects/import/parse` | multipart upload or `{format, text}`. Returns `{ rules: [{rule, status, reason}], groups? }`. |
| GET | `admin/redirects/import/plugins` | importers whose data is present on this site |
| POST | `admin/redirects/import/plugins/<id>/preview` | preview one plugin's rules |
| POST | `admin/redirects/import/deactivate` | deactivate the source plugin. Needs `activate_plugins`. |
| GET | `admin/redirects/export?format=csv\|json\|apache\|nginx\|redirects&group=` | file response |
| POST | `admin/redirects/db/install` | create the three tables |

Uploads are capped at 5 MB. Nothing in an upload is executed.

## Scripts

Guide: [scripts](scripts.md).

### GET `admin/scripts`

Permission: `manage_options`.

```json
{
  "moduleEnabled": true,
  "createTarget": { "path": "wp-content/themes/acme/proto-scripts", "writable": true, "reason": null },
  "canWrite": true,
  "writeReason": null,
  "roles": [ { "slug": "administrator", "name": "Administrator" } ],
  "items": [
    { "slug": "gtm", "title": "Google Tag Manager", "enabled": true, "priority": 10,
      "locations": [ "head", "bodyOpen" ], "where": { "include": [ "all" ] },
      "origin": "file", "double": null, "issues": [] }
  ],
  "issues": []
}
```

`writeReason` is `unfiltered_html` when the user may not post unfiltered HTML, besides the usual read-only reasons.

### GET `admin/scripts/<slug>`

The metadata file as the schema-files route returns it (`text`, `hash`, `data`), plus the snippet texts:

```json
{ "snippets": { "head": "<script>...</script>", "bodyOpen": "", "bodyClose": "" } }
```

### POST `admin/scripts/<slug>`

Permission: write (*writes files*) and `unfiltered_html`.

```json
{ "text": "<script.jsonc text>", "hash": "<hash from GET, or null>", "snippets": { "head": "...", "bodyOpen": "", "bodyClose": "" } }
```

The server writes `script.jsonc` and the three `.html` files in one request and returns `{ "hash": "...", "path": "...", "warnings": [] }`.
Delete goes through `DELETE admin/schema-files/script/<slug>?confirm=<slug>`.

## Admin screens (other)

These routes back the Blocks, Tailwind, System, Previews and Setup Wizard screens.
All need `manage_options`.

| Method | Route | Purpose |
|---|---|---|
| GET | `admin/dashboard` | stats, blocks list, example-block state |
| DELETE | `admin/cache` | clear the template cache |
| POST | `admin/example-blocks` | toggle the bundled example blocks |
| POST | `admin/demos/install`, `admin/demos/remove` | install or remove demo blocks (*writes files*; not used by the UI) |
| GET | `admin/tailwind` | Tailwind settings and status |
| POST | `admin/tailwind/settings` | save Tailwind settings |
| POST | `admin/tailwind/compile` | compile Tailwind CSS |
| DELETE | `admin/tailwind/cache` | clear the Tailwind cache |
| POST | `admin/tailwind/cli` | download the Tailwind CLI (*writes files*) |
| POST | `admin/tailwind/theme-file` | write the theme Tailwind file (*writes files*) |
| GET, POST | `admin/system` | system status; save the category name |
| GET | `admin/previews` | Preview Capture status per block |
| GET | `admin/wizard` | setup wizard state |
| POST | `admin/wizard/style`, `admin/wizard/complete` | wizard steps |
| POST | `admin/wizard/demos` | install demo blocks for a style (*writes files*; not used by the UI) |

## Block editor

| Method | Route | Permission | Purpose |
|---|---|---|---|
| GET | `blocks` | `edit_posts` | every registered Proto-Block |
| GET | `blocks/<name>` | `edit_posts` | one block's settings |
| POST | `preview` | `edit_posts` | render a preview: `{template, attributes, postId?}`; with `postId` the user may edit, `$template->field()` reads that post |
| GET | `controls/options?source=wp:posts&args=<json>` | `edit_posts` | dynamic control and field choices |
| GET, DELETE | `cache` | `manage_options` | template cache stats; clear it |
| POST | `preview-capture` | `manage_options`, file mods on | store a captured block preview `{block, image}` (*writes files*) |
