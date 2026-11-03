# Changelog

## Unreleased — admin rebuild

### Changed
- **Admin rebuilt in React + Tailwind 4 + shadcn/ui.** The Blocks, Tailwind
  Settings, System Status, Block Previews and Setup Wizard screens are one
  React app (`assets/js/admin.js`, `assets/css/admin.css`); no admin screen is
  rendered by PHP any more.
- **PHP 8.1 is now required** (`Requires PHP: 8.1`).

### Added
- REST routes under `proto-blocks/v1/admin/*`: `dashboard`, `cache`,
  `example-blocks`, `demos/install`, `demos/remove`, `tailwind`,
  `tailwind/settings`, `tailwind/compile`, `tailwind/cache`, `tailwind/cli`,
  `tailwind/theme-file`, `system`, `previews`, `wizard`, `wizard/style`,
  `wizard/demos`, `wizard/complete`.
- E2E harness (wp-env + Playwright): `npm run test:e2e`.

### Removed
- Tailwind v3 (the frozen `assets/css/admin-legacy.css` build) and the Google
  Fonts and Material Icons admin stylesheets.
- Per-control `admin-ajax.php` actions. Only
  `proto_blocks_tailwind_get_compile_inputs` and
  `proto_blocks_tailwind_store_css` remain, for the in-browser Tailwind compiler.
- `Plugin::getTailwindAdminSettings()` and the PHP page renderers
  (`AdminPage::renderSettingsPage`, `SetupWizard::renderWizard`,
  `PreviewCapture::renderAdminUi`, `Tailwind\AdminSettings::render`).

## Unreleased — security hardening

### Security
- **Renderer XSS (high).** `Renderer::render()` ran `html_entity_decode()`
  over every block's finished HTML, undoing the `esc_html()`/`esc_attr()`
  templates had applied. Any block echoing visitor input (for example
  `get_search_query()`) was a reflected XSS and any contributor-controlled
  attribute a stored one. Replaced with a per-entity decoder that restores
  libxml's non-ASCII entities but never decodes `< > & " '`. The only visible
  output change is `&#038;` now reaching the browser as `&amp;`.
- **Attribute trust boundary.** Block attributes live in the block-comment
  JSON, which WordPress kses does not touch. The plugin now sanitises
  Proto-Blocks attributes on save (`wp_insert_post_data`) and again at render
  (`render_block_data`) for authors who lack `unfiltered_html`: wysiwyg fields
  get `wp_kses_post`, text fields an inline-formatting allowlist, URL keys
  `esc_url_raw`, `rel`/`target`/`anchor` normalised. Trust is recorded per
  post in `_proto_blocks_unfiltered` by whoever last saved it (legacy posts
  fall back to the author's capability); template parts render as trusted
  and synced patterns use their own post's trust. Disable with
  `add_filter('proto_blocks_sanitize_attributes', '__return_false')`.
  Caveat: a bare `<` typed by such a user is stored as `&lt;`, as in post content.
- **Editor.** Stored attribute strings are laundered client-side before they
  reach `RichText`, SVGs in previews are rebuilt as React elements instead of
  `innerHTML`, block icons are sanitised, and link URLs are scheme-checked.
- **Fields layer.** Link text and the inner-blocks attribute fallback no longer
  insert raw markup; `rel="noopener"` is forced on `_blank`; video `mime` is
  validated; repeaters cap rendered rows (`max`, default 500, filter
  `proto_blocks_repeater_max_items`).
- **Wrapper attributes.** Block `style` support values go through
  `safecss_filter_attr()`; align/colour/font-size slugs through
  `sanitize_html_class()`.
- **Template cache.** Cached parser output is now JSON under a salted file
  name instead of a PHP file `include`d from `uploads/`. Legacy `.php` cache
  files are deleted, never included. Cache directories get `index.php` and a
  deny-all `.htaccess`.
- **Tailwind.** Compile inputs (`content.html` with every template's source,
  `input.css`) no longer persist in a web-readable directory. The CLI binary is
  verified against GitHub's published SHA-256 digest on download and re-hashed
  before every execution (existing installs must re-download once). The `npx`
  fallback is opt-in (`PROTO_BLOCKS_TAILWIND_ALLOW_NPX`) and pinned. On-reload
  compiles only run for logged-in administrators. `store_css` is size-capped
  and validated. New constants: `PROTO_BLOCKS_TAILWIND_CLI_VERSION`,
  `PROTO_BLOCKS_TAILWIND_CLI_SHA256`, `PROTO_BLOCKS_TAILWIND_ALLOW_UNVERIFIED`.
- **Updater.** Release zips are published with a SHA-256 (release body and
  `.sha256` asset) and verified before install; package URLs must be
  `https://github.com/GustavoGomez092/Proto-Blocks/releases/download/…`; the
  exact `proto-blocks-<version>.zip` asset is required; `Update URI` header
  added so wordpress.org can never supply an update for this slug.
- **REST / options providers.** `/preview` applies the same sanitiser as the
  AJAX preview; error bodies are generic unless `PROTO_BLOCKS_DEBUG`;
  `wp:users` never searches email/login and lists only published authors to
  callers without `list_users`; `wp:posts`/`wp:terms` require viewable types.
  Absolute server paths are no longer exposed to the editor or REST.
- **Admin.** Setup wizard requires `manage_options` before its nonce check;
  demo-block install/remove verifies a manifest so a same-named custom block
  is never deleted; preview-capture validates PNG/JPEG bytes and size and
  honours `DISALLOW_FILE_MODS`; capture iframe is sandboxed.
- **CLI / examples / docs.** Scaffolded inner-blocks templates echo
  `$innerBlocksContent` (was an unescaped `$content`); slugs and field names
  are validated; colour controls in example templates use
  `sanitize_hex_color()` and `url()` values are encoded via the new
  `proto_blocks_css_url()`; all example templates carry an ABSPATH guard;
  README gains an "Escaping in templates" reference.
- **CI.** GitHub Actions pinned to commit SHAs, minimal `permissions`.

### Removed
- The never-mounted admin React app (`assets/js/admin.js`) is no longer built
  or enqueued.

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
