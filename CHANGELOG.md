# Changelog

## 2.5.3 — 2026-06-03

### Fixed
- Template cache now stores its files under `wp-content/uploads`
  (`uploads/proto-blocks/templates/`, alongside the Tailwind cache) instead
  of `wp-content/cache`. Managed hosts such as WP Engine own and purge
  `wp-content/cache`, so cached templates never persisted there (the
  dashboard showed 0). Falls back to `wp-content/cache` if the uploads dir
  is unavailable, and the location is filterable via `proto_blocks_cache_dir`.

## 2.5.2 — 2026-06-03

### Fixed
- The self-updater now clears its cached release and WordPress's
  plugin-update cache after the plugin updates (via `upgrader_process_complete`),
  so a stale "update available" prompt doesn't linger after a successful update.

## 2.5.1 — 2026-06-03

### Fixed
- Pin the GitHub REST API version (`X-GitHub-Api-Version: 2022-11-28`) on the
  self-updater's releases request, guarding against future API default changes.

## 2.5.0 — 2026-06-03

### Added
- GitHub self-updater: surfaces stable `vX.Y.Z` releases through WordPress's
  native plugin-update flow (transient-cached, no API key, no third-party
  library). Adds a "Check for updates" link on the Plugins screen and a
  git-checkout safety guard (the updater no-ops on a git working copy). On
  update, the release zip's lowercase `proto-blocks` folder is renamed to the
  installed folder so the plugin stays active.

## 2.4.0 — 2026-06-03

### Fixed
- Templates now receive `$block` (the `WP_Block` instance on the frontend,
  `null` in the editor preview), so the documented preview-detection
  (`$is_preview = ! isset($block)`) works. Previously `$block` was never passed,
  so `$is_preview` was always true and any frontend-only branch never ran.

### Added
- Reveal runtime: a frontend-only, dependency-free script/style implementing the
  `data-proto-animate` lifecycle (`pending`/`manual` → `done`) with a hard
  guarantee that content is never left hidden (scroll reveal, `prefers-reduced-motion`,
  no-JS `<noscript>` fallback, and a watchdog for failed/absent block JS).
- `docs/animation.md` — authoring guide for the convention.
- Legacy `data-animate` accepted as an alias of `data-proto-animate`.
- Repeater item-level link editing. When a repeater declares a `link`
  sub-field that is **not** bound to an inline `data-proto-field` element,
  a link button now appears in the item's overlay toolbar (URL + "open in
  new tab"). This makes whole-card links (the entire item is the `<a>`)
  and icon-only links (an `<a>` with no editable text) editable, where
  previously there was no UI to set the URL. Inline `data-proto-field="link"`
  binding continues to work as before; the toolbar control is suppressed
  when the link is already bound inline, so a field never gets two editors.

### Changed
- The repeater "add between" (`+`) button is now teleported through a
  portal into the editor's top-level popover layer instead of living
  inside the item. An item with `overflow: hidden` (rounded corners,
  inner shadows) can no longer clip it.
- The "add between" button placement now follows the repeater's rendered
  flow direction — right edge for items laid out in a row, bottom edge for
  stacked items — measured from geometry, so flex rows, CSS grids, and
  wrapped grids all place it sensibly.
