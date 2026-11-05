# Redirects Track C: storage, retention, permalink monitor

Spec: `docs/superpowers/specs/2026-11-05-redirects-design.md` §1 (`log`, `trackHits`, `monitor`), §5, §8, §13, §14.
Contract: `docs/superpowers/plans/2026-11-05-redirects-interfaces.md`, "Track C".

## Tasks

1. `Storage\IpResolver`: `resolve()`, `mask()`, `inCidr()`, `forStorage()`. Test: `tests/php/Modules/Redirects/Storage/IpResolverTest.php`.
2. `Storage\Schema` + `Storage\Installer`: three CREATE TABLE statements (dbDelta format, charset collate), `ensure()`, `status()`, `drop()`. Tests: `SchemaTest.php`, `InstallerTest.php`.
3. `Storage\Hits`: upsert, `all()`, `reset()`, `delete()`, `prune()`, `orphans()`. Test: `HitsTest.php`.
4. `Storage\RequestSnapshot` + `Storage\LogTable` (shared row shaping, filters, paging) + `Storage\RedirectLog`. Tests: `RequestSnapshotTest.php`, `RedirectLogTest.php`.
5. `Storage\NotFoundLog`: `log404()` with per-minute URL+IP de-dup, `grouped()`, `deleteByUrl()`. Test: `NotFoundLogTest.php`.
6. `Storage\Retention`: daily prune, batches of 10,000, single 10-minute follow-up. Test: `RetentionTest.php`.
7. `Monitor`: hooks, move/trash rules, associated suffix, loop avoidance, unwritable issue. Test: `tests/php/Modules/Redirects/MonitorTest.php`.

Every task: failing test, implementation, `composer test` green, one commit.

## Rulings

- The hits/log tables name the group column `group_slug`, not `group`: `GROUP` is a reserved word in MySQL and every query would need backticks. The API shape keeps `group`.
- `request` is `LONGTEXT` in both log tables (JSON columns are not available on every supported MariaDB/MySQL).
- `Engine\HitRecorder` / `Engine\RedirectLogger` (Track B) are not in this worktree, so `Hits` and `RedirectLog` carry no `implements` clause; integration adds it. `log404()` takes `mixed` (a `RequestSnapshot` array or B's `Request` object), which is a legal widening of `log404(Request $r)`.
- `logRedirect(array $entry)` entry keys: `url`, `target`, `code`, `group`, `ruleId`, `redirectBy` (default `proto-blocks`), `request` (snapshot array or `Request` object; defaults to `RequestSnapshot::fromGlobals($_SERVER)`).
- `IpResolver::resolve()` returns the client address unmasked (Track B's `ip` condition needs it); `forStorage()` applies `log.ip`. `trustedProxies` entries may be single addresses or CIDR ranges. A header item such as `1.2.3.4:8080` or `[2001:db8::1]:443` is reduced to its address before validation. Only the five allow-listed header names are honoured, whatever the settings say.
- Stored request headers drop `authorization`, `cookie`, `host`, `proxy-authorization` (spec) and also `x-wp-nonce`, `x-api-key`, `x-auth-token`, `x-csrf-token`, `x-xsrf-token`, `set-cookie` (credentials too). At most 50 headers, values cut at 1000 characters.
- Method allow-list: GET HEAD POST PUT PATCH DELETE OPTIONS TRACE CONNECT; anything else is stored as `OTHER`.
- `Hits::record()` honours `trackHits`; the rule's own `log: false` is the dispatcher's call (it has the rule). `Hits::reset()` zeroes the row; `delete()` removes it.
- `NotFoundLog::log404()` applies the `proto_blocks_redirects_log_404` filter itself; Track B must not apply it a second time. De-duplication compares the stored `ip` value (null-safe), so under `ip: none` one URL collapses to one row per minute.
- Retention cutoffs use UTC (`current_time(..., true)`). The follow-up batch is a single event on the same hook with args `['continue']` so the daily event is not mistaken for it.
- Monitor: Track A's `Registry` and `Serializer` are not in this worktree, so the constructor takes `object $registry` and an optional serializer closure (default `Serializer::group(...)`); integration may tighten the type. The writer is the real `SchemaFileWriter`; existing files are written with their current hash.
- Monitor notices (loop avoidance) and `monitor_unwritable` are persisted in the option `proto_blocks_redirects_monitor_issues` (newest 20) so the screen sees them on a later request; `Monitor::issues()` returns them as `Issue` objects, `clearIssues()` empties it.
- Monitor: a rule already in the monitor group with the same source is updated in place instead of appended; regex rules and PHP-declared rules are never touched by loop avoidance (a PHP rule cannot be written; it is reported in the notice instead).
- Monitor: a loaded group whose file is not the writer's own copy (a parent theme or a `proto_blocks_schema_paths` root) counts as not writable (`monitor_unwritable`, reason `read_only`) instead of being shadowed by a new copy in the writable root. Any unwritable group blocks every write of that event.
- Monitor paths are rawurldecoded (the engine matches decoded paths), relative to `home_url()`'s path, and permalinks on another host are ignored. The trash rule does not trigger loop avoidance (it is disabled and targets `/`).
