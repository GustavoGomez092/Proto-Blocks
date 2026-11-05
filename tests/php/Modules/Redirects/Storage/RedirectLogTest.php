<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use ProtoBlocks\Modules\Redirects\Storage\RedirectLog;
use ProtoBlocks\Modules\Redirects\Storage\RequestSnapshot;

final class RedirectLogTest extends StorageTestCase
{
    /** @param array<string, mixed> $log */
    private static function log(array $log = []): RedirectLog
    {
        return new RedirectLog(['log' => $log]);
    }

    /** @return array<string, mixed> */
    private static function entry(array $request = [], array $extra = []): array
    {
        return $extra + [
            'url' => '/old',
            'target' => '/new',
            'code' => 301,
            'group' => 'legacy-site',
            'ruleId' => 'r_8f3a2c1d',
            'request' => $request + [
                'url' => '/old',
                'method' => 'GET',
                'ip' => '203.0.113.77',
                'agent' => 'Mozilla/5.0',
                'referrer' => 'https://google.com/',
                'headers' => [],
            ],
        ];
    }

    /** @return array<string, mixed> the inserted row */
    private function inserted(): array
    {
        $inserts = $this->callsOf('insert');
        self::assertCount(1, $inserts);
        self::assertSame('wp_proto_redirect_log', $inserts[0][1]);

        return $inserts[0][2];
    }

