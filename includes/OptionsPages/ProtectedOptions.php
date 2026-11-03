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
    /** Exact names (compared lowercase). */
    private const EXACT = [
        // allowed_options groups
        'general', 'discussion', 'media', 'reading', 'writing', 'options', 'privacy',
        // core state
        'siteurl', 'home', 'active_plugins', 'template', 'stylesheet', 'stylesheet_root', 'template_root',
        'current_theme', 'cron', 'rewrite_rules', 'db_version', 'initial_db_version', 'db_upgraded',
        'auto_core_update_notified', 'recently_activated', 'uninstall_plugins', 'sidebars_widgets',
        'user_count', 'wplang', 'sticky_posts', 'fresh_site', 'theme_switched', 'site_icon',
        // salts and keys
        'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt',
        'secure_auth_key', 'secure_auth_salt', 'secret_key',
        // settings screens (allowed_options)
        'blogname', 'blogdescription', 'admin_email', 'new_admin_email', 'users_can_register',
        'default_role', 'timezone_string', 'date_format', 'time_format', 'start_of_week', 'gmt_offset',
        'default_category', 'default_post_format', 'default_pingback_flag', 'default_ping_status',
        'default_comment_status', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page',
        'blog_public', 'permalink_structure', 'category_base', 'tag_base', 'upload_path',
        'upload_url_path', 'comment_registration', 'moderation_keys', 'disallowed_keys', 'blacklist_keys',
        'blog_charset', 'comments_notify', 'moderation_notify', 'mailserver_url', 'mailserver_login',
        'mailserver_pass', 'mailserver_port', 'fileupload_url', 'ping_sites', 'hack_file',
        'comment_moderation', 'comment_max_links', 'show_avatars', 'avatar_default',
        'thumbnail_size_w', 'thumbnail_size_h', 'thumbnail_crop', 'medium_size_w', 'medium_size_h',
        'medium_large_size_w', 'medium_large_size_h', 'large_size_w', 'large_size_h',
        'uploads_use_yearmonth_folders', 'comments_per_page', 'page_comments', 'thread_comments',
        'thread_comments_depth', 'posts_per_rss', 'rss_use_excerpt', 'use_smilies', 'use_balancetags',
        'link_manager_enabled', 'require_name_email', 'comment_previously_approved', 'comment_order',
        'default_comments_page', 'close_comments_for_old_posts', 'close_comments_days_old',
        'show_comments_cookies_opt_in', 'avatar_rating', 'default_email_category', 'default_link_category',
        // other core state
        'recovery_keys', 'admin_email_lifespan', 'wp_page_for_privacy_policy', 'site_logo', 'html_type',
        'can_compress_scripts', 'auto_plugin_theme_update_emails', 'finished_splitting_shared_terms',
    ];

    private const PREFIXES = [
        '_transient', '_site_transient', 'theme_mods_', 'widget_', 'proto_blocks_', 'auto_update_',
        'sidebars_widgets', 'rewrite_rules', 'active_plugins', 'recently_activated', 'uninstall_plugins',
    ];

    private const SUFFIXES = ['user_roles'];

    public static function isProtected(string $option): bool
    {
        $name = strtolower(trim($option));

        if ($name === '') {
            return true;
        }

        foreach (self::list() as $protected) {
            if ($name === strtolower(trim((string) $protected))) {
                return true;
            }
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
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

        $list = array_merge(self::EXACT, [$prefix . 'user_roles']);

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('proto_blocks_protected_options', $list);
            if (is_array($filtered)) {
                $list = array_values(array_filter($filtered, 'is_string'));
            }
        }

        return $list;
    }
}
