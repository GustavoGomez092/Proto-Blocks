# Docs track: Redirects and Scripts

Track name: `docs-redirects-scripts`. Source of truth: the two 2026-11-05 specs. Docs only; no code.

## Tasks

1. `docs/redirects.md` in the house pattern (At a glance, declare, keys, conditions, actions, queries, regex, safety, groups, settings, DB, monitor, headers, import/export, screen, REST, CLI, troubleshooting).
2. `docs/scripts.md` (declare, keys, placement targets, output order, trust, screen, REST, CLI, troubleshooting).
3. Update `docs/content-model-files.md`, `docs/cli.md`, `docs/rest-api.md`, `docs/trust-and-security.md`, `README.md`, `CHANGELOG.md` (2.16.0 Unreleased).
4. Skill repo (separate): `references/redirects.md`, `references/scripts.md`, pointers, version 2.5.0, `npm test` green.

## Tests

No code. Checks: every relative markdown link resolves; every command and key named in the docs appears in the specs.

## Rulings

- Where the specs leave a choice to the builder (comment text, component split) the docs stay silent.
- Behaviour differences reported by implementers are reconciled at integration.
