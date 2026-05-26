# Automated Semver Release Workflow — Design

**Date:** 2026-05-25
**Status:** Approved (pending spec review)

## Problem

Proto-Blocks has no version-bump or semantic-versioning release automation. The version is hand-maintained in three places and can drift:

- `proto-blocks.php` — the `Version:` plugin header
- `proto-blocks.php` — the `PROTO_BLOCKS_VERSION` constant
- `package.json` — `version`

The existing CI (`.github/workflows/build-and-release.yml`) builds on every push to `main` and refreshes a single rolling **"latest"** GitHub release. There is no versioned tag/release and no bump mechanism.

(Note: this plugin has no `readme.txt` "Stable tag" — it uses `README.md` — so there is no fourth version location to update.)

## Goal

On every push to `main`, automatically determine the semantic-version bump from Conventional Commits, update all version locations, commit the bump, tag it, and publish a versioned GitHub release with the built zip. Leave the existing rolling "latest" workflow untouched.

## Decisions (agreed)

- **Trigger / bump source:** automatic, from Conventional Commits since the last release tag.
  - `BREAKING CHANGE` (footer) or a `type!:` subject (e.g. `feat!:`) → **major**
  - `feat:` → **minor**
  - `fix:` → **patch**
  - none of the above → **no release** (doc/chore/refactor-only pushes do not cut a release)
- **Output:** bump the three version locations, commit `chore(release): vX.Y.Z [skip ci]` back to `main`, create tag `vX.Y.Z`, and publish a versioned GitHub release containing `dist/proto-blocks.zip`. The rolling "latest" release is kept as-is.
- **Branch protection:** `main` is **not** protected, so the `github-actions` bot can push the release commit directly using the default `GITHUB_TOKEN`.

## Architecture

A new workflow `.github/workflows/release.yml`, separate from `build-and-release.yml` (single responsibility; the latter is unchanged). The version-bump *decision* is extracted into a small, unit-tested Node module so the only non-trivial logic is testable off-CI.

### Components

| Component | Responsibility |
|-----------|----------------|
| `scripts/next-version.mjs` | **Pure logic + thin CLI.** Exports `nextVersion(currentVersion, commitSubjects, commitBodies) → { version, type } \| null` (no git, no I/O). When run directly (`node scripts/next-version.mjs`) it reads `CURRENT_VERSION` and the commit log from env/stdin (see below) and prints either `version=<v>\ntype=<t>` to stdout, or nothing, exiting 0 either way. |
| `scripts/__tests__/next-version.test.mjs` | Jest unit tests for the classifier + version math. |
| `.github/workflows/release.yml` | Orchestrates: guard → gather commits → call `next-version` → bump files → commit/tag/push → build → release. |

### `next-version.mjs` contract

```
nextVersion(current: string, subjects: string[], bodies: string[])
  → { version: string, type: 'major'|'minor'|'patch' } | null
```

Rules (evaluated across all commits since the last tag):
- If any subject matches `^\w+(\(.+\))?!:` OR any body contains `BREAKING CHANGE` → `major`.
- Else if any subject matches `^feat(\(.+\))?:` → `minor`.
- Else if any subject matches `^fix(\(.+\))?:` → `patch`.
- Else → `null`.
- Version math: split `current` on `.`; major → `(M+1).0.0`; minor → `M.(m+1).0`; patch → `M.m.(p+1)`.

Pre-1.0.0 note: standard semver math applies as above (no special 0.x downgrade of breaking → minor). The plugin is at 1.0.0, so this is moot, but the rule is stated explicitly to remove ambiguity.

### Workflow flow (`release.yml`)

1. **Triggers:** `push` to `main`, plus `workflow_dispatch` (manual safety valve). `permissions: contents: write`. `concurrency` group to serialize runs.
2. **Checkout** with `fetch-depth: 0` (full history + tags needed to find the last tag and diff commits).
3. **Loop guard:** if the HEAD commit subject starts with `chore(release):`, exit the job successfully (no-op). The release commit also carries `[skip ci]`, so GitHub normally won't even start a run for it — this guard is defense-in-depth.
4. **Determine the last tag:** `git describe --tags --abbrev=0 --match 'v*'` (empty if none yet).
5. **Collect commits since the last tag** (or all commits if no tag): subjects via `git log <range> --format=%s`, bodies via `--format=%b`.
6. **Compute the bump:** pass the current `package.json` version (env `CURRENT_VERSION`) and the collected commit subjects+bodies (via stdin, e.g. the `%s`/`%b` logs) to `node scripts/next-version.mjs`; capture its `version=`/`type=` stdout into workflow outputs (`$GITHUB_OUTPUT`). **If it prints nothing, exit the job successfully without releasing.**
7. **Bump the three locations:**
   - `npm version <new> --no-git-tag-version` (updates `package.json`, no tag/commit).
   - `sed` the `proto-blocks.php` ` * Version: <old>` header line → new.
   - `sed` the `proto-blocks.php` `define('PROTO_BLOCKS_VERSION', '<old>')` line → new.
   - Sanity check: `grep` confirms the new version appears in both files (fail the job if a replacement didn't take).
8. **Commit + tag + push:** configure `git` as the `github-actions[bot]`, commit `chore(release): vX.Y.Z [skip ci]` (with NO AI/Claude attribution), create annotated tag `vX.Y.Z`, push the commit and the tag to `main`.
9. **Build:** `npm ci || npm install` then `npm run dist` (produces `dist/proto-blocks.zip`, same as the existing workflow).
10. **Release:** `gh release create vX.Y.Z dist/proto-blocks.zip --title "vX.Y.Z" --generate-notes`.

### Interaction with `build-and-release.yml`

Both fire on a normal push to `main`. `build-and-release.yml` refreshes "latest" from the triggering (pre-bump) commit; `release.yml` cuts the versioned `vX.Y.Z`. They are independent. The release commit carries `[skip ci]`, so neither workflow re-runs on it (no loop; "latest" simply doesn't refresh for the bump commit, which is fine — the versioned release is canonical).

## Error handling

- **No release-worthy commits:** exit 0 without tagging/releasing (the common case for chore/docs pushes).
- **sed replacement miss:** the post-bump `grep` verification fails the job so a half-bumped state is never committed.
- **Tag already exists** (e.g. re-run): `git push` of an existing tag fails the job loudly rather than silently mis-releasing.
- **Loop:** prevented by `[skip ci]` + the `chore(release):` HEAD guard.

## Testing

- **Unit (Jest, runnable locally + in CI):** `scripts/__tests__/next-version.test.mjs` covers: breaking via `!`, breaking via `BREAKING CHANGE` body, `feat`→minor, `fix`→patch, scoped types (`feat(scope):`), precedence (a `feat` + `fix` together → minor; a breaking + `feat` → major), and the `null` case (only `chore`/`docs`). Plus version-math cases for each bump from `1.0.0`.
- **Manual / observational (cannot run in this repo):** trigger the workflow on a branch or via `workflow_dispatch` against a scratch repo/tag to confirm the end-to-end push+tag+release; verify a chore-only push produces no release.

## Out of scope (YAGNI)

- Changelog file generation/maintenance (the GitHub release notes are auto-generated via `--generate-notes`).
- Pre-release / RC channels.
- Updating the rolling "latest" release to the bumped version (kept independent).
- PR-based release flow (only needed if `main` becomes protected — documented as the fallback but not built).