    public function testRowShape(): void
    {
        self::log()->logRedirect(self::entry());

        self::assertSame([
            'created' => '2026-11-05 12:34:56',
            'url' => '/old',
            'target' => '/new',
            'code' => 301,
            'group_slug' => 'legacy-site',
            'rule_id' => 'r_8f3a2c1d',
            'redirect_by' => 'proto-blocks',
            'method' => 'GET',
            'ip' => null,
            'agent' => 'Mozilla/5.0',
            'referrer' => 'https://google.com/',
            'request' => null,
        ], $this->inserted());
        $formats = $this->callsOf('insert')[0][3];
        self::assertCount(12, $formats);
        self::assertSame('%d', $formats[3]);
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function policies(): iterable
    {
        yield 'default (7 days)' => [null, true];
        yield '-1 does not log' => [-1, false];
        yield 'below -1 is -1' => [-5, false];
        yield '0 keeps forever' => [0, true];
        yield 'N days' => [30, true];
        yield 'numeric string' => ['-1', false];
    }

    #[DataProvider('policies')]
    public function testLogPolicy(mixed $days, bool $writes): void
    {
        $log = $days === null ? self::log() : self::log(['redirects' => $days]);
        $log->logRedirect(self::entry());
        self::assertCount($writes ? 1 : 0, $this->callsOf('insert'));
    }

    public function testRedirectPolicyDoesNotAffect404Policy(): void
    {
        $log = self::log(['redirects' => 7, 'notFound' => -1]);
        self::assertTrue($log->enabled());
        self::assertSame(7, $log->retentionDays());
        self::assertSame(90, self::log(['redirects' => 400])->retentionDays());
    }

    public function testTruncation(): void
    {
        self::log()->logRedirect(self::entry(
            ['agent' => str_repeat('a', 300), 'referrer' => 'https://x.test/' . str_repeat('r', 300)],
            ['url' => '/' . str_repeat('u', 2100), 'target' => '/' . str_repeat('t', 2100), 'group' => str_repeat('g', 70), 'ruleId' => 'r_8f3a2c1d99', 'redirectBy' => str_repeat('b', 60)]
        ));
        $row = $this->inserted();
        self::assertSame(2000, mb_strlen($row['url']));
        self::assertSame(2000, mb_strlen($row['target']));
        self::assertSame(255, mb_strlen($row['agent']));
        self::assertSame(255, mb_strlen($row['referrer']));
        self::assertSame(60, strlen($row['group_slug']));
        self::assertSame(10, strlen($row['rule_id']));
        self::assertSame(50, strlen($row['redirect_by']));
    }

    public function testTruncationCountsCharactersNotBytes(): void
    {
        self::log()->logRedirect(self::entry(['agent' => str_repeat('é', 300)]));
        $agent = $this->inserted()['agent'];
        self::assertSame(255, mb_strlen($agent));
        self::assertTrue(mb_check_encoding($agent, 'UTF-8'));
    }

    public function testUrlIsDecodedOnceAndSanitised(): void
    {
        self::log()->logRedirect(self::entry([], ['url' => "/caf%C3%A9/%2541?x=<script>alert(1)</script>\x01\x7f"]));
        self::assertSame('/café/%41?x=', $this->inserted()['url']);
    }

    public function testInvalidUtf8IsStripped(): void
    {
        self::log()->logRedirect(self::entry(['agent' => "bad\xC3\x28agent"], ['url' => "/a%FFb"]));
        $row = $this->inserted();
        self::assertTrue(mb_check_encoding($row['url'], 'UTF-8'));
        self::assertTrue(mb_check_encoding($row['agent'], 'UTF-8'));
        self::assertSame('/ab', $row['url']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function methods(): iterable
    {
        yield 'lower-case get' => ['get', 'GET'];
        yield 'head' => ['HEAD', 'HEAD'];
        yield 'post' => ['Post', 'POST'];
        yield 'delete' => ['DELETE', 'DELETE'];
        yield 'options' => ['OPTIONS', 'OPTIONS'];
        yield 'webdav' => ['PROPFIND', 'OTHER'];
        yield 'injection' => ["GET'; DROP TABLE", 'OTHER'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('methods')]
    public function testMethodAllowList(string $method, string $stored): void
    {
        self::log()->logRedirect(self::entry(['method' => $method]));
        self::assertSame($stored, $this->inserted()['method']);
    }

    public function testEmptyAgentAndReferrerAreNull(): void
    {
        self::log()->logRedirect(self::entry(['agent' => '', 'referrer' => '   ']));
        $row = $this->inserted();
        self::assertNull($row['agent']);
        self::assertNull($row['referrer']);
    }

    public function testCodeIsClampedAndUnsafeTargetCodeZeroKept(): void
    {
        self::log()->logRedirect(self::entry([], ['code' => 0, 'target' => null]));
        $row = $this->inserted();
        self::assertSame(0, $row['code']);
        self::assertNull($row['target']);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function ipPolicies(): iterable
    {
        yield 'none' => ['none', null];
        yield 'masked' => ['masked', '203.0.113.0'];
        yield 'full' => ['full', '203.0.113.77'];
    }

    #[DataProvider('ipPolicies')]
    public function testIpPolicy(string $policy, ?string $stored): void
    {
        self::log(['ip' => $policy])->logRedirect(self::entry());
        self::assertSame($stored, $this->inserted()['ip']);
    }

    public function testIpFromTrustedProxyHeader(): void
    {
        $log = self::log(['ip' => 'full', 'trustedProxies' => ['203.0.113.77'], 'ipHeaders' => ['X-Forwarded-For']]);
        $log->logRedirect(self::entry(['headers' => ['x-forwarded-for' => '1.1.1.1, 2001:db8::5']]));
        self::assertSame('2001:db8::5', $this->inserted()['ip']);
    }

    public function testSpoofedForwardedForFromUntrustedAddressIsIgnored(): void
    {
        $log = self::log(['ip' => 'full', 'trustedProxies' => ['10.0.0.1'], 'ipHeaders' => ['X-Forwarded-For']]);
        $log->logRedirect(self::entry(['headers' => ['x-forwarded-for' => '1.1.1.1']]));
        self::assertSame('203.0.113.77', $this->inserted()['ip']);
    }

    public function testHeadersAreNotStoredByDefault(): void
    {
        self::log()->logRedirect(self::entry(['headers' => ['accept' => 'text/html']]));
        self::assertNull($this->inserted()['request']);
    }

    public function testSensitiveHeadersAreStrippedCaseInsensitively(): void
    {
        $server = [
            'REQUEST_URI' => '/old',
            'REQUEST_METHOD' => 'GET',
            'REMOTE_ADDR' => '203.0.113.77',
            'HTTP_AUTHORIZATION' => 'Bearer secret',
            'HTTP_COOKIE' => 'wordpress_logged_in=secret',
            'HTTP_HOST' => 'example.test',
            'HTTP_PROXY_AUTHORIZATION' => 'Basic secret',
            'HTTP_X_WP_NONCE' => 'secret',
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_ACCEPT_LANGUAGE' => 'en',
        ];
        $snapshot = RequestSnapshot::fromGlobals($server);
        $snapshot['headers']['Cookie'] = 'mixed-case secret';
        $snapshot['headers']['AUTHORIZATION'] = 'upper secret';

        self::log(['headers' => true])->logRedirect(self::entry([], ['request' => $snapshot]));
        $stored = $this->inserted()['request'];

        self::assertIsString($stored);
        self::assertStringNotContainsString('secret', $stored);
        self::assertSame(['headers' => ['accept' => 'text/html', 'accept-language' => 'en']], json_decode($stored, true));
    }

    public function testStoredHeadersAreBounded(): void
    {
        $headers = ['x-long' => str_repeat('v', 5000), "bad name" => 'x', 'x-ctl' => "a\r\nSet-Cookie: x"];
        for ($i = 0; $i < 80; $i++) {
            $headers['x-h' . $i] = 'v';
        }
        self::log(['headers' => true])->logRedirect(self::entry(['headers' => $headers]));
        $decoded = json_decode((string) $this->inserted()['request'], true);

        self::assertCount(50, $decoded['headers']);
        self::assertSame(1000, strlen($decoded['headers']['x-long']));
        self::assertSame('aSet-Cookie: x', $decoded['headers']['x-ctl']);
        self::assertArrayNotHasKey('bad name', $decoded['headers']);
    }

    public function testAcceptsTheEngineRequestObject(): void
    {
        $request = new class {
            public string $uri = '/from-object?a=1';
            public string $method = 'head';
            public string $remoteAddr = '198.51.100.20';
            public string $userAgent = 'curl/8';
            public string $referrer = '';
            /** @var array<string, string> */
            public array $headers = ['accept' => '*/*'];
        };
        self::log(['ip' => 'full', 'headers' => true])->logRedirect(['target' => '/t', 'code' => 302, 'request' => $request]);
        $row = $this->inserted();

        self::assertSame('/from-object?a=1', $row['url']);
        self::assertSame('HEAD', $row['method']);
        self::assertSame('198.51.100.20', $row['ip']);
        self::assertSame('curl/8', $row['agent']);
        self::assertNull($row['group_slug']);
        self::assertSame('{"headers":{"accept":"*/*"}}', $row['request']);
    }

    public function testDefaultsToTheGlobalRequest(): void
    {
        $saved = $_SERVER;
        $_SERVER['REQUEST_URI'] = '/from-globals';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            self::log()->logRedirect(['target' => '/x', 'code' => 307]);
        } finally {
            $_SERVER = $saved;
        }
        $row = $this->inserted();
        self::assertSame('/from-globals', $row['url']);
        self::assertSame('POST', $row['method']);
    }

    public function testCustomRedirectBy(): void
    {
        self::log()->logRedirect(self::entry([], ['redirectBy' => 'WordPress']));
        self::assertSame('WordPress', $this->inserted()['redirect_by']);
    }

    public function testQueryBuildsPreparedFiltersAndPaging(): void
    {
        $this->db->queue[] = '42';
        $this->db->queue[] = [[
            'id' => '9', 'created' => '2026-11-05 10:00:00', 'url' => '/old', 'target' => '/new', 'code' => '301',
            'group_slug' => 'g', 'rule_id' => 'r_00000001', 'redirect_by' => 'proto-blocks', 'method' => 'GET',
            'ip' => null, 'agent' => 'UA', 'referrer' => null, 'request' => '{"headers":{"accept":"*/*"}}',
        ]];

        $result = self::log()->query([
            'search' => "50%_o'k",
            'code' => '301',
            'group' => 'g',
            'ruleId' => 'r_00000001',
            'from' => '2026-11-01',
            'to' => '2026-11-05',
            'unknown' => 'ignored',
        ], 3, 20);

        self::assertSame(42, $result['total']);
        self::assertSame([[
            'id' => 9, 'created' => '2026-11-05 10:00:00', 'url' => '/old', 'target' => '/new', 'code' => 301,
            'group' => 'g', 'ruleId' => 'r_00000001', 'redirectBy' => 'proto-blocks', 'method' => 'GET',
            'ip' => null, 'agent' => 'UA', 'referrer' => null, 'request' => ['headers' => ['accept' => '*/*']],
        ]], $result['rows']);

        $where = " WHERE (url LIKE '%50\\\\%\\\\_o\\'k%' OR target LIKE '%50\\\\%\\\\_o\\'k%') AND code = 301 AND group_slug = 'g'"
            . " AND rule_id = 'r_00000001' AND created >= '2026-11-01 00:00:00' AND created <= '2026-11-05 23:59:59'";
        self::assertSame([
            'SELECT COUNT(*) FROM wp_proto_redirect_log' . $where,
            'SELECT * FROM wp_proto_redirect_log' . $where . ' ORDER BY id DESC LIMIT 20 OFFSET 40',
        ], $this->sql());
    }

    public function testQueryWithoutFiltersAndClampedPaging(): void
    {
        self::log()->query([], 0, 5000);
        self::assertSame([
            'SELECT COUNT(*) FROM wp_proto_redirect_log',
            'SELECT * FROM wp_proto_redirect_log ORDER BY id DESC LIMIT 200 OFFSET 0',
        ], $this->sql());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function ignoredFilters(): iterable
    {
        yield 'bad date' => [['from' => '2026-13-40', 'to' => 'yesterday']];
        yield 'sql in date' => [['from' => "2026-11-01' OR 1=1 --"]];
        yield 'non-numeric code' => [['code' => '30x']];
        yield 'array values' => [['search' => ['x'], 'group' => ['g'], 'code' => [301]]];
        yield 'blank search' => [['search' => '   ', 'group' => '']];
    }

    #[DataProvider('ignoredFilters')]
    public function testInvalidFiltersAreIgnored(array $filters): void
    {
        self::log()->query($filters);
        self::assertSame('SELECT COUNT(*) FROM wp_proto_redirect_log', $this->sql()[0]);
    }

    public function testDateTimeFilters(): void
    {
        self::log()->query(['from' => '2026-11-01T08:30', 'to' => '2026-11-02 09:15:20']);
        self::assertSame(
            "SELECT COUNT(*) FROM wp_proto_redirect_log WHERE created >= '2026-11-01 08:30:00' AND created <= '2026-11-02 09:15:20'",
            $this->sql()[0]
        );
    }

    public function testDeleteByIds(): void
    {
        $this->db->queue[] = 2;
        self::assertSame(2, self::log()->delete([3, '5', 'x', -1, 0, 3, '7; DROP']));
        self::assertSame(['DELETE FROM wp_proto_redirect_log WHERE id IN (3, 5)'], $this->sql());
    }

    public function testDeleteNothing(): void
    {
        self::assertSame(0, self::log()->delete(['x']));
        self::assertSame([], $this->db->calls);
    }

    public function testClearAllAndFiltered(): void
    {
        $this->db->queue[] = 10;
        self::assertSame(10, self::log()->clear([]));
        $this->db->queue[] = 1;
        self::assertSame(1, self::log()->clear(['code' => 410]));
        self::assertSame([
            'DELETE FROM wp_proto_redirect_log',
            'DELETE FROM wp_proto_redirect_log WHERE code = 410',
        ], $this->sql());
    }

    public function testExportRowsReadsInKeysetBatches(): void
    {
        $batch = [];
        for ($id = 1000; $id > 500; $id--) {
            $batch[] = ['id' => (string) $id, 'url' => '/u' . $id];
        }
        $this->db->queue[] = $batch;
        $this->db->queue[] = [['id' => '12', 'url' => '/last']];

        $rows = iterator_to_array(self::log()->exportRows(['group' => 'g']), false);

        self::assertCount(501, $rows);
        self::assertSame('/last', $rows[500]['url']);
        self::assertSame([
            "SELECT * FROM wp_proto_redirect_log WHERE group_slug = 'g' ORDER BY id DESC LIMIT 500",
            "SELECT * FROM wp_proto_redirect_log WHERE group_slug = 'g' AND id < 501 ORDER BY id DESC LIMIT 500",
        ], $this->sql());
    }

    public function testExportRowsWithoutFiltersUsesWhereForTheKeyset(): void
    {
        $batch = array_fill(0, 500, ['id' => '600']);
        $this->db->queue[] = $batch;
        iterator_to_array(self::log()->exportRows(), false);
        self::assertSame('SELECT * FROM wp_proto_redirect_log WHERE id < 600 ORDER BY id DESC LIMIT 500', $this->sql()[1]);
    }

    public function testLog404DelegatesToTheNotFoundLog(): void
    {
        self::log(['notFound' => 7])->log404(['url' => '/missing', 'method' => 'GET', 'ip' => '1.2.3.4']);
        $inserts = $this->callsOf('insert');
        self::assertCount(1, $inserts);
        self::assertSame('wp_proto_404_log', $inserts[0][1]);
    }

    public function testLog404HonoursTheNotFoundPolicyNotTheRedirectOne(): void
    {
        self::log(['redirects' => 7, 'notFound' => -1])->log404(['url' => '/missing']);
        self::assertSame([], $this->db->calls);
    }
}
