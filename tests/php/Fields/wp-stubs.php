<?php

declare(strict_types=1);

/**
 * Global-namespace WordPress stubs for the Fields tests. Loaded by
 * FieldOutputEscapingTest via require_once; it is not a test file itself.
 * Everything here is guarded, so a real WordPress or a stub defined in
 * tests/php/bootstrap.php takes precedence.
 */

/*
 * The unit-test bootstrap has no WordPress. The field layer calls a handful of
 * WP helpers, stubbed here (guarded, so a real WP or another test's stub wins).
 * The kses stubs are deliberately an approximation of the real thing: an
 * allowlist of tags, an allowlist of attributes per tag, everything else
 * removed. They model what wp_kses()/wp_kses_post() do to these payloads; the
 * production code relies on core's implementation.
 */
if (!function_exists('wp_kses')) {
    function wp_kses($string, $allowed_html, $allowed_protocols = [])
    {
        // Like wp_kses_normalize_entities(): a bare "&" becomes "&amp;". This is
        // what makes a kses'd string unsafe to drop into a text node, so the
        // stub has to model it for the tests to mean anything.
        $string = preg_replace('/&(?!(?:#[0-9]+|#[xX][0-9a-fA-F]+|[A-Za-z][A-Za-z0-9]*);)/', '&amp;', (string) $string);

        return (string) preg_replace_callback(
            '/<(\/?)([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>|[<>]/',
            static function (array $m) use ($allowed_html): string {
                // Like wp_kses_split(): a "<" or ">" that is not part of a
                // tag is turned into an entity rather than left for the parser.
                if ($m[0] === '<' || $m[0] === '>') {
                    return $m[0] === '<' ? '&lt;' : '&gt;';
                }
                $tag = strtolower($m[2]);
                if (!isset($allowed_html[$tag])) {
                    return '';
                }
                if ($m[1] === '/') {
                    return '</' . $tag . '>';
                }
                $attrs = '';
                preg_match_all('/([a-zA-Z-]+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/', $m[3], $found, PREG_SET_ORDER);
                foreach ($found as $a) {
                    $name = strtolower($a[1]);
                    if (!empty($allowed_html[$tag][$name])) {
                        $attrs .= ' ' . $name . '="' . htmlspecialchars(trim($a[2], "\"'"), ENT_QUOTES, 'UTF-8') . '"';
                    }
                }
                return '<' . $tag . $attrs . '>';
            },
            (string) $string
        );
    }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($string)
    {
        static $post = null;
        if ($post === null) {
            $common = ['class' => true, 'id' => true, 'title' => true, 'style' => true];
            $post = [
                'a' => $common + ['href' => true, 'rel' => true, 'target' => true],
                'img' => $common + ['src' => true, 'alt' => true, 'width' => true, 'height' => true, 'srcset' => true, 'sizes' => true],
                'textarea' => $common + ['name' => true],
            ];
            foreach (['p', 'div', 'span', 'strong', 'em', 'b', 'i', 'br', 'code', 'pre', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'figure', 'figcaption', 'section'] as $tag) {
                $post[$tag] = $common;
            }
        }
        return wp_kses($string, $post);
    }
}
if (!function_exists('esc_url')) {
    function esc_url($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $m) && !in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true)) {
            return '';
        }
        return str_replace(['"', "'"], ['', '&#039;'], $url);
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', false);
    }
}
if (!function_exists('apply_filters')) {
    define('PB_FIELDS_TEST_APPLY_FILTERS', true);
    /** Tests register callbacks in $GLOBALS['pb_test_filters'][$hook][]. */
    function apply_filters($hook, $value, ...$args)
    {
        foreach ($GLOBALS['pb_test_filters'][$hook] ?? [] as $callback) {
            $value = $callback($value, ...$args);
        }
        return $value;
    }
}
