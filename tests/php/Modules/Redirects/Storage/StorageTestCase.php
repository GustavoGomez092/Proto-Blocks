<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Storage;

use PHPUnit\Framework\TestCase;

/** A fresh recording $wpdb, option store, cron table and clock per test. */
abstract class StorageTestCase extends TestCase
{
    protected \wpdb $db;

    /** 2026-11-05 12:34:56 UTC */
    protected const NOW = 1793882096;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new \wpdb();
        $GLOBALS['wpdb'] = $this->db;
        $GLOBALS['pb_test_options'] = [];
        $GLOBALS['pb_test_dbdelta'] = [];
        $GLOBALS['pb_test_cron'] = [];
        $GLOBALS['pb_test_filters'] = [];
        $GLOBALS['pb_test_hooks'] = [];
        $GLOBALS['pb_test_now'] = self::NOW;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = new \wpdb();
        unset($GLOBALS['pb_test_now'], $GLOBALS['pb_test_dbdelta']);
        $GLOBALS['pb_test_options'] = [];
        $GLOBALS['pb_test_cron'] = [];
        $GLOBALS['pb_test_filters'] = [];
        $GLOBALS['pb_test_hooks'] = [];
        parent::tearDown();
    }

    /** @return list<array<int, mixed>> calls of one method */
    protected function callsOf(string $method): array
    {
        return array_values(array_filter($this->db->calls, static fn(array $c): bool => $c[0] === $method));
    }

    /** @return list<string> final SQL of query/get_* calls, in order */
    protected function sql(): array
    {
        $out = [];
        foreach ($this->db->calls as $call) {
            if (in_array($call[0], ['query', 'get_results', 'get_var', 'get_row', 'get_col'], true)) {
                $out[] = (string) $call[1];
            }
        }

        return $out;
    }
}
