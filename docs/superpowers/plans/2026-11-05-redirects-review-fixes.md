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
