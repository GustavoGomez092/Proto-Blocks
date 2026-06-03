# GitHub Self-Updater Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let any site running Proto-Blocks receive stable `vX.Y.Z` GitHub releases through WordPress's native plugin-update flow.

**Architecture:** One self-contained class `ProtoBlocks\Updater\GitHubUpdater` (`includes/Updater/GitHubUpdater.php`). Pure, WordPress-free static methods do the release selection and changelog formatting (unit-tested); thin instance methods hook WP's update transient, `plugins_api`, and `upgrader_source_selection`. The GitHub Releases list is fetched over HTTP and cached in a 12h transient. The updater is wired in `Plugin::initializeServices()` (admin only) and **disables itself on git checkouts** so it can never wipe a development working copy.

**Tech Stack:** PHP 8.0, WordPress 6.3+ plugin-update hooks, GitHub REST API (unauthenticated, public repo `GustavoGomez092/Proto-Blocks`), PHPUnit (`composer test`, stubs in `tests/php/bootstrap.php`).

**Conventions:**
- Conventional commit messages (`feat:` / `test:` / `docs:`). Do **not** manually bump the version — the release CI computes it from commit types.
- Run PHP tests with `composer test` (PHPUnit, `failOnWarning="true"`).

---

## File Structure

- **Create** `includes/Updater/GitHubUpdater.php` — the entire updater (built up across Tasks 1–5). Pure static core + instance hooks.
- **Modify** `includes/Core/Plugin.php` — instantiate + `register()` the updater in `initializeServices()` (admin only) (Task 5).
- **Create** `tests/php/Updater/SelectLatestStableTest.php` — unit tests for release selection (Task 1).
- **Create** `tests/php/Updater/ChangelogHtmlTest.php` — unit tests for changelog formatting (Task 2).

No change needed to `scripts/build-zip.js` (its `includes/**/*` pattern already ships the new folder — verified in Task 6).

---

## Task 1: Pure release selection (`select_latest_stable` + `find_zip_asset`)

**Files:**
- Create: `tests/php/Updater/SelectLatestStableTest.php`
- Create: `includes/Updater/GitHubUpdater.php`

- [ ] **Step 1: Write the failing test**

Create `tests/php/Updater/SelectLatestStableTest.php`:

