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
