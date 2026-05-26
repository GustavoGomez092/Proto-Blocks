# Automated Semver Release Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On every push to `main`, auto-detect the semver bump from Conventional Commits, update all version locations, commit + tag, and publish a versioned GitHub release with the built zip.

**Architecture:** A new `.github/workflows/release.yml` (separate from the unchanged `build-and-release.yml` rolling-"latest" workflow) orchestrates the release. The only non-trivial logic — classifying the bump and computing the next version — lives in a pure, unit-tested ESM module `scripts/next-version.mjs`, tested with Node's built-in test runner (no jest/babel/transform needed for `.mjs`).

**Tech Stack:** GitHub Actions, Node 20, `node --test` (built-in test runner), `gh` CLI, bash/sed.

**Design doc:** `docs/superpowers/specs/2026-05-25-ci-auto-semver-release-design.md`

**Spec refinement (intentional):** the spec sketched `nextVersion(current, subjects, bodies)`. This plan uses a single `messages: string[]` array of full commit messages (subject = first line; the whole message is scanned for `BREAKING CHANGE`). Same behavior, simpler interface and CLI piping.

## Version locations (the three the bump must update)
- `proto-blocks.php` — the ` * Version: 1.0.0` plugin-header line
- `proto-blocks.php` — `define('PROTO_BLOCKS_VERSION', '1.0.0');`
- `package.json` — `"version": "1.0.0"`

## File structure

| File | Responsibility | Action |
|------|----------------|--------|
| `scripts/next-version.mjs` | Pure `nextVersion(current, messages)` classifier + semver math, plus a CLI (env `CURRENT_VERSION` + commit messages on stdin → `version=`/`type=` stdout). | Create (Task 1) |
| `scripts/next-version.test.mjs` | `node --test` unit tests for classification + version math. | Create (Task 1) |
| `package.json` | Add `"test:scripts": "node --test scripts/"`. | Modify (Task 1) |
| `.github/workflows/release.yml` | The release orchestration workflow. | Create (Task 2) |

---

## Task 1: `next-version.mjs` — bump logic (TDD, `node --test`)

**Files:**
- Create: `scripts/next-version.mjs`
- Test: `scripts/next-version.test.mjs`
- Modify: `package.json` (scripts)

- [ ] **Step 1: Add the `test:scripts` npm script**

In `package.json` `"scripts"`, add after `"test:js"`:
```json
"test:scripts": "node --test scripts/",
```

- [ ] **Step 2: Write the failing test `scripts/next-version.test.mjs`**

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { nextVersion } from './next-version.mjs';

test('feat → minor bump', () => {
    assert.deepEqual(nextVersion('1.2.3', ['feat: add thing']), {
        version: '1.3.0',
        type: 'minor',
    });
});

test('fix → patch bump', () => {
    assert.deepEqual(nextVersion('1.2.3', ['fix: a bug']), {
        version: '1.2.4',
        type: 'patch',
    });
});

test('feat! subject → major bump', () => {
    assert.deepEqual(nextVersion('1.2.3', ['feat!: drop old API']), {
        version: '2.0.0',
        type: 'major',
    });
});

test('scoped type with ! → major', () => {
    assert.deepEqual(nextVersion('1.2.3', ['refactor(core)!: rework']), {
        version: '2.0.0',
        type: 'major',
    });
});

test('BREAKING CHANGE in body → major', () => {
    assert.deepEqual(
        nextVersion('1.2.3', ['feat: x\n\nBREAKING CHANGE: removes y']),
        { version: '2.0.0', type: 'major' }
    );
});

test('scoped feat → minor', () => {
    assert.deepEqual(nextVersion('1.2.3', ['feat(editor): add panel']), {
        version: '1.3.0',
        type: 'minor',
    });
});

test('highest precedence wins (feat + fix → minor)', () => {
    assert.deepEqual(nextVersion('1.2.3', ['fix: a', 'feat: b']), {
        version: '1.3.0',
        type: 'minor',
    });
});

