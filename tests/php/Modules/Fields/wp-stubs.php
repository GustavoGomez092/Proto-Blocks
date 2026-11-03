<?php

declare(strict_types=1);

/**
 * WordPress stubs for the Phase 2 Fields module tests (Tasks 1-9). Required
 * with require_once from each test in tests/php/Modules/Fields; not a test
 * file itself. Later tasks that need a stub not listed here add their own
 * tests/php/Modules/Fields/stubs/<Area>.php rather than editing this file.
 *
 * Every stub is guarded, so a real WordPress or a stub defined earlier (the
 * bootstrap, or another suite's wp-stubs.php) wins. State lives in globals
 * that tests set up and reset:
 *
 *   pb_test_caps[$userId]         list of capabilities (shared with Security stubs)
 *   pb_test_current_user          acting user id
 *   pb_test_options[$name]        options (get_option is in the bootstrap)
 *   pb_test_filters[$hook][]      callbacks run by apply_filters (Fields stubs)
 *   pb_test_hooks[$hook][]        add_action/add_filter registrations:
 *                                 ['callback' => ..., 'priority' => int, 'args' => int]
 *   pb_test_meta_reg[$type][$subtype][$key]  register_meta args ('' subtype = all)
 *   pb_test_meta[$type][$id][$key]           list of stored values (update_*_meta model core:
 *                                 subtype filter, unslash, sanitize_callback, update_{type}_metadata;
 *                                 get_*_meta returns the registered default for a missing key)
 *   pb_test_posts[$id]            array|object: ID, post_type, post_status, post_author,
 *                                 post_mime_type, page_template, ...
 *   pb_test_terms[$id]            array|object: term_id, taxonomy, name, slug
 *   pb_test_object_terms[$objectId][$taxonomy]  list of term ids
 *   pb_test_set_terms[]           wp_set_object_terms calls
 *   pb_test_meta_rows[$mid]       object rows for get_metadata_by_mid / update_metadata_by_mid
 *   pb_test_users[$id]            array|object: ID, roles, user_login, ...
 *   pb_test_supports[$postType][$feature]  true or the feature args
 *   pb_test_attachments[$id]      ['url', 'width', 'height', 'mime', 'metadata']
 *   pb_test_transients[$key]      ['value' => ..., 'expiration' => int]
 *   pb_test_meta_boxes[]          add_meta_box calls
 *   pb_test_bindings[$name]       register_block_bindings_source properties
 *   pb_test_public_types          list of publicly viewable post types (unset = all)
 *   pb_test_queried_object, pb_test_current_post, pb_test_block_editor, pb_test_bloginfo
 */

require_once __DIR__ . '/../../Security/wp-stubs.php';

if (!function_exists('pb_test_obj')) {
    /** Arrays in the fixture globals are handed out as objects, like WP_Post/WP_Term/WP_User. */
    function pb_test_obj(mixed $value): ?object
    {
        if (is_object($value)) {
            return $value;
        }
        return is_array($value) ? (object) $value : null;
    }
}

// ---------------------------------------------------------------- hooks

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        $GLOBALS['pb_test_hooks'][$hook][] = ['callback' => $callback, 'priority' => (int) $priority, 'args' => (int) $accepted_args];
        return true;
    }
}
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        return add_filter($hook, $callback, $priority, $accepted_args);
    }
}

// ---------------------------------------------------------------- meta registration

if (!function_exists('register_meta')) {
    function register_meta($object_type, $meta_key, $args, $deprecated = null)
    {
        $subtype = (string) ($args['object_subtype'] ?? '');
        $GLOBALS['pb_test_meta_reg'][$object_type][$subtype][$meta_key] = $args;
        return true;
    }
}
if (!function_exists('register_post_meta')) {
    function register_post_meta($post_type, $meta_key, array $args)
    {
        return register_meta('post', $meta_key, $args + ['object_subtype' => $post_type]);
    }
}
if (!function_exists('register_term_meta')) {
    function register_term_meta($taxonomy, $meta_key, array $args)
    {
        return register_meta('term', $meta_key, $args + ['object_subtype' => $taxonomy]);
    }
}

