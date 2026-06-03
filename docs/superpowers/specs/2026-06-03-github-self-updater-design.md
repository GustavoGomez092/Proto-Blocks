# Proto-Blocks GitHub Self-Updater — Design

**Date:** 2026-06-03
**Status:** Approved (design); pending implementation plan.

## Goal

Let any WordPress site running Proto-Blocks receive stable `vX.Y.Z` GitHub
releases through WordPress's normal plugin-update flow — no manual zip uploads.

**Success criteria**

- A site on an older version shows the "update available" badge on the Plugins
  screen and Dashboard → Updates.
- Clicking **Update now** downloads the release's `proto-blocks-X.Y.Z.zip`
  asset and installs it in place.
- After update, the plugin folder is still `Proto-Blocks` and the plugin stays
  active.
- When the site is already on the newest stable release, nothing is shown.
- If GitHub is unreachable or returns unexpected data, there is no PHP error and
  no false "update available".

## Context

- **Repo:** `GustavoGomez092/Proto-Blocks` — **public** (no auth needed to read
  releases or download assets; only unauthenticated GitHub API rate limits
  apply, which caching handles).
- **Release streams (already in place):**
  - `release.yml` cuts stable releases tagged **`vX.Y.Z`**, each carrying a
    built `proto-blocks-X.Y.Z.zip` asset plus generated notes.
  - `build-and-release.yml` keeps a **rolling `latest`** release (tag `latest`,
    "Latest build (main)") rebuilt on every push to `main`.
- **Installed plugin folder:** `Proto-Blocks` (capital P), basename
  `Proto-Blocks/proto-blocks.php`.
- **Release-asset zip internal folder:** `proto-blocks` (lowercase).
- **No existing updater code.**

Two facts drive the tricky parts of this design:

1. **The `latest` rolling release is _not_ flagged as a pre-release**, and
   GitHub's `/releases/latest` endpoint returns it (currently with a stale
   `proto-blocks-2.3.1.zip`) — **not** the newest `vX.Y.Z`. So we cannot use
   `/releases/latest`; we must list releases and select the newest
   semver-tagged one ourselves.
2. **The zip folder (`proto-blocks`) differs from the install folder
   (`Proto-Blocks`).** Left alone, WordPress would unzip to `proto-blocks/`,
   install it as a _separate_ plugin, and deactivate the original. The unzipped
   source folder must be renamed to `Proto-Blocks` during the upgrade.

## Non-goals (YAGNI)

- No opt-in beta/dev channel (the rolling `latest` build is ignored entirely).
- No authenticated GitHub requests / token support (public repo + caching make
  unauthenticated requests sufficient).
- No custom settings page or auto-background-install beyond what WordPress's
  standard updater already does.
- No third-party update library (e.g. plugin-update-checker) — hand-rolled.

## Architecture

A single self-contained class, `includes/Updater/GitHubUpdater.php`
(namespace `ProtoBlocks\Updater`), instantiated from `Core/Plugin.php` on
`admin_init` so it loads only in the admin and adds no front-end cost.

Configuration it reads (no hard-coded duplication):

| Value | Source |
|-------|--------|
| Repo owner / name | constants on the class: `GustavoGomez092` / `Proto-Blocks` |
| Plugin basename (`Proto-Blocks/proto-blocks.php`) | `plugin_basename(PROTO_BLOCKS_FILE)` |
| Install folder (`Proto-Blocks`) | `dirname(plugin_basename(PROTO_BLOCKS_FILE))` |
| Current version | `PROTO_BLOCKS_VERSION` |
| Asset name pattern | `proto-blocks-<version>.zip` (fallback: first `.zip` asset) |

State: a single transient (see Caching). No options/tables.

The class ships in the dist zip because it lives under `includes/` and is loaded
by the existing autoloader. The implementation plan must verify
`scripts/build-zip.js` includes `includes/Updater/`.

### Separation of concerns

The release-selection logic is a **pure static method** with no WordPress
dependencies:

```
GitHubUpdater::select_latest_stable(array $releases): ?array
```

Given the decoded GitHub `/releases` JSON, it returns the newest stable release
(or `null`). This is the unit-tested core; everything else (HTTP, transients,
WP hooks) is thin glue around it.

## Version detection

Endpoint: `GET https://api.github.com/repos/GustavoGomez092/Proto-Blocks/releases`
(default page of 30 is more than enough). Request via `wp_remote_get()` with a
`User-Agent` header (GitHub requires one) and a short timeout.

`select_latest_stable()` keeps only releases where **all** hold:

- `draft` is false,
- `prerelease` is false,
- `tag_name` matches `^v?\d+\.\d+\.\d+$` (this excludes the `latest` tag and the
  legacy `0.3` tag).

