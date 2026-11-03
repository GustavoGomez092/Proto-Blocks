<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Tailwind;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Tailwind\AdminSettings;

/**
 * The React Tailwind screen talks to REST routes; only the two AJAX actions
 * the browser compiler (src/tailwind/compiler.ts) posts to remain.
 */
final class AdminSettingsActionsTest extends TestCase
{
    public function testOnlyTheBrowserCompilerActionsAreKept(): void
    {
        self::assertSame(['get_compile_inputs', 'store_css'], AdminSettings::KEPT_AJAX_ACTIONS);
    }

    public function testPerControlHandlersAndRenderAreGone(): void
    {
        self::assertFalse(method_exists(AdminSettings::class, 'handleToggle'));

        $removed = [
            'handleSetMode',
            'handleSetEngine',
            'handleToggleGlobalStyles',
            'handleCompile',
            'handleClearCache',
            'handleDownloadCli',
            'handleCreateThemeFile',
            'handleGetStatus',
            'render',
        ];
        foreach ($removed as $method) {
            self::assertFalse(method_exists(AdminSettings::class, $method), $method . ' is removed');
        }
        self::assertTrue(method_exists(AdminSettings::class, 'handleGetCompileInputs'));
        self::assertTrue(method_exists(AdminSettings::class, 'handleStoreCss'));
    }
}
