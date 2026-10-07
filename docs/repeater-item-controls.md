# Repeater item controls

A repeater row has two halves. `fields` are edited **on the canvas**, where the
author sees what they are changing. `itemControls` are edited **in the sidebar**,
for the values that have nowhere to be typed on the canvas at all.

```jsonc
"reps": {
  "type": "repeater",
  "itemLabel": "name",
  "fields": {                              // canvas: printed, bound, visible
    "logo":      { "type": "image" },
    "name":      { "type": "text" },
    "territory": { "type": "text" }
  },
  "itemControls": {                        // sidebar: consumed, not printed
    "phone":   { "type": "text",     "label": "Phone" },
    "email":   { "type": "text",     "label": "Email" },
    "address": { "type": "textarea", "label": "Address" }
  }
}
```

## Why this exists

A field is editable only when some element in the rendered markup carries its
`data-proto-field`. That is fine for anything the template **prints**.

It breaks for anything the template **consumes**: a phone number read into a
`data-` attribute for a dialog, a CSV parsed into a table, a colour passed to a
`style`. None of them produce an element, so none of them can be bound, so there
is nowhere to type them. Before `itemControls` the only ways out were both bad —
render the value somewhere it does not belong just to make it editable, or leave
the author editing JSON by hand.

`itemControls` gives those values a home without putting them on the canvas.

## How it behaves

Click or tab into a row on the canvas and a panel appears at the **top of the
block's sidebar**, titled with that row — its `itemLabel` field, or "Item 3" when
that is empty. The block's own settings stay below it, so nothing is hidden by
focusing a row.

The panel writes into the same repeater attribute the canvas edits, so the two
halves cannot drift apart. The template reads a row exactly as it always did:

```php
foreach ($attributes['reps'] as $rep) {
    $phone = (string) ($rep['phone'] ?? '');   // itemControls
    $name  = (string) ($rep['name'] ?? '');    // fields
}
```

There is no second attribute, no separate array, and nothing extra to migrate.

## Choosing between fields and itemControls

| The value is… | Put it in | Because |
|---|---|---|
| printed in the row's markup | `fields` | the author edits it where they see it |
| consumed — read into an attribute, parsed, passed to a style | `itemControls` | it has no element to bind, so no canvas home |
| a choice from a list (`select`, `toggle`, `range`, `color`) | `itemControls` | a picker belongs in a panel, not inline in content |

A good test: **if removing the value changes nothing visible in the row's markup,
it belongs in `itemControls`.**

## What a row may hold

`itemControls` accepts every control type — `text`, `textarea`, `select`,
`multiselect`, `toggle`, `checkbox`, `range`, `number`, `color`,
`color-palette`, `radio`, `image`, `video`, `file` — and each is validated
exactly as it is in the block's own `controls`, including `optionsSource`. A bad
one is reported by its path, `reps.pick`, so you know which row's control is
wrong rather than only that something is.

**A repeater inside `itemControls` is rejected.** Rows do not nest: a list inside
a sidebar panel has nowhere to be laid out, and the stored value would stop being
a flat list of flat objects.

## Compatibility

`itemControls` is additive and optional:

- A repeater that declares none behaves exactly as it did. No existing block
  changes, and none in the bundled library declares it.
- The stored attribute shape is unchanged — still an array of flat objects.
- The PHP side is untouched. Templates read rows the same way.
- The sidebar only changes while a row with `itemControls` is focused.

## See also

- `repeater-control.md` — the repeater as a *control*, for repeated configuration
  that never appears on the canvas at all
- `fields.md` — the canvas half, and the rule that a field must be rendered to be
  editable
