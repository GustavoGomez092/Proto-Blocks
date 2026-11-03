# Post Types, Custom Fields and Settings Pages — Design

**Date:** 2026-11-02
**Status:** Reviewed; awaiting approval. Implementation plan to follow.

## Intent

Proto-Blocks today is a block-building plugin. A site that needs its own
content model still hand-writes `register_post_type`, `register_taxonomy`,
`register_post_meta`, meta boxes and Settings API pages, or installs Advanced
Custom Fields. This design makes Proto-Blocks **self-sufficient**: one plugin
declares post types, taxonomies, custom fields and settings pages in a line of
PHP or a JSONC file, edits them from an admin UI that writes those same files,
and reads values back in templates and core blocks. It takes the plugin from
brochureware sites to data-driven sites without a second plugin.

The developer's own three live sites show what repeats: every custom type is a
closure on `init` with 7 to 20 hand-written labels, `show_in_rest`, `with_front
false`, `menu_position 22`, a dashicon, the same `supports` list, a hierarchical
"type" taxonomy with `show_admin_column`, typed single meta with REST and auth
callbacks, and a meta box that repeats the nonce, autosave and capability
boilerplate (`cadco-theme/inc/cadco-resources.php`,
`optimizedit/inc/oit-resources-cpt.php`). None of those sites uses ACF. All of
that boilerplate is what the defaults absorb.

## Goals

1. **One-line declaration.** `proto_post_type('Product');` registers a complete,
   sensible post type. Every setting can be overridden, none must be.
2. **Files are the only store.** Declarations and every UI edit live in JSONC
   files inside the theme, git-tracked. Nothing about the content model is
   ever written to the database.
3. **A UI for developers and client admins** that lists every post type,
   taxonomy, field group and options page on the site, whatever registered it,
   and edits them by writing JSONC.
4. **ACF-class custom fields** on post types, options pages, taxonomy terms and
   users, with an editing experience that is fast and pleasant to fill.
5. **Settings pages** declared the same way, with tabs and child pages.
6. **Every module stands alone.** Post types without blocks, settings pages
   beside ACF, fields under Elementor's classic editor, blocks only. Any
   combination works.
7. **The security boundary from the November 2026 sweep holds**: untrusted
   authors cannot store markup or scripts, templates get escaped values by
   default, and the UI cannot be used to break a site.

## Non-goals

- Migrating an existing ACF site's stored meta. ACF compatibility is limited to
  the field-group file format and the getter alias. New builds only.
- Classic WordPress menus. Block menus cover them.
- Database-stored declarations of any kind, including as a fallback.
- Replacing site-specific PHP that is too bespoke to declare (CADCO's
  WooCommerce rewrite rules, importer-created attribute taxonomies). Those stay
  in PHP and coexist.

## Anti-goals (what makes this a failure even if it works)

- A declaration that ends up as long as `register_post_type`.
- A UI a client admin can break the site from.
- A slow or clunky editing experience in the field boxes.

## Constraints

- **Stack:** PHP 8.1+ (align the plugin header with `composer.json`, which
  already requires 8.1), WordPress 6.3+, React 18 via `@wordpress/scripts`,
  TypeScript, Jest, PHPUnit without WordPress (per-area `wp-stubs.php`).
- **Admin UI stack (decided):** React, Tailwind, shadcn/ui on Radix,
  react-hook-form, zod. The current admin is PHP-rendered markup with Tailwind
  v3 (`prefix: 'pb-'`, `important: '.proto-blocks-admin-ui'`) and inline jQuery
  AJAX. All of it is rebuilt in the new stack during Phase 0; Basecoat is not
  present and nothing from the old admin survives except the server-side
  handlers it talked to.
- **Tailwind inside `wp-admin`** must never touch core admin styles: no global
  preflight, utilities scoped to the plugin's admin root element. The plugin
  already carries Tailwind v3 for admin CSS and v4 for the in-browser block
  compiler; Phase 0 moves the admin to v4 and drops v3.
- **Hosts:** some installs disable file modifications or mount the theme
  read-only. The UI must detect this and become read-only with a clear message.
- **Trust:** `manage_options` for the UI and for any file write; the
  `unfiltered_html` boundary for stored field values; path confinement for
  every file the UI touches.
- **Coexistence:** ACF, WooCommerce, Elementor and the classic editor may be
  active. Nothing may assume the block editor.

## Decisions

