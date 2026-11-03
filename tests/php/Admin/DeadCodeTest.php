<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Admin;

require_once __DIR__ . '/wp-stubs.php';

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Admin\AdminPage;
use ProtoBlocks\Admin\PreviewCapture;
use ProtoBlocks\Admin\SetupWizard;
use ProtoBlocks\Tailwind\AdminSettings;

/**
 * Guards against the PHP-rendered admin coming back: every admin screen is
 * React now, so no PHP class may render page HTML.
 */
final class DeadCodeTest extends TestCase
{
    public function testPhpRenderedAdminMethodsAreGone(): void
    {
        self::assertFalse(method_exists(AdminPage::class, 'renderPage'));
        self::assertFalse(method_exists(AdminPage::class, 'renderSettingsPage'));
        self::assertFalse(method_exists(AdminPage::class, 'renderSystemStatusPage'));
        self::assertFalse(method_exists(SetupWizard::class, 'renderWizard'));
        self::assertFalse(method_exists(PreviewCapture::class, 'renderAdminUi'));
        self::assertFalse(method_exists(AdminSettings::class, 'render'));
    }

    public function testPluginHeaderRequiresPhp81(): void
    {
        $header = (string) file_get_contents(dirname(__DIR__, 3) . '/proto-blocks.php');
        self::assertStringContainsString('Requires PHP: 8.1', $header);
    }

    public function testLegacyAdminAssetsAreGone(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileDoesNotExist($root . '/assets/css/admin-legacy.css');
        self::assertStringNotContainsString('admin-legacy', (string) file_get_contents($root . '/includes/Admin/Assets.php'));
    }
}
