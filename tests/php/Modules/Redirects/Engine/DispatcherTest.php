<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use ProtoBlocks\Modules\Redirects\Engine\Dispatcher;
use ProtoBlocks\Modules\Redirects\Engine\HitRecorder;
use ProtoBlocks\Modules\Redirects\Engine\RedirectLogger;
use ProtoBlocks\Modules\Redirects\Engine\Request;

final class DispatcherTest extends EngineTestCase
{
    /** @var list<array{0: string, 1: string}> */
    private array $headers = [];
    private int $exits = 0;
    /** @var list<string> order of side effects */
    private array $events = [];
    public object $hits;
    public object $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->headers = [];
        $this->exits = 0;
        $this->events = [];
        $events = &$this->events;
        $this->hits = new class ($events) implements HitRecorder {
            public array $calls = [];
            public function __construct(private array &$events) {}
            public function record(string $group, string $ruleId): void
            {
                $this->calls[] = [$group, $ruleId];
                $this->events[] = 'hit';
            }
        };
        $this->log = new class ($events) implements RedirectLogger {
            public array $entries = [];
            public array $notFound = [];
            public function __construct(private array &$events) {}
            public function logRedirect(array $entry): void
            {
                $this->entries[] = $entry;
                $this->events[] = 'log';
            }
            public function log404(Request $r): void
            {
                $this->notFound[] = $r;
            }
        };
        $GLOBALS['pb_test_now'] = 1_700_000_000;
    }

    private function dispatcher(array $settings = []): Dispatcher
    {
        return new Dispatcher(
            self::settings($settings),
            function (string $name, string $value): void {
                $this->headers[] = [$name, $value];
                $this->events[] = 'header:' . $name;
            },
            function (): void {
                $this->exits++;
                $this->events[] = 'exit';
            },
            $this->hits,
            $this->log,
        );
    }

    private static function urlMatch(int $code, array $rule = []): array
    {
        return ['rule' => self::rule($rule + ['code' => $code]), 'target' => '/new', 'action' => 'url', 'code' => $code, 'reason' => 'matched'];
    }

    /** @return array<string, array{int, bool}> code, expects cache headers (else nocache) */
    public static function urlCodes(): array
    {
        return ['301' => [301, true], '308' => [308, true], '302' => [302, false], '303' => [303, false], '307' => [307, false]];
    }

    #[DataProvider('urlCodes')]
    public function testUrlRedirectEveryCode(int $code, bool $cached): void
    {
        $this->dispatcher()->dispatch(self::urlMatch($code), self::req('/old'));
        self::assertSame([['location' => '/new', 'status' => $code, 'by' => 'proto-blocks', 'safe' => false]], $GLOBALS['pb_test_redirects']);
        self::assertSame($code, $GLOBALS['pb_test_status']);
        self::assertSame(1, $this->exits);
        if ($cached) {
            self::assertSame([['Cache-Control', 'max-age=3600'], ['Expires', gmdate('D, d M Y H:i:s', 1_700_000_000 + 3600) . ' GMT']], $this->headers);
            self::assertArrayNotHasKey('pb_test_nocache', $GLOBALS);
        } else {
            self::assertSame([], $this->headers);
            self::assertTrue($GLOBALS['pb_test_nocache']);
        }
    }

    /** @return array<string, array{int, ?string}> cache setting, expected Cache-Control (null = none) */
    public static function cacheSettings(): array
    {
        return ['-1 no-cache' => [-1, null], '0 none' => [0, null], '1 hour' => [1, 'max-age=3600'], '24 hours' => [24, 'max-age=86400'], '168 hours' => [168, 'max-age=604800']];
    }

    #[DataProvider('cacheSettings')]
    public function testCacheSetting(int $hours, ?string $cacheControl): void
    {
        $this->dispatcher(['cache' => $hours])->dispatch(self::urlMatch(301), self::req('/old'));
        $sent = array_column($this->headers, 1, 0);
        self::assertSame($cacheControl, $sent['Cache-Control'] ?? null);
        self::assertSame($hours === -1, $GLOBALS['pb_test_nocache'] ?? false);
    }

    public function testRedirectHeadersSentFirstThenHitLogRedirectExit(): void
    {
        $settings = ['headers' => ['redirect' => ['X-Robots-Tag' => 'noindex']]];
        $this->dispatcher($settings)->dispatch(self::urlMatch(301, ['headers' => ['X-Rule' => 'r', 'Set-Cookie' => 'x=1']]), self::req('/old'));
        self::assertSame(['header:X-Robots-Tag', 'header:X-Rule', 'hit', 'log', 'header:Cache-Control', 'header:Expires', 'exit'], $this->events);
    }

    public function testLogEntryShape(): void
    {
        $r = self::req('/old?x=1', ['REQUEST_METHOD' => 'POST', 'HTTP_USER_AGENT' => 'UA', 'HTTP_REFERER' => 'https://ref.test/']);
        $this->dispatcher()->dispatch(self::urlMatch(308, ['group' => 'legacy', 'id' => 'r_0000abcd']), $r);
        $entry = $this->log->entries[0];
        self::assertSame(
            ['url' => '/old?x=1', 'target' => '/new', 'code' => 308, 'group' => 'legacy', 'ruleId' => 'r_0000abcd', 'redirectBy' => 'proto-blocks', 'method' => 'POST', 'agent' => 'UA', 'referrer' => 'https://ref.test/', 'reason' => 'matched'],
            array_diff_key($entry, ['request' => true])
        );
        self::assertSame($r, $entry['request']);
        self::assertSame([['legacy', 'r_0000abcd']], $this->hits->calls);
    }

    public function testRuleLogFalseSkipsLogAndHits(): void
    {
        $this->dispatcher()->dispatch(self::urlMatch(301, ['log' => false]), self::req('/old'));
        self::assertSame([], $this->log->entries);
        self::assertSame([], $this->hits->calls);
        self::assertSame(1, $this->exits);
    }

    public function testTrackHitsFalseStillLogs(): void
    {
        $this->dispatcher(['trackHits' => false])->dispatch(self::urlMatch(301), self::req('/old'));
        self::assertSame([], $this->hits->calls);
        self::assertCount(1, $this->log->entries);
    }

    public function testWithoutRecorderOrLogger(): void
    {
        $d = new Dispatcher(self::settings(), static function (): void {}, static function (): void {});
        $d->dispatch(self::urlMatch(302), self::req('/old'));
        self::assertSame(302, $GLOBALS['pb_test_redirects'][0]['status']);
    }

    /** @return array<string, array{int}> */
    public static function errorCodes(): array
    {
        $out = [];
        foreach ([400, 401, 403, 404, 410, 418, 451, 500, 501, 502, 503, 504] as $code) {
            $out[(string) $code] = [$code];
        }
        return $out;
    }

    #[DataProvider('errorCodes')]
    public function testErrorEveryCode(int $code): void
    {
        $GLOBALS['wp_query'] = new class {
            public int $set404 = 0;
            public function set_404(): void { $this->set404++; }
        };
        $d = $this->dispatcher();
        $d->dispatch(['rule' => self::rule(['action' => 'error', 'code' => $code, 'to' => null]), 'target' => null, 'action' => 'error', 'code' => $code, 'reason' => 'matched'], self::req('/gone'));
        self::assertSame($code, $GLOBALS['pb_test_status']);
        self::assertTrue($GLOBALS['pb_test_nocache']);
        self::assertSame([['X-Redirect-By', 'proto-blocks']], $this->headers);
        self::assertSame([], $GLOBALS['pb_test_redirects']);
        self::assertSame(0, $this->exits, 'the theme 404 template renders');
        self::assertSame(1, $GLOBALS['wp_query']->set404);
        self::assertSame($code, $d->pendingStatus());
        self::assertSame(1000, has_action('wp', [$d, 'applyError']));
        self::assertSame($code, $this->log->entries[0]['code']);
        self::assertNull($this->log->entries[0]['target']);

        // After WordPress ran the main query (status 200), the `wp` hook restores the error.
        $GLOBALS['pb_test_status'] = 200;
        $d->applyError();
        self::assertSame($code, $GLOBALS['pb_test_status']);
        self::assertSame(2, $GLOBALS['wp_query']->set404);

        $GLOBALS['pb_test_status'] = 200;
        $d->resendStatus();
        self::assertSame(in_array($code, [410, 451], true) ? $code : 200, $GLOBALS['pb_test_status'], '410/451 re-sent at send_headers');
    }

    public function testApplyErrorWithoutPendingDoesNothing(): void
    {
        $d = $this->dispatcher();
        $d->applyError();
        $d->resendStatus();
        self::assertArrayNotHasKey('pb_test_status', $GLOBALS);
        self::assertNull($d->pendingStatus());
    }

    public function testPassRewritesTheRequest(): void
    {
        $_GET = ['old' => '1'];
        $this->dispatcher()->dispatch(['rule' => self::rule(['action' => 'pass', 'code' => null]), 'target' => '/wp-json/v1/thing?a=1&b[]=2#frag', 'action' => 'pass', 'code' => null, 'reason' => 'matched'], self::req('/old-api'));
        self::assertSame('/wp-json/v1/thing?a=1&b[]=2', $_SERVER['REQUEST_URI']);
        self::assertSame('/wp-json/v1/thing', $_SERVER['PATH_INFO']);
        self::assertSame('a=1&b[]=2', $_SERVER['QUERY_STRING']);
        self::assertSame(['a' => '1', 'b' => ['2']], $_GET);
        self::assertSame([], $GLOBALS['pb_test_redirects']);
        self::assertSame(0, $this->exits);
        self::assertSame(200, $this->log->entries[0]['code']);
        self::assertCount(1, $this->hits->calls);
    }

    public function testPassWithoutQuery(): void
    {
        $this->dispatcher()->dispatch(['rule' => self::rule(['action' => 'pass']), 'target' => '/inner', 'action' => 'pass', 'code' => null, 'reason' => 'matched'], self::req('/x'));
        self::assertSame('/inner', $_SERVER['REQUEST_URI']);
        self::assertSame('', $_SERVER['QUERY_STRING']);
        self::assertSame([], $_GET);
    }

    public function testNothingFiresActionCountsButDoesNotLog(): void
    {
        $rule = self::rule(['action' => 'nothing', 'code' => null, 'to' => null]);
        $r = self::req('/ignore-me');
        $this->dispatcher()->dispatch(['rule' => $rule, 'target' => null, 'action' => 'nothing', 'code' => null, 'reason' => 'matched'], $r);
        self::assertSame([[$rule, $r]], $GLOBALS['pb_test_actions']['proto_blocks_redirects_nothing']);
        self::assertSame([], $this->log->entries);
        self::assertCount(1, $this->hits->calls);
        self::assertSame([], $GLOBALS['pb_test_redirects']);
        self::assertSame(0, $this->exits);
    }

    public function testCanonicalRedirect(): void
    {
        $this->dispatcher(['headers' => ['redirect' => ['X-A' => 'b']]])->redirect("https://example.test/a\r\n", 301, self::req('/a'));
        self::assertSame('https://example.test/a', $GLOBALS['pb_test_redirects'][0]['location']);
        self::assertSame('proto-blocks', $GLOBALS['pb_test_redirects'][0]['by']);
        self::assertSame(['X-A', 'b'], $this->headers[0]);
        self::assertSame('canonical', $this->log->entries[0]['reason']);
        self::assertNull($this->log->entries[0]['group']);
        self::assertSame([], $this->hits->calls);
        self::assertSame(1, $this->exits);
    }

    public function testSiteHeaders(): void
    {
        $this->dispatcher(['headers' => ['site' => ['X-Content-Type-Options' => 'nosniff', 'Location' => '/x']]])->sendSiteHeaders();
        self::assertSame([['X-Content-Type-Options', 'nosniff']], $this->headers);
    }

    public function testReportIssuesOncePerRequestWithCodeZero(): void
    {
        $d = $this->dispatcher();
        $issues = [
            ['code' => 'regex_error', 'group' => 'g', 'ruleId' => 'r_00000001', 'message' => 'x'],
            ['code' => 'unsafe_target', 'group' => 'g', 'ruleId' => 'r_00000002', 'message' => 'y'],
            ['code' => 'unsafe_target', 'group' => 'g', 'ruleId' => 'r_00000003', 'message' => 'z'],
        ];
        $d->reportIssues($issues, self::req('/x'));
        $d->reportIssues($issues, self::req('/x'));
        self::assertCount(1, $this->log->entries);
        self::assertSame(0, $this->log->entries[0]['code']);
        self::assertSame('r_00000002', $this->log->entries[0]['ruleId']);
        self::assertSame('unsafe_target', $this->log->entries[0]['reason']);
    }
}
