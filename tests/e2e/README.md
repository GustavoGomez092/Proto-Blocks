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