### 1. Declaration model

Two sources, same schema:

- **PHP, one line.** Called from the theme (any time before `init` priority 9):
  ```php
  proto_post_type('Product');
  proto_post_type('Resource', ['has_archive' => false, 'menu_icon' => 'icons/resource.svg']);
  proto_taxonomy('Media Type', ['post_types' => ['resource'], 'hierarchical' => true]);
  proto_options_page('Theme Settings', ['fields' => [...]]);
  proto_field_group('product', [...]);   // a field group attached to an existing type
  ```
  The first argument is the human singular name; the slug, plural and labels
  derive from it. The optional array accepts every key the JSONC file accepts.
- **JSONC file, auto-discovered.** One folder per declaration, parallel to
  `proto-blocks/`:
  ```
  theme/
    proto-blocks/<block>/block.json        (unchanged)
    proto-types/<slug>/type.jsonc          post type + its taxonomies + its field groups
    proto-taxonomies/<slug>/taxonomy.jsonc standalone taxonomy (attaches to any types)
    proto-options/<slug>/page.jsonc        options page + tabs + child pages + fields
  ```
  `.json` is accepted too. Child-theme folders are discovered after parent
  folders and win on the same slug. The filter `proto_blocks_schema_paths`
  adds search roots, mirroring `proto_blocks_paths` for blocks.

**Precedence and the double-declaration warning.** When the same slug is
declared in PHP and in a file, the file wins entirely (no merge) and the plugin
raises a warning: an admin notice on the Proto-Blocks screens, a badge on that
item in the UI, and a `warning` from `wp proto-blocks validate`. The warning
names both locations. This is the designed outcome of editing a PHP-declared
item in the UI: the UI writes a file, the file takes over, the developer sees
the warning and deletes the PHP line when ready.

**External types.** A `proto-types/<slug>/type.jsonc` whose slug is registered
by core or another plugin is an **override file**: its `args` are applied
through the `register_post_type_args` filter, and its taxonomies and field
groups attach to that type. The same mechanism lets a declaration change a
built-in type, for example giving `post` an editor `template` as OptimizedIT
does in `inc/oit-post-template.php`. Taxonomies from other plugins are
overridable the same way through `register_taxonomy_args`.

**Comments.** Files are JSONC: `//` and `/* */` comments, trailing commas
tolerated. The PHP loader strips comments before decoding. Scaffolded files
ship with comments that explain each section.

### 2. Post type defaults

A bare declaration produces, relative to WordPress defaults:

| Setting | Default | Derivation |
|---|---|---|
| slug | `product` | `sanitize_title` of the singular, max 20 chars, must match `^[a-z][a-z0-9_-]*$`, must not collide with reserved core slugs |
| labels | full set | generated from singular and plural; plural by English rules with a `plural` override key; lowercase variants for "No products found" |
| `public` | true | |
| `show_in_rest` | true | |
| `has_archive` | true | string archive slug accepted; `false` for "a page is the archive" (CADCO resources) |
| `rewrite` | `['slug' => <slug>, 'with_front' => false]` | matches every site surveyed |
| `supports` | title, editor, thumbnail, excerpt, revisions | `custom-fields` only on request |
| `menu_position` | 22 | below Pages, where every site puts them |
| `menu_icon` | generic Proto-Blocks SVG | see icon rules |
| `hierarchical` | false | |
| `template` / `template_lock` | none | accepted as in `register_post_type` |
| `taxonomies` | `[]` | inline declarations under a `taxonomies` key |
| `fields` | `[]` | field groups under a `fields` key |
| `capability_type` | `post` | `map_meta_cap` stays default |

Every key of `register_post_type` remains settable; unknown keys pass through
unchanged so the declaration never blocks a WordPress feature.

**Menu icon rules.** A dashicon class is used as is. A theme-relative path:
`.svg` is read, size-capped, sanitised with the plugin's existing SVG
sanitiser (`src/editor/utils/sanitize-svg.ts` has the client rules; the server
needs an equivalent kses-based pass), and inlined as a base64 data URI so it
follows the admin colour scheme; `.png`/`.jpg`/`.webp` resolve to the theme
URL. An absolute `https://` URL passes through. Anything else falls back to the
generic icon with a validation warning.

**Taxonomy defaults.** `public`, `show_in_rest`, `show_admin_column`,
`hierarchical false`, `rewrite` with `with_front false`, generated labels, with
`menu_name` defaulting to the plural. Inline taxonomies pre-fill `post_types`
with the enclosing type; standalone ones list their types and may name types
they do not own.

