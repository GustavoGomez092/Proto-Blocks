# Dynamic Control Options (server-injected `select` options)

## Overview

A `select` control normally lists its choices with a static `options` array in
`block.json`. With **server-injected options**, a `select` can instead declare
an `optionsSource` (plus optional `sourceArgs`) and have its choices loaded from
the server at edit time. This is useful when the available choices come from
WordPress data (pages, taxonomy terms, users) or from your own application
(currencies, regions, API records, etc.) and would be impractical to hard-code.

When a control uses `optionsSource`:

- You **do not** provide a static `options` array (the schema validator accepts a
  `select` that has `optionsSource` and no `options`).
- In the editor, the control renders as an **async select** that shows a spinner
  while it fetches, then populates with the returned options.
- The stored attribute value is the chosen option's `key`.

## `block.json` syntax

```json
{
  "protoBlocks": {
    "controls": {
      "relatedPage": {
        "type": "select",
        "label": "Related Page",
        "optionsSource": "wp:posts",
        "sourceArgs": { "post_type": "page", "per_page": 50 }
      },
      "category": {
        "type": "select",
        "label": "Category",
        "optionsSource": "wp:terms",
        "sourceArgs": { "taxonomy": "category" }
      },
      "currency": {
        "type": "select",
        "label": "Currency",
        "optionsSource": "currencies"
      }
    }
  }
}
```

- `optionsSource` (string, required for dynamic mode): the registered provider id.
- `sourceArgs` (object, optional): arguments passed to the provider. Only
  whitelisted keys are forwarded to the provider (see [Registering a custom
  provider](#registering-a-custom-provider)).

A full working example lives in [`examples/dynamic-select/`](../examples/dynamic-select/).

## Built-in sources

| Source     | Supported `sourceArgs`               | Returns                                   |
| ---------- | ------------------------------------ | ----------------------------------------- |
| `wp:posts` | `post_type`, `per_page`, `search`    | Posts of the given type → `{ key: ID, label: title }` |
| `wp:terms` | `taxonomy`, `per_page`, `search`     | Terms in the taxonomy → `{ key: term_id, label: name }` |
| `wp:users` | `per_page`, `search`                 | Users → `{ key: ID, label: display_name }` |

`per_page` is clamped to the range **1–200** server-side, regardless of the value
you request.

## Registering a custom provider

Register your own source on the `proto_blocks_register_options_providers` action.
The registry instance is passed to the callback; call `register()` on it.

```php
add_action('proto_blocks_register_options_providers', function ($providers) {
    $providers->register('currencies', function (array $args): array {
        return [
            ['key' => 'usd', 'label' => 'US Dollar'],
            ['key' => 'eur', 'label' => 'Euro'],
        ];
    });
});
```

Details:

- **Provider name** — the first argument (`'currencies'`) is the id you reference
  in a control's `optionsSource`.
- **Callback** — receives an `array $args` (the `sourceArgs` from `block.json`,
  filtered to the whitelist) and must return the option list.
- **Return shape** — return a list of `{ key, label }` entries, e.g.
  `[['key' => 'usd', 'label' => 'US Dollar'], ...]`. A plain associative
  `key => label` map (e.g. `['usd' => 'US Dollar']`) is also accepted and
  normalized.
- **Allowed args** — an optional third argument to `register()` whitelists which
  `sourceArgs` keys are forwarded to your callback. The default is to allow all
  args. Example:

  ```php
  $providers->register('currencies', $callback, ['region']);
  ```

  Here only a `region` arg from `sourceArgs` reaches the callback.

## How it works at runtime

1. The editor renders any `select` with an `optionsSource` as an async control.
2. It fetches options from the REST endpoint:

   ```
   GET proto-blocks/v1/controls/options?source=<id>&args=<json>
   ```

   - `source` — the `optionsSource` id.
   - `args` — URL-encoded JSON of the `sourceArgs`.
   - **Permission:** `edit_posts`.
   - **Response:** `{ "options": [ { "key": ..., "label": ... }, ... ], "total": <int> }`.
3. While the request is in flight the control shows a spinner; on success it
   populates the dropdown with the returned options.

## Manual verification (requires a running WordPress install — NOT executed in this build)

> The following steps require a live WordPress install with Proto-Blocks active.
> They were **not** executed in this build (there is no WordPress runtime
> available here). Use this as a checklist when verifying against a real site.

1. **Install the example block.** Copy `examples/dynamic-select/` into the active
   theme's `proto-blocks/` directory (or whichever blocks path your install
   discovers), then run:

   ```
   wp proto-blocks cache clear
   ```

2. **Inspect the controls.** In the block editor, insert the **"Dynamic Select
   Demo"** block. Confirm the **"Related Page"** and **"Category"** selects each
   show a spinner momentarily, then populate with the site's existing pages and
   categories respectively.

3. **Select and save.** Choose a value in each select; confirm the editor preview
   updates and that, after saving, the frontend renders the chosen page title and
   category name.

4. **REST smoke test.** Logged in as a user with `edit_posts`, run in the browser
   console:

   ```js
   wp.apiFetch({
     path: '/proto-blocks/v1/controls/options?source=wp:posts&args=' +
       encodeURIComponent(JSON.stringify({ post_type: 'page' }))
   }).then(console.log)
   ```

   Expect `{ options: [ { key, label }, ... ], total }`. Then request an unknown
   source:

   ```js
   wp.apiFetch({ path: '/proto-blocks/v1/controls/options?source=does-not-exist' })
   ```

   Expect an HTTP `400` with code `proto_blocks_unknown_source`.
