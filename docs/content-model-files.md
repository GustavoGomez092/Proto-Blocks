# Content model files

> **At a glance**
> - **What:** the rules every declaration file follows: post types, taxonomies, field groups, settings pages, redirects and scripts.
> - **Where:** `proto-types/<slug>/type.jsonc`, `proto-taxonomies/<slug>/taxonomy.jsonc`, `proto-fields/<slug>/group.jsonc`, `proto-options/<slug>/page.jsonc`, `proto-redirects/<group>/redirects.jsonc` and `proto-scripts/<slug>/script.jsonc` in the theme.
> - **PHP:** `proto_post_type()`, `proto_taxonomy()`, `proto_field_group()`, `proto_options_page()`, `proto_redirect()` and `proto_script()` declare the same things without a file.
> - **Admin:** Proto Blocks > Post Types, Taxonomies, Fields, Options Pages, Redirects and Scripts read and write these files.
> - **CLI:** `wp proto-blocks validate` checks every declaration; `wp proto-blocks fields validate` checks field groups.

Declarations are data in files.
Nothing about the content model is ever stored in the database.
The admin screens edit the same files a developer edits by hand.

Related: [post types](post-types.md), [taxonomies](taxonomies.md), [fields](fields.md), [settings pages](settings-pages.md), [redirects](redirects.md), [scripts](scripts.md), [trust and security](trust-and-security.md), [REST API](rest-api.md), [CLI](cli.md).

## File layout

One folder per declaration.
The folder name is the slug.

```
your-theme/
  proto-types/
    event/type.jsonc              post type "event" (+ inline taxonomies and field groups)
    post/type.jsonc               override of the core "post" type
  proto-taxonomies/
    topic/taxonomy.jsonc          standalone taxonomy "topic"
  proto-fields/
    event-details/group.jsonc     standalone field group (or an ACF Tools export)
  proto-options/
    theme-settings/page.jsonc     settings page "theme-settings"
  proto-redirects/
    settings.jsonc                redirect module settings (one file, optional)
    legacy-site/redirects.jsonc   one group of redirect rules, ordered
  proto-scripts/
    gtm/script.jsonc              script metadata and placement
    gtm/head.html                 raw snippet for <head> (optional)
    gtm/body-open.html            raw snippet after <body> (optional)
    gtm/body-close.html           raw snippet before </body> (optional)
```

| Kind | Folder | File name | Slug rule |
|---|---|---|---|
| Post type | `proto-types/` | `type.jsonc` or `type.json` | `^[a-z][a-z0-9_-]*$`, 20 characters at most, not reserved |
| Taxonomy | `proto-taxonomies/` | `taxonomy.jsonc` or `taxonomy.json` | `^[a-z][a-z0-9_-]*$`, 32 characters at most, not reserved |
| Field group | `proto-fields/` | `group.jsonc` or `group.json` | `^[a-z][a-z0-9_-]{0,31}$` |
| Settings page | `proto-options/` | `page.jsonc` or `page.json` | `^[a-z][a-z0-9-]*$`, 64 characters at most, not reserved |
| Redirect group | `proto-redirects/` | `redirects.jsonc` or `redirects.json` | `^[a-z][a-z0-9_-]*$`, 60 characters at most, `settings` reserved |
| Redirect settings | `proto-redirects/` | `settings.jsonc` | none; one file, REST path `redirect-settings/settings` |
| Script | `proto-scripts/` | `script.jsonc` plus `head.html`, `body-open.html`, `body-close.html` | the content-model slug rule `^[a-z][a-z0-9_-]*$` |

