# WP-CLI

> **At a glance**
> - **What:** every `wp proto-blocks` command: blocks, post types, taxonomies, settings pages, field groups, redirects, scripts, validation, cache and Tailwind.
> - **Where:** commands write declaration files into the active theme (`proto-types/`, `proto-taxonomies/`, `proto-options/`) and block templates into `templates/`.
> - **PHP:** nothing to call; the commands use the same writer and validators as the admin screens.
> - **Admin:** the same operations live on Proto Blocks > Post Types, Taxonomies, Fields and Options Pages.
> - **CLI:** `wp help proto-blocks` lists the commands below.

Related: [post types](post-types.md), [taxonomies](taxonomies.md), [fields](fields.md), [settings pages](settings-pages.md), [redirects](redirects.md), [scripts](scripts.md), [content model files](content-model-files.md).

## Conventions

- A command that fails calls `WP_CLI::error()` and exits with status **1**.
- Success exits with status **0**.
- Warnings never change the exit status.
- `--format` accepts what `WP_CLI\Utils\format_items()` accepts (`table`, `csv`, `json`, `yaml`) unless a command lists fewer.
- Arguments echoed back in messages are stripped of control characters.
- Paths in output are relative (`proto-types/event/type.jsonc`, or from the WordPress root), except where noted.

### Who the command runs as

WP-CLI runs as user 0 unless you pass `--user`.

- **File writes** (`create`, `rename`, `scaffold`) need file modifications on (`DISALLOW_FILE_MODS` and `DISALLOW_FILE_EDIT` false). On multisite they also need a network administrator: `wp --user=<super-admin> proto-blocks type create Event`.
- **Field values** written through WP-CLI (`wp post meta update`, `wp option update`) go through the fields' sanitise callbacks. User 0 is untrusted: markup is sanitised and `code` and `password` values are refused. Seed them as an administrator who has `unfiltered_html`: `wp --user=admin post meta update 42 subtitle "Doors at nine"`.

## Command list

| Command | Purpose |
|---|---|
| `wp proto-blocks list` | registered blocks |
| `wp proto-blocks create` | scaffold a block |
| `wp proto-blocks validate` | validate blocks and every declaration |
| `wp proto-blocks export` | copy a block folder |
| `wp proto-blocks cache clear\|stats` | the template cache |
| `wp proto-blocks tailwind compile\|status\|enable` | Tailwind CSS |
| `wp proto-blocks type list\|create\|scaffold\|validate\|rename` | post types |
| `wp proto-blocks taxonomy list\|create\|validate\|rename` | taxonomies |
| `wp proto-blocks options list\|create` | settings pages |
| `wp proto-blocks fields list\|validate` | field groups |
| `wp proto-blocks redirect <subcommand>` | redirects, groups, import, export, logs, tables |
| `wp proto-blocks script list\|create\|validate\|enable\|disable\|delete` | scripts |

## `wp proto-blocks validate`

Validates every block and, unless a block is named, every declaration: post types, taxonomies, field groups and settings pages.

```bash
wp proto-blocks validate [<name>] [--format=table|json]
```

| Argument | Meaning |
|---|---|
| `<name>` | validate only that block (declarations are skipped) |
| `--format` | `table` (default) or `json` |

Table output prints the block rows (`block`, `status`, `message`), then the declaration rows (`kind`, `slug`, `level`, `code`, `message`, `source`).
JSON output is one document:

```json
{
  "blocks": [ { "block": "event-card", "status": "valid", "message": "OK" } ],
  "declarations": [
    { "kind": "type", "slug": "event", "level": "warning", "code": "double_declaration",
      "message": "\"event\" is declared twice: … is used and … is ignored. Remove one of them.",
      "source": "proto-types/event/type.jsonc" }
  ]
}
```

- `kind` is `type`, `taxonomy`, `options`, `field-group`, `redirects`, `redirect-settings` or `script` (or empty for a finding without a kind).
- `level` is `error` or `warning`.
- Exits 1 when any block row has `status: error` or any declaration row has `level: error`.
- Ends with "Success: All blocks and declarations validated successfully." otherwise.

Menu warnings of settings pages (such as `capability_unknown`) are found while the admin menu is built, so they appear only in the admin, not here.

## `wp proto-blocks type`

