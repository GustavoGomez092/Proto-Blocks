# Taxonomies

> **At a glance**
> - **What:** complete taxonomies (and overrides of `category`, `post_tag` and plugin taxonomies) from a name.
> - **Where:** `your-theme/proto-taxonomies/<slug>/taxonomy.jsonc`, or inline in a `proto-types/<slug>/type.jsonc`.
> - **PHP:** `proto_taxonomy('Topic', ['post_types' => ['event', 'post']]);` in `functions.php`.
> - **Admin:** Proto Blocks > Taxonomies (`admin.php?page=proto-blocks-taxonomies`).
> - **CLI:** `wp proto-blocks taxonomy list|create|validate|rename`.

A bare name is enough.
The plural, the slug and every label are generated.
Every `register_taxonomy()` argument can still be set.

Related: [post types](post-types.md), [fields](fields.md), [content model files](content-model-files.md), [REST API](rest-api.md#post-types-and-taxonomies), [CLI](cli.md#wp-proto-blocks-taxonomy).

## Declare a taxonomy

### In PHP

```php
// functions.php
proto_taxonomy('Topic', ['post_types' => ['event', 'post']]);
```

Signature:

```php
proto_taxonomy(string $name, array $args = []): void
```

- `$name` is the human singular name.
- `$args` takes any key a `taxonomy.jsonc` file accepts.
- Call it from `functions.php`, or on `init` before priority 9.
  A later call is ignored with a `declared_too_late` warning.

### In a file

```jsonc
// proto-taxonomies/topic/taxonomy.jsonc
// Standalone taxonomy shared by a Proto-Blocks type and a core type.
{
  "name": "Topic",
  "post_types": ["event", "post"],
  "show_admin_column": true
}
```

The folder name is the slug.
A `"slug"` key, when written, must equal the folder name.

A standalone taxonomy may attach to types it does not own, including core and plugin types.

### Inline in a post type

```jsonc
// proto-types/event/type.jsonc
{
  "name": "Event",
  "taxonomies": [
    { "name": "Event Type", "hierarchical": true }
  ]
}
```

The inline taxonomy `event-type` gets `post_types: ["event"]` automatically.
It is edited in the type's file.
See [post types: inline taxonomies](post-types.md#inline-taxonomies).

## Every key

Keys marked *plugin* never reach WordPress.
Every other key goes to `register_taxonomy()`.
Unknown keys pass through unchanged.

| Key | Type | Default | Notes |
|---|---|---|---|
| `name` | string, 1–100 chars, no `<` `>` | — (*plugin*) | Singular name. Required to register a new taxonomy; optional in an override. |
| `plural` | string, 1–100 chars, no `<` `>` | generated (*plugin*) | |
| `slug` | `^[a-z][a-z0-9_-]{0,31}$` | from `name` (*plugin*) | Must equal the folder name in a file. |
| `post_types` | list of strings | `[]` (*plugin*) | The object types. An inline taxonomy always includes its type. |
| `fields` | list of field groups | `[]` (*plugin*) | Shown on this taxonomy's term forms. See [fields](fields.md). |
| `$schema` | string | — (*plugin*) | Ignored. |
| `labels` | object of strings, no `<` `>` | full generated set | Merges over the generated labels. |
| `public` | boolean | `true` | |
| `show_in_rest` | boolean | `true` | Needed for the block editor's term panels. |
| `show_admin_column` | boolean | `true` | |
| `hierarchical` | boolean | `false` | `true` is category-like (checklist), `false` tag-like. |
| `show_ui` | boolean | WordPress default | |
| `show_tagcloud` | boolean | WordPress default | |
| `show_in_quick_edit` | boolean | WordPress default | |
| `rewrite` | `false`, or object `{slug, with_front, hierarchical}` | `{"slug": "<slug>", "with_front": false}` | An object merges over the default. |
| `meta_box_cb` | `false` only | WordPress default | `false` hides the term box. A callback cannot be set from a file. |

Merge rules are the same as for post types: `labels` merges, a `rewrite` object merges, every other key replaces.

The same keys are refused in files as for post types (callback keys, `_builtin`, `_edit_link`, label markup).
See [post types: keys a file cannot set](post-types.md#keys-a-file-cannot-set).

## What gets registered

A bare `proto_taxonomy('Topic')` registers `topic` with:

| Argument | Value |
|---|---|
| object types | `post_types` |
| `labels` | the generated set below |
| `public` | `true` |
| `show_in_rest` | `true` |
| `show_admin_column` | `true` |
| `hierarchical` | `false` |
| `rewrite` | `['slug' => 'topic', 'with_front' => false]` |

### Generated labels

Every taxonomy gets, for `Topic` / `Topics`:
`name` (Topics), `singular_name` (Topic), `menu_name` (Topics), `search_items`, `all_items`, `edit_item`, `view_item`, `update_item`, `add_new_item`, `new_item_name`, `not_found` ("No topics found."), `no_terms`, `items_list_navigation`, `items_list`, `most_used`, `back_to_items` ("← Go to Topics"), `item_link`, `item_link_description`, `name_field_description`, `slug_field_description`, `desc_field_description`.

A hierarchical taxonomy adds `parent_item`, `parent_item_colon`, `parent_field_description` and `filter_by_item`.
A flat one adds `popular_items`, `separate_items_with_commas`, `add_or_remove_items` and `choose_from_most_used`.
Editors read your names everywhere, never "tags" or "categories".

## Overrides

A declaration whose slug is already registered, or is `category` or `post_tag`, is an **override**.
Only the declared keys are applied, through the `register_taxonomy_args` filter.
Declared `post_types` are attached with `register_taxonomy_for_object_type()` on `init` priority 20.

```jsonc
// proto-taxonomies/category/taxonomy.jsonc
// Rename categories and show them on events too.
{
  "labels": { "name": "Sections", "singular_name": "Section", "menu_name": "Sections" },
  "post_types": ["event"],
  "show_admin_column": true
}
```

A taxonomy registered before the declaration was known gets only `labels`, `show_admin_column`, `show_in_quick_edit` and `description` applied to the object.
Other keys are ignored with `late_override_ignored`.

A name-less declaration whose slug nobody registers by `wp_loaded` is a `name_required` error.

## Precedence

- A file beats a PHP call of the same slug (`double_declaration` warning).
- A standalone taxonomy (file or PHP) beats one of the same slug declared inline in a type; the inline type joins its `post_types`.
- A child theme's folder beats the parent's.
- A root added with `proto_blocks_schema_paths` comes after the themes.

## Slug rules

- Derived from `name` with `sanitize_title()`, cut to 32 characters, trailing `-` removed.
- Must match `^[a-z][a-z0-9_-]*$`, at most 32 characters.
- `category` and `post_tag` are allowed, as overrides only.
- The same reserved words as post types are refused, including `post_format`, `nav_menu`, `link_category`, `wp_theme`, `wp_template_part_area` and `wp_pattern_category`.
  The full list is in [post types: slug rules](post-types.md#slug-rules).

## Rewrite rules

Taxonomy `rewrite`, `public`, `query_var` and `hierarchical` are part of the rewrite hash.
A change flushes rewrite rules once on the next request.
See [post types: rewrite rules](post-types.md#rewrite-rules).

## Templates

Taxonomy templates are scaffolded with their post type:

```bash
wp proto-blocks type scaffold event --taxonomies   # writes taxonomy-topic.html, taxonomy-event-type.html
```

See [post types: template scaffolding](post-types.md#template-scaffolding).

## Guardrails

| Situation | What happens |
|---|---|
| Changing the slug | Read-only in the UI (it is the folder name). Use `wp proto-blocks taxonomy rename`. |
| Renaming or removing a field while terms exist | `field_removed` finding: stored values stay under the old meta key. |
| Deleting a declaration whose taxonomy has terms | `409 proto_blocks_confirm_required` until you type the slug. Terms are kept but become inaccessible. |
| Removing an inline taxonomy from a type while it has terms | `taxonomy_removed` finding on the type's save. |

Term counts include empty terms.

## The Taxonomies screen

**Proto Blocks > Taxonomies** requires `manage_options`.

### List

Every taxonomy with `show_ui`, plus every declared one, with its origin (*Proto-Blocks file*, *Proto-Blocks PHP*, *Core*, *Plugin: …*, *Theme: …*), source, attached post types, inline parent type, term count, badges and issues.

### Create and edit

**New taxonomy** opens the form; saving writes `proto-taxonomies/<slug>/taxonomy.jsonc`.
Sections:

- **Names**: name, plural name, slug (filled in from the name; read-only once the file exists, rename it with WP-CLI).
- **Attached to**: the post types editors see it on. A taxonomy may attach to types it does not own.
- **Behaviour**: Hierarchical (like categories), Show as a column in admin lists, Public, Show in REST API.
- **URLs**: URL slug and "Prefix with permalink base".
- **Labels**.

A taxonomy declared inline is read-only here: "This taxonomy is declared inside the event post type file, so it is edited there."
A core or plugin taxonomy offers **Create override file**.
A PHP taxonomy shows "Declared in PHP at …"; saving writes a file that takes over.
**Delete…** removes the file after the guardrail checks.

## REST routes

| Method | Route | Purpose |
|---|---|---|
| GET | `admin/taxonomies` | Every taxonomy, tagged by origin, with attached types and inline parent |
| GET | `admin/taxonomies/<slug>` | One taxonomy: list item, effective args, attached types, term count |
| GET | `admin/schema-files/taxonomy/<slug>` | The file text and hash |
| POST, PUT | `admin/schema-files/taxonomy/<slug>` | Save the file (*writes files*) |
| DELETE | `admin/schema-files/taxonomy/<slug>?confirm=<slug>` | Delete the file (*writes files*) |

Shapes and errors: [REST API](rest-api.md#post-types-and-taxonomies).

## CLI

```bash
wp proto-blocks taxonomy list [--format=table|csv|json|yaml]
wp proto-blocks taxonomy create "Topic" [--slug=topic] [--plural=Topics] [--json]
wp proto-blocks taxonomy validate [topic] [--format=table|json]
wp proto-blocks taxonomy rename topic subject [--yes] [--migrate]
```

`create` writes a file with `"post_types": []`; add the types it attaches to.
Details: [CLI](cli.md#wp-proto-blocks-taxonomy).

## Troubleshooting

| Symptom or code | Cause | Fix |
|---|---|---|
| The taxonomy does not appear on a type | `post_types` does not list it, or the type is not registered. | Add the type slug to `post_types`. |
| No term panel in the block editor | `show_in_rest` is `false`. | Set it to `true`. |
| `name_required` | No `name` and nobody registered the slug. | Add `"name"`. |
| `double_declaration` on an inline taxonomy | A standalone file of the same slug exists, or two types inline it differently. | Keep one declaration. |
| "Declared inline … edit it there" | Inline taxonomies are saved through their type. | Edit `proto-types/<type>/type.jsonc`. |
| `slug_reserved` | The slug is a WordPress reserved word. | Pick another slug. |

Other codes are explained in [post types: troubleshooting](post-types.md#troubleshooting).