if (!function_exists('pb_test_get_meta')) {
    /** Runs pb_test_filters (apply_filters) and then add_filter() registrations by priority, like core's apply_filters(). */
    function pb_test_apply_hooks(string $hook, $value, ...$args)
    {
        $value = apply_filters($hook, $value, ...$args);
        $hooks = $GLOBALS['pb_test_hooks'][$hook] ?? [];
        usort($hooks, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
        foreach ($hooks as $h) {
            $value = ($h['callback'])($value, ...array_slice($args, 0, max(0, $h['args'] - 1)));
        }
        return $value;
    }

    /** get_object_subtype() without its filter. */
    function pb_test_meta_subtype(string $type, $id): string
    {
        if ($type === 'post') {
            return (string) get_post_type((int) $id);
        }
        if ($type === 'term') {
            $t = get_term((int) $id);
            return (string) ($t->taxonomy ?? '');
        }
        return $type === 'user' ? 'user' : '';
    }

    /**
     * get_metadata() semantics: '' key → all keys; single → first value or ''; else list.
     * A missing key yields the registered default, as core's filter_default_metadata() does.
     */
    function pb_test_get_meta(string $type, $id, string $key, bool $single)
    {
        $all = $GLOBALS['pb_test_meta'][$type][(int) $id] ?? [];
        if ($key === '') {
            return $all;
        }
        if (!array_key_exists($key, $all)) {
            $subtype = pb_test_meta_subtype($type, $id);
            $reg = $GLOBALS['pb_test_meta_reg'][$type][$subtype][$key]
                ?? $GLOBALS['pb_test_meta_reg'][$type][''][$key]
                ?? null;
            if (is_array($reg) && array_key_exists('default', $reg)) {
                return $single ? $reg['default'] : [$reg['default']];
            }
            return $single ? '' : [];
        }
        return $single ? ($all[$key][0] ?? '') : $all[$key];
    }

    /**
     * update_metadata() in core's order: get_object_subtype (filtered),
     * wp_unslash, sanitize_meta (the registered sanitize_callback), then the
     * update_{$type}_metadata short-circuit; a non-null check is the result.
     */
    function pb_test_update_meta(string $type, $id, string $key, $value, string $subtype): bool
    {
        $subtype = (string) pb_test_apply_hooks("get_object_subtype_{$type}", pb_test_meta_subtype($type, $id) ?: $subtype, (int) $id);
        $value = wp_unslash($value);
        $reg = $GLOBALS['pb_test_meta_reg'][$type][$subtype][$key]
            ?? $GLOBALS['pb_test_meta_reg'][$type][''][$key]
            ?? null;
        if (is_array($reg) && isset($reg['sanitize_callback']) && is_callable($reg['sanitize_callback'])) {
            $value = call_user_func($reg['sanitize_callback'], $value, $key, $type, $subtype);
        }
        $check = pb_test_apply_hooks("update_{$type}_metadata", null, (int) $id, $key, $value, '');
        if ($check !== null) {
            return (bool) $check;
        }
        $GLOBALS['pb_test_meta'][$type][(int) $id][$key] = [$value];
        return true;
    }

    function pb_test_delete_meta(string $type, $id, string $key): bool
    {
        if (!isset($GLOBALS['pb_test_meta'][$type][(int) $id][$key])) {
            return false;
        }
        unset($GLOBALS['pb_test_meta'][$type][(int) $id][$key]);
        return true;
    }
}

if (!function_exists('get_metadata_by_mid')) {
    /** Rows from pb_test_meta_rows[$mid]: object with meta_id, <type>_id, meta_key, meta_value. */
    function get_metadata_by_mid($meta_type, $meta_id)
    {
        $row = $GLOBALS['pb_test_meta_rows'][(int) $meta_id] ?? null;
        return is_object($row) && isset($row->{$meta_type . '_id'}) ? $row : false;
    }
}
if (!function_exists('update_metadata_by_mid')) {
    /**
     * Core order: short-circuit filter with the given key (false when omitted), then
     * the write; a different string key RENAMES the row (pb_test_meta and
     * pb_test_meta_rows are kept in sync).
     */
    function update_metadata_by_mid($meta_type, $meta_id, $meta_value, $meta_key = false)
    {
        $check = pb_test_apply_hooks("update_{$meta_type}_metadata_by_mid", null, (int) $meta_id, $meta_value, $meta_key);
        if ($check !== null) {
            return (bool) $check;
        }
        $row = get_metadata_by_mid($meta_type, $meta_id);
        if (!$row) {
            return false;
        }
        $objectId = (int) $row->{$meta_type . '_id'};
        $old = (string) $row->meta_key;
        $key = is_string($meta_key) && $meta_key !== '' ? $meta_key : $old;
        if ($key !== $old) {
            unset($GLOBALS['pb_test_meta'][$meta_type][$objectId][$old]);
        }
        $value = wp_unslash($meta_value);
        $GLOBALS['pb_test_meta'][$meta_type][$objectId][$key] = [$value];
        $row->meta_key = $key;
        $row->meta_value = $value;
        return true;
    }
}
if (!function_exists('metadata_exists')) {
    /** A stored row, regardless of registered defaults. */
    function metadata_exists($meta_type, $object_id, $meta_key)
    {
        return isset($GLOBALS['pb_test_meta'][$meta_type][(int) $object_id][$meta_key]);
    }
}
if (!function_exists('wp_slash')) {
    function wp_slash($value)
    {
        if (is_array($value)) {
            return array_map('wp_slash', $value);
        }
        return is_string($value) ? addslashes($value) : $value;
    }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false)
    {
        return pb_test_get_meta('post', $post_id, (string) $key, (bool) $single);
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $meta_key, $meta_value, $prev_value = '')
    {
        return pb_test_update_meta('post', $post_id, (string) $meta_key, $meta_value, (string) get_post_type($post_id));
    }
}
if (!function_exists('delete_post_meta')) {
    function delete_post_meta($post_id, $meta_key, $meta_value = '')
    {
        return pb_test_delete_meta('post', $post_id, (string) $meta_key);
    }
}
if (!function_exists('get_term_meta')) {
    function get_term_meta($term_id, $key = '', $single = false)
    {
        return pb_test_get_meta('term', $term_id, (string) $key, (bool) $single);
    }
}
if (!function_exists('update_term_meta')) {
    function update_term_meta($term_id, $meta_key, $meta_value, $prev_value = '')
    {
        $term = get_term($term_id);
        return pb_test_update_meta('term', $term_id, (string) $meta_key, $meta_value, (string) ($term->taxonomy ?? ''));
    }
}
if (!function_exists('delete_term_meta')) {
    function delete_term_meta($term_id, $meta_key, $meta_value = '')
    {
        return pb_test_delete_meta('term', $term_id, (string) $meta_key);
    }
}
if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key = '', $single = false)
    {
        return pb_test_get_meta('user', $user_id, (string) $key, (bool) $single);
    }
}
if (!function_exists('update_user_meta')) {
    function update_user_meta($user_id, $meta_key, $meta_value, $prev_value = '')
    {
        return pb_test_update_meta('user', $user_id, (string) $meta_key, $meta_value, '');
    }
}
if (!function_exists('delete_user_meta')) {
    function delete_user_meta($user_id, $meta_key, $meta_value = '')
    {
        return pb_test_delete_meta('user', $user_id, (string) $meta_key);
    }
}

