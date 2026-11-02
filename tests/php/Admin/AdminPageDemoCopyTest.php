<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Admin;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Admin\AdminPage;

/**
 * Demo install/remove must never delete a same-named custom block or a demo
 * the developer has edited. These exercise the directory check it relies on.
 */
final class AdminPageDemoCopyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pb-demo-copy-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);
    }

    public function testManifestListsFilesRelativeAndSortedExcludingMarker(): void
    {
        $dir = $this->makeBlock('shipped', [
            'template.php'   => '<?php echo 1;',
            'block.json'     => '{"name":"proto-blocks/hero"}',
            'assets/a.css'   => 'body{}',
            AdminPage::DEMO_MARKER => '{"files":{}}',
        ]);

        $manifest = AdminPage::directoryManifest($dir);

        self::assertSame(['assets/a.css', 'block.json', 'template.php'], array_keys($manifest));
        self::assertSame(hash('sha256', '<?php echo 1;'), $manifest['template.php']);
        self::assertArrayNotHasKey(AdminPage::DEMO_MARKER, $manifest);
    }

    public function testIdenticalCopyWithoutMarkerIsADemo(): void
    {
        $files = ['template.php' => '<?php echo 1;', 'block.json' => '{"name":"proto-blocks/hero"}'];
        $shipped   = $this->makeBlock('examples/hero', $files);
        $installed = $this->makeBlock('theme/hero', $files);

        self::assertTrue(AdminPage::isDemoCopy($installed, $shipped));
    }

    public function testEditedTemplateIsNotADemo(): void
    {
        $shipped   = $this->makeBlock('examples/hero', ['template.php' => '<?php echo 1;', 'block.json' => '{}']);
        $installed = $this->makeBlock('theme/hero', ['template.php' => '<?php echo 2; // mine', 'block.json' => '{}']);

        self::assertFalse(AdminPage::isDemoCopy($installed, $shipped));
    }

    public function testSameNamedCustomBlockIsNotADemo(): void
    {
        $shipped   = $this->makeBlock('examples/hero', ['template.php' => '<?php echo 1;', 'block.json' => '{"name":"proto-blocks/hero"}']);
        $installed = $this->makeBlock('theme/hero', ['hero.php' => '<?php // custom', 'block.json' => '{"name":"my-theme/hero"}']);

        self::assertFalse(AdminPage::isDemoCopy($installed, $shipped));
    }

    public function testExtraFileAddedByDeveloperIsNotADemo(): void
    {
        $files = ['template.php' => '<?php echo 1;'];
        $shipped   = $this->makeBlock('examples/hero', $files);
        $installed = $this->makeBlock('theme/hero', $files + ['view.js' => 'console.log(1)']);

        self::assertFalse(AdminPage::isDemoCopy($installed, $shipped));
    }

    public function testMarkerManifestWinsOverChangedShippedFiles(): void
    {
        // Installed from an older plugin version: the shipped example has since
        // changed, but the on-disk copy still matches what was installed.
        $installedFiles = ['template.php' => '<?php echo "v1";'];
        $installed = $this->makeBlock('theme/hero', $installedFiles);
        file_put_contents(
            $installed . '/' . AdminPage::DEMO_MARKER,
            json_encode(['files' => AdminPage::directoryManifest($installed)])
        );
        $shipped = $this->makeBlock('examples/hero', ['template.php' => '<?php echo "v2";']);

        self::assertTrue(AdminPage::isDemoCopy($installed, $shipped));
    }

    public function testMarkerDoesNotLaunderAnEditedCopy(): void
    {
        $installed = $this->makeBlock('theme/hero', ['template.php' => '<?php echo "v1";']);
        file_put_contents(
            $installed . '/' . AdminPage::DEMO_MARKER,
            json_encode(['files' => AdminPage::directoryManifest($installed)])
        );
        // Developer edits after install.
        file_put_contents($installed . '/template.php', '<?php echo "edited";');
        $shipped = $this->makeBlock('examples/hero', ['template.php' => '<?php echo "v1";']);

        self::assertFalse(AdminPage::isDemoCopy($installed, $shipped));
    }

    public function testCorruptMarkerFallsBackToShippedComparison(): void
    {
        $files = ['template.php' => '<?php echo 1;'];
        $shipped   = $this->makeBlock('examples/hero', $files);
        $installed = $this->makeBlock('theme/hero', $files + [AdminPage::DEMO_MARKER => 'not json']);

        self::assertTrue(AdminPage::isDemoCopy($installed, $shipped));
    }

    public function testMissingOrEmptyDirectoriesAreNotDemos(): void
    {
        $shipped = $this->makeBlock('examples/hero', ['template.php' => '']);
        $empty   = $this->root . '/theme/empty';
        mkdir($empty, 0777, true);

        self::assertFalse(AdminPage::isDemoCopy($this->root . '/theme/absent', $shipped));
        self::assertFalse(AdminPage::isDemoCopy($empty, $shipped));
        self::assertFalse(AdminPage::isDemoCopy($shipped, $this->root . '/examples/absent'));
    }

    public function testFileModsAllowedReflectsConstant(): void
    {
        if (defined('DISALLOW_FILE_MODS')) {
            self::assertSame(!\DISALLOW_FILE_MODS, AdminPage::fileModsAllowed());
            return;
        }
        self::assertTrue(AdminPage::fileModsAllowed());
    }

    /** @param array<string, string> $files relative path => contents */
    private function makeBlock(string $relative, array $files): string
    {
        $dir = $this->root . '/' . $relative;
        foreach ($files as $path => $contents) {
            $full = $dir . '/' . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, $contents);
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
