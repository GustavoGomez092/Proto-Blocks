# Settings pages

> **At a glance**
> - **What:** admin settings pages (options pages) with tabs, child pages, their own capability, and values read with `proto_field()`.
> - **Where:** `your-theme/proto-options/<slug>/page.jsonc` (or `page.json`).
> - **PHP:** `proto_options_page('Theme Settings', ['fields' => [...]]);` in `functions.php`.
> - **Admin:** Proto Blocks > Options Pages (`admin.php?page=proto-blocks-options-pages`) edits the declarations; each page appears where it declares.
> - **CLI:** `wp proto-blocks options list|create`.

A page renders the same field form as the meta boxes and saves through REST behind the page's own capability.
Values are stored one option per field, or in one array option you choose.

Related: [fields](fields.md), [content model files](content-model-files.md), [REST API](rest-api.md#settings-pages), [CLI](cli.md#wp-proto-blocks-options), [trust and security](trust-and-security.md).

## Declare a page

### In PHP

```php
// functions.php
proto_options_page('Theme Settings', [
    'menu'     => 'top',
    'icon'     => 'dashicons-admin-generic',
    'autoload' => false,
    'tabs'     => [
        ['title' => 'General',  'fields' => [['name' => 'phone', 'label' => 'Phone', 'type' => 'text']]],
        ['title' => 'Tracking', 'fields' => [['name' => 'head_scripts', 'label' => 'Head scripts', 'type' => 'code']]],
    ],
    'children' => [
        ['slug' => 'footer', 'title' => 'Footer', 'fields' => [['name' => 'copyright', 'type' => 'text']]],
    ],
]);
```

Signature:

```php
proto_options_page(string $title, array $args = []): void
```

- `$title` is the page title.
- The slug comes from the title (`sanitize_title()`, `_` turned into `-`) unless `$args['slug']` is set.
  When no valid slug can be derived, the call is ignored with a `_doing_it_wrong` notice; pass a `slug`.
- `$args` takes any key a `page.jsonc` accepts.
- **Declare pages in `functions.php`, any time before `wp_loaded`.**
  A call inside an `admin_menu` callback still adds the menu item, but that page is unknown to REST saves and to `proto_field()` on other requests, so saving it fails.
  A call after `wp_loaded` (or after `admin_menu` priority 10 in the admin) is ignored with a `declared_too_late` warning.

### In a file

```jsonc
// proto-options/theme-settings/page.jsonc
{
  // Theme-wide settings: top-level menu, two tabs and one child page.
  "title": "Theme Settings",
  "menu": "top",
  "icon": "dashicons-admin-generic",
  "autoload": false,
  "tabs": [
    { "title": "General", "fields": [ { "name": "phone", "label": "Phone", "type": "text" } ] },
    { "title": "Tracking", "fields": [ { "name": "head_scripts", "label": "Head scripts", "type": "code" } ] },
  ],
  "children": [
    { "slug": "footer", "title": "Footer", "fields": [ { "name": "copyright", "type": "text" } ] },
  ],
}
```

The folder name is the page slug and its admin page slug.
A `"slug"` key, when written, must equal the folder name.
A file beats a PHP call of the same slug; a child theme's folder beats the parent's.

A new file from the CLI or the screen starts as:

```jsonc
// Proto-Blocks options page declaration.
// The folder name is the page slug and its admin menu slug. Every commented
// line shows a default; uncomment and edit it to change that default.
{
  "title": "Theme Settings",
  // Where the page appears: "top" for a menu item of its own, or the slug of
  // a parent menu such as "options-general.php" or "edit.php?post_type=product".
  // "menu": "top",
  // Who can open the page and save its values.
  // "capability": "manage_options",
  // "menuTitle": "Theme Settings",
  // "icon": "dashicons-admin-generic",
  // "position": 80,
  // "autoload": true,
  // Values are stored as one option per field ("<slug>_<field name>"), or
  // all in one array option with { "mode": "array", "option": "<name>" }.
  // "storage": { "mode": "fields" },
  // "tabs": [{ "title": "General", "fields": [] }],
  // "children": [{ "slug": "advanced", "title": "Advanced", "fields": [] }],
  "fields": []
}
```

## Every key

| Key | Type | Default | Notes |
|---|---|---|---|
| `title` | string, 1–200 chars, no `<` `>` | required | The page heading. |
| `slug` | `^[a-z][a-z0-9-]{0,63}$` | folder name / from title | Must equal the folder name in a file. |
| `menu` | string, `^[a-z0-9_.?=&-]+$` | `"top"` | `"top"`, or a parent menu slug. See [menu placement](#menu-placement). |
| `menuTitle` | string, no `<` `>` | `title` | The menu label. `menu_title` is accepted as an alias; `menuTitle` wins. |
| `capability` | `^[a-z][a-z0-9_]*$` | `"manage_options"` | Who can open the page and save it. Used verbatim. |
| `icon` | `"dashicons-*"`, or a theme-relative `.svg` path | generic dashicon | Top-level pages only. No URLs, no `..`. |
| `position` | integer | WordPress default | Menu position. |
| `autoload` | boolean | `true` | Autoload for every option this page writes. |
| `storage` | `{"mode": "fields"}` or `{"mode": "array", "option": "<name>"}` | `{"mode": "fields"}` | See [storage](#storage-and-option-names). |
| `fields` | list of fields or field groups | `[]` | Shown above any tabs. |
| `tabs` | list of `{title, fields}` | `[]` | Each `title` 1–200 chars, no `<` `>`. |
| `children` | list of child pages | `[]` | See [child pages](#child-pages). |
| `$schema` | string | — | Ignored. |

Unknown keys are allowed and ignored.
Callback keys (`register_meta_box_cb`, `rest_controller_class`, `meta_box_cb`, ...) are refused: a page file holding one fails the schema check and is skipped.

### Child pages

| Key | Type | Default |
|---|---|---|
| `slug` | `^[a-z][a-z0-9-]*$`, required | — |
| `title` | string, 1–200 chars, no `<` `>`, required | — |
| `capability` | as above | the parent's |
| `autoload` | boolean | the parent's |
| `storage` | as above | `{"mode": "fields"}` (not inherited) |
| `fields`, `tabs` | as above | `[]` |
| `menuTitle` / `menu_title`, `position` | as above | |

- A child's full slug is `<parent>-<child>`: `theme-settings-footer`.
- A child page is a real submenu page under its parent.
- A child cannot have children.
- A full slug that is reserved is an error (`child_slug_reserved` at load); one that another page already uses is skipped (`child_slug_taken`).

## Slug rules

- `^[a-z][a-z0-9-]*$`: lowercase letters, digits and hyphens (no `_`), starting with a letter.
- At most 64 characters.
- Reserved: `proto-blocks`, anything starting with `proto-blocks-`, and the core and WooCommerce screens `index`, `general`, `writing`, `reading`, `discussion`, `media`, `permalink`, `privacy`, `options`, `options-general`, `themes`, `plugins`, `users`, `tools`, `profile`, `upload`, `menus`, `widgets`, `customize`, `woocommerce`.

## Menu placement

| `menu` | Result |
|---|---|
| `"top"` | A top-level menu item with `icon` and `position`. |
| a core screen, e.g. `"options-general.php"`, `"tools.php"`, `"themes.php"`, `"users.php"` | A submenu item there. |
| a post type menu, e.g. `"edit.php?post_type=product"` | Under that type's menu. |
| a plugin's menu slug, e.g. `"woocommerce"` | Under that plugin's menu. |
| another declared page's slug, e.g. `"theme-settings"` | Under that page. |

- A parent that does not exist leaves the page reachable only by URL (`parent_missing` warning).
- Pages whose parents form a cycle register top-level (`menu_cycle` warning).
- WordPress cannot nest a submenu under a submenu, so the children of a page that is itself a submenu are listed beside it, under the same parent (an info note, `child_of_submenu`).
- A top-level page with children repeats itself as the first submenu item, so it keeps its own label.

The Options Pages screen lists the menus other plugins and the theme registered, so you can pick a parent.

## Capability

- `capability` defaults to `manage_options` and may be any capability, such as `manage_woocommerce` or `edit_pages`.
- It is used verbatim, never widened.
- The menu item, the page, and `proto-blocks/v1/options/<slug>` all require it, plus a logged-in user.
  `manage_options` is not implied.
- A capability no role grants leaves the page to super admins only (`capability_unknown` warning).
- A child page inherits its parent's capability unless it sets its own.

## Fields on a page

`fields` (and each tab's `fields`) is a list of bare fields, field groups, or both.

```jsonc
"fields": [
  { "name": "phone", "label": "Phone", "type": "text" },                 // a bare field
  { "name": "email", "label": "Email", "type": "email" },                // joins the same implicit group
  { "key": "group_social", "title": "Social", "fields": [                // a field group
      { "name": "instagram", "type": "url" },
      { "name": "linkedin", "type": "url" }
  ] }
]
```

- An entry with `type` is a field; an entry with `fields` is a group.
- Consecutive bare fields share an implicit group, keyed `group_<page_slug_with_underscores>_<n>` and titled with the page or tab title.
- A field without `key` gets `field_<page_slug_with_underscores>_<name>` (for `phone` on `theme-settings`: `field_theme_settings_phone`).
- A group without `key` gets the implicit key; without `title`, the page or tab title.
- Field names must match `^[a-z][a-z0-9_]*$` (stricter than other locations) and be unique across the whole page, tabs included.
- Every [field type](fields.md#field-types) works.
- Conditions refer to field keys, generated ones included.

A standalone group can target a page too:

```jsonc
// proto-fields/theme-settings-social/group.jsonc
{
  "key": "group_theme_social",
  "title": "Social",
  "location": [[{ "param": "options_page", "operator": "==", "value": "theme-settings" }]],
  "fields": [ { "key": "field_theme_social_x", "name": "x_handle", "type": "text" } ]
}
```

Its fields appear after the page's own groups.
A field whose name the page already uses is skipped (`field_skipped` warning).

## Storage and option names

### One option per field (default)

Each top-level field is its own option, named `<page_slug>_<field_name>` with the slug's hyphens kept:

| Page | Field | Option |
|---|---|---|
| `theme-settings` | `phone` | `theme-settings_phone` |
| `theme-settings` | `head_scripts` | `theme-settings_head_scripts` |
| `theme-settings-footer` | `copyright` | `theme-settings-footer_copyright` |

- An option name over 191 characters is an error.
- Autoload follows the page's `autoload`.

### One array option

```jsonc
"storage": { "mode": "array", "option": "cadco_display_settings" }
```

- Every field of the page is one key of that array option.
- `option` matches `^[A-Za-z0-9_-]+$`, at most 191 characters.
- Keys of the option that are not declared fields survive a save.
- When the option already holds a value that is not an array (another plugin's), nothing is saved and a notice says so.
- A `true_false` value keeps the format already stored: `'1'`/`''`, `'1'`/`'0'`, `'yes'`/`'no'`, `'on'`/`'off'`, `'true'`/`'false'` or `1`/`0`; a new value is a PHP boolean.

### Protected names

A page can never read or write core or plugin-critical options.
Refused names include `siteurl`, `home`, `active_plugins`, `template`, `stylesheet`, `cron`, `rewrite_rules`, `admin_email`, `users_can_register`, `default_role`, every core settings screen option, the salts, `<prefix>user_roles`, and any name starting with `_transient`, `_site_transient`, `theme_mods_`, `widget_`, `proto_blocks_` or `auto_update_`, or ending with `user_roles`.
Comparison is case-insensitive.

- In `fields` mode, a field whose derived option name is protected makes the declaration invalid.
- In `array` mode, a protected `option` is invalid.
- The writer refuses such a file; a save that targets one answers `403 proto_blocks_protected_option`.

Add your own names with the `proto_blocks_protected_options` filter (it receives the exact-name list; the prefix and suffix rules always apply):

```php
add_filter('proto_blocks_protected_options', static function (array $names): array {
    $names[] = 'my_license_key';
    return $names;
});
```

### Shared storage

Two pages that write the same option name get a `shared_storage` warning.
Saving one page may overwrite fields of the same name on the other; give each page its own option.

## Reading values

```php
$phone     = proto_field('phone', 'option:theme-settings');
$copyright = proto_field('copyright', 'option:theme-settings-footer');
$all       = proto_fields('option:theme-settings');           // name => value
$scripts   = proto_field('head_scripts', 'option:theme-settings'); // code: as stored
$raw       = proto_field('phone', 'option:theme-settings', true);
```

- `option:<page_slug>` reads either storage mode.
- Values are escaped for their type, like every field. `code` comes back as stored.
- A field never saved returns its `default_value`.
- Only fields declared for that page are read; other names return `null`.
- A field whose option name is protected is never read.

In a block, bind a page field with `"context": "option:theme-settings"` (see [Block Bindings](fields.md#block-bindings)).
Settings page fields are bindable by any author unless the field sets `"bindings": false`.

## The settings page

Each declared page renders a React form in the admin.

- Fields above the tabs show first; tabs are client-side and **Save settings** saves every tab.
- A field hidden by conditional logic is not written.
- Validation runs in the browser and on the server.
  On the server, a page save is all-or-nothing: any invalid field returns the errors and nothing is saved ("Nothing was saved. Fix the marked fields and save again.").
- For a user without `unfiltered_html`, `code` and `password` fields (and containers holding one) show "Hidden; only users with unfiltered HTML may view and edit this field." Their values never reach the browser, and a save leaves them untouched.
- Users with `manage_options` see an **Edit declaration** link to the Options Pages screen.
- The page opens at `admin.php?page=<slug>` (top level) or `<parent>?page=<slug>` (submenu).

## The Options Pages screen

**Proto Blocks > Options Pages** requires `manage_options`.

### List

- Every declared page: page, menu placement, capability, storage (per field, or the array option), origin (*Proto-Blocks file* or *Proto-Blocks PHP*), "Declared twice", warnings, and **View** / **Edit**.
- **Other settings pages**: the settings pages other plugins and the theme add to the menu, read-only, with their slug and who added them.

### New options page

**New options page** asks for the page title (the slug is generated and editable), then **Continue** opens the full form for menu, access and fields.
The file `proto-options/<slug>/page.jsonc` is written when you save.

### Edit

- **Page**: title.
- **Menu placement**: top-level menu item, or under an existing menu (parent picker); position; menu title.
- **Access & storage**: the capability (with a warning when no role has it, or when everyone who can log in would get it), how values are saved (one setting per field, recommended, or a single array option and its name), and "Load on every page" (autoload).
- **Fields**: the page's fields, edited with the [field builder](fields.md#the-fields-screen-and-builder) components.
- **Tabs**: add, rename, reorder, remove; each holds its own fields.
- **Child pages**: title, slug, capability ("Inherit from page"), fields.
- **Save** writes the file; **Delete** removes it ("Saved values are not deleted.").

A PHP-declared page is shown read-only with **Create file**, which writes its declaration to a file that then takes over.
The editor is read-only under `DISALLOW_FILE_MODS` / `DISALLOW_FILE_EDIT`, for multisite users who are not network administrators, and for files it cannot write.
A save that conflicts with a change on disk asks before overwriting.
Comments are kept, except inside an array you edit (such as `fields` or `tabs`).

## REST routes

| Method | Route | Permission | Purpose |
|---|---|---|---|
| GET | `options/<slug>` | logged in + the page capability | `{values, trusted, readOnly}` |
| POST | `options/<slug>` | logged in + the page capability | save `{fields, present}`; returns `{values, notices}` |
| GET | `admin/options-pages` | `manage_options` | the list, external menus, capabilities, warnings |
| GET | `admin/options-pages/<slug>` | `manage_options` | one page, its declaration, menu parents, capabilities |
| GET, POST, PUT, DELETE | `admin/schema-files/options/<slug>` | `manage_options` (+ file writes) | the declaration file |

Saving `theme-settings`:

```bash
curl -X POST https://example.test/wp-json/proto-blocks/v1/options/theme-settings \
  -H "X-WP-Nonce: $NONCE" -H 'Content-Type: application/json' \
  -d '{
        "fields":  { "group_theme_settings_1": { "phone": "+1 555 0100" } },
        "present": { "group_theme_settings_1": ["phone"] }
      }'
```

```json
{ "values": { "phone": "+1 555 0100", "head_scripts": "" }, "notices": [] }
```

Only fields listed in `present` are written.
`values` are the stored (raw) values of every page field, or their defaults.
For a user without `unfiltered_html`, `code` and `password` values are `null`.
Full shapes and errors: [REST API](rest-api.md#settings-pages).

## CLI

```bash
wp proto-blocks options list [--format=table|json|csv]
wp proto-blocks options create "Theme Settings" [--slug=theme-settings] [--menu=top] [--capability=manage_options] [--storage-option=<name>]
```

`list` columns: `slug`, `title`, `menu`, `capability`, `storage` (`fields` or `array:<option>`), `origin`.
`create` writes `proto-options/<slug>/page.jsonc` into the active theme.
Details: [CLI](cli.md#wp-proto-blocks-options).

## Turning the module off

```php
add_filter('proto_blocks_modules', static fn (array $modules): array => ['options_pages' => false] + $modules);
```

Settings pages keep working with the Fields module off.
Without the Fields module, `proto_field('x', 'option:<page>')` returns `null`; read the options directly.

## Example: adopting a hand-written page (CADCO)

CADCO's theme had a hand-written **Products > Display Settings** page that stored everything in one option, `cadco_display_settings`, behind `manage_woocommerce`.
It is re-declared as a file with the same option, parent menu and capability (`tests/fixtures/options-pages/cadco-display-settings/page.jsonc`):

```jsonc
// proto-options/cadco-display-settings/page.jsonc
{
  // CADCO product display settings, adopted from the theme's hand-written page.
  "title": "Display Settings",
  "menu": "edit.php?post_type=product",
  "capability": "manage_woocommerce",
  "storage": { "mode": "array", "option": "cadco_display_settings" },
  "fields": [
    { "key": "field_cadco_hide_children", "name": "hide_children", "label": "Hide child products", "type": "true_false" },
    { "key": "field_cadco_children_placement", "name": "children_placement", "label": "Child product placement", "type": "radio",
      "choices": { "related": "Related products", "section": "Own section" }, "default_value": "related" },
    { "key": "field_cadco_children_heading", "name": "children_heading", "label": "Section heading", "type": "text",
      "conditional_logic": [[ { "field": "field_cadco_children_placement", "operator": "==", "value": "section" } ]] },
  ],
}
```

Then delete the theme's own `add_submenu_page()` and settings registration.

```php
// The theme's existing reads keep working:
$settings = get_option('cadco_display_settings', []);
$hide     = !empty($settings['hide_children']);

// New code can use the getter:
$placement = proto_field('children_placement', 'option:cadco-display-settings');
```

What to expect:

- Stored values keep working; undeclared keys of the option survive a save.
- `true_false` keeps the stored format (`'1'`/`''` stays `'1'`/`''`), so `!empty()` reads are unaffected.
- `children_heading` is hidden unless placement is "Own section", and a hidden field is not written; a theme that always stored a default heading keeps its own fallback.
- The per-field names `<slug>_<field>` are used only in `fields` mode.

## Trust rules

- Saving needs the page capability; values are then sanitised per type for users without `unfiltered_html`, exactly as post meta.
- `code` and `password` fields need `unfiltered_html` to read in the form, to save, and to see over REST.
- Protected option names are refused at load, at write time and at read time.
- With the Fields module on, every per-field option (`fields` mode) also gets a `sanitize_option_<page>_<name>` filter with the same trust callback, so `update_option()` from other code meets the same boundary.

See [trust and security](trust-and-security.md).

## Troubleshooting

| Symptom or code | Cause | Fix |
|---|---|---|
| "No settings page has that slug." (`404 proto_blocks_options_page_not_found`) on save | The page was declared inside `admin_menu`, or too late. | Declare it in `functions.php`. |
| `declared_too_late` | `proto_options_page()` after `wp_loaded` (or `admin_menu` 10 in the admin). | Call it earlier. |
| The menu item is missing | No role has the capability (`capability_unknown`), or the parent does not exist (`parent_missing`). | Fix `capability` or `menu`. |
| `declaration_invalid: Field "x" ... reserved option` | The derived option name is protected. | Rename the field or use `array` storage. |
| `shared_storage` | Two pages write the same option. | Give each page its own option. |
| `field_skipped` | A standalone group's field name is already on the page. | Rename it. |
| Nothing saved, notice about "not a list of settings" | The array option holds a scalar from another plugin. | Pick another option name. |
| `child_slug_reserved` / `child_slug_taken` | The child's full slug is reserved or already used. | Change the child `slug`. |
| `php_declaration_ignored` | The file of the same slug is invalid, so the PHP call is not used either. | Fix the file. |