// ---------------------------------------------------------------- options

if (!function_exists('update_option')) {
    /** Records the autoload flag in pb_test_autoload[$name]. */
    function update_option($option, $value, $autoload = null)
    {
        $GLOBALS['pb_test_options'][$option] = $value;
        if ($autoload !== null) {
            $GLOBALS['pb_test_autoload'][$option] = $autoload;
        }
        return true;
    }
}

// ---------------------------------------------------------------- users and capabilities

if (!function_exists('current_user_can')) {
    function current_user_can($capability, ...$args)
    {
        return in_array($capability, $GLOBALS['pb_test_caps'][get_current_user_id()] ?? [], true);
    }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in()
    {
        return get_current_user_id() > 0;
    }
}
if (!function_exists('get_userdata')) {
    function get_userdata($user_id)
    {
        return pb_test_obj($GLOBALS['pb_test_users'][(int) $user_id] ?? null) ?? false;
    }
}

// ---------------------------------------------------------------- posts

if (!function_exists('get_post')) {
    function get_post($post = null, $output = 'OBJECT', $filter = 'raw')
    {
        if (is_object($post)) {
            return $post;
        }
        $id = $post === null ? get_the_ID() : (int) $post;
        return pb_test_obj($GLOBALS['pb_test_posts'][(int) $id] ?? null);
    }
}
if (!function_exists('get_the_ID')) {
    function get_the_ID()
    {
        return $GLOBALS['pb_test_current_post'] ?? false;
    }
}
if (!function_exists('get_post_type')) {
    function get_post_type($post = null)
    {
        $p = get_post($post);
        return $p && isset($p->post_type) ? (string) $p->post_type : false;
    }
}
if (!function_exists('get_post_status')) {
    function get_post_status($post = null)
    {
        $p = get_post($post);
        return $p && isset($p->post_status) ? (string) $p->post_status : false;
    }
}
if (!function_exists('get_page_template_slug')) {
    function get_page_template_slug($post = null)
    {
        $p = get_post($post);
        return $p ? (string) ($p->page_template ?? '') : false;
    }
}
if (!function_exists('get_queried_object')) {
    function get_queried_object()
    {
        return $GLOBALS['pb_test_queried_object'] ?? null;
    }
}
if (!function_exists('is_post_publicly_viewable')) {
    function is_post_publicly_viewable($post = null)
    {
        $p = get_post($post);
        if ($p === null || ($p->post_status ?? '') !== 'publish') {
            return false;
        }
        // pb_test_public_types, when set, lists the publicly viewable post types.
        return !isset($GLOBALS['pb_test_public_types'])
            || in_array((string) ($p->post_type ?? ''), (array) $GLOBALS['pb_test_public_types'], true);
    }
}
if (!function_exists('post_type_supports')) {
    function post_type_supports($post_type, $feature)
    {
        return isset($GLOBALS['pb_test_supports'][$post_type][$feature]);
    }
}
if (!function_exists('add_post_type_support')) {
    function add_post_type_support($post_type, $feature, ...$args)
    {
        foreach ((array) $feature as $f) {
            $GLOBALS['pb_test_supports'][$post_type][$f] = $args === [] ? true : $args;
        }
    }
}
if (!function_exists('use_block_editor_for_post')) {
    function use_block_editor_for_post($post)
    {
        return (bool) ($GLOBALS['pb_test_block_editor'] ?? true);
    }
}

