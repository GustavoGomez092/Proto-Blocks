<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Storage;

use ProtoBlocks\Modules\Redirects\Storage\Hits;

final class HitsTest extends StorageTestCase
{
    public function testRecordIsOnePreparedUpsert(): void
    {
        (new Hits())->record('legacy-site', 'r_8f3a2c1d');

        $prepare = $this->callsOf('prepare');
        self::assertCount(1, $prepare);
        self::assertSame(
            "INSERT INTO wp_proto_redirect_hits (group_slug, rule_id, hits, last_hit) VALUES ('%s', '%s', 1, '%s')"
            . ' ON DUPLICATE KEY UPDATE hits = hits + 1, last_hit = VALUES(last_hit)',
            $prepare[0][1]
        );
        self::assertSame(['legacy-site', 'r_8f3a2c1d', '2026-11-05 12:34:56'], $prepare[0][2]);
        self::assertCount(1, $this->callsOf('query'));
    }

    public function testRecordUsesTheCurrentPrefix(): void
    {
        $this->db->prefix = 'wp_2_';
        (new Hits())->record('php', 'r_00000000');
        self::assertStringStartsWith('INSERT INTO wp_2_proto_redirect_hits ', $this->sql()[0]);
    }

    public function testTrackHitsFalseRecordsNothing(): void
    {
        (new Hits(['trackHits' => false]))->record('legacy-site', 'r_8f3a2c1d');
        self::assertSame([], $this->db->calls);
    }

    public function testInvalidKeysAreNeverWritten(): void
    {
        $hits = new Hits();
        $hits->record('Bad Group', 'r_8f3a2c1d');
        $hits->record('legacy', 'r_XYZ');
        $hits->record('legacy', "r_8f3a2c1d\n");
        $hits->record(str_repeat('a', 61), 'r_8f3a2c1d');
        self::assertSame([], $this->db->calls);
    }

    public function testAllShapesRowsByGroupAndRule(): void
    {
        $this->db->queue[] = [
            ['group_slug' => 'a', 'rule_id' => 'r_00000001', 'hits' => '5', 'last_hit' => '2026-11-01 10:00:00'],
            ['group_slug' => 'a', 'rule_id' => 'r_00000002', 'hits' => '0', 'last_hit' => null],
            ['group_slug' => 'b', 'rule_id' => 'r_00000003', 'hits' => '12', 'last_hit' => '2026-11-02 10:00:00'],
        ];
        self::assertSame([
            'a' => [
                'r_00000001' => ['hits' => 5, 'lastHit' => '2026-11-01 10:00:00'],
                'r_00000002' => ['hits' => 0, 'lastHit' => null],
            ],
            'b' => ['r_00000003' => ['hits' => 12, 'lastHit' => '2026-11-02 10:00:00']],
        ], (new Hits())->all());
    }

    public function testAllOnEmptyTable(): void
    {
        self::assertSame([], (new Hits())->all());
    }

    public function testResetZeroesTheRow(): void
    {
        (new Hits())->reset('a', 'r_00000001');
        self::assertSame(["UPDATE wp_proto_redirect_hits SET hits = 0, last_hit = NULL WHERE group_slug = 'a' AND rule_id = 'r_00000001'"], $this->sql());
    }

    public function testDeleteRemovesTheRow(): void
    {
        (new Hits())->delete('a', "r_0'1");
        self::assertSame(["DELETE FROM wp_proto_redirect_hits WHERE group_slug = 'a' AND rule_id = 'r_0\\'1'"], $this->sql());
    }

    public function testPruneDeletesOnlyUnknownRows(): void
    {
        $this->db->queue[] = [
            ['group_slug' => 'a', 'rule_id' => 'r_00000001'],
            ['group_slug' => 'a', 'rule_id' => 'r_00000002'],
            ['group_slug' => 'b', 'rule_id' => 'r_00000001'],
        ];
        $this->db->queue[] = 2;
        $deleted = (new Hits())->prune(['a/r_00000001']);

        self::assertSame(2, $deleted);
        $sql = $this->sql();
        self::assertSame('SELECT group_slug, rule_id FROM wp_proto_redirect_hits', $sql[0]);
        self::assertSame(
            "DELETE FROM wp_proto_redirect_hits WHERE (group_slug, rule_id) IN (('a', 'r_00000002'), ('b', 'r_00000001'))",
            $sql[1]
        );
    }

    public function testPruneWithNothingOrphanedRunsNoDelete(): void
    {
        $this->db->queue[] = [['group_slug' => 'a', 'rule_id' => 'r_00000001']];
        self::assertSame(0, (new Hits())->prune(['a/r_00000001']));
        self::assertCount(1, $this->sql());
    }

    public function testPruneBatchesLargeOrphanSets(): void
    {
        $rows = [];
        for ($i = 0; $i < 1200; $i++) {
            $rows[] = ['group_slug' => 'g', 'rule_id' => sprintf('r_%08x', $i)];
        }
        $this->db->queue[] = $rows;
        $this->db->queue[] = 500;
        $this->db->queue[] = 500;
        $this->db->queue[] = 200;

        self::assertSame(1200, (new Hits())->prune([]));
        self::assertCount(4, $this->sql());
    }

    public function testOrphansCountsWithoutDeleting(): void
    {
        $this->db->queue[] = [
            ['group_slug' => 'a', 'rule_id' => 'r_00000001'],
            ['group_slug' => 'a', 'rule_id' => 'r_00000002'],
        ];
        self::assertSame(1, (new Hits())->orphans(['a/r_00000002']));
        self::assertCount(1, $this->sql());
    }
}
