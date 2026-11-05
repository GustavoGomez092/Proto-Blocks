# Changelog

## [2.16.0] - Unreleased

### Added
- **Redirects module.** Redirect rules and settings are JSONC files in the theme (`proto-redirects/<group>/redirects.jsonc`, `proto-redirects/settings.jsonc`) or `proto_redirect()` / `proto_redirect_group()` in PHP. Actions `url`, `error`, `pass`, `nothing`; conditions login, role, referrer, agent, header, cookie, IP/CIDR, language, server, filter and 404; query modes, case and trailing-slash flags; regex with capture groups; groups with ordering; hit counts, redirect log and 404 log (three tables, the only runtime data; no rule or setting is stored); permalink monitor (single sites only: on multisite it never writes the shared theme); custom headers and canonical https/www; import from Redirection, CSV, JSON, Apache and `_redirects`, export to CSV, JSON, Apache, Nginx and `_redirects`; Proto Blocks > Redirects screen; `wp proto-blocks redirect`; REST routes under `admin/redirects`. Open-redirect, loop and regex guards. With `log.external`, redirects issued by other code through `wp_redirect()` are logged too (`x_redirect_by`). See `docs/redirects.md`.
- **Scripts module.** Head, body-open and body-close snippets as files (`proto-scripts/<slug>/script.jsonc` plus `head.html`, `body-open.html`, `body-close.html`) or `proto_script()` in PHP, with include/exclude placement targets, logged-in and role rules, per-location priorities, an admin screen, `wp proto-blocks script` and REST routes under `admin/scripts`. Writing needs `unfiltered_html`. See `docs/scripts.md`.
- Filters `proto_blocks_redirects_excluded`, `proto_blocks_redirects_priority`, `proto_blocks_redirects_canonical_target`, `proto_blocks_redirects_log_404`, `proto_blocks_scripts_markers`, `proto_blocks_scripts_enabled`; action `proto_blocks_redirects_nothing`; module keys `redirects` and `scripts` in `proto_blocks_modules`.

### Changed
- Shared test stubs for the new modules live in `tests/php/bootstrap.php`.

## 2.15.0 — 2026-11-03

### Admin rebuild

#### Changed
- **Admin rebuilt in React + Tailwind 4 + shadcn/ui.** The Blocks, Tailwind
  Settings, System Status, Preview Capture and Setup Wizard screens are one
  React app (`assets/js/admin.js`, `assets/css/admin.css`); no admin screen is
  rendered by PHP any more.
- **PHP 8.1 is now required** (`Requires PHP: 8.1`).
- Demo block installation removed from the admin UI (code retained). The
  Blocks screen keeps the Example Blocks switch; the setup wizard is now
  Welcome, Choose your style, Finish.
- File-writing admin routes (`demos/install`, `demos/remove`,
  `tailwind/cli`, `tailwind/theme-file`, `wizard/demos`) and
  `POST /preview-capture` answer `403 proto_blocks_file_mods` when
  `DISALLOW_FILE_MODS` or `DISALLOW_FILE_EDIT` is set, and the Tailwind CLI
  is never downloaded in that state (including from `tailwind/compile`).
- Admin utility classes are scoped to the admin app root, so they no longer
  match core or third-party markup on Proto-Blocks screens.

#### Added
- REST routes under `proto-blocks/v1/admin/*`: `dashboard`, `cache`,
  `example-blocks`, `demos/install`, `demos/remove`, `tailwind`,
  `tailwind/settings`, `tailwind/compile`, `tailwind/cache`, `tailwind/cli`,
  `tailwind/theme-file`, `system`, `previews`, `wizard`, `wizard/style`,
  `wizard/demos`, `wizard/complete`.
- E2E harness (wp-env + Playwright): `npm run test:e2e`.

#### Removed
- Tailwind v3 (the frozen `assets/css/admin-legacy.css` build) and the Google
  Fonts and Material Icons admin stylesheets.
- Per-control `admin-ajax.php` actions. Four remain:
  `proto_blocks_tailwind_get_compile_inputs` and
  `proto_blocks_tailwind_store_css` (the in-browser Tailwind compiler),
  `proto_blocks_preview` (editor block previews) and
  `proto_blocks_capture_render` (the Preview Capture iframe).
- `Plugin::getTailwindAdminSettings()` and the PHP page renderers
  (`AdminPage::renderSettingsPage`, `SetupWizard::renderWizard`,
  `PreviewCapture::renderAdminUi`, `Tailwind\AdminSettings::render`).

### Phase 1: post types and taxonomies

#### Added
- **Post types and taxonomies from one line of PHP or a JSONC file.**
  `proto_post_type('Book')` / `proto_taxonomy('Genre', ['post_types' => ['book']])`
  in the theme, or `proto-types/<slug>/type.jsonc` and
  `proto-taxonomies/<slug>/taxonomy.jsonc` (comments and trailing commas
  allowed, `.json` too). Plurals, slugs and every admin label are generated;
  defaults follow the spec (public, REST, archive, `with_front` off, menu
  position 22). Child theme folders win over the parent; extra roots via
  `proto_blocks_schema_paths`. A file beats a PHP call for the same slug and
  both are flagged ("Declared twice" badge, admin notice, `double_declaration`
  in `wp proto-blocks validate`).
- **Overrides of core and plugin types and taxonomies**: a declaration for an
  already registered slug (or `post`, `page`, `attachment`, `category`,
  `post_tag`) applies only its declared keys, e.g. a starter `template` for
  posts.
