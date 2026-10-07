# Repeater control

A repeatable group of controls in the **sidebar**: an ordered list of rows, each
built from the control's own `fields`.

## When to use it rather than the repeater field

Proto-Blocks has two repeaters, and they are for different things.

The repeater **field** (`protoBlocks.fields`) renders its editing UI into the
canvas, beside the thing being edited. That is right when the repeated thing is
content the visitor reads — a list of milestones, a set of cards, a row of
logos — because the author edits it where they can see it.

The repeater **control** (`protoBlocks.controls`) is for repeated
*configuration*: a set of tabs, a list of breakpoints, a table pasted as CSV,
anything the template consumes rather than prints. Those have no natural place
on the canvas. Put them in a field and the block's own markup ends up carrying
editing furniture, or — worse — the value has nowhere to be edited at all,
because a field is only editable when some element carries its
`data-proto-field` and a parsed value has no element.

## Shape

```json
{
  "protoBlocks": {
    "controls": {
      "tabs": {
        "type": "repeater",
        "label": "Tabs",
        "itemLabel": "label",
        "min": 1,
        "max": 12,
        "help": "One table per tab.",
        "fields": {
          "label": { "type": "text", "label": "Tab name" },
          "csv":   { "type": "textarea", "label": "Table (CSV)" },
          "csvFile": {
            "type": "file",
            "label": "Or a CSV file",
            "allowedTypes": ["text/csv"]
          }
        }
      }
    }
  }
}
```

| Key | Meaning |
|---|---|
| `fields` | **Required.** The controls one row is built from, keyed by the name each value is stored under. |
| `itemLabel` | Which field titles a row in the list. Defaults to the first field. A row with no value falls back to "Item 1". |
| `min` | Rows below this cannot be removed. Default `0`. |
| `max` | Adding stops here. Default unlimited. |

## The value

An array of flat objects, one per row:

```php
$tabs = $attributes['tabs'] ?? [];

foreach ($tabs as $tab) {
    $label = (string) ($tab['label'] ?? '');
    $csv   = (string) ($tab['csv'] ?? '');
}
```

Rows are plain arrays, so ordering, `array_filter` and `array_chunk` all work as
they would on any list. There is no item id: the order *is* the identity.

## What a row may contain

Every control type except `repeater` and `gallery`: `text`, `textarea`,
`select`, `multiselect`, `toggle`, `checkbox`, `range`, `number`, `color`,
`color-palette`, `radio`, `image`, `video`, `file`.

Rows do not nest. A second level inside a sidebar panel is unreadable, and
keeping it flat keeps the stored value a plain list of flat objects. Validation
rejects a `repeater` inside a repeater rather than rendering something
unusable.

A row's controls are rendered through the same switch as any other control, so
`options`, `optionsSource`, `help`, `min`/`max` and the rest behave identically
inside a row.

## In the editor

Rows collapse to their title and only the open one shows its controls — a
sidebar is narrow, and several expanded rows at once cannot be read. Each row
carries move-up, move-down and remove; "Add item" sits beneath the list.

## Three repeaters, and which is which

Proto-Blocks repeats in three places, and they are not interchangeable:

| | Where it is edited | What it is for |
|---|---|---|
| repeater **field** (`fields`) | the canvas | repeated **content** — the row's own markup |
| repeater field's **itemControls** | the sidebar, per row | values a row *consumes* rather than prints |
| repeater **control** (`controls`) | the sidebar | repeated **configuration** that never appears as content |

A list of milestones is a field. The phone number each milestone carries into a
dialog is an itemControl. A set of tabs whose CSV the template parses is a
control. See `repeater-item-controls.md` for the middle one.

## See also

- `repeater-item-controls.md` — per-row controls in the sidebar for a repeater field
- `file-control.md` — for choosing any attachment, not only an image
- `references/composition.md` in the skill — choosing between a field and a
  control in the first place