```php
<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Updater;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Updater\GitHubUpdater;

final class SelectLatestStableTest extends TestCase
{
    /** Build a minimal release array, overridable per field. */
    private function rel(string $tag, array $extra = []): array
    {
        $ver = ltrim($tag, 'v');
        return array_merge([
            'tag_name'     => $tag,
            'draft'        => false,
            'prerelease'   => false,
            'html_url'     => 'https://github.com/x/y/releases/tag/' . $tag,
            'body'         => 'notes',
            'published_at' => '2026-01-01T00:00:00Z',
            'assets'       => [[
                'name'                 => 'proto-blocks-' . $ver . '.zip',
                'browser_download_url' => 'https://github.com/x/y/releases/download/' . $tag . '/proto-blocks-' . $ver . '.zip',
            ]],
        ], $extra);
    }

    public function test_picks_highest_semver_not_newest_date(): void
    {
        $releases = [$this->rel('v2.3.1'), $this->rel('v2.4.0'), $this->rel('v2.2.0')];
        $r = GitHubUpdater::select_latest_stable($releases);
        $this->assertSame('2.4.0', $r['version']);
        $this->assertStringContainsString('proto-blocks-2.4.0.zip', $r['package']);
    }

    public function test_excludes_non_semver_latest_tag(): void
    {
        $releases = [
            $this->rel('latest', ['assets' => [[
                'name'                 => 'proto-blocks-9.9.9.zip',
                'browser_download_url' => 'https://github.com/x/y/releases/download/latest/proto-blocks-9.9.9.zip',
            ]]]),
            $this->rel('v2.4.0'),
        ];
        $this->assertSame('2.4.0', GitHubUpdater::select_latest_stable($releases)['version']);
    }

    public function test_excludes_prerelease_and_draft(): void
    {
        $releases = [
            $this->rel('v3.0.0', ['prerelease' => true]),
            $this->rel('v2.9.0', ['draft' => true]),
            $this->rel('v2.4.0'),
        ];
        $this->assertSame('2.4.0', GitHubUpdater::select_latest_stable($releases)['version']);
    }

    public function test_prefers_named_asset_then_any_zip(): void
    {
        $named = $this->rel('v2.4.0', ['assets' => [
            ['name' => 'something-else.zip', 'browser_download_url' => 'https://github.com/x/y/a.zip'],
            ['name' => 'proto-blocks-2.4.0.zip', 'browser_download_url' => 'https://github.com/x/y/p.zip'],
        ]]);
        $this->assertSame('https://github.com/x/y/p.zip', GitHubUpdater::select_latest_stable([$named])['package']);

        $fallback = $this->rel('v2.4.0', ['assets' => [
            ['name' => 'whatever.zip', 'browser_download_url' => 'https://github.com/x/y/w.zip'],
        ]]);
        $this->assertSame('https://github.com/x/y/w.zip', GitHubUpdater::select_latest_stable([$fallback])['package']);
    }

    public function test_release_without_zip_asset_is_skipped(): void
    {
        $rel = $this->rel('v2.4.0', ['assets' => [
            ['name' => 'notes.txt', 'browser_download_url' => 'https://github.com/x/y/notes.txt'],
        ]]);
        $this->assertNull(GitHubUpdater::select_latest_stable([$rel]));
    }

    public function test_empty_or_malformed_returns_null(): void
    {
        $this->assertNull(GitHubUpdater::select_latest_stable([]));
        $this->assertNull(GitHubUpdater::select_latest_stable(['garbage', 42, null]));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd "<plugin dir>" && composer test -- --filter SelectLatestStable`
Expected: FAIL — `Class "ProtoBlocks\Updater\GitHubUpdater" not found`.

- [ ] **Step 3: Write the minimal implementation**

Create `includes/Updater/GitHubUpdater.php`:

```php
<?php
/**
 * GitHub Self-Updater
 *
 * Surfaces stable vX.Y.Z GitHub releases of Proto-Blocks through
 * WordPress's native plugin-update flow. Public repo, no auth token,
 * no third-party library.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Updater;

final class GitHubUpdater
{
    private const OWNER     = 'GustavoGomez092';
    private const REPO      = 'Proto-Blocks';
    private const SLUG      = 'proto-blocks';
    private const TRANSIENT = 'proto_blocks_github_release';

    /**
     * Choose the newest STABLE release from GitHub's /releases JSON.
     *
     * Stable = non-draft, non-prerelease, semver tag (vX.Y.Z). This
     * deliberately excludes the rolling "latest" tag (non-semver) that
     * build-and-release.yml maintains. Returns the resolved release or
     * null when none qualifies.
     *
     * @param array<int,mixed> $releases Decoded /releases response.
     * @return array{version:string,package:string,html_url:string,body:string,published_at:string}|null
     */
    public static function select_latest_stable(array $releases): ?array
    {
        $best     = null;
        $best_ver = '0.0.0';

        foreach ($releases as $rel) {
            if (!is_array($rel)) {
                continue;
            }
            if (!empty($rel['draft']) || !empty($rel['prerelease'])) {
                continue;
            }
            if (!preg_match('/^v?(\d+\.\d+\.\d+)$/', (string) ($rel['tag_name'] ?? ''), $m)) {
                continue;
            }
            $ver = $m[1];

            $package = self::find_zip_asset($rel['assets'] ?? [], $ver);
            if ($package === '') {
                continue;
            }

            if (version_compare($ver, $best_ver, '>')) {
                $best_ver = $ver;
                $best = [
                    'version'      => $ver,
                    'package'      => $package,
                    'html_url'     => (string) ($rel['html_url'] ?? ''),
                    'body'         => (string) ($rel['body'] ?? ''),
                    'published_at' => (string) ($rel['published_at'] ?? ''),
                ];
            }
        }

        return $best;
    }

    /**
     * Find the download URL of the release's plugin zip.
     *
     * Prefers proto-blocks-<version>.zip; falls back to the first .zip
     * asset; returns '' when the release has no zip asset.
     *
     * @param array<int,mixed> $assets Release assets.
     */
    private static function find_zip_asset(array $assets, string $version): string
    {
        $preferred = 'proto-blocks-' . $version . '.zip';
        $fallback  = '';

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = (string) ($asset['name'] ?? '');
            $url  = (string) ($asset['browser_download_url'] ?? '');
            if ($url === '') {
                continue;
            }
            if ($name === $preferred) {
                return $url;
            }
            if ($fallback === '' && substr($name, -4) === '.zip') {
                $fallback = $url;
            }
        }

        return $fallback;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test -- --filter SelectLatestStable`