test('breaking beats feat (→ major)', () => {
    assert.deepEqual(nextVersion('1.2.3', ['feat: a', 'feat!: b']), {
        version: '2.0.0',
        type: 'major',
    });
});

test('only chore/docs → null (no release)', () => {
    assert.equal(
        nextVersion('1.2.3', ['chore: deps', 'docs: readme', 'refactor: tidy']),
        null
    );
});

test('empty commit list → null', () => {
    assert.equal(nextVersion('1.2.3', []), null);
});
```

- [ ] **Step 3: Run the test, verify it fails**

Run: `npm run test:scripts`
Expected: FAIL — cannot find module `./next-version.mjs`.

- [ ] **Step 4: Implement `scripts/next-version.mjs`**

```js
/**
 * Conventional-Commits → next semver version.
 *
 * Pure logic (no git, no I/O) plus a thin CLI used by the release workflow.
 *
 * Rules, evaluated across all commit messages since the last release:
 *   - subject `type!:` (optionally scoped) OR `BREAKING CHANGE` anywhere → major
 *   - subject `feat:` (optionally scoped) → minor
 *   - subject `fix:`  (optionally scoped) → patch
 *   - none of the above → null (no release)
 * Highest-precedence match across all commits wins.
 */

import { readFileSync, realpathSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const RANK = { patch: 1, minor: 2, major: 3 };

/**
 * @param {string} current   Current semver, e.g. "1.2.3".
 * @param {string[]} messages Full commit messages (subject on the first line).
 * @returns {{version: string, type: 'major'|'minor'|'patch'} | null}
 */
export function nextVersion(current, messages) {
    let bump = null;

    for (const message of messages) {
        const subject = (message.split('\n')[0] || '').trim();
        let type = null;

        if (/^\w+(\(.+\))?!:/.test(subject) || /BREAKING CHANGE/.test(message)) {
            type = 'major';
        } else if (/^feat(\(.+\))?:/.test(subject)) {
            type = 'minor';
        } else if (/^fix(\(.+\))?:/.test(subject)) {
            type = 'patch';
        }

        if (type && (bump === null || RANK[type] > RANK[bump])) {
            bump = type;
        }
    }

    if (bump === null) {
        return null;
    }

    const [major, minor, patch] = current
        .split('.')
        .map((n) => parseInt(n, 10) || 0);

    const version =
        bump === 'major'
            ? `${major + 1}.0.0`
            : bump === 'minor'
              ? `${major}.${minor + 1}.0`
              : `${major}.${minor}.${patch + 1}`;

    return { version, type: bump };
}

// CLI: only when executed directly (never during `import` in tests).
const invokedDirectly =
    process.argv[1] &&
    realpathSync(process.argv[1]) === fileURLToPath(import.meta.url);

if (invokedDirectly) {
    const current = process.env.CURRENT_VERSION || '0.0.0';
    let raw = '';
    try {
        raw = readFileSync(0, 'utf8'); // stdin
    } catch {
        raw = '';
    }
    // Commit messages are piped in, separated by the ASCII record separator
    // (0x1e) so multi-line bodies stay intact.
    const messages = raw
        .split('\x1e')
        .map((s) => s.trim())
        .filter(Boolean);

    const result = nextVersion(current, messages);
    if (result) {
        process.stdout.write(`version=${result.version}\ntype=${result.type}\n`);
    }
}
```

- [ ] **Step 5: Run the test, verify it passes**

Run: `npm run test:scripts`
Expected: PASS — 10 tests pass (`# pass 10`).

- [ ] **Step 6: Smoke-test the CLI directly**

Run:
```bash
printf 'feat: add a thing\x1efix: a bug' | CURRENT_VERSION=1.0.0 node scripts/next-version.mjs
```
Expected output:
```
version=1.1.0
type=minor
```
Run the no-release case:
```bash
printf 'chore: deps' | CURRENT_VERSION=1.0.0 node scripts/next-version.mjs
```
Expected: no output (empty), exit 0.

- [ ] **Step 7: Commit**

```bash
git add scripts/next-version.mjs scripts/next-version.test.mjs package.json
git commit -m "feat(ci): conventional-commits next-version resolver with tests"
```

---

## Task 2: `release.yml` — the release workflow

No automated test (a GitHub Actions workflow). Verified by YAML sanity + careful construction; the bump logic it depends on is unit-tested in Task 1 and re-run in CI before any release. The existing `build-and-release.yml` is NOT modified.

**Files:**
- Create: `.github/workflows/release.yml`

- [ ] **Step 1: Create `.github/workflows/release.yml`**

```yaml
name: Release

on:
  push:
    branches: [main]
  workflow_dispatch:

permissions:
  contents: write

concurrency:
  group: release-main
  cancel-in-progress: false

jobs:
  release:
    runs-on: ubuntu-latest
    timeout-minutes: 15
    steps:
      - name: Checkout
        uses: actions/checkout@v4
        with:
          fetch-depth: 0 # need full history + tags to diff commits since last release

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - name: Install dependencies
        run: npm ci || npm install --no-audit --no-fund

      - name: Test version logic
        run: npm run test:scripts

      - name: Determine version bump
        id: bump
        run: |
          set -euo pipefail

          # Loop guard: never release off our own release commit.
          SUBJECT=$(git log -1 --format=%s)
          if printf '%s' "$SUBJECT" | grep -q '^chore(release):'; then
            echo "Release commit detected; skipping."
            echo "release=false" >> "$GITHUB_OUTPUT"
            exit 0
          fi

          LAST_TAG=$(git describe --tags --abbrev=0 --match 'v*' 2>/dev/null || true)
          if [ -n "$LAST_TAG" ]; then
            RANGE="${LAST_TAG}..HEAD"
          else
            RANGE="HEAD"
          fi

          CURRENT=$(node -p "require('./package.json').version")

          # Pipe full commit messages (record-separated by 0x1e) to the resolver.
          OUT=$(git log "$RANGE" --format='%B%x1e' \
            | CURRENT_VERSION="$CURRENT" node scripts/next-version.mjs)

          if [ -z "$OUT" ]; then
            echo "No release-worthy commits since ${LAST_TAG:-start}; skipping."
            echo "release=false" >> "$GITHUB_OUTPUT"
          else
            echo "release=true" >> "$GITHUB_OUTPUT"
            echo "$OUT" >> "$GITHUB_OUTPUT"   # version=X.Y.Z and type=...
            echo "Bumping ${CURRENT} -> $(echo "$OUT" | sed -n 's/^version=//p')"
          fi

      - name: Bump version files
        if: steps.bump.outputs.release == 'true'
        env:
          NEW: ${{ steps.bump.outputs.version }}
        run: |
          set -euo pipefail

          npm version "$NEW" --no-git-tag-version --allow-same-version

          sed -i -E "s/^( \* Version:[[:space:]]*).*/\1${NEW}/" proto-blocks.php
          sed -i -E "s/(define\('PROTO_BLOCKS_VERSION', ')[^']*('\);)/\1${NEW}\2/" proto-blocks.php

          # Fail loudly if either replacement did not land (no half-bumped state).
          grep -q "Version: ${NEW}" proto-blocks.php
          grep -q "PROTO_BLOCKS_VERSION', '${NEW}'" proto-blocks.php

      - name: Commit, tag, and push
        if: steps.bump.outputs.release == 'true'
        env:
          NEW: ${{ steps.bump.outputs.version }}
        run: |
          set -euo pipefail

          git config user.name "github-actions[bot]"
          git config user.email "41898282+github-actions[bot]@users.noreply.github.com"

          git add package.json package-lock.json proto-blocks.php
          git commit -m "chore(release): v${NEW} [skip ci]"
          git tag "v${NEW}"

          git push origin HEAD:main
          git push origin "v${NEW}"

      - name: Build plugin zip
        if: steps.bump.outputs.release == 'true'
        run: npm run dist

      - name: Create versioned release
        if: steps.bump.outputs.release == 'true'
        env:
          GH_TOKEN: ${{ github.token }}
          NEW: ${{ steps.bump.outputs.version }}
        run: |
          set -euo pipefail
          test -f dist/proto-blocks.zip || { echo "::error::dist/proto-blocks.zip missing"; exit 1; }
          gh release create "v${NEW}" \
            dist/proto-blocks.zip \
            --repo "$GITHUB_REPOSITORY" \
            --title "v${NEW}" \
            --generate-notes
```

- [ ] **Step 2: Validate the workflow YAML parses**

Run (uses the Node already available locally):
```bash
node -e "const fs=require('fs');const s=fs.readFileSync('.github/workflows/release.yml','utf8');if(!/^name:\s*Release/m.test(s)||!/jobs:/.test(s)){throw new Error('release.yml missing expected keys')}console.log('release.yml basic structure OK')"
```
Expected: `release.yml basic structure OK`.
(If `yamllint` or `actionlint` is available, prefer running that — `actionlint .github/workflows/release.yml` — but it is optional.)

- [ ] **Step 3: Sanity-check the sed commands against the real file (dry run, do NOT commit changes)**

Run:
```bash
cp proto-blocks.php /tmp/pb.php
NEW=9.9.9 sed -E "s/^( \* Version:[[:space:]]*).*/\19.9.9/" /tmp/pb.php | grep -m1 "Version:"
NEW=9.9.9 sed -E "s/(define\('PROTO_BLOCKS_VERSION', ')[^']*('\);)/\19.9.9\2/" /tmp/pb.php | grep PROTO_BLOCKS_VERSION
rm /tmp/pb.php
```
Expected: the two printed lines show `Version: 9.9.9` and `define('PROTO_BLOCKS_VERSION', '9.9.9');`. This confirms the regexes match the actual current file format before they ever run in CI.

- [ ] **Step 4: Confirm `build-and-release.yml` is unchanged**

Run: `git status --short .github/workflows/`
Expected: only `release.yml` shows as new/added; `build-and-release.yml` is not modified.

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/release.yml
git commit -m "feat(ci): auto semver bump, tag, and versioned release on push to main"
```

---

## Final Verification

- [ ] `npm run test:scripts` — all version-logic tests pass.
- [ ] `npm run test:js` and `vendor/bin/phpunit` — still green (no regressions from the package.json scripts edit).
- [ ] CLI smoke (Task 1, Step 6) produces the expected `version=`/`type=` output and the empty no-release case.
- [ ] sed dry-run (Task 2, Step 3) rewrites both version lines in `proto-blocks.php`.
- [ ] `build-and-release.yml` untouched.

## Manual / observational verification (cannot run in this repo)

After merging to `main`:
- A push whose commits include a `feat:` (and no breaking) cuts a `vX.(Y+1).0` release: a `chore(release): vX.Y.Z [skip ci]` commit appears on `main`, a `vX.Y.Z` tag and a versioned GitHub release with `proto-blocks.zip` are created, and the three version locations match.
- A `fix:`-only push cuts a patch release; a `feat!:`/`BREAKING CHANGE` push cuts a major release.
- A docs/chore-only push creates **no** release.
- The release commit does **not** trigger another release (the `[skip ci]` + `chore(release):` guard hold).
- If `main` ever becomes branch-protected, the bot push in "Commit, tag, and push" will fail — switch to a PAT/app token or a release-PR flow (documented fallback; not built).

## First-run note

If no `v*` tag exists yet, the range is `HEAD` (all history), so the first run bumps based on every commit since the repo began (e.g. any historical `feat:` → a minor bump from the current `1.0.0`). To anchor the baseline and avoid this, create an initial tag once before relying on the workflow: `git tag v1.0.0 && git push origin v1.0.0`. Subsequent runs only consider commits since the latest tag.

## Notes / Deferred (out of scope)

- Changelog file (relying on GitHub's `--generate-notes`).
- Pre-release/RC channels.
- Refreshing the rolling "latest" release to the bumped version (kept independent).
