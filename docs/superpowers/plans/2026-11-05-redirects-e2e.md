# Redirects E2E (track G2) plan

Spec: docs/superpowers/specs/2026-11-05-redirects-design.md §14 (Playwright, edge cases).

## Tasks

1. Fixture mu-plugin `tests/e2e/fixtures/mu-plugins/pb-e2e-redirects.php`: `proto_redirect()` while the option `pb_e2e_redirects_php` holds a source path (nothing declared otherwise).
2. Helpers `tests/e2e/redirects-utils.ts`: group/settings file writers into the pb-e2e-block copy, `raw()` request (`maxRedirects: 0`), DB clean-up through `wp eval`, chmod helpers, anonymous/logged-in request contexts.
3. `tests/e2e/redirects.spec.ts`:
   - HTTP: url 301, 302 + `query: pass`, 410, pass, nothing (plain 404, not logged), regex capture, ignoreCase / ignoreTrailingSlash on and off, `when` login (else) / referrer regex / header, `when: 404`, excluded `/wp-json/` and `/wp-login.php`, POST + 308, self-loop, `//evil.example` capture collapsed to a path, hit counts.
   - Edge cases: `%2F`, `//double//slash`, root `/` trailing slash, unicode `/café` vs `/caf%C3%A9`, `a[]=1&a[]=2`, fragment in target with `query: pass`, HEAD.
   - UI: 404 → Add redirect (prefill, file written, redirect works, 404 rows gone); create / edit / toggle / move / duplicate / delete rules and groups with the JSONC read back and a hand comment kept; Test box trace; PHP badge and Declared twice; CSV import (preview, duplicate mode); exports (download event + request API headers, raw body); settings save and `log.notFound: -1`; log tab with rule links; read-only when the group folder is unwritable; permalink monitor.
4. `tests/e2e/redirects-visual.spec.ts`: one baseline per tab (macOS).
5. Regenerate the four stale sidebar baselines (content-model-visual post types list + taxonomies list, fields-builder location + JSONC) in a separate commit.
6. README section; merge `feat/redirects-scripts`, rebuild, full `npm run test:e2e`.

## Rulings

- **Raw requests.** `request.fetch` with `maxRedirects: 0` and an absolute URL (a path starting with `//` would otherwise be read as a host). Assertions compare `{status, location, by}` with `X-Redirect-By: proto-blocks`, so core's own redirects (old slugs, canonical) cannot pass for ours.
- **`%2F`.** wp-env's Apache refuses an encoded slash with its own 404 before PHP runs (AllowEncodedSlashes Off). The test pins that and checks the engine's decoding through `GET admin/redirects/test?url=/a%2Fb`.
- **Open-redirect capture.** Request paths have `//` collapsed before matching, so the capture that would build `//evil.example` comes from the query (`^/pb-e2e-out\?u=(.*)$`, regex rules match `path?query`).
- **Read-only.** Docker Desktop for macOS ignores mode bits on the bind-mounted site (`test -w` says no, PHP's `is_writable()` says yes and writes succeed), so the chmod test skips itself when `createTarget.writable` stays true; it runs on Linux Docker. A second test always runs: the mu-plugin adds a filter root holding the `e2e-locked` group (`readOnlyReason: read_only`), whose controls are disabled while it still redirects.
- **pass body.** The fixture theme's index template prints titles only, so the pass target is recognised by its linked page title.
- **Declared twice per rule.** A PHP rule dropped for a file twin only left a `double_declaration` issue on the file rule; the origin badge read the group-level `double` only. Fixed in `RedirectsTab` (`doubleOf`), with a Jest test.
- **CSV export** uses CRLF line ends (RFC 4180); the test splits on `\r\n`.
- **Stale baselines.** Only the four named captures were regenerated (`-g` scoped); a pixel comparison of old and new shows no change right of the 160px sidebar above the footer band.