**Rewrite flushing.** The plugin stores a hash of the registered slugs and
rewrite settings in a transient-free option keyed by theme and flushes rewrite
rules once when the hash changes, on the request after registration. No
flush on every load, no manual step.

### 3. Template scaffolding

A deliberate action, never a side effect of registration. Available as
`wp proto-blocks type scaffold <slug>` and as a button on the type's screen in
the UI. It writes, into the active theme's `templates/`:

- `single-<slug>.html`
- `archive-<slug>.html` when the type has an archive
- `taxonomy-<slug>.html` for each of the type's taxonomies, on request

Existing files are never overwritten; the command reports which it skipped.
On a classic theme (`wp_is_block_theme()` false) the scaffold refuses with a
message rather than generating PHP templates; classic and Elementor sites
build their own templates.
The scaffold seeds from the theme's own `single.html` / `archive.html` when
present (header and footer parts, the main group) and otherwise from a minimal
block template with `core/post-content` or a `core/query` loop. The button is
disabled in read-only mode.

### 4. The admin UI

One React application mounted on every Proto-Blocks admin screen, routed by
screen. Screens: **Blocks** (today's dashboard), **Post Types**,
**Taxonomies**, **Fields**, **Options Pages**, **Tailwind**, **System Status**,
**Previews**, **Setup Wizard**. The last four are today's PHP pages rebuilt;
their server handlers (the `wp_ajax_proto_blocks_tailwind_*` actions,
`PreviewCapture`, wizard steps) are kept and moved to REST where practical.

**List screens** show every item on the site tagged by origin: *Proto-Blocks
file*, *Proto-Blocks PHP*, *Plugin: <name>*, *Core*. Items with both a file and
a PHP declaration carry the double-declaration badge.

**Edit screens** are forms over the declaration schema: react-hook-form with a
zod schema generated from the same JSON Schema the server validates against.
Saving produces a JSONC edit, not a database write:

- The client edits the file text with a comment-preserving JSONC editor
  (`jsonc-parser`'s `modify`/`applyEdits`, the library VS Code uses), so
  developer comments and key order survive.
- The client sends the whole resulting file text to
  `POST /proto-blocks/v1/admin/schema-files/<kind>/<slug>` (PUT also accepted).
- The server re-parses it, validates it against the JSON Schema, confirms the
  path is inside an allowed root (active theme, child theme), confirms
  `manage_options`, the REST nonce, `AdminPage::fileModsAllowed()` and
  `is_writable()`, then writes atomically (`Cache::writeAtomic` pattern) and
  invalidates the schema cache.
- Creating a new item creates the folder and file; deleting one deletes the
  folder after a confirmation that names the path.

**Read-only mode.** If `fileModsAllowed()` is false or the theme folder is not
writable, every list and edit screen renders, with all write controls disabled
and one banner stating why ("File modifications are disabled on this site" or
"The theme folder is not writable: <path>") and what to do. There is no
database fallback.

**Capability.** `manage_options` for every screen and endpoint. Field *values*
are a different matter (section 6).

**Content-aware guardrails.** Valid files can still break a site, so the edit
and delete paths check content before writing:

- Changing a post type's or taxonomy's slug is blocked in the UI while any
  posts or terms exist under it; the screen shows the count and points to the
  CLI, which performs the rename only with `--yes` and offers to migrate.
- Deleting a declaration whose type or taxonomy still holds content requires
  typing the slug and shows what becomes inaccessible; the content itself is
  never deleted.
- Removing `editor`, `title` or `thumbnail` from `supports` on a type with
  content, or unregistering a taxonomy with terms, warns before saving.
- Field renames warn that stored values stay under the old meta key.

### 5. Custom fields

**Declaration.** A field group is an object in the `fields` array of a type
file or options page file, or a standalone group in
`proto-types/<slug>/type.jsonc` for an external type. Its shape **mirrors ACF
local JSON** so an ACF export drops in unchanged: `key`, `title`, `fields[]`
with `key`, `label`, `name`, `type`, `required`, `default_value`,
`instructions`, `placeholder`, `conditional_logic`, `sub_fields`, `layouts`,
`location` rules, `position`, `style`, `menu_order`. Inside a type or options
page file the `location` is implied and may be omitted. ACF-only keys the
plugin does not implement are preserved and ignored, never an error. A JSON
Schema documents the accepted subset; `wp proto-blocks validate` checks it.

**Locations, in delivery order:** post types, options pages, taxonomy terms,
users.

**Field types in version one** and their stored value:

| Type | Stored as | Notes |
|---|---|---|
| text, textarea, number, email, url | string / number | number, email and url are text variants with their own sanitiser and REST type |
| wysiwyg | string (HTML) | `wp_kses_post` for untrusted users |
| image | integer attachment ID | the getter returns `{id,url,alt,width,height,sizes}` |
| file | integer attachment ID | getter returns `{id,url,filename,mime,size}` |
| gallery | integer[] | |
| video | integer ID or string URL | matches the block field |
| link | `{url,title,target}` | `rel` normalised as in `LinkField` |
| select, radio | string or string[] | `choices` map; `optionsSource` accepted like block controls (`wp:posts`, `wp:terms`, theme providers) |
| true/false | boolean | |
| color | string | `proto_blocks_sanitize_color` |
| date | `YYYY-MM-DD` string | time and datetime as variants |
| taxonomy | integer[] term IDs | may also set the post's terms (`save_terms`) |
| relationship | integer[] post IDs | post type and taxonomy filters, ordered |
| group | object | one row holding the sub-field values |
| repeater | array of objects | one row; `min`, `max`, `max` capped by `proto_blocks_repeater_max_items` |
| flexible content | array of `{layout, ...values}` | one row |
| code | string | scripts and raw HTML; **only** users with `unfiltered_html` may write it, others see it read-only |

**The Fields screen is a full visual field-group builder**, like ACF's: a
client admin adds fields, picks types, sets labels, choices, defaults,
instructions, required, validation, conditional logic, sub-fields for group,
repeater and flexible content layouts, and the group's location, position and
style, all through forms, with drag-and-drop ordering. Every edit is written
to the JSONC file like any other declaration. A JSONC view of the same group
with validation is available beside the builder for developers. This is the
largest single piece of UI in the project and is sized as such in Phase 2.

**Location rules.** The accepted ACF grammar is `post_type`, `post_status`,
`post_taxonomy`, `page_template`, `post` (ID), `taxonomy`, `user_role`,
`user_form` and `options_page`. A group that contains a rule type the plugin
does not implement is **not rendered** and raises a validation warning naming
the rule; it never falls open onto every post. When ACF is active and its
registry already holds a group with the same `key`, Proto-Blocks skips its copy
and shows a notice, so a box never renders twice.

**Storage: one meta row per field.** The meta key is the field `name`
(`show_in_rest` schema derived from the type; `single => true`; typed
`object`/`array` schemas for group, repeater and flexible content, stored as
one JSON value). Terms use `register_term_meta`, users `register_meta('user')`.
Options page fields are one option each, named `<page_slug>_<field_name>`,
autoloaded by default with a per-page `autoload` override. Revisions: post meta
registered with `revisions_enabled` where the WordPress version supports it.

**Editing experience.** Field groups render as meta boxes in the position the
group declares (`normal`, `side`, or after the title), so they work in the
block editor, the classic editor and under Elementor. Each box mounts the same
React form stack as the admin UI; the box's inputs carry real `name`
attributes so the save path is the ordinary post form submit (`save_post` with
a nonce) and Gutenberg's meta-box save request, one path for every editor.
Term and user forms mount the same way on their edit screens. Performance is
a requirement: a post with 50 fields must stay interactive; heavy types
(relationship, gallery, wysiwyg) load their pickers lazily.

**Trust for field values.** Trust is decided at write time per user, not
per post, so the `_proto_blocks_unfiltered` post flag is not involved and
term, user and option values follow the same rule. Each meta registration
gets a `sanitize_callback` that sanitises per type when the current user lacks
`unfiltered_html` and stores as given otherwise; `auth_callback` denies only
the code field to untrusted users. A stored code value is therefore trusted by
construction, and an untrusted user saving the post leaves it untouched (the
box renders it read-only and does not resubmit it). The getter returns code
values as stored.

**Validation** runs twice from one definition: zod on the client for immediate
feedback, PHP on save for authority. Required, pattern, min/max, length,
choice membership. A failing save keeps the post but reports the errors in
the box; it never silently drops a value. Conditional logic
(`conditional_logic` rules: equals, not equals, contains, empty, not empty,
pattern) hides fields client-side and skips their validation server-side.

**Getter.**
```php
proto_field(string $name, $context = null, bool $raw = false);
proto_fields($context = null, bool $raw = false);          // all fields of a group holder
proto_rows(string $name, $context = null): iterable;         // repeater / flexible content
```
`$context` is a post ID or object (default: the queried post), `'option:<page>'`,
`'term:<id>'`, or `'user:<id>'`. Values come back **escaped for their declared
type**: text through `esc_html`, URLs through `esc_url`, wysiwyg through
`wp_kses_post`, image and file arrays with escaped URLs and attributes, colour
through the colour sanitiser, code unchanged only when the stored value was
written by a trusted user (otherwise empty). `$raw = true` returns the stored
value. `get_field()`, `the_field()` and `get_fields()` are defined as aliases
only when ACF is not active, and behave identically, escaped by default; WordPress
escapers do not double-encode entities, so a template that escapes again is
harmless.

**Block bindings.** Two routes to the same data:

- Proto-Blocks templates call `proto_field()` directly, and `$template->field()`
  wraps it with the current post context. A repeater field can also be bound
  to `data-proto-repeater` in the editor through a `fieldSource` key in the
  block's `protoBlocks` config (left to the builder to detail; it must not
  weaken the attribute sanitiser).
- A Block Bindings source `proto-blocks/field` with args `{ name, context }`
  lets core paragraph, heading, image and button blocks read post meta, term
  meta and options values, resolved server-side through the same getter. The
  Bindings API exists from WordPress 6.5; the plugin's floor rises to 6.5 in
  Phase 2, which also covers `revisions_enabled` (6.4).

### 6. Security

- **UI and files:** `manage_options`, REST nonce, `fileModsAllowed()`,
  `is_writable()`, path confinement to the theme roots (realpath prefix check,
  no symlink escape), JSON Schema validation before any write, atomic writes,
  size cap on files, `.jsonc`/`.json` only. Deletion only of folders the
  plugin recognises as its own (contain a valid declaration file).
- **Field values:** the same boundary as block attributes. On save, values
  from a user without `unfiltered_html` are sanitised per type with
  `AttributeSanitizer`'s rules (`ksesInline`, `sanitizeUrl`, `sanitizeRel`,
  `wp_kses_post` for wysiwyg); the code field refuses them. The meta
  `auth_callback` enforces the same for REST writes. Values written by trusted
  users are stored as given and the getter still escapes on read.