// ---------------------------------------------------------------- terms

if (!function_exists('get_term')) {
    function get_term($term, $taxonomy = '', $output = 'OBJECT', $filter = 'raw')
    {
        if (is_object($term)) {
            return $term;
        }
        $t = pb_test_obj($GLOBALS['pb_test_terms'][(int) $term] ?? null);
        if ($t === null || ($taxonomy !== '' && ($t->taxonomy ?? '') !== $taxonomy)) {
            return null;
        }
        return $t;
    }
}
if (!function_exists('wp_get_object_terms')) {
    function wp_get_object_terms($object_ids, $taxonomies, $args = [])
    {
        $out = [];
        foreach ((array) $object_ids as $id) {
            foreach ((array) $taxonomies as $tax) {
                foreach ($GLOBALS['pb_test_object_terms'][(int) $id][$tax] ?? [] as $termId) {
                    $out[] = ($args['fields'] ?? 'all') === 'ids' ? (int) $termId : get_term($termId);
                }
            }
        }
        return array_values(array_filter($out, static fn($t) => $t !== null));
    }
}
if (!function_exists('wp_set_object_terms')) {
    function wp_set_object_terms($object_id, $terms, $taxonomy, $append = false)
    {
        $ids = array_map('intval', (array) $terms);
        $GLOBALS['pb_test_set_terms'][] = ['object_id' => (int) $object_id, 'terms' => $ids, 'taxonomy' => $taxonomy, 'append' => (bool) $append];
        $current = $append ? ($GLOBALS['pb_test_object_terms'][(int) $object_id][$taxonomy] ?? []) : [];
        $GLOBALS['pb_test_object_terms'][(int) $object_id][$taxonomy] = array_values(array_unique(array_merge($current, $ids)));
        return $ids;
    }
}

// ---------------------------------------------------------------- attachments

