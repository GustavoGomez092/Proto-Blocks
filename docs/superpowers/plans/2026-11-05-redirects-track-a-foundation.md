# Redirects Track A: Foundation (plan)

Spec: `docs/superpowers/specs/2026-11-05-redirects-design.md` (sections 1, 2, 3 target/regex rules, 4, 10, 13, 16).
Contract: `docs/superpowers/plans/2026-11-05-redirects-interfaces.md` ("Track A").

## Tasks

1. Schema `includes/ContentModel/schemas/redirects.schema.json` (`$defs/group`, `rule`, `when`, `settings`), checked by `ContentModel\SchemaCheck`. Test: `Definition/SchemaTest`.
2. `Definition\Patterns` (regex compile check mirroring RegexGuard, header, host, IP/CIDR grammar), `RuleNormalizer`, `RuleValidator`, `GroupValidator`, `Settings`, `SettingsValidator`. Tests under `Definition/`.
3. `SchemaFileWriter` gains a `flat` extended kind (`<root>/<folder>/<basename>.jsonc`), needed for `proto-redirects/settings.jsonc`. `Sources\GroupFiles`, `Sources\PhpDeclarations`, `functions.php`. Tests under `Sources/`.
4. `Registry` (precedence, ordering, issues, `filesHash`, `fromArrays`). Tests under `Registry/`.
5. `Serializer`, `Templates`. Tests under `Serializer/`.
6. `Coexistence`, `RedirectsModule` (`registerFileKinds`, `registry`, `settings`, `issues`, `boot`, declaration-warning filter). Tests under `Sources/FileKindTest` and `Registry/ModuleTest`.

Every task: failing test, implementation, `composer test` green, commit.

## Rulings

- The settings file is `proto-redirects/settings.jsonc` but `extendKind()` only addressed `<folder>/<slug>/<basename>`. `SchemaFileWriter::extendKind()` gets a backward-compatible `flat` option (path `<root>/<folder>/<basename>.jsonc|.json`, slug pattern defaults to the basename, the folder may be shared with one non-flat kind, delete never removes the folder). Smallest change that keeps the spec's layout and REST path.
- Group slug grammar `^(?!settings$)[a-z][a-z0-9_-]{0,59}$` (max 60, `settings` reserved). `php` is reserved for PHP-declared groups (`proto_redirect_group('PHP')` is refused); a file group named `php` replaces the virtual group like any file group replaces a PHP group.
- Validation codes beyond the spec's list: `missing_source`, `invalid_source`, `invalid_else`, `invalid_condition`, `invalid_host`, `invalid_url`, `invalid_ip`, `schema_invalid`. The 500-character regex cap is `invalid_regex`.
- `to` must start with `/` (not `//` or `/\`) or be `http(s)://host...`; `$1` alone is refused. `error`/`nothing` refuse `to`; `pass`/`nothing` refuse `code`; `pass` refuses an absolute `to`.
- Condition regexes (`when.regex`) are compiled and capped like `from` but get no `unanchored_regex` warning (contains-matching is their normal use).
- The normaliser keeps `from` as authored apart from the same-host reduction (no percent-decoding, no slash collapsing): idempotent, so `Registry::fromArrays()` can re-normalise. Track B decodes when indexing. In a non-regex `from`, `\?` is a literal `?` (not the query separator); the reduction never splits on it.
- Normalised `when` always has the four keys `type`, `value` (null for login/404, list for ip/language), `name` (null unless header/cookie; header names title-cased), `regex` (false unless referrer/agent/header/cookie). `server` values lower-cased.
- Derived id: `'r_' . substr(sha1($slug . "\0" . ($from ?? '') . "\0" . json_encode($when, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\0" . $action), 0, 8)` over the normalised `when` (null encodes as `null`).
- Registry: a group-level error (schema error outside `/rules/N`, invalid JSONC, oversized file) skips the group; a rule-level error drops only that rule (with an error Issue). Fail closed per rule, not per site.
- Double declaration key: regex flag + `from` (non-regex: `strtolower(rtrim(from, '/')) ?: '/'`) + json(when). The Issue carries the file group slug, kind `redirects` and the file rule id as `key`, source = PHP call site.
- `Settings::merge()` sanitises per key: wrong type → default; log days clamped to -1..90; `cache` outside {-1,0,1,24,168} → default 1; aliases lower-cased, invalid hosts dropped, de-duplicated, first 20 kept; `relocate` must be an absolute http(s) URL without userinfo/query/fragment else "". `SettingsValidator` reports the same things as errors for writes.
- Hosts are ASCII (punycode for IDN) in settings; the `server` condition accepts Unicode hosts.
- Serializer: `group($group, ?array $inherited = null)`: `$inherited` = site defaults (default built-in); the `defaults` block lists only keys differing from `$inherited`; a rule flag is omitted when equal to the effective group default. Settings serialise in `Settings::defaults()` key order, unknown keys dropped.
- New-file templates are passed through `extendKind(... 'template' => ...)` (`Redirects\Templates`): `DeclarationTemplate` is not edited, SchemaFilesRoutes already serves extended templates. No integration hook needed.
- `Coexistence::paused($settings, ?callable $detect = null)`: the detector is injectable so tests never declare a global `Redirection_Admin`.
- `tests/php/API/FieldsRoutesTest.php` asserted `extendedKinds() === ['field-group']` after a Manager boot; with the redirects module booting it now also lists `redirects`/`redirect-settings`. The two assertions are relaxed to contains/not-contains (only change outside the track's folders besides the writer).
