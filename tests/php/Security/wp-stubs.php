<?php
/**
 * WordPress function stubs for the Security / API unit tests.
 *
 * The suite runs without WordPress, so the kses family is emulated here.
 * The emulation is deliberately modelled on the real behaviour the tests
 * depend on (tag allowlist, attribute allowlist, bad-protocol stripping,
 * wp_pre_kses_less_than turning a lone "<" into "&lt;"), but it is NOT
 * WordPress kses: a passing test proves the sanitizer routes each value to
 * the right helper with the right allowlist, not kses's own parsing edge
 * cases. Every stub is guarded so a real implementation loaded first wins.
 */

declare(strict_types=1);

// PHPUnit loads test files in sorted order, so tests/php/API and
// tests/php/Security load before tests/php/Fields. The Fields suite ships
// its own kses/esc stubs with the same contract; load them first so every
// suite in the process sees one consistent emulation, and only fill in what
// is still missing below.
$pbFieldsStubs = __DIR__ . '/../Fields/wp-stubs.php';
if (is_file($pbFieldsStubs)) {
    require_once $pbFieldsStubs;
}
unset($pbFieldsStubs);

if (!function_exists('pb_test_kses')) {
    /**
     * Minimal kses: keep allowed tags with allowed attributes, drop the rest,
     * encode a "<" that does not start a tag, strip bad protocols on href/src.
     */
    function pb_test_kses(string $content, array $allowed): string
    {
        // wp_pre_kses_less_than: a "<" with no ">" before the next "<" / end.
        $content = (string) preg_replace_callback(
            '%<[^>]*?((?=<)|>|$)%',
            static fn(array $m): string => str_contains($m[0], '>') ? $m[0] : htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8'),
            $content
        );

        $protocols = wp_allowed_protocols();

        return (string) preg_replace_callback(
            '%<[^>]*>%',
            static function (array $m) use ($allowed, $protocols): string {
                if (!preg_match('%^<\s*(/\s*)?([a-zA-Z0-9-]+)([^>]*)>$%', $m[0], $t)) {
                    return '';
                }
                $elem = strtolower($t[2]);
                if (!isset($allowed[$elem])) {
                    return '';
                }
                if (trim($t[1]) !== '') {
                    return "</$elem>";
                }
                $attrs = '';
                preg_match_all('/([a-zA-Z-]+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+)/', $t[3], $am, PREG_SET_ORDER);
                foreach ($am as $a) {
                    $name = strtolower($a[1]);
                    $val  = trim($a[2], "\"'");
                    if (empty($allowed[$elem][$name])) {
                        continue;
                    }
                    if (in_array($name, ['href', 'src'], true)
                        && preg_match('/^\s*([a-z][a-z0-9+.-]*):/i', $val, $p)
                        && !in_array(strtolower($p[1]), $protocols, true)) {
                        continue;
                    }
                    $attrs .= ' ' . $name . '="' . $val . '"';
                }
                return "<$elem$attrs>";
            },
            $content
        );
    }
}

if (!function_exists('wp_allowed_protocols')) {
    function wp_allowed_protocols(): array
    {
        return ['http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'tel', 'sms', 'feed'];
    }
}

if (!function_exists('wp_kses')) {
    function wp_kses($content, $allowed_html, $allowed_protocols = []): string
    {
        return pb_test_kses((string) $content, is_array($allowed_html) ? $allowed_html : wp_kses_allowed_html('post'));
    }
}

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

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($content): string
    {
        return pb_test_kses((string) $content, wp_kses_allowed_html('post'));
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url, $protocols = null): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        $url = (string) preg_replace('/[^a-z0-9-~+_.?#=!&;,\/:%@$|*\'()\[\]\\x80-\\xff]/i', '', $url);
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $m)) {
            return in_array(strtolower($m[1]), wp_allowed_protocols(), true) ? $url : '';
        }
        if (!preg_match('#^[/?\#]#', $url) && !str_starts_with($url, '//')) {
            $url = 'http://' . $url;
        }
        return $url;
    }
}

if (!function_exists('user_can')) {
    /** Tests set $GLOBALS['pb_test_caps'][$userId][] = 'unfiltered_html' to grant a capability. */
    function user_can($user, $capability, ...$args): bool
    {
        $id = is_object($user) ? (int) ($user->ID ?? 0) : (int) $user;
        return in_array($capability, $GLOBALS['pb_test_caps'][$id] ?? [], true);
    }
}

if (!function_exists('get_current_user_id')) {
    /** Tests set $GLOBALS['pb_test_current_user'] to pick the acting user. */
    function get_current_user_id(): int
    {
        return (int) ($GLOBALS['pb_test_current_user'] ?? 0);
    }
}

if (!function_exists('sanitize_html_class')) {
    function sanitize_html_class($classname, $fallback = ''): string
    {
        $sanitized = (string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) $classname);
        return $sanitized === '' ? (string) $fallback : $sanitized;
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