- **Icons:** SVG sanitised server-side, size-capped.
- **Options providers:** field `optionsSource` uses the existing hardened
  providers (viewable types only, user search limited).
- **No PHP execution from files:** declarations are data; the only PHP is
  the theme's own one-liners.
- **Templates:** scaffolded HTML contains no user data; templates authored by
  developers keep the existing rule that every echo is escaped, which the
  getter now does for them by default.

### 7. Settings pages

```jsonc
// proto-options/theme-settings/page.jsonc
{
  "title": "Theme Settings",
  "menu": "top",                 // "top" | "<parent slug>" | "edit.php?post_type=product"
  "capability": "manage_options",
  "icon": "icons/settings.svg",
  "tabs": [ { "title": "General", "fields": [ ... ] }, { "title": "Tracking", "fields": [ ... ] } ],
  "children": [ { "slug": "footer", "title": "Footer", "fields": [ ... ] } ]
}
```
Top-level or submenu under any existing menu, including a post type's menu.
Storage defaults to one option per field; a page may set
`"storage": { "mode": "array", "option": "cadco_display_settings" }` to keep
every field in one array option under a chosen name, which lets a site that
already stores settings that way adopt the file without losing values or
rewriting reads. Adding fields to an existing core settings screen (the
O-Flex reading-page toggle) is out of scope; such a field stays in PHP.
Tabs are client-side; child pages are real submenu pages. Capability
defaults to `manage_options` and may be any capability (CADCO's
`manage_woocommerce` case). The page renders the same React form stack as the
meta boxes and saves through REST with a nonce, one option per field. The
same page is also where the UI's own "edit this options page's declaration"
link lives for `manage_options` users.

