<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use ProtoBlocks\Modules\Redirects\Engine\Conditions\Factory;
use ProtoBlocks\Modules\Redirects\Engine\Conditions\Ip;
use ProtoBlocks\Modules\Redirects\Engine\Conditions\Language;
use ProtoBlocks\Modules\Redirects\Engine\Conditions\Server;
use ProtoBlocks\Modules\Redirects\Engine\Request;

final class ConditionsTest extends EngineTestCase
{
    private static function eval(array $when, Request $r): bool
    {
        return Factory::for($when['type'])->evaluate($when, $r);
    }

    public function testFactoryCoversEverySpecType(): void
    {
        self::assertSame(
            ['login', 'role', 'referrer', 'agent', 'header', 'cookie', 'ip', 'language', 'server', 'filter', '404'],
            Factory::types()
        );
        foreach (Factory::types() as $type) {
            self::assertSame($type, Factory::for($type)::type());
        }
    }

    public function testFactoryUnknownTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Factory::for('moon-phase');
    }

    public function testLogin(): void
    {
        self::assertFalse(self::eval(['type' => 'login'], self::req('/a')));
        $GLOBALS['pb_test_current_user'] = 3;
        self::assertTrue(self::eval(['type' => 'login'], self::req('/a')));
    }

    public function testRoleAsCapability(): void
    {
        $GLOBALS['pb_test_current_user'] = 3;
        $GLOBALS['pb_test_caps'][3] = ['editor', 'edit_posts'];
        self::assertTrue(self::eval(['type' => 'role', 'value' => 'editor'], self::req('/a')));
        self::assertTrue(self::eval(['type' => 'role', 'value' => 'edit_posts'], self::req('/a')));
        self::assertFalse(self::eval(['type' => 'role', 'value' => 'administrator'], self::req('/a')));
        self::assertFalse(self::eval(['type' => 'role', 'value' => ''], self::req('/a')));
    }

    public function testReferrerExactIsCaseSensitive(): void
    {
        $r = self::req('/a', ['HTTP_REFERER' => 'https://google.com/']);
        self::assertTrue(self::eval(['type' => 'referrer', 'value' => 'https://google.com/', 'regex' => false], $r));
        self::assertFalse(self::eval(['type' => 'referrer', 'value' => 'https://GOOGLE.com/', 'regex' => false], $r));
    }

    public function testReferrerRegexIsCaseInsensitive(): void
    {
        $r = self::req('/a', ['HTTP_REFERER' => 'https://WWW.Google.com/search']);
        self::assertTrue(self::eval(['type' => 'referrer', 'value' => 'google\.', 'regex' => true], $r));
        self::assertFalse(self::eval(['type' => 'referrer', 'value' => 'bing\.', 'regex' => true], $r));
    }

    public function testReferrerAbsentIsFalseEvenForEmptyValue(): void
    {
        self::assertFalse(self::eval(['type' => 'referrer', 'value' => '', 'regex' => false], self::req('/a')));
        self::assertFalse(self::eval(['type' => 'referrer', 'value' => '.*', 'regex' => true], self::req('/a')));
    }

    public function testInvalidRegexValueIsFalse(): void
    {
        $r = self::req('/a', ['HTTP_USER_AGENT' => 'x']);
        self::assertFalse(self::eval(['type' => 'agent', 'value' => '(', 'regex' => true], $r));
    }

    public function testAgent(): void
    {
        $r = self::req('/a', ['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone)']);
        self::assertTrue(self::eval(['type' => 'agent', 'value' => 'iphone|android', 'regex' => true], $r));
        self::assertTrue(self::eval(['type' => 'agent', 'value' => 'Mozilla/5.0 (iPhone)'], $r));
        self::assertFalse(self::eval(['type' => 'agent', 'value' => 'Mozilla'], $r));
    }

    public function testHeaderNameIsCaseInsensitive(): void
    {
        $r = self::req('/a', ['HTTP_X_COUNTRY' => 'DE']);
        self::assertTrue(self::eval(['type' => 'header', 'name' => 'X-Country', 'value' => 'DE'], $r));
        self::assertTrue(self::eval(['type' => 'header', 'name' => 'x-country', 'value' => 'DE'], $r));
        self::assertTrue(self::eval(['type' => 'header', 'name' => 'X_COUNTRY', 'value' => 'DE'], $r));
        self::assertFalse(self::eval(['type' => 'header', 'name' => 'X-Country', 'value' => 'de'], $r));
        self::assertTrue(self::eval(['type' => 'header', 'name' => 'X-Country', 'value' => '^d', 'regex' => true], $r));
    }

    public function testHeaderAbsentIsFalse(): void
    {
        self::assertFalse(self::eval(['type' => 'header', 'name' => 'X-Missing', 'value' => ''], self::req('/a')));
        self::assertFalse(self::eval(['type' => 'header', 'name' => '', 'value' => ''], self::req('/a')));
    }

    public function testCookie(): void
    {
        $r = self::req('/a', [], ['ab' => 'b-variant']);
        self::assertTrue(self::eval(['type' => 'cookie', 'name' => 'ab', 'value' => 'b-variant'], $r));
        self::assertFalse(self::eval(['type' => 'cookie', 'name' => 'AB', 'value' => 'b-variant'], $r), 'cookie names are case-sensitive');
        self::assertTrue(self::eval(['type' => 'cookie', 'name' => 'ab', 'value' => '^b-', 'regex' => true], $r));
        self::assertFalse(self::eval(['type' => 'cookie', 'name' => 'missing', 'value' => ''], $r));
    }

    /** @return array<string, array{string, string, bool}> */
    public static function cidrs(): array
    {
        return [
            'v4 exact' => ['192.0.2.1', '192.0.2.1', true],
            'v4 exact other' => ['192.0.2.2', '192.0.2.1', false],
            'v4 /24 first' => ['192.0.2.0', '192.0.2.0/24', true],
            'v4 /24 last' => ['192.0.2.255', '192.0.2.0/24', true],
            'v4 /24 outside' => ['192.0.3.0', '192.0.2.0/24', false],
            'v4 /25 boundary in' => ['10.0.0.127', '10.0.0.0/25', true],
            'v4 /25 boundary out' => ['10.0.0.128', '10.0.0.0/25', false],
            'v4 /0 everything' => ['8.8.8.8', '0.0.0.0/0', true],
            'v4 /32' => ['8.8.8.8', '8.8.8.8/32', true],
            'v4 /33 invalid' => ['8.8.8.8', '8.8.8.8/33', false],
            'v4 bad bits' => ['8.8.8.8', '8.8.8.8/x', false],
            'v4 empty bits' => ['8.8.8.8', '8.8.8.8/', false],
            'v4 malformed' => ['8.8.8', '8.8.8.0/24', false],
            'v6 exact' => ['2001:db8::1', '2001:db8::1', true],
            'v6 /32 in' => ['2001:db8:ffff::1', '2001:db8::/32', true],
            'v6 /32 out' => ['2001:db9::1', '2001:db8::/32', false],
            'v6 /127 boundary in' => ['2001:db8::1', '2001:db8::/127', true],
            'v6 /127 boundary out' => ['2001:db8::2', '2001:db8::/127', false],
            'v6 /128' => ['::1', '::1/128', true],
            'v6 /129 invalid' => ['::1', '::1/129', false],
            'v6 /0' => ['2001:db8::1', '::/0', true],
            'v6 uppercase' => ['2001:DB8::1', '2001:db8::/64', true],
            'mixed families' => ['192.0.2.1', '2001:db8::/32', false],
            'v4 /0 does not match v6' => ['2001:db8::1', '0.0.0.0/0', false],
            'v4-mapped v6 compared as v4' => ['::ffff:192.0.2.5', '192.0.2.0/24', true],
        ];
    }

    #[DataProvider('cidrs')]
    public function testInCidr(string $ip, string $cidr, bool $expected): void
    {
        self::assertSame($expected, Ip::inCidr($ip, $cidr));
    }

    public function testIpConditionListAndResolver(): void
    {
        $r = self::req('/a', ['REMOTE_ADDR' => '10.1.2.3']);
        self::assertTrue(self::eval(['type' => 'ip', 'value' => ['192.0.2.1', '10.0.0.0/8']], $r));
        self::assertFalse(self::eval(['type' => 'ip', 'value' => ['192.0.2.1']], $r));
        Ip::setResolver(static fn(Request $req): ?string => '192.0.2.1');
        self::assertTrue(self::eval(['type' => 'ip', 'value' => ['192.0.2.1']], $r));
        Ip::setResolver(static fn(Request $req): ?string => null);
        self::assertFalse(self::eval(['type' => 'ip', 'value' => ['0.0.0.0/0']], $r));
    }

    public function testIpWithoutRemoteAddrIsFalse(): void
    {
        self::assertFalse(self::eval(['type' => 'ip', 'value' => ['0.0.0.0/0', '::/0']], self::req('/a')));
    }

    public function testLanguagePrefixMatch(): void
    {
        $r = self::req('/a', ['HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.9,de;q=0.5']);
        self::assertTrue(self::eval(['type' => 'language', 'value' => ['de']], $r));
        self::assertTrue(self::eval(['type' => 'language', 'value' => ['EN']], $r));
        self::assertTrue(self::eval(['type' => 'language', 'value' => ['en-gb']], $r));
        self::assertFalse(self::eval(['type' => 'language', 'value' => ['fr', 'es']], $r));
        self::assertFalse(self::eval(['type' => 'language', 'value' => ['en-US']], $r));
    }

    public function testLanguagePrefixRespectsSubtagBoundary(): void
    {
        $r = self::req('/a', ['HTTP_ACCEPT_LANGUAGE' => 'eng']);
        self::assertFalse(self::eval(['type' => 'language', 'value' => ['en']], $r));
    }

    public function testLanguageQZeroIgnored(): void
    {
        $r = self::req('/a', ['HTTP_ACCEPT_LANGUAGE' => 'fr;q=0, en']);
        self::assertFalse(self::eval(['type' => 'language', 'value' => ['fr']], $r));
        self::assertSame(['en'], Language::accepted('fr;q=0, en, *'));
    }

    public function testLanguageNoHeader(): void
    {
        self::assertFalse(self::eval(['type' => 'language', 'value' => ['en']], self::req('/a')));
        self::assertFalse(self::eval(['type' => 'language', 'value' => []], self::req('/a', ['HTTP_ACCEPT_LANGUAGE' => 'en'])));
    }

    public function testServerIgnoresPortAndCase(): void
    {
        $r = Request::fromUrl('/a', ['HTTP_HOST' => 'Shop.Example:8080']);
        self::assertTrue(self::eval(['type' => 'server', 'value' => 'shop.example'], $r));
        self::assertTrue(self::eval(['type' => 'server', 'value' => 'SHOP.example:443'], $r));
        self::assertTrue(self::eval(['type' => 'server', 'value' => 'https://shop.example/'], $r));
        self::assertFalse(self::eval(['type' => 'server', 'value' => 'example'], $r));
        self::assertFalse(self::eval(['type' => 'server', 'value' => ''], $r));
    }

    public function testServerUnicodeHost(): void
    {
        $r = Request::fromUrl('/a', ['HTTP_HOST' => 'bücher.example']);
        self::assertTrue(self::eval(['type' => 'server', 'value' => 'BÜCHER.example'], $r));
    }

    public function testServerIdnPunycodeEquivalence(): void
    {
        if (!function_exists('idn_to_ascii')) {
            self::markTestSkipped('ext-intl not loaded');
        }
        $r = Request::fromUrl('/a', ['HTTP_HOST' => 'xn--bcher-kva.example']);
        self::assertTrue(self::eval(['type' => 'server', 'value' => 'bücher.example'], $r));
        self::assertSame('xn--bcher-kva.example', Server::normalise('Bücher.Example'));
    }

    public function testFilterViaPbTestFilters(): void
    {
        $seen = null;
        $GLOBALS['pb_test_filters']['my_redirect_check'][] = static function ($value, Request $r) use (&$seen) {
            $seen = [$value, $r->path];
            return $r->path === '/yes';
        };
        self::assertTrue(self::eval(['type' => 'filter', 'value' => 'my_redirect_check'], self::req('/yes')));
        self::assertSame([false, '/yes'], $seen);
        self::assertFalse(self::eval(['type' => 'filter', 'value' => 'my_redirect_check'], self::req('/no')));
    }

    public function testFilterUnhookedIsFalseAndBadNameRefused(): void
    {
        self::assertFalse(self::eval(['type' => 'filter', 'value' => 'nobody_listens'], self::req('/a')));
        $GLOBALS['pb_test_filters']['Bad Name'][] = static fn() => true;
        self::assertFalse(self::eval(['type' => 'filter', 'value' => 'Bad Name'], self::req('/a')));
    }

    public function testNotFound(): void
    {
        self::assertFalse(self::eval(['type' => '404'], self::req('/a')));
        $GLOBALS['pb_test_query']['is_404'] = true;
        self::assertTrue(self::eval(['type' => '404'], self::req('/a')));
    }
}
