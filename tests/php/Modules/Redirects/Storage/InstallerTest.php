<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Storage;

use ProtoBlocks\Modules\Redirects\Storage\Installer;
use ProtoBlocks\Modules\Redirects\Storage\Schema;

final class InstallerTest extends StorageTestCase
{
    private const ALL = ['wp_proto_redirect_hits', 'wp_proto_redirect_log', 'wp_proto_404_log'];

    public function testSchemaHasThreeTablesWithThePrefixAndCollation(): void
    {
        $tables = Schema::tables('wp_');
        self::assertSame(self::ALL, array_keys($tables));
        foreach ($tables as $name => $sql) {
            self::assertStringStartsWith("CREATE TABLE {$name} (\n", $sql);
            self::assertStringEndsWith(') DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;', $sql);
            self::assertStringContainsString('PRIMARY KEY  (', $sql, 'dbDelta needs two spaces after PRIMARY KEY');
            self::assertStringNotContainsString('INDEX', $sql);
            self::assertStringNotContainsString(' group ', $sql, 'GROUP is reserved; the column is group_slug');
        }
    }

    public function testSchemaColumnsMatchTheSpec(): void
    {
        $tables = Schema::tables('wp_', '');
        self::assertStringContainsString('PRIMARY KEY  (group_slug,rule_id)', $tables['wp_proto_redirect_hits']);
        self::assertStringContainsString('rule_id char(10) NOT NULL', $tables['wp_proto_redirect_hits']);
        self::assertStringContainsString('last_hit datetime NULL', $tables['wp_proto_redirect_hits']);
        foreach (['wp_proto_redirect_log', 'wp_proto_404_log'] as $t) {
            self::assertStringContainsString('url varchar(2000) NOT NULL', $tables[$t]);
            self::assertStringContainsString('ip varchar(45) NULL', $tables[$t]);
            self::assertStringContainsString('agent varchar(255) NULL', $tables[$t]);
            self::assertStringContainsString('referrer varchar(255) NULL', $tables[$t]);
            self::assertStringContainsString('method varchar(10)', $tables[$t]);
            self::assertStringContainsString('KEY created (created)', $tables[$t]);
            self::assertStringContainsString('KEY url (url(191))', $tables[$t]);
            self::assertStringEndsWith(');', $tables[$t]);
        }
        self::assertStringContainsString('KEY rule (group_slug,rule_id)', $tables['wp_proto_redirect_log']);
        self::assertStringContainsString('redirect_by varchar(50)', $tables['wp_proto_redirect_log']);
        self::assertStringContainsString('target varchar(2000) NULL', $tables['wp_proto_redirect_log']);
    }

    public function testEnsureRunsDbDeltaAndRecordsTheVersion(): void
    {
        $this->db->queue[] = self::ALL;
        (new Installer())->ensure();

        self::assertCount(3, $GLOBALS['pb_test_dbdelta']);
        self::assertStringContainsString('CREATE TABLE wp_proto_redirect_hits', $GLOBALS['pb_test_dbdelta'][0]);
        self::assertSame('1', $GLOBALS['pb_test_options'][Installer::OPTION]);
        self::assertSame("SHOW TABLES LIKE 'wp\\\\_proto\\\\_%'", $this->sql()[0]);
    }

    public function testEnsureIsIdempotentOnceTheOptionIsSet(): void
    {
        $GLOBALS['pb_test_options'][Installer::OPTION] = '1';
        (new Installer())->ensure();
        (new Installer())->ensure();

        self::assertSame([], $GLOBALS['pb_test_dbdelta']);
        self::assertSame([], $this->db->calls);
    }

    public function testEnsureRunsAgainForAnOlderOrStrayVersion(): void
    {
        $GLOBALS['pb_test_options'][Installer::OPTION] = '0';
        $this->db->queue[] = self::ALL;
        (new Installer())->ensure();
        self::assertCount(3, $GLOBALS['pb_test_dbdelta']);
        self::assertSame('1', $GLOBALS['pb_test_options'][Installer::OPTION]);

        // An int 1 (not the string the installer writes) is not the version either.
        $GLOBALS['pb_test_options'][Installer::OPTION] = 1;
        $GLOBALS['pb_test_dbdelta'] = [];
        $this->db->queue[] = self::ALL;
        (new Installer())->ensure();
        self::assertCount(3, $GLOBALS['pb_test_dbdelta']);
    }

