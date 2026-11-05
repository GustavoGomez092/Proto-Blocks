<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use ProtoBlocks\Modules\Redirects\Storage\NotFoundLog;

final class NotFoundLogTest extends StorageTestCase
{
    /** @param array<string, mixed> $log */
    private static function log(array $log = []): NotFoundLog
    {
        return new NotFoundLog(['log' => $log]);
    }

    /** @return array<string, mixed> */
    private static function request(array $extra = []): array
    {
        return $extra + [
            'url' => '/wp-config.php.bak',
            'method' => 'GET',
            'ip' => '203.0.113.77',
            'agent' => 'scanner',
            'referrer' => '',
            'headers' => [],
        ];
    }

    public function testFirstHitInAMinuteInserts(): void
    {
        self::log(['ip' => 'full'])->log404(self::request());

        self::assertSame(
            ["SELECT id FROM wp_proto_404_log WHERE url = '/wp-config.php.bak' AND ip = '203.0.113.77'"
            . " AND created >= '2026-11-05 12:34:00' AND created < '2026-11-05 12:35:00' ORDER BY id DESC LIMIT 1"],
            $this->sql()
        );
        $inserts = $this->callsOf('insert');
        self::assertCount(1, $inserts);
        self::assertSame('wp_proto_404_log', $inserts[0][1]);
        self::assertSame([
            'url' => '/wp-config.php.bak',
            'ip' => '203.0.113.77',
            'created' => '2026-11-05 12:34:56',
            'method' => 'GET',
            'agent' => 'scanner',
            'referrer' => null,
            'request' => null,
        ], $inserts[0][2]);
        self::assertSame([], $this->callsOf('update'));
    }

    public function testSameUrlAndIpWithinTheMinuteUpdatesInstead(): void
    {
        $this->db->queue[] = '17';
        $this->db->queue[] = 1;
        self::log(['ip' => 'full'])->log404(self::request(['agent' => 'scanner/2']));

        self::assertSame([], $this->callsOf('insert'));
        $updates = $this->callsOf('update');
        self::assertCount(1, $updates);
        self::assertSame('wp_proto_404_log', $updates[0][1]);
        self::assertSame(['id' => 17], $updates[0][3]);
        self::assertSame('2026-11-05 12:34:56', $updates[0][2]['created']);
        self::assertSame('scanner/2', $updates[0][2]['agent']);
        self::assertArrayNotHasKey('url', $updates[0][2]);
        self::assertArrayNotHasKey('ip', $updates[0][2]);
    }

    public function testTheNextMinuteLooksInItsOwnWindow(): void
    {
        $GLOBALS['pb_test_now'] = self::NOW + 4; // 12:35:00
        self::log(['ip' => 'full'])->log404(self::request());
        self::assertStringContainsString("created >= '2026-11-05 12:35:00' AND created < '2026-11-05 12:36:00'", $this->sql()[0]);
        self::assertCount(1, $this->callsOf('insert'));
    }

    public function testMinuteBoundaryIsInclusiveExclusive(): void
    {
        $GLOBALS['pb_test_now'] = self::NOW + 3; // 12:34:59, still the 12:34 window
        self::log(['ip' => 'full'])->log404(self::request());
        self::assertStringContainsString("created >= '2026-11-05 12:34:00' AND created < '2026-11-05 12:35:00'", $this->sql()[0]);
    }

    public function testIpNoneDeduplicatesOnNullIp(): void
    {
        self::log()->log404(self::request());
        self::assertSame(
            "SELECT id FROM wp_proto_404_log WHERE url = '/wp-config.php.bak' AND ip IS NULL"
            . " AND created >= '2026-11-05 12:34:00' AND created < '2026-11-05 12:35:00' ORDER BY id DESC LIMIT 1",
            $this->sql()[0]
        );
        self::assertNull($this->callsOf('insert')[0][2]['ip']);
    }

