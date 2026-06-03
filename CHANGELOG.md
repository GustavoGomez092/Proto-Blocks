# Changelog

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
