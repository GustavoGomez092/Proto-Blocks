# Redirects

> **At a glance**
> - **What:** a redirect manager with Redirection's feature set. Every rule and every setting is a file in the theme. The database holds only hit counts, the redirect log and the 404 log.
> - **Where:** `your-theme/proto-redirects/<group>/redirects.jsonc` for rules, `your-theme/proto-redirects/settings.jsonc` for module settings.
> - **PHP:** `proto_redirect('/old', '/new');` in `functions.php`.
> - **Admin:** Proto Blocks > Redirects (`admin.php?page=proto-blocks-redirects`) has tabs for Redirects, 404s, Log, Import / Export and Settings.
> - **CLI:** `wp proto-blocks redirect list|add|delete|enable|disable|group|validate|test|import|import-plugin|export|log|prune|db`.

A rule says "this request goes there".
Rules live in version control with the rest of the theme, so they are reviewed, diffed and identical in every environment.
The module is independent: switch it off with the `redirects` key of the `proto_blocks_modules` filter and nothing else changes.

Related: [content model files](content-model-files.md), [scripts](scripts.md), [REST API](rest-api.md#redirects), [CLI](cli.md#wp-proto-blocks-redirect), [trust and security](trust-and-security.md#redirect-targets).

## Declare a redirect

### In PHP

```php
// functions.php
proto_redirect('/old', '/new');                       // 301
proto_redirect('/promo', '/sale', 302);
proto_redirect('/gone', null, 410);                   // action error
proto_redirect('^/blog/(.*)$', '/news/$1', ['regex' => true]);
proto_redirect('/members', '/dashboard', ['when' => ['type' => 'login'], 'else' => '/login']);

proto_redirect_group('Legacy site', [                 // a whole group, same keys as the file
    'position' => 5,
    'rules' => [ ['from' => '/a', 'to' => '/b'] ],
]);
```

Signatures:

```php
proto_redirect(string $from, ?string $to = null, int|array $codeOrArgs = 301): void
proto_redirect_group(string $title, array $group): void
```

- An int third argument is `code`. A 4xx or 5xx code implies `action: error`. An array is the full rule.
- Rules declared this way join the virtual group `php` (title "Declared in PHP", position 100). It is not a file.
- `proto_redirect_group()` derives the group slug from the title.
- Both functions are callable until `init` priority 9. A call after that is ignored with a `declared_too_late` warning.
- PHP declarations are trusted code, like every other kind. File declarations are stripped of unsafe keys.

**File wins.**
A file group with the same slug as a PHP group replaces it whole, with the `double_declaration` warning and badge.
A PHP rule whose normalised `from` and `when` equal a file rule's is dropped with `double_declaration`, naming the call site.
Editing a PHP rule in the UI writes it into the chosen file group, which produces exactly that warning until you delete the PHP line.

### In a file

```jsonc
// proto-redirects/legacy-site/redirects.jsonc
{
  "title": "Legacy site",
  "enabled": true,
  "position": 10,
  "defaults": { "ignoreCase": true, "ignoreTrailingSlash": true, "query": "exact" },
  "rules": [
    { "from": "/about-us", "to": "/about" },
    { "id": "r_8f3a2c1d", "title": "Old blog", "from": "^/blog/(\\d+)/(.*)$", "regex": true, "to": "/news/$2", "code": 301 },
    { "from": "/promo", "to": "/sale", "code": 302, "query": "pass" },
    { "from": "/members", "when": { "type": "login" }, "to": "/dashboard", "else": "/login" },
    { "from": "/gone-forever", "action": "error", "code": 410 },
    { "from": "/old-api", "action": "pass", "to": "/wp-json/v1/thing" },
    { "from": "/ignore-me", "action": "nothing" },
    { "when": { "type": "404" }, "to": "/", "enabled": false }
  ]
}
```

- The folder name is the group slug: `^[a-z][a-z0-9_-]*$`, 60 characters at most. `settings` is reserved because it is the settings file.
- `redirects.json` is accepted. When both exist, `.jsonc` wins with the `both_extensions` warning.
- A child theme's group beats the parent's group of the same slug.
- The `proto_blocks_schema_paths` filter adds read-only roots, as for every other kind.
- A file is capped at 256 KB. A group that outgrows it is split by the developer; the validator says so.
- An invalid JSONC file is skipped and reported. Other groups still run.

## Every key

### Group

| Key | Type | Default | Notes |
|---|---|---|---|
| `title` | string | the slug | Shown in the UI. |
| `enabled` | bool | `true` | `false` disables every rule in the group without touching their own `enabled`. |
| `position` | int | `10` | Groups evaluate in ascending position, then slug. |
| `defaults` | object | none | Per-group defaults for `ignoreCase`, `ignoreTrailingSlash` and `query`. |
| `rules` | list | `[]` | Ordered. |

### Rule

| Key | Type | Default | Meaning |
|---|---|---|---|
| `id` | `^r_[a-f0-9]{8}$` | derived | Stable identity for hit counts and the UI. The UI always writes one. A rule without one gets `r_` plus the first 8 hex characters of the sha1 of group slug + `from` + `when` + `action` at load time. Two rules in one group that derive the same id are a `duplicate_rule` error. |
| `title` | string, 200 chars at most | `""` | Description. `<` and `>` are refused. |
| `enabled` | bool | `true` | |
| `from` | string, 2000 chars at most | required unless `when.type` is `404` | Source path, or a regex when `regex` is true. A non-regex source must start with `/`. An absolute URL on the same host is reduced to its path at validation. An absolute URL on another host is `use_when_server`: use `when: {type: "server"}`. |
| `regex` | bool | `false` | `from` is a PCRE body without delimiters. See [regex](#regex-and-capture-groups). |
| `to` | string, 2000 chars at most | required for `url` and `pass` | A path (`/...`) or an absolute `http(s)://` URL. `$1` to `$9` and `${1}` substitute when `regex` is true. Any other scheme is `invalid_target`. |
| `else` | string | none | Target when `when` is false. Without it the rule falls through. Not allowed without `when`, nor with an `action` other than `url`. |
| `code` | int | `301` for `url`, `404` for `error` | `url`: 301, 302, 303, 307, 308. `error`: 400, 401, 403, 404, 410, 418, 451, 500, 501, 502, 503, 504. Anything else is `invalid_code`. Omitted for `pass` and `nothing`. |
| `action` | `url`, `error`, `pass`, `nothing` | `url` | See [actions](#actions-and-codes). |
| `when` | object | none | A condition. Absent means always. See [conditions](#conditions). |
| `query` | `exact`, `exactOrder`, `ignore`, `pass` | group default, else `exact` | See [query modes](#query-modes-and-flags). |
| `ignoreCase` | bool | group default, else `true` | |
| `ignoreTrailingSlash` | bool | group default, else `true` | |
| `log` | bool | `true` | `false` keeps this rule out of the redirect log and the hit count. |
| `headers` | object, string to string | none | Extra response headers on this redirect only. See [headers](#http-headers-and-canonical). |

Flag defaults resolve rule, then group `defaults`, then the settings file `defaults`, then `true` / `true` / `exact`.

## Conditions

`when` is `{ "type": ..., ...fields }`.
One condition per rule.
Matched sends the request to `to`; not matched goes to `else`, or falls through to the next rule.

| type | Fields | True when |
|---|---|---|
| `login` | none | the user is logged in |
| `role` | `value` role or capability | `current_user_can(value)` |
| `referrer` | `value`, `regex` (default false) | the Referer equals the value, or matches the case-insensitive regex |
| `agent` | `value`, `regex` | the same against User-Agent |
| `header` | `name`, `value`, `regex` | the request header `name` (case-insensitive) equals or matches. An absent header is false. |
| `cookie` | `name`, `value`, `regex` | the cookie is present and equals or matches |
| `ip` | `value`: array of IPs or CIDRs, v4 or v6 | the client IP is in any entry. See [IP policy](#redirect-log-and-404-log). |
| `language` | `value`: array of language tags | any tag appears in Accept-Language. Matching is a case-insensitive prefix: `en` matches `en-GB`. |
| `server` | `value` host | the request host equals the value, case-insensitive, port ignored |
| `filter` | `value` matching `^[a-z0-9_-]+$` | `apply_filters(value, false, $request)` returns a truthy value. This is the one extension point into PHP. It names a hook, not a callable, so it is allowed in files. |
| `404` | none | WordPress resolved the request to a 404 |

```jsonc
{ "from": "/landing", "when": { "type": "referrer", "value": "google\\.", "regex": true }, "to": "/landing-google" }
```

- `value` strings are 500 characters at most. A regex-flagged value is validated like `from`.
- A `404` rule runs at `template_redirect`, not on `init`. `from` is optional; when present it must also match.
- `login` and `role` depend on the session. `redirect test` and the screen's Test box use the calling admin's session and say so.

## Actions and codes

| `action` | Effect |
|---|---|
| `url` | `wp_redirect($target, $code, 'proto-blocks')`, then `exit`. Core sends `X-Redirect-By: proto-blocks`. 301 and 308 get `Cache-Control` and `Expires` per the `cache` setting. 302, 303 and 307 get `nocache_headers()`. Rule `headers` and `headers.redirect` are sent first. |
| `error` | Sends the status line, `nocache_headers()` and `X-Redirect-By`, sets the query to 404 and renders the theme's 404 template. 410 and 451 also set the status line again at `send_headers` so caches see it. |
| `pass` | An internal rewrite. `to` must be a path. `REQUEST_URI`, `PATH_INFO`, `QUERY_STRING` and `$_GET` are replaced and WordPress parses the new request. The canonical redirect is suppressed. An absolute `to` is `invalid_target`. Proto-Blocks never proxies an external URL. |
| `nothing` | Stops processing. Suppresses 404 logging and the canonical redirect for this request. Fires the `proto_blocks_redirects_nothing` action. |

All HTTP methods are processed.
Use 307 or 308 when a POST body must survive.

**Where it runs.**
Front end only: not `is_admin()`, not WP-CLI, not cron, not XML-RPC.
The main pass runs on `init` priority 10 (filter `proto_blocks_redirects_priority`).
`404` rules and 404 logging run on `template_redirect` priority 10.
`redirect_canonical` returns false once a rule has matched or passed.

**Excluded paths.**
These are never matched and never 404-logged: `/wp-admin/`, `/wp-login.php`, `/wp-cron.php`, `/xmlrpc.php`, the REST prefix (`rest_get_url_prefix()`) and `?rest_route=`.
The `proto_blocks_redirects_excluded` filter (bool, request) can exclude more.

## Query modes and flags

| `query` | Matches when |
|---|---|
| `exact` | every source parameter is present with an equal value and there are no extra parameters. Order is ignored. |
| `exactOrder` | the raw query string is equal. |
| `ignore` | source parameters are present and equal. Extra parameters are ignored and dropped. |
| `pass` | as `ignore`, and the extra request parameters are appended to the target. The target's own parameters win. Nested arrays go to depth 10. An existing fragment moves after the query. |

- `ignoreCase` applies to the path and to query keys and values.
- `ignoreTrailingSlash` makes `/about` and `/about/` the same source.
- A regex rule matches `path?query` as one string and `query` is ignored.

**Request normalisation.**
Control characters and invalid UTF-8 are stripped.
The path and query split at the first `?`.
The path is `rawurldecode`d once, so `%2F` behaves as `/`.
Double slashes collapse to one.
The query is kept raw and also parsed to an array.
The host is lowercased without the port.

**Candidate selection.**
Rules compile once per request into an exact map keyed by the lowercased, slash-trimmed source and an ordered list of regex rules.
The exact map is looked up first, and each candidate then checks its own `ignoreCase` and `ignoreTrailingSlash`.
The regex list follows in order.
The first rule whose action fires wins.

## Regex and capture groups

```jsonc
{ "from": "^/blog/(\\d+)/(.*)$", "regex": true, "to": "/news/$2" }
```

- Write the body only, no delimiters. A literal `?` is `\\?` inside JSON.
- Validation compiles the pattern. A compile error is `invalid_regex` with PHP's message. A pattern over 500 characters is refused.
- A pattern with neither `^` nor `$` is allowed but warns `unanchored_regex`. Unanchored matching is kept for Redirection compatibility.
- Matching uses `preg_match` and checks `preg_last_error()`. A backtrack or recursion limit error makes the rule not fire and records `regex_error` in the issues list.
- The `s` modifier is always set, `i` when `ignoreCase` is true, and `u` when the pattern is valid UTF-8 and compiles with it.
- `$1` to `$9` and `${1}` substitute into `to`.
- A rule that matches everything (`.*`) placed first wins. The safety rules below still hold.

## Safety rules

### Open redirect

1. A target written as a path must still be a path after substitution. A leading `//` collapses to `/`. A backslash, a control character or a `scheme:` prefix in the result makes the rule not fire. One `unsafe_target` issue per request goes to the redirect log with code 0.
2. A target written absolute keeps its host. If substitution changes the host, the rule does not fire.
3. The final target goes through `wp_sanitize_redirect()`.
4. Only `http` and `https` schemes are accepted.

### Loops and chains

- A rule whose computed target normalises to the current request (same path, same query under the rule's mode) does not fire.
- Validation refuses a non-regex rule whose `to` equals `from` (`self_redirect`).
- Validation warns `redirect_chain` when a non-regex rule's `to` equals another enabled rule's `from` in any group, naming both. Chains work. Collapse them.

### Regex limits

500 characters, compiled at write time, `preg_last_error()` checked on every match. See [regex](#regex-and-capture-groups).

### Excluded paths

Login, admin, cron, XML-RPC and REST are never touched. See [actions and codes](#actions-and-codes).

## Groups and order

- Groups evaluate in ascending `position`, then slug. Inside a group, rules run in file order.
- Exact-match rules are looked up first, then regex rules in the same order.
- A group with `enabled: false` contributes nothing, even for rules with `enabled: true`.
- Rule ids are unique inside a group. Hit counts are keyed by group and id.

## Settings file

```jsonc
// proto-redirects/settings.jsonc, every key optional
{
  "defaults": { "ignoreCase": true, "ignoreTrailingSlash": true, "query": "exact" },
  "log": {
    "redirects": 7,
    "notFound": 7,
    "ip": "none",
    "headers": false,
    "external": false,
    "trustedProxies": [],
    "ipHeaders": []
  },
  "trackHits": true,
  "cache": 1,
  "monitor": { "postTypes": [], "group": "modified-posts", "trash": false, "associated": "" },
  "headers": { "site": { "X-Content-Type-Options": "nosniff" }, "redirect": {} },
  "canonical": { "https": false, "www": "", "aliases": [], "relocate": "" },
  "coexist": false
}
```

| Key | Meaning |
|---|---|
| `defaults` | Site-wide flag defaults for every rule. |
| `log.redirects` | Days to keep the redirect log. `-1` do not log, `0` forever, maximum 90. |
| `log.notFound` | The same for the 404 log. |
| `log.ip` | `none`, `masked` or `full`. |
| `log.headers` | Store request headers, minus `authorization`, `cookie`, `host` and `proxy-authorization`. |
| `log.external` | Also log redirects issued by other code (`x_redirect_by`). |
| `log.trustedProxies` | `REMOTE_ADDR` values allowed to supply a client-IP header. |
| `log.ipHeaders` | Ordered: `CF-Connecting-IP`, `X-Forwarded-For`, `X-Real-IP`, `Client-IP`, `True-Client-IP`. |
| `trackHits` | Count hits per rule. |
| `cache` | `Cache-Control` max-age hours on 301 and 308: `-1` no-cache headers, `0` none, `1`, `24`, `168`. |
| `monitor.postTypes` | Public post types to watch. `[]` is off. |
| `monitor.group` | Group file the monitor writes to. Created when first needed. |
| `monitor.trash` | Also add a disabled rule when a watched post is trashed. |
| `monitor.associated` | A suffix such as `/feed/` that adds a second rule with the suffix on both sides. |
| `headers.site` | Sent on every front-end response. |
| `headers.redirect` | Sent only with redirects. |
| `canonical.https` | 301 http to https. |
| `canonical.www` | `""`, `www` or `nowww`. |
| `canonical.aliases` | Other hostnames 301'd to the site URL. Host only, 20 at most. |
| `canonical.relocate` | `""` or an absolute URL. Sends everything except wp-admin, wp-login.php and REST there with a 301. |
| `coexist` | Run even while the Redirection plugin is active. |

Every number is clamped and an unknown key is a `schema_invalid` error.
The settings file has no slug. Its REST path is `admin/schema-files/redirect-settings/settings`.

## Hit counts

When `trackHits` is true and the rule's `log` is true, a matched rule increments `hits` and sets `last_hit` for its group and id.
That is one `INSERT ... ON DUPLICATE KEY UPDATE`, one row per request at most.

- Deleting a rule in the UI deletes its row.
- A rule removed in the files leaves an orphan row. The Prune button and `wp proto-blocks redirect prune` remove orphans.
- Hits are not configuration, so they are never imported as rules.

## Redirect log and 404 log

Three tables, per site on multisite (`$wpdb->prefix`):

| Table | Holds |
|---|---|
| `{prefix}proto_redirect_hits` | `group`, `rule_id`, `hits`, `last_hit`. |
| `{prefix}proto_redirect_log` | One row per redirect: time, requested URL, target, code, group, rule, who redirected, method, IP, agent, referrer and, when enabled, request headers. |
| `{prefix}proto_404_log` | One row per 404: time, URL, method, IP, agent, referrer, request headers. |

**Why they are in the database.**
Hits and log rows change on every request and cannot be files.
Configuration is never stored there.
Delete the tables and every redirect still works.

- Tables are created with `dbDelta` on activation and on `plugins_loaded` whenever the option `proto_blocks_redirects_db` is not `1`.
- Deactivation keeps the data. `wp proto-blocks redirect db drop` is the only way to drop the tables.
- Writes are prepared statements. The URL is decoded, sanitised and cut at 2000 characters. Agent and referrer are cut at 255. The method is allow-listed and upper-cased.
- Logging is write-only on the request path. Reads happen in the admin.

**Retention.**
A daily cron `proto_blocks_redirects_prune` deletes rows older than `log.redirects` or `log.notFound` days in batches of 10,000.
A full batch reschedules itself in 10 minutes.
`-1` stops writing; `0` keeps everything.

**404 logging** also skips when a `nothing` rule matched, or when the `proto_blocks_redirects_log_404` filter returns false.
The same URL from the same IP within the same minute is one row, updated, not duplicated.

**IP policy.**
The default is `none`: no IP is stored.
`masked` zeroes the last IPv4 octet or the lower 64 bits of an IPv6 address.
`full` stores the address.
The client IP is `REMOTE_ADDR`.
If `REMOTE_ADDR` is in `log.trustedProxies`, the first configured `log.ipHeaders` header that holds a valid IP replaces it (the last non-empty item of a comma list).
The same resolution feeds the `ip` condition.

## Permalink monitor

Set `monitor.postTypes` and enable permalinks.
When a published watched post changes its path, the monitor appends a rule to the group file named in `monitor.group`:

```jsonc
{ "from": "/old-slug/", "to": "/new-slug/", "title": "About us (moved 2026-11-05)" }
```

- It remembers the permalink on `pre_post_update` and compares on `post_updated` priority 11.
- Both statuses must be `publish`, the type must be watched and the old path must not be `/`.
- If a rule in any group already has `from` equal to the new path, that rule is set `enabled: false` in its file to avoid a loop, with a notice.
- `monitor.associated` appends a second rule with the suffix on both sides.
- `monitor.trash` appends a disabled rule to `/` when a watched post is trashed.
- On an unwritable theme the monitor records a `monitor_unwritable` warning, shown on the screen and in `validate`, and does nothing else.
- The monitor writes through the server-side serializer. Comments inside that group file are not preserved. The file header says so.

## HTTP headers and canonical

**Headers.**
`headers.site` is sent on `send_headers` for every non-excluded front-end request.
`headers.redirect` and a rule's `headers` are sent before a `url` redirect.

- Names match `^[A-Za-z0-9-]{1,100}$`.
- Values are 1000 characters at most. CR, LF and control characters are refused.
- `Set-Cookie`, `Location`, `Status`, `Content-Length` and `Transfer-Encoding` are refused as names.
- Every refusal is `invalid_header`.

**Canonical.**
`settings.canonical` runs first on `init`: https upgrade, www or nowww, aliases to the site host, then relocate (except `/wp-admin`, `/wp-login.php` and REST).
It sends one 301 and preserves the path and query.
The `proto_blocks_redirects_canonical_target` filter can change the target.

## Import and export

Parsers are pure PHP: they take a string and return rules plus a diagnostic per row.
Nothing is executed. Uploads are capped at 5 MB.

### CSV

```csv
source,target,regex,code
/old,/new,0,301
/blog/(.*),/news/$1,1,301
```

- Delimiters `,` `;` `|` are detected. Columns are by position: `source, target, regex, code`. A header row is recognised and skipped.
- A 4xx code becomes `action: error`.
- A missing regex column is guessed from the characters `()[]$^*`.
- A row whose source equals its target is skipped. A leading `[FORMULA] ` is removed.
- Export columns: `source,target,regex,code,type,hits,title,status,group`. Cells starting with `=`, `+`, `-` or `@` (ASCII and full-width) get a `[FORMULA] ` prefix.

### JSON

```jsonc
{ "groups": { "legacy-site": { "title": "Legacy site", "rules": [ { "from": "/a", "to": "/b" } ] } }, "settings": {} }
```

Import accepts Proto-Blocks' own shape and Redirection's JSON (`plugin`, `redirects[]`, `groups[]`), mapping `match_type`, `action_type`, `action_data` and the `match_data.source` flags to the keys above.
Export writes the Proto-Blocks shape.

### Apache

```apache
RewriteRule ^old$ /new [R=301,L]
Redirect 301 /moved /new-place
```

- Import reads `RewriteRule`, `Redirect` and `RedirectMatch`, and a `RewriteCond` on `%{HTTP_REFERER}`, `%{HTTP_USER_AGENT}`, `%{HTTP_HOST}` or `%{QUERY_STRING}` before a rule. Flags: `NC`, `QSA`, `R=`, `L`, `F`, `G`, and word codes. `^/?$` sets the trailing-slash flag.
- Export writes an `<IfModule mod_rewrite.c>` block between `# Proto-Blocks redirects` and `# End Proto-Blocks redirects`. Enabled rules only, `R=code,L`, `NC`, `QSA` for pass, `NE` when the target has `#` or `%`. 403 becomes `F`, 410 becomes `G`. Referrer, agent, server and query conditions are exported. Others are commented `# skipped: <reason>`.
- Proto-Blocks never writes `.htaccess`. Paste the block yourself.

### Nginx (export only)

```nginx
rewrite ^/old$ /new permanent;
location = /gone { return 410; }
```

`(?i)` marks `ignoreCase`. Agent and referrer conditions use `if ($http_user_agent ~* ...)`. Other conditions are commented.

### `_redirects` (Netlify)

```text
/old /new 301
/blog/* /news/:splat
```

Import and export: `from to [status]`, `#` comments, `*` and `:splat`. `:name` placeholders are rejected.

### Duplicate modes

| Mode | Behaviour |
|---|---|
| `skip` | An existing rule with the same source, regex flag and condition is kept. Default. |
| `replace` | The existing rule's target, code and flags are overwritten. Its id is kept. |
| `add` | Always append. |

### Migrating from the Redirection plugin

1. Open Proto Blocks > Redirects. While Redirection is active the screen shows "Redirection is active, Proto-Blocks redirects are paused." Rules do not fire yet, so nothing redirects twice.
2. Open Import / Export. Under "Import from plugin", choose Redirection. Only plugins whose data is present on this site are listed.
3. Read the preview. Each row is `new`, `duplicate` or `invalid` with a reason.
4. Choose the target group and the duplicate mode, then import. One group file per Redirection group is written, keeping title, enabled, position, flags and conditions. Hits are not imported.
5. Tick "deactivate source plugin" (needs `activate_plugins`), or deactivate Redirection yourself.
6. Run `wp proto-blocks redirect validate` and test a few URLs with `wp proto-blocks redirect test`.
7. Commit the new files in `proto-redirects/`.

From the shell: `wp proto-blocks redirect import-plugin redirection --dry-run`, then again without `--dry-run`.

Other importers: Simple 301 Redirects (option `301_redirects`), Safe Redirect Manager (`_redirect_rule_*` meta), Rank Math (`rank_math_redirections`), Yoast SEO Premium (`wpseo-premium-redirects-base`) and WordPress old slugs (`_wp_old_slug`).
Each is listed only when its table or option exists and is not empty.

## The Redirects screen

Proto Blocks > Redirects. Tabs live in the URL (`?tab=`).

| Tab | What it does |
|---|---|
| Redirects | A table of every rule across groups: enabled switch, source, target or action, code, condition badge, group, hits, last hit and an origin badge (File, PHP, Declared twice). Search, filters (group, code, condition, enabled), "Add redirect", a group menu (create, rename, enable or disable, reorder, delete). Row actions: edit, move up or down, duplicate, delete. Bulk: enable, disable, delete, move to group, reset hits. The edit dialog mirrors the schema. A Test box shows which rule fires for a URL. |
| 404s | Grouped by URL with count, last seen and last referrer. "Add redirect" opens the dialog with Source prefilled and, on save, deletes the matching 404 rows. "Ignore" adds an `action: nothing` rule. Filters: URL contains, date range. Export CSV. |
| Log | Time, URL, target, code, rule, redirect by, IP, agent, referrer. Filters: URL, rule, code, date. Clear all, delete selected, export CSV. |
| Import / Export | Upload, preview with per-row status, choose group and duplicate mode, then write. "Import from plugin". Export as CSV, JSON, Apache, Nginx or `_redirects`. |
| Settings | A form over `settings.jsonc`. A "Prune orphan hit rows" button, and a "Create tables" button when tables are missing. |

- Every write edits the group file's JSONC text in the browser with comment-preserving edits and posts it with the file hash. Comments survive. A conflict reloads the file.
- Moving a rule between groups deletes it from one file, then inserts it into the other, each with its own hash.
- On a read-only host a banner states the reason and every write control is disabled. Logs and 404s still work, except "Add redirect".
- When Redirection is active and `coexist` is false, a banner on every tab says so and offers an Import button.

## REST routes

All under `proto-blocks/v1`.
Reads need `manage_options`. Writes go through the file-write gate.
Full shapes: [REST API](rest-api.md#redirects).

| Route | Purpose |
|---|---|
| `GET admin/redirects` | groups with rules, settings, issues, DB state |
| `GET admin/redirects/test?url=` | which rule fires, with a trace |
| `GET, DELETE admin/redirects/log` and `admin/redirects/404` | read and clear logs |
| `POST admin/redirects/hits/reset`, `admin/redirects/hits/prune` | reset a rule's hits, prune orphans |
| `POST admin/redirects/import/parse` | parse an upload or text |
| `GET admin/redirects/import/plugins`, `POST admin/redirects/import/plugins/{id}/preview`, `POST admin/redirects/import/deactivate` | plugin importers |
| `GET admin/redirects/export?format=` | export file |
| `POST admin/redirects/db/install` | create tables |
| `admin/schema-files/redirects/{slug}`, `admin/schema-files/redirect-settings/settings` | the files |

## CLI

```bash
wp proto-blocks redirect <subcommand>
```

| Subcommand | Flags |
|---|---|
| `list` | `--group=`, `--format=table\|json\|csv` |
| `add <from> [<to>]` | `--code=`, `--group=`, `--regex`, `--when=<type>`, `--when-value=`, `--action=`, `--query=`, `--title=` |
| `delete <id>` | `--group=` |
| `enable <id>`, `disable <id>` | |
| `group list`, `group create <title>`, `group enable`, `group disable`, `group delete <slug>` | |
| `validate` | also part of `wp proto-blocks validate` |
| `test <url>` | `--logged-in` |
| `import <file>` | `--format=csv\|json\|apache\|redirects`, `--group=`, `--duplicates=skip\|replace\|add`, `--dry-run` |
| `import-plugin <id>` | `--group=`, `--dry-run` |
| `export [<file>\|-]` | `--format=csv\|json\|apache\|nginx\|redirects`, `--group=` |
| `log` | `--404`, `--limit=`, `--clear` |
| `prune` | removes orphan hit rows |
| `db install\|status\|drop` | `--yes` |

CLI writes go through the server-side serializer, so hand-written comments inside the rules array of an edited group file are lost.
Full details: [CLI](cli.md#wp-proto-blocks-redirect).

## Troubleshooting

| Symptom or code | Cause | Fix |
|---|---|---|
| Nothing redirects and a banner says Redirection is active | The Redirection plugin is active and `coexist` is false. | Import, then deactivate Redirection. Or set `"coexist": true`. |
| A rule does not fire | Many causes. | Follow the checklist below. |
| The screen is read-only | `file_mods`, `multisite`, `not_writable` or `outside_root`. | See [read-only reasons](content-model-files.md#read-only-reasons). Edit the files in the theme instead. |
| `unsafe_target` in the log with code 0 | Substitution made a path target unsafe (`//`, backslash, scheme). | Anchor the regex and constrain the capture group. |
| `regex_error` | Backtrack or recursion limit. | Simplify the pattern. |
| `redirect_chain` | One rule's target is another's source. | Point the first rule at the final URL. |
| `double_declaration` | The same group or source is in PHP and a file. | Delete the PHP line. |
| `declared_too_late` | `proto_redirect()` after `init` priority 9. | Call it in `functions.php`. |
| A hit row remains after deleting a rule | The rule was removed from the file, not in the UI. | Prune orphan rows in Settings or run `wp proto-blocks redirect prune`. |
| Redirects work but the log is empty | `log.redirects` is `-1`, the rule has `log: false`, or the tables are missing. | Check the Settings tab and `wp proto-blocks redirect db status`. |

**Rule not firing checklist.**

1. Run `wp proto-blocks redirect test /the/url` (add `--logged-in` for `login` and `role` rules). The trace lists each rule and why it did not match.
2. Is the group enabled, and the rule?
3. Trailing slash and case: check `ignoreTrailingSlash` and `ignoreCase`.
4. Query string: with `exact` an extra parameter stops the match. Try `ignore` or `pass`.
5. Regex: is it anchored, and does it match `path?query` as one string?
6. An earlier rule or group may win. Check `position` and file order.
7. A `404` condition only runs at `template_redirect`, after WordPress resolved the request.
8. Is the path excluded (admin, login, cron, XML-RPC, REST)?
9. Did a self-loop or unsafe-target guard stop it? Look at the issues list.
10. Browsers cache a 301. Test with curl or a private window.

## Related

[Content model files](content-model-files.md), [scripts](scripts.md), [REST API](rest-api.md#redirects), [CLI](cli.md#wp-proto-blocks-redirect), [trust and security](trust-and-security.md#redirect-targets).