It picks the highest remaining release by `version_compare` on the normalized
(leading-`v`-stripped) tag, then selects that release's asset whose `name`
matches `proto-blocks-<version>.zip` (fallback: the first asset ending in
`.zip`). The returned payload is `{ version, package (asset browser_download_url),
html_url, body, published_at }`.

If no qualifying release-with-zip-asset exists, the result is `null` → treated as
"no update". We never fall back to GitHub's auto-generated source zipball (it
contains unbuilt `src/`, not the shipping plugin).

## Update flow

Three WordPress hooks:

1. **`pre_set_site_transient_update_plugins`** — read the cached latest release
   (see Caching); if its version is greater than `PROTO_BLOCKS_VERSION`, add an
   update entry to `$transient->response[ 'Proto-Blocks/proto-blocks.php' ]`
   with: `slug` (`proto-blocks`), `plugin` (basename), `new_version`,
   `package` (asset URL), `url` (release `html_url`). If not newer, add the
   plugin to `$transient->no_update` so WordPress shows it as up to date.

2. **`plugins_api`** (filter) — when WordPress requests `plugin_information` for
   our slug, return an object for the "View details" modal: `name`, `slug`,
   `version`, `author`, `homepage` (repo URL), `requires` (`6.3`),
   `requires_php` (`8.0`), `last_updated`, `download_link` (asset URL), and
   `sections['changelog']` built from the release `body` (minimal
   markdown→HTML: escape, then convert headings/lists/line breaks; no external
   parser).

3. **`upgrader_source_selection`** (filter) — during _our_ plugin's upgrade
   only, if the unzipped source folder is not already `Proto-Blocks`, rename it
   (e.g. `.../proto-blocks/` or `.../proto-blocks-2.4.0/` →
   `.../Proto-Blocks/`) so WordPress installs in place and the plugin stays
   active. Scope the rename by checking the in-progress upgrade is for our
   plugin (via the `$hook_extra['plugin']` basename passed to the filter) so it
   never touches other plugins' updates.

WordPress's own `Plugin_Upgrader` performs the download (public asset URL needs
no auth header), unzip, source-selection (our rename), and in-place install.

## Caching, rate limits, manual recheck

- The resolved latest-release payload is cached in a **12-hour transient**,
  `proto_blocks_github_release`. `pre_set_site_transient_update_plugins` reads
  the transient and only calls the API on a cache miss — so routine admin page
  loads make no network calls.
- Unauthenticated GitHub API allows 60 requests/hour/IP; at ~2 checks/day/site
  this is comfortable.
- WordPress's **"Check again"** (force-check) on the Updates screen bypasses the
  cache: when a force-check is detected, delete the transient before resolving.
- A **"Check for updates"** row-action link on the Plugins screen deletes the
  transient and triggers a re-check (`wp_update_plugins()`), then returns to the
  Plugins screen.

## Error handling

- Any failure path — network timeout, non-200 status, malformed/empty JSON,
  no qualifying release, missing zip asset — returns the last cached value if
  present, otherwise "no update". Never a fatal, never a false update.
- The `package`/`download_link` URL is validated to a `github.com` or
  `objects.githubusercontent.com` (release-asset CDN) host before use; anything
  else is rejected.
- All values rendered into admin UI / the details modal are escaped.
- Failures are silent to end users (optionally noted via `error_log` when
  `WP_DEBUG` is on); they are not surfaced as admin notices.

## Testing

**Unit (pure logic, no WordPress):** test `select_latest_stable()` against
fixtures:

- Multiple `vX.Y.Z` releases → picks the highest by semver (not by date).
- The `latest` tag present among them → excluded.
- A pre-release / draft present → excluded.
- Newest release missing a `proto-blocks-*.zip` asset → falls back to first
  `.zip`, or `null` if none.
- Empty list / malformed entries → `null`, no error.

(Mirror the existing `npm run test:scripts` pattern if a PHP harness is added;
otherwise a standalone PHP assertion script under `tests/` invoked manually.)

**Manual QA matrix (real site):**

1. Installed == newest stable → no badge; shown as up to date.
2. Installed < newest stable → badge appears; **Update now** succeeds; afterward
   the folder is still `Proto-Blocks` and the plugin is still active; version
   reflects the new release.
3. GitHub unreachable (simulate via blocked host / bad URL) → no PHP error, no
   false update.
4. Force-check ("Check again") and the Plugins-screen "Check for updates" link
   both bypass the 12h cache.

## Open implementation notes (for the plan)

- Confirm `scripts/build-zip.js` includes `includes/Updater/` in the dist zip.
- Decide the exact autoload/instantiation point in `Core/Plugin.php`.
- Pick the minimal markdown→HTML approach for the changelog section.
