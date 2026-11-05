# Redirects integration — boot wiring (track `redirects-integration-boot`)

Joins tracks A (foundation), B (engine) and C (storage/monitor). Spec: `docs/superpowers/specs/2026-11-05-redirects-design.md` §3, §5, §8, §13. Contract: `2026-11-05-redirects-interfaces.md`.

## Tasks

1. Storage implements the engine interfaces: `Storage\Hits implements Engine\HitRecorder`, `Storage\RedirectLog implements Engine\RedirectLogger` (`log404(mixed)` is a legal widening). Test: instances satisfy the interfaces, a Dispatcher with the real storage writes the upsert and the log row, `log: false` writes neither. Files: `Storage/Hits.php`, `Storage/RedirectLog.php`, `tests/php/Modules/Redirects/Storage/EngineContractTest.php`.
2. One CIDR implementation: `Storage\IpResolver::inCidr()` gains the v4-mapped IPv6 comparison; `Engine\Conditions\Ip::inCidr()` delegates. Both test suites stay green; new cases pin the mapped form in IpResolverTest.
3. `from` decoding: test that `/caf%C3%A9` and `/café` rules both match either request form, and that `/a\?b` matches the path `/a?b` literally (MatcherTest additions; fix SourceMatcher if needed).
4. Monitor takes the real `Registry`; the default serializer passes the site defaults (`Serializer::group($group, $settings['defaults'])`). MonitorTest uses `Registry::fromArrays()` instead of a duck-typed double.
5. `RedirectsModule::boot()` wiring + `activate()` / `deactivate()` statics; `issues()` merges Monitor issues. Tests in `tests/php/Modules/Redirects/ModuleBootTest.php`.
6. Manager-level issues test (redirect_chain appears in `Manager::issues()`).
7. End-to-end `tests/php/Modules/Redirects/IntegrationTest.php`.
8. PHP 8.1 run in Docker (`php:8.1-cli`), fix incompatibilities.

## Rulings

- **Boot timing.** Everything is wired in `boot()` (after_setup_theme 1 via Manager), so a filtered `proto_blocks_redirects_priority` below 10 still works. Settings are read there (one registry build). A PHP declaration made between boot and the `init` 9 seal is caught by `onSealed()` (hooked at init 9 after `PhpDeclarations::seal`), which invalidates the registry only when the PHP declaration fingerprint changed; the FrontController reads `enabledRules()` through a closure on the first match, so it sees the sealed set.
- **Exit/header seam.** Manager constructs the module, so the production Dispatcher's `header()`/`exit` are replaced in tests through the `@internal` static `RedirectsModule::setResponseHandlers()`.
- **Coexistence seam.** `Coexistence::setDetector()` (`@internal`) replaces the Redirection detection for every caller; a real `Redirection_Admin` class or constant would leak into every later test.
- **Coexistence scope.** The pause covers the front end only (no FrontController: no redirects, no logging, no 404 log). Tables, retention, the IP resolver and the permalink monitor stay wired: the monitor writes files and never redirects.
- **`log: false`.** Enforced once, in `Engine\Dispatcher` (record + log). `Storage\Hits` honours only `trackHits`; `RedirectLog` only `settings.log`.
- **`proto_blocks_redirects_log_404`.** Applied once, by `FrontController`, with the engine `Request` (spec: "bool, request"). `NotFoundLog` no longer applies it with a snapshot array, which would have broken a callback type-hinted on `Request`.
- **CIDR.** `Storage\IpResolver::inCidr()` is the one implementation and gained B's IPv4-mapped IPv6 comparison (both directions, the mapped range only when its prefix is >= 96); `Conditions\Ip::inCidr()` delegates.
- **`from` decoding.** No fix needed: B's SourceMatcher already splits at the first unescaped `?`, unescapes `\?` and rawurldecodes once; pinned end to end through `Registry::fromArrays()`.
- **Monitor.** Constructor typed `Registry`; the optional serializer closure stays as a test seam (MonitorTest counts calls and injects a broken serializer), its default is now `Serializer::group($group, $settings['defaults'])`. MonitorTest uses `Registry::fromArrays()`; the "invalidated" assertions read the registry's private build cache by reflection.
- **issues().** Registry issues + `Monitor::issues()` when the monitor is wired (stale notices of a switched-off monitor are not reported). Matcher issues are per request and private to the FrontController's Matcher; they reach the redirect log (`unsafe_target`, code 0), not `issues()`.
- **Monitor writer.** `new SchemaFileWriter(SchemaPaths::writableRoots(), AdminPage::fileModsAllowed())` as the boot comment said: post saves by editors must still be able to write (the REST routes' `canWriteCode()` is not required).
- **Activation.** `activate()` = `Installer::ensure()`, `deactivate()` = `Retention::unschedule()`; tables and data stay. proto-blocks.php is not edited (wired file); the two calls are in the report.