Expected: PASS (6 tests, no warnings).

- [ ] **Step 5: Commit**

```bash
git add includes/Updater/GitHubUpdater.php tests/php/Updater/SelectLatestStableTest.php
git commit -m "feat(updater): pure GitHub release selection (newest stable semver + zip asset)"
```

---

## Task 2: Changelog formatting (`changelog_html`)

**Files:**
- Create: `tests/php/Updater/ChangelogHtmlTest.php`
- Modify: `includes/Updater/GitHubUpdater.php`

- [ ] **Step 1: Write the failing test**

Create `tests/php/Updater/ChangelogHtmlTest.php`:

```php
<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Updater;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Updater\GitHubUpdater;

final class ChangelogHtmlTest extends TestCase
{
    public function test_empty_body(): void
    {
        $this->assertSame('<p>No release notes.</p>', GitHubUpdater::changelog_html('   '));
    }

    public function test_headings_lists_paragraphs(): void
    {
        $md   = "## Added\n- one\n- two\n\nPlain line";
        $html = GitHubUpdater::changelog_html($md);
        $this->assertStringContainsString('<h4>Added</h4>', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
        $this->assertStringContainsString('<li>two</li>', $html);
        $this->assertStringContainsString('</ul>', $html);
        $this->assertStringContainsString('<p>Plain line</p>', $html);
    }
}
```

> Note: `tests/php/bootstrap.php` stubs `esc_html()` as a passthrough, so we test structure (headings/lists/paragraphs), not escaping. Escaping correctness comes from calling `esc_html()` in the implementation (visible in code review).

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter ChangelogHtml`
Expected: FAIL — `Call to undefined method ...::changelog_html()`.

- [ ] **Step 3: Write the minimal implementation**

Add this method to `includes/Updater/GitHubUpdater.php`, immediately after `select_latest_stable()`:

```php
    /**
     * Minimal markdown -> HTML for the release notes shown in the
     * "View details" modal. Handles headings (#), bullet lists (-, *),
     * and paragraphs. Everything is escaped via esc_html(); no external
     * parser.
     */
    public static function changelog_html(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '<p>No release notes.</p>';
        }

        $out     = [];
        $in_list = false;

        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $is_li = (bool) preg_match('/^[-*]\s+(.*)$/', $line, $li);

            if ($is_li && !$in_list) {
                $out[]   = '<ul>';
                $in_list = true;
            } elseif (!$is_li && $in_list) {
                $out[]   = '</ul>';
                $in_list = false;
            }

            if ($is_li) {
                $out[] = '<li>' . esc_html($li[1]) . '</li>';
            } elseif (preg_match('/^#{1,6}\s+(.*)$/', $line, $h)) {
                $out[] = '<h4>' . esc_html($h[1]) . '</h4>';
            } else {
                $out[] = '<p>' . esc_html($line) . '</p>';
            }
        }

        if ($in_list) {
            $out[] = '</ul>';
        }

        return implode("\n", $out);
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test -- --filter ChangelogHtml`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/Updater/GitHubUpdater.php tests/php/Updater/ChangelogHtmlTest.php
git commit -m "feat(updater): minimal markdown changelog formatter for the details modal"
```

