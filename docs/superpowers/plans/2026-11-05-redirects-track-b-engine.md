# Redirects — Track B (Engine) plan

Spec: `docs/superpowers/specs/2026-11-05-redirects-design.md` §2, §3, §7, §13, §14.
Contract: `docs/superpowers/plans/2026-11-05-redirects-interfaces.md` "Track B — Engine".

Everything lives under `includes/Modules/Redirects/Engine/` and
`tests/php/Modules/Redirects/Engine/`. No other file is touched.

## Tasks

1. **Request + RegexGuard** — `Engine\Request` (normalisation, exclusions), `Engine\RegexGuard`
   (delimiter, flags, cap, `preg_last_error`). Tests: `RequestTest`, `RegexGuardTest`.
2. **Conditions** — `Conditions\ConditionInterface`, one class per `when.type`
   (`Login`, `Role`, `Referrer`, `Agent`, `Header`, `Cookie`, `Ip` with static `inCidr`,
   `Language`, `Server`, `Filter`, `NotFound`), `Conditions\Factory`. Test: `ConditionsTest`.
3. **QueryMatcher + TargetBuilder** — query modes, pass-through, substitution, safety rules 1–4.
   Tests: `QueryMatcherTest`, `TargetBuilderTest`.
4. **Matcher** — exact map + regex list, flags, conditions, else/fall-through, stages, trace,
   issues, `Matcher::order()` for groups. Test: `MatcherTest`.
5. **Headers + Canonical + Dispatcher** — header grammar, canonical target, every action/code,
   cache headers, hit/log calls; `HitRecorder` and `RedirectLogger` interfaces.
   Tests: `HeadersTest`, `CanonicalTest`, `DispatcherTest`.
6. **FrontController** — hooks, exclusions, environment skips, 404 stage, canonical suppression,
   send_headers. Test: `FrontControllerTest`.

## Rulings

- **Candidate order.** The exact-map bucket and the regex list are merged back into the global
  evaluation order before evaluation, so "first rule whose action fires wins" holds across
  both lists and a `.*` regex placed first beats a later exact rule (spec §14 edge case).
  The map only avoids scanning unrelated exact rules.
- **Map key.** `mb_strtolower` instead of `strtolower`, so an `ignoreCase` rule on `/CAFÉ`
  lands in the same bucket as a request for `/café`. The per-rule check still enforces the
  rule's own flags against the un-normalised path.
- **Rule `from` normalisation.** A non-regex `from` is split at the first unescaped `?`; `\?`
  becomes a literal `?` in the path; the path is `rawurldecode`d once and `//` collapsed, the
  same as the request, so `/caf%C3%A9` and `/café` are the same source.
- **Order of rules.** `Matcher` evaluates rules in the order it is given (the Registry's
  evaluation order). `Matcher::order(list<Group>)` flattens groups by position, slug, index
  and drops disabled groups; the Registry may reuse it.
- **Self-loop guard (rule 4).** The final target (after query pass-through) is turned into a
  request on the same host and run through this rule's own source test; a target the rule
  would match again does not fire. This covers "same path, same query under the rule's mode"
  and also catches `.*` → `/`.
- **Duplicate query keys.** `a=1&a=2` parses PHP-style (last wins) for `exact`/`ignore`/`pass`;
  `exactOrder` compares the raw string.
- **Pass-through extras.** Parameters named in the source query are consumed, not appended;
  target parameters win on a key clash (case-insensitively when `ignoreCase`).
- **`ip` client address.** `Conditions\Ip` resolves the client IP through an injectable
  resolver (`Ip::setResolver(callable(Request): ?string)`), defaulting to `REMOTE_ADDR`.
  Integration sets it to Track C's `IpResolver::resolve($request->server, $settings['log'])`.
  `Ip::inCidr()` is the CIDR implementation Track C may reuse.
- **Port.** `Request` keeps `port` (extra readonly prop) so canonical redirects on dev hosts
  such as `localhost:10003` keep the port; `host` stays port-free as the contract says.
- **Logging.** `url` redirects and `error` actions are logged with their code, `pass` with code
  200, `unsafe_target` once per request with code 0; `nothing` is never logged. A rule with
  `log: false` is neither logged nor counted. 404 logging is skipped when any rule matched at
  `init` (an `error` 404 is intentional, not a missing page) or a `nothing` rule matched.
- **Environment.** WP-CLI and XML-RPC are read from constants through an optional
  `$environment` callable on `FrontController` so tests can drive them.
- **Settings in tests.** `Definition\Settings` is Track A's; tests use a private
  `EngineTestCase::settings()` with the spec §1 defaults.
