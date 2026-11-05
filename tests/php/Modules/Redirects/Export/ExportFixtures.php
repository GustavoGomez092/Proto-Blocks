<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Export;

/**
 * Normalised groups (contract shape) shared by the exporter golden tests.
 */
final class ExportFixtures
{
    public const EXPECTED = __DIR__ . '/../Import/fixtures/expected/';

    /** A normalised rule: every key, registry keys included. */
    public static function rule(string $group, int $index, array $rule): array
    {
        $action = $rule['action'] ?? 'url';
        return $rule + [
            'id' => sprintf('r_%08x', $index + ($group === 'shop' ? 0x20 : ($group === 'off' ? 0x40 : 0))),
            'title' => '',
            'enabled' => true,
            'from' => null,
            'regex' => false,
            'to' => null,
            'else' => null,
            'code' => match ($action) { 'url' => 301, 'error' => 404, default => null },
            'action' => $action,
            'when' => null,
            'query' => 'exact',
            'ignoreCase' => true,
            'ignoreTrailingSlash' => true,
            'log' => true,
            'headers' => [],
            'group' => $group,
            'origin' => 'file',
            'source' => '/theme/proto-redirects/' . $group . '/redirects.jsonc',
            'index' => $index,
        ];
    }

    private static function group(string $slug, string $title, int $position, bool $enabled, array $rules): array
    {
        $list = [];
        foreach ($rules as $i => $rule) {
            $list[] = self::rule($slug, $i + 1, $rule);
        }
        return [
            'slug' => $slug,
            'title' => $title,
            'enabled' => $enabled,
            'position' => $position,
            'defaults' => ['ignoreCase' => true, 'ignoreTrailingSlash' => true, 'query' => 'exact'],
            'rules' => $list,
            'origin' => 'file',
            'source' => '/theme/proto-redirects/' . $slug . '/redirects.jsonc',
            'root' => '/theme',
            'double' => null,
        ];
    }

    /** @return list<array> deliberately not in evaluation order */
    public static function groups(): array
    {
        return [
            self::group('legacy-site', 'Legacy site', 10, true, [
                ['from' => '/about-us', 'to' => '/about'],
                ['title' => 'Old blog', 'from' => '^/blog/(\d+)/(.*)$', 'regex' => true, 'to' => '/news/$2'],
                ['from' => '/promo', 'to' => '/sale', 'code' => 302, 'query' => 'pass'],
                ['from' => '/members', 'when' => ['type' => 'login'], 'to' => '/dashboard', 'else' => '/login', 'code' => 302],
                ['from' => '/gone-forever', 'action' => 'error', 'code' => 410],
                ['from' => '/old-api', 'action' => 'pass', 'to' => '/wp-json/v1/thing'],
                ['from' => '/ignore-me', 'action' => 'nothing'],
                ['from' => '/landing', 'when' => ['type' => 'referrer', 'value' => 'google\.', 'name' => null, 'regex' => true], 'to' => '/landing-google', 'else' => '/landing-other'],
                ['when' => ['type' => '404'], 'to' => '/', 'enabled' => false],
                ['title' => '=HYPERLINK("http://evil.test")', 'from' => '/formula', 'to' => '/safe'],
                ['from' => '/Case', 'to' => '/Case-Sensitive', 'code' => 307, 'ignoreCase' => false, 'ignoreTrailingSlash' => false],
                ['from' => '/search?q=old', 'to' => '/find'],
                ['from' => '/forbidden', 'action' => 'error', 'code' => 403],
                ['from' => "/inject\nRewriteRule ^ http://evil.test [R]", 'to' => '/x'],
                ['from' => '/agent', 'when' => ['type' => 'agent', 'value' => 'iPhone', 'regex' => false], 'to' => '/mobile', 'code' => 302],
                ['from' => '/host', 'when' => ['type' => 'server', 'value' => 'old.example.com'], 'to' => 'https://new.example.com/host'],
                ['from' => '/ip', 'when' => ['type' => 'ip', 'value' => ['1.2.3.4']], 'to' => '/x'],
                ['title' => "\u{FF20}full-width, \"quoted\"", 'from' => '/pct', 'to' => '/a%20b#frag'],
                ['title' => '-minus', 'from' => '/space here', 'to' => '/there', 'headers' => ['X-Robots-Tag' => 'noindex']],
            ]),
            self::group('shop', 'Shop', 5, true, [
                ['from' => '^/shop/(.*)$', 'regex' => true, 'to' => '/store/$1', 'code' => 308],
                ['from' => '/cart', 'to' => '/basket', 'enabled' => false],
            ]),
            self::group('off', 'Switched off', 1, false, [
                ['from' => '/off', 'to' => '/on'],
            ]),
        ];
    }

    public static function hits(): array
    {
        return [
            'legacy-site' => ['r_00000001' => ['hits' => 42, 'lastHit' => '2026-11-01 10:00:00']],
            'shop' => ['r_00000021' => ['hits' => 7, 'lastHit' => null]],
        ];
    }
}