---

## Task 3: Remote fetch + transient cache (`get_remote`, `fetch_releases`, `is_trusted_package`)

**Files:**
- Modify: `includes/Updater/GitHubUpdater.php`

No automated test: these are network/transient glue over the unit-tested `select_latest_stable()`. Correctness is verified end-to-end in Task 6.

- [ ] **Step 1: Add the fetch + cache methods**

Add these methods to `includes/Updater/GitHubUpdater.php`, after `changelog_html()`:

```php
    /**
     * Resolve the newest stable release, cached for 12h in a transient.
     *
     * The cache stores ['release' => array|null] so a failed/empty fetch
     * is also remembered (no API hammering, no false update). Pass
     * $force = true to bypass the cache (force-check / manual recheck).
     *
     * @return array{version:string,package:string,html_url:string,body:string,published_at:string}|null
     */
    public static function get_remote(bool $force = false): ?array
    {
        if (!$force) {
            $cached = get_transient(self::TRANSIENT);
            if (is_array($cached) && array_key_exists('release', $cached)) {
                return is_array($cached['release']) ? $cached['release'] : null;
            }
        }

        $releases = self::fetch_releases();
        $release  = is_array($releases) ? self::select_latest_stable($releases) : null;

        set_transient(self::TRANSIENT, ['release' => $release], 12 * HOUR_IN_SECONDS);

        return $release;
    }

    /**
     * GET the repository's releases list. Returns the decoded array, or
     * null on any network / status / decode failure.
     *
     * @return array<int,mixed>|null
     */
    private static function fetch_releases(): ?array
    {
        $url = 'https://api.github.com/repos/' . self::OWNER . '/' . self::REPO . '/releases?per_page=30';

        $response = wp_remote_get($url, [
            'timeout' => 8,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'Proto-Blocks-Updater',
            ],
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Guard the download URL to GitHub hosts before WordPress fetches it.
     */
    private static function is_trusted_package(string $url): bool
    {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

        return $host === 'github.com'
            || $host === 'objects.githubusercontent.com'
            || substr($host, -11) === '.github.com';
    }
```

- [ ] **Step 2: Lint the file**

Run: `php -l includes/Updater/GitHubUpdater.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Re-run the full PHP suite (nothing regressed)**

Run: `composer test`
Expected: PASS (all tests, including the two updater test files).

- [ ] **Step 4: Commit**

```bash
git add includes/Updater/GitHubUpdater.php
git commit -m "feat(updater): cached GitHub releases fetch with trusted-host package guard"
```

---

## Task 4: Update hooks (`__construct`, `check_update`, `plugins_api_handler`, `rename_source`)

**Files:**
- Modify: `includes/Updater/GitHubUpdater.php`

No automated test (WordPress filter glue); verified in Task 6.

- [ ] **Step 1: Add instance state + the three hook callbacks**

Add these properties at the top of the class body in `includes/Updater/GitHubUpdater.php` (immediately after the constants):

```php
    private string $file;     // absolute plugin file (PROTO_BLOCKS_FILE)
    private string $basename; // 'Proto-Blocks/proto-blocks.php'
    private string $dir;      // 'Proto-Blocks' (install folder name)
```

Add the constructor (place it before `select_latest_stable()`):

```php
    public function __construct(string $plugin_file)
    {
        $this->file     = $plugin_file;
        $this->basename = plugin_basename($plugin_file);
        $this->dir      = dirname($this->basename);
    }
