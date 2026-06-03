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