    public function testVersionIsNotRecordedWhenATableIsMissing(): void
    {
        $this->db->queue[] = ['wp_proto_redirect_hits', 'wp_proto_redirect_log'];
        $installer = new Installer();
        $installer->ensure();

        self::assertArrayNotHasKey(Installer::OPTION, $GLOBALS['pb_test_options']);
        // So a later request tries again, once the back-off has passed.
        unset($GLOBALS['pb_test_transients'][Installer::RETRY_TRANSIENT]);
        $this->db->queue[] = self::ALL;
        $installer->ensure();
        self::assertCount(6, $GLOBALS['pb_test_dbdelta']);
        self::assertSame('1', $GLOBALS['pb_test_options'][Installer::OPTION]);
    }

    public function testAFailedCreateBacksOffInsteadOfRetryingEveryRequest(): void
    {
        $GLOBALS['pb_test_transients'] = [];
        $this->db->queue[] = []; // no CREATE grant: nothing was created
        $installer = new Installer();
        $installer->ensure();

        self::assertSame(Installer::RETRY_SECONDS, $GLOBALS['pb_test_transients'][Installer::RETRY_TRANSIENT]['expiration']);
        $calls = count($this->db->calls);
        $installer->ensure();
        $installer->ensure();
        self::assertCount(3, $GLOBALS['pb_test_dbdelta'], 'no dbDelta while backing off');
        self::assertCount($calls, $this->db->calls, 'no SHOW TABLES either');

        // An explicit install (Settings "Create tables", CLI db install) ignores the back-off and clears it.
        $this->db->queue[] = self::ALL;
        self::assertTrue($installer->install());
        self::assertArrayNotHasKey(Installer::RETRY_TRANSIENT, $GLOBALS['pb_test_transients']);
        unset($GLOBALS['pb_test_transients']);
    }

    public function testMultisiteUsesTheCurrentSitePrefix(): void
    {
        $this->db->prefix = 'wp_3_';
        $this->db->queue[] = ['wp_3_proto_redirect_hits', 'wp_3_proto_redirect_log', 'wp_3_proto_404_log'];
        (new Installer())->ensure();

        self::assertStringContainsString('CREATE TABLE wp_3_proto_redirect_hits', $GLOBALS['pb_test_dbdelta'][0]);
        self::assertStringContainsString('CREATE TABLE wp_3_proto_redirect_log', $GLOBALS['pb_test_dbdelta'][1]);
        self::assertStringContainsString('CREATE TABLE wp_3_proto_404_log', $GLOBALS['pb_test_dbdelta'][2]);
        self::assertSame('1', $GLOBALS['pb_test_options'][Installer::OPTION]);
    }

    public function testPrefixIsReadPerCallNotAtConstruction(): void
    {
        $installer = new Installer();
        $this->db->prefix = 'wp_7_';
        $this->db->queue[] = ['wp_7_proto_redirect_hits', 'wp_7_proto_redirect_log', 'wp_7_proto_404_log'];
        self::assertTrue($installer->status()['tables']);
    }

    public function testStatus(): void
    {
        $this->db->queue[] = [];
        self::assertSame(['tables' => false, 'version' => null], (new Installer())->status());

        $GLOBALS['pb_test_options'][Installer::OPTION] = '1';
        $this->db->queue[] = self::ALL;
        self::assertSame(['tables' => true, 'version' => '1'], (new Installer())->status());
    }

    public function testDropRemovesEveryTableAndTheOption(): void
    {
        $GLOBALS['pb_test_options'][Installer::OPTION] = '1';
        (new Installer())->drop();

        self::assertSame([
            'DROP TABLE IF EXISTS wp_proto_redirect_hits',
            'DROP TABLE IF EXISTS wp_proto_redirect_log',
            'DROP TABLE IF EXISTS wp_proto_404_log',
        ], $this->sql());
        self::assertArrayNotHasKey(Installer::OPTION, $GLOBALS['pb_test_options']);
    }
}
