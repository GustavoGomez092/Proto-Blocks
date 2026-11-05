<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Storage;

/**
 * The three runtime tables (spec §5), as dbDelta statements: one column per
 * line, two spaces after PRIMARY KEY, KEY not INDEX. Configuration is never
 * stored here; dropping these tables loses counts and logs, never a redirect.
 *
 * The group column is `group_slug` because GROUP is a reserved word.
 */
final class Schema
{
    public const HITS = 'proto_redirect_hits';
    public const LOG = 'proto_redirect_log';
    public const NOT_FOUND = 'proto_404_log';

    /** Schema version stored in the `proto_blocks_redirects_db` option. */
    public const VERSION = '1';

    public static function hitsTable(string $prefix): string
    {
        return $prefix . self::HITS;
    }

    public static function logTable(string $prefix): string
    {
        return $prefix . self::LOG;
    }

    public static function notFoundTable(string $prefix): string
    {
        return $prefix . self::NOT_FOUND;
    }

    /** @return list<string> */
    public static function tableNames(string $prefix): array
    {
        return [self::hitsTable($prefix), self::logTable($prefix), self::notFoundTable($prefix)];
    }

    /**
     * @param string|null $collate `$wpdb->get_charset_collate()` when null
     * @return array<string, string> table name => CREATE TABLE statement
     */
    public static function tables(string $prefix, ?string $collate = null): array
    {
        if ($collate === null) {
            global $wpdb;
            $collate = is_object($wpdb) && method_exists($wpdb, 'get_charset_collate') ? (string) $wpdb->get_charset_collate() : '';
        }
        $collate = $collate === '' ? '' : ' ' . $collate;

        $hits = self::hitsTable($prefix);
        $log = self::logTable($prefix);
        $notFound = self::notFoundTable($prefix);

        return [
            $hits => "CREATE TABLE {$hits} (\n"
                . "  group_slug varchar(60) NOT NULL,\n"
                . "  rule_id char(10) NOT NULL,\n"
                . "  hits bigint(20) unsigned NOT NULL DEFAULT 0,\n"
                . "  last_hit datetime NULL DEFAULT NULL,\n"
                . "  PRIMARY KEY  (group_slug,rule_id)\n"
                . "){$collate};",
            $log => "CREATE TABLE {$log} (\n"
                . "  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n"
                . "  created datetime NOT NULL,\n"
                . "  url varchar(2000) NOT NULL,\n"
                . "  target varchar(2000) NULL DEFAULT NULL,\n"
                . "  code smallint(5) unsigned NOT NULL DEFAULT 0,\n"
                . "  group_slug varchar(60) NULL DEFAULT NULL,\n"
                . "  rule_id char(10) NULL DEFAULT NULL,\n"
                . "  redirect_by varchar(50) NOT NULL DEFAULT '',\n"
                . "  method varchar(10) NOT NULL DEFAULT '',\n"
                . "  ip varchar(45) NULL DEFAULT NULL,\n"
                . "  agent varchar(255) NULL DEFAULT NULL,\n"
                . "  referrer varchar(255) NULL DEFAULT NULL,\n"
                . "  request longtext NULL,\n"
                . "  PRIMARY KEY  (id),\n"
                . "  KEY created (created),\n"
                . "  KEY rule (group_slug,rule_id),\n"
                . "  KEY url (url(191))\n"
                . "){$collate};",
            $notFound => "CREATE TABLE {$notFound} (\n"
                . "  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n"
                . "  created datetime NOT NULL,\n"
                . "  url varchar(2000) NOT NULL,\n"
                . "  method varchar(10) NOT NULL DEFAULT '',\n"
                . "  ip varchar(45) NULL DEFAULT NULL,\n"
                . "  agent varchar(255) NULL DEFAULT NULL,\n"
                . "  referrer varchar(255) NULL DEFAULT NULL,\n"
                . "  request longtext NULL,\n"
                . "  PRIMARY KEY  (id),\n"
                . "  KEY created (created),\n"
                . "  KEY url (url(191))\n"
                . "){$collate};",
        ];
    }
}
