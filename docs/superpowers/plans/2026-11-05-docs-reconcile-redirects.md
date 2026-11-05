# Docs reconcile: redirects

Track `docs-reconcile-redirects`. Bring the redirects documentation in line with what tracks A to F and the integration actually built. The code is the truth.

## Tasks

1. `docs/redirects.md`: validation codes table, normalisation rulings, engine rulings, storage facts, settings clamping, monitor rulings, import/export facts, REST and CLI as built, boot facts, Redirection migration walkthrough.
2. `docs/content-model-files.md`: broken redirect group fails closed, flat settings file, database list.
3. `docs/cli.md`: flags and refusals as built.
4. `docs/rest-api.md`: route list, permissions, shapes.
5. `docs/trust-and-security.md`: fail-closed and header notes, log hygiene.
6. `README.md` and `CHANGELOG.md`: claims that are wrong.
7. Skill repo `references/redirects.md` (working tree only, no commit).

## Rulings

- `log.external` is accepted, validated and shown in the Settings tab, but no code reads it: other plugins' redirects are not logged yet. Documented as reserved.
- The REST route group writes no theme file; every route needs only `manage_options` (deactivate also `activate_plugins`). The file-write gate applies to `admin/schema-files/*`.
- The plugin import screen has no group picker: each source group is written to a group of the same slug, only the duplicate mode is chosen.
