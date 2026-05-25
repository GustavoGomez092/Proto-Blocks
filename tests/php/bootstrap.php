<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}
if (!function_exists('esc_html')) {
    function esc_html($text) { return $text; }
}
if (!function_exists('sanitize_text_field')) {
    // Minimal stub — does not strip tags or normalize whitespace like the real WP function.
    function sanitize_text_field($text) { return is_string($text) ? trim($text) : $text; }
}