```

Add the three hook callbacks at the end of the class body (after `is_trusted_package()`):

```php
    /**
     * pre_set_site_transient_update_plugins: inject our update entry when
     * a newer trusted release exists; otherwise mark the plugin as
     * up to date so WordPress doesn't show an unknown state.
     *
     * @param mixed $transient
     * @return mixed
     */
    public function check_update($transient)
    {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $force   = !empty($_GET['force-check']); // phpcs:ignore WordPress.Security.NonceVerification
        $release = self::get_remote($force);
        $current = defined('PROTO_BLOCKS_VERSION') ? PROTO_BLOCKS_VERSION : '0.0.0';

        if (is_array($release)
            && version_compare($release['version'], $current, '>')
            && self::is_trusted_package($release['package'])
        ) {
            $transient->response[$this->basename] = (object) [
                'slug'        => self::SLUG,
                'plugin'      => $this->basename,
                'new_version' => $release['version'],
                'package'     => $release['package'],
                'url'         => $release['html_url'],
                'tested'      => '',
            ];
        } else {
            $transient->no_update[$this->basename] = (object) [
                'slug'        => self::SLUG,
                'plugin'      => $this->basename,
                'new_version' => $current,
                'url'         => 'https://github.com/' . self::OWNER . '/' . self::REPO,
                'package'     => '',
            ];
        }

        return $transient;
    }

    /**
     * plugins_api: provide the "View details" modal data for our slug.
     *
     * @param mixed  $result
     * @param string $action
     * @param object $args
     * @return mixed
     */
    public function plugins_api_handler($result, $action, $args)
    {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== self::SLUG) {
            return $result;
        }

        $release = self::get_remote();
        if (!is_array($release)) {
            return $result;
        }

        return (object) [
            'name'          => 'Proto-Blocks',
            'slug'          => self::SLUG,
            'version'       => $release['version'],
            'author'        => '<a href="https://github.com/' . self::OWNER . '">Gustavo Gomez</a>',
            'homepage'      => 'https://github.com/' . self::OWNER . '/' . self::REPO,
            'download_link' => $release['package'],
            'trunk'         => $release['package'],
            'requires'      => '6.3',
            'requires_php'  => '8.0',
            'last_updated'  => $release['published_at'],
            'sections'      => [
                'changelog' => self::changelog_html($release['body']),
            ],
        ];
    }

    /**
     * upgrader_source_selection: the release zip unpacks to a lowercase
     * "proto-blocks" folder, but the plugin may be installed under a
     * different folder (it is "Proto-Blocks" here). Rename the unpacked
     * source to match the installed folder so WordPress updates in place
     * and the plugin stays active. Scoped to our plugin only.
     *
     * @param string $source        Unpacked subfolder path.
     * @param string $remote_source Temp parent dir.
     * @param object $upgrader      WP_Upgrader instance (unused).
     * @param array  $hook_extra    Upgrade context.
     * @return string|\WP_Error
     */
    public function rename_source($source, $remote_source, $upgrader, $hook_extra = [])
    {
        if (!is_array($hook_extra) || ($hook_extra['plugin'] ?? '') !== $this->basename) {
            return $source;
        }

        $source = untrailingslashit($source);
        if (basename($source) === $this->dir) {
            return trailingslashit($source);
        }

        $desired = trailingslashit($remote_source) . $this->dir;

        global $wp_filesystem;
        if ($wp_filesystem && $wp_filesystem->move($source, $desired, true)) {
            return trailingslashit($desired);
        }

        return trailingslashit($source);
    }
```

- [ ] **Step 2: Lint the file**

Run: `php -l includes/Updater/GitHubUpdater.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Re-run the PHP suite (pure methods still pass)**

