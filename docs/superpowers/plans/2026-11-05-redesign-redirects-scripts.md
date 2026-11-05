# Redesign: Redirects and Scripts admin screens

**Design hand-off:** `.superpowers/sdd/2026-11-05-redirects-scripts/design/Scripts and Redirects.dc.html`
(screens "Scripts", "Scripts — empty", "Script editor", "Redirects", "Redirects — 404s", "Edit redirect dialog").
Applied the way the Phase 0 hand-off was: existing shell/ui components, tokens in
`src/admin/styles/tokens.css` (imported by admin.css), utilities scoped to `#proto-blocks-admin`,
no new dependencies, no inline hex.

## Phase 1: Scripts (commit per screen)

1. **Tokens.** Every colour in the design except `#9fb6d3` (key/kbd ink on navy and the
   dark-panel JSON keys) already exists as a token. Add `--panel-key`.
2. **Scripts list** (`src/admin/scripts/ScriptList.tsx`)
   - Header: eyebrow "Proto-Blocks · Site", description with `proto-scripts/` chip, New script.
   - Two columns at `lg` (table + 300px dark aside), stacked below (782px safe).
   - Table: Script (origin dot + title, `proto-scripts/<slug>/` path), Prints in (mono
     `head` / `body` / `footer` chips, on = brand wash, off = muted), Where it runs (include
     labels, "except" chips on the destructive wash, "· hidden from" role pill), Priority (right,
     tabular), On (switch), Edit/View. Disabled rows: muted title. Footer note on ordering.
   - Aside "Rendered on the front end": per location, enabled scripts in print order
     (priority, then slug), disabled ones struck through with "(off)".
   - Empty state: brand eyebrow, headline, template tiles (Google Tag Manager, Site
     verification meta, Chat widget, Blank script) that open the create screen prefilled
     (`?new=1&template=<id>`). Tiles disabled when read-only.
3. **Script editor** (`ScriptEditor.tsx`, `CodeEditor.tsx`, `PlacementBuilder.tsx`)
   - Header: back link, title as an inline input ("Script title"), origin dot + path, Enabled
     switch, Delete (ghost), "Save to file" with the ⌘S/Ctrl+S hint (shortcut wired).
   - Snippets card: tabs per location (dot when it has code, "empty" otherwise, file name on the
     right), gutter editor, footer note. A failed save jumps to the first tab with an error.
   - "Where it runs" card: Pages row (Include / Exclude chip rows, chip remove, compact add
     row with dashed Add button), Visitors row (segmented Anyone / Logged in / Logged out,
     role chip-checkboxes for "Only these roles" and "Never for these roles").
   - Aside (sticky at lg): "In plain words" sentence built from the form, Priority input,
     "Prints before", create-only folder slug; dark `script.jsonc` card with the text that would
     be written and an Unsaved/Saved line.

Tests: `src/admin/__tests__/scripts/*` stay green; assertions change only for changed copy or
structure (tab switch before a snippet, "Save to file", "Script title", radio copy, chip labels).
New tests: empty-state templates, location chips, print-order aside, plain-words summary,
prints-before, tab jump on error.

## Phase 2: Redirects (after the UI follow-ups merge)

Component inventory (design → existing pieces):
- Header + tabs on the hairline → `PageHeader` gains a `nav` slot; Radix `Tabs` root now wraps the
  header so the `TabsList` sits in it. Per-tab description line. Rules tab shows the rule count.
- Toolbar → `Input` with search icon (sr-only label), `FilterPill` (Radix `Select` restyled as a pill:
  dashed "+ Code" unset, solid "Code 301" set), `GroupMenu`, Add redirect.
- Rules table → `Table`/`Panel`; Rule column (source, regex chip, arrow, target, condition pill;
  second line origin dot, title, "otherwise"); `CodeCell` (temp note, amber errors); Group; Hits
  (faint at 0); Last hit; On (`Switch`); Edit + "⋮" `Popover` menu (Move up/down, Duplicate, Delete).
  Disabled rows on the subtle wash. Footer: counts, file path when filtered to a group, ordering note.
- 404s → period chips (Radix radio items as pills) driving From/To, grouped table with "Redirect…",
  Ignore, delete; aside with hits, distinct paths, ignore-rule count and the retention note linking to
  Settings.
- Edit redirect dialog → from/to card (source with Regular expression check, condition and action
  selects on the divider, target + With status with its SEO hint), `<details>` Matching options,
  Title · optional + Group, footer Enabled `Switch` + Cancel / Save redirect, "Writes to <file>".
- Log, Import / Export, Settings (not in the design) → same search field, toolbar, table density,
  muted secondary columns; Import / Export in two columns (Export as the aside); Settings at
  max 960px with 24px gaps.

No new tokens beyond `--panel-key` (Phase 1).

## Rulings

- No "Preview as a different page or role" link, "Format" or "Open in theme" links: the design
  shows them but no such behaviour exists; adding dead links would mislead. The aside caption is
  reworded to what the list can know (print order, not per-page evaluation).
- "Prints before" names the next enabled script sharing a location, by (priority, slug).
- Redirects: the design's "Always / Logged in / Logged out / Referrer…" segmented control cannot hold
  the eleven condition types, so the divider holds the existing Match condition and Action selects.
- Redirects: the 404s period chips default to "All time" (the design shows 7 days) so the default
  request and every existing URL contract stay as they were.
- Redirects: no 404 count badge on the tab and no 7-day sparkline: the index route carries neither,
  and adding requests on every screen load would change the REST contract. "Redirects created from
  404s" is replaced by the count of ignore rules, which the index does carry.
- Redirects: the visible labels follow the design ("When a visitor requests", "Send them to", "With
  status", "Regular expression", tab "Rules"); tests updated to the new accessible names.
- Templates are client-side presets only (title, snippets, where); the server validates as for any
  new script.