The reserved slugs are listed in [post types](post-types.md#slug-rules), [taxonomies](taxonomies.md#slug-rules) and [settings pages](settings-pages.md#slug-rules).

Only the declaration file in each folder is read.
Other files in the folder (an SVG icon, notes) are ignored by the loader.
The exception is `proto-scripts/<slug>/`: its three `.html` files are the snippets, written through the same confined, atomic writer and capped at 256 KB each. Only those three names are ever read or written. See [scripts](scripts.md).

## JSONC conventions

Files are JSONC: JSON with comments and trailing commas.

```jsonc
// proto-types/event/type.jsonc
{
  /* Everything not written here keeps its default. */
  "name": "Event",
  "menu_icon": "icons/event.svg",   // a theme-relative SVG
  "taxonomies": [
    { "name": "Event Type", "hierarchical": true },
  ],                                 // trailing commas are fine
}
```

- `//` line comments and `/* */` block comments are allowed anywhere outside a string.
- Trailing commas before `}` and `]` are allowed.
- A UTF-8 byte order mark is stripped.
- The root must be an object.
- A field group file may also have a list root (an ACF Tools export of several groups).
- A raw newline inside a string is an error. Write `\n`.
- Line and column numbers in errors refer to the original text, comments included.
- A `$schema` key is accepted in every declaration and ignored, so an editor can be pointed at a schema file.

When both `type.jsonc` and `type.json` exist in one folder, `.jsonc` is used.
A `both_extensions` warning names the ignored file.

## Limits

| Limit | Value | What happens |
|---|---|---|
| File size | 256 KB (262144 bytes) | A larger file is never opened (`file_too_large`). A UI save answers `413 proto_blocks_file_too_large`. |
| Nesting depth | 64 levels | Deeper JSON is `invalid_jsonc`. |
| `__proto__` key | refused at any depth | `invalid_jsonc`, with the line number. |
| Encoding | UTF-8 | Invalid JSON is `invalid_jsonc`, with line and column. |
| Menu icon SVG | 32 KB | A larger SVG falls back to the default icon with `icon_invalid`. |

## Discovery and precedence

Files are discovered under the schema roots.
The default roots are, in this order:

1. the parent theme (`get_template_directory()`),
2. the child theme (`get_stylesheet_directory()`), when one is active,
3. any root added with the `proto_blocks_schema_paths` filter.

A later root replaces an earlier root's declaration of the same kind and slug.
So the child theme beats the parent theme.
A root the filter appends beats both; prepend it instead if it should lose.

```php
// An mu-plugin that adds a shared folder of declarations (read-only for the UI).
add_filter('proto_blocks_schema_paths', static function (array $paths): array {
    $paths[] = WP_CONTENT_DIR . '/shared-content-model';
    return $paths;
});
```

Roots are resolved with `realpath()`.
A folder or file that resolves outside its root (a symlink pointing out) is skipped with `path_outside_root`.

### File beats PHP

When a file and a PHP call declare the same kind and slug, the file wins entirely.
Nothing from the PHP call is merged in.
The plugin raises a `double_declaration` warning that names both locations:

- an admin notice on the Proto-Blocks screens, for users with `manage_options`,
- a "Declared twice" badge on the item in the list screens,
- a `warning` row in `wp proto-blocks validate`.

This is the designed outcome of editing a PHP-declared item in the UI.
The UI writes a file, the file takes over, and you delete the PHP line when ready.

### Field groups by key

Field groups are matched by their `key`, not their folder.
A `proto-fields/` file beats a group inside a type or taxonomy file, which beats a PHP group.
A `duplicate_group_key` warning names both.
See [fields](fields.md#precedence-and-errors).

### Broken files

A broken or invalid file is skipped and reported.
Every other declaration still loads.

A broken child-theme `proto-fields/<slug>/group.jsonc` does **not** let the parent theme's file of the same slug back in.
The parent's group is dropped too, with a `broken_override` error.
This stops a child that re-typed a parent's `code` field from silently falling back to the parent's definition.

A broken `proto-options/<slug>/page.jsonc` also suppresses a `proto_options_page()` call of the same slug, so a typo never hands the page to the PHP call's capability.

## The schema files

| File | Validates |
|---|---|
| `includes/ContentModel/schemas/content-model.schema.json` | `type`, `taxonomy` and `options` declarations (`#/$defs/type`, `#/$defs/taxonomy`, `#/$defs/options`) |
| `schemas/field-group.schema.json` | one field group, or an ACF Tools export (a list of groups) |
| `schemas/field-type-aliases.json` | ACF field type names mapped to Proto-Blocks types |

The server checks a subset of JSON Schema: `type`, `enum`, `pattern`, `minLength`, `maxLength`, `minimum`, `maximum`, `properties`, `required`, `additionalProperties`, `items`, `minItems`, `maxItems`, `uniqueItems`, `anyOf`, `allOf`, local `#/$defs/...` references, and `false` for a refused key.
Field groups are checked by the plugin's group validator, which mirrors `field-group.schema.json`.
On top of the schema, modules add their own rules through the `proto_blocks_declaration_errors` filter (field group structure, protected option names).

## How the UI writes files

Every admin edit is a file write.
There is no database fallback.

1. The screen loads the file with `GET proto-blocks/v1/admin/schema-files/<kind>/<slug>`.
   The response carries the text and its SHA-256 `hash`.
2. The form edits the text with a comment-preserving JSONC editor (`jsonc-parser`'s `modify` and `applyEdits`).
   Comments and key order survive.
3. The screen sends the whole new text with `POST` (or `PUT`) to the same route: `{text, hash, acknowledge}`.
4. The server re-parses the text, checks the schema and the modules' rules, checks the path, checks the hash, checks the content guardrails, then writes atomically.

```json
POST /wp-json/proto-blocks/v1/admin/schema-files/type/event
{
  "text": "{\n  \"name\": \"Event\",\n  \"has_archive\": \"events\"\n}\n",
  "hash": "<sha256 of the text you loaded>",
  "acknowledge": []
}
```

The full contract is in [REST API](rest-api.md#declaration-files).

### Comments inside an edited array are lost

The editor replaces an array it changes as one value.
Comments **inside** that array (for example inside `fields`, `tabs`, `children`, `taxonomies` or `supports`) are lost on save.
Comments outside the edited array, and comments next to other keys, are kept.
`labels` and `rewrite` are edited key by key, so a comment beside one label survives an edit to another.

### Hash conflicts

A save carries the hash of the text it started from.
When the file on disk changed since then, the server refuses with `409 proto_blocks_conflict` and returns the current `hash`.
Nothing is written.
The screen offers to reload the file and apply your change again.

A new file is saved with `"hash": null`.
If a file appeared at that path meanwhile, the save is a conflict too.

### Atomic writes and confinement

- The text is written to a temporary file in the same folder, then renamed over the target.
  Readers never see a half-written file.
- The target is checked for a symlink before the write and again right before the rename.
- Writes are confined to the active theme and its parent theme.
  Every path segment below the theme must be a real folder or file, never a symlink.
- Roots added by `proto_blocks_schema_paths` are never written.
- New declarations are created in the active (child) theme.
- An existing file is edited in place when it sits in the active theme or its parent.
- Only `<theme>/<folder>/<slug>/<basename>.jsonc|.json` is ever written or deleted.

### Deleting

Deleting removes the declaration file.
The folder goes too when it holds nothing but files the plugin recognises (`.json`, `.jsonc`, `.svg`, `.png`, `.jpg`, `.jpeg`, `.webp`).
Anything else keeps the folder, and the response lists what was kept.
Content (posts, terms, stored values) is never deleted.

## Read-only reasons

Every list item and file result says whether it can be written, and why not.

| Reason | Meaning |
|---|---|
| `file_mods` | `DISALLOW_FILE_MODS` or `DISALLOW_FILE_EDIT` is true. |
| `multisite` | The site is multisite and the user is not a network administrator. |
| `not_writable` | The file, or its nearest existing folder, is not writable by PHP. |
| `outside_root` | The file is outside the active and parent theme (for example in a filter-added root). |
| `not_proto` | The item is registered by core or a plugin and has no Proto-Blocks declaration yet (saving creates an override file). |
| `inline` | A taxonomy declared inside a post type file, or a field group inside another declaration. Edit it in that file. |
| `acf` | A field group that Advanced Custom Fields registers. Edit it in ACF. |

The screens render in read-only mode with one banner that states the reason.
All write controls are disabled.

## `DISALLOW_FILE_MODS` and `DISALLOW_FILE_EDIT`

When either constant is true:

- every screen still lists and shows declarations,
- every save, create, delete and scaffold control is disabled,
- every file-writing REST route answers `403 proto_blocks_file_mods`,
- the CLI commands that write files (`create`, `rename`, `scaffold`) fail with "File modifications are disabled on this site."

Declarations keep loading and registering as usual.
Field values and settings page values are not files, so they keep saving.

## Multisite

On multisite the theme is shared by the whole network.
So only a network administrator (`is_super_admin()`) may write declaration files or templates.

- A sub-site administrator sees the content-model screens read-only with reason `multisite`.
- File-writing routes answer `403 proto_blocks_multisite`.
- From WP-CLI, run as a network administrator: `wp --user=<super-admin> proto-blocks type create Event`.

On a single site, `manage_options` is enough.

## What is stored in the database

Declarations never are.
The plugin keeps only bookkeeping and the redirect module's runtime data:

| Name | Kind | Holds |
|---|---|---|
| `proto_blocks_rewrite_hash` | option | `{ "<theme>": "<sha1>" }` of the registered rewrite settings, to flush rewrite rules once when they change |
| `proto_blocks_external_menus` | transient (1 hour) | menu slugs and titles of other plugins, for the Options Pages screen |
| `proto_fields_err_*` | transients (5 minutes) | the last save's field validation errors, per object and user |
| `{prefix}proto_redirect_hits` | table | hit count and last hit per redirect rule |
| `{prefix}proto_redirect_log` | table | the redirect log |
| `{prefix}proto_404_log` | table | the 404 log |
| `proto_blocks_redirects_db` | option | `1` once the three tables exist, so they are checked only when it is not `1` |

Redirect and script configuration is never stored here.
Delete the three tables and every redirect still works.
See [redirects](redirects.md#redirect-log-and-404-log).
Field values are ordinary post, term and user meta, and options.
See [fields](fields.md#storage) and [settings pages](settings-pages.md#storage-and-option-names).
