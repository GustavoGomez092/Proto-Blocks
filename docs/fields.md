# Custom fields

Proto-Blocks adds custom fields to posts, pages, custom post types, taxonomy terms, users and settings pages. Groups are declared in files (or one PHP call), edited in a visual builder, saved as ordinary meta, and read with `proto_field()`.

Requires WordPress 6.5+ (Block Bindings) and PHP 8.1.

- [Declaring groups](#declaring-groups)
- [Field settings](#field-settings)
- [Field types and stored values](#field-types-and-stored-values)
- [Location rules](#location-rules)
- [Reading values](#reading-values)
- [Block Bindings](#block-bindings)
- [The Fields builder](#the-fields-builder)
- [Trust and security](#trust-and-security)
- [ACF import](#acf-import)
- [REST, storage and performance](#rest-storage-and-performance)
- [WP-CLI](#wp-cli)

## Declaring groups

The group shape mirrors ACF local JSON, so an ACF export drops in unchanged. Files are the only store: nothing about a group lives in the database.

### 1. A group file

```
your-theme/
  proto-fields/
    speaker/group.jsonc
```

The folder name is the slug (`^[a-z][a-z0-9_-]*$`, 32 characters at most). The file may hold one group or an ACF Tools export (an array of groups). Comments and trailing commas are allowed.

```jsonc
// proto-fields/speaker/group.jsonc
{
  "key": "group_speaker",
  "title": "Speaker",
  "position": "normal",            // normal | side | acf_after_title
  "style": "default",              // default | seamless
  "menu_order": 0,
  "location": [
    [{ "param": "post_type", "operator": "==", "value": "speaker" }]
  ],
  "fields": [
    { "key": "field_company", "name": "company", "label": "Company", "type": "text", "required": 1 },
    { "key": "field_photo", "name": "photo", "label": "Photo", "type": "image" },
    {
      "key": "field_sessions", "name": "sessions", "label": "Sessions", "type": "repeater", "max": 10,
      "sub_fields": [
        { "key": "field_s_title", "name": "title", "label": "Title", "type": "text" },
        { "key": "field_s_room", "name": "room", "label": "Room", "type": "select", "choices": { "a": "Room A", "b": "Room B" } }
      ]
    }
  ]
}
```

A group needs `key`, `title` and `fields`. A field needs `key` and `type`, and a `name` (except `tab`, `accordion` and `message`, which are layout only). Names match `^[A-Za-z][A-Za-z0-9_-]{0,63}$`, and no two fields at one level share a name. A group file without a `location` matches nothing; give it one.

### 2. Inline in a type, taxonomy or settings page file

Put `fields` (a list of groups) in a `proto-types/<slug>/type.jsonc`, `proto-taxonomies/<slug>/taxonomy.jsonc` or options page file. The location is implied (the type, taxonomy or page the file declares) and may be left out.

```jsonc
// proto-types/speaker/type.jsonc
{
  "name": "Speaker",
  "fields": [
    { "key": "group_speaker", "title": "Speaker", "fields": [
      { "key": "field_company", "name": "company", "type": "text" }
    ] }
  ]
}
```

### 3. PHP

```php
// functions.php, or on `init` before priority 10
proto_field_group('speaker', [
    'key'    => 'group_speaker',
    'title'  => 'Speaker',
    'fields' => [
        ['key' => 'field_company', 'name' => 'company', 'label' => 'Company', 'type' => 'text'],
    ],
]);
```

`proto_field_group(string $target, array $group)`. The target is a post type (`'speaker'`), `'taxonomy:<taxonomy>'`, `'options:<page>'`, or `''` when the group carries its own `location`.

### Precedence and errors

- A child theme's `proto-fields/` folder beats the parent's for the same slug.
- A broken child group file does **not** fall back to the parent's group of the same slug: the group is missing and the error is shown.
- Invalid files and groups are skipped and reported on the Fields screen and by `wp proto-blocks fields validate`. The schema is `schemas/field-group.schema.json`.
- ACF-only keys (`wrapper`, `aria-label`, `placement`, ...) are preserved when the builder saves and otherwise ignored.
- A post type that receives fields gains `custom-fields` support (core needs it to expose meta over REST). The field keys are protected meta, so the raw Custom Fields panel never edits them.

## Field settings

Common to most fields: `label`, `instructions`, `required`, `default_value`, `placeholder`, `conditional_logic`. Proto-Blocks extensions: `pattern` (PCRE a text value must match), `minlength`, `optionsSource` (dynamic choices such as `wp:posts`, `wp:terms`, `wp:users` or a theme provider), `bindings` (`false` hides the field from Block Bindings) and `show_in_rest` (see [REST](#rest-storage-and-performance)). Type-specific settings include `choices` and `multiple` (select, radio), `min`, `max` and `step` (number), `max` (repeater rows), `post_type` (relationship), `taxonomy` and `save_terms` (taxonomy), `new_lines` (textarea).

Conditional logic hides a field while its rules fail (equals, not equals, contains, empty, not empty, pattern). Hidden fields are neither validated nor written. Rules refer to a field at the same level.

Validation runs in the browser for immediate feedback and again in PHP on save, which is authoritative. A value that fails is not written; the errors appear in the box after the save (kept for five minutes per user). The post itself still saves.

## Field types and stored values

| Type | Stored as | Getter returns (escaped) |
|---|---|---|
| `text`, `textarea`, `email`, `url`, `number` | string (number: number) | string; url through `esc_url` |
| `password` | string | string; never exposed over REST or bindings by default |
| `wysiwyg` | HTML string | `wp_kses_post` output |
| `image` | attachment ID | `{id, url, alt, width, height, sizes}`, or `null` if the attachment is gone |
| `file` | attachment ID | `{id, url, filename, mime, size}` |
| `gallery` | list of attachment IDs | list of image arrays |
| `video` | attachment ID or URL string | `{id?, url}` |
| `link` | `{url, title, target}` | same, URL and attributes escaped |
| `select`, `radio` | string, or list of strings with `multiple` | same; membership checked against `choices` |
| `true_false` | boolean | boolean |
| `color` | string | sanitised colour |
| `date` | `Y-m-d` | string |
| `time` | `H:i:s` | string |
| `datetime` | `Y-m-d H:i:s` | string |
| `taxonomy` | list of term IDs (with `save_terms`, the object's terms are set too) | list of IDs |
| `relationship` | ordered list of post IDs | IDs; posts the visitor cannot read are left out |
| `group` | object of sub-field values | object |
| `repeater` | list of row objects | list of rows (use `proto_rows()`) |
| `flexible_content` | list of `{layout, ...values}`; rows with an undeclared layout are dropped | list of rows |
| `code` | string, scripts and raw HTML | exactly as stored (see [Trust](#trust-and-security)) |
| `tab`, `accordion`, `message` | nothing | layout only |

Everything is one meta row per field, named after the field `name`; group, repeater and flexible content are stored as one JSON value. Terms use term meta, users user meta, settings pages one option per field (`<page_slug>_<field_name>`, autoloaded unless the group sets `"autoload": false`).

## Location rules

`location` is an OR list of AND lists of rules, as in ACF: `[[rule, rule], [rule]]`. The rule params are `post_type`, `post_status`, `post_taxonomy` (`taxonomy:term`), `page_template`, `post` (an ID), `taxonomy`, `user_role`, `user_form` (`all`, `add`, `edit`, `register`) and `options_page`; operators are `==` and `!=`. A group with any other param is **not rendered** and raises a warning naming the rule; it never falls open onto every post. A branch that mixes object kinds (a post rule with a user rule) never matches.

`wp proto-blocks fields validate` warns (`wide_location`) about a group whose post branch is not narrowed by a `post_type`, `post`, `page_template` or `post_taxonomy` `==` rule, because it appears on every post type.

## Reading values

```php
$company = proto_field('company');                 // current post
$company = proto_field('company', 42);             // post ID
$phone   = proto_field('phone', 'option:theme-settings');
$bio     = proto_field('bio', 'user:7');
$colour  = proto_field('accent', 'term:12');

$all = proto_fields($post_id);                     // name => value
foreach (proto_rows('sessions', $post_id) as $row) {
    echo '<li>', $row['title'], ' (', $row['room'], ')</li>';
}
```

Signatures: `proto_field(string $name, $context = null, bool $raw = false)`, `proto_fields($context = null, bool $raw = false)`, `proto_rows(string $name, $context = null)`. The context is a post ID or object (default: the loop post, else the queried object), `'post:<id>'`, `'term:<id>'`, `'user:<id>'` or `'option:<page>'`. A name not declared for that object returns `null`.

In a Proto-Blocks `template.php`, `$template->field('company')` reads from the block's post (the Query Loop post, else the current one).

- Values are **escaped for their type** by default. `$raw = true` returns the stored value and leaves escaping to you.
- `code` fields are returned exactly as stored.
- The getter does **no read-permission check**. It reads a draft, a private post, any user or any options page if asked. Never build `$context` from visitor input (`$_GET`, query vars, attributes a contributor controls).
- `get_field()`, `the_field()` and `get_fields()` exist only while ACF is inactive. `the_field()` echoes `code` values raw.
- ACF `Ymd` dates are read as `Y-m-d`.

## Block Bindings

The source `proto-blocks/field` lets core paragraph, heading, image and button blocks show a field:

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"proto-blocks/field","args":{"name":"company"}}}}} -->
<p></p>
<!-- /wp:paragraph -->
```

Args: `name`, optional `context` (same forms as the getter; default the block's post) and optional `key` to pick one part of an image, file, link or video value, a single-post relationship, or one sub-field of a `group`.

- `code` and `password` fields are never bound. Set `"bindings": false` on any other field to opt out.
- Posts render for published posts, or for viewers who can read the post. A post behind a password gives nothing.
- User fields are bindable only by viewers who can `edit_user`. Terms of non-public taxonomies need `edit_term`.
- **Options-page fields are bindable by any author** unless the field has `"bindings": false`. Set it on anything secret.
- Values are the escaped output, never raw. When the value is `null`, the block keeps its saved content.
- Because a logged-in viewer can see more than a visitor, a full-page cache that serves logged-in users must vary by user (or skip them).

## The Fields builder

**Proto Blocks > Fields** (`admin.php?page=proto-blocks-fields`) lists every group with its source, location and status, and edits it visually. It requires `manage_options`.

- One row per field: label, name, type, required. Drag to reorder, or use the keyboard (focus the handle, then arrow keys). Nest sub-fields for groups, repeaters and flexible content layouts.
- Per field: a settings tab and a conditional logic tab. Choice lists, defaults and instructions are forms, not JSON.
- **Group settings** card: title, position, style, menu order, and `autoload` for settings-page groups.
- **Location** builder: OR groups of AND rows, using the nine params above. An unsupported rule already in a file shows locked, with a button to remove it. A group inside a type, taxonomy or page file shows its implied location instead.
- **JSONC** pane: the same group as text, with line and column errors and live validation. Valid edits flow back into the builder. Comments outside an edited array survive a save; comments **inside** an edited array are lost.
- **New group** asks for a title and a destination: its own `proto-fields/<slug>/group.jsonc`, or inside an existing type file.
- **Save to file** writes the JSONC file after schema validation. A file changed since you loaded it opens a conflict dialog instead of overwriting.

Groups are read-only, with the reason shown, when:

- `DISALLOW_FILE_MODS` or `DISALLOW_FILE_EDIT` is set;
- the site is multisite and you are not a network admin;
- ACF registers the group ("This group is registered by Advanced Custom Fields.");
- the group is inline in a file you cannot write, or comes from PHP.

## Trust and security

Trust is decided per user, at write time, for posts, terms, users and options alike.

- `code` and `password` fields are written only by users with `unfiltered_html`. Everyone else sees them hidden: "Hidden; only users with unfiltered HTML may view and edit this field." Saving as such a user leaves the stored value untouched.
- Other fields are sanitised for their type when the writer lacks `unfiltered_html`.
- A `code` value is trusted because only a trusted user could write it through the plugin. Values written some other way (another plugin, `update_post_meta`, the database) are not vouched for, and **re-typing a field to `code` renders the values already stored under the old type raw**. Clear them first if they came from untrusted writers.
- Repeater rows are capped for every writer: the field's `max`, never above the `proto_blocks_repeater_max_items` filter (default 500; `0` means no rows). Lowering the cap never drops stored rows: the box shows them all with "This repeater has N rows; the limit is M. Remove rows to save.", saving is refused until enough rows are removed, and at `0` the rows are read-only.

```php
add_filter('proto_blocks_repeater_max_items', fn ($max, $name, $settings) => 100, 10, 3);
```

- Group files are confined to the theme folders and checked against the schema before any write; the builder routes need `manage_options` and the REST nonce.

## ACF import

Copy an ACF Tools export into `proto-fields/<slug>/group.json` and it works unchanged.

- ACF aliases: `date_picker`, `time_picker`, `date_time_picker`, `color_picker`, `checkbox`, `button_group`, `post_object`, `page_link`, `oembed` and `user` map onto the types above.
- An **unknown field type is an error**: the field is skipped and reported.
- If ACF is active and already registers a group with the same `key` (for example from its own `acf-json` folder), Proto-Blocks skips its copy and shows a notice, so a box never renders twice. The builder shows that group read-only.
- ACF dates stored as `Ymd` read as `Y-m-d`.

## REST, storage and performance

- Every field is registered meta with a REST schema derived from its type. `show_in_rest` is on by default and can be set per field (`"show_in_rest": false`). `password` and `code` fields, and groups or repeaters that contain one, default to off. Core serves exposed meta to anonymous visitors for published posts, so turn it off for anything private.
- User fields are off by default; set `"show_in_rest": true` to expose one. Term meta of public taxonomies and post meta of password-protected posts are returned by core's REST API without the password, so set `"show_in_rest": false` on sensitive fields of those objects.
- Post meta is registered with `revisions_enabled` where the post type supports revisions.
- Boxes render in the block editor, the classic editor and Elementor's classic mode, and on term and user edit screens. Inputs carry real `name` attributes and save through the ordinary form submit, with a nonce.
- Heavy pickers (relationship, gallery, wysiwyg) load lazily, so a post with 50 fields stays interactive.

## WP-CLI

```bash
wp proto-blocks fields list [--format=table|json|csv]
wp proto-blocks fields validate [<path>...]
```

`list` shows one row per group (key, title, source, path, field count, location, status: `active`, `unrenderable` or `skipped-acf`). `validate` checks every discovered group, declaration and PHP group, or the files you pass. It exits `1` on errors; warnings (such as `wide_location`) do not fail it.

Values written from the CLI go through the same sanitisers. To seed `code` or markup values, run as an administrator who has `unfiltered_html`:

```bash
wp --user=admin post meta update 42 company "Acme"
```

Without such a user the value is sanitised, or for `code` fields refused.
