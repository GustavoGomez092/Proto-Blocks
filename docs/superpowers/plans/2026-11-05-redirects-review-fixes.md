# Redirects review fixes (server side)

Source: the server-side review of the Redirects and Scripts modules (H1, M1-M5, Low 1-12).
TDD, one commit per finding, `composer test` green at every commit.

## Tasks

| # | Finding | Files | Test |
|---|---|---|---|
| 1 | H1 monitor on multisite | `RedirectsModule.php`, docs/redirects.md | `MonitorMultisiteTest` (adjusted to the ruling) |
| 2 | M1 canonical uses the site host | `Engine/Canonical.php` | `CanonicalTest` (`Host: evil.test`) |
| 3 | M2 IP-bearing headers | `Storage/LogTable.php` | `RedirectLogTest` (none / masked / full) |
| 4 | M3 `log.external` | `ExternalLog.php` (new), `RedirectsModule.php`, docs | `ExternalLogTest` |
| 5 | M4 loop avoidance needs `manage_options` | `Monitor.php` | `MonitorTest` |
| 6 | M5 settings-only boot, lazy registry | `Registry.php`, `Sources/GroupFiles.php` | `GroupFilesTest`, `ModuleBootTest` |
| 7+ | Low 1-12 | see each commit | named tests |

## Rulings

- H1: the monitor is registered only when `!is_multisite()`. On multisite with
  `monitor.postTypes` set, `RedirectsModule::issues()` carries a warning
  `monitor_unwritable` (reason `multisite`, slug `settings`, kind
  `redirect-settings`). The reviewer's test now asserts no monitor and no post hook.
- M1: when the request host is not the home host (or its `www.` variant), the
  target is built from the home host and port. An unknown host is redirected only
  when a configured step (https, alias) would fire; `www` never fires for it.
- M4: without `manage_options` the rule is left alone and a `monitor_conflict`
  warning names the post and the rule. The move rule that would chain into
  the kept rule is NOT written (writing it would create a chain, or a loop
  when the kept rule points back at the old path, e.g. a rename back inside
  the monitor group). Moves without a conflict (and an `associated` rule whose
  own target does not conflict) are still written. PHP-declared rules keep
  the existing notice for everyone (nobody can disable them).
- M2: `masked` strips the IP-bearing headers too (ruling); `x-client-ip` is
  stripped as well (the reviewer listed it).
- M3: `ExternalRedirects` is registered only while the module is not paused
  for the Redirection plugin (paused means "no redirects, no logging").
- M5: Registry::settings() reads the settings file alone until a build; the
  optional transient cache of the normalised rules was not added.
- Low 1: `Refresh` is forbidden; `Content-Type` is NOT (the reviewer said
  "probably"; refusing it would change documented header behaviour without a
  concrete attack). `src/admin/redirects/schema.ts` FORBIDDEN_HEADERS still
  lacks `refresh` (client file, outside this track): the server refuses it.
- Low 2: `php` is reserved for group FILES only (GroupValidator::FILE_SLUG_PATTERN:
  folders, the writer kind, `monitor.group`, CLI import, the JSON schema);
  SLUG_PATTERN still accepts `php` because log, hit and REST filters must match
  the PHP group's rows.
- Low 5: kept the documented storage ("autoloaded while set, deleted
  otherwise"); the absent option is detected through wp_load_alloptions()
  instead of storing `[]`.
- Low 6: a failed table creation backs off one hour (transient
  `proto_blocks_redirects_db_retry`); install() and plugin activation ignore it.
  docs/redirects.md updated.
- Low 7 NOT changed: network-wide deactivation would need proto-blocks.php
  (a wired file) to pass `$network_wide` and a loop over every site, which
  core discourages on large networks; the orphan cron events are harmless
  (their callback is gone while the plugin is inactive and the event is
  rescheduled once on reactivation).
- Low 12: `$_REQUEST` is rebuilt as `array_merge($_GET, $_POST)`; documented.
