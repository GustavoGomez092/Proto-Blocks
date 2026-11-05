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

(filled in while executing)
