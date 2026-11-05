<?php

declare(strict_types=1);

namespace ProtoBlocks\Modules\Redirects\Storage;

/**
 * Creates, reports on and drops the runtime tables. `ensure()` runs dbDelta
 * only while the `proto_blocks_redirects_db` option is not the current
 * schema version, so it costs one autoloaded option read per request.
 * When the tables cannot be created (no CREATE grant) it backs off for an
 * hour (transient RETRY_TRANSIENT) instead of running dbDelta and SHOW
 * TABLES on every request; install() itself always runs.
 * The prefix is read from `$wpdb` on every call, so on multisite each site
 * gets its own tables after `switch_to_blog()`.
 */
final class Installer
{
    public const OPTION = 'proto_blocks_redirects_db';
    public const RETRY_TRANSIENT = 'proto_blocks_redirects_db_retry';
    public const RETRY_SECONDS = 3600;

    public function ensure(): void
    {
        if (get_option(self::OPTION) === Schema::VERSION || get_transient(self::RETRY_TRANSIENT) !== false) {
            return;
        }
        if (!$this->install()) {
            set_transient(self::RETRY_TRANSIENT, 1, self::RETRY_SECONDS);
        }
    }

    /** Runs dbDelta unconditionally; records the version once every table exists. */
    public function install(): bool
    {
        if (!function_exists('dbDelta') && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/upgrade.php')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        if (!function_exists('dbDelta')) {
            return false;
        }
        dbDelta(array_values(Schema::tables($this->prefix())));
        if (!$this->tablesExist()) {
            return false;
        }
        update_option(self::OPTION, Schema::VERSION, true);
        delete_transient(self::RETRY_TRANSIENT);

        return true;
    }

    /** @return array{tables: bool, version: ?string} */
    public function status(): array
    {
        $version = get_option(self::OPTION, null);

        return [
            'tables' => $this->tablesExist(),
            'version' => is_string($version) && $version !== '' ? $version : null,
        ];
    }

    public function drop(): void
    {
        $db = $this->db();
        foreach (Schema::tableNames($this->prefix()) as $table) {
            // Table names come from the prefix and constants only; DDL takes no placeholders.
            $db->query("DROP TABLE IF EXISTS {$table}");
        }
        delete_option(self::OPTION);
    }

    public function tablesExist(): bool
    {
        $db = $this->db();
        $prefix = $this->prefix();
        $found = (array) $db->get_col($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($prefix . 'proto_') . '%'));

        return array_diff(Schema::tableNames($prefix), array_map('strval', $found)) === [];
    }

    private function prefix(): string
    {
        return (string) $this->db()->prefix;
    }

    private function db(): object
    {
        global $wpdb;

        return $wpdb;
    }
}
