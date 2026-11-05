<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Storage;

use ProtoBlocks\Modules\Redirects\Storage\Retention;

final class RetentionTest extends StorageTestCase
{
    /** @param array<string, mixed> $log */
    private static function retention(array $log): Retention
    {
        return new Retention(['log' => $log]);
    }

    public function testScheduleAddsOneDailyEvent(): void
    {
        $retention = self::retention([]);
        $retention->schedule();
        $retention->schedule();
        (new Retention([]))->schedule();

        self::assertCount(1, $GLOBALS['pb_test_cron'][Retention::HOOK]);
        $event = $GLOBALS['pb_test_cron'][Retention::HOOK][0];
        self::assertSame('daily', $event['recurrence']);
        self::assertSame([], $event['args']);
        self::assertSame(self::NOW + 3600, $event['timestamp']);
    }

    public function testAPendingFollowUpDoesNotCountAsTheDailyEvent(): void
    {
        $GLOBALS['pb_test_cron'][Retention::HOOK][] = ['timestamp' => self::NOW + 600, 'recurrence' => null, 'args' => ['continue']];
        self::retention([])->schedule();
        self::assertCount(2, $GLOBALS['pb_test_cron'][Retention::HOOK]);
    }

    public function testUnscheduleClearsTheHook(): void
    {
        $retention = self::retention([]);
        $retention->schedule();
        $retention->unschedule();
        self::assertSame([], $GLOBALS['pb_test_cron'][Retention::HOOK] ?? []);
    }

    public function testRegisterHooksRun(): void
    {
        $retention = self::retention([]);
        $retention->register();
        self::assertSame([$retention, 'run'], $GLOBALS['pb_test_hooks'][Retention::HOOK][0]['callback']);
    }

    public function testRunDeletesOlderRowsInBatchesUsingUtcCutoffs(): void
    {
        $this->db->queue[] = 12;
        $this->db->queue[] = 3;
        $deleted = self::retention(['redirects' => 7, 'notFound' => 30])->run();

        self::assertSame(['redirects' => 12, 'notFound' => 3], $deleted);
        self::assertSame([
            "DELETE FROM wp_proto_redirect_log WHERE created < '2026-10-29 12:34:56' ORDER BY id ASC LIMIT 10000",
            "DELETE FROM wp_proto_404_log WHERE created < '2026-10-06 12:34:56' ORDER BY id ASC LIMIT 10000",
        ], $this->sql());
        self::assertArrayNotHasKey(Retention::HOOK, $GLOBALS['pb_test_cron']);
    }

    public function testDefaultsAreSevenDays(): void
    {
        self::retention([])->run();
        self::assertStringContainsString("created < '2026-10-29 12:34:56'", $this->sql()[0]);
        self::assertStringContainsString("created < '2026-10-29 12:34:56'", $this->sql()[1]);
    }

    public function testZeroAndMinusOneNeverDelete(): void
    {
        self::assertSame(['redirects' => 0, 'notFound' => 0], self::retention(['redirects' => 0, 'notFound' => -1])->run());
        self::assertSame([], $this->db->calls);
    }

    public function testDaysAreClampedToNinety(): void
    {
        self::retention(['redirects' => 365, 'notFound' => 0])->run();
        self::assertSame(["DELETE FROM wp_proto_redirect_log WHERE created < '2026-08-07 12:34:56' ORDER BY id ASC LIMIT 10000"], $this->sql());
    }

    public function testAFullBatchSchedulesOneFollowUpInTenMinutes(): void
    {
        $this->db->queue[] = 10000;
        $this->db->queue[] = 5;
        self::retention(['redirects' => 7, 'notFound' => 7])->run();

        self::assertSame(
            [['timestamp' => self::NOW + 600, 'recurrence' => null, 'args' => ['continue']]],
            array_values($GLOBALS['pb_test_cron'][Retention::HOOK])
        );
    }

    public function testFullBatchOnTheNotFoundLogAlsoReschedules(): void
    {
        $this->db->queue[] = 0;
        $this->db->queue[] = 10000;
        self::retention(['redirects' => 7, 'notFound' => 7])->run();
        self::assertCount(1, $GLOBALS['pb_test_cron'][Retention::HOOK]);
    }

    public function testFollowUpIsNotQueuedTwice(): void
    {
        $retention = self::retention(['redirects' => 7, 'notFound' => 7]);
        $this->db->queue = [10000, 10000, 10000, 10000];
        $retention->run();
        $retention->run();
        self::assertCount(1, $GLOBALS['pb_test_cron'][Retention::HOOK]);
    }

    public function testAPartialBatchDoesNotReschedule(): void
    {
        $this->db->queue = [9999, 9999];
        self::retention(['redirects' => 7, 'notFound' => 7])->run();
        self::assertArrayNotHasKey(Retention::HOOK, $GLOBALS['pb_test_cron']);
    }

    public function testMultisitePrefix(): void
    {
        $this->db->prefix = 'wp_4_';
        self::retention(['redirects' => 1, 'notFound' => 1])->run();
        self::assertStringStartsWith('DELETE FROM wp_4_proto_redirect_log ', $this->sql()[0]);
        self::assertStringStartsWith('DELETE FROM wp_4_proto_404_log ', $this->sql()[1]);
    }
}
