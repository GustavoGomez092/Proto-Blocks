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

(filled in while executing)