if (!function_exists('wp_get_attachment_image_src')) {
    function wp_get_attachment_image_src($attachment_id, $size = 'thumbnail', $icon = false)
    {
        $a = $GLOBALS['pb_test_attachments'][(int) $attachment_id] ?? null;
        return $a ? [$a['url'] ?? '', (int) ($a['width'] ?? 0), (int) ($a['height'] ?? 0), false] : false;
    }
}
if (!function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url($attachment_id = 0)
    {
        return $GLOBALS['pb_test_attachments'][(int) $attachment_id]['url'] ?? false;
    }
}
if (!function_exists('get_post_mime_type')) {
    function get_post_mime_type($post = null)
    {
        $id = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;
        if (isset($GLOBALS['pb_test_attachments'][$id]['mime'])) {
            return $GLOBALS['pb_test_attachments'][$id]['mime'];
        }
        $p = get_post($post);
        return $p && isset($p->post_mime_type) ? (string) $p->post_mime_type : false;
    }
}
if (!function_exists('wp_get_attachment_metadata')) {
    function wp_get_attachment_metadata($attachment_id = 0, $unfiltered = false)
    {
        return $GLOBALS['pb_test_attachments'][(int) $attachment_id]['metadata'] ?? false;
    }
}

// ---------------------------------------------------------------- nonces, JSON, transients

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1)
    {
        return 'nonce:' . $action;
    }
}
if (!function_exists('wp_verify_nonce')) {
    /** Valid nonce for $action is the string "nonce:<action>". */
    function wp_verify_nonce($nonce, $action = -1)
    {
        return $nonce === 'nonce:' . $action ? 1 : false;
    }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $display = true)
    {
        $field = '<input type="hidden" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr(wp_create_nonce($action)) . '" />';
        if ($display) {
            echo $field;
        }
        return $field;
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}
if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0)
    {
        $GLOBALS['pb_test_transients'][$transient] = ['value' => $value, 'expiration' => (int) $expiration];
        return true;
    }
}
if (!function_exists('get_transient')) {
    function get_transient($transient)
    {
        return $GLOBALS['pb_test_transients'][$transient]['value'] ?? false;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient($transient)
    {
        $had = isset($GLOBALS['pb_test_transients'][$transient]);
        unset($GLOBALS['pb_test_transients'][$transient]);
        return $had;
    }
}

// ---------------------------------------------------------------- editor registration

if (!function_exists('add_meta_box')) {
    function add_meta_box($id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default', $callback_args = null)
    {
        $GLOBALS['pb_test_meta_boxes'][] = compact('id', 'title', 'callback', 'screen', 'context', 'priority', 'callback_args');
    }
}
if (!function_exists('register_block_bindings_source')) {
    function register_block_bindings_source($source_name, array $source_properties)
    {
        $GLOBALS['pb_test_bindings'][$source_name] = $source_properties;
        return true;
    }
}
if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '', $filter = 'raw')
    {
        $defaults = ['version' => '6.5', 'name' => 'Test site', 'charset' => 'UTF-8', 'language' => 'en-US'];
        return (string) ($GLOBALS['pb_test_bloginfo'][$show] ?? $defaults[$show] ?? '');
    }
}

// ---------------------------------------------------------------- sanitisers

if (!function_exists('sanitize_hex_color')) {
    function sanitize_hex_color($color)
    {
        if ('' === $color) {
            return '';
        }
        return preg_match('|^#([A-Fa-f0-9]{3}){1,2}$|', (string) $color) ? $color : null;
    }
}
if (!function_exists('sanitize_textarea_field')) {
    /** Like sanitize_text_field() but keeps line breaks and inner whitespace. */
    function sanitize_textarea_field($str)
    {
        if (is_array($str) || is_object($str)) {
            return '';
        }
        $str = (string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $str);
        return trim(strip_tags($str));
    }
}
if (!function_exists('is_email')) {
    function is_email($email, $deprecated = false)
    {
        return filter_var((string) $email, FILTER_VALIDATE_EMAIL) !== false ? (string) $email : false;
    }
}
if (!function_exists('sanitize_email')) {
    function sanitize_email($email)
    {
        $email = (string) preg_replace("/[^a-zA-Z0-9!#$%&'*+\\/=?^_`{|}~.@-]/", '', trim((string) $email));
        return is_email($email) ? $email : '';
    }
}
if (!function_exists('absint')) {
    function absint($maybeint)
    {
        return abs((int) $maybeint);
    }
}
