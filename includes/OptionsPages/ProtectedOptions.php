<?php

declare(strict_types=1);

namespace ProtoBlocks\OptionsPages;

/**
 * Option names a declared settings page may never read or write: WordPress
 * core settings, secrets, roles, cron, transients and this plugin's own state.
 * Comparison is case-insensitive.
 */
final class ProtectedOptions
{
    private const EXACT = [
        // allowed_options groups
        'general', 'discussion', 'media', 'reading', 'writing', 'options', 'privacy',
        // core state
        'siteurl', 'home', 'active_plugins', 'template', 'stylesheet', 'cron', 'rewrite_rules',
        'db_version', 'initial_db_version', 'auto_core_update_notified', 'recently_activated',
        'uninstall_plugins', 'sidebars_widgets', 'user_count', 'wplang',
        // salts and keys
        'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt',
        'secure_auth_key', 'secure_auth_salt', 'secret_key',
    ];

    private const PREFIXES = ['_transient', '_site_transient', 'theme_mods_', 'widget_', 'proto_blocks_'];

    /** Settings-screen fields stored by core's allowed_options groups. */
    private const CORE_SETTINGS = [
        'blogname', 'blogdescription', 'admin_email', 'users_can_register', 'default_role',
        'timezone_string', 'date_format', 'time_format', 'start_of_week', 'gmt_offset',
        'WPLANG', 'new_admin_email', 'default_category', 'default_post_format',
        'default_pingback_flag', 'default_ping_status', 'default_comment_status',
        'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'blog_public',
        'permalink_structure', 'category_base', 'tag_base', 'upload_path', 'upload_url_path',
        'users_can_register', 'comment_registration', 'moderation_keys', 'disallowed_keys',
        'blog_charset', 'comments_notify', 'moderation_notify', 'mailserver_url',
        'mailserver_login', 'mailserver_pass', 'mailserver_port', 'fileupload_url',
    ];

    public static function isProtected(string $option): bool
    {
        $name = strtolower($option);

        foreach (self::list() as $protected) {
            if ($name === strtolower((string) $protected)) {
                return true;
            }
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function list(): array
    {
        global $wpdb;
        $prefix = (is_object($wpdb) && isset($wpdb->prefix) && is_string($wpdb->prefix)) ? $wpdb->prefix : 'wp_';

        $list = array_merge(self::EXACT, self::CORE_SETTINGS, [$prefix . 'user_roles']);

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('proto_blocks_protected_options', $list);
            if (is_array($filtered)) {
                $list = array_values(array_filter($filtered, 'is_string'));
            }
        }

        return $list;
    }
}
