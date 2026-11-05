# Redirects Track D — Import / export (plan)

Spec: `docs/superpowers/specs/2026-11-05-redirects-design.md` §1, §9, §13. Contract: `docs/superpowers/plans/2026-11-05-redirects-interfaces.md` "Track D".
Pure PHP, no dependency on Tracks A/B/C. Namespace `ProtoBlocks\Modules\Redirects\Import` / `...\Export`.

## Tasks

1. **Shared import pieces** — `Import/ParsedRow` (row constructors + reason messages), `Import/RawRule` (canonical key order, null dropping), `Import/Patterns` (literal/regex helpers, wildcard `*` → `(.*)`), `Import/FormatInterface`, `Import/Formats` (5 MB cap constant, filename decision, content sniffing, `detect`, `byId`, `all`). Tests: `Import/PatternsTest`, `Import/FormatsTest`.
2. **CsvFormat** — delimiter detection `, ; |`, RFC 4180 tokenizer with multi-line quoted fields and line tracking, BOM, CRLF/CR, header row (positional or named columns incl. our own export header), code → action, regex sniff, `[FORMULA] ` unescape, self-redirect and empty-source rows invalid. Test: `Import/CsvFormatTest` + fixtures.
3. **JsonFormat** — Proto-Blocks shape (`groups`, `settings`, also a single group file object), Redirection shape (match types, action types, flags, string `action_data` upgrade, group mapping and slugging, position ordering). `parseGroups()`, `parseSettings()`. Test: `Import/JsonFormatTest` + fixtures.
4. **ApacheFormat** — `RewriteRule`, `RewriteCond` (HTTP_REFERER / HTTP_USER_AGENT / HTTP_HOST / QUERY_STRING), `Redirect`, `RedirectMatch`, `RedirectPermanent`, `RedirectTemp`, flags, word codes, line continuations, WordPress block skipped. Test: `Import/ApacheFormatTest`.
5. **NetlifyFormat** — `from to [status][!]`, comments, `*`/`:splat`, `:name` rejected, absolute source → `server` condition. Test: `Import/NetlifyFormatTest`.
6. **Merger** — skip | replace | add, duplicate key = normalised from + regex + when, `replace` keeps the existing id, ids assigned to added rules without collisions; `mark()` for preview statuses. Test: `Import/MergerTest`.
7. **Plugin importers** — `PluginImporterInterface`, `AbstractPluginImporter` (`skipped()`), `PluginImporters`, `RedirectionImporter`, `Simple301Importer`, `SafeRedirectManagerImporter`, `RankMathImporter`, `YoastImporter`, `OldSlugsImporter`. Test: `Import/PluginImportersTest` (`$wpdb->queue`, `pb_test_options`, `pb_test_posts`).
8. **Exporters** — `Export/ExporterInterface`, `Export/Support` (formula escape, control-char guard, pattern helpers), `CsvExporter`, `JsonExporter`, `ApacheExporter`, `NginxExporter`, `NetlifyExporter`, `Exporters`. Golden files in `tests/php/Modules/Redirects/Import/fixtures/expected/*.txt`. Tests: `Export/*ExporterTest`.
9. **Round trips** — CSV → parse → export, JSON → parse → export. Test: `Export/RoundTripTest`.

## Rulings

- `ParsedRow.reason` is a stable machine code (`too_large`, `self_redirect`, `empty_source`, `missing_target`, `invalid_code`, `invalid_json`, `unsupported_*`, …); `ParsedRow::message($code)` gives English text for the UI.
- Whole-input failures (`too_large`, `invalid_json`, `unknown_shape`) are a single invalid row with `line` 0; every other row's `line` is 1-based (JSON: 1-based record ordinal, since JSON has no line per rule).
- Parsers emit only keys they know (canonical §1 order, nulls dropped) so group defaults apply to whatever the source does not express. Parsers never set `duplicate`; `Merger::mark()` does, against the target group and earlier rows.
- CSV: unknown codes are `invalid_code` rows (not silently 301). A header row is recognised by known column names; named columns are then read by name (our export header round-trips title/status/type).
- Apache: Apache is case-sensitive, so rules without `NC` import `ignoreCase: false`. `RewriteRule` never sees the query: no `QSA` → `query: ignore`, `QSA` → `pass`. `[F]` → 403 (symmetric with the exporter), `[G]` → 410, `seeother` → 303 (Apache's meaning). A path target without `R` is an internal rewrite → `pass`; an absolute target without `R` → 302 (Apache's implicit redirect). `Redirect` imports as an exact match (as Redirection does; Apache's prefix semantics are not reproduced) with `ignoreTrailingSlash: true`. Everything between `# BEGIN WordPress` / `# END WordPress` is skipped. Unsupported `RewriteCond` (e.g. `REQUEST_FILENAME`) invalidates the next rule.
- Netlify: status 200 → `pass`, 4xx/5xx → `error` (target dropped), condition tokens (`Country=`, `Role=`, query tokens) → invalid. Default 301.
- JSON (Redirection): `random` action, login rules with only `logged_out`, and condition rules with only `url_notfrom` are `unsupported_*` rows (no negated condition in our model). Serialized PHP is never unserialized from uploaded files; plugin importers read DB serialized values with `allowed_classes => false`.
- A Proto-Blocks single group file (`{title?, rules: [...]}`) is also accepted, as group slug `imported`. Redirection rules with an unknown `group_id` land in group `imported`.
- Merger assigns ids to added rules that lack one (or collide): `r_` + sha1(slug, from, when, action[, n]) — the same derivation as Track A for the first, a counter for collisions.
- CSV export skips rules with a `when` condition (re-importing them would make them unconditional); JSON is the lossless format. 404 rules (`from` null) are skipped in CSV too.
- Apache/Nginx/Netlify exporters refuse (comment `# skipped: …`) any rule whose values contain control characters, so nothing can inject directives. Patterns use `^/?` so they work both in `.htaccess` and server context. No `server {}` wrapper in the Nginx export (it is meant to be included inside one). Netlify exports only literal rules and `^/prefix/(.*)$` regexes.
- JSON export: groups in evaluation order, registry keys (`group`, `origin`, `source`, `index`) and null / empty-headers keys dropped, two-space indent, optional settings via the constructor.
- Plugin importers expose rows they could not convert through `AbstractPluginImporter::skipped()` (not on the interface, which is fixed by the contract).
