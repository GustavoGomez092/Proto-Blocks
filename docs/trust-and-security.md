# Trust and security

> **At a glance**
> - **What:** who may write what (declaration files, field values, settings), what the getter escapes, and what the plugin refuses.
> - **Where:** the rules apply to every `proto-types/`, `proto-taxonomies/`, `proto-fields/` and `proto-options/` file and to every stored field value.
> - **PHP:** `proto_field($name, $context, $raw = false)` escapes by default; PHP declarations are trusted code.
> - **Admin:** every content-model screen needs `manage_options`; file writes also need file mods on and, on multisite, a network administrator.
> - **CLI:** run as a trusted user (`wp --user=<admin>`) to write `code` values or files on multisite.

This page is the trust boundary in one place, for developers and for AI agents that build sites with the plugin.

Related: [content model files](content-model-files.md), [fields](fields.md#trust-rules), [settings pages](settings-pages.md#trust-rules), [REST API](rest-api.md#permissions).

## Two kinds of writes

| Write | What it changes | Who may do it |
|---|---|---|
| A declaration | a theme file (`type.jsonc`, `group.jsonc`, …) | a developer editing the theme, or an administrator through the UI |
| A value | post, term or user meta, or an option | anyone the object's edit capability allows, sanitised by trust |

Declarations are code-adjacent (they shape the site); values are content.
They have separate gates.

## Declarations

### Who can write a declaration file

| Path | Gate |
|---|---|
| Editing the theme by hand, deploying with git | whoever controls the theme |
| The admin UI and the `admin/schema-files/*` routes | `manage_options`, the REST nonce, `DISALLOW_FILE_MODS` and `DISALLOW_FILE_EDIT` false, `is_super_admin()` on multisite |
| WP-CLI `create`, `rename`, `scaffold` | file mods on, and on multisite a network administrator (`--user`) |

The UI writes only inside the active theme and its parent, through an atomic, symlink-checked, hash-checked write.
See [content model files](content-model-files.md#how-the-ui-writes-files).

Declaring a `code` field needs no `unfiltered_html`: a declaration is a theme file.
The `unfiltered_html` rule applies to the values stored in that field.

### Declarations are data

The loader only reads declaration files; it never includes or executes them.

- A file cannot name PHP code. `register_meta_box_cb`, `meta_box_cb` (except `false`), `meta_box_sanitize_cb`, `update_count_callback`, `rest_controller_class`, `autosave_rest_controller_class`, `revisions_rest_controller_class`, `_builtin` and `_edit_link` are refused, and so is any unknown key whose value names an existing function or static method (`"Ns\\fn"`, `"Class::method"`, `["Class", "method"]`).
  - Type and taxonomy files: the loader strips the key with an `unsafe_key` warning; the writer refuses the file.
  - Settings page files: the file fails the schema check and is skipped.
- Labels cannot carry markup. `name`, `plural`, `labels.*`, page and tab titles may not contain `<` or `>`.
- A field group is normalised to known keys; its meta is registered with the plugin's own callbacks, never callbacks from the file.
- `__proto__` keys are refused at any depth; files over 256 KB are never opened.

PHP declarations (`proto_post_type()`, `proto_taxonomy()`, `proto_field_group()`, `proto_options_page()`) are trusted code and may set callback keys and label markup.

### Read paths and file confinement

- Declaration folders, files and SVG icons must resolve inside their theme root (`realpath()`); a symlink out is skipped (`path_outside_root`).
- An icon path cannot be absolute, contain `:` or `\`, or change extension through a symlink.
- Theme SVG icons are size-capped (32 KB) and sanitised on the server: scripts, event handlers, external references and `<style>` are removed.
- Template scaffolding copies only the theme's own seed templates; nothing from a declaration is interpolated except the slug in the file name.

### Fail closed

- A broken child-theme field group never falls back to the parent's group of the same slug (`broken_override`).
- A broken settings page file also suppresses a PHP declaration of the same slug, so the page never falls back to a wider capability.
- A location rule the plugin does not implement makes the whole group unrenderable; it never shows on every post.
- An `optionsSource` that cannot be resolved rejects every value.
- A `pattern` that does not compile fails every value.

## Values

### Write-time, per-user trust

Trust is decided from the **current user** at each write, never per post.
It applies the same way to post meta, term meta, user meta and settings page options.

| Writer | What is stored |
|---|---|
| has `unfiltered_html` | the value as given, normalised for shape |
| lacks `unfiltered_html` | the value through its type's sanitiser |
| no user (cron, WP-CLI without `--user`, importers) | treated as lacking `unfiltered_html` |

Sanitisers per type: `sanitize_text_field()` (text, password, choices), `sanitize_textarea_field()`, `sanitize_email()`, the plugin's URL sanitiser (url, link URL, video URL), `wp_kses_post()` (wysiwyg), hex colours only (color), strict date formats, positive integer IDs (media, relationship, taxonomy).

### `code` and `password`

- Only users with `unfiltered_html` may write a `code` or `password` field, or a group, repeater or flexible content field that holds one at any depth.
- For anyone else, the save leaves the stored value exactly as it is and reports "Only users with unfiltered HTML may edit this field."
- This holds for the meta boxes, core's REST meta endpoints, `update_post_meta()` and friends (short-circuit filters refuse the write), and settings pages.
- In the editor such a user sees a `code` field read-only without its value, and `password` fields and containers as "Hidden; only users with unfiltered HTML may view and edit this field." Their stored values are never sent to that browser.
- On settings pages, both are hidden and masked as `null` in REST responses.
- Both are off in core's REST API by default and never bound by Block Bindings.

### What the getter guarantees

`proto_field()`, `proto_fields()`, `proto_rows()`, `$template->field()` and the ACF aliases return values **escaped for their declared type**, whoever wrote them:

| Type | Escaping |
|---|---|
| text, password, email, choices, dates | `esc_html()` |
| url | `esc_url()` |
| wysiwyg | `wp_kses_post()` (a trusted writer's script is still stripped) |
| color | hex colour or `''` |
| image, file, gallery, video, link, relationship, taxonomy | arrays with `esc_url()`, `esc_html()` and `esc_attr()` parts |
| group, repeater, flexible content | every sub-value by its own type |
| **code** | **as stored** |

- `$raw = true` (and `$format_value = false` for the aliases) returns the stored value; escaping is then the caller's job.
- WordPress escapers do not double-encode, so escaping again is harmless.
- Relationship output leaves out posts the viewer cannot read; video output never exposes a non-video attachment.

### The `code` caveat

A `code` value is returned raw because the plugin lets only trusted users write it.
That proof covers values written **through the plugin** only.

- A meta row of the same name written before the plugin existed, or by other code, comes back raw too.
- **Re-typing a field from `text` (or any type) to `code` makes the values already stored under the old type come back raw**, whoever wrote them. Clear those values first if untrusted users could write them.

### No read-permission check in the getter

The getter reads whatever object the context names: a draft, a private post, any user, any declared settings page.

- Never build `$context` from visitor input: `$_GET`, `$_POST`, query vars, or block attributes a contributor controls.
- Only the declared-field rule applies: a name not declared for that object returns `null`.

## Exposure

### Core's REST API

| Field | Default |
|---|---|
| post and term fields | exposed (`show_in_rest` on) |
| `password` and `code` fields, and containers holding one | not exposed |
| user fields | **not exposed** |

Exposed meta is readable anonymously in the `view` context of published posts, viewable terms and public users.
Set `"show_in_rest": false` on anything private, and `"show_in_rest": true` only on user fields meant to be public.

### Block Bindings

Bindings are written by anyone who can edit a post and rendered for every visitor, so the `proto-blocks/field` source applies its own policy:

- declared value fields only; `code` and `password` never; `"bindings": false` opts a field out;
- posts behind a password give nothing; posts that are not publicly viewable need `read_post`;
- terms of non-viewable taxonomies need `edit_term`;
- user fields need `edit_user`;
- **settings page fields are bindable by any author** unless the field sets `"bindings": false`;
- output is always the escaped value; URL attributes go through `esc_url()`.

A full-page cache that serves logged-in users must vary by user, because a logged-in viewer can see more.

### Protected meta and options

- Every registered field key is protected meta, so core's raw Custom Fields panel never lists or edits it.
- User fields may not use `session_tokens` or the capability and user-level keys (`wp_capabilities`, `wp_user_level`, and the multisite forms).
- Settings pages may never read or write protected options: core settings (`siteurl`, `home`, `admin_email`, `default_role`, `users_can_register`, …), `active_plugins`, `template`, `stylesheet`, `cron`, `rewrite_rules`, salts, `<prefix>user_roles`, and names starting with `_transient`, `_site_transient`, `theme_mods_`, `widget_`, `proto_blocks_` or `auto_update_`. Extend the list with `proto_blocks_protected_options`.

### Capabilities

| Surface | Capability |
|---|---|
| Post Types, Taxonomies, Fields, Options Pages screens | `manage_options` |
| A field box on a post / term / user | `edit_post` / `edit_term` (or `edit_terms` to add) / `edit_user` (or `create_users` to add) |
| A settings page and `options/<slug>` | the page's own capability, verbatim, never widened, plus a logged-in user |
| The public registration form | none; only groups that name `user_form == register` (or `all`), always sanitised |

## Limits that protect the site

| Limit | Value |
|---|---|
| Declaration file size | 256 KB |
| JSON depth | 64 |
| Rows per repeater or flexible content (all nesting levels together) | 500 by default (`proto_blocks_repeater_max_items`); more is refused, stored rows are never truncated |
| IDs per gallery, relationship or taxonomy field | 500 |
| User `pattern` length / subject length | 200 bytes / 10,000 bytes |
| Saved errors per object | 500 paths, 64 KB of submitted values, 5 minutes |

## What the plugin never does

- It never stores a declaration in the database, not even as a fallback.
- It never executes or includes a declaration file.
- It never writes outside the active theme and its parent, never through a symlink, and never into a `proto_blocks_schema_paths` root.
- It never overwrites an existing block template when scaffolding.
- It never deletes content (posts, terms, stored values) when a declaration is deleted or renamed; `rename --migrate` moves posts or terms only on request.
- It never reads ACF's `acf-json` folders, so they cannot change a Proto-Blocks field's trust rules.
- It never silently truncates rows or drops an invalid value; it refuses and reports.
- It never sends a `code` or `password` value to the browser of a user who may not edit it.

## Five rules for agents

1. Write declarations as JSONC files in the theme; let `wp proto-blocks validate` pass before you finish.
2. Echo getter output as returned; never build `$context` from request data.
3. Use `code` only for administrator-pasted markup, and never re-type an existing field to `code`.
4. Set `"show_in_rest": false` and `"bindings": false` on any value that is not public.
5. Seed `code` and markup values with `wp --user=<admin>`; user 0 is untrusted.
