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
- `proto_redirect_group()` derives the group slug from the title (60 characters at most) or takes a `slug` key from the array. The slug follows the file grammar. `php` and `settings` are refused with `invalid_slug` and the group is ignored. Declaring the same slug twice warns `duplicate_php` and the later call is used.
- Both functions are callable until `init` priority 9. A call after that is ignored with a `declared_too_late` warning.
- PHP declarations are trusted code, like every other kind. File declarations are stripped of unsafe keys.

**File wins.**
A file group with the same slug as a PHP group replaces it whole, with the `double_declaration` warning and badge.
A PHP rule whose regex flag, normalised `from` and `when` equal a file rule's is dropped with `double_declaration`, naming the call site and the file rule.
Editing a PHP rule in the UI writes it into the chosen file group, which produces exactly that warning until you delete the PHP line.
The command line and the permalink monitor never edit a PHP rule: they say where it is declared.

**Broken files fail closed.**
A group file that is not valid JSONC, is over the size cap, cannot be read or resolves outside its root is skipped with an error, and a PHP group of the same slug is dropped too (`broken_override`), so a typo never hands the group back to a PHP declaration.
The same holds for a broken child-theme file and the parent theme's group of that slug.

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
- The `proto_blocks_schema_paths` filter adds read-only roots, as for every other kind. The screen and the command line never write a group read from such a root or from the parent theme: they refuse instead of shadowing it.
- A file is capped at 256 KB (`file_too_large`). Split a group that outgrows it.
- An invalid JSONC file (`invalid_jsonc`) is skipped and reported. Other groups still run.
- A group-level schema error (for example a wrong type for `rules`, or an unknown group key) skips the whole group (`schema_invalid`). A rule-level error drops only that rule and keeps the rest of the group: the module fails closed per rule, not per site.

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
| `id` | `^r_[a-f0-9]{8}$` | derived | Stable identity for hit counts and the UI. The UI always writes one. A rule without one gets `r_` plus the first 8 hex characters of `sha1(slug NUL from NUL json(when) NUL action)` at load time, where `when` is the normalised condition (`null` when absent). Two rules in one group with the same id are a `duplicate_rule` error, and the second is dropped. |
| `title` | string, 200 chars at most | `""` | Description. `<` and `>` are refused. |
| `enabled` | bool | `true` | |
| `from` | string, 2000 chars at most | required unless `when.type` is `404` | Source path, or a regex when `regex` is true. A non-regex source must start with `/` (`missing_source` or `invalid_source` otherwise). An absolute URL on the same host is reduced to its path. An absolute URL on another host is `use_when_server`: use `when: {type: "server"}`. The value is kept as written (no percent-decoding, no slash collapsing); the matcher decodes it once when it indexes the rule, so `/caf%C3%A9` and `/café` are the same source. In a non-regex source `\?` (written `\\?` in JSON) is a literal `?`; an unescaped `?` starts the query the rule must match. |
| `regex` | bool | `false` | `from` is a PCRE body without delimiters. See [regex](#regex-and-capture-groups). |
| `to` | string, 2000 chars at most | required for `url` and `pass`; refused for `error` and `nothing` | A path (`/...`, not `//` or `/\`) or an absolute `http(s)://` URL with a host and no user name or password. `$1` to `$9` and `${1}` substitute when `regex` is true. Any other shape, such as another scheme or a bare `$1`, is `invalid_target`. |
| `else` | string | none | Target when `when` is false. Without it the rule falls through. Not allowed without `when`, nor with an `action` other than `url` (`invalid_else`). Same target rules as `to`. |
| `code` | int | `301` for `url`, `404` for `error` | `url`: 301, 302, 303, 307, 308. `error`: 400, 401, 403, 404, 410, 418, 451, 500, 501, 502, 503, 504. Anything else is `invalid_code`. `pass` and `nothing` take no code; writing one is `invalid_code`. |
| `action` | `url`, `error`, `pass`, `nothing` | `url` | See [actions](#actions-and-codes). |
| `when` | object | none | A condition. Absent means always. See [conditions](#conditions). Loading normalises it to the keys `type`, `value`, `name` and `regex`: `value` is null for `login` and `404` and a list for `ip` and `language`, `name` is null unless `header` or `cookie` (header names are title-cased), `regex` is false unless the type takes it, and `server` values are lower-cased. |
| `query` | `exact`, `exactOrder`, `ignore`, `pass` | group default, else `exact` | See [query modes](#query-modes-and-flags). |
| `ignoreCase` | bool | group default, else `true` | |
| `ignoreTrailingSlash` | bool | group default, else `true` | |
| `log` | bool | `true` | `false` keeps this rule out of the redirect log and the hit count. |
| `headers` | object, string to string | none | Extra response headers on this redirect only. See [headers](#http-headers-and-canonical). |

Flag defaults resolve rule, then group `defaults`, then the settings file `defaults`, then `true` / `true` / `exact`.
The writers (screen, CLI, monitor) omit a flag that equals its effective default, so files stay short.

### Validation codes

`wp proto-blocks redirect validate`, `wp proto-blocks validate` and the screen report these. An error drops the rule or file it names; a warning does not.

| Where | Code | Level | Meaning |
|---|---|---|---|
| Rule | `missing_source` | error | No `from` and the condition is not `404`. |
| Rule | `invalid_source` | error | A non-regex `from` that does not start with `/` or holds a control character. |
| Rule | `use_when_server` | error | `from` is a URL on another host. |
| Rule | `invalid_regex` | error | The pattern does not compile, or is over 500 characters. |
| Rule | `unanchored_regex` | warning | A regex `from` with neither `^` nor `$`. |
| Rule | `invalid_target` | error | A bad `to`, or a `to` on an `error` or `nothing` rule. |
| Rule | `invalid_else` | error | A bad `else`, or one without `when`, or on a non-`url` rule. |
| Rule | `invalid_code` | error | A code outside the action's list, or a code on `pass` or `nothing`. |
| Rule | `self_redirect` | error | A non-regex `url` rule whose `to` or `else` is its own `from`. |
| Rule | `invalid_header` | error | A bad name or value in the rule's `headers`. |
| Rule | `invalid_condition` | error | An unknown `when.type`, a missing or malformed `value` or `name`, or a condition regex that does not compile. A condition regex is never warned as unanchored. |
| Group | `schema_invalid` | error | A wrong key, type or range. Outside `rules` it skips the whole group. |
| Group | `duplicate_rule` | error | Two rules with one id in a group. |
| Group | `redirect_chain` | warning | A rule's `to` is another enabled rule's `from`. |
| Settings | `schema_invalid` | error | An unknown key, a wrong type or an out-of-range value. |
| Settings | `invalid_header` | error | A bad name or value in `headers.site` or `headers.redirect`. |
| Settings | `invalid_host` | error | A `canonical.aliases` entry that is not a bare host name. |
| Settings | `invalid_url` | error | `canonical.relocate` is not an absolute http(s) URL without query or fragment. |
| Settings | `invalid_ip` | error | A `log.trustedProxies` entry that is not an IP address or CIDR range. |
| Discovery | `invalid_slug` | error | A folder name that is not a group slug, including `settings`. The folder is skipped. |
| Discovery | `path_outside_root` | error | A file or folder resolves outside its root. |
| Discovery | `file_too_large` | error | The file is over 256 KB. |
| Discovery | `unreadable` | error | The file could not be read. |
| Discovery | `invalid_jsonc` | error | Not JSONC, or the root is not an object. |
| Discovery | `broken_override` | error | A broken file stopped a same-slug group (parent theme or PHP) from loading. |
| Discovery | `both_extensions` | warning | `.jsonc` and `.json` both exist. |
| PHP | `declared_too_late` | warning | A call after `init` priority 9. |
| PHP | `duplicate_php` | warning | The same PHP group slug twice. |
| PHP | `invalid_slug` | error | A PHP group slug that is invalid, `settings` or `php`. |
| Precedence | `double_declaration` | warning | The same group or rule in PHP and in a file. |
| Monitor | `monitor_unwritable`, `monitor_rule_disabled` | warning | See [permalink monitor](#permalink-monitor). |

At run time the engine also records `unsafe_target`, `self_loop` and `regex_error` (see [safety rules](#safety-rules)). They are not file findings.

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
| `error` | Sends the status line, `nocache_headers()` and `X-Redirect-By`, sets the query to 404 and renders the theme's 404 template. WordPress resets the query when it runs its own, so the status and the 404 state are applied again on `wp` (priority 1000), and 410 and 451 are sent once more at `send_headers` so caches see them. |
| `pass` | An internal rewrite. `to` must be a path. `REQUEST_URI`, `PATH_INFO`, `QUERY_STRING` and `$_GET` are replaced and WordPress parses the new request. The canonical redirect is suppressed. An absolute `to` is `invalid_target`. Proto-Blocks never proxies an external URL. |
| `nothing` | Stops processing. Suppresses 404 logging and the canonical redirect for this request. Fires the `proto_blocks_redirects_nothing` action with the rule and the request. It is never written to the redirect log (it still counts a hit unless `log` is false). |

In the redirect log a `url` rule is logged with its code, an `error` rule with its error code, and a `pass` rule with code 200.
A redirect sent by the canonical settings is logged without a group or rule.

All HTTP methods are processed.
Use 307 or 308 when a POST body must survive.

**Where it runs.**
Front end only: not `is_admin()`, not WP-CLI, not cron, not XML-RPC.
The main pass runs on `init` priority 10 (filter `proto_blocks_redirects_priority`).
`404` rules and 404 logging run on `template_redirect` priority 10.
`redirect_canonical` returns false once a rule has matched or passed.
The rules are read from the registry on every request (nothing is cached between requests), and the matcher is built on the first match so a PHP declaration made before `init` priority 9 is included.

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
Rules compile once per request into an exact map keyed by the lowercased (multibyte-aware), slash-trimmed source and an ordered list of regex rules.
Each candidate then checks its own `ignoreCase` and `ignoreTrailingSlash` against the un-normalised path, and candidates from both lists are merged back into the global order before evaluation.
The first rule whose action fires wins.
A rule is a regex rule when `regex` is true. A duplicate query key such as `a=1&a=2` parses PHP-style (last wins) except in `exactOrder`, which compares the raw string.
With `query: pass`, parameters named in the source query are consumed and not appended again, and on a key clash the target's own value wins.

## Regex and capture groups

```jsonc
{ "from": "^/blog/(\\d+)/(.*)$", "regex": true, "to": "/news/$2" }
```

- Write the body only, no delimiters. A literal `?` is `\\?` inside JSON.
- Validation compiles the pattern. A compile error is `invalid_regex` with PHP's message. A pattern over 500 characters is refused.
- A pattern with neither `^` nor `$` is allowed but warns `unanchored_regex`. Unanchored matching is kept for Redirection compatibility.
- Matching uses `preg_match` and checks `preg_last_error()`. A backtrack or recursion limit error makes the rule not fire (the test trace says `regex_error`) and evaluation continues with the next rule.
- The `s` modifier is always set, `i` when `ignoreCase` is true, and `u` when the pattern is valid UTF-8 and compiles with it.
- `$1` to `$9` and `${1}` substitute into `to`.
- A rule that matches everything (`.*`) placed first wins. The safety rules below still hold.

## Safety rules

### Open redirect

1. A target written as a path must still be a path after substitution. A leading `//` collapses to `/`. A backslash, a control character or a `scheme:` prefix in the result makes the rule not fire, and evaluation goes on with the next rule. One `unsafe_target` row per request goes to the redirect log with code 0 and no target.
2. A target written absolute keeps its host. If substitution changes the host, the rule does not fire.
3. The final target goes through `wp_sanitize_redirect()`.
4. Only `http` and `https` schemes are accepted.

### Loops and chains

- A rule whose computed target normalises to the current request (same host, same path under the rule's case and slash flags, same query: raw for `exactOrder`, parsed and order-free otherwise) does not fire, and the test trace says `self_loop`. A `.*` rule therefore fires once and stops at its own target instead of being refused.
- Validation refuses a non-regex rule whose `to` equals `from` (`self_redirect`).
- Validation warns `redirect_chain` when a non-regex rule's `to` equals another enabled rule's `from` in any group, naming both. Chains work. Collapse them.

### Regex limits

500 characters, compiled at write time, `preg_last_error()` checked on every match. See [regex](#regex-and-capture-groups).

### Excluded paths

Login, admin, cron, XML-RPC and REST are never touched. See [actions and codes](#actions-and-codes).

## Groups and order

- Groups evaluate in ascending `position`, then slug. Inside a group, rules run in file order.
- There is one global order across exact and regex rules: the first rule in that order whose action fires wins, so a `.*` regex placed first beats every later exact rule. The exact map only saves scanning unrelated rules.
- A group with `enabled: false` is skipped whole, even for rules with `enabled: true`. A disabled rule in an enabled group is skipped on its own.
- A rule that fails validation is dropped on its own; the rest of its group still runs. A broken file drops the group (and a PHP group of the same slug).
- The Test box and `redirect test` evaluate every rule of every enabled group, show disabled rules in the trace as `disabled`, and never redirect.
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
| `log.headers` | Store request headers in the `request` column, minus `authorization`, `cookie`, `set-cookie`, `host`, `proxy-authorization`, `x-wp-nonce`, `x-api-key`, `x-auth-token`, `x-csrf-token` and `x-xsrf-token`. At most 50 headers, each value cut at 1000 characters. |
| `log.external` | Reserved. It is validated and shown on the Settings tab, but nothing reads it yet: redirects issued by other plugins or core are not logged. |
| `log.trustedProxies` | `REMOTE_ADDR` values, single addresses or CIDR ranges (100 at most), allowed to supply a client-IP header. |
| `log.ipHeaders` | Ordered: `CF-Connecting-IP`, `X-Forwarded-For`, `X-Real-IP`, `Client-IP`, `True-Client-IP`. |
| `trackHits` | Count hits per rule. |
| `cache` | `Cache-Control` max-age hours on 301 and 308: `-1` no-cache headers, `0` none, `1`, `24`, `168`. |
| `monitor.postTypes` | Post types to watch (`^[a-z0-9_-]{1,20}$` each). `[]` is off. |
| `monitor.group` | Group file the monitor writes to, default `modified-posts`. Created when first needed. |
| `monitor.trash` | Also add a disabled rule when a watched post is trashed. |
| `monitor.associated` | A suffix that starts with `/`, such as `/feed/` (100 characters at most), that adds a second rule with the suffix on both sides. |
| `headers.site` | Sent on every front-end response. |
| `headers.redirect` | Sent only with redirects. |
| `canonical.https` | 301 http to https. |
| `canonical.www` | `""`, `www` or `nowww`. |
| `canonical.aliases` | Other hostnames 301'd to the site URL. Host only (punycode for international names), lower-cased, 20 at most. |
| `canonical.relocate` | `""` or an absolute http(s) URL without query, fragment or user name. Sends everything except wp-admin, wp-login.php and REST there with a 301. |
| `coexist` | Run even while the Redirection plugin is active. |

**Clamping.**
`validate` reports an unknown key, a wrong type or an out-of-range value as `schema_invalid`.
Whatever the file says, the module reads it defensively: a wrong type falls back to the default; `log.redirects` and `log.notFound` are clamped to -1..90; a `cache` outside -1, 0, 1, 24, 168 becomes 1; aliases are lower-cased, invalid hosts dropped, duplicates removed and the first 20 kept; an invalid `relocate` becomes `""`; `trustedProxies` entries that are not an IP or CIDR are dropped; `ipHeaders` keeps only the five known names; an invalid header in `headers.site` or `headers.redirect` is not sent.

The settings file is a `flat` kind: the folder `proto-redirects/` holds the file itself (`settings.jsonc` or `settings.json`), not a folder named after a slug.
Its REST path is `admin/schema-files/redirect-settings/settings`, and group files use `admin/schema-files/redirects/<slug>`.

## Hit counts

When `trackHits` is true and the rule's `log` is true, a matched rule increments `hits` and sets `last_hit` for its group and id.
That is one `INSERT ... ON DUPLICATE KEY UPDATE`, one row per request at most.

- `wp proto-blocks redirect delete` deletes the rule's row. Deleting in the screen, or removing a rule in the file, leaves an orphan row. The Prune button and `wp proto-blocks redirect prune` remove orphans. A rule's hits can be reset from the screen (`hits/reset`) or deleted over REST (`DELETE admin/redirects/hits`).
- A rule keeps its hits only while its group slug and id stay the same, so give a rule an explicit `id` before renaming its group or editing its source.
- Hits are not configuration, so they are never imported as rules.

## Redirect log and 404 log

Three tables, per site on multisite (`$wpdb->prefix`):

| Table | Holds |
|---|---|
| `{prefix}proto_redirect_hits` | `group_slug`, `rule_id`, `hits`, `last_hit`. Primary key on the first two. |
| `{prefix}proto_redirect_log` | One row per redirect: time, requested URL, target, code, `group_slug`, `rule_id`, who redirected, method, IP, agent, referrer and, when enabled, the request headers. |
| `{prefix}proto_404_log` | One row per 404: time, URL, method, IP, agent, referrer, request headers. |

The group column is `group_slug` because `GROUP` is a reserved word in MySQL; the REST API still calls it `group`.
The `request` column is `LONGTEXT` holding JSON, since JSON columns are not available on every supported MariaDB and MySQL.
Dates are UTC.

Besides the tables the module keeps two options: `proto_blocks_redirects_db` (the schema version, `1`) and `proto_blocks_redirects_monitor_issues` (the newest 20 permalink monitor warnings, so the screen can show them on a later request).

**Why they are in the database.**
Hits and log rows change on every request and cannot be files.
Configuration is never stored there.
Delete the tables and every redirect still works.

- Tables are created with `dbDelta` on plugin activation and on `plugins_loaded` whenever the option `proto_blocks_redirects_db` is not `1`. A host that refuses `CREATE` keeps the module working without logs; the Settings tab offers "Create tables" and the CLI `db install`.
- Deactivation unschedules the retention cron and keeps the data. `wp proto-blocks redirect db drop` is the only way to drop the tables.
- Writes are prepared statements. The URL is decoded, sanitised and cut at 2000 characters. Agent and referrer are cut at 255. The method is allow-listed (GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS, TRACE, CONNECT) and upper-cased; anything else is stored as `OTHER`.
- Logging is write-only on the request path. Reads happen in the admin.
- Admin lists page with `page` and `per_page` (5 to 200, default 50).

**Retention.**
A daily cron `proto_blocks_redirects_prune` deletes rows older than `log.redirects` or `log.notFound` days (UTC cutoff, at most 90) in batches of 10,000.
A full batch schedules one follow-up event on the same hook with the argument `continue`, 10 minutes later, so the daily event is not mistaken for it.
`-1` stops writing; `0` keeps everything.
`wp proto-blocks redirect prune` runs the same retention once, after pruning orphan hit rows.

**404 logging** also skips when any rule matched at `init` (an `error` rule's 404 is intentional, not a missing page), when a `nothing` rule matched, or when the `proto_blocks_redirects_log_404` filter returns false (applied once, with the engine request).
The same URL from the same IP within the same minute is one row, updated, not duplicated. Under `log.ip: none` that means one row per URL per minute.

**IP policy.**
The default is `none`: no IP is stored.
`masked` zeroes the last IPv4 octet or the lower 64 bits of an IPv6 address.
`full` stores the address.
The client IP is `REMOTE_ADDR`.
If `REMOTE_ADDR` is in `log.trustedProxies` (a single address or a CIDR range), the first configured `log.ipHeaders` header that holds a valid IP replaces it (the last non-empty item of a comma list; a trailing port such as `1.2.3.4:8080` or `[2001:db8::1]:443` is dropped). Only the five allow-listed header names are honoured, whatever the settings say.
The same resolution feeds the `ip` condition.

## Permalink monitor

Set `monitor.postTypes` and enable permalinks.
When a published watched post changes its path, the monitor appends a rule to the group file named in `monitor.group`:

```jsonc
{ "from": "/old-slug/", "to": "/new-slug/", "title": "About us (moved 2026-11-05)" }
```

- It remembers the permalink on `pre_post_update` and compares on `post_updated` priority 11.
- Both statuses must be `publish`, the type must be watched and the old path must not be `/`.
- Paths are percent-decoded (the engine matches decoded paths), taken relative to the site's home path, and a permalink on another host is ignored.
- If an enabled non-regex rule in any file group already has `from` equal to the new path, that rule is set `enabled: false` in its own file to avoid a loop, with a `monitor_rule_disabled` notice. Regex rules are never touched. A PHP rule cannot be written, so the notice says where it is declared. The trash rule does not trigger this (it is disabled and targets `/`).
- A rule already in the monitor group with the same source is updated in place, so moving a post twice does not pile up rules.
- `monitor.associated` appends a second rule with the suffix on both sides.
- `monitor.trash` appends a disabled rule to `/` when a watched post is trashed.
- The monitor writes every touched file or none. If any file involved is not writable, including a group read from the parent theme or a `proto_blocks_schema_paths` root (a read-only root counts as unwritable: a new copy would shadow it), it records a `monitor_unwritable` warning and writes nothing. Warnings are kept in the option `proto_blocks_redirects_monitor_issues` and shown on the screen and in `validate`.
- Post saves by editors still write on a single site, because the monitor uses the writer directly: it is not gated on the REST write gate.
- On multisite the monitor is off: a sub-site editor must not write into a theme the whole network shares. With `monitor.postTypes` set, a `monitor_unwritable` warning (reason `multisite`) shows on the screen and in `validate`.
- The monitor keeps running while the module is paused for the Redirection plugin: it writes files and never redirects.
- The monitor writes through the server-side serializer. Comments inside that group file are not preserved.

## HTTP headers and canonical

**Headers.**
`headers.site` is sent on `send_headers` for every non-excluded front-end request.
`headers.redirect` and a rule's `headers` are sent before a `url` redirect.

- Names match `^[A-Za-z0-9-]{1,100}$`.
- Values are strings of 1000 characters at most. CR, LF and control characters are refused.
- `Set-Cookie`, `Location`, `Status`, `Content-Length` and `Transfer-Encoding` are refused as names.
- Every refusal is `invalid_header`. A rule's header with the same name as a `headers.redirect` header replaces it.

**Canonical.**
`settings.canonical` runs first on `init`, only for requests the engine runs for (so a REST call or a `wp-login.php` POST is never redirected).
When `relocate` is set it is the only rule: every request goes to that URL with its path and query, except wp-admin, wp-login.php and REST.
Otherwise one 301 combines the aliases-to-site-host change, the www or nowww change (for the site's own host) and the https upgrade, preserving the path and query. The target port is kept on development hosts such as `localhost:10003`.
The `proto_blocks_redirects_canonical_target` filter can change the target. A canonical redirect is logged without a group or rule.

## Import and export

Parsers are pure PHP: they take a string and return rules plus a diagnostic per row (`new`, `duplicate` or `invalid`, with a reason code and a line number; JSON rows are numbered by record).
Nothing is executed. Uploads and CLI files are capped at 5 MB (`proto_blocks_import_too_large`, 413 over REST). An input that cannot be parsed at all (too large, not JSON, unknown shape) is one invalid row on line 0.
Every `new` row is checked against the same validator as a file write, so a row that would not load is shown as `invalid` with the validator's code.

### CSV

```csv
source,target,regex,code
/old,/new,0,301
/blog/(.*),/news/$1,1,301
```

- Delimiters `,` `;` `|` are detected. Columns are by position: `source, target, regex, code`. A header row is recognised by its column names and skipped; named columns are then read by name, so our own export round-trips its title, status and type.
- A 4xx or 5xx code becomes `action: error`. An unknown code is an `invalid_code` row, not a silent 301.
- A missing regex column is guessed from the characters `()[]$^*`.
- A row whose source equals its target is skipped as `self_redirect`. A leading `[FORMULA] ` is removed.
- Export columns: `source,target,regex,code,type,hits,title,status,group`. Cells starting with `=`, `+`, `-` or `@` (ASCII and full-width) get a `[FORMULA] ` prefix; the REST export also prefixes a leading tab or CR.
- The CSV export leaves out rules with a `when` condition (re-importing them would make them unconditional) and `404` rules without a source. Use JSON for a lossless copy.

### JSON

```jsonc
{ "groups": { "legacy-site": { "title": "Legacy site", "rules": [ { "from": "/a", "to": "/b" } ] } }, "settings": {} }
```

Import accepts Proto-Blocks' own shape, a single group file (`{title, rules}`, imported as group `imported`) and Redirection's JSON (`plugin`, `redirects[]`, `groups[]`), mapping `match_type`, `action_type`, `action_data` and the `match_data.source` flags to the keys above. Redirection rules with an unknown `group_id` land in `imported`.
Redirection's `random` action, login rules that only test `logged_out`, and condition rules with only `url_notfrom` have no equivalent here and come in as invalid rows.
Serialized PHP is never unserialized from an uploaded file.
Export writes the Proto-Blocks shape: groups in evaluation order, without registry keys. An export of every group also carries the settings (a backup); a one-group export does not.

### Apache

```apache
RewriteRule ^old$ /new [R=301,L]
Redirect 301 /moved /new-place
```

- Import reads `RewriteRule`, `Redirect` and `RedirectMatch`, and a `RewriteCond` on `%{HTTP_REFERER}`, `%{HTTP_USER_AGENT}`, `%{HTTP_HOST}` or `%{QUERY_STRING}` before a rule. A `RewriteCond` on anything else (such as `REQUEST_FILENAME`) makes the next rule invalid. Everything between `# BEGIN WordPress` and `# END WordPress` is skipped.
- Apache is case-sensitive, so a rule without `NC` imports with `ignoreCase: false`. `RewriteRule` never sees the query: without `QSA` it imports as `query: ignore`, with `QSA` as `query: pass`. `[F]` becomes 403 and `[G]` becomes 410. `seeother` is 303. A path target without `R` is an internal rewrite (`pass`); an absolute target without `R` is a 302. `Redirect` imports as an exact match with the trailing-slash flag on (Apache's prefix matching is not reproduced). Flags: `NC`, `QSA`, `R=`, `L`, `F`, `G` and word codes.
- Export writes an `<IfModule mod_rewrite.c>` block between `# Proto-Blocks redirects` and `# End Proto-Blocks redirects`. Enabled rules only, `R=code,L`, `NC`, `QSA` for pass, `NE` when the target has `#` or `%`. 403 becomes `F`, 410 becomes `G`. Referrer, agent, server and query conditions are exported. Others are commented `# skipped: <reason>`.
- Proto-Blocks never writes `.htaccess`. Paste the block yourself.

### Nginx (export only)

```nginx
rewrite ^/old$ /new permanent;
location = /gone { return 410; }
```

`(?i)` marks `ignoreCase`. Agent and referrer conditions use `if ($http_user_agent ~* ...)`. Other conditions are commented. There is no `server {}` wrapper: include the output inside one. A target that is not a `pass` ends with `?` so nginx drops the request's arguments, matching `exact` and `ignore`.

### `_redirects` (Netlify)

```text
/old /new 301
/blog/* /news/:splat
```

Import and export: `from to [status]`, `#` comments, `*` and `:splat`. `:name` placeholders are rejected. On import, status 200 becomes `pass`, a 4xx or 5xx becomes `error` (the target is dropped), and condition tokens (`Country=`, `Role=`, query tokens) make the line invalid; the default is 301.
The export keeps only portable rules: literal sources and `^/prefix/(.*)$` regexes. Anything else is skipped with a comment. Netlify's file must be named `_redirects`, and the download is.
All exporters refuse (with a `# skipped: ...` comment) any rule whose values hold a control character, so nothing can inject a directive. Patterns escape only PCRE metacharacters, so exported files stay readable.

### Duplicate modes

| Mode | Behaviour |
|---|---|
| `skip` | An existing rule with the same key is kept. Default. |
| `replace` | The existing rule's target, code and flags are overwritten. Its id is kept. |
| `add` | Always append. |

The duplicate key is the normalised `from`, the regex flag and the normalised `when`, checked against the target group and against earlier rows of the same import. A rule added without an id gets one derived like a file rule's, with a counter on collision. Every importer refuses a source or target holding a control character.

### Migrating from the Redirection plugin

1. Open Proto Blocks > Redirects. While Redirection is active the screen shows "Redirection is active, Proto-Blocks redirects are paused." Rules do not fire yet, so nothing redirects twice.
2. Open the Import / Export tab. The "Import from a plugin" panel lists only plugins whose data is present on this site. Press Preview next to Redirection.
3. Read the preview. It shows every group the plugin has and its rules, and marks a group that "merges into existing group". Rows the importer could not convert are not listed on the screen; the REST preview returns them as `skipped` and the CLI warns about each. The plugin preview does not mark duplicates: the duplicate mode is applied when the file is written.
4. Choose the duplicate mode (skip, replace or add), then press "Import N rules". There is no group picker for a plugin import: one group file per Redirection group is written (or merged into the group of the same slug), keeping title, enabled, position, flags and conditions. Hits are not imported.
5. Tick "Deactivate Redirection after importing" to deactivate it once the files are written. The checkbox is shown when the importer knows the plugin file, and the server needs `activate_plugins` (and does not deactivate a network-active plugin from a site). Or deactivate Redirection yourself.
6. Run `wp proto-blocks redirect validate` and test a few URLs with `wp proto-blocks redirect test`.
7. Commit the new files in `proto-redirects/`.

The import writes group files from the browser, so it needs a writable theme. From the shell: `wp proto-blocks redirect import-plugin redirection --dry-run`, then again without `--dry-run`; add `--group=<slug>` to put every rule in one group.
File imports (the "Import a file" panel) work the same way, with a Preview that marks each row New, Duplicate or Invalid, an "Import into group" choice (or "Keep the file's groups" for a JSON export with several groups) and the duplicate mode.

Other importers: Simple 301 Redirects (option `301_redirects`), Safe Redirect Manager (`_redirect_rule_*` meta), Rank Math (`rank_math_redirections`), Yoast SEO Premium (`wpseo-premium-redirects-base`) and WordPress old slugs (`_wp_old_slug`).
Each is listed only when its table or option exists and is not empty. Serialized values in the database are read with `allowed_classes` off. WordPress old slugs has no plugin to deactivate.

## The Redirects screen

Proto Blocks > Redirects. Tabs live in the URL (`?tab=`).

| Tab | What it does |
|---|---|
| Redirects | A table of every rule across groups: enabled switch, source, target or action, code, condition badge, group, hits, last hit and an origin badge (File, PHP, Declared twice). Search, filters (group, code, condition, enabled), "Add redirect", a group menu (create, rename, enable or disable, reorder, delete). Row actions: edit, move up or down, duplicate, delete. Bulk: enable, disable, delete, move to group, reset hits. The edit dialog mirrors the schema. A Test box shows which rule fires for a URL. |
| 404s | Grouped by URL with count, last seen and last referrer. "Add redirect" opens the dialog with Source prefilled and, on save, deletes the matching 404 rows. "Ignore" adds an `action: nothing` rule. Filters: URL contains, date range. Export CSV. |
| Log | Time, URL, target, code, rule, redirect by, IP, agent, referrer. Filters: URL, rule, code, date. Clear all, delete selected, export CSV. |
| Import / Export | Upload, preview with per-row status, choose group and duplicate mode, then write. "Import from plugin". Export as CSV, JSON, Apache, Nginx or `_redirects`. |
| Settings | A form over `settings.jsonc`. A "Prune orphan hit rows" button, and a "Create tables" button when tables are missing. |

- Every write edits the group file's JSONC text in the browser and posts it with the file hash to `admin/schema-files/redirects/<slug>`. A one-line rule is replaced as text, so comments between rules and at the end of a line survive; a rule written over several lines, or with comments inside, is edited key by key. A rule without an `id` gets its derived id written on the first edit. Before writing, the screen compares the file's hash with the one in the last list; a difference opens the conflict dialog and reloads.
- Moving a rule between groups deletes it from one file, then inserts it into the other, each with its own hash. A group read from the parent theme, a filter root or PHP shows no write controls.
- On a read-only host a banner states the reason and every write control is disabled. Logs and 404s still work, except "Add redirect".
- "Add redirect" and "Ignore" use the first writable file group by position. With none, the dialog offers a new group "Redirects" (`redirects`), created on save.
- When Redirection is active and `coexist` is false, a banner on every tab says so and offers an Import button.
- The regex check in the edit dialog translates PCRE-only syntax (`(?P<`, possessive quantifiers, `\A`, `\z`, inline `(?i)`) before trying it in the browser. The server's compile is the authority.

## REST routes

All under `proto-blocks/v1`.
Every `admin/redirects/*` route needs `manage_options`, and `import/deactivate` also needs `activate_plugins`.
None of them writes a theme file, so a read-only host keeps its logs, hit counts and imports' previews usable. Rule and settings files are written through `admin/schema-files/*`, which has the file-write gate (file modifications on, a network administrator on multisite).
Full shapes: [REST API](rest-api.md#redirects).

| Route | Purpose |
|---|---|
| `GET admin/redirects` | groups with rules, hits, per-rule issues, settings, loose issues, DB state |
| `GET admin/redirects/test?url=&loggedIn=` | which rule fires, with a trace (`stage`, `reason`, `loggedIn`, `note`) |
| `GET, DELETE admin/redirects/log` and `admin/redirects/404` | read and clear logs (`ids[]`, `all=1`; 404 also `?url=`) |
| `GET admin/redirects/log/export`, `admin/redirects/404/export` | CSV download |
| `DELETE admin/redirects/hits`, `POST admin/redirects/hits/reset`, `admin/redirects/hits/prune` | delete or reset a rule's hits, prune orphans |
| `POST admin/redirects/import/parse` | parse an upload or text |
| `GET admin/redirects/import/plugins`, `POST admin/redirects/import/plugins/{id}/preview`, `POST admin/redirects/import/deactivate` | plugin importers |
| `GET admin/redirects/export?format=&group=` | export file |
| `POST admin/redirects/db/install` | create tables |
| `admin/schema-files/redirects/{slug}`, `admin/schema-files/redirect-settings/settings` | the files |

## CLI

```bash
wp proto-blocks redirect <subcommand>
```

| Subcommand | Flags |
|---|---|
| `list` | `--group=`, `--format=table\|json\|csv` |
| `add <from> [<to>]` | `--code=`, `--group=` (default `redirects`), `--regex`, `--when=<type>`, `--when-value=` (comma-separated for `ip` and `language`), `--when-name=` (header and cookie), `--action=`, `--query=`, `--title=` |
| `delete <id>` | `--group=` |
| `enable <id>`, `disable <id>` | |
| `group list`, `group create <title>` (`--slug=`, `--position=`), `group enable <slug>`, `group disable <slug>`, `group delete <slug>` | `--yes` to delete, `--format=` for list |
| `validate` | `--format=table\|json\|csv`. Also part of `wp proto-blocks validate`. |
| `test <url>` | `--logged-in` (the `--user`, else the first administrator), `--format=table\|json` |
| `import <file>` | `--format=csv\|json\|apache\|redirects` (detected when left out), `--group=` (default `imported`; without it a JSON export keeps its groups), `--duplicates=skip\|replace\|add`, `--dry-run` |
| `import-plugin <id>` | `--group=` (default: the plugin's own groups), `--duplicates=`, `--dry-run` |
| `export [<file>\|-]` | `--format=csv\|json\|apache\|nginx\|redirects`, `--group=`, `--force` to overwrite a file |
| `log` | `--404`, `--limit=` (1 to 200, default 20), `--clear`, `--format=` |
| `prune` | removes orphan hit rows and runs log retention once |
| `db install\|status\|drop` | `--yes` to drop |

CLI writes read the group file's raw data and write it back through the server-side serializer, so hand-written comments inside the rules array of an edited group file are lost.
The CLI refuses a file that is not valid JSONC (it would rewrite it), a group declared only in PHP, and a group read from the parent theme or another root that is not writable.
Full details: [CLI](cli.md#wp-proto-blocks-redirect).

## Boot and lifecycle

- The module is wired once the theme is set up (`after_setup_theme`), so a filtered `proto_blocks_redirects_priority` below 10 still works. A PHP declaration made after that and before the seal at `init` priority 9 is still included: the registry is rebuilt when the PHP declarations changed.
- Plugin activation creates the tables. Boot schedules the daily retention cron when it is not scheduled, and checks the tables on `plugins_loaded`, so a plugin update or a copied database repairs itself.
- Deactivation unschedules the retention cron. Tables and data stay.
- The registry is read on every request and is never cached across requests, so a file edit takes effect immediately.
- While the Redirection plugin is active and `coexist` is false, the front end is paused: no redirects, no logging and no 404 log. The tables, the retention cron, the client-IP resolution and the permalink monitor stay wired.
- The module can be switched off with the `redirects` key of the `proto_blocks_modules` filter. The screen then says so.

## Troubleshooting

| Symptom or code | Cause | Fix |
|---|---|---|
| Nothing redirects and a banner says Redirection is active | The Redirection plugin is active and `coexist` is false. | Import, then deactivate Redirection. Or set `"coexist": true`. |
| A rule does not fire | Many causes. | Follow the checklist below. |
| The screen is read-only | `file_mods`, `multisite`, `not_writable` or `outside_root`. | See [read-only reasons](content-model-files.md#read-only-reasons). Edit the files in the theme instead. |
| `unsafe_target` in the log with code 0 | Substitution made a path target unsafe (`//`, backslash, scheme). | Anchor the regex and constrain the capture group. |
| `regex_error` in the test trace | Backtrack or recursion limit. | Simplify the pattern. |
| `redirect_chain` | One rule's target is another's source. | Point the first rule at the final URL. |
| `double_declaration` | The same group or source is in PHP and a file. | Delete the PHP line. |
| `declared_too_late` | `proto_redirect()` after `init` priority 9. | Call it in `functions.php`. |
| A hit row remains after deleting a rule | The rule was removed from the file, not in the UI. | Prune orphan rows in Settings or run `wp proto-blocks redirect prune`. |
| Redirects work but the log is empty | `log.redirects` is `-1`, the rule has `log: false`, the rule is a `nothing` rule, or the tables are missing. | Check the Settings tab and `wp proto-blocks redirect db status`. |
| `monitor_unwritable` | The permalink monitor could not write a group file. | See [read-only reasons](content-model-files.md#read-only-reasons), or move `monitor.group` to a writable group. |
| `broken_override` | A broken file stopped a same-slug group from loading. | Fix the JSONC error the same screen reports. |
| `log.external` does nothing | The setting is reserved. | Redirects from other plugins are not logged. |

**Rule not firing checklist.**

1. Run `wp proto-blocks redirect test /the/url` (add `--logged-in` for `login` and `role` rules). The trace lists each rule and why it did not match.
2. Is the group enabled, and the rule?
3. Trailing slash and case: check `ignoreTrailingSlash` and `ignoreCase`.
4. Query string: with `exact` an extra parameter stops the match. Try `ignore` or `pass`.
5. Regex: is it anchored, and does it match `path?query` as one string?
6. An earlier rule or group may win. Check `position` and file order.
7. A `404` condition only runs at `template_redirect`, after WordPress resolved the request.
8. Is the path excluded (admin, login, cron, XML-RPC, REST)?
9. Did a self-loop or unsafe-target guard stop it? The test trace names the reason (`self_loop`, `unsafe_target`, `regex_error`); an unsafe target also leaves a code 0 row in the redirect log.
10. Browsers cache a 301. Test with curl or a private window.

## Related

[Content model files](content-model-files.md), [scripts](scripts.md), [REST API](rest-api.md#redirects), [CLI](cli.md#wp-proto-blocks-redirect), [trust and security](trust-and-security.md#redirect-targets).
