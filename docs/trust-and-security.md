# Trust and security

> **At a glance**
> - **What:** who may write what (declaration files, field values, settings, redirects, scripts), what the getter escapes, and what the plugin refuses.
> - **Where:** the rules apply to every `proto-types/`, `proto-taxonomies/`, `proto-fields/`, `proto-options/`, `proto-redirects/` and `proto-scripts/` file and to every stored field value.
> - **PHP:** `proto_field($name, $context, $raw = false)` escapes by default; PHP declarations are trusted code.
> - **Admin:** every content-model screen needs `manage_options`; file writes also need file mods on and, on multisite, a network administrator.
> - **CLI:** run as a trusted user (`wp --user=<admin>`) to write `code` values or files on multisite.

This page is the trust boundary in one place, for developers and for AI agents that build sites with the plugin.

Related: [content model files](content-model-files.md), [fields](fields.md#trust-rules), [settings pages](settings-pages.md#trust-rules), [redirects](redirects.md#safety-rules), [scripts](scripts.md#trust-rules), [REST API](rest-api.md#permissions).

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
- A broken redirect group file drops a PHP group of the same slug and the parent theme's group too (`broken_override`). A rule that fails validation is dropped alone; the rest of its group runs. A group-level schema error skips the group.
- A location rule the plugin does not implement makes the whole group unrenderable; it never shows on every post.
- An `optionsSource` that cannot be resolved rejects every value.
- A `pattern` that does not compile fails every value.

### Redirect targets

Redirect files are data, and a rule can still do harm if a target is built from the request.
The module refuses that case. Full list: [redirects](redirects.md#safety-rules).

- A target written as a path must still be a path after substitution. A leading `//` collapses to `/`. A backslash, a control character or a `scheme:` prefix makes the rule not fire. Capture groups cannot make an open redirect.
- A target written absolute keeps its host. If substitution changes it, the rule does not fire.
- Only `http` and `https` are accepted. The final target goes through `wp_sanitize_redirect()`.
- A rule whose target is the current request does not fire. A rule whose `to` equals `from` is refused.
- Regex: compiled at write time, 500 characters at most, `preg_last_error()` checked on every match.
- A rule whose target equals the request, or that a capture group turns unsafe, does not fire and evaluation goes on; an unsafe target leaves one code 0 row in the redirect log.
- Headers: names match `^[A-Za-z0-9-]{1,100}$`, values are 1000 characters at most, CR, LF and control characters are refused, and `Set-Cookie`, `Location`, `Status`, `Content-Length`, `Transfer-Encoding` and `Refresh` (a meta-refresh redirect that would skip the target-safety rules) cannot be set.
- Logs: the client IP is stored as `none` (default), `masked` or `full`. A proxy header is trusted only when `REMOTE_ADDR` is a listed trusted proxy (address or CIDR), and only the five allow-listed header names count. Stored request headers (opt-in) never include `authorization`, `cookie`, `set-cookie`, `host`, `proxy-authorization`, `x-wp-nonce`, `x-api-key`, `x-auth-token`, `x-csrf-token` or `x-xsrf-token`, with 50 headers at most and values cut at 1000 characters. The logs are written with prepared statements.
- Imports and exports: parsers never execute or unserialize an uploaded file, uploads are capped at 5 MB, every imported row goes through the file validator, exporters refuse any value holding a control character, and CSV downloads prefix formula-looking cells (`=`, `+`, `-`, `@`, tab, CR) with `[FORMULA] `.
- Deactivating the source plugin after an import needs `activate_plugins`, and only the plugin the importer names can be deactivated.
- The REST routes under `admin/redirects` need `manage_options` and write no theme file; rule and settings files go through the file-write gate of `admin/schema-files`.
- `pass` is an internal rewrite. It never fetches an external URL.
- `when.type: "filter"` names a hook, not a callable.
- Logs: IP storage defaults to `none`, sensitive request headers are never stored, reading logs needs `manage_options`, and CSV exports prefix cells that start with `=`, `+`, `-` or `@`.
- Importers execute nothing, uploads are capped at 5 MB, and deactivating a source plugin needs `activate_plugins`.

### Scripts raw output

`proto-scripts/` snippets are output exactly as stored on the front end.
That is the point, and it is a boundary: anyone who can write these files can run JavaScript on every visitor's browser, exactly as someone who can edit the theme.

- Writing a script file needs `manage_options` **and** `unfiltered_html`. Without it the write is refused with `403 proto_blocks_unfiltered_html` and the screen is read-only with the reason `unfiltered_html`. `DISALLOW_UNFILTERED_HTML` disables writes site-wide.
- The generic `admin/schema-files/script/*` write and delete routes need `unfiltered_html` as well.
- File-mods and multisite rules apply as for every declaration. Write reasons are reported in the order `file_mods`, `multisite`, `unfiltered_html`.
- WP-CLI does not check `unfiltered_html`: the shell user owns the theme files. The file-mods rule still applies.
- A `<?php`, `<?=` or `<?` followed by whitespace in a snippet, in a file, inline or in a `proto_script()` call, is refused (`php_in_snippet`). Snippets are never included as PHP.
- Snippets are capped at 256 KB (`snippet_too_large`, `413 proto_blocks_file_too_large` on a save).
- Snippets are never printed in the admin, ajax, cron, REST, iframe requests, the customizer preview, feeds or embeds. `proto_blocks_scripts_enabled` can only switch output off.
- A script with any error is skipped whole. A broken child-theme file also drops the parent file and the PHP declaration of the same slug.
- Only three fixed file names per script folder are ever read or written (`invalid_snippet_file`, also for a `{file}` value in `proto_script()`).
- Deleting a script removes the folder only when it holds a valid `script.jsonc` and nothing but the three snippet files; anything else is kept and reported.

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
| Post Types, Taxonomies, Fields, Options Pages, Redirects, Scripts screens | `manage_options` |
| Writing a script file | `manage_options` and `unfiltered_html` |
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