- Menu icons from a dashicon, an `https://` URL or a theme file; theme SVGs are
  sanitised on the server and inlined as `data:` URIs.
- Rewrite rules flush automatically when the declarations change.
- **Post Types and Taxonomies admin screens** (Proto Blocks menu): every
  registered type and taxonomy with its origin, source and counts; edit forms
  that save back to the JSONC file keeping comments; create and delete;
  confirmation before a save that affects existing content; block template
  scaffolding (`single-<slug>.html`, `archive-<slug>.html`, never
  overwriting; off on classic themes); read-only under `DISALLOW_FILE_MODS` /
  `DISALLOW_FILE_EDIT`.
- REST routes `proto-blocks/v1/admin/types`, `admin/types/<slug>`,
  `admin/types/<slug>/scaffold`, `admin/taxonomies`, `admin/taxonomies/<slug>`
  and `proto-blocks/v1/admin/schema-files/<type|taxonomy>/<slug>`.
- **`wp proto-blocks type list|create|scaffold|validate|rename` and
  `wp proto-blocks taxonomy list|create|validate|rename`**;
  `wp proto-blocks validate` also reports declarations.
- `proto_blocks_modules` filter to switch the post type and taxonomy modules
  off.
- E2E coverage for the content model (fixture block and classic themes,
  in-WordPress parity check against two live sites' hand-written
  registrations, visual baselines for both screens).

#### Security
- Declaration files can no longer name PHP callbacks or classes
  (`register_meta_box_cb`, `rest_controller_class`, ...); such keys are
  stripped with a warning when loading and refused when saving.
- Labels in declaration files may not contain `<` or `>`.
- On multisite only network administrators can write declaration files
  (`403 proto_blocks_multisite`).

### Phase 2: custom fields

#### Added
- **Custom fields** for posts, custom post types, taxonomy terms, users and
  settings pages. Groups are ACF-shaped, declared as
  `proto-fields/<slug>/group.jsonc` files, inline `fields` in type, taxonomy
  and page files, or `proto_field_group()` in PHP. Guide: `docs/fields.md`.
- Field types (text variants, wysiwyg, image, file, gallery, video,
  link, select, radio, true/false, color, date/time/datetime, taxonomy,
  relationship, group, repeater, flexible content, code, password) plus
  `tab`, `accordion` and `message` layout fields; one registered meta row
  per field with a REST schema (`show_in_rest` per field).
- Meta boxes in the block editor, classic editor and Elementor, and on term
  and user forms, with client and server validation and conditional logic.
- Getter `proto_field()`, `proto_fields()`, `proto_rows()` and
  `$template->field()`, escaped per type (`$raw = true` to opt out);
  `get_field()`, `the_field()` and `get_fields()` aliases when ACF is
  inactive.
- Block Bindings source `proto-blocks/field` (`"bindings": false` opts a
  field out).
- Fields builder at Proto Blocks > Fields: field rows with drag and keyboard
  reorder, group settings, location and condition builders, a JSONC pane,
  new-group dialog and Save to file.
- ACF import: local JSON and Tools exports work unchanged; ACF-registered
  groups with the same key are skipped with a notice.
- `wp proto-blocks fields list|validate`; filter
  `proto_blocks_repeater_max_items` (default 500).

#### Changed
- **WordPress 6.5 is now the minimum** (Block Bindings).
- Post types that receive fields gain `custom-fields` support; field keys
  are protected meta.

#### Security
- `code` and `password` fields are written only by users with
  `unfiltered_html`; other values are sanitised per type at write time.
- The getter does no read-permission check; never pass visitor input as
  `$context`. Bindings exclude `code` and `password`, and options-page
  fields are bindable by any author unless `"bindings": false`.
- User fields are not exposed over REST unless the field sets
  `"show_in_rest": true`.
- A lowered `proto_blocks_repeater_max_items` never truncates stored rows:
  the box shows them with a warning and the save is refused until rows are
  removed.

### Phase 3: settings pages

#### Added
- **Settings pages from one line of PHP or a JSONC file.**
  `proto_options_page('Theme Settings', [...])` or
  `proto-options/<slug>/page.jsonc`: top-level or under any menu, tabs, child
  pages, the page's own capability (default `manage_options`). Values are
  stored per field as `<page_slug>_<field_name>`, or in one array option with
  `storage: { mode: "array", option: "..." }`; protected core options are
  refused.
- `proto_field('x', 'option:<page_slug>')` reads page values in either
  storage mode.
- REST route `proto-blocks/v1/options/<slug>` (GET/POST) gated on the page
  capability.
- `options_pages` key in the `proto_blocks_modules` filter; settings pages
  keep working with the Fields module off.
- **Proto Blocks > Options Pages** screen: lists the settings pages (and,
  read-only, other plugins' settings pages) and edits a page's declaration,
  writing `proto-options/<slug>/page.jsonc` (comments inside an edited array
  are lost). New pages take a title, then the form; PHP pages get
  "Create file"; deleting removes the file and keeps saved values.
- `wp proto-blocks options list` and
  `wp proto-blocks options create "<Title>" [--slug] [--menu] [--capability] [--storage-option]`.
- `wp proto-blocks validate` reports settings-page findings
  (`shared_storage`, schema errors, skipped fields; menu warnings such as unknown capabilities appear only in the admin).

### Security hardening

#### Security
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

#### Removed
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
