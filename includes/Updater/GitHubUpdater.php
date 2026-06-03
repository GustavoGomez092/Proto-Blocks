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
