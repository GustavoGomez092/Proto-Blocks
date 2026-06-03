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
