<?php
/**
 * GitHub Self-Updater
 *
 * Surfaces stable vX.Y.Z GitHub releases of Proto-Blocks through
 * WordPress's native plugin-update flow. Public repo, no auth token,
 * no third-party library.
 *
 * Hardening (see tests/php/Updater):
 *  - Only `https://github.com/<OWNER>/<REPO>/releases/download/...` URLs
 *    are ever cached, offered or downloaded (is_trusted_package()).
 *  - The release asset must be named exactly proto-blocks-<version>.zip.
 *  - The downloaded zip is verified against the sha256 the release
 *    workflow publishes (release body line `sha256: <hex>` and/or the
 *    `proto-blocks-<version>.zip.sha256` asset) before WordPress unpacks it.
 *  - wordpress.org cannot supply an update for this basename: the plugin
 *    declares `Update URI: https://github.com/...` and check_update()
 *    drops any foreign response entry.
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

    /** Hostname of the `Update URI` header; core fires update_plugins_{hostname}. */
    private const UPDATE_URI_HOST = 'github.com';

    /**
     * Filter: return true to refuse installing a release that publishes no
     * sha256 checksum. Defaults to false so releases cut before the
     * checksum was added to the workflow still install. This default will
     * flip to true in a future major version; sites can opt in today with
     * `add_filter('proto_blocks_require_update_checksum', '__return_true')`.
     */
    public const REQUIRE_CHECKSUM_FILTER = 'proto_blocks_require_update_checksum';

    private string $file;     // absolute plugin file (PROTO_BLOCKS_FILE)
    private string $basename; // 'Proto-Blocks/proto-blocks.php'
    private string $dir;      // 'Proto-Blocks' (install folder name)

    /** error_log() the "no checksum" condition at most once per request. */
    private static bool $logged_missing_checksum = false;

    public function __construct(string $plugin_file)
    {
        $this->file     = $plugin_file;
        $this->basename = plugin_basename($plugin_file);
        $this->dir      = dirname($this->basename);
    }

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

        // Core update mechanism. Registered in ALL contexts (admin AND the
        // WP-Cron auto-update path, which is non-admin) so background
        // auto-updates also see the release and get the install folder
        // renamed correctly.
        //
        // update_plugins_github.com is core's sanctioned path for plugins
        // with an `Update URI` header (WP 5.8+); pre_set_site_transient
        // remains as the authority that also evicts any foreign entry.
        add_filter('update_plugins_' . self::UPDATE_URI_HOST, [$this, 'update_uri_check'], 10, 4);
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugins_api_handler'], 10, 3);
        add_filter('upgrader_pre_download', [$this, 'verified_download'], 10, 4);
        add_filter('upgrader_source_selection', [$this, 'rename_source'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'after_update'], 10, 2);

        // Admin-only UI affordances.
        if (is_admin()) {
            add_filter('plugin_action_links_' . $this->basename, [$this, 'action_links']);
            add_action('admin_init', [$this, 'maybe_force_check']);
            add_action('admin_notices', [$this, 'checked_notice']);
        }
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
        check_admin_referer('proto_blocks_check_update');
        if (!current_user_can('update_plugins')) {
            wp_die(
                esc_html__('You do not have permission to check for updates.', 'proto-blocks'),
                '',
                ['response' => 403]
            );
        }

        delete_transient(self::TRANSIENT);
        self::get_remote(true);
        delete_site_transient('update_plugins');

        wp_safe_redirect(add_query_arg('proto_blocks_checked', '1', admin_url('plugins.php')));
        exit;
    }

    /**
     * After an update finishes, if it was this plugin, drop our cached
     * release and WordPress's plugin-update cache so a stale "update
     * available" prompt doesn't linger post-update. Runs in admin and
     * cron (auto-update) contexts.
     *
     * @param \WP_Upgrader $upgrader
     * @param array        $hook_extra
     */
    public function after_update($upgrader, $hook_extra): void
    {
        if (!is_array($hook_extra)) {
            return;
        }
        if (($hook_extra['action'] ?? '') !== 'update' || ($hook_extra['type'] ?? '') !== 'plugin') {
            return;
        }

        // Bulk updates pass 'plugins' (array); single updates pass 'plugin'.
        $plugins = (array) ($hook_extra['plugins'] ?? []);
        if (isset($hook_extra['plugin'])) {
            $plugins[] = $hook_extra['plugin'];
        }
        if (!in_array($this->basename, $plugins, true)) {
            return;
        }

        delete_transient(self::TRANSIENT);
        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache();
        }
    }

    /**
     * Show a one-time "update check complete" notice after the manual
     * "Check for updates" link redirects back to the Plugins screen.
     */
    public function checked_notice(): void
    {
        if (empty($_GET['proto_blocks_checked'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>'
            . esc_html__('Proto-Blocks: update check complete.', 'proto-blocks')
            . '</p></div>';
    }

    /**
     * Choose the newest STABLE release from GitHub's /releases JSON.
     *
     * Stable = non-draft, non-prerelease, semver tag (vX.Y.Z), carrying a
     * trusted proto-blocks-<version>.zip asset. This deliberately excludes
     * the rolling "latest" tag (non-semver) that build-and-release.yml
     * maintains. Returns the resolved release or null when none qualifies.
     *
     * @param array<int,mixed> $releases Decoded /releases response.
     * @return array{version:string,package:string,html_url:string,body:string,published_at:string,sha256:string,sha256_url:string}|null
     */
    public static function select_latest_stable(array $releases): ?array
    {
        $best     = null;
        $best_ver = '0.0.0';

        foreach ($releases as $rel) {
            $candidate = self::normalise_release($rel);
            if ($candidate === null) {
                continue;
            }
            if (version_compare($candidate['version'], $best_ver, '>')) {
                $best_ver = $candidate['version'];
                $best     = $candidate;
            }
        }

        return $best;
    }

    /**
     * Reduce one raw /releases entry to the fields the updater uses, or
     * null when it is not a stable, downloadable release.
     *
     * @param mixed $rel
     * @return array{version:string,package:string,html_url:string,body:string,published_at:string,sha256:string,sha256_url:string}|null
     */
    private static function normalise_release($rel): ?array
    {
        if (!is_array($rel)) {
            return null;
        }
        if (!empty($rel['draft']) || !empty($rel['prerelease'])) {
            return null;
        }
        if (!preg_match('/^v?(\d+\.\d+\.\d+)$/', (string) ($rel['tag_name'] ?? ''), $m)) {
            return null;
        }
        $ver    = $m[1];
        $assets = is_array($rel['assets'] ?? null) ? $rel['assets'] : [];

        $package = self::find_zip_asset($assets, $ver);
        if ($package === '') {
            return null;
        }

        $body = (string) ($rel['body'] ?? '');

        return [
            'version'      => $ver,
            'package'      => $package,
            'html_url'     => (string) ($rel['html_url'] ?? ''),
            'body'         => $body,
            'published_at' => (string) ($rel['published_at'] ?? ''),
            'sha256'       => self::extract_sha256($body),
            'sha256_url'   => self::find_checksum_asset($assets, $ver),
        ];
    }

    /**
     * Pull a `sha256: <64 hex>` declaration out of free text (release body
     * or the contents of a .sha256 sidecar). Returns lowercase hex or ''.
     */
    public static function extract_sha256(string $text): string
    {
        // The release workflow appends the checksum as a trailer on the LAST
        // line of the body. Auto-generated notes above it echo PR titles and
        // commit subjects, which anyone with a merged PR controls, so the
        // first match in the body must never win: prefer an anchored trailer
        // and otherwise take the last declaration in the text.
        if (preg_match('/^---\s*\R\s*sha256:\s*([a-f0-9]{64})\s*$/im', $text, $m)) {
            return strtolower($m[1]);
        }
        if (preg_match_all('/sha256:\s*([a-f0-9]{64})\b/i', $text, $all) && !empty($all[1])) {
            return strtolower((string) end($all[1]));
        }

        return '';
    }

    /**
     * Parse the contents of a `.sha256` sidecar file: either a bare hash,
     * `sha256sum` output (`<hex>  <filename>`), or a `sha256: <hex>` line.
     */
    public static function extract_sha256_from_sidecar(string $text): string
    {
        $from_label = self::extract_sha256($text);
        if ($from_label !== '') {
            return $from_label;
        }
        if (preg_match('/(?:^|\s)([a-f0-9]{64})(?=\s|$)/i', $text, $m)) {
            return strtolower($m[1]);
        }

        return '';
    }

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

    /**
     * Resolve the newest stable release, cached for 12h in a transient.
     *
     * The cache stores ['release' => array|null] so a failed/empty fetch
     * is also remembered (no API hammering, no false update). Pass
     * $force = true to bypass the cache (force-check / manual recheck).
     *
     * @return array{version:string,package:string,html_url:string,body:string,published_at:string,sha256:string,sha256_url:string}|null
     */
    public static function get_remote(bool $force = false): ?array
    {
        if (!$force) {
            $cached = get_transient(self::TRANSIENT);
            if (is_array($cached) && array_key_exists('release', $cached)) {
                $release = $cached['release'];
                // Entries cached by an older plugin version lack the checksum
                // keys; treat them as a miss so they are re-fetched.
                if ($release === null || (is_array($release) && array_key_exists('sha256', $release))) {
                    return $release;
                }
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
                'Accept'               => 'application/vnd.github+json',
                'User-Agent'           => 'Proto-Blocks-Updater',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Only release assets of THIS repository are acceptable packages:
     * https, host github.com, path under /<OWNER>/<REPO>/releases/download/.
     *
     * GitHub redirects asset downloads to objects.githubusercontent.com;
     * that hop happens inside download_url() and never re-enters this
     * check, so that host is deliberately not allow-listed (its paths carry
     * no owner/repo and would admit any repository's assets).
     */
    public static function is_trusted_package(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        if (strtolower((string) ($parts['host'] ?? '')) !== 'github.com') {
            return false;
        }

        $path = (string) ($parts['path'] ?? '');
        if (str_contains($path, '..') || str_contains($path, '//') || str_contains($path, '\\') || str_contains($path, '%')) {
            return false;
        }

        $prefix = '/' . self::OWNER . '/' . self::REPO . '/releases/download/';

        return strlen($path) > strlen($prefix)
            && strncasecmp($path, $prefix, strlen($prefix)) === 0;
    }

    /**
     * `proto-blocks-v2.4.0.zip` and `proto-blocks-2.4.0.zip` are the same
     * artefact; compare on the un-prefixed form.
     */
    private static function normalise_asset_name(string $name): string
    {
        return (string) preg_replace('/^proto-blocks-v(?=\d)/', 'proto-blocks-', $name);
    }

    /**
     * Find the download URL of the release's plugin zip.
     *
     * Requires the exact name proto-blocks-<version>.zip (leading "v"
     * tolerated) on a trusted URL. No fallback to "any .zip": a stray or
     * planted asset must never be installed as the plugin.
     *
     * @param array<int,mixed> $assets Release assets.
     */
    private static function find_zip_asset(array $assets, string $version): string
    {
        return self::find_asset_url($assets, 'proto-blocks-' . $version . '.zip');
    }

    /**
     * Find the download URL of the release's sha256 sidecar
     * (proto-blocks-<version>.zip.sha256), or '' when absent.
     *
     * @param array<int,mixed> $assets Release assets.
     */
    private static function find_checksum_asset(array $assets, string $version): string
    {
        return self::find_asset_url($assets, 'proto-blocks-' . $version . '.zip.sha256');
    }

    /**
     * @param array<int,mixed> $assets
     */
    private static function find_asset_url(array $assets, string $wanted): string
    {
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            if (self::normalise_asset_name((string) ($asset['name'] ?? '')) !== $wanted) {
                continue;
            }
            $url = (string) ($asset['browser_download_url'] ?? '');

            return self::is_trusted_package($url) ? $url : '';
        }

        return '';
    }

    /**
     * The version actually installed on disk right now.
     *
     * Deliberately NOT PROTO_BLOCKS_VERSION. That constant is fixed when the
     * plugin file is loaded, at the very start of the request. WordPress re-runs
     * the update check inside the SAME request that just swapped the plugin
     * files, so by then disk holds the new version while the constant still
     * holds the old one — the comparison sees "newer release available" and
     * re-offers the version that was only just installed. That is why the
     * prompt survived the update and only cleared after two or three rounds:
     * each subsequent request booted the new constant, but the stale entry had
     * already been written to the update_plugins transient.
     *
     * $transient->checked is WordPress's own scan of the plugin headers, and
     * after_update() calls wp_clean_plugins_cache() so it re-reads from disk.
     * It is therefore the honest answer to "what is installed".
     *
     * @param mixed $transient
     */
    private function installed_version($transient): string
    {
        $checked = is_object($transient) && is_array($transient->checked ?? null)
            ? $transient->checked
            : [];
        $version = $checked[$this->basename] ?? '';

        if (is_string($version) && $version !== '') {
            return $version;
        }

        // No entry for us (plugin not scanned yet): fall back to the constant.
        return defined('PROTO_BLOCKS_VERSION') ? PROTO_BLOCKS_VERSION : '0.0.0';
    }

    /**
     * Whether this request may bypass the 12h release cache via
     * ?force-check=1. Core's own "Check again" link carries no nonce, so an
     * un-nonced request is simply treated as a normal (cached) check rather
     * than refused; a nonced URL (action proto_blocks_force_check) from a
     * user who can update plugins is honoured.
     */
    private function is_forced_check(): bool
    {
        if (empty($_GET['force-check'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return false;
        }
        if (!function_exists('current_user_can') || !current_user_can('update_plugins')) {
            return false;
        }
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])) : '';

        return $nonce !== '' && wp_verify_nonce($nonce, 'proto_blocks_force_check') !== false;
    }

    /**
     * Build the update entry core expects, or null when no trusted newer
     * release is known.
     *
     * @param array<string,mixed>|null $release
     * @return array<string,string>|null
     */
    private function update_payload(?array $release, string $installed): ?array
    {
        if (!is_array($release)
            || !version_compare((string) $release['version'], $installed, '>')
            || !self::is_trusted_package((string) $release['package'])
        ) {
            return null;
        }

        return [
            'id'           => 'https://github.com/' . self::OWNER . '/' . self::REPO,
            'slug'         => self::SLUG,
            'plugin'       => $this->basename,
            'version'      => (string) $release['version'],
            'new_version'  => (string) $release['version'],
            'package'      => (string) $release['package'],
            'url'          => (string) $release['html_url'],
            'tested'       => '',
            'requires_php' => '8.0',
        ];
    }

    /**
     * update_plugins_github.com (WP 5.8+ `Update URI` mechanism): answer
     * core's question "is there an update for this plugin file?" for our
     * basename only. Any other plugin pointing its Update URI at github.com
     * is passed through untouched.
     *
     * @param array|false $update
     * @param array       $plugin_data Plugin headers.
     * @param string      $plugin_file Plugin basename.
     * @param string[]    $locales
     * @return array|false
     */
    public function update_uri_check($update, $plugin_data, $plugin_file, $locales = [])
    {
        if (!is_string($plugin_file) || $plugin_file !== $this->basename) {
            return $update;
        }

        $installed = is_array($plugin_data) && !empty($plugin_data['Version'])
            ? (string) $plugin_data['Version']
            : (defined('PROTO_BLOCKS_VERSION') ? PROTO_BLOCKS_VERSION : '0.0.0');

        $payload = $this->update_payload(self::get_remote(), $installed);

        return $payload ?? $update;
    }

    /**
     * pre_set_site_transient_update_plugins: inject our update entry when
     * a newer trusted release exists; otherwise mark the plugin as
     * up to date AND evict any entry another source (e.g. a wordpress.org
     * plugin squatting the `proto-blocks` slug) placed under our basename.
     *
     * @param mixed $transient
     * @return mixed
     */
    public function check_update($transient)
    {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $release = self::get_remote($this->is_forced_check());
        $current = $this->installed_version($transient);
        $payload = $this->update_payload($release, $current);

        if (!is_array($transient->response ?? null)) {
            $transient->response = [];
        }
        if (!is_array($transient->no_update ?? null)) {
            $transient->no_update = [];
        }

        if ($payload !== null) {
            unset($transient->no_update[$this->basename]);
            $transient->response[$this->basename] = (object) $payload;
        } else {
            unset($transient->response[$this->basename]);
            $transient->no_update[$this->basename] = (object) [
                'id'          => 'https://github.com/' . self::OWNER . '/' . self::REPO,
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

        // Never hand core an install URL we would refuse to download.
        $package = self::is_trusted_package((string) $release['package']) ? (string) $release['package'] : '';

        return (object) [
            'name'          => 'Proto-Blocks',
            'slug'          => self::SLUG,
            'version'       => $release['version'],
            'author'        => '<a href="https://github.com/' . self::OWNER . '">Gustavo Gomez</a>',
            'homepage'      => 'https://github.com/' . self::OWNER . '/' . self::REPO,
            'download_link' => $package,
            'trunk'         => $package,
            'requires'      => '6.5',
            'requires_php'  => '8.1',
            'last_updated'  => $release['published_at'],
            'sections'      => [
                'changelog' => self::changelog_html($release['body']),
            ],
        ];
    }

    /**
     * Whether a release without a published checksum must be refused.
     * See REQUIRE_CHECKSUM_FILTER.
     */
    public static function checksum_required(): bool
    {
        return (bool) apply_filters(self::REQUIRE_CHECKSUM_FILTER, false);
    }

    /**
     * Compare a downloaded file against an expected sha256.
     *
     * Pure helper (no WordPress I/O beyond the filesystem) so the policy is
     * unit-testable:
     *  - expected hash present: file hash must match, else WP_Error
     *  - expected hash absent:  allowed unless $require_checksum
     *
     * @return true|\WP_Error
     */
    public static function verify_package_file(string $file, string $expected_sha256, bool $require_checksum)
    {
        $expected = strtolower(trim($expected_sha256));

        if ($expected === '') {
            if ($require_checksum) {
                return new \WP_Error(
                    'proto_blocks_no_checksum',
                    __('Proto-Blocks update refused: this release publishes no sha256 checksum.', 'proto-blocks')
                );
            }

            return true;
        }

        if (!preg_match('/^[a-f0-9]{64}$/', $expected)) {
            return new \WP_Error(
                'proto_blocks_bad_checksum_format',
                __('Proto-Blocks update refused: the published checksum is malformed.', 'proto-blocks')
            );
        }

        if (!is_file($file) || !is_readable($file)) {
            return new \WP_Error(
                'proto_blocks_unreadable_package',
                __('Proto-Blocks update refused: the downloaded package could not be read.', 'proto-blocks')
            );
        }

        $actual = hash_file('sha256', $file);
        if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
            return new \WP_Error(
                'proto_blocks_bad_checksum',
                __('Proto-Blocks update refused: the downloaded package does not match the published sha256 checksum.', 'proto-blocks')
            );
        }

        return true;
    }

    /**
     * Locate the release record that offers $package. Normally the cached
     * one; falls back to a fresh /releases scan when the cache has moved
     * on (a newer release appeared between "update available" and "click").
     *
     * @return array<string,mixed>|null
     */
    private static function release_for_package(string $package): ?array
    {
        $release = self::get_remote();
        if (is_array($release) && $release['package'] === $package) {
            return $release;
        }

        $releases = self::fetch_releases();
        if (!is_array($releases)) {
            return null;
        }
        foreach ($releases as $rel) {
            $candidate = self::normalise_release($rel);
            if ($candidate !== null && $candidate['package'] === $package) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The sha256 the release publishes for its zip: from the release body
     * first, else fetched from the .sha256 sidecar asset. '' when neither
     * exists.
     *
     * @param array<string,mixed> $release
     */
    private static function expected_checksum(array $release): string
    {
        // The .sha256 sidecar asset is written by the release workflow itself
        // and cannot be influenced by PR titles, so it is the authority; the
        // body trailer is only a fallback when the sidecar is unavailable.
        $url = (string) ($release['sha256_url'] ?? '');
        if ($url !== '' && self::is_trusted_package($url)) {
            $response = wp_remote_get($url, [
                'timeout'    => 15,
                'user-agent' => 'Proto-Blocks-Updater',
            ]);
            if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
                $sha = self::extract_sha256_from_sidecar((string) wp_remote_retrieve_body($response));
                if ($sha !== '') {
                    return $sha;
                }
            }
        }

        return (string) ($release['sha256'] ?? '');
    }

    /**
     * upgrader_pre_download: fetch our package ourselves and verify its
     * sha256 against the one the release publishes before WordPress unpacks
     * it. Returns the verified temp file path, a WP_Error, or $reply
     * untouched for packages that are not ours.
     *
     * @param false|string|\WP_Error $reply
     * @param string                 $package
     * @param \WP_Upgrader           $upgrader
     * @param array                  $hook_extra
     * @return false|string|\WP_Error
     */
    public function verified_download($reply, $package, $upgrader, $hook_extra = [])
    {
        if ($reply !== false || !is_string($package)) {
            return $reply;
        }

        $hook_extra = is_array($hook_extra) ? $hook_extra : [];
        $is_ours    = ($hook_extra['plugin'] ?? '') === $this->basename;

        // Install-by-slug (plugins_api download_link) carries no 'plugin'
        // key; recognise our own release URL instead.
        if (!$is_ours) {
            $is_ours = ($hook_extra['type'] ?? '') === 'plugin' && self::is_trusted_package($package);
        }
        if (!$is_ours) {
            return $reply;
        }

        if (!self::is_trusted_package($package)) {
            return new \WP_Error(
                'proto_blocks_untrusted_package',
                __('Proto-Blocks update refused: the package is not a release asset of the Proto-Blocks repository.', 'proto-blocks')
            );
        }

        $release = self::release_for_package($package);
        if ($release === null) {
            return new \WP_Error(
                'proto_blocks_unknown_release',
                __('Proto-Blocks update refused: the package does not belong to a published stable release. Check for updates again.', 'proto-blocks')
            );
        }

        $expected = self::expected_checksum($release);
        $require  = self::checksum_required();

        if ($expected === '' && !$require && !self::$logged_missing_checksum) {
            self::$logged_missing_checksum = true;
            error_log(sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                'Proto-Blocks: release v%s publishes no sha256 checksum; installing unverified. Add add_filter(\'%s\', \'__return_true\') to refuse such releases.',
                (string) $release['version'],
                self::REQUIRE_CHECKSUM_FILTER
            ));
        }

        if ($expected === '' && $require) {
            return self::verify_package_file('', '', true);
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $file = download_url($package, 300);
        if (is_wp_error($file)) {
            return $file;
        }

        $verdict = self::verify_package_file((string) $file, $expected, $require);
        if ($verdict !== true) {
            @unlink((string) $file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return $verdict;
        }

        return $file;
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
        if (!$wp_filesystem) {
            return new \WP_Error(
                'proto_blocks_no_filesystem',
                __('Proto-Blocks update failed: the filesystem is not initialised.', 'proto-blocks')
            );
        }

        if ($wp_filesystem->move($source, $desired, true)) {
            return trailingslashit($desired);
        }

        return new \WP_Error(
            'proto_blocks_rename_failed',
            sprintf(
                /* translators: %s: target plugin folder name */
                __('Proto-Blocks update failed: could not rename the unpacked folder to %s.', 'proto-blocks'),
                $this->dir
            )
        );
    }
}
