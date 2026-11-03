# Post types

> **At a glance**
> - **What:** complete custom post types (and overrides of core and plugin types) from a name.
> - **Where:** `your-theme/proto-types/<slug>/type.jsonc` (or `type.json`).
> - **PHP:** `proto_post_type('Event');` in `functions.php`.
> - **Admin:** Proto Blocks > Post Types (`admin.php?page=proto-blocks-post-types`).
> - **CLI:** `wp proto-blocks type list|create|scaffold|validate|rename`.

A bare name is enough.
The plural, the slug and every admin label are generated, and the defaults match what hand-written sites repeat.
Every `register_post_type()` argument can still be set.

Related: [taxonomies](taxonomies.md), [fields](fields.md), [content model files](content-model-files.md), [REST API](rest-api.md#post-types-and-taxonomies), [CLI](cli.md#wp-proto-blocks-type).

## Declare a post type

### In PHP

```php
// functions.php
proto_post_type('Event');
```

Signature:

```php
proto_post_type(string $name, array $args = []): void
```

- `$name` is the human singular name (`'Event'`, `'Case Study'`).
- `$args` takes any key a `type.jsonc` file accepts (see [every key](#every-key)).
- Call it from `functions.php`, or on `init` before priority 9.
  A later call is ignored with a `declared_too_late` warning.
- The call only records the declaration.
  Validation happens when the content model loads, so a bad declaration never fatals the site.

```php
proto_post_type('Event', [
    'has_archive' => 'events',
    'menu_icon'   => 'icons/event.svg',     // relative to the active (child) theme
    'supports'    => ['title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'author'],
    'taxonomies'  => [
        ['name' => 'Event Type', 'hierarchical' => true],   // declared inline
        'category',                                          // an existing taxonomy
    ],
]);
```

PHP declarations are trusted code.
They may set callback keys (`register_meta_box_cb`, `rest_controller_class`, ...) and markup in labels, which files may not.

### In a file

```
your-theme/
  proto-types/
    event/
      type.jsonc
      icons/            (optional; any theme path works)
```

```jsonc
// proto-types/event/type.jsonc
{
  "name": "Event"
}
```

The folder name is the slug.
A `"slug"` key may be written, but it must equal the folder name (`slug_mismatch` otherwise).

A fuller file, using the same keys as the PHP example:

```jsonc
// proto-types/event/type.jsonc
{
  "name": "Event",
  "plural": "Events",
  "has_archive": "events",
  "menu_icon": "icons/event.svg",
  "supports": ["title", "editor", "thumbnail", "excerpt", "revisions", "author"],
  "rewrite": { "slug": "events" },          // merges over { "slug": "event", "with_front": false }
  "labels": { "add_new_item": "Add a new event" },
  "taxonomies": [
    { "name": "Event Type", "hierarchical": true },
    "category",
  ],
  "fields": [
    {
      "key": "group_event_basics",
      "title": "Event basics",
      "fields": [
        { "key": "field_event_subtitle", "name": "subtitle", "label": "Subtitle", "type": "text" },
        { "key": "field_event_start", "name": "start_date", "label": "Start date", "type": "date", "required": true }
      ]
    }
  ],
}
```

Inline `fields` groups show on this type without a `location`.
See [fields](fields.md#2-inline-in-a-type-taxonomy-or-settings-page-file).

## Every key

Keys marked *plugin* are Proto-Blocks' own and never reach WordPress.
Every other key is passed to `register_post_type()`.
Keys not listed here pass through unchanged, so no WordPress feature is blocked.

| Key | Type | Default | Notes |
|---|---|---|---|
| `name` | string, 1–100 chars, no `<` `>` | — (*plugin*) | Singular name. Required to register a new type. Optional in an override. |
| `plural` | string, 1–100 chars, no `<` `>` | generated (*plugin*) | English rules: `Event` → `Events`, `Category` → `Categories`, `Person` → `People`, `News` → `News`. |
| `slug` | `^[a-z][a-z0-9_-]{0,19}$` | from `name` (*plugin*) | Must equal the folder name in a file. |
| `labels` | object of strings, no `<` `>` | full generated set | Merges over the generated labels. |
| `taxonomies` | list of strings or taxonomy objects | `[]` (*plugin*) | A string attaches an existing taxonomy; an object declares one inline (see [inline taxonomies](#inline-taxonomies)). |
| `fields` | list of field groups | `[]` (*plugin*) | See [fields](fields.md). |
| `$schema` | string | — (*plugin*) | Ignored. |
| `public` | boolean | `true` | |
| `show_in_rest` | boolean | `true` | `false` switches the type to the classic editor. |
| `hierarchical` | boolean | `false` | |
| `has_archive` | boolean, or string `^[a-z0-9][a-z0-9/_-]*$` | `true` | A string is the archive path. `false` when a page is the archive. |
| `rewrite` | `false`, or object `{slug, with_front, feeds, pages}` | `{"slug": "<slug>", "with_front": false}` | An object merges over the default. `false` turns pretty URLs off. |
| `supports` | `false`, or list of unique strings | `["title", "editor", "thumbnail", "excerpt", "revisions"]` | A declared list replaces the default list. |
| `menu_position` | integer 0–10000 | `22` | Just below Pages. |
| `menu_icon` | string, ≤ 2048 chars | Proto-Blocks icon | See [menu icons](#menu-icons). |
| `template` | list of block arrays | none | As in `register_post_type()`: `[["core/heading", {"level": 2}], ...]`. |
| `template_lock` | `false`, `"all"`, `"insert"`, `"contentOnly"` | none | |
| `capability_type` | string, or `[singular, plural]` | `"post"` | `map_meta_cap` keeps WordPress' default. |
| `exclude_from_search` | boolean | WordPress default | |
| `publicly_queryable` | boolean | WordPress default | |
| `show_ui` | boolean | WordPress default | |
| `show_in_menu` | boolean | WordPress default | A string parent menu is refused by the schema. |
| `show_in_nav_menus` | boolean | WordPress default | |
| `show_in_admin_bar` | boolean | WordPress default | |
| `map_meta_cap` | boolean | WordPress default | |

Merge rules:

- `labels` merges over the generated (or, for an override, the existing) labels.
- `rewrite` merges when both sides are objects; otherwise the declared value replaces.
- Every other declared key replaces the default.

### Keys a file cannot set

A declaration file is data.
It cannot hand WordPress anything WordPress calls or instantiates:

- `register_meta_box_cb`, `meta_box_sanitize_cb`, `update_count_callback`,
- `rest_controller_class`, `autosave_rest_controller_class`, `revisions_rest_controller_class`,
- `_builtin`, `_edit_link`,
- `meta_box_cb`, except the literal `false`,
- any unknown key whose value names an existing PHP function or static method (`"My\\fn"`, `"Class::method"`, `["Class", "method"]`).

When a file holds one, the loader strips the key with an `unsafe_key` warning and the type still registers.
A UI save of such a file is refused (`400 proto_blocks_schema_invalid`).
Declare those keys in PHP instead.

`name`, `plural` and `labels` may not contain `<` or `>`.
The loader drops the markup (`name`, `plural`) or the label (`labels.*`) with an `unsafe_label` warning; a UI save is refused.

## What gets registered

A bare `proto_post_type('Event')` registers `event` with:

| Argument | Value |
|---|---|
| `labels` | the full set below |
| `public` | `true` |
| `show_in_rest` | `true` |
| `has_archive` | `true` |
| `rewrite` | `['slug' => 'event', 'with_front' => false]` |
| `supports` | `['title', 'editor', 'thumbnail', 'excerpt', 'revisions']` |
| `menu_position` | `22` |
| `menu_icon` | the Proto-Blocks icon (a `data:image/svg+xml` URI) |
| `hierarchical` | `false` |
| `capability_type` | `'post'` |
| `taxonomies` | the string entries of `taxonomies` |

A type that receives a field group also gains `custom-fields` support, because core needs it to expose meta over REST.

### Generated labels

For `Event` / `Events`:

| Label | Value |
|---|---|
| `name`, `menu_name` | Events |
| `singular_name`, `name_admin_bar` | Event |
| `add_new` | Add New |
| `add_new_item` | Add New Event |
| `new_item` / `edit_item` / `view_item` | New Event / Edit Event / View Event |
| `view_items` / `all_items` / `search_items` | View Events / All Events / Search Events |
| `not_found` / `not_found_in_trash` | No events found. / No events found in Trash. |
| `archives` / `attributes` / `parent_item_colon` | Event Archives / Event Attributes / Parent Event: |
| `featured_image`, `set_featured_image`, `remove_featured_image`, `use_featured_image` | WordPress' wording |
| `insert_into_item` / `uploaded_to_this_item` | Insert into event / Uploaded to this event |
| `filter_items_list` / `items_list_navigation` / `items_list` | Filter events list / Events list navigation / Events list |
| `item_published` / `item_updated` / `item_link` | Event published. / Event updated. / Event Link |

Lowercase variants keep acronyms: `FAQ Entry` gives "No FAQ entries found."

## Inline taxonomies

An object in `taxonomies` declares a taxonomy that belongs to the type.

```jsonc
// proto-types/event/type.jsonc
{
  "name": "Event",
  "taxonomies": [
    { "name": "Event Type", "hierarchical": true },          // slug "event-type"
    { "name": "Venue", "slug": "venue", "show_admin_column": false },
    "post_tag"                                               // attach an existing taxonomy
  ]
}
```

- `name` is required in an inline object.
- The slug comes from `name` (32 characters at most) unless `slug` is set.
- `post_types` is pre-filled with the enclosing type; you may list more.
- Every [taxonomy key](taxonomies.md#every-key) is accepted.
- An inline taxonomy is edited in its type's file, never on its own.
- A standalone `proto-taxonomies/<slug>/taxonomy.jsonc` of the same slug wins, and the inline type is added to its `post_types` (`double_declaration` warning).
- Two types that inline the same taxonomy with identical data (apart from `post_types`) share it silently. Any other difference is a `double_declaration` warning; the first one wins and gains the second type.

## Overrides of core and plugin types

A declaration whose slug is already registered, or is `post`, `page` or `attachment`, is an **override**.

- Only the keys you write are applied, on top of the existing registration.
- No defaults are added.
- `name` is optional.
- The merge runs through the `register_post_type_args` filter, so it reaches core's types and plugins that register on `init`.
- String `taxonomies` are attached with `register_taxonomy_for_object_type()`.
- Inline `fields` groups show on that type.

A full override of the core `post` type:

```jsonc
// proto-types/post/type.jsonc
// Override of the core "post" type: only these keys are applied.
{
  "labels": {
    "name": "Articles",
    "singular_name": "Article",
    "menu_name": "Articles",
    "add_new_item": "Add New Article"
  },
  "menu_icon": "dashicons-media-document",
  "menu_position": 5,
  "supports": ["title", "editor", "thumbnail", "excerpt", "revisions", "author", "comments"],
  "template": [
    ["core/heading", { "level": 2, "placeholder": "Eyebrow title" }],
    ["core/spacer", { "height": "48px" }],
    ["core/paragraph", { "placeholder": "Intro" }],
    ["core/image", {}]
  ],
  "template_lock": false,
  "taxonomies": ["topic"],
  "fields": [
    {
      "key": "group_post_extras",
      "title": "Article extras",
      "position": "side",
      "fields": [
        { "key": "field_post_eyebrow", "name": "eyebrow", "label": "Eyebrow", "type": "text", "maxlength": 60 }
      ]
    }
  ]
}
```

The same in PHP:

```php
proto_post_type('Post', [
    'slug'     => 'post',
    'template' => [['core/heading', ['level' => 2]], ['core/paragraph', []]],
]);
```

A plugin's type is overridden the same way, by its slug (`proto-types/product/type.jsonc` for WooCommerce products).

### Late overrides

A type registered before the declaration was known (before `after_setup_theme` priority 1) cannot be reached by the filter.
Then only these keys are applied directly to the registered object:
`labels`, `template`, `template_lock`, `menu_icon`, `menu_position`, `supports`, `description`, `show_in_admin_bar`, `show_in_nav_menus`.
Every other declared key is ignored with a `late_override_ignored` warning that names them.

### Name-less declarations

A declaration without `name` whose slug nobody registers by `wp_loaded` registers nothing.
It is reported as a `name_required` error.
Add a `name`, or keep it as an override for a type a plugin registers later.

## Precedence

- A file beats a PHP call of the same slug entirely (`double_declaration` warning, "Declared twice" badge, admin notice).
- A child theme's `proto-types/<slug>/` beats the parent theme's.
- A root added with `proto_blocks_schema_paths` comes after the themes, so it wins unless prepended.
- Calling `proto_post_type()` twice for one slug keeps the later call (`duplicate_php` warning).

Details: [content model files](content-model-files.md#discovery-and-precedence).

## Slug rules

- Derived from `name` with `sanitize_title()`, cut to 20 characters, trailing `-` removed (`Case Study` → `case-study`).
- Must match `^[a-z][a-z0-9_-]*$` (lowercase letters, digits, `_`, `-`, starting with a letter).
- At most 20 characters.
- `post`, `page` and `attachment` are allowed, as overrides only.
- Reserved and refused: WordPress' reserved query terms (`attachment_id`, `author`, `author_name`, `calendar`, `cat`, `category`, `category__and`, `category__in`, `category__not_in`, `category_name`, `comments_per_page`, `comments_popup`, `custom`, `customize_messenger_channel`, `customized`, `cpage`, `day`, `debug`, `embed`, `error`, `exact`, `feed`, `fields`, `hour`, `link_category`, `m`, `minute`, `monthnum`, `more`, `name`, `nav_menu`, `nonce`, `nopaging`, `offset`, `order`, `orderby`, `p`, `page_id`, `paged`, `pagename`, `pb`, `perm`, `post__in`, `post__not_in`, `post_format`, `post_mime_type`, `post_status`, `post_tag`, `post_type`, `posts`, `posts_per_archive_page`, `posts_per_page`, `preview`, `robots`, `s`, `search`, `second`, `sentence`, `showposts`, `static`, `subpost`, `subpost_id`, `tag`, `tag__and`, `tag__in`, `tag__not_in`, `tag_id`, `tag_slug__and`, `tag_slug__in`, `taxonomy`, `tb`, `term`, `terms`, `theme`, `themes`, `title`, `type`, `types`, `w`, `withcomments`, `withoutcomments`, `year`), core's internal types (`revision`, `nav_menu_item`, `custom_css`, `customize_changeset`, `oembed_cache`, `user_request`, `wp_block`, `wp_template`, `wp_template_part`, `wp_global_styles`, `wp_navigation`, `wp_font_family`, `wp_font_face`, `action`) and core's internal taxonomies (`wp_theme`, `wp_template_part_area`, `wp_pattern_category`).

Error codes: `slug_pattern`, `slug_length`, `slug_reserved`.

## Menu icons

`menu_icon` takes one of:

| Value | Example | Result |
|---|---|---|
| A dashicon | `"dashicons-calendar-alt"` | Used as is. Must match `^dashicons-[a-z0-9-]+$`. |
| An `https://` URL | `"https://cdn.example.com/event.svg"` | Passed through `esc_url_raw()`. `http://` is refused. |
| A theme SVG | `"icons/event.svg"` | Read, size-capped (32 KB), sanitised on the server, inlined as a `data:image/svg+xml;base64` URI so it follows the admin colour scheme. |
| A theme image | `"icons/event.png"` | `.png`, `.jpg`, `.jpeg`, `.webp`: resolved to the theme URL. |

Theme paths:

- are relative, with no leading `/`, no `:`, no backslash;
- resolve against the theme the file lives in (a PHP declaration resolves against the active, child theme);
- must stay inside that theme after `realpath()` (no `..` or symlink escape);
- must keep their extension after symlinks are resolved.

An SVG is cleaned of scripts, event handlers, external references and `<style>`.
Use `fill="black"` (or `currentColor`) so WordPress tints it.

Anything else falls back to the Proto-Blocks icon with an `icon_invalid` warning.
In a root added by `proto_blocks_schema_paths` (which has no URL), raster images fall back too; use an SVG or a dashicon there.

## Rewrite rules

You never visit Settings > Permalinks.

- On `wp_loaded` (priority 20) the plugin hashes `rewrite`, `has_archive`, `public`, `query_var` and `hierarchical` of every type and taxonomy it registered or overrode.
- When the hash differs from the stored `proto_blocks_rewrite_hash` for the active theme, it runs `flush_rewrite_rules(false)` once and stores the new hash.
- Nothing flushes during a Customizer theme preview.
- Switching themes always flushes once, because the stored hash belongs to the other theme.
- `wp proto-blocks type rename ... --migrate` deletes the stored hash, so the next request flushes.

## Template scaffolding

Block templates are never written as a side effect of registering.
Scaffolding is a deliberate action, from the screen's **Templates** card or the CLI:

```bash
wp proto-blocks type scaffold event               # single-event.html, archive-event.html
wp proto-blocks type scaffold event --taxonomies  # + taxonomy-<slug>.html for each taxonomy of the type
```

It writes into the active theme's `templates/` folder:

| File | When |
|---|---|
| `single-<slug>.html` | always |
| `archive-<slug>.html` | when the type has an archive (the registered `has_archive`, else the declared one, else `true`) |
| `taxonomy-<taxonomy>.html` | with `--taxonomies` (or "Also taxonomy templates" in the UI), for each declared taxonomy attached to the type |

- A template that exists in the active or parent theme is skipped and reported, never overwritten.
- Seeds are copied from the theme's own `templates/single.html` and `templates/archive.html` (header and footer parts included).
- Without a seed, a minimal template is used: header part, a `core/post-title` and `core/post-content` (single) or a `core/query` loop with pagination (archive and taxonomy), footer part.
- Nothing from the declaration is interpolated except the slug in the file name.
- A classic theme (`wp_is_block_theme()` false) is refused: "This is a classic theme. Template scaffolding needs a block theme".
- Scaffolding needs file mods on and, on multisite, a network administrator.

## Guardrails

A valid file can still hide content that exists.
Saves and deletes check the content first.
Nothing here blocks you for good; the UI explains and asks.

| Situation | What happens |
|---|---|
| Changing a type's slug | The slug is the folder name and is read-only in the UI. The screen shows the count and points to `wp proto-blocks type rename`. |
| Removing `title`, `editor` or `thumbnail` from `supports` while posts exist | `409 proto_blocks_needs_confirmation` with a `supports_removed` finding ("Removing editor hides it on 12 existing event posts. Their content is kept."). |
| Removing an inline taxonomy that has terms | `taxonomy_removed` finding: the terms are kept but become inaccessible. |
| Renaming or removing a field while posts exist | `field_removed` finding: stored values stay under the old meta key. |
| Deleting a declaration whose type still has posts (or whose inline taxonomies have terms) | `409 proto_blocks_confirm_required` until you type the slug. The content is never deleted. |

Counts include every post status except `auto-draft`.
To save anyway, send the codes you accept in `acknowledge` (the UI does this when you confirm):

```json
{ "text": "…", "hash": "…", "acknowledge": ["supports_removed"] }
```

## The Post Types screen

**Proto Blocks > Post Types** requires `manage_options`.
The view lives in the URL: `&type=<slug>` opens a type, `&new=1` opens the create dialog.

### List

Every post type with `show_ui`, plus every declared one, with:

- label and slug,
- origin: *Proto-Blocks file*, *Proto-Blocks PHP*, *Core*, *Plugin: <name>* or *Theme: <name>*,
- source (the file, or `file:line` of the PHP call, never an absolute path),
- an override flag and a "Declared twice" badge,
- the post count,
- its issues.

### Create

**Add post type** asks for the singular name.
The plural and slug are generated (slug: 20 characters at most) and the dialog shows the file it will write.
**Create & open** writes `proto-types/<slug>/type.jsonc` from the commented template and opens the form.

### Edit

The form saves back to the JSONC file and keeps your comments.
Sections:

- **General**: singular name, plural name, slug (read-only: it is the folder name).
- **Visibility**: Public, Show in REST API (required for the block editor), Hierarchical.
- **Archive and URLs**: archive page (automatic, automatic at a custom path, or none), URL slug, "Prefix with permalink base" (`with_front`).
- **Supports**: Title, Editor, Excerpt, Featured image, Revisions, Custom fields, Page attributes, Comments, Author.
- **Editor**: template lock.
- **Menu**: icon and menu position (22 = just below Pages).
- **Labels**: empty labels use the generated ones.
- **Taxonomies**: add an inline taxonomy, or attach an existing one by slug.
- **Effective settings**: what WordPress registers after defaults, read-only.
- **Templates**: the scaffold card (see above).
- **File text**: the exact text **Save to file** writes.

Other states:

- A core or plugin type shows "Registered by …" and **Create override file**; only the settings you change are written.
- A PHP-declared type shows "Declared in PHP at …" and saving creates a file that takes over.
- A type declared twice shows "Declared twice — this file wins".
- **Delete…** removes the file after the guardrail checks.
- In read-only mode every control that writes is disabled, with one banner that states why.

## REST routes

All under `proto-blocks/v1`, all need `manage_options` and the REST nonce.

| Method | Route | Purpose |
|---|---|---|
| GET | `admin/types` | Every post type, tagged by origin |
| GET | `admin/types/<slug>` | One type: list item, effective args, content counts, templates |
| POST | `admin/types/<slug>/scaffold` | Write block templates; body `{"taxonomies": true}` (*writes files*) |
| GET | `admin/schema-files/type/<slug>` | The declaration file text and hash |
| POST, PUT | `admin/schema-files/type/<slug>` | Save the file (*writes files*) |
| DELETE | `admin/schema-files/type/<slug>?confirm=<slug>` | Delete the file (*writes files*) |

Shapes and errors: [REST API](rest-api.md#post-types-and-taxonomies).

## CLI

```bash
wp proto-blocks type list [--format=table|csv|json|yaml]
wp proto-blocks type create "Event" [--slug=event] [--plural=Events] [--json]
wp proto-blocks type scaffold event [--taxonomies]
wp proto-blocks type validate [event] [--format=table|json]
wp proto-blocks type rename event happening [--yes] [--migrate]
```

Details: [CLI](cli.md#wp-proto-blocks-type).

## Troubleshooting

| Symptom or code | Cause | Fix |
|---|---|---|
| `declared_too_late` | `proto_post_type()` ran after `init` priority 9. | Call it from `functions.php`. |
| `name_required` | No `name` and nobody registered the slug. | Add `"name"`. |
| `schema_invalid` | A key has the wrong type (for example `"show_in_menu": "edit.php"`, `"menu_position": "22"`). | Fix the value; the message names the JSON path. |
| `slug_mismatch` | `"slug"` differs from the folder name. | Remove `"slug"` or rename the folder. |
| `slug_reserved` / `slug_pattern` / `slug_length` | See [slug rules](#slug-rules). | Pick another slug. |
| `unsafe_key` | A callback key in a file. | Move it to a PHP declaration. |
| `unsafe_label` | `<` or `>` in a name or label. | Write plain text. |
| `icon_invalid` | The icon path is missing, outside the theme, too large or of another type. | See [menu icons](#menu-icons). |
| `late_override_ignored` | The type was registered before the override was known. | Use a file, or declare in `functions.php`. |
| `double_declaration` | A file and a PHP call share the slug. | Delete the PHP line. |
| `invalid_jsonc` | Syntax error at the reported line and column. | Fix the file. |
| `file_too_large` | Over 256 KB. | Split fields into `proto-fields/` files. |
| The type is missing from the block editor | `show_in_rest` is `false`. | Set it to `true`. |
| 404 on the archive after a change | A flush runs on the next request after the change. | Load any page once; check `has_archive` and `rewrite`. |
| The screen is read-only | See [read-only reasons](content-model-files.md#read-only-reasons). | |