Post types. With no subcommand, `list` runs.

### `type list`

```bash
wp proto-blocks type list [--format=table|csv|json|yaml]
```

One row per declared post type (files and PHP calls, after precedence).

| Column | Value |
|---|---|
| `slug` | the slug |
| `name` | the declared `name`, or empty for a name-less override |
| `origin` | `file` or `php` |
| `source` | `proto-types/<slug>/type.jsonc`, or the PHP call's `file:line` |
| `override` | `yes` for `post`, `page` and `attachment`, else `no` |
| `double` | `yes` when a file and a PHP call share the slug |
| `posts` | posts of the type, every status except `auto-draft` |

### `type create`

```bash
wp proto-blocks type create <name> [--slug=<slug>] [--plural=<plural>] [--json]
```

| Argument | Meaning |
|---|---|
| `<name>` | the singular name, e.g. `"Event"` |
| `--slug` | the slug; default derived from the name (20 characters at most) |
| `--plural` | written as a live `"plural"` key; default a commented-out generated plural |
| `--json` | print `{"path": "<absolute path>"}` instead of the success line |

Writes `proto-types/<slug>/type.jsonc` into the active theme and prints "Success: Created <absolute path>".
The file starts from the commented template:

```jsonc
// Proto-Blocks post type declaration.
// The folder name is the post type slug. Every commented line shows a
// default; uncomment and edit it to change that default.
{
  "name": "Event",
  // "plural": "Events",
  // "has_archive": true,
  // "menu_icon": "dashicons-admin-post",
  // "supports": ["title", "editor", "thumbnail", "excerpt", "revisions"],
  // "rewrite": { "with_front": false },
  // "taxonomies": [{ "name": "Category" }],
  "fields": []
}
```

Errors (exit 1): a slug the rules refuse, a slug already declared (`"event" is already declared.`), file modifications off, an unwritable theme.

### `type scaffold`

```bash
wp proto-blocks type scaffold <slug> [--taxonomies]
```

Writes block templates for a **declared** type into the active theme's `templates/`:
`single-<slug>.html`, `archive-<slug>.html` when the type has an archive, and with `--taxonomies` a `taxonomy-<slug>.html` per declared taxonomy attached to the type.
Existing templates (in the active or parent theme) are skipped.

```text
written: templates/single-event.html
skipped (exists): templates/archive-event.html
Success: 1 written, 1 skipped.
```

