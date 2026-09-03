# Multiselect Control — Design

**Date:** 2026-09-02
**Status:** Approved (design); pending implementation plan.

## Goal

Give block authors a control that selects **many** items instead of one, so a
block can hold an ordered, hand-curated list of posts, terms or users without a
bespoke UI per block.

**Success criteria**

- `"type": "multiselect"` in a block's `protoBlocks.controls` renders a token
  field in the inspector; the stored attribute is an array of option keys.
- It accepts the **same** `options` / `optionsSource` / `sourceArgs` contract as
  `select`, so every existing provider (`wp:posts`, `wp:terms`, `wp:users`, and
  any theme-registered one) works with no server change.
- Typing in the field searches the **server**, not just the loaded page, so a
  catalogue larger than `per_page` is fully reachable.
- Selected items can be reordered by dragging, and the stored order is the order
  the template renders.
- `wp proto-blocks validate` reports no error or warning for a correct
  multiselect, and a hard error for one with neither `options` nor
  `optionsSource`.
- Values are sanitised server-side; a hand-edited post payload cannot put
  arbitrary strings into the attribute.

## Context

The control layer already has everything this needs except the control itself.

- **Server options pipeline exists.** `includes/Controls/OptionsProviders.php`
  plus `GET /proto-blocks/v1/controls/options?source=&args=` (capability
  `edit_posts`) already resolve a source id to a `{key,label}[]` list.
  `Plugin::registerCoreOptionsProviders()` ships `wp:posts`, `wp:terms` and
  `wp:users`; themes add their own on
  `proto_blocks_register_options_providers`. **None of this changes.**
- **Client fetch helper exists.** `src/editor/controls/options-source.ts`
  (`fetchControlOptions`) already wraps that endpoint and maps to
  `{value,label}[]`.
- **The single-value precedent exists.** `DynamicSelectControl.tsx` is the shape
  to follow: fetch on mount, spinner while loading, an inline
  "Could not load options." on failure.
- **Drag-reorder is already a dependency.** `@dnd-kit/core`, `@dnd-kit/sortable`
  and `@dnd-kit/utilities` are in `package.json` `dependencies` and are used by
  `src/editor/fields/RepeaterField.tsx`. No new package.
- **A debounce helper exists.** `src/editor/utils/debounce.ts`.
- **Attribute plumbing already understands arrays.** `Registry::mapDataType()`
  maps `'array'` → WordPress attribute type `array`; `RepeaterField` registers
  `['type' => 'array', 'default' => []]` and works.

Three facts drive the tricky parts of this design:

1. **`per_page` is clamped server-side to 1–200** (`Plugin::clampPerPage()`),
   and real catalogues exceed that — the Cadco workbook is ~238 products. A
   control that loads once on mount would silently make some records
   unselectable, and the author would have no way to tell. Server-side search is
   therefore a requirement, not a refinement.
2. **`FormTokenField` is a control over label _strings_, but we store keys.**
   Two posts may legitimately share a title. The label→key mapping has to be
   explicit and collision-aware, or selecting "Half Size Convection Oven" would
   store whichever of the two the map happened to hold last.
3. **`Registry::sanitize()`'s `match` has no `array` arm.** Its `default`
   returns `$value` untouched, so an array-typed control is currently
   *unsanitised*. `repeater` escapes this because it is a *field* and sanitises
   through `FieldInterface::sanitize()`; a control has no such path. This is a
   latent hole that adding the first array-typed control would walk straight
   into.

## Approach (decided)

Add `multiselect` as a **core control type**, mirroring `select` in every
respect except arity. Selection uses core's `FormTokenField`; ordering uses a
dnd-kit sortable list rendered beneath it.

Rejected alternatives:

- **A `select` field type usable inside a repeater.** Would have let authors
  repeat a picker row, but fields and controls are separate registries with
  separate rendering paths (`data-proto-*` binding vs inspector), and fields
  have no options pipeline. Far more surface for a worse result.
