# Redirects — Design

**Date:** 2026-11-05
**Status:** Approved for build by the developer ("start with the build"). Every decision below is the author's call unless marked as the developer's.
**Release:** v2.16.0 through the existing release workflow (fast-forward `main`, never squash).

## Intent

Client sites run the Redirection plugin for 301s, 404 tracking and redirect logs. Its rules live in the database, so they are invisible to git, drift between environments, and cannot be reviewed. Proto-Blocks already keeps the whole content model in git-tracked JSONC files. This design adds a redirect manager with Redirection's feature set, where **every rule and every setting is a file in the theme** and the database holds only what cannot be a file: hit counts, the redirect log and the 404 log.

The developer's requirements (verbatim intent): rules saved as files "as we do for everything in protoblocks so they can be git tracked"; git tracking is "the only source of truth"; extend the protoblocks skill and the README with the syntax; full unit and E2E coverage, "look for edge cases"; ship fast.

## Goals

1. **One-line declaration.** `proto_redirect('/old', '/new');` works. A JSONC file does the same with every option.
2. **Files are the only store for configuration.** Rules, groups and module settings are JSONC files in the theme. Nothing about *what* redirects is ever written to the database.
3. **Feature parity with Redirection where it matters:** every match type, every action, query-string modes, case and trailing-slash flags, regex with capture groups, groups with ordering, hit counts, redirect log, 404 log with "create redirect from this 404", permalink-change monitor, custom HTTP headers, canonical https/www, CSV/JSON/.htaccess/`_redirects` import, CSV/JSON/.htaccess/Nginx export, importers for Redirection and the common redirect plugins, WP-CLI.
4. **Safer than Redirection by default:** no open redirect through capture groups, no silent invalid regex, no self-loop, excluded core paths, bounded regex.
5. **Same admin experience** as the other content-model screens: React screen under Proto-Blocks, file-backed, read-only when the theme is unwritable, declared-twice badges, issues list.
6. **Independent module:** `redirects` in the `Modules` manager, switchable off by the `proto_blocks_modules` filter; nothing else depends on it.

## Non-goals