    public function testMaskedIpIsWhatDeduplicates(): void
    {
        self::log(['ip' => 'masked'])->log404(self::request());
        self::assertStringContainsString("AND ip = '203.0.113.0' AND", $this->sql()[0]);
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function policies(): iterable
    {
        yield 'default' => [null, true];
        yield '-1' => [-1, false];
        yield '0' => [0, true];
        yield '90' => [90, true];
    }

    #[DataProvider('policies')]
    public function testLogPolicy(mixed $days, bool $writes): void
    {
        $log = $days === null ? self::log() : self::log(['notFound' => $days]);
        $log->log404(self::request());
        self::assertCount($writes ? 1 : 0, $this->callsOf('insert'));
        if (!$writes) {
            self::assertSame([], $this->db->calls);
        }
    }

    public function testTheLog404FilterIsLeftToTheFrontController(): void
    {
        // FrontController applies it once with the engine's Request; applying it
        // here again would hand typed callbacks a snapshot array.
        $calls = 0;
        $GLOBALS['pb_test_filters']['proto_blocks_redirects_log_404'][] = static function ($log) use (&$calls) {
            $calls++;
            return false;
        };
        self::log()->log404(self::request());
        self::assertSame(0, $calls);
        self::assertCount(1, $this->callsOf('insert'));
    }

    public function testTruncationAndHeaders(): void
    {
        self::log(['headers' => true])->log404(self::request([
            'url' => '/' . str_repeat('x', 2500),
            'agent' => str_repeat('a', 400),
            'referrer' => 'https://ref.test/' . str_repeat('r', 400),
            'method' => 'propfind',
            'headers' => ['Cookie' => 'secret', 'Host' => 'secret', 'accept' => 'text/html'],
        ]));
        $row = $this->callsOf('insert')[0][2];
        self::assertSame(2000, mb_strlen($row['url']));
        self::assertSame(255, mb_strlen($row['agent']));
        self::assertSame(255, mb_strlen($row['referrer']));
        self::assertSame('OTHER', $row['method']);
        self::assertSame('{"headers":{"accept":"text/html"}}', $row['request']);
        // The de-dup lookup uses the same cut URL the row stores.
        self::assertStringContainsString("url = '/" . str_repeat('x', 1999) . "'", $this->sql()[0]);
    }

    public function testQueryFiltersUrlAndReferrer(): void
    {
        self::log()->query(['search' => 'php', 'code' => 404, 'group' => 'g']);
        self::assertSame(
            "SELECT COUNT(*) FROM wp_proto_404_log WHERE (url LIKE '%php%' OR referrer LIKE '%php%')",
            $this->sql()[0]
        );
    }

    public function testQueryRowShape(): void
    {
        $this->db->queue[] = 1;
        $this->db->queue[] = [['id' => '3', 'created' => '2026-11-05 12:00:00', 'url' => '/x', 'method' => 'GET', 'ip' => null, 'agent' => null, 'referrer' => 'https://r.test/', 'request' => 'not json']];
        self::assertSame(['rows' => [[
            'id' => 3, 'created' => '2026-11-05 12:00:00', 'url' => '/x', 'method' => 'GET',
            'ip' => null, 'agent' => null, 'referrer' => 'https://r.test/', 'request' => null,
        ]], 'total' => 1], self::log()->query([]));
    }

    public function testGroupedByUrl(): void
    {
        $this->db->queue[] = '2';
        $this->db->queue[] = [
            ['url' => '/a', 'count' => '7', 'last_seen' => '2026-11-05 12:00:00', 'last_referrer' => 'https://r.test/'],
            ['url' => '/b', 'count' => '1', 'last_seen' => '2026-11-04 12:00:00', 'last_referrer' => null],
        ];
        $result = self::log()->grouped(['search' => 'a', 'from' => '2026-11-01'], 2, 10);

        self::assertSame(['rows' => [
            ['url' => '/a', 'count' => 7, 'lastSeen' => '2026-11-05 12:00:00', 'lastReferrer' => 'https://r.test/'],
            ['url' => '/b', 'count' => 1, 'lastSeen' => '2026-11-04 12:00:00', 'lastReferrer' => null],
        ], 'total' => 2], $result);

        $where = " WHERE (l.url LIKE '%a%' OR l.referrer LIKE '%a%') AND l.created >= '2026-11-01 00:00:00'";
        self::assertSame([
            'SELECT COUNT(DISTINCT l.url) FROM wp_proto_404_log l' . $where,
            'SELECT l.url, COUNT(*) AS count, MAX(l.created) AS last_seen,'
            . ' (SELECT r.referrer FROM wp_proto_404_log r WHERE r.url = l.url ORDER BY r.id DESC LIMIT 1) AS last_referrer'
            . ' FROM wp_proto_404_log l' . $where . ' GROUP BY l.url ORDER BY count DESC, last_seen DESC LIMIT 10 OFFSET 10',
        ], $this->sql());
    }

    public function testDeleteByUrlIsPrepared(): void
    {
        $this->db->queue[] = 4;
        self::assertSame(4, self::log()->deleteByUrl("/a' OR '1'='1"));
        self::assertSame(["DELETE FROM wp_proto_404_log WHERE url = '/a\\' OR \\'1\\'=\\'1'"], $this->sql());
    }

    public function testClearFilteredByUrl(): void
    {
        self::log()->clear(['url' => '/a']);
        self::assertSame(["DELETE FROM wp_proto_404_log WHERE url = '/a'"], $this->sql());
    }

    public function testDeleteIds(): void
    {
        self::log()->delete([1, 2]);
        self::assertSame(['DELETE FROM wp_proto_404_log WHERE id IN (1, 2)'], $this->sql());
    }

    public function testMultisitePrefix(): void
    {
        $this->db->prefix = 'wp_5_';
        self::log()->log404(self::request());
        self::assertStringContainsString('FROM wp_5_proto_404_log ', $this->sql()[0]);
        self::assertSame('wp_5_proto_404_log', $this->callsOf('insert')[0][1]);
    }
}