Run: `composer test`
Expected: PASS (the added instance methods don't affect the unit tests).

- [ ] **Step 4: Commit**

```bash
git add includes/Updater/GitHubUpdater.php
git commit -m "feat(updater): WP update hooks (transient, plugins_api, install folder rename)"
```

---

## Task 5: Registration, manual recheck, and wiring into the plugin

**Files:**
- Modify: `includes/Updater/GitHubUpdater.php`
- Modify: `includes/Core/Plugin.php`

- [ ] **Step 1: Add `register()`, `action_links()`, and `maybe_force_check()`**

Add these methods to `includes/Updater/GitHubUpdater.php`, immediately after the constructor:

```php
    /**
     * Wire the WordPress hooks. No-op on a git checkout: WordPress's
     * updater deletes + replaces the plugin folder, which would destroy
     * a development working copy (.git, src/, node_modules). Dev installs
     * update via git, not this updater.
     */
    public function register(): void
    {
        if (is_dir(dirname($this->file) . '/.git')) {
            return;
        }

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugins_api_handler'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'rename_source'], 10, 4);
        add_filter('plugin_action_links_' . $this->basename, [$this, 'action_links']);
        add_action('admin_init', [$this, 'maybe_force_check']);
    }

    /**
     * Add a "Check for updates" link to the plugin's row on the Plugins
     * screen.
     *
     * @param array<string,string> $links
     * @return array<string,string>
     */
    public function action_links(array $links): array
    {
        $url = wp_nonce_url(
            add_query_arg('proto_blocks_check_update', '1', admin_url('plugins.php')),
            'proto_blocks_check_update'
        );
        $links['pb_check_update'] = '<a href="' . esc_url($url) . '">'
            . esc_html__('Check for updates', 'proto-blocks') . '</a>';

        return $links;
    }

    /**
     * Handle the "Check for updates" link: bust our cache + WP's plugin
     * update cache, then redirect back to the Plugins screen.
     */
    public function maybe_force_check(): void
    {
        if (empty($_GET['proto_blocks_check_update'])) {
            return;
        }
        if (!current_user_can('update_plugins')) {
            return;
        }
        check_admin_referer('proto_blocks_check_update');

        delete_transient(self::TRANSIENT);
        self::get_remote(true);
        delete_site_transient('update_plugins');

        wp_safe_redirect(add_query_arg('proto_blocks_checked', '1', admin_url('plugins.php')));
        exit;
    }
```

- [ ] **Step 2: Wire it into `Plugin::initializeServices()`**

In `includes/Core/Plugin.php`, add the import near the other `use` statements (after `use ProtoBlocks\Tailwind\AdminSettings as TailwindAdminSettings;`):

```php
use ProtoBlocks\Updater\GitHubUpdater;
```

Then, inside `initializeServices()`, add the updater to the existing admin-only block (the one that creates `admin_page` / `setup_wizard`), right after the `setup_wizard` lines:

```php
            // GitHub self-updater (admin only; no-ops on git checkouts)
            $this->services['updater'] = new GitHubUpdater(PROTO_BLOCKS_FILE);
            $this->services['updater']->register();
```

- [ ] **Step 3: Lint both files**

Run: `php -l includes/Updater/GitHubUpdater.php && php -l includes/Core/Plugin.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Confirm the site still loads + suite passes**

Run: `~/.local/bin/wp --user=1 option get blogname` (loads WP + the plugin without fatal)
Expected: prints the site name (no PHP fatal).
Run: `composer test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/Updater/GitHubUpdater.php includes/Core/Plugin.php
git commit -m "feat(updater): register hooks (git-checkout guard), manual recheck link, wiring"
```

---

## Task 6: End-to-end verification + docs

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Confirm the updater ships in the dist zip**

Run: `npm run dist && unzip -l dist/proto-blocks-*.zip | grep 'includes/Updater/GitHubUpdater.php'`
Expected: the file is listed (the existing `includes/**/*` pattern covers it).

- [ ] **Step 2: End-to-end install test — ON A THROWAWAY SITE, NOT THIS GIT CHECKOUT**

> CRITICAL: Do **not** click "Update now" on this development install. The updater no-ops here anyway (the `.git` guard), and a real update would delete `.git`, `src/`, `node_modules`. Perform the install test on a separate Local site (or a plain copy of the plugin with no `.git`).

On the throwaway site:
1. Install an older build as a normal plugin: download `proto-blocks-2.3.1.zip` from the GitHub release and install it via **Plugins → Add New → Upload Plugin**; activate it. (It installs under a folder with no `.git`, so the updater is active.)
2. Visit **Dashboard → Updates** (or **Plugins**). Expected: Proto-Blocks shows an available update to the newest stable `vX.Y.Z`.
3. Open the plugin's **View details** link. Expected: the modal shows the version, author, homepage, and a formatted changelog from the release notes.
4. Click **Update now**. Expected: it downloads `proto-blocks-<new>.zip`, installs successfully, the plugin folder name is unchanged, and the plugin stays active at the new version.
5. Click the **Check for updates** link on the plugin row. Expected: it returns to the Plugins screen and (with no newer release) shows no update.

Record the results of steps 2–5.

- [ ] **Step 3: Negative check — no false update when current**

On the throwaway site now running the newest version, reload **Plugins**.
Expected: no update badge; the plugin is listed as up to date.

- [ ] **Step 4: Update the docs**

In `README.md`, add a bullet under the **Features** list:

```markdown
- **Self-Updating**: Installs update from GitHub releases through the native WordPress update flow (stable releases only; no API key). Disabled automatically on git checkouts.
```

In `CHANGELOG.md`, add under the top (current unreleased) section an `### Added` entry:

```markdown
- GitHub self-updater: surfaces stable `vX.Y.Z` releases through WordPress's
  native plugin-update flow (transient-cached, no API key, no third-party
  library). Includes a "Check for updates" link and a git-checkout safety
  guard. The release zip's lowercase `proto-blocks` folder is renamed to the
  installed folder on update so the plugin stays active.
```

> Do not edit version numbers in `proto-blocks.php` / `package.json` — the release CI bumps them from the conventional commits in this branch.

- [ ] **Step 5: Commit**

```bash
git add README.md CHANGELOG.md
git commit -m "docs(updater): document the GitHub self-updater in README + CHANGELOG"
```

---

## Self-Review

**Spec coverage:**
- Native WP updates from stable releases → Tasks 4 (`check_update`, `plugins_api_handler`), 5 (wiring). ✓
- One hand-rolled class, no library → Tasks 1–5. ✓
- Semver filtering, ignore rolling `latest` → Task 1 (`select_latest_stable`), unit-tested. ✓
- Asset selection (`proto-blocks-<v>.zip`, fallback) → Task 1 (`find_zip_asset`), unit-tested. ✓
- Folder rename `proto-blocks` → `Proto-Blocks` → Task 4 (`rename_source`). ✓
- `plugins_api` details + changelog → Tasks 2 (`changelog_html`), 4 (`plugins_api_handler`). ✓
- 12h transient cache + force-check + "Check for updates" link → Tasks 3 (`get_remote`), 4 (force-check in `check_update`), 5 (`action_links`/`maybe_force_check`). ✓
- Error handling: failures → cached/no update, never fatal/false → Task 3 (null caching), Task 4 (guards). ✓
- Download host validation → Task 3 (`is_trusted_package`), used in Task 4. ✓
- Ships in dist zip → Task 6 Step 1. ✓
- Testing: pure logic unit-tested + manual QA matrix → Tasks 1, 2 (unit), Task 6 (manual). ✓
- YAGNI: no token, no beta channel → not implemented. ✓
- Added beyond spec (safety): git-checkout guard in `register()` → prevents destroying dev checkouts; documented in Task 5 and Task 6.

**Placeholder scan:** No TBD/TODO; every code step contains complete code; every command has expected output. ✓

**Type/name consistency:** `select_latest_stable` returns the `{version,package,html_url,body,published_at}` shape used by `get_remote`, `check_update`, and `plugins_api_handler`. `self::SLUG` (`proto-blocks`) used consistently for matching; `$this->basename` / `$this->dir` consistent across hooks. `get_remote`/`select_latest_stable`/`changelog_html` are static and called as `self::`. ✓