Errors (exit 1): the type is not declared, a classic theme, file modifications off, `templates/` not writable.
See [post types: template scaffolding](post-types.md#template-scaffolding).

### `type validate`

```bash
wp proto-blocks type validate [<slug>] [--format=table|csv|json|yaml]
```

The declaration rows of `wp proto-blocks validate`, limited to `kind: type` (plus rows without a kind), and to `<slug>` when given.
JSON output is the list of rows.
Exits 1 on any error; otherwise "Success: All post type and taxonomy declarations are valid."

### `type rename`

```bash
wp proto-blocks type rename <old> <new> [--yes] [--migrate]
```

Moves `proto-types/<old>/` to `proto-types/<new>/`.

- Without `--yes` it prints the plan and changes nothing, then exits 1:

  ```text
  Plan: move proto-types/event/type.jsonc to proto-types/happening/ (12 posts affected).
  Existing posts would stay under "event"; add --migrate to move them.
  Error: Nothing changed. Run again with --yes to apply.
  ```

- With `--yes` it writes the file under the new slug (every writer check applies), then deletes the old one.
- With `--migrate` it also updates `wp_posts.post_type` from `<old>` to `<new>`, flushes the object cache, and clears the stored rewrite hash so the next request flushes rewrite rules.
- Without `--migrate`, existing posts stay under the old slug and are no longer shown; a warning says how many.

Refused (exit 1, nothing changes):

- `<old>` is not declared, or is declared in PHP ("rename it in the theme code"),
- `<new>` is invalid, equal to `<old>`, or already declared,
- with `--migrate`, posts already exist under `<new>`.

If the database update fails after the file moved, the command says so and exits 1; fix the database or rename the folder back.

## `wp proto-blocks taxonomy`

Taxonomies. With no subcommand, `list` runs.
There is no `scaffold`; taxonomy templates come from `type scaffold --taxonomies`.

```bash
wp proto-blocks taxonomy list [--format=table|csv|json|yaml]
wp proto-blocks taxonomy create <name> [--slug=<slug>] [--plural=<plural>] [--json]
wp proto-blocks taxonomy validate [<slug>] [--format=table|csv|json|yaml]
wp proto-blocks taxonomy rename <old> <new> [--yes] [--migrate]
```

- `list` has the same columns as `type list`; `override` is `yes` for `category` and `post_tag`; `posts` holds the **term** count.
- `list` includes taxonomies declared inline in a type.
- `create` writes `proto-taxonomies/<slug>/taxonomy.jsonc` with `"post_types": []`; slugs are at most 32 characters.
- `rename` refuses a taxonomy declared inside a post type ("rename it there"). `--migrate` updates `wp_term_taxonomy.taxonomy` and cleans both taxonomies' caches.

## `wp proto-blocks options`

Settings pages.

### `options list`

```bash
wp proto-blocks options list [--format=table|json|csv]
```

| Column | Value |
|---|---|
| `slug` | the page slug; child pages appear as `<parent>-<child>` |
| `title` | the page title |
| `menu` | `top` or the parent menu slug |
| `capability` | the page capability |
| `storage` | `fields`, or `array:<option>` |
| `origin` | `file` or `php` |

### `options create`

```bash
wp proto-blocks options create <title> [--slug=<slug>] [--menu=<menu>] [--capability=<cap>] [--storage-option=<name>]
```

| Argument | Meaning |
|---|---|
| `<title>` | the page title |
| `--slug` | default: the title as a slug |
| `--menu` | `top` (default) or a parent menu slug, written as a live key |
| `--capability` | default `manage_options`, written as a live key |
| `--storage-option` | store every value in this one array option (`"storage": {"mode": "array", ...}`) |

Writes `proto-options/<slug>/page.jsonc` into the active theme and prints "Success: Created proto-options/<slug>/page.jsonc".
The text is checked with the same rules as a file on load, protected option names included.

```bash
wp proto-blocks options create "Theme Settings"
wp proto-blocks options create "Display Settings" --slug=cadco-display-settings \
  --menu="edit.php?post_type=product" --capability=manage_woocommerce --storage-option=cadco_display_settings
```

Errors (exit 1): no slug can be derived (pass `--slug`), an invalid or reserved slug, a slug already declared, a file already at that path, a protected storage option, file modifications off.

## `wp proto-blocks fields`

Field groups.

### `fields list`

```bash
wp proto-blocks fields list [--format=table|json|csv]
```

| Column | Value |
|---|---|
| `key` | the group key |
| `title` | the group title |
| `source` | `php`, `type:<slug>`, `taxonomy:<slug>`, `options:<slug>`, `file:<slug>`, or `acf` |
| `path` | the file, or `file:line` of the PHP call |
| `fields` | top-level field count |
| `location` | a one-line summary of the rules |
| `status` | `active`, `unrenderable` (no rule can match) or `skipped-acf` (ACF registers the key) |

Prints "No field groups." for an empty table.
Errors when the Fields module is disabled.

### `fields validate`

```bash
wp proto-blocks fields validate [<path>...]
```

- Without paths: every discovered group file, inline group and PHP group, plus meta-registration findings.
- With paths: only those files (one group or an ACF export each). Works with the module disabled.

One line per finding:

```text
ERROR proto-fields/event-details/group.jsonc fields[2].name invalid_name: Field name "2nd" must start with a letter …
WARNING proto-fields/event-details/group.jsonc location wide_location: The field group "group_event_details" matches every post type; add a post_type == rule to confine it.
Error: 1 error(s), 1 warning(s).
```

Exits 1 on any error; otherwise "Success: No errors, N warning(s)."
The `wide_location` warning is CLI-only: a group whose post branch no `post_type`, `post`, `page_template` or `post_taxonomy` `==` rule narrows.

## `wp proto-blocks redirect`

Redirect rules, groups, import, export, logs and tables.
Guide: [redirects](redirects.md).

```bash
wp proto-blocks redirect <subcommand>
```

| Subcommand | Flags | Purpose |
|---|---|---|
| `list` | `--group=<slug>`, `--format=table\|json\|csv` | every rule |
| `add <from> [<to>]` | `--code=`, `--group=`, `--regex`, `--when=<type>`, `--when-value=`, `--action=`, `--query=`, `--title=` | append a rule to a group file |
| `delete <id>` | `--group=` | remove a rule |
| `enable <id>`, `disable <id>` | | toggle a rule |
| `group list` | | every group |
| `group create <title>` | | a new group file |
| `group enable <slug>`, `group disable <slug>` | | toggle a group |
| `group delete <slug>` | | delete a group file |
| `validate` | | check every group and the settings file. Also part of `wp proto-blocks validate`. |
| `test <url>` | `--logged-in` | which rule fires for a URL, with a trace. Nothing is redirected. |
| `import <file>` | `--format=csv\|json\|apache\|redirects`, `--group=`, `--duplicates=skip\|replace\|add`, `--dry-run` | import a file |
| `import-plugin <id>` | `--group=`, `--dry-run` | import another plugin's redirects |
| `export [<file>\|-]` | `--format=csv\|json\|apache\|nginx\|redirects`, `--group=` | write an export to a file, or to stdout with `-` |
| `log` | `--404`, `--limit=`, `--clear` | read or clear the redirect log, or the 404 log with `--404` |
| `prune` | | remove hit rows whose rule no longer exists |
| `db install\|status\|drop` | `--yes` | create, check or drop the three tables |

```bash
wp proto-blocks redirect add /old /new --code=301 --group=legacy-site
wp proto-blocks redirect test /old
wp proto-blocks redirect import-plugin redirection --dry-run
wp proto-blocks redirect export --format=apache -
```

- File writes need file modifications on, and on multisite a network administrator, as for every writing command.
- Writes go through the server-side serializer. Hand-written comments inside the rules array of an edited group file are lost. The file header says so.
- Pass `--logged-in` to `redirect test` to evaluate `login` and `role` conditions as a logged-in visitor.
- `db drop` is the only way to drop the tables. Deactivating the plugin keeps them.

## `wp proto-blocks script`

Head, body-open and body-close snippets.
Guide: [scripts](scripts.md).

```bash
wp proto-blocks script list
wp proto-blocks script create <title>
wp proto-blocks script validate
wp proto-blocks script enable <slug>
wp proto-blocks script disable <slug>
wp proto-blocks script delete <slug> --confirm=<slug>
```

- `create` writes `proto-scripts/<slug>/script.jsonc` into the active theme.
- `delete` needs `--confirm=<slug>` equal to the slug. It removes the folder only when it holds a valid `script.jsonc` and nothing but the three snippet files.

## Blocks

### `wp proto-blocks list`

```bash
wp proto-blocks list [--format=table|csv|json|yaml]
```

Columns: `name` (`proto-blocks/<name>`), `title`, `category`, `description`.

### `wp proto-blocks create`

```bash
wp proto-blocks create <name> [--title=<title>] [--description=<description>] [--category=<category>] \
  [--fields=<name:type,...>] [--dir=theme|plugin] [--force]
```

Scaffolds a block (`block.json`, `template.php`, `style.css`).
`--fields` takes `name:type` pairs, for example `"quote:wysiwyg,author:text,image:image"`.

### `wp proto-blocks export`

```bash
wp proto-blocks export <name> [--output=<path>]
```

Copies a block folder to `<path>` (default `./<name>`); refuses an existing directory.

## `wp proto-blocks cache`

```bash
wp proto-blocks cache clear    # also the default with no subcommand
wp proto-blocks cache stats
```

`stats` prints the cached template count, total size, cache directory and whether the cache is enabled.

## `wp proto-blocks tailwind`

```bash
wp proto-blocks tailwind enable
wp proto-blocks tailwind compile
wp proto-blocks tailwind status [--format=table|json|yaml]
```

`status` fields: `enabled`, `mode`, `engine`, `last_compiled` (ISO 8601, or `never` in a table).

## Recipes

Build the `event` content model from scratch:

```bash
wp proto-blocks type create "Event"
wp proto-blocks taxonomy create "Topic"        # then add "event" to its post_types
wp proto-blocks options create "Theme Settings"
wp proto-blocks validate
wp proto-blocks type scaffold event --taxonomies
```

Check a site in CI:

```bash
wp proto-blocks validate --format=json > declarations.json
wp proto-blocks fields validate
```
