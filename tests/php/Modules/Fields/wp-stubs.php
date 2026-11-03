<?php

declare(strict_types=1);

/**
 * WordPress stubs for the Phase 2 Fields module tests (Tasks 1-9). Required
 * with require_once from each test in tests/php/Modules/Fields; not a test
 * file itself. Later tasks that need a stub not listed here add their own
 * tests/php/Modules/Fields/stubs/<Area>.php rather than editing this file.
 *
 * Shared stubs (hooks, users and capabilities, nonces, JSON, transients,
 * options, sanitisers, get_bloginfo) live in tests/php/bootstrap.php with
 * one shape for every directory; do not copy them here. Every stub is
 * guarded, so a real WordPress wins. State lives in globals that tests set
 * up and reset (the shared ones are documented in the bootstrap too):
 *
 *   pb_test_caps[$userId]         list of capabilities (shared with Security stubs)
 *   pb_test_current_user          acting user id
 *   pb_test_options[$name]        options (get_option is in the bootstrap)
 *   pb_test_filters[$hook][]      callbacks run by apply_filters (Fields stubs)
 *   pb_test_hooks[$hook][]        add_action/add_filter registrations:
 *                                 ['callback' => ..., 'priority' => int, 'args' => int]
 *   pb_test_meta_reg[$type][$subtype][$key]  register_meta args ('' subtype = all)
 *   pb_test_meta[$type][$id][$key]           list of stored values
 *   pb_test_posts[$id]            array|object: ID, post_type, post_status, post_author,
 *                                 post_mime_type, page_template, ...
 *   pb_test_terms[$id]            array|object: term_id, taxonomy, name, slug
 *   pb_test_object_terms[$objectId][$taxonomy]  list of term ids
 *   pb_test_set_terms[]           wp_set_object_terms calls
 *   pb_test_users[$id]            array|object: ID, roles, user_login, ...
 *   pb_test_supports[$postType][$feature]  true or the feature args
 *   pb_test_attachments[$id]      ['url', 'width', 'height', 'mime', 'metadata']
 *   pb_test_transients[$key]      ['value' => ..., 'expiration' => int]
 *   pb_test_meta_boxes[]          add_meta_box calls
 *   pb_test_bindings[$name]       register_block_bindings_source properties
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
    /** get_metadata() semantics: '' key → all keys; single → first value or ''; else list. */
    function pb_test_get_meta(string $type, $id, string $key, bool $single)
    {
        $all = $GLOBALS['pb_test_meta'][$type][(int) $id] ?? [];
        if ($key === '') {
            return $all;
        }
        if (!array_key_exists($key, $all)) {
            return $single ? '' : [];
        }
        return $single ? ($all[$key][0] ?? '') : $all[$key];
    }

    /** update_metadata() with core's sanitize_meta(): the registered sanitize_callback runs first. */
    function pb_test_update_meta(string $type, $id, string $key, $value, string $subtype): bool
    {
        $reg = $GLOBALS['pb_test_meta_reg'][$type][$subtype][$key]
            ?? $GLOBALS['pb_test_meta_reg'][$type][''][$key]
            ?? null;
        if (is_array($reg) && isset($reg['sanitize_callback']) && is_callable($reg['sanitize_callback'])) {
            $value = call_user_func($reg['sanitize_callback'], $value, $key, $type, $subtype);
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

// ---------------------------------------------------------------- users

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
        return $p !== null && ($p->post_status ?? '') === 'publish';
    }
}
if (!function_exists('post_type_supports')) {
    function post_type_supports($post_type, $feature)
    {
        return isset($GLOBALS['pb_test_supports'][$post_type][$feature]);
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

// ---------------------------------------------------------------- nonces

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

// ---------------------------------------------------------------- numbers

if (!function_exists('absint')) {
    function absint($maybeint)
    {
        return abs((int) $maybeint);
    }
}
