# The `multiselect` Control

`multiselect` stores an **ordered list** of option keys. It takes exactly the
same configuration as [`select`](./dynamic-control-options.md) — static
`options` or a server-loaded `optionsSource` — and differs only in arity.

```json
"featuredProducts": {
  "type": "multiselect",
  "label": "Featured products",
  "optionsSource": "wp:posts",
  "sourceArgs": { "post_type": "product", "per_page": 200 }
}
```

## The stored value

An array of option keys, as strings, in the order the author arranged them:

```php
$ids = $attributes['featuredProducts'] ?? [];   // ['18279', '18277', '18282']
```

Keys are strings even when they are numeric post IDs — the same rule `select`
follows.

## Reading it in a template

```php
$ids = $attributes['featuredProducts'] ?? [];

$products = $ids ? get_posts([
    'post_type'      => 'product',
    'post__in'       => array_map('intval', $ids),
    'orderby'        => 'post__in',
    'posts_per_page' => count($ids),
]) : [];

foreach ($products as $product) {
    // ...
}
```

> **`'orderby' => 'post__in'` is load-bearing.** Without it WordPress returns
> the posts in date order and throws away the ordering the author just dragged
> into place. This is the single most common mistake with this control.

A key whose post has since been deleted simply returns no row — the loop is
shorter, nothing errors. The editor keeps showing the key as a bare token so
the author can see and remove it.

A selected item that is not in the current result page — because the catalogue is larger than `per_page` and no search has surfaced it yet — also shows as its bare key until you search for it; the selection itself is never lost.

## Authoring behaviour

- **Search hits the server.** `per_page` is clamped server-side to 1–200, so on
  a large catalogue the control queries as the author types (debounced) rather
  than filtering only the first page. Any provider that honours a `search`
  argument gets this for free; the built-in `wp:posts`, `wp:terms` and
  `wp:users` all do. `wp:posts` matches against title, excerpt and post
  content, but the field then filters the returned suggestions by matching
  the typed text against each item's title, so only title matches are ever
  shown -- a term that only appears in a product's body copy will not surface
  a suggestion for it.
- **Order is drag-and-drop.** With two or more selections a reorder list
  appears under the token field.
- **Duplicate labels are disambiguated.** Two posts sharing a title show as
  `Half Size Convection Oven (#18282)`. The suffix is display-only; the stored
  value is always the bare key.

## Validation

A `multiselect` with neither `options` nor `optionsSource` is a hard schema
error, exactly as for `select`:

```
Control "featuredProducts" of type "multiselect" must have options or an optionsSource defined
```

## Custom providers

Anything registered on `proto_blocks_register_options_providers` works with
`multiselect` unchanged — see
[Dynamic / Server-Provided Options](./dynamic-control-options.md). A provider
that ignores the `search` argument still works; it just cannot narrow past its
own `per_page`.
