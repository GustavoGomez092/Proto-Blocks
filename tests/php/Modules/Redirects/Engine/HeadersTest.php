<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use ProtoBlocks\Modules\Redirects\Engine\Headers;

final class HeadersTest extends EngineTestCase
{
    /** @return array<string, array{string, string, bool}> */
    public static function grammar(): array
    {
        return [
            'plain' => ['X-Content-Type-Options', 'nosniff', true],
            'digits and dashes' => ['X-1-2', 'v', true],
            'empty value allowed' => ['X-Empty', '', true],
            'value at the cap' => ['X-Long', str_repeat('a', 1000), true],
            'value over the cap' => ['X-Long', str_repeat('a', 1001), false],
            'CRLF in value' => ['X-A', "a\r\nSet-Cookie: x=1", false],
            'LF in value' => ['X-A', "a\nb", false],
            'NUL in value' => ['X-A', "a\0b", false],
            'tab in value' => ['X-A', "a\tb", false],
            'DEL in value' => ['X-A', "a\x7Fb", false],
            'space in name' => ['X A', 'v', false],
            'colon in name' => ['X-A:', 'v', false],
            'underscore in name' => ['X_A', 'v', false],
            'CRLF in name' => ["X-A\r\n", 'v', false],
            'empty name' => ['', 'v', false],
            'name at 100' => [str_repeat('a', 100), 'v', true],
            'name over 100' => [str_repeat('a', 101), 'v', false],
            'Set-Cookie forbidden' => ['Set-Cookie', 'a=b', false],
            'set-cookie lower forbidden' => ['set-cookie', 'a=b', false],
            'Location forbidden' => ['Location', 'https://evil.test', false],
            'LOCATION forbidden' => ['LOCATION', '/x', false],
            'Status forbidden' => ['Status', '200', false],
            'Content-Length forbidden' => ['Content-Length', '0', false],
            'Transfer-Encoding forbidden' => ['Transfer-Encoding', 'chunked', false],
        ];
    }

    #[DataProvider('grammar')]
    public function testValid(string $name, string $value, bool $expected): void
    {
        self::assertSame($expected, Headers::valid($name, $value));
    }

    public function testSiteDropsInvalid(): void
    {
        $settings = self::settings(['headers' => ['site' => [
            'X-Content-Type-Options' => 'nosniff',
            'Set-Cookie' => 'evil=1',
            'X-Bad' => "a\r\nb",
            'X-Num' => 5,
            'X-Arr' => ['x'],
        ]]]);
        self::assertSame(['X-Content-Type-Options' => 'nosniff', 'X-Num' => '5'], Headers::site($settings));
    }

    public function testSiteMissingSettings(): void
    {
        self::assertSame([], Headers::site([]));
        self::assertSame([], Headers::site(['headers' => ['site' => 'nope']]));
    }

    public function testRedirectMergesRuleOverSettingsCaseInsensitively(): void
    {
        $settings = self::settings(['headers' => ['redirect' => ['X-Robots-Tag' => 'noindex', 'X-From' => 'settings']]]);
        $rule = self::rule(['headers' => ['x-robots-tag' => 'none', 'X-Rule' => 'yes', 'Location' => '/evil']]);
        self::assertSame(['X-From' => 'settings', 'x-robots-tag' => 'none', 'X-Rule' => 'yes'], Headers::redirect($rule, $settings));
    }
}
