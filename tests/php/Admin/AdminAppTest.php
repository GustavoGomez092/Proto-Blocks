<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Admin;

require_once __DIR__ . '/wp-stubs.php';

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ProtoBlocks\Admin\AdminApp;

/**
 * The React admin is mounted by AdminApp: it prints the mount and portal
 * nodes and hands the app its bootstrap data.
 */
final class AdminAppTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pb_test_current_user'] = 1;
        $GLOBALS['pb_test_caps'] = [1 => ['manage_options']];
        $GLOBALS['pb_test_inline'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pb_test_current_user'], $GLOBALS['pb_test_caps'], $GLOBALS['pb_test_inline']);
    }

    public function testBootstrapContainsScreenAndRestFields(): void
    {
        $data = (new AdminApp(null))->bootstrapData('system');

        foreach (['screen', 'restRoot', 'restNonce', 'adminUrl', 'version', 'fileModsAllowed', 'writeReason', 'fileModsConstant', 'assetBase', 'tailwind'] as $key) {
            self::assertArrayHasKey($key, $data);
        }
        self::assertSame('system', $data['screen']);
        self::assertStringEndsWith('/proto-blocks/v1/', $data['restRoot']);
        self::assertSame('nonce:wp_rest', $data['restNonce']);
        self::assertIsBool($data['fileModsAllowed']);
    }

    public function testWriteReasonFollowsFileModsAndMultisite(): void
    {
        $app = new AdminApp(null);
        $data = $app->bootstrapData('system');
        self::assertSame($data['fileModsAllowed'] ? null : 'file_mods', $data['writeReason']);

        if ($data['fileModsAllowed']) {
            try {
                $GLOBALS['pb_test_multisite'] = true;
                $GLOBALS['pb_test_super_admins'] = [];
                self::assertSame('multisite', $app->bootstrapData('system')['writeReason']);
                self::assertTrue($app->bootstrapData('system')['fileModsAllowed']);
            } finally {
                unset($GLOBALS['pb_test_multisite'], $GLOBALS['pb_test_super_admins']);
            }
        }
    }

    public function testFileModsConstantMatchesFileModsAllowed(): void
    {
        $data = (new AdminApp(null))->bootstrapData('system');

        // Constants cannot be undefined within one process, so check the pairing.
        if ($data['fileModsAllowed']) {
            self::assertNull($data['fileModsConstant']);
        } else {
            self::assertContains($data['fileModsConstant'], ['DISALLOW_FILE_MODS', 'DISALLOW_FILE_EDIT']);
        }
    }

    public function testFileModsConstantNamesTheFirstTruthyConstant(): void
    {
        self::assertNull(AdminApp::fileModsConstant([]));
        self::assertNull(AdminApp::fileModsConstant(['DISALLOW_FILE_MODS' => false, 'DISALLOW_FILE_EDIT' => false]));
        self::assertSame('DISALLOW_FILE_EDIT', AdminApp::fileModsConstant(['DISALLOW_FILE_MODS' => false, 'DISALLOW_FILE_EDIT' => true]));
        self::assertSame('DISALLOW_FILE_MODS', AdminApp::fileModsConstant(['DISALLOW_FILE_MODS' => true, 'DISALLOW_FILE_EDIT' => true]));
    }

    public function testOnlyRegisteredScreenHooksLoadTheApp(): void
    {
        $app = new AdminApp(null);
        $app->addScreenHook('proto-blocks_page_proto-blocks-system', 'system');

        self::assertTrue($app->isScreenHook('proto-blocks_page_proto-blocks-system'));
        self::assertFalse($app->isScreenHook('toplevel_page_proto-blocks'));
        self::assertFalse($app->isScreenHook(''));
    }

    public function testAddScreenHookRejectsUnknownScreens(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AdminApp(null))->addScreenHook('some_hook', 'nope');
    }

    public function testUnknownScreenThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AdminApp(null))->bootstrapData('nope');
    }

    public function testTailwindNullWhenManagerAbsent(): void
    {
        self::assertNull((new AdminApp(null))->bootstrapData('dashboard')['tailwind']);
    }

    public function testRenderScreenEchoesMountAndPortal(): void
    {
        ob_start();
        (new AdminApp(null))->renderScreen('dashboard');
        $html = (string) ob_get_clean();

        self::assertStringContainsString('id="proto-blocks-admin"', $html);
        self::assertStringContainsString('data-screen="dashboard"', $html);
        self::assertStringContainsString('id="proto-blocks-admin-portal"', $html);
        self::assertStringContainsString('window.protoBlocksAdmin', $html);
    }

    public function testInlineScriptCannotCloseItsScriptTag(): void
    {
        ob_start();
        (new AdminApp(null))->renderScreen('system');
        $html = (string) ob_get_clean();

        // Only the fallback tag's own closing tag; JSON_HEX_TAG keeps payload "<" escaped.
        self::assertSame(1, substr_count($html, '</script>'));
        self::assertStringNotContainsString('<\/', $html);
    }

    public function testRenderScreenPrintsNothingWithoutManageOptions(): void
    {
        $GLOBALS['pb_test_caps'] = [1 => []];
        ob_start();
        (new AdminApp(null))->renderScreen('system');
        $html = (string) ob_get_clean();

        self::assertSame('', $html);
    }

    public function testRenderScreenUsesInlineScriptWhenHandleEnqueued(): void
    {
        $GLOBALS['pb_test_enqueued_scripts'] = ['proto-blocks-admin'];
        ob_start();
        (new AdminApp(null))->renderScreen('system');
        $html = (string) ob_get_clean();
        unset($GLOBALS['pb_test_enqueued_scripts']);

        self::assertStringNotContainsString('<script', $html);
        self::assertCount(1, $GLOBALS['pb_test_inline']);
        self::assertSame('proto-blocks-admin', $GLOBALS['pb_test_inline'][0]['handle']);
        self::assertSame('before', $GLOBALS['pb_test_inline'][0]['position']);
        self::assertStringStartsWith('window.protoBlocksAdmin = ', $GLOBALS['pb_test_inline'][0]['data']);
    }
}