### 8. Modules and independence

The plugin gains a `Modules` layer: `Blocks`, `PostTypes`, `Taxonomies`,
`Fields`, `OptionsPages`, `AdminUI`. Each has a `boot()` and registers only
its own hooks; none calls into another except through declared interfaces
(`Fields` asks `PostTypes` for declared types, and works equally with types it
did not declare). A site with no `proto-blocks/` folder and no block
declarations runs the other modules unchanged. Feature toggles
(`proto_blocks_modules` filter) can switch a module off; the UI shows the
module as disabled rather than failing.

### 9. CLI

`wp proto-blocks type list|create|scaffold|validate`, `taxonomy list|create`,
`fields list|validate`, `options list|create`. `create` writes a commented
JSONC file from the defaults, reusing the block scaffold's reserved-name
checks with the post type slug rule from section 2 (`^[a-z][a-z0-9_-]*$`,
underscores allowed because existing types such as `oit_resource` use them). `validate` covers all
schema files and reports double declarations.

### 10. Phases and what "done" means

**Phase 0, admin rebuild.** The new React application replaces every current
admin page with no loss of function. Tailwind v3 removed, admin built on v4
with scoped preflight. All current PHPUnit and Jest suites pass; new Jest
coverage for the shell, forms and the JSONC editor; PHPUnit and Jest added to
CI (today neither workflow runs them).

