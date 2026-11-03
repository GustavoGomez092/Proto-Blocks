# Custom fields

> **At a glance**
> - **What:** ACF-class custom fields on posts, custom post types, taxonomy terms, users and settings pages, read back escaped with `proto_field()`.
> - **Where:** `your-theme/proto-fields/<slug>/group.jsonc`, or a `fields` list inside a `type.jsonc`, `taxonomy.jsonc` or `page.jsonc`.
> - **PHP:** `proto_field_group('event', ['key' => 'group_event_details', 'title' => 'Event details', 'fields' => [...]]);`
> - **Admin:** Proto Blocks > Fields (`admin.php?page=proto-blocks-fields`), a visual builder that writes the files.
> - **CLI:** `wp proto-blocks fields list|validate`.

Groups mirror ACF local JSON, so an ACF export drops in unchanged.
Each field is one meta row (or one option) named after the field.
Requires WordPress 6.5+ and PHP 8.1+.

Related: [post types](post-types.md), [taxonomies](taxonomies.md), [settings pages](settings-pages.md), [content model files](content-model-files.md), [trust and security](trust-and-security.md), [REST API](rest-api.md#fields), [CLI](cli.md#wp-proto-blocks-fields).

## Contents

- [Quick start](#quick-start)
- [Declaring groups](#declaring-groups)
- [Group settings](#group-settings)
- [Location rules](#location-rules)
- [Field settings](#field-settings)
- [Field types](#field-types)
- [Conditional logic](#conditional-logic)
- [Groups, repeaters and flexible content](#groups-repeaters-and-flexible-content)
- [Reading values](#reading-values)
- [ACF-named aliases](#acf-named-aliases)
- [Block Bindings](#block-bindings)
- [The editing UI](#the-editing-ui)
- [The Fields screen and builder](#the-fields-screen-and-builder)
- [Saving, validation and errors](#saving-validation-and-errors)
- [Storage](#storage)
- [REST](#rest)
- [CLI](#cli)
- [Trust rules](#trust-rules)
- [Performance](#performance)
- [Filters](#filters)
- [Migrating from ACF](#migrating-from-acf)

## Quick start

Declare the `event` type and give it an "Event details" group.

```php
// functions.php
proto_post_type('Event');
```

```jsonc
// proto-fields/event-details/group.jsonc
{
  "key": "group_event_details",
  "title": "Event details",
  "position": "acf_after_title",
  "location": [[{ "param": "post_type", "operator": "==", "value": "event" }]],
  "fields": [
    { "key": "field_event_subtitle", "name": "subtitle", "label": "Subtitle", "type": "text", "maxlength": 120 },
    { "key": "field_event_start", "name": "start_date", "label": "Start date", "type": "date", "required": true },
    { "key": "field_event_online", "name": "is_online", "label": "Online event", "type": "true_false" },
    {
      "key": "field_event_stream", "name": "stream_url", "label": "Stream URL", "type": "url",
      "conditional_logic": [[{ "field": "field_event_online", "operator": "==", "value": "1" }]]
    },
    { "key": "field_event_hero", "name": "hero", "label": "Hero image", "type": "image" },
    {
      "key": "field_event_agenda", "name": "agenda", "label": "Agenda", "type": "repeater", "max": 20,
      "sub_fields": [
        { "key": "field_event_agenda_time", "name": "time", "label": "Time", "type": "time" },
        { "key": "field_event_agenda_title", "name": "title", "label": "Title", "type": "text" }
      ]
    }
  ]
}
```

Read the values in a template.
They come back escaped for their type, so nothing is escaped again.

```php
<?php // single-event template part, or a Proto-Blocks template.php ?>
<p class="event-subtitle"><?php echo proto_field('subtitle'); ?></p>
<p class="event-date"><?php echo proto_field('start_date'); ?></p>

<?php if (proto_field('is_online')) : ?>
    <a href="<?php echo proto_field('stream_url'); ?>">Watch online</a>
<?php endif; ?>

<?php $hero = proto_field('hero'); ?>
<?php if ($hero) : ?>
    <img src="<?php echo $hero['url']; ?>" alt="<?php echo $hero['alt']; ?>"
         width="<?php echo $hero['width']; ?>" height="<?php echo $hero['height']; ?>">
<?php endif; ?>

<ul>
    <?php foreach (proto_rows('agenda') as $row) : ?>
        <li><?php echo $row['time']; ?> — <?php echo $row['title']; ?></li>
    <?php endforeach; ?>
</ul>
```

The editor shows an "Event details" box under the title of every event, in the block editor, the classic editor and Elementor.

## Declaring groups

Four ways, all producing the same kind of group.

### 1. A group file

```
your-theme/
  proto-fields/
    event-details/
      group.jsonc        (or group.json)
```

- The folder name is the slug: `^[a-z][a-z0-9_-]{0,31}$`.
- The file holds one group (object root) or an ACF Tools export (a list of groups).
- A group file needs a `location`; without one it matches nothing (`missing_location`).
- Comments and trailing commas are allowed. See [content model files](content-model-files.md#jsonc-conventions).

A new group file from the builder starts as:

```jsonc
{
  "key": "group_event_details",
  "title": "Event details",
  "fields": [],
  // Where the group shows, e.g. [[{ "param": "post_type", "operator": "==", "value": "post" }]]
  "location": []
}
```

### 2. Inline in a type, taxonomy or settings page file

Put a `fields` list in the declaration.
The location is implied by the file and may be left out.

```jsonc
// proto-types/event/type.jsonc
{
  "name": "Event",
  "fields": [
    {
      "key": "group_event_details",
      "title": "Event details",
      "fields": [
        { "key": "field_event_subtitle", "name": "subtitle", "label": "Subtitle", "type": "text" }
      ]
    }
  ]
}
```

| File | `fields` holds | Implied location |
|---|---|---|
| `proto-types/<slug>/type.jsonc` | a list of groups (each with `key`, `title`, `fields`) | `post_type == <slug>` |
| `proto-taxonomies/<slug>/taxonomy.jsonc` | a list of groups | `taxonomy == <slug>` |
| `proto-options/<slug>/page.jsonc` | a list of fields **or** groups; `key` and group `title` are generated | `options_page == <slug>` |

A `location` written inside an inline group is ignored with a `location_ignored` warning.
Settings page fields are covered in [settings pages](settings-pages.md#fields-on-a-page).

### 3. PHP

```php
// functions.php
proto_field_group('event', [
    'key'      => 'group_event_details',
    'title'    => 'Event details',
    'position' => 'acf_after_title',
    'fields'   => [
        ['key' => 'field_event_subtitle', 'name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text'],
        ['key' => 'field_event_start', 'name' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'required' => true],
    ],
]);
```

Signature:

```php
proto_field_group(string $target, array $group): void
```

| `$target` | Implied location |
|---|---|
| `'event'` (a post type slug, `^[a-z][a-z0-9_-]{0,19}$`) | `post_type == event` |
| `'taxonomy:topic'` | `taxonomy == topic` |
| `'options:theme-settings'` | `options_page == theme-settings` |
| `''` | the group's own `location` |

- Any other target is an `invalid_target` error and the group is skipped.
- `$group` may also be a list of groups (an ACF Tools export decoded to an array).
- Call it from `functions.php`, or on `init` before priority 10.
  The group index is built once per request after that; later groups are not seen.
- PHP groups are read-only in the builder.

### 4. An ACF export

Copy an ACF local JSON file (`acf-json/group_*.json`) or a Tools export into `proto-fields/<slug>/group.json`.

```
your-theme/proto-fields/acf-export/group.json   ← [ { "key": "group_6543a1b2c3d4e", "title": "Event details", ... } ]
```

- ACF-only keys (`wrapper`, `aria-label`, `placement`, `prepend`, `ui_on_text`, ...) are kept and ignored.
- ACF type names are mapped (see [ACF type aliases](#acf-type-aliases)).
- An unknown field type is an error: the field is skipped and reported (`unknown_type`).
- An unsupported location param makes the group unrenderable (`unsupported_location_rule`).

### Precedence and errors

- One group per `key` wins: a `proto-fields/` file beats a group inside a type or taxonomy file, which beats a PHP group. A `duplicate_group_key` warning names both.
- A child theme's `proto-fields/<slug>/` beats the parent's.
- A broken child file does **not** fall back to the parent's file of the same slug (`broken_override` error). A `lower_rank_in_effect` warning says a PHP or inline group with the same key may be in effect instead.
- A group with a structural error is dropped entirely (fail closed). Other groups still load.
- When ACF is active and already registers a group with the same `key`, Proto-Blocks skips its copy (`acf_duplicate_key` warning and an admin notice), so a box never renders twice.

Structural errors: `missing_key`, `missing_title`, `missing_fields`, `invalid_field`, `missing_type`, `invalid_name`, `duplicate_name`, `duplicate_key`, `layouts_not_allowed`, `layouts_required`, `sub_fields_required`, `invalid_condition`, `invalid_location`, `invalid_position`, `invalid_style`, `expected_group`, `invalid_target`.
Warnings: `unsupported_location_rule`, `empty_location_branch`, `missing_location`, `location_ignored`, `mixed_location_kinds`, `unknown_type` (reported as an error by `validate` and the writer), `duplicate_meta_key`, `protected_meta_key`, `protected_option`, `wide_location` (CLI only).

## Group settings

| Key | Type | Default | Notes |
|---|---|---|---|
| `key` | non-empty string | required | Unique across all groups. Conventionally `group_<something>`. |
| `title` | string | required | The meta box title. |
| `fields` | list of fields | required | See [field settings](#field-settings). |
| `location` | list of lists of rules | `[]` | Required in a group file and for a `''` PHP target. See [location rules](#location-rules). |
| `position` | `"normal"`, `"side"`, `"acf_after_title"` | `"normal"` | `acf_after_title` is right under the title in the classic editor, and the top of the main column in the block editor. |
| `style` | `"default"`, `"seamless"` | `"default"` | Validated and kept. The 2.15.0 boxes render both the same. |
| `menu_order` | integer | `0` | Lower shows first. Also decides which group owns a field name shared by two groups. |
| `autoload` | boolean | `true` | Settings-page groups only. Read by the Fields module's own option store. A page declared in `proto-options/` or with `proto_options_page()` saves with the **page's** `autoload` instead, so set it there. |

`show_in_rest` and `bindings` are **field** settings, not group settings.

## Location rules

`location` is an OR list of AND lists of rules, as in ACF:

```jsonc
"location": [
  [ { "param": "post_type", "operator": "==", "value": "event" },
    { "param": "post_status", "operator": "!=", "value": "draft" } ],   // event AND not draft
  [ { "param": "post_type", "operator": "==", "value": "page" },
    { "param": "page_template", "operator": "==", "value": "page-event-landing" } ]   // OR a landing page
]
```

| `param` | `value` | Screen | Matches when |
|---|---|---|---|
| `post_type` | post type slug | post | the edited post has that type |
| `post_status` | `publish`, `draft`, `pending`, `private`, `future`, ... | post | the post has that status |
| `post_taxonomy` | `<taxonomy>:<term-slug>`, e.g. `topic:workshops` | post | the post has that term |
| `page_template` | template slug, or `default` | post | the post uses that template (`''` counts as `default`) |
| `post` | a post ID (digits) | post | it is that post |
| `taxonomy` | taxonomy slug, or `all` | term | the term form is for that taxonomy |
| `user_role` | role slug, or `all` | user | the user has that role |
| `user_form` | `add`, `edit`, `register`, or `all` | user | the add-user, profile/edit, or public registration form |
| `options_page` | settings page slug | option | it is that settings page |

Operators are `==` and `!=`.
Values may be strings, numbers or booleans; they are compared as strings.

Fail-closed rules:

- A group with **no** rules, or with an empty AND list, is not shown anywhere.
- A rule with any other `param` makes the **whole group** unrenderable, with a warning naming the param. It never falls open onto every post.
- An AND list that mixes screens (a post rule and a user rule) never matches (`mixed_location_kinds`).
- A rule that cannot be decided (a malformed `post` ID, a `post_taxonomy` without `:`) fails, whether `==` or `!=`.
- `!=` does not cross screens: `post_type != page` never matches a user screen.
- The public registration form shows only groups whose rule names `user_form == register` or `user_form == all` explicitly.

`wp proto-blocks fields validate` warns `wide_location` about a group whose post branch is not narrowed by a `post_type`, `post`, `page_template` or `post_taxonomy` `==` rule, because it shows on every post type.

## Field settings

Every field is an object.
Keys the plugin does not model are kept in the field's settings untouched, so ACF options survive a round trip.

### Common keys

| Key | Type | Notes |
|---|---|---|
| `key` | non-empty string, required | Unique within the group, at any depth. Conditions refer to it. Conventionally `field_<something>`. |
| `type` | string, required | A [field type](#field-types) or an [ACF alias](#acf-type-aliases). |
| `name` | `^[A-Za-z][A-Za-z0-9_-]{0,63}$` | Required except for `tab`, `accordion` and `message`. The meta key. Unique among siblings. |
| `label` | string | Shown to editors. Defaults to the name in the box. |
| `instructions` | string | Help text under the label. |
| `required` | boolean or `0`/`1` | Empty values fail validation. |
| `default_value` | any | Returned by the getter while nothing is stored; also the registered meta default. |
| `placeholder` | string | |
| `conditional_logic` | `0`, or list of lists of rules | See [conditional logic](#conditional-logic). |
| `wrapper.width` | number 1–99 (percent) | Share of the box row; fields sit on a 12-column grid. |
| `wrapper.class` | string | Kept. |
| `show_in_rest` | boolean | Whether core's REST API exposes the value. See [REST](#rest). |
| `bindings` | boolean | `false` hides the field from the [Block Bindings](#block-bindings) source. |

Settings page fields are stricter: `name` must match `^[a-z][a-z0-9_]*$`.

### Validation keys

| Key | Applies to | Rule |
|---|---|---|
| `minlength` | any string value | at least N characters (`mb_strlen`) |
| `maxlength` | any string value | at most N characters |
| `pattern` | any string value | PCRE the value must match. Written without delimiters; `/` is escaped for you; compiled with `/Du`. At most 200 bytes. A pattern that does not compile fails every value ("Invalid pattern rule."). Values over 10,000 bytes do not match. |
| `min`, `max` | `number` | numeric bounds |
| `min`, `max` | `repeater`, `flexible_content`, `gallery`, `relationship` | row or item count |
| `choices` | `select`, `radio` | the value must be a key |

Validation runs in the browser for immediate feedback and again in PHP on save, which is authoritative.
Both sides share one corpus of cases (`tests/fixtures/fields/validation-cases.json`).

### Settings kept but not applied

These ACF settings are accepted, kept in the file and shown in the builder, but neither the server nor the field box applies them in 2.15.0:
`return_format`, `mime_types`, `display_format`, `ui`, `filters`, `language`.
The getter always returns the shapes listed below.

## Field types

The 28 types, grouped as the builder groups them.

| Category | Types |
|---|---|
| Basic | `text`, `textarea`, `number`, `email`, `url`, `password` |
| Content | `wysiwyg`, `image`, `file`, `gallery`, `video`, `code` |
| Choice | `select`, `radio`, `true_false`, `color`, `date`, `time`, `datetime` |
| Relational | `link`, `relationship`, `taxonomy` |
| Layout | `group`, `repeater`, `flexible_content`, `tab`, `accordion`, `message` |

Each section lists the stored shape, what `proto_field()` returns escaped (the default) and raw (`$raw = true`), and the REST schema.
"Untrusted writer" means a user without `unfiltered_html`; see [trust rules](#trust-rules).

### `text`

Single line of plain text.

| | |
|---|---|
| Settings | `placeholder`, `minlength`, `maxlength`, `pattern`, `prepend`, `append` (shown beside the input) |
| Stored | string |
| Untrusted writer | `sanitize_text_field()` |
| Escaped | `esc_html()` string |
| Raw | the stored string |
| REST | `{"type": "string"}` |

```jsonc
{ "key": "field_event_subtitle", "name": "subtitle", "label": "Subtitle", "type": "text", "maxlength": 120 }
```

```php
<h2><?php echo proto_field('subtitle'); ?></h2>
```

### `textarea`

Several lines of plain text.

| | |
|---|---|
| Settings | `rows` (box height, default 4), `new_lines` (`"br"` converts newlines on output), `minlength`, `maxlength`, `pattern` |
| Stored | string |
| Untrusted writer | `sanitize_textarea_field()` |
| Escaped | `esc_html()`; with `"new_lines": "br"`, `nl2br(esc_html())` |
| Raw | the stored string |
| REST | `{"type": "string"}` |

```jsonc
{ "key": "field_event_summary", "name": "summary", "label": "Summary", "type": "textarea", "rows": 4, "new_lines": "br" }
```

```php
<p><?php echo proto_field('summary'); ?></p>
```

### `number`

| | |
|---|---|
| Settings | `min`, `max`, `step` |
| Stored | integer when `step` is integral, float when it is fractional or `"any"`, the number's own type when `step` is unset; `null` when empty |
| Validation | numeric, within `min`/`max` |
| Escaped | the number (int or float) or `null` |
| Raw | the stored number |
| REST | `{"type": ["integer", "null"]}` with an integral `step`, else `{"type": ["number", "null"]}` |

```jsonc
{ "key": "field_event_seats", "name": "seats", "label": "Seats", "type": "number", "min": 1, "max": 500, "step": 1 }
```

```php
<?php $seats = proto_field('seats'); ?>
<?php if ($seats !== null) : ?><span><?php echo (int) $seats; ?> seats</span><?php endif; ?>
```

### `email`

| | |
|---|---|
| Stored | string |
| Validation | `is_email()` |
| Untrusted writer | `sanitize_email()` |
| Escaped | `esc_html()` string |
| REST | string with `format: email`, or the empty string |

```php
<?php $email = proto_field('contact_email'); ?>
<a href="mailto:<?php echo $email; ?>"><?php echo $email; ?></a>
```

### `url`

| | |
|---|---|
| Stored | string |
| Validation | the URL must survive the plugin's URL sanitiser unchanged (allowed schemes only, no `javascript:`) |
| Untrusted writer | the plugin's URL sanitiser |
| Escaped | `esc_url()` string |
| REST | string with `format: uri`, or the empty string |

```jsonc
{ "key": "field_event_stream", "name": "stream_url", "label": "Stream URL", "type": "url", "placeholder": "https://" }
```

```php
<a href="<?php echo proto_field('stream_url'); ?>">Watch</a>
```

### `password`

A masked input.
Treated as a secret.

| | |
|---|---|
| Stored | string |
| Who may write | only users with `unfiltered_html` |
| Who sees it in the editor | only users with `unfiltered_html`; others see "Hidden; only users with unfiltered HTML may view and edit this field." |
| Escaped | `esc_html()` string |
| REST | off by default |
| Bindings | never |

The getter returns it to any PHP that asks; it has no read-permission check.

### `wysiwyg`

Rich text from the classic editor (TinyMCE), loaded lazily.

| | |
|---|---|
| Stored | HTML string |
| Untrusted writer | `wp_kses_post()` |
| Escaped | `wp_kses_post()` output, for every writer (a trusted writer's script is still stripped on output) |
| Raw | the stored HTML |
| REST | `{"type": "string"}` |

```php
<div class="event-notes"><?php echo proto_field('notes'); ?></div>
```

### `image`

| | |
|---|---|
| Settings | `return_format`, `mime_types` (kept, not applied) |
| Stored | integer attachment ID |
| Accepted input | an ID, a digit string, an integral float, or `{"id": …}` |
| Escaped | `{id, url, alt, width, height, sizes}`, or `null` when the attachment is gone |
| Raw | the integer ID (or `0`) |
| REST | `{"type": "integer"}` |

`sizes` is `{ "<size name>": {url, width, height}, ... }`.
`url` is `esc_url()`, `alt` is `esc_attr()`.

```php
<?php $hero = proto_field('hero'); ?>
<?php if ($hero) : ?>
    <img src="<?php echo $hero['sizes']['large']['url'] ?? $hero['url']; ?>" alt="<?php echo $hero['alt']; ?>">
<?php endif; ?>
```

Need markup from core instead? Read the ID raw: `wp_get_attachment_image(proto_field('hero', null, true), 'large')`.

### `file`

| | |
|---|---|
| Stored | integer attachment ID |
| Escaped | `{id, url, filename, mime, size}` (size in bytes), or `null` |
| Raw | the integer ID |
| REST | `{"type": "integer"}` |

```php
<?php $pdf = proto_field('brochure'); ?>
<?php if ($pdf) : ?><a href="<?php echo $pdf['url']; ?>" download><?php echo $pdf['filename']; ?></a><?php endif; ?>
```

### `gallery`

| | |
|---|---|
| Settings | `min`, `max` (items; capped at 500) |
| Stored | list of unique positive attachment IDs |
| Escaped | list of image arrays (as `image`), missing attachments left out |
| Raw | the list of IDs |
| REST | `{"type": "array", "items": {"type": "integer"}, "maxItems": max}` |

```php
<?php foreach (proto_field('photos') as $photo) : ?>
    <img src="<?php echo $photo['url']; ?>" alt="<?php echo $photo['alt']; ?>">
<?php endforeach; ?>
```

### `video`

| | |
|---|---|
| Stored | an attachment ID (integer) or an external URL (string) |
| Escaped | `{id, url}` for a video attachment, `{url}` for a URL, or `null` (an ID that is not a `video/*` attachment is never exposed) |
| Raw | the ID or URL |
| REST | `{"type": ["integer", "string"]}` |

```php
<?php $video = proto_field('teaser'); ?>
<?php if ($video) : ?><video src="<?php echo $video['url']; ?>" controls></video><?php endif; ?>
```

### `code`

Scripts and raw HTML, in a syntax-highlighted editor.

| | |
|---|---|
| Settings | `language` (`html`, `css`, `javascript`, `json`, `php`; kept, not applied) |
| Stored | string |
| Who may write | only users with `unfiltered_html`. An untrusted save leaves the stored value untouched. |
| Who sees it in the editor | users with `unfiltered_html`; others get a read-only input without the value |
| Escaped | **exactly as stored** |
| REST | off by default |
| Bindings | never |

```php
<?php // Header scripts the administrator pasted into a settings page. ?>
<?php echo proto_field('head_scripts', 'option:theme-settings'); ?>
```

Read [trust rules](#trust-rules) before using `code`.

### `select`

| | |
|---|---|
| Settings | `choices`, `multiple`, `optionsSource`, `sourceArgs`, `placeholder` |
| Stored | string, or a list of strings with `"multiple": true` |
| Validation | every value must be a choice key (or a key the `optionsSource` returns) |
| Escaped | `esc_html()` string, or a list of them |
| Raw | the stored string or list |
| REST | a string with an `enum` of the keys (plus `''` when single and optional), or an array of them |

`choices` is a map of value to label, or a list of values:

```jsonc
{ "key": "field_event_audience", "name": "audience", "label": "Audience", "type": "select", "multiple": true,
  "choices": { "students": "Students", "professionals": "Professionals", "everyone": "Everyone" } }

{ "key": "field_event_level", "name": "level", "type": "select", "choices": ["beginner", "advanced"] }
```

Dynamic choices come from the block controls' options providers:

```jsonc
{ "key": "field_event_venue", "name": "venue_page", "label": "Venue page", "type": "select",
  "optionsSource": "wp:posts", "sourceArgs": { "post_type": "page", "per_page": 50 } }
```

| `optionsSource` | `sourceArgs` | Keys |
|---|---|---|
| `wp:posts` | `post_type`, `per_page`, `search` | post IDs |
| `wp:terms` | `taxonomy`, `per_page`, `search` | term IDs |
| `wp:users` | `per_page`, `search` | user IDs |
| a theme provider | its own | see [dynamic control options](dynamic-control-options.md) |

`sourceArgs` come from the declaration only, never from a request.
An unknown or failing source rejects every value (fail closed).

```php
<?php foreach (proto_field('audience') as $audience) : ?><span class="tag"><?php echo $audience; ?></span><?php endforeach; ?>
```

The getter returns the stored key, not the label.

### `radio`

Same as a single `select`: `choices`, `optionsSource`, `sourceArgs`; stores one string.

```jsonc
{ "key": "field_event_format", "name": "format", "type": "radio", "default_value": "talk",
  "choices": { "talk": "Talk", "workshop": "Workshop" } }
```

### `true_false`

| | |
|---|---|
| Settings | `message` (text beside the checkbox), `ui` (kept) |
| Stored | boolean (post, term and user meta) |
| Accepted input | booleans, numbers, `"1"`, `"true"`, `"on"`, `"yes"` (true); anything else is false |
| Escaped | boolean |
| REST | `{"type": "boolean"}` |

```php
<?php if (proto_field('is_online')) : ?><span class="badge">Online</span><?php endif; ?>
```

On a settings page with `array` storage, an existing `'1'`/`''`, `'yes'`/`'no'`, `'on'`/`'off'` or `1`/`0` format is kept.
See [settings pages](settings-pages.md#storage-and-option-names).

### `color`

| | |
|---|---|
| Stored | string |
| Untrusted writer | `proto_blocks_sanitize_color()`: a 3- or 6-digit hex colour, else `''` |
| Escaped | re-validated on every read: `#abc`/`#aabbcc`, else `''` |
| REST | `{"type": "string"}` |

```php
<?php $accent = proto_field('accent'); ?>
<section<?php if ($accent) : ?> style="--accent: <?php echo $accent; ?>"<?php endif; ?>>
```

### `date`

| | |
|---|---|
| Stored | `Y-m-d` string |
| Validation | a real calendar date (`2026-02-30` is refused) |
| Untrusted writer | strict round trip; anything else becomes `''` |
| Escaped | `esc_html()` string; an ACF `Ymd` value (`20261102`) reads as `2026-11-02` |
| REST | `{"type": "string"}` (no `format`) |

```php
<?php $start = proto_field('start_date'); ?>
<time datetime="<?php echo $start; ?>"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($start))); ?></time>
```

### `time`

Stored as `H:i:s`.
The validator accepts `H:i` and `H:i:s`; an untrusted writer's value must be exactly `H:i:s`.
Escaped with `esc_html()`.

### `datetime`

Stored as `Y-m-d H:i:s`.
The validator accepts `Y-m-d H:i`, `Y-m-d H:i:s` and the `T`-separated forms; an untrusted writer's value must be exactly `Y-m-d H:i:s`.
Escaped with `esc_html()`.

### `link`

| | |
|---|---|
| Stored | `{url, title, target}`; a bare string is read as the URL |
| Untrusted writer | URL sanitiser, `sanitize_text_field()` on the title, `target` kept only as `_blank` |
| Escaped | `{url, title, target}` with `esc_url()`, `esc_html()`, `esc_attr()`, plus `rel: "noopener noreferrer"` when `target` is `_blank` |
| REST | object `{url, title, target}`, no other keys |

```php
<?php $link = proto_field('register_link'); ?>
<?php if ($link['url']) : ?>
    <a href="<?php echo $link['url']; ?>"<?php if ($link['target']) : ?> target="<?php echo $link['target']; ?>" rel="<?php echo $link['rel']; ?>"<?php endif; ?>>
        <?php echo $link['title'] ?: 'Register'; ?>
    </a>
<?php endif; ?>
```

### `relationship`

Posts picked from a searchable list, in order.

| | |
|---|---|
| Settings | `post_type` (list of slugs), `taxonomy` (list of `taxonomy:term-slug` filters), `min`, `max` |
| Stored | ordered list of unique post IDs |
| Escaped | list of `{id, title, url, type}`; with `"max": 1`, one `{id, title, url, type}` or `null` |
| Filtering | posts of other types than `post_type`, and posts the viewer cannot read (not publicly viewable and no `read_post`), are left out |
| Raw | the list of IDs |
| REST | `{"type": "array", "items": {"type": "integer"}, "maxItems": max}` |

```jsonc
{ "key": "field_event_speakers", "name": "speakers", "label": "Speakers", "type": "relationship",
  "post_type": ["speaker"], "max": 8 }
```

```php
<?php foreach (proto_field('speakers') as $speaker) : ?>
    <a href="<?php echo $speaker['url']; ?>"><?php echo $speaker['title']; ?></a>
<?php endforeach; ?>
```

The editor's picker searches 20 posts per page and shows only posts the editor may read.

### `taxonomy`

Terms of one taxonomy.

| | |
|---|---|
| Settings | `taxonomy` (one slug, required for output), `field_type` (`checkbox`, `multi_select`, `radio`, `select`), `save_terms`, `max` |
| Stored | list of term IDs |
| `save_terms` | on a post, also sets the post's terms (when the taxonomy is registered for the type and the user may assign terms) |
| Escaped | list of `{id, name, slug, url}`; IDs that are not terms of `taxonomy` are left out |
| Raw | the list of IDs |
| REST | `{"type": "array", "items": {"type": "integer"}, "maxItems": max}` |

```jsonc
{ "key": "field_event_topics", "name": "topics", "label": "Topics", "type": "taxonomy",
  "taxonomy": "topic", "field_type": "checkbox", "save_terms": true }
```

```php
<?php foreach (proto_field('topics') as $term) : ?>
    <a href="<?php echo $term['url']; ?>"><?php echo $term['name']; ?></a>
<?php endforeach; ?>
```

### `group`

One object of sub-fields, stored together.

| | |
|---|---|
| Settings | `sub_fields` (required, at least one), `layout` (kept) |
| Stored | object `{ "<sub name>": value, ... }` in one meta row |
| Escaped | the same object with every sub-value escaped by its own type |
| REST | an object schema built from the sub-fields, `additionalProperties: false` |

```jsonc
{ "key": "field_event_venue", "name": "venue", "label": "Venue", "type": "group", "sub_fields": [
  { "key": "field_event_venue_name", "name": "name", "label": "Name", "type": "text" },
  { "key": "field_event_venue_map", "name": "map", "label": "Map link", "type": "url" }
] }
```

```php
<?php $venue = proto_field('venue'); ?>
<a href="<?php echo $venue['map']; ?>"><?php echo $venue['name']; ?></a>
```

### `repeater`

Rows of the same sub-fields.

| | |
|---|---|
| Settings | `sub_fields` (required), `min`, `max`, `button_label`, `collapsed` (sub-field key used as the row title), `layout` (kept) |
| Stored | list of row objects in one meta row |
| Escaped | list of rows, each escaped per sub-field; use `proto_rows()` |
| REST | `{"type": "array", "items": <object>, "maxItems": cap}` |

See [groups, repeaters and flexible content](#groups-repeaters-and-flexible-content) for row shapes and caps.

```php
<?php foreach (proto_rows('agenda') as $i => $row) : ?>
    <li><strong><?php echo $row['time']; ?></strong> <?php echo $row['title']; ?></li>
<?php endforeach; ?>
```

### `flexible_content`

Rows built from named layouts.

| | |
|---|---|
| Settings | `layouts` (required: a list, or an ACF-style object keyed by layout key), `min`, `max`, `button_label` |
| Layout keys | `name` (required, field-name rule), `label`, `key`, `sub_fields`, `min`, `max` |
| Stored | list of `{ "layout": "<name>", ...values }` in one meta row |
| Escaped | the same rows, escaped per sub-field; rows with an undeclared layout are dropped |
| REST | array of `oneOf` objects, each requiring its `layout` constant |

```jsonc
{ "key": "field_event_sections", "name": "sections", "label": "Sections", "type": "flexible_content", "layouts": [
  { "key": "layout_event_text", "name": "text_block", "label": "Text", "sub_fields": [
    { "key": "field_event_sections_body", "name": "body", "label": "Body", "type": "wysiwyg" } ] },
  { "key": "layout_event_quote", "name": "quote", "label": "Quote", "sub_fields": [
    { "key": "field_event_sections_quote", "name": "quote", "label": "Quote", "type": "textarea" },
    { "key": "field_event_sections_cite", "name": "cite", "label": "Cite", "type": "text" } ] }
] }
```

```php
<?php foreach (proto_rows('sections') as $row) : ?>
    <?php if ($row['layout'] === 'text_block') : ?>
        <div class="prose"><?php echo $row['body']; ?></div>
    <?php elseif ($row['layout'] === 'quote') : ?>
        <blockquote><?php echo $row['quote']; ?><cite><?php echo $row['cite']; ?></cite></blockquote>
    <?php endif; ?>
<?php endforeach; ?>
```

### `tab`, `accordion`, `message`

Layout only.
They store nothing, need no `name`, and the getter never returns them.

| Type | Effect in the box | Settings |
|---|---|---|
| `tab` | The fields after it, up to the next tab, form one tab. | `label` (the tab title) |
| `accordion` | The fields after it fold into a collapsible section. | `label`, `open`, `endpoint` (closes the open accordion) |
| `message` | Static text. | `message` |

Inactive tabs and closed sections stay mounted, so their inputs still submit.

```jsonc
{ "key": "field_event_tab_media", "label": "Media", "name": "", "type": "tab" }
```

### ACF type aliases

From `schemas/field-type-aliases.json`:

| ACF type | Proto-Blocks type | Extra |
|---|---|---|
| `date_picker` | `date` | |
| `time_picker` | `time` | |
| `date_time_picker` | `datetime` | |
| `color_picker` | `color` | |
| `checkbox` | `select` | `multiple` is set to true |
| `button_group` | `radio` | |
| `post_object` | `relationship` | `max` is set to 1 unless `multiple` |
| `page_link` | `relationship` | `max` is set to 1 unless `multiple` |
| `oembed` | `video` | |
| `user` | `select` | `optionsSource` is set to `wp:users` |

The builder shows the alias's label and keeps the declared type name in the file.

## Conditional logic

A field with `conditional_logic` shows only while its rules pass.

```jsonc
{
  "key": "field_event_stream", "name": "stream_url", "type": "url",
  "conditional_logic": [
    [ { "field": "field_event_online", "operator": "==", "value": "1" } ],
    [ { "field": "field_event_format", "operator": "==", "value": "workshop" },
      { "field": "field_event_seats", "operator": "!=empty" } ]
  ]
}
```

- The outer list is OR; each inner list is AND.
- A rule's `field` is the **key** of another field.
- `0`, `false`, `""`, `"0"`, `null` and `[]` mean "no conditions" (ACF writes `0`).

| Operator | True when |
|---|---|
| `==` | the value equals `value` as a string; for a list, the list contains it |
| `!=` | not `==` |
| `==contains` | the string contains `value`, or the list holds it; never true for an empty `value` |
| `==empty` | the value is empty |
| `!=empty` | the value is not empty |
| `==pattern` | the string matches the PCRE `value` (same rules as the `pattern` setting) |

Booleans compare as `"1"` and `"0"`, so a checked `true_false` equals `"1"`.

**Empty** means `null`, `''`, `[]`, `false`, or a link whose `url` is empty.
For ID types (`image`, `file`, `video`, `gallery`, `relationship`, `taxonomy`, and the `post_object`, `page_link`, `user` aliases) a stored `0` or `'0'` is empty too.
A `number` field holding `0` is **not** empty.

**Same-level rule.** Conditions read only sibling fields: the fields of the same group level, the same repeater row or the same layout.
A rule that names a field elsewhere is never true, so the field stays hidden.
The builder offers only siblings.

Hidden fields are neither validated nor written on save, even when a value was posted.
The server recomputes visibility from the stored values overlaid with the submitted ones; it never trusts a client flag.

## Groups, repeaters and flexible content

### Row shapes

```json
{
  "venue":    { "name": "Hall A", "map": "https://maps.example/hall-a" },
  "agenda":   [ { "time": "09:00:00", "title": "Doors" }, { "time": "09:30:00", "title": "Keynote" } ],
  "sections": [ { "layout": "text_block", "body": "<p>Hello</p>" },
                { "layout": "quote", "quote": "Ship it.", "cite": "Ada" } ]
}
```

- A `group` value is an object keyed by sub-field name.
- A `repeater` value is a list of such objects.
- A `flexible_content` row carries its layout in `layout`.
- The field box posts the layout as `_layout`; the save path renames it to `layout` before storing. Code that writes meta directly (REST, `update_post_meta`) uses `layout`.
- Keys that are not sub-field names are dropped.
- Nested containers are processed by their own type, at any depth.

### Row cap

Every repeater and flexible content field has a row cap:

- its own `max`, when positive,
- never above the `proto_blocks_repeater_max_items` filter (default **500**; `0` means no rows).

The filter value is a budget shared across **all nesting levels** of one top-level field, so a repeater inside a repeater cannot multiply it.

```php
add_filter('proto_blocks_repeater_max_items', static function (int $max, string $name, array $settings): int {
    return $name === 'agenda' ? 50 : $max;
}, 10, 3);
```

The cap never truncates stored rows:

- Submitting more rows than the cap is an error ("Add no more than 50 rows."), never a silent truncation.
- After you lower the cap, the box still shows every stored row with "This repeater has N rows; the limit is M. Remove rows to save."
- Saving that field is refused until enough rows are removed.
- At a cap of `0` the stored rows are read-only.
- The getter and REST output return at most the cap.

## Reading values

### `proto_field()`

```php
proto_field(string $name, $context = null, bool $raw = false): mixed
```

One field value, escaped for its type.
`null` for a name not declared for that object (with a `_doing_it_wrong` notice under `WP_DEBUG`).
An unset field returns its `default_value`, shaped by its type, else the type's empty value.

### `proto_fields()`

```php
proto_fields($context = null, bool $raw = false): array
```

Every value field of every group shown on that object, as `name => value`.
The first declaration of a name wins.

### `proto_rows()`

```php
proto_rows(string $name, $context = null): iterable
```

The escaped rows of a `repeater` or `flexible_content` field, as a list.
An empty list for any other type, an undeclared name or no rows.
There is no raw variant; use `proto_field($name, $context, true)`.

### Contexts

| `$context` | Object |
|---|---|
| `null` | the current loop post when in the loop; otherwise the queried object (a singular post, a term archive, an author archive) |
| `42` or `'42'` | post 42 |
| a `WP_Post`, `WP_Term` or `WP_User` | that object |
| `'post:42'` | post 42 |
| `'term:12'` | term 12 (its taxonomy is looked up) |
| `'user:7'` | user 7 |
| `'option:theme-settings'` | the settings page `theme-settings` |

Anything else (`0`, negative IDs, a missing term, trailing junk or a newline) resolves to nothing, and the getter returns `null`.

```php
$subtitle = proto_field('subtitle');                         // current event
$subtitle = proto_field('subtitle', 42);                     // event 42
$note     = proto_field('term_note', 'term:12');
$bio      = proto_field('user_note', 'user:7');
$phone    = proto_field('phone', 'option:theme-settings');
$all      = proto_fields('post:42');
$raw      = proto_field('hero', 42, true);                   // the attachment ID
```

### Escaping

| Type | Escaped output |
|---|---|
| `text`, `password`, `email`, `select`, `radio`, `date`, `time`, `datetime` | `esc_html()` |
| `textarea` | `esc_html()`, `nl2br()` with `new_lines: br` |
| `url` | `esc_url()` |
| `wysiwyg` | `wp_kses_post()` |
| `color` | hex colour or `''` |
| `number`, `true_false` | typed scalar |
| `image`, `gallery`, `file`, `video`, `link`, `relationship`, `taxonomy` | arrays with escaped URLs, text and attributes |
| `group`, `repeater`, `flexible_content` | every sub-value escaped by its own type |
| `code` | **as stored** |

Escaping applies to every stored value, whoever wrote it.
WordPress escapers do not double-encode entities, so escaping again in a template is harmless.

`$raw = true` returns the stored value; escaping is then your job.

### No permission check

The getter does **no read-permission check**.
It reads a draft, a private post, any user and any declared settings page when asked.
Never build `$context` from visitor input (`$_GET`, `$_POST`, query vars, block attributes a contributor controls).
Only the declared-field rule applies: undeclared names return `null`.

### In block templates

Inside a Proto-Blocks `template.php`:

```php
$template->field(string $name, bool $raw = false): mixed
```

It reads from the block's post: the `postId` block context (a Query Loop), else the current post, else the queried object.

```php
<?php // proto-blocks/event-card/template.php ?>
<div class="event-card">
    <p class="event-card__subtitle"><?php echo $template->field('subtitle'); ?></p>
    <ul>
        <?php foreach (proto_rows('agenda') as $row) : ?>
            <li><?php echo $row['title']; ?></li>
        <?php endforeach; ?>
    </ul>
</div>
```

In the editor preview, `$template->field()` reads the post being edited when the editor may edit it.

## ACF-named aliases

When Advanced Custom Fields is **not** active, and nothing else defined `get_field()` first, the plugin defines:

```php
get_field($selector, $post_id = false, $format_value = true)
the_field($selector, $post_id = false): void
get_fields($post_id = false, $format_value = true): array
```

- They load on `after_setup_theme` priority 100, after the theme's `functions.php`, so a theme-bundled ACF wins.
- Values are escaped like `proto_field()`. `$format_value = false` returns the stored value.
- `$selector` is a field **name**. ACF `field_…` keys are not supported.
- `the_field()` prints scalars, joins lists of scalars with `", "`, prints the `url` of an array that has one, and prints nothing for other arrays. It prints `code` values raw.

| `$post_id` | Context |
|---|---|
| `false`, `null`, `''`, `0` | the current object |
| an integer, a digit string, a WP object | that post or object |
| `'term_12'`, `'topic_12'` | term 12 (the taxonomy prefix must match) |
| `'user_7'` | user 7 |
| `'option'`, `'options'` | nothing: there is no default options page (`_doing_it_wrong`); use `'option:<page-slug>'` with `proto_field()` |

There is no `have_rows()` / `the_row()`; loop over `proto_rows()`.

## Block Bindings

The source `proto-blocks/field` lets core paragraph, heading, image and button blocks show a field, resolved on the server through the same escaping reader.

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"proto-blocks/field","args":{"name":"subtitle"}}}}} -->
<p></p>
<!-- /wp:paragraph -->

<!-- wp:image {"metadata":{"bindings":{"url":{"source":"proto-blocks/field","args":{"name":"hero"}},"alt":{"source":"proto-blocks/field","args":{"name":"hero","key":"alt"}}}}} -->
<figure class="wp-block-image"><img alt=""/></figure>
<!-- /wp:image -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"proto-blocks/field","args":{"name":"phone","context":"option:theme-settings"}}}}} -->
<p></p>
<!-- /wp:paragraph -->
```

Args:

| Arg | Required | Meaning |
|---|---|---|
| `name` | yes | the field name |
| `context` | no | the getter's string or integer grammar (`"term:12"`, `"option:theme-settings"`); default the block's `postId` |
| `key` | no | one part of an `image`, `file`, `link` or `video` value, a single-post `relationship` (`"max": 1`), or one sub-field of a `group` (one level) |

Without `key`, array values map to the bound attribute:

| Field type | `url` | `alt` | `id` | `content` / `text` |
|---|---|---|---|---|
| `image`, `file` | `url` | `alt` | `id` | — |
| `link` | `url` | — | — | `title` |

Visibility rules:

- Only declared, value-holding fields are bound.
- `code` and `password` fields are never bound. A field with `"bindings": false` opts out.
- A post behind a password gives nothing. A post that is not publicly viewable needs `read_post` for the viewer.
- A term of a non-viewable taxonomy needs `edit_term`.
- User fields need `edit_user` for that user.
- Settings page fields are bindable **by any author who can edit a post** unless the field sets `"bindings": false`. Set it on anything that is not public.
- Values are always the escaped output. URL attributes go through `esc_url()`; a rejected URL gives nothing. Other non-content attributes go through `esc_attr()`.
- When a binding gives nothing, the block keeps its saved content.

A logged-in viewer may see more than an anonymous visitor.
A full-page cache that serves logged-in users must vary by user, or skip them.

The `proto-blocks/field` source exists only on WordPress 6.5+ and while the Fields module is on.

## The editing UI

### Meta boxes

Each group matching the edited post is one meta box, in the block editor, the classic editor and Elementor's classic mode.

| `position` | Block editor | Classic editor |
|---|---|---|
| `normal` | main column | main column |
| `side` | sidebar | sidebar |
| `acf_after_title` | main column, high priority | right under the title |

- Every input carries a real `name`, so the ordinary form submit saves it: the classic editor's post form, and the block editor's meta-box request after the post saves. No JavaScript is needed to save.
- Fields sit on a 12-column grid (`wrapper.width`). Narrow boxes stack fields in one column.
- Tabs and accordions keep every panel mounted.
- Repeater rows can be added, removed, moved by pointer or keyboard, and collapsed. `min` rows exist from the start.
- Relationship, gallery and wysiwyg pickers load on first use.
- The box needs `edit_post` for that post.

### Term and user forms

- Term groups render on the add-term form and as rows on the edit-term screen, for users who may add terms (`edit_terms`) or edit that term (`edit_term`).
- User groups render on the profile and edit-user screens (`edit_user`), the add-user screen (`create_users`), and the public registration form (`register`, only groups that name it).
- The add forms start from the declared defaults; they never print values stored on another term or user.
- Registration errors stop the registration and are shown inline in the same request; nothing is stored for a logged-out visitor.

### Hidden and read-only fields

For a user without `unfiltered_html`:

- a `code` field shows read-only, without its value,
- a `password` field, and any group, repeater or flexible content holding a `code` or `password` field, shows "Hidden; only users with unfiltered HTML may view and edit this field.",
- the stored values of those fields never reach that user's browser,
- saving leaves them untouched.

Fields hidden by conditional logic stay in the form, hidden, and are not saved.

## The Fields screen and builder

**Proto Blocks > Fields** requires `manage_options`.

### Group list

- Every group with its title, where it shows ("Shows on"), source, field count, position and status.
- Filters: All, Post types, Taxonomies, Options pages, Users, Other.
- Origins: *Proto-Blocks file*, *Proto-Blocks PHP*, *Plugin: Advanced Custom Fields*.
- Status: `active`, `unrenderable` ("No location rule can match"), or `skipped-acf`.
- **Add field group** asks for a title and a destination: its own `proto-fields/<slug>/group.jsonc` (slug 1–32 lowercase letters, digits, `-`, `_`), or inside an existing post type file.
- **Remove field group** deletes a group file after you type its slug ("Values saved in its fields stay in the database but are no longer shown"). A group inside a type file, or one of several groups in an ACF export, is removed from that file; the rest of the file is kept.

### Builder

- **Fields** tree: one row per field (label, name, type, required). Drag to reorder, or focus the handle and use the arrow keys. Nest sub-fields under groups and repeaters, and under each flexible content layout.
- **Add field**: a type picker grouped as Basic, Content, Choice, Relational, Layout.
- **Field settings** panel, per field, in tabs: General (label, name, type, instructions, default value, and the type's own settings such as choices or post types), Validation (required, min/max length, pattern, min, max, step), Presentation (placeholder, width, CSS class) and **Conditional logic**.
- **Conditional logic** builder: rule groups joined by "or", rules joined by "and", operators *is*, *is not*, *contains*, *is empty*, *is not empty*, *matches pattern*. Only fields at the same level are offered. A rule that reads a field elsewhere is flagged.
- **Location rules** builder: "or" groups of "and" rules over the nine params, with value pickers (post types, statuses, templates, taxonomies, roles, user forms, settings pages). An unsupported rule already in the file shows locked with a remove button. An inline group shows "Shown on … — set by the file it lives in".
- **Presentation**: position (Below editor, Sidebar, After title), style (Box, Seamless), order, and autoload for settings-page groups.
- **File & key**: the destination file and the group key.
- **JSONC** view: the same group as text, with live validation and line/column errors. Valid edits flow back into the builder.
- **Save to file** (⌘S / Ctrl+S) writes the file after server validation. A file changed on disk opens a conflict dialog instead of overwriting.

Comments survive a save, except comments **inside** an array the builder edits (such as `fields`).

### Read-only groups

The builder shows a group read-only, with the reason, when:

- `DISALLOW_FILE_MODS` or `DISALLOW_FILE_EDIT` is set (`file_mods`),
- the site is multisite and you are not a network administrator (`multisite`),
- its file cannot be written (`not_writable`),
- ACF registers the group (`acf`),
- it is declared in PHP or inside another declaration you cannot write (`inline`).

Declaring a `code` field needs no `unfiltered_html`: a declaration is a theme file.
The trust rule applies to the values stored in it.

## Saving, validation and errors

The save path is the same for posts, terms and users:

1. The form posts `proto_fields[<group key>][<name>]`, the list of rendered editable fields `proto_fields_present[<group key>][]`, a nonce and an object marker.
2. The server checks the nonce, the edit capability and that the marker names this object.
3. For each group matching the object, each field listed as present is processed. Fields not present are untouched.
4. Visibility is recomputed; hidden fields are skipped.
5. Values are validated. An invalid field is **not written**; the rest of the save goes on.
6. Values are normalised for everyone, and sanitised per type for an untrusted writer.
7. Code-holding fields are refused for an untrusted writer, with "Only users with unfiltered HTML may edit this field."

The post (or term, or user) always saves.
Errors and the invalid submitted values are kept for five minutes, per object and per user, and the box shows them in place on the next load.
They are read once.

Error messages:

| Message | Cause |
|---|---|
| This field is required. | `required` and empty |
| Enter at most %d characters. / Enter at least %d characters. | `maxlength` / `minlength` |
| The value does not match the required format. | `pattern`, or a wrong shape |
| Invalid pattern rule. | the `pattern` does not compile |
| Enter a valid email address. / Enter a valid URL. / Enter a valid date. | `email`, `url`, date types |
| Enter a number no less than %s. / no greater than %s. | `number` bounds |
| Choose one of the listed options. | not a choice key, a list for a single select, an unknown layout |
| Invalid choice. | an `optionsSource` that could not be resolved |
| Add at least %d rows. / Add no more than %d rows. | `min` / `max` / row cap |

Error paths are dotted: `agenda.1.title`.

## Storage

One meta row per stored top-level field, named after the field `name`:

| Location | Storage |
|---|---|
| Post types | `register_post_meta($type, $name, ...)`, `single`, typed, with a default |
| Taxonomies | `register_term_meta($taxonomy, $name, ...)` |
| Users | `register_meta('user', $name, ...)` |
| Settings pages | one option per field, `<page_slug>_<field_name>`, or one array option (see [settings pages](settings-pages.md#storage-and-option-names)) |

- `group`, `repeater` and `flexible_content` are one JSON-serialisable value in one row (not ACF's flattened rows).
- The meta type comes from the field type's REST schema.
- Post meta is registered with `revisions_enabled` when the type supports revisions (WordPress 6.4+).
- A type that receives fields gains `custom-fields` support.
- Registered keys are protected meta, so core's raw Custom Fields panel never lists or edits them.
- A name used by two groups on the same screen is registered once, for the first group by `menu_order`, then source order (`duplicate_meta_key` warning).
- User fields may not use `session_tokens` or the capability and user-level keys (`wp_capabilities`, `wp_user_level`, and the multisite `wp_<n>_` forms): `protected_meta_key` warning, the field is not stored.
- Settings page fields whose option name is protected are not stored (`protected_option`).

Renaming or removing a field never moves or deletes stored values; they stay under the old key.

## REST

### Core's REST API

Every stored field is registered meta with a schema derived from its type, so it appears in core's `meta` object.

| Field | `show_in_rest` default |
|---|---|
| post and term fields | on |
| `password` and `code` fields, and containers holding one | off |
| user fields | **off** |

Set `"show_in_rest": true` or `false` on a field to decide.

Exposed meta is readable anonymously in the `view` context of published posts, viewable terms and public users.
Term meta of public taxonomies and post meta of password-protected posts are served without the password.
Turn `show_in_rest` off for anything private.

Writes through core's endpoints go through the same callbacks:

```bash
curl -X POST https://example.test/wp-json/wp/v2/event/42 \
  -H "X-WP-Nonce: $NONCE" -H 'Content-Type: application/json' \
  -d '{"meta": {"subtitle": "Doors at nine"}}'
```

- The user needs `edit_post` (`edit_term`, `edit_user`) for the object.
- A code-holding field also needs `unfiltered_html`; otherwise the stored value does not change.
- Values are sanitised per type for untrusted writers.

The `event` route is `wp/v2/event` because the type's `rest_base` defaults to its slug.

### Plugin routes

| Method | Route | Purpose |
|---|---|---|
| GET | `fields/errors?object=post:42` | The current user's last save errors for an object (read once) |
| GET | `fields/relationship?group=<key>&field=<name or key>&search=&page=1` | The relationship picker's search |
| GET | `admin/fields` | Every group, for the Fields screen |
| GET | `admin/fields/<group key>` | One group with its file and the builder's pick lists |
| POST | `admin/fields/validate` | Validate group text without saving |
| GET, POST, PUT, DELETE | `admin/schema-files/field-group/<slug>` | Read, save and delete `proto-fields/<slug>/group.jsonc` |

Shapes and errors: [REST API](rest-api.md#fields).

## CLI

```bash
wp proto-blocks fields list [--format=table|json|csv]
wp proto-blocks fields validate [<path>...]
```

`list` columns: `key`, `title`, `source`, `path`, `fields`, `location`, `status` (`active`, `unrenderable`, `skipped-acf`).
`validate` without paths checks every discovered file, inline group and PHP group; with paths, only those files.
It exits `1` on any error; warnings (such as `wide_location`) do not fail.

Values written from the CLI go through the same callbacks.
WP-CLI runs as user 0 unless you pass `--user`, and user 0 is untrusted: markup is sanitised and `code` values are refused.
Seed `code` or markup values as an administrator who has `unfiltered_html`:

```bash
wp --user=admin post meta update 42 subtitle "Doors at nine"
wp --user=admin option update theme-settings_head_scripts '<script src="https://cdn.example/a.js"></script>'
```

Details: [CLI](cli.md#wp-proto-blocks-fields).

## Trust rules

Trust is decided per user, at write time, for posts, terms, users and settings pages alike.

- A user with `unfiltered_html` stores values as given (normalised for shape).
- Anyone else gets each type's sanitiser.
- `code` and `password` fields, and containers holding one, are written only by users with `unfiltered_html`. An untrusted save leaves the stored value untouched and reports the refusal.
- A context-less write (cron, WP-CLI without `--user`, importers) is untrusted.
- The getter escapes every type except `code`, whoever wrote the value.
- A `code` value is trusted only because the plugin lets only trusted users write it. A value written some other way (another plugin, `update_post_meta()`, the database) is not vouched for.
- **Re-typing a field to `code` makes the values already stored under the old type come back raw.** Clear them first if untrusted users wrote them.

The full boundary is in [trust and security](trust-and-security.md).

## Performance

- The group index is built once per request, lazily, on the first read after `init` priority 10. Nothing reads a file before that.
- Matches per object are memoised for the request; values are never cached (escaped output can depend on the viewer).
- `optionsSource` choices are resolved once per source and arguments per request.
- In the editor, heavy pickers (relationship, gallery, wysiwyg) load lazily; no picker code loads before it is opened.
- The E2E budget for a box with 50 fields: mount in 600 ms or less, keystroke p95 of 50 ms or less, max 100 ms.
- A repeater holds at most 500 rows by default (the shared row budget).
- A box payload lists at most 500 terms per taxonomy field and 2000 term entries in total.

## Filters

| Filter | Arguments | Use |
|---|---|---|
| `proto_blocks_repeater_max_items` | `int $max`, `string $name`, `array $settings` | the row cap (default 500) |
| `proto_blocks_field_groups` | `list<FieldGroup> $groups` | add or remove normalised groups after loading |
| `proto_blocks_field_stores` | `array<string, Store> $stores` | replace the store for a kind (`post`, `term`, `user`, `option`) |
| `proto_blocks_declaration_errors` | `array $errors`, `string $kind`, `array $data`, `string $slug` | add validation findings for a declaration (`kind` `field-group`, `type`, `taxonomy`, `options`) |
| `proto_blocks_modules` | `array<string, bool> $modules` | `['fields' => false]` turns the module off |

With the module off, no meta is registered, no boxes render, no bindings source exists, and `proto_field()` returns `null`.

```php
add_filter('proto_blocks_modules', static fn (array $modules): array => ['fields' => false] + $modules);
```

## Migrating from ACF

Proto-Blocks reads ACF's **file format** and offers ACF's **getter names**.
It does not migrate an existing ACF site's stored meta.

What carries over unchanged:

- local JSON and Tools exports, ACF-only keys included,
- `key`, `title`, `fields`, `location`, `position`, `style`, `menu_order`, `conditional_logic`, `sub_fields`, `layouts`,
- the nine location params listed above,
- `get_field()`, `the_field()`, `get_fields()` when ACF is inactive,
- ACF `Ymd` dates, read as `Y-m-d`.

What differs:

| ACF | Proto-Blocks |
|---|---|
| `group`/`repeater`/`flexible_content` stored as flattened rows (`agenda_0_title`) plus `_agenda` reference meta | one JSON value per field, no reference meta |
| values returned mostly raw | escaped by default; `$format_value = false` for raw |
| `return_format` decides the shape | ignored; fixed shapes per type |
| `have_rows()` / `the_row()` | `proto_rows()` |
| `get_field('x', 'option')` | `proto_field('x', 'option:<page-slug>')` |
| `get_field('field_123abc')` | names only |
| unknown field types render nothing | an error; the field is skipped |
| unknown location params | the whole group is not rendered |
| `user` fields store user objects | `select` with `optionsSource: wp:users`, storing user IDs as strings |
| `post_object` without `multiple` | `relationship` with `max: 1`, read as one `{id, title, url, type}` |
| `acf-json/` folders | never read; copy files to `proto-fields/<slug>/group.json` |
| any role can store markup through some fields | `code` and `password` need `unfiltered_html` |

When ACF stays active, a Proto-Blocks group with the same `key` as an ACF group is skipped, with a notice.
