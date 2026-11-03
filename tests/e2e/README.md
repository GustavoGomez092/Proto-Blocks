# E2E tests (wp-env + Playwright)

Prerequisites: Docker running, Node, `npm install`.

```bash
npm run build                      # required first: the plugin is mounted from the repo root
npm run wp-env start               # dev site :8888, test site :8889 (admin / password)
npx playwright install chromium    # once
npm run test:e2e                   # run the suite against :8889
npm run test:e2e:ui                # interactive UI mode
npx playwright test --update-snapshots   # refresh screenshots (tests/e2e/__screenshots__)
npm run wp-env stop
```

Global setup logs in as admin (state saved to `tests/e2e/.auth/admin.json`) and enables
the `proto_blocks_example_blocks` option via `wp-env run tests-cli`.
Screenshot baselines are platform-specific (file names include the project and OS suffix,
e.g. `-chromium-darwin`). Baselines generated on macOS will not match on Linux; generate the
CI baselines on Linux (e.g. in the CI image/Docker) and commit those separately.
Reports: `playwright-report/`, failures: `test-results/` (both git-ignored).

Content model specs (`post-types`, `taxonomies`, `content-model-visual`) use the fixture themes
in `tests/e2e/fixtures/themes`, mounted at `wp-content/pb-e2e-fixtures` (the `mappings` key in
`.wp-env.json`; after changing it run `npm run wp-env start -- --update`). Each spec installs a
fresh copy into `wp-content/themes` (the screens write into the theme), activates it and
restores the previous theme afterwards. `tests/e2e/fixtures/parity.php` is the in-WordPress
parity check (`npx wp-env run tests-cli wp eval-file wp-content/pb-e2e-fixtures/parity.php`).
The committed content-model baselines were generated on macOS (`-chromium-darwin`).

The settings-page spec (`options-page`) uses the `pb-e2e-options` mu-plugin
(`tests/e2e/fixtures/mu-plugins`; the whole folder is mapped to `wp-content/mu-plugins`,
because a single-file mapping fails on a fresh environment under Docker Desktop for macOS,
so add further test mu-plugins to that folder). It adds
`wp-content/pb-e2e` (the same fixtures folder, mapped a second time) to
`proto_blocks_schema_paths`, so `tests/e2e/fixtures/proto-options` declares the
`theme-settings` and `shop-notices` pages, and `wp-content/pb-e2e/blocks` to
`proto_blocks_paths` for the `options-probe` block. On first load it also creates
a `product` type (without WooCommerce), the `pb_shop_manager` role
(`manage_woocommerce`, no `unfiltered_html`) and its test-site user `shopmanager`.
These add admin menu items on every screen, so full-page admin baselines include them.
The Fields module's `sanitize_option` trust callback strips markup for writers
without `unfiltered_html` (WP-CLI's default user 0), so the spec seeds option values
with `wp eval --user=admin`.

The Fields specs (`fields-boxes`, `fields-frontend`, `fields-perf`) activate the
`pb-e2e-fields` block child theme (`tests/e2e/fixtures/themes/pb-e2e-fields`, listed under
`themes` in `.wp-env.json` so it is mounted into `wp-content/themes`; after changing it run
`npm run wp-env start -- --update`) in `beforeAll` and restore the previous theme in
`afterAll`. The theme declares an `event` type, the realistic ACF export
(`proto-fields/acf-export`, a byte-for-byte copy of `tests/fixtures/fields/acf-export-realistic.json`),
the 50-field `perf` group, a term group (`category`), a user group, an `event-card` block and a
`single-event` template with a Block Bindings paragraph. The classic-editor runs toggle the
`proto_test_classic_editor` option (mu-plugin `proto-classic-editor.php`). Users `author1` and
`contributor1` are created per run and deleted afterwards. `fields-perf` prints the measured numbers
(mount time, keystroke p95/max) and records them as test annotations; budgets: mount <= 600 ms,
keystroke p95 <= 50 ms and max <= 100 ms, no picker chunk before the relationship "Add" button is used.
The Fields baselines (`fields-box-*`, `fields-term-form`) are macOS (`-chromium-darwin`).