- **A searchable checkbox list.** Sidesteps the label→key ambiguity entirely,
  but has no ordering and reads badly past a few dozen options.
- **Per-block bespoke controls.** What this feature exists to stop.

## Decisions (agreed)

- **Name:** `multiselect` (one word, matching `color-palette`'s lowercase
  hyphenation style only where a second word exists).
- **Value shape:** `string[]` of option keys, order-significant. Keys stay
  strings even when they are numeric ids, matching `select` (whose "stored value
  is the option key", e.g. a post ID as a string).
- **UI:** `FormTokenField` for add/remove/search, plus a dnd-kit `SortableContext`
  list of the selected tokens for drag-reordering.
- **Config surface:** exactly `select`'s — `options`, `optionsSource`,
  `sourceArgs`, `label`, `help`, `default`, `conditions`. **No new config keys.**
  Drag-reordering is unconditional behaviour, not a `sortable` flag, and there is
  no `min`/`max`. A control that is configured just like `select` and merely
  stores more than one value is the whole idea; every knob added now is API
  surface to support forever.
- **Scope:** control + docs + skill + tests, shipped across both repos, before
  any consuming block is built.

## Architecture

### Server

**`includes/Core/Plugin.php` — `registerCoreControlTypes()`**

```php
$registry->register('multiselect', [
    'data_type' => 'array',
    'default'   => [],
]);
```

No `sanitize` callback: with the registry's `'array'` arm added below, the
default path already does the right thing — reject a non-array outright (`[]`),
cast each scalar entry with `sanitize_text_field()`, drop non-scalars and
empties. Deduplication belongs there too: a token field can only add an option
once, so a duplicate means a tampered payload.

**`includes/Controls/Registry.php` — `sanitize()`**

Add the missing arm so *any* array-typed control (this one and future ones) is
sanitised when it does not supply its own callback:

```php
'array' => is_array($value)
    ? array_values(array_unique(array_filter(
        array_map('sanitize_text_field', array_filter($value, 'is_scalar')),
        static fn($v) => $v !== ''
    )))
    : [],
```

**`includes/Schema/SchemaValidator.php`**

- Add `'multiselect'` to `VALID_CONTROL_TYPES`.
- Extend the existing options guard so it covers both types:
  a `select` **or** `multiselect` with neither `options` nor `optionsSource` is
  a hard error.
- **Fix the stale list while here.** `VALID_CONTROL_TYPES` currently holds 7
  entries (`text`, `select`, `toggle`, `range`, `number`, `color`, `image`) while
  `registerCoreControlTypes()` registers 12. `textarea`, `checkbox`,
  `color-palette`, `radio` and `video` therefore emit a spurious
  `Control "x" uses unknown type "y"` warning today. Add all five.

### Client

**`src/editor/controls/MultiSelectControl.tsx`** (new)

Props mirror `DynamicSelectControl` exactly, plus static `options`. Internal
state:

| State | Purpose |
|---|---|
| `available` | `{value,label}[]` most recently returned by the server |
| `labelsByKey` | `Map<string,string>` — every key ever seen, so a *selected* token still renders its label after a search narrows `available` |
| `search` | current input text, debounced before refetch |
| `loading` / `error` | same states as `DynamicSelectControl` |

Behaviour:

- On mount, and on every debounced `search` change, call
  `fetchControlOptions(source, { ...sourceArgs, search })`. Static `options`
  short-circuit this entirely and filter client-side.
- `labelsByKey` accumulates rather than replaces, so tokens for previously
  selected keys never degrade to a raw id when they fall out of the current
  result page.
- `suggestions` = labels of `available` minus already-selected.
- `onChange` maps returned labels back to keys through a
  `keysByLabel` index built from `available` ∪ selected.

**Label collisions.** `keysByLabel` is built as a `Map<string, string[]>`. When a
label maps to more than one key, the control renders those entries with a
disambiguating suffix — `Half Size Convection Oven (#18282)` — so every
suggestion string is unique and the reverse lookup is total. The suffix is
display-only; the stored value is always the bare key.

**Ordering.** Below the token field, selected keys render as a
`SortableContext` (dnd-kit, `verticalListSortingStrategy`) of drag handles, the
same pattern `RepeaterField` uses. Dragging reorders the array; removing is
available from both the token chip and the row. Always on — with one selection
the list is a single undraggable row, which costs nothing.

**`src/editor/controls/render.tsx`**

```tsx
case 'multiselect':
    return (
        <MultiSelectControl
            label={config.label}
            value={Array.isArray(value) ? (value as string[]) : []}
            options={config.options}
            source={config.optionsSource}
            sourceArgs={config.sourceArgs}
            onChange={onChange}
        />
    );
```

**`src/editor/types.ts`** — no change needed: `multiselect` reuses `select`'s
existing `options` / `optionsSource` / `sourceArgs` config keys, which
`ControlConfig` already declares.

### Template contract

```php
$ids = $attributes['featuredProducts'] ?? [];

$products = $ids ? get_posts([
    'post_type'      => 'product',
    'post__in'       => array_map('intval', $ids),
    'orderby'        => 'post__in',   // preserves the author's drag order
    'posts_per_page' => count($ids),
]) : [];
```

`orderby => 'post__in'` is the load-bearing half — without it WordPress returns
date order and the drag-reordering the control just bought is thrown away. This
must be prominent in the docs.

## Error handling

- **Provider throws / endpoint 500** → the control renders
  "Could not load options.", exactly as `DynamicSelectControl` does. Already-selected
  tokens still render from `labelsByKey`, so an author never sees their curated
  list appear empty because of a transient fetch failure.
- **Unknown source (HTTP 400)** → same inline message; the console carries the
  detail.
- **A stored key no longer resolving** (post deleted) → the token renders as the
  bare key, and the template's `get_posts()` simply returns fewer rows. No fatal,
  no empty render. Documented as expected behaviour rather than special-cased.
- **Non-array attribute** (hand-edited payload) → sanitiser returns `[]`.

## Testing

**JS (`wp-scripts test-unit-js`)**

- `MultiSelectControl`: label↔key round-trip, including two options sharing a
  label — asserting the disambiguated suggestion and that the stored value is
  the bare key.
- `labelsByKey` persistence: select a key, search to a term that excludes it,
  assert the token still shows its label.
- Debounce: N keystrokes produce one fetch.

**PHP (PHPUnit)**

- `Registry::sanitize()` `'array'` arm: non-array → `[]`; nested arrays and
  objects filtered out; duplicates collapsed; empty strings dropped; a clean
  list passes through order-intact.
- `SchemaValidator`: multiselect with `optionsSource` → valid; with neither →
  one error; the five previously-missing control types → no warning.

## Non-goals (YAGNI)

- No pagination UI. Server search covers reachability; an infinite-scroll token
  field is not worth the surface.
- No "create new item inline" from the token field.
- No grouped/optgroup suggestions.
- No per-item metadata (thumbnails in the token list). Labels only.
- **No `min`/`max`/`sortable` config keys.** The control takes exactly `select`'s
  config. A cap on selections is the first thing likely to be asked for; it stays
  out until a block actually needs it.
- No migration path from `select` to `multiselect` — a block author changes the
  type and re-picks.

## Delivery

Two repos, conventional commits so semantic-release cuts the versions:

1. **`GustavoGomez092/Proto-Blocks`** — `feat(controls): add multiselect control
   with server-backed search and drag ordering`. Push to `main` triggers
   `build-and-release.yml` + `release.yml` → minor bump.
2. **`GustavoGomez092/protoblocks-skill`** — `docs: document the multiselect
   control`. Updates `skills/protoblocks/SKILL.md` (control-type quick
   reference) and `skills/protoblocks/references/controls.md` (full entry,
   including the `orderby => 'post__in'` note).

Plugin docs: a new `docs/multiselect-control.md`, cross-linked from
`docs/dynamic-control-options.md`, plus a README control-table row.

The consuming block (`cadco-featured-products`) is built **after** this ships,
against the released control.
