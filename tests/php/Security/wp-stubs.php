<?php
/**
 * WordPress stand-ins only the Security / API / Modules tests use: the kses
 * allowlist and safecss_filter_attr(). wp_kses()/wp_kses_post(),
 * esc_url_raw(), user_can() and the other shared stubs live in
 * tests/php/bootstrap.php; do not copy them here. Every stub is guarded so a
 * real implementation wins.
 */

declare(strict_types=1);

if (!function_exists('wp_kses_allowed_html')) {
    function wp_kses_allowed_html($context = ''): array
    {
        $plain = [];
        $html  = [
            'a'    => ['href' => true, 'target' => true, 'rel' => true, 'title' => true],
            'img'  => ['src' => true, 'alt' => true, 'width' => true, 'height' => true, 'class' => true],
            'span' => ['class' => true, 'style' => true],
            'div'  => ['class' => true, 'style' => true],
            'p'    => ['class' => true],
        ];
        foreach (['strong', 'em', 'b', 'i', 'u', 's', 'br', 'code', 'pre', 'kbd', 'sub', 'sup', 'mark',
                  'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'hr'] as $tag) {
            $html[$tag] = $plain;
        }
        return $html;
    }
}

if (!function_exists('safecss_filter_attr')) {
    /**
     * Minimal safecss_filter_attr: keep declarations whose property is in a
     * small allowlist, drop the rest (so `position:fixed` disappears).
     */
    function safecss_filter_attr($css, $deprecated = ''): string
    {
        $allowed = ['color', 'background-color', 'font-size', 'line-height', 'padding', 'margin',
                    'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
                    'margin-top', 'margin-right', 'margin-bottom', 'margin-left'];
        $kept = [];
        foreach (explode(';', (string) $css) as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '' || !str_contains($declaration, ':')) {
                continue;
            }
            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            if (!in_array(strtolower($property), $allowed, true) || preg_match('/[\\\\(&}=]|\/\*/', preg_replace('/\b(?:var|calc)\([^)]*\)/', '', $value) ?? '')) {
                continue;
            }
            $kept[] = $property . ': ' . $value;
        }
        return implode(';', $kept);
    }
}