- Writing `.htaccess` or Nginx config automatically. Export only. (Writing outside the theme breaks the confinement rule.)
- Proxying external URLs through the `pass` action (SSRF surface). `pass` is internal only.
- Migrating old permalink structures by re-parsing requests (Redirection's "permalinks" option). A regex rule covers it.
- Target shortcodes (`[userid]`, `[md5]`, …) and "random post" action.
- Granular capabilities per tab. `manage_options` throughout, like every other Proto-Blocks screen (filter `proto_blocks_redirects_capability` for the whole module).
- An RSS feed of rules. Git is the feed.
- Importers for Pretty Links, SEOPress, Slim SEO, EPS, Fake Redirection. The importer interface is open; six importers ship (section 9).
- Object-cache of match results. Rules come from files already in memory; there is no query to cache.

## Anti-goals

- A rule whose behaviour depends on which environment it runs in. If the file is the same, the redirect is the same.
- A UI write that loses a developer's hand-written comment in the file.
- A page-load cost that grows with the log tables. Logging is write-only on the hot path; reads happen in the admin.
- An open redirect, a redirect loop, or a regex that can hang a request.

## Constraints

- PHP 8.1+, WordPress 6.5+, the existing React/Tailwind 4/shadcn/react-hook-form/zod admin stack, PHPUnit without WordPress, Jest, Playwright on wp-env. All suites must also pass in `php:8.1-cli`, `php:8.3-cli`, `ubuntu:26.04` and `node:24` containers before release (lesson from 2.15.0).
- Trust boundary from `docs/trust-and-security.md` holds: `manage_options` for the screen and every write; file-mods and multisite rules; path confinement to the theme; declaration files are data, never executed; `UntrustedKeys` stripping applies.
- Coexistence: the Redirection plugin may still be active during migration. Proto-Blocks must not double-redirect: when Redirection's front controller class exists, this module shows a notice and stays passive until Redirection is deactivated or the setting `coexist: true` is set in the settings file.

## Decisions

### 1. Files

```
theme/
  proto-redirects/
    settings.jsonc                 module settings (one file, optional)
    <group-slug>/redirects.jsonc   one group of rules, ordered
```

- `.json` accepted; `.jsonc` wins with the existing `both_extensions` warning. Child theme discovered after parent and wins on the same group slug. Filter `proto_blocks_schema_paths` adds read-only roots as for every other kind.
- Group slug grammar: the content-model slug rule `^[a-z][a-z0-9_-]*$`, max 60. `settings` is reserved (it is the settings file, not a group).
- The file kind is registered through `SchemaFileWriter::extendKind('redirects', 'proto-redirects', 'redirects', …)` and `extendKind('redirect-settings', …)` for the settings file, the way `field-group` is. It does not go through `Registry`.
- Size cap: the existing 256 KB per file. A group that outgrows it is split by the developer; the validator says so.

**Group file**

```jsonc
// proto-redirects/legacy-site/redirects.jsonc
{
  "title": "Legacy site",        // shown in the UI; defaults to the slug
  "enabled": true,               // false disables every rule without touching their own "enabled"
  "position": 10,                // groups evaluate in ascending position, then slug; default 10
  "defaults": {                  // per-group defaults for the rule flags below (optional)
    "ignoreCase": true,
    "ignoreTrailingSlash": true,
    "query": "exact"
  },
  "rules": [
    { "from": "/about-us", "to": "/about" },
    { "id": "r_8f3a2c1d", "title": "Old blog", "from": "^/blog/(\\d+)/(.*)$", "regex": true, "to": "/news/$2", "code": 301 },
    { "from": "/promo", "to": "/sale", "code": 302, "query": "pass" },
    { "from": "/members", "when": { "type": "login" }, "to": "/dashboard", "else": "/login" },
    { "from": "/gone-forever", "action": "error", "code": 410 },
    { "from": "/old-api", "action": "pass", "to": "/wp-json/v1/thing" },
    { "from": "/ignore-me", "action": "nothing" },
    { "from": "/landing", "when": { "type": "referrer", "value": "google\\.", "regex": true }, "to": "/landing-google" },
    { "when": { "type": "404" }, "to": "/" , "enabled": false }
  ]
}
```

**Rule keys**

| key | type | default | meaning |
|---|---|---|---|
| `id` | string `^r_[a-f0-9]{8}$` | derived | stable identity for hit counts and the UI. The UI always writes one. A rule without one gets `r_` + first 8 hex of sha1 of `group slug + from + when + action` at load time; two rules in a group that derive the same id is a validation error (`duplicate_rule`). |
| `title` | string ≤ 200 | `""` | description; `<` and `>` refused like every label |
| `enabled` | bool | `true` | |
| `from` | string ≤ 2000 | required unless `when.type` is `404` | source path, or a regex when `regex` is true. Non-regex: must start with `/`; an absolute URL on the same host is reduced to its path at validation; an absolute URL on another host is a validation error (`use_when_server`), the message says to use `when: {type: "server"}`. |
| `regex` | bool | `false` | `from` is a PCRE body (no delimiters). Validation compiles it, refuses errors (`invalid_regex` with PHP's message), refuses patterns over 500 characters, and warns (`unanchored_regex`) when it has neither `^` nor `$`. Unanchored matching is kept for Redirection compatibility. |
| `to` | string ≤ 2000 | required for `url` and `pass` | path (`/...`) or absolute `http(s)://` URL. `$1`…`$9` and `${1}` substitutions when `regex`. Any other scheme is refused (`invalid_target`). |
| `else` | string | none | target when `when` evaluates false (Redirection's `url_notfrom`). Without it the rule falls through. Not allowed without `when`, nor with `action` other than `url`. |
| `code` | int | `301` for url; `404` for error; omitted for pass/nothing | url: 301, 302, 303, 307, 308. error: 400, 401, 403, 404, 410, 418, 451, 500, 501, 502, 503, 504. Anything else is `invalid_code`. |
| `action` | `url` \| `error` \| `pass` \| `nothing` | `url` | section 3 |
| `when` | object | none | condition; section 2. Absent = always. |
| `query` | `exact` \| `exactOrder` \| `ignore` \| `pass` | group default, else `exact` | section 3 |
| `ignoreCase` | bool | group default, else `true` | |
| `ignoreTrailingSlash` | bool | group default, else `true` | |
| `log` | bool | `true` | `false` excludes this rule from the redirect log and hit count |
| `headers` | object string→string | none | extra response headers on this redirect only (section 7 rules apply) |

Default flags are `true`/`true`/`exact`, Redirection's fresh-install defaults. They can be flipped in the settings file (`defaults`) for the whole site, per group, and per rule.

**Settings file**

```jsonc
// proto-redirects/settings.jsonc — every key optional
{
  "defaults": { "ignoreCase": true, "ignoreTrailingSlash": true, "query": "exact" },
  "log": {
    "redirects": 7,              // days to keep; -1 = do not log; 0 = forever; max 90
    "notFound": 7,               // same for 404s
    "ip": "none",                // "none" | "masked" | "full"
    "headers": false,            // store request headers (minus authorization, cookie, host, proxy-authorization)
    "external": false,           // also log redirects issued by other code (x_redirect_by)
    "trustedProxies": [],        // REMOTE_ADDRs allowed to supply a client-IP header
    "ipHeaders": []              // ordered: "CF-Connecting-IP" | "X-Forwarded-For" | "X-Real-IP" | "Client-IP" | "True-Client-IP"
  },
  "trackHits": true,
  "cache": 1,                    // Cache-Control max-age hours on 301/308: -1 no-cache headers, 0 none, 1, 24, 168
  "monitor": {
    "postTypes": [],             // public post types to watch; [] = off
    "group": "modified-posts",   // group file the monitor writes to (created when first needed)
    "trash": false,              // also add a disabled rule when a watched post is trashed
    "associated": ""             // e.g. "/feed/" adds a second rule with this suffix on both sides
  },
  "headers": {                   // sent on every front-end response ("site") or only with redirects ("redirect")
    "site": { "X-Content-Type-Options": "nosniff" },
    "redirect": {}
  },
  "canonical": {
    "https": false,              // 301 http → https
    "www": "",                   // "" | "www" | "nowww"
    "aliases": [],               // other hostnames 301'd to the site URL, max 20, host only
    "relocate": ""               // "" or an absolute URL: 301 everything except wp-admin, wp-login.php, REST
  },
  "coexist": false               // run even while the Redirection plugin is active
}
```

Every number is clamped and every unknown key is a `schema_invalid` error. The settings file has no slug; its REST path is `admin/schema-files/redirect-settings/settings`.

### 2. Conditions (`when`)

`when` is `{ "type": ..., ...fields }`. One condition per rule, like Redirection. Matched → `to`; not matched → `else` or fall through.

| type | fields | true when |
|---|---|---|
| `login` | — | user is logged in |
| `role` | `value` role or capability | `current_user_can(value)` |
| `referrer` | `value`, `regex` (bool, default false) | Referer equals value, or matches the case-insensitive regex |
| `agent` | `value`, `regex` | same against User-Agent |
| `header` | `name`, `value`, `regex` | request header `name` (case-insensitive; stored title-cased) equals/matches; absent → false |
| `cookie` | `name`, `value`, `regex` | cookie present and equals/matches |
| `ip` | `value` array of IPs or CIDRs (v4/v6) | client IP (section 6 resolution) is in any entry. CIDR is an addition over Redirection. |
| `language` | `value` array of language tags | any tag (case-insensitive prefix match, `en` matches `en-GB`) appears in Accept-Language |
| `server` | `value` host | request host equals value (case-insensitive, port ignored) |
| `filter` | `value` `^[a-z0-9_-]+$` | `apply_filters(value, false, $request)` returns truthy. Documented as "PHP decides", the only extension point into code. |
| `404` | — | WordPress resolved the request to a 404. These rules run at `template_redirect`; `from` is optional and, when present, must also match. |

`value` strings are ≤ 500 characters. Regex-flagged values are validated like `from`.

### 3. Actions and request handling

**Where it runs.** Front end only (`!is_admin()`, not WP-CLI, not cron, not XML-RPC). Excluded paths, never matched and never 404-logged: `/wp-admin/`, `/wp-login.php`, `/wp-cron.php`, `/xmlrpc.php`, the REST prefix (`rest_get_url_prefix()`), and `?rest_route=`. Filter `proto_blocks_redirects_excluded` (bool, request) can exclude more. Main pass on `init` priority 10 (filterable `proto_blocks_redirects_priority`); `404` rules and 404 logging on `template_redirect` 10; `redirect_canonical` returns false once a rule has matched or passed.

**Request normalisation.** From `REQUEST_URI`: control characters and invalid UTF-8 stripped; path and query split at the first `?`; path `rawurldecode`d once (so `%2F` behaves as `/`), double slashes collapsed to one, no trailing-slash change here. Query kept raw and also parsed to an array. Fragment never arrives. Host from `HTTP_HOST` lowercased without port; scheme from `is_ssl()`.

**Candidate selection.** Rules are loaded from files into memory once per request (already true for every other kind) and compiled into: an exact map keyed by `strtolower(rtrim(path,'/')) ?: '/'`, and an ordered regex list. The exact map holds each non-regex rule under its normalised `from`. The lookup takes the request's normalised path; the per-rule check then enforces the rule's own `ignoreCase` and `ignoreTrailingSlash` against the un-normalised path. Then the regex list in order. Order within each list is group `position`, group slug, then file order. First rule whose action fires wins. A group with `enabled: false` contributes nothing.

**Query modes.**
- `exact`: every source parameter present with equal value, no extra parameters, order ignored.
- `exactOrder`: raw query string equality.
- `ignore`: source parameters present and equal; extra parameters ignored and dropped.
- `pass`: as `ignore`; extra request parameters are appended to the target (target's own parameters win; nested arrays to depth 10; an existing fragment moves after the query).
- A regex rule matches `path?query` as one string and `query` is ignored (Redirection behaviour, documented).
- `ignoreCase` applies to query keys and values too.

**Actions.**
- `url`: `wp_redirect($target, $code, 'proto-blocks')` then `exit`. Core sends `X-Redirect-By: proto-blocks`. 301/308 get `Cache-Control`/`Expires` per `cache`; 302/303/307 get `nocache_headers()`. Rule `headers` and settings `headers.redirect` are sent first.
- `error`: `status_header($code)`, `nocache_headers()`, `X-Redirect-By`; the global query is set to 404 (`$wp_query->set_404()`) and the theme's 404 template renders, except 410 and 451 which also set the status line again at `send_headers` so caches see it.
- `pass`: internal rewrite. `$target` must be a path; `REQUEST_URI`, `PATH_INFO`, `QUERY_STRING` and `$_GET` are replaced and WordPress parses the new request. Canonical redirect suppressed. An absolute `to` is `invalid_target` at validation.
- `nothing`: stops processing, suppresses 404 logging and the canonical redirect for this request. Fires `proto_blocks_redirects_nothing`.

**Target safety (differs from Redirection, deliberately).**
1. A target written as a path must still be a path after substitution: leading `//` collapsed to `/`, a `\`, control character, or `scheme:` prefix in the result makes the rule not fire (and logs an `unsafe_target` issue once per request to the redirect log with code 0). This blocks open redirects through capture groups.
2. A target written absolute keeps its host: if substitution changes the host, the rule does not fire.
3. The final target is passed through `wp_sanitize_redirect()`.
4. Self-loop guard: a rule whose computed target normalises to the current request (same path, same query under the rule's mode) does not fire. Validation also refuses a non-regex rule whose `to` equals `from` (`self_redirect`).
5. Chain warning: validation reports `redirect_chain` (warning) when a non-regex rule's `to` equals another enabled rule's `from` in any group, naming both. Chains still work; the developer collapses them.
6. Regex matching uses `preg_match` and checks `preg_last_error()`; a backtrack or recursion limit error makes the rule not fire and records `regex_error` in the issues list for the UI.

**HTTP methods.** All methods are processed (Redirection behaviour). The docs say to use 307/308 when POST bodies must survive.

**Hit tracking.** When `trackHits` is true and the rule's `log` is true, a matched rule increments `hits` and sets `last_hit` for `(group, rule id)` in the hits table (one `INSERT … ON DUPLICATE KEY UPDATE`). Deleting a rule in the UI deletes its row; a rule removed in git leaves an orphan row that the admin's "Prune" button and `wp proto-blocks redirect prune` remove.

### 4. PHP declarations

```php
proto_redirect('/old', '/new');                       // 301
proto_redirect('/promo', '/sale', 302);
proto_redirect('/gone', null, 410);                   // action error
proto_redirect('^/blog/(.*)$', '/news/$1', ['regex' => true]);
proto_redirect('/members', '/dashboard', ['when' => ['type' => 'login'], 'else' => '/login']);

proto_redirect_group('Legacy site', [                 // a whole group, same keys as the file
    'position' => 5,
    'rules' => [ ['from' => '/a', 'to' => '/b'], … ],
]);
```

- `proto_redirect(string $from, ?string $to = null, int|array $codeOrArgs = 301)`. An int third argument is `code`; 4xx/5xx codes imply `action: error`. An array is the full rule. Rules declared this way join the virtual group `php` (title "Declared in PHP", position 100, not a file). `proto_redirect_group(string $title, array $group)` declares a group whose slug derives from the title.
- Both are callable until `init` priority 9 (sealed, `declared_too_late` warning after). Defined in `includes/Modules/Redirects/functions.php`, loaded from `proto-blocks.php` like the field functions.
- **File wins.** A file group with the same slug as a PHP group replaces it whole, with the `double_declaration` warning and badge. A PHP rule whose normalised `from` + `when` equals a file rule's is dropped with `double_declaration` too, naming the call site. Editing a PHP rule in the UI writes it into the chosen file group, which produces exactly that warning until the developer deletes the PHP line.
- PHP declarations are trusted (same as every other kind): no `UntrustedKeys` stripping. File declarations are stripped and the writer refuses unsafe keys. Note `when.type: "filter"` names a hook, not a callable: allowed in files, documented.

### 5. Database (runtime data only)

Three tables, created with `dbDelta` on activation and on `plugins_loaded` whenever `get_option('proto_blocks_redirects_db') !== '1'`; per site on multisite (`$wpdb->prefix`). Dropping them is `wp proto-blocks redirect db drop` only; deactivation keeps data; uninstall is out of scope (no uninstall.php exists today).

```
{prefix}proto_redirect_hits   group VARCHAR(60), rule_id CHAR(10), hits BIGINT UNSIGNED, last_hit DATETIME NULL,
                              PRIMARY KEY (group, rule_id)
{prefix}proto_redirect_log    id BIGINT AI PK, created DATETIME, url VARCHAR(2000), target VARCHAR(2000) NULL,
                              code SMALLINT, group VARCHAR(60) NULL, rule_id CHAR(10) NULL, redirect_by VARCHAR(50),
                              method VARCHAR(10), ip VARCHAR(45) NULL, agent VARCHAR(255) NULL, referrer VARCHAR(255) NULL,
                              request JSON/LONGTEXT NULL,  KEY created, KEY rule (group, rule_id), KEY url (url(191))
{prefix}proto_404_log         id BIGINT AI PK, created DATETIME, url VARCHAR(2000), method VARCHAR(10),
                              ip VARCHAR(45) NULL, agent VARCHAR(255) NULL, referrer VARCHAR(255) NULL, request LONGTEXT NULL,
                              KEY created, KEY url (url(191))
```

- Writes are prepared statements; `url` is decoded and `sanitize_text_field`ed, cut at 2000; agent/referrer cut at 255; method allow-listed and upper-cased.
- IP resolution: `REMOTE_ADDR`; if it is in `log.trustedProxies`, the first configured `log.ipHeaders` header that holds a valid IP (last non-empty item of a comma list) replaces it. Stored per `log.ip`: `none` → NULL, `masked` → last IPv4 octet / lower 64 bits zeroed, `full`.
- Retention: a daily cron `proto_blocks_redirects_prune` deletes rows older than `log.redirects` / `log.notFound` days in batches of 10,000; a full batch reschedules itself in 10 minutes. `-1` disables writing; `0` keeps forever. 404 logging also skips when a `nothing` rule matched or the `proto_blocks_redirects_log_404` filter returns false; identical URL+IP within the same minute is not duplicated (one UPDATE instead) to blunt scanners.
- `docs/content-model-files.md` "What is stored in the database" gains these tables and the option, with the sentence: configuration is never stored here; delete these tables and every redirect still works.

### 6. Admin UI

Submenu **Redirects** under Proto-Blocks, hook `proto-blocks-redirects`, screen `redirects`, registered exactly like the other content-model screens (`AdminApp::SCREENS`, `bootstrap.ts`, `screens/index.ts`, `ContentModelMenu`, `jest.setup.ts` preload). Tabs are client-side (`?tab=`), state in the URL like the other screens.

**Redirects tab.** Table of every rule across groups: enabled switch, source, target/action, code, condition badge, group, hits, last hit, origin badge (File / PHP / Declared twice). Toolbar: search (source, target, title), filters (group, code, condition, enabled), "Add redirect", group menu (create, rename title, enable/disable, reorder position, delete). Row actions: edit, move up/down within group, duplicate, delete. Bulk: enable, disable, delete, move to group, reset hits. The add/edit dialog is a react-hook-form + zod form mirroring the schema: Source, Target, Code (select with meanings), Match condition (select, reveals its fields), Action, Query mode, Ignore case, Ignore trailing slash, Title, Group, Log. A live "Test" box: type a URL, see which rule fires (calls `admin/redirects/test`). Every write edits the group file's JSONC text client-side with `jsonc-parser` `modify` (comments survive) and posts it through the existing schema-files route with the hash; conflict and guardrail dialogs are reused. Moving a rule between groups is a delete in one file and an insert in another, in that order, each with its own hash.

**404s tab.** Grouped by URL: URL, count, last seen, last referrer; expand shows recent individual hits. Actions: "Add redirect" (opens the dialog with Source prefilled and Target empty; on save, the matching 404 rows are deleted), "Ignore" (adds an `action: nothing` rule), delete group, clear all. Filters: URL contains, date range. Export CSV.

**Log tab.** Redirect log table: time, URL, target, code, rule (link to edit), redirect by, IP, agent, referrer. Filters: URL, rule, code, date. Clear all, delete selected, export CSV.

**Import / Export tab.** Upload (`.csv`, `.json`, `.htaccess`/`.txt`, `_redirects`) → server parses (`admin/redirects/import/parse`) → preview table with per-row status (new / duplicate / invalid with reason) → choose target group and duplicate mode (skip / replace / add) → the client writes the group file. "Import from plugin" lists only the plugins whose data is present on this site (section 9), with preview, then writes the same way, with an optional "deactivate source plugin" checkbox that calls `deactivate_plugins` through `admin/redirects/import/deactivate` (`activate_plugins` capability). Export: pick group or all, format CSV / JSON / Apache / Nginx / `_redirects`; the browser downloads the server's output (`admin/redirects/export`).

**Settings tab.** Form over `settings.jsonc` with the sections of section 1; writes through `redirect-settings`. A "Prune orphan hit rows" button and a "Create tables" button when the DB check reports missing tables.

**Read-only hosts.** `ReadOnlyBanner` with the existing reasons; every write control disabled with `aria-describedby`. Logs and 404s remain usable (DB), except "Add redirect".

**Coexistence notice.** When Redirection is active and `coexist` is false, a banner on every tab: "Redirection is active, Proto-Blocks redirects are paused. Import your rules, then deactivate Redirection." with an Import button.

### 7. Headers and canonical

- Settings `headers.site` are sent on `send_headers` for every non-excluded front-end request; `headers.redirect` and a rule's `headers` are sent before a `url` redirect. Header names must match `^[A-Za-z0-9-]{1,100}$`, values ≤ 1000 characters with CR/LF/control characters refused (`invalid_header`). `Set-Cookie`, `Location`, `Status`, `Content-Length`, `Transfer-Encoding` are refused as names.
- Canonical (`settings.canonical`) runs first on `init`: https upgrade, www/nowww, aliases → site host, relocate (except `/wp-admin`, `/wp-login.php`, REST). One 301 preserving path and query. Filter `proto_blocks_redirects_canonical_target`.

### 8. Permalink monitor

When `monitor.postTypes` is non-empty and permalinks are enabled: on `pre_post_update` remember the permalink; on `post_updated` priority 11, if both statuses are `publish`, the type is watched, and the path changed (and the old path is not `/`), append `{from: old path, to: new path, title: "<post title> (moved <date>)"}` to the group file named in `monitor.group`, creating the group file from the template if needed. If a rule in any group has `from` equal to the new path, it is set `enabled: false` in its file (loop avoidance) with a notice. `monitor.associated` appends a second rule with the suffix on both sides. `monitor.trash` appends a disabled rule to `/` when a watched post is trashed. On an unwritable theme the monitor records an `Issue` (warning `monitor_unwritable`, shown on the screen and in `validate`) and does nothing else. Server-side writes use the serializer in section 10 (comments inside that one group file are not preserved; the file header says so).

### 9. Import and export

All parsers are pure PHP classes under `Modules/Redirects/Import/` and `Export/`, taking strings and returning `Rule` arrays plus per-row diagnostics; the REST layer and CLI wrap them.

- **CSV import**: delimiter detection `,` `;` `|`; columns by position `source, target, regex, code` (header row recognised and skipped); 4xx code → `error`; missing regex column → metacharacter sniff `()[]$^*`; source == target skipped; `[FORMULA] ` prefix removed. **CSV export**: `source,target,regex,code,type,hits,title,status,group`; cells starting with `= + - @` (ASCII and full-width) prefixed `[FORMULA] `.
- **JSON import**: accepts Proto-Blocks' own export (`{ "groups": { "<slug>": <group file object> }, "settings"?: {...} }`) and Redirection's JSON (`plugin`, `redirects[]`, `groups[]`), mapping `match_type`/`action_type`/`action_data`/`match_data.source` flags to section 1 keys. **JSON export** is the Proto-Blocks shape.
- **Apache import**: `RewriteRule`, `Redirect`, `RedirectMatch`, `RewriteCond %{HTTP_REFERER|HTTP_USER_AGENT|HTTP_HOST|QUERY_STRING}` preceding a rule; flags `NC`, `QSA`, `R=`, `L`, `F`, `G`; word codes; `^/?$` → trailing-slash flag. **Apache export**: `<IfModule mod_rewrite.c>` block between `# Proto-Blocks redirects` / `# End Proto-Blocks redirects`, enabled rules only, `R=code,L`, `NC`, `QSA` for pass, `NE` when target has `#`/`%`, 403 → `F`, 410 → `G`; conditions referrer/agent/server/query exported, the rest commented `# skipped: <reason>`.
- **Nginx export**: `rewrite ^…$ target permanent|redirect;`, `(?i)` for ignoreCase, `location = /path { return code; }` for errors, `if ($http_user_agent ~* …)` for agent/referrer; others commented.
- **`_redirects` (Netlify) import/export**: `from to [status]`, `#` comments, `*`/`:splat`, `:name` placeholders rejected.
- **Plugin importers** (interface `PluginImporter { id(), label(), available(): bool, preview(): Rule[], cleanupHint(): ?string }`): Redirection (`{prefix}redirection_items` + groups → one group file per Redirection group, preserving title, enabled, position, flags and conditions; hits are *not* imported since they are not configuration), Simple 301 Redirects (option `301_redirects`), Safe Redirect Manager (`_redirect_rule_*` meta), Rank Math (`rank_math_redirections`), Yoast SEO Premium (`wpseo-premium-redirects-base`), WordPress old slugs (`_wp_old_slug`). `available()` checks the table or option exists and is non-empty.
- **Duplicate modes**: `skip` (existing source+regex+when kept), `replace` (existing rule's target/code/flags overwritten, id kept), `add` (always append). Default `skip`.

### 10. Server-side writing

`RedirectFileSerializer::serialize(array $group): string` emits a stable, commented JSONC: a header comment ("Managed by Proto-Blocks. Edit by hand or in wp-admin → Proto-Blocks → Redirects. Hand-written comments inside the rules array survive UI edits but not CLI or monitor writes."), two-space indent, one rule per line in the canonical key order of section 1, flags omitted when equal to the group default. `DeclarationTemplate` gains `redirects` and `redirect-settings` templates. CLI `add`, the monitor and the 404 "Add redirect" (when the client is not involved, i.e. CLI) write through `SchemaFileWriter::write` with the serializer output. UI writes keep comments via `jsonc-parser` as every other screen does.

### 11. REST

Namespace `proto-blocks/v1`, class `API\Admin\RedirectsRoutes` in `ROUTE_GROUPS`, read `manage_options`, writes through the existing `writePermission()`:

- `GET admin/redirects` → `{ moduleEnabled, paused (coexistence), createTarget, groups: [{slug,title,enabled,position,origin,double,writable,hash,rules:[{…rule, hits, lastHit, issues[]}]}], settings, settingsFile: {exists,hash,writable}, issues[], db: {tables: bool, orphans: int} }`.
- `GET admin/redirects/test?url=` → `{ matched: bool, group, ruleId, action, code, target, trace: [{group, ruleId, result, reason}] }` (evaluates without redirecting; `login`/`role` conditions use the calling admin's session and say so).
- `GET admin/redirects/log`, `GET admin/redirects/404` with `page`, `per_page` (5–200), `search`, `code`, `rule`, `from`, `to`, `group_by=url`; `DELETE` with `ids[]` or `all=1` (+ current filter); `GET …/export?format=csv`.
- `POST admin/redirects/hits/reset` `{group, ruleId}` and `POST admin/redirects/hits/prune`.
- `POST admin/redirects/import/parse` multipart or `{format, text}` → `{ rules:[{rule, status, reason}], groups? }`; `GET admin/redirects/import/plugins` → available importers; `POST admin/redirects/import/plugins/{id}/preview`; `POST admin/redirects/import/deactivate` (`activate_plugins`).
- `GET admin/redirects/export?format=csv|json|apache|nginx|redirects&group=` → file response.
- `POST admin/redirects/db/install`.
- Files: the existing `admin/schema-files/redirects/{slug}` and `admin/schema-files/redirect-settings/settings`.

### 12. CLI

`wp proto-blocks redirect <subcommand>`:
`list [--group=] [--format=table|json|csv]`, `add <from> [<to>] [--code=] [--group=] [--regex] [--when=<type>] [--when-value=] [--action=] [--query=] [--title=]`, `delete <id> [--group=]`, `enable <id>`, `disable <id>`, `group list|create <title>|enable|disable|delete <slug>`, `validate` (also folded into `wp proto-blocks validate`), `test <url> [--logged-in]`, `import <file> [--format=csv|json|apache|redirects] [--group=] [--duplicates=skip|replace|add] [--dry-run]`, `import-plugin <id> [--group=] [--dry-run]`, `export [--format=csv|json|apache|nginx|redirects] [--group=] [<file>|-]`, `log [--404] [--limit=] [--clear]`, `prune`, `db install|status|drop [--yes]`.

### 13. Security summary

- Files: `manage_options`, file-mods, multisite super-admin, confinement, hash conflict, `UntrustedKeys`, size cap: all inherited from `SchemaFileWriter`.
- No PHP execution from files. `when.type: filter` names a hook string only.
- Open redirect: section 3 target rules 1–3; `wp_sanitize_redirect`; schemes limited to http/https.
- Loops: self-loop guard, chain warning.
- Regex: compile at write, 500-char cap, `preg_last_error` check, the `s` modifier only plus `i` when ignoreCase; `u` added when the pattern is valid UTF-8 and compiles with it.
- Headers: name/value grammar, forbidden names, CRLF refused.
- Logs: IP policy default `none`; sensitive request headers never stored; log reads `manage_options`; export CSV formula-escaped; log `url` column is `esc_html`ed in the UI (React does this by default; the export is the risk, hence the prefix).
- Import: parsers never execute anything; sizes capped at 5 MB; plugin importers read with prepared queries and only when `manage_options`; deactivating the source plugin requires `activate_plugins`.
- `pass` is internal-only; absolute targets refused.
- DB writes are prepared; the hits upsert is bounded to one row per request.

### 14. Testing

- **PHPUnit** (no WordPress): request normaliser table tests (encoded slashes, double slashes, unicode, trailing slash, case, empty path, query parsing); every condition type with matched/else/fall-through; every action; every query mode including `pass` merging and fragment order; regex substitution, invalid regex refusal, unanchored warning, length cap, backtrack error path; target safety 1–4 (`//` collapse, `\`, `javascript:`, host change, self-loop); chain detection; group ordering and `enabled:false`; PHP declarations, double declaration, sealing; file kind (loader, validator every error code, extendKind write, template, serializer round-trip and stability, hand comments preserved by a jsonc modify fixture); settings clamping; IP resolution with trusted proxies and masking v4/v6; log row shaping and truncation; retention batching; every importer and exporter with fixtures, CSV formula escaping, duplicate modes; REST routes (permissions, shapes, error codes, export headers); CLI commands via the existing CLI test harness; coexistence pause. New stubs (`$wpdb` double, `wp_redirect`, `wp_safe_redirect`, `wp_sanitize_redirect`, `status_header`, `nocache_headers`, `home_url`, `is_ssl`, `is_user_logged_in`, `is_404`, `wp_schedule_event`, `wp_next_scheduled`, `rest_get_url_prefix`, `get_post_types`, `get_permalink`, `deactivate_plugins`, `is_plugin_active`) go in `tests/php/bootstrap.php` only.
- **Jest**: the screen's five tabs with `mockApi`; add/edit dialog validation mirrors every schema error; enable toggle writes the expected JSONC text (snapshot of the modify result including a preserved comment); move between groups; 404 → Add redirect prefill and deletion; import preview statuses; read-only mode disables writes; coexistence banner; test box trace rendering.
- **Playwright** (wp-env, fixture theme): write a group file into the theme and assert the front end returns 301/302/410/pass/nothing with the right `Location` via `request` (no browser follow); query modes and case/trailing flags on real requests; a 404 appears in the 404s tab, "Add redirect" creates the rule and the next request redirects; create/edit/toggle/reorder/delete through the UI and read the file back with `themeFile`; PHP-declared rule shows the PHP badge, editing it produces the declared-twice badge; CSV import via the upload control; export download has the expected header; settings save writes `settings.jsonc`; read-only mode (chmod the folder); log tab shows the hit; monitor: rename a published post's slug and assert the rule appears in `modified-posts/redirects.jsonc`; screenshot baselines for each tab (macOS only, as today).
- **Edge cases to pin** (each gets a named test): `%2F` in path; `//double//slash`; trailing-slash on `/` root; `from` with a literal `?` escaped as `\?`; unicode source and request (`/café` vs `/caf%C3%A9`); IDN host in `server`; query parameter arrays `a[]=1&a[]=2`; same key twice; fragment in target with `pass`; 308 on POST; `HEAD` request; a rule in a disabled group with `enabled: true`; two groups same position; child theme overriding a parent group; file and PHP both declaring the same source; a 256 KB file; an invalid JSONC file (the group is skipped, others still run, an issue is shown); regex that matches everything (`.*`) placed first (it wins, chain/loop guards still hold); `X-Forwarded-For` spoofing from an untrusted address; IPv6 masking; log retention `0` and `-1`; DST-free `created` comparisons (UTC); multisite table prefix; Redirection active with `coexist` false and true.

### 15. Documentation and skill

- New `docs/redirects.md` in the house pattern (At a glance; Declare in PHP / in a file; Every key; Conditions; Actions; Query and flags; Regex; Safety rules; Groups and order; Logs and 404s; Monitor; Headers and canonical; Import and export; The Redirects screen; REST routes; CLI; Troubleshooting).
- Update `README.md` (Features bullet, Content model table, admin screens paragraph, filters table, For AI agents list), `docs/content-model-files.md` (layout, slug rules, "What is stored in the database"), `docs/cli.md`, `docs/rest-api.md`, `docs/trust-and-security.md`, `CHANGELOG.md`.
- Skill repo `protoblocks-skill` → 2.5.0: new `skills/protoblocks/references/redirects.md` with the syntax, recipes (migrate from Redirection, catch a renamed section, geo/role landing pages, retire a URL with 410), and the edge-case list; pointers from `SKILL.md`, `content-model.md`, `content-model-recipes.md`, `cli-and-hooks.md`, `admin-and-cli.md`; CHANGELOG. Pushed and installed locally like 2.4.0.

### 16. Modules and code layout

`includes/Modules/Redirects/`: `RedirectsModule` (ModuleInterface, id `redirects`, boots everything below when enabled), `Definition/{Rule,Group,Settings,RuleNormalizer,RuleValidator,GroupValidator,SettingsValidator}`, `Sources/{GroupFiles,PhpDeclarations}`, `Registry` (merges sources, precedence, issues, compiled index), `Engine/{Request,Matcher,Conditions/*,Actions/*,QueryMatcher,TargetBuilder,RegexGuard,Exclusions,Canonical,Headers}`, `Storage/{Schema,Installer,Hits,RedirectLog,NotFoundLog,IpResolver,Retention}`, `Monitor`, `Import/*`, `Export/*`, `Serializer`, `functions.php`; `API/Admin/RedirectsRoutes`; `CLI/RedirectCommand`; `src/admin/screens/RedirectsScreen.tsx` + `src/admin/redirects/*` (tabs, dialog, schema.ts, jsonc edits, api hooks). Schema: `includes/ContentModel/schemas/redirects.schema.json` with `$defs/group`, `$defs/rule`, `$defs/settings`, consumed by both PHP `SchemaCheck` and the zod mirror.

### 17. What "done" means

All of sections 1–15 implemented; PHPUnit, Jest, scripts, E2E green locally and in the four Docker images; CI green; `docs/` and README updated; skill 2.5.0 pushed and installed; v2.16.0 released by fast-forwarding `main`; the release zip verified (checksum, version, bundles); the three client sites can import their Redirection rules from the UI.

## Left to the builder

- Exact zod/JSON Schema authoring; React component split inside `src/admin/redirects/`; table virtualisation if a site has more than ~2,000 rules (not required).
- The pretty-printer's exact comment text.
- CIDR parsing implementation (no library; v4 and v6 bit masks).
- Playwright helpers for raw HTTP assertions (`request.get` with `maxRedirects: 0`).

## Calls made by the author, open to objection

- One file per group (`proto-redirects/<slug>/redirects.jsonc`), not one file per rule, and `settings.jsonc` beside the group folders.
- Stable rule ids `r_xxxxxxxx` written by the UI and derived when absent.
- Default flags `ignoreCase: true`, `ignoreTrailingSlash: true`, `query: exact` (Redirection's fresh-install defaults), overridable at three levels.
- Unanchored regex kept for migration compatibility, with a warning.
- Hit counts, redirect log and 404 log in three DB tables; everything else in files.
- `pass` internal only; no shortcodes; no random action; no automatic `.htaccess` writing; no granular capabilities.
- Pausing while the Redirection plugin is active unless `coexist: true`.
- Server-side writes (CLI, monitor) do not preserve hand-written comments; UI writes do.
- 404 de-duplication per URL+IP per minute.
- `manage_options` for logs as well as rules.
