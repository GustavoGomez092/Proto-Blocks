<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Modules\Redirects\RedirectsModule;
use ProtoBlocks\Modules\Redirects\Storage\Retention;

/** Deactivation clears the retention cron on one site, or on every site network-wide. */
final class DeactivationTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['pb_test_cron'] = [];
        $GLOBALS['pb_test_switched'] = [];
        $GLOBALS['pb_test_sites'] = [1, 2, 3];
        $GLOBALS['pb_test_current_site'] = 1;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pb_test_cron'], $GLOBALS['pb_test_switched'], $GLOBALS['pb_test_sites'], $GLOBALS['pb_test_current_site']);
    }

    public function testSingleSiteDeactivationUnschedulesWithoutSwitching(): void
    {
        wp_schedule_event(time(), 'daily', Retention::HOOK);
        RedirectsModule::deactivate();

        self::assertFalse(wp_next_scheduled(Retention::HOOK));
        self::assertSame([], $GLOBALS['pb_test_switched']);
    }

    public function testNetworkWideDeactivationVisitsEverySite(): void
    {
        wp_schedule_event(time(), 'daily', Retention::HOOK);
        RedirectsModule::deactivate(true);

        self::assertSame([1, 2, 3], $GLOBALS['pb_test_switched']);
        self::assertFalse(wp_next_scheduled(Retention::HOOK));
        self::assertSame(1, get_current_blog_id(), 'restore_current_blog() ran after the last site');
    }
}