**Phase 1, post types and taxonomies.** `proto_post_type('Product')` registers
the type with the defaults above; the JSONC file does the same; the file wins
with a warning; overrides of external and built-in types work; icon rules;
rewrite flushing; the Post Types and Taxonomies screens list, create, edit and
delete through files; read-only mode; template scaffold from CLI and UI; CLI
commands; validator. Done when the CADCO resource type and OptimizedIT resource
type can be re-declared as one line each plus a short override array and
produce the same registered object (a comparison test).

**Phase 2, fields.** All sixteen types, four locations in order, one-row
storage with REST schemas, the editing boxes in the three editors, validation
on both sides, conditional logic, getter with escaping and raw flag, aliases,
block bindings source, trust boundary, and the visual field-group builder.
The builder is planned as its own sub-phase after the storage, boxes and
getter are working from hand-written files. Done when an ACF local
JSON export for a realistic group loads and edits without changes and the
measured editing performance target holds.

**Phase 3, settings pages.** Top-level and submenu pages, tabs, children,
capabilities, one option per field, getter context `option:`, Options Pages
screen, CLI. Done when CADCO's display settings page can be re-declared as a
file, reading its existing array option through the storage override, and
renders with its conditional heading field.

Each phase ships as its own release through the existing release workflow.

## Left to the builder

- Internal class layout within `Modules`, REST route shapes, JSON Schema
  authoring, and the server-side JSONC parser.
- The pluralisation rules (a small English table with the explicit `plural`
  override is enough).
- The Tailwind v4 scoping technique for `wp-admin`, as long as no core admin
  style changes.
- shadcn/ui component selection and theming; the screen layouts will be
  designed separately with the Design artifact type before Phase 0 UI work.
- Exact performance target and how it is measured for the field boxes.
- How `fieldSource` binds a repeater field into a block template without
  weakening `AttributeSanitizer`.
- Test strategy details, within the existing PHPUnit-without-WordPress and
  Jest conventions.

## Calls made by the author, open to objection

- Folder names `proto-types/`, `proto-taxonomies/`, `proto-options/` and file
  names `type.jsonc`, `taxonomy.jsonc`, `page.jsonc`.
- Client-side comment-preserving editing with `jsonc-parser`, server-side
  validation of the whole file, rather than a PHP comment-preserving writer.
- Nested field types stored as one JSON meta value rather than ACF's flattened
  rows.
- Options stored one option per field, autoloaded by default.
- ACF aliases limited to `get_field`, `the_field`, `get_fields`; repeater
  loops use `proto_rows()` rather than `have_rows`/`the_row`.
- `number`, `email`, `url`, `time`, `datetime` added as cheap variants of
  text and date.
- Meta boxes as the single editing surface in all editors, saved through the
  post form, rather than a Gutenberg sidebar panel.
- `proto_field_group()` for declaring a group, keeping `proto_fields()` as the
  getter.
- Write-time, per-user trust for field values rather than the per-post flag.
- Content-aware guardrails on slug changes, deletes and `supports` removal.
- Unknown ACF location rules fail closed; same-key groups skipped when ACF is
  active.
- Classic themes get a refusal from the template scaffold, not PHP templates.
- The `storage` override for settings pages; core settings screens out of
  scope.
- WordPress floor 6.5 from Phase 2 for Block Bindings.
