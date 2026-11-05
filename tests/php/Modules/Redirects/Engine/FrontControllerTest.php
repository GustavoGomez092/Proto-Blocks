<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use ProtoBlocks\Modules\Redirects\Engine\Dispatcher;
use ProtoBlocks\Modules\Redirects\Engine\FrontController;
use ProtoBlocks\Modules\Redirects\Engine\HitRecorder;
use ProtoBlocks\Modules\Redirects\Engine\RedirectLogger;
use ProtoBlocks\Modules\Redirects\Engine\Request;

final class FrontControllerTest extends EngineTestCase
{
    /** @var list<array{0: string, 1: string}> */
    private array $headers = [];
    private int $exits = 0;
    public object $log;
    public object $hits;
    private int $ruleLoads = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->headers = [];
        $this->exits = 0;
        $this->ruleLoads = 0;
        $this->log = new class implements RedirectLogger {
            public array $entries = [];
            public array $notFound = [];
            public function logRedirect(array $entry): void { $this->entries[] = $entry; }
            public function log404(Request $r): void { $this->notFound[] = $r->path; }
        };
        $this->hits = new class implements HitRecorder {
            public array $calls = [];
            public function record(string $group, string $ruleId): void { $this->calls[] = [$group, $ruleId]; }
        };
    }

    private function controller(array $rules, string $url = '/old', array $settings = [], array $env = [], array $server = []): FrontController
    {
        $settings = self::settings($settings);
        $dispatcher = new Dispatcher(
            $settings,
            function (string $n, string $v): void { $this->headers[] = [$n, $v]; },
            function (): void { $this->exits++; },
            $this->hits,
            $this->log,
        );
        return new FrontController(
            function () use ($rules): array {
                $this->ruleLoads++;
                return $rules;
            },
            $settings,
            $dispatcher,
            static fn(): array => $env,
            static fn(): Request => Request::fromUrl($url, $server + ['HTTP_HOST' => 'example.test']),
        );
    }

    public function testRegisterAddsHooks(): void
    {
        $c = $this->controller([]);
        $c->register();
        self::assertSame(10, has_action('init', [$c, 'onInit']));
        self::assertSame(10, has_action('template_redirect', [$c, 'onTemplateRedirect']));
        self::assertSame(10, has_action('send_headers', [$c, 'onSendHeaders']));
        self::assertSame(10, has_filter('redirect_canonical', [$c, 'onRedirectCanonical']));
    }

    public function testInitPriorityFilterable(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_redirects_priority'][] = static fn() => 1;
        $c = $this->controller([]);
        $c->register();
        self::assertSame(1, has_action('init', [$c, 'onInit']));
    }

    public function testInitRedirects(): void
    {
        $c = $this->controller([self::rule(['from' => '/old', 'to' => '/new', 'group' => 'g', 'id' => 'r_00000abc'])]);
        $c->onInit();
        self::assertSame('/new', $GLOBALS['pb_test_redirects'][0]['location']);
        self::assertSame(301, $GLOBALS['pb_test_redirects'][0]['status']);
        self::assertSame('proto-blocks', $GLOBALS['pb_test_redirects'][0]['by']);
        self::assertSame(1, $this->exits);
        self::assertSame([['g', 'r_00000abc']], $this->hits->calls);
        self::assertTrue($c->handled());
        self::assertFalse($c->onRedirectCanonical('https://example.test/old/'));
    }

    public function testNoMatchLeavesCanonicalAlone(): void
    {
        $c = $this->controller([self::rule(['from' => '/other'])]);
        $c->onInit();
        self::assertSame([], $GLOBALS['pb_test_redirects']);
        self::assertSame('https://x.test/', $c->onRedirectCanonical('https://x.test/'));
    }

    public function testRulesLoadedOnceAcrossHooks(): void
    {
        $GLOBALS['pb_test_query']['is_404'] = true;
        $c = $this->controller([self::rule(['from' => '/other'])]);
        $c->onInit();
        $c->onTemplateRedirect();
        self::assertSame(1, $this->ruleLoads);
    }

    /** @return array<string, array{string}> */
    public static function excludedPaths(): array
    {
        return [
            'rest' => ['/wp-json/x'],
            'rest_route' => ['/?rest_route=/x'],
            'wp-admin' => ['/wp-admin/'],
            'wp-login' => ['/wp-login.php'],
            'wp-cron' => ['/wp-cron.php'],
            'xmlrpc' => ['/xmlrpc.php'],
        ];
    }

    #[DataProvider('excludedPaths')]
    public function testExcludedPathsNeverMatchNorLog404(string $url): void
    {
        $catchAll = self::rule(['from' => '.*', 'regex' => true, 'to' => '/caught']);
        $GLOBALS['pb_test_query']['is_404'] = true;
        $c = $this->controller([$catchAll, self::rule(['from' => null, 'when' => ['type' => '404'], 'to' => '/'])], $url, ['headers' => ['site' => ['X-A' => 'b']]]);
        $c->onInit();
        $c->onTemplateRedirect();
        $c->onSendHeaders();
        self::assertFalse($c->runs());
        self::assertSame([], $GLOBALS['pb_test_redirects']);
        self::assertSame([], $this->log->notFound);
        self::assertSame([], $this->headers);
        self::assertSame(0, $this->ruleLoads);
    }

    public function testSkipsInAdmin(): void
    {
        $GLOBALS['pb_test_is_admin'] = true;
        $c = $this->controller([self::rule()]);
        $c->onInit();
        self::assertSame([], $GLOBALS['pb_test_redirects']);
    }

    public function testSkipsInCron(): void
    {
        $GLOBALS['pb_test_doing_cron'] = true;
        $c = $this->controller([self::rule()]);
        $c->onInit();
        self::assertSame([], $GLOBALS['pb_test_redirects']);
    }

    public function testSkipsInCli(): void
    {
        $c = $this->controller([self::rule()], '/old', [], ['cli' => true]);
        $c->onInit();
        self::assertSame([], $GLOBALS['pb_test_redirects']);
    }

    public function testSkipsInXmlRpc(): void
    {
        $c = $this->controller([self::rule()], '/old', [], ['xmlrpc' => true]);
        $c->onInit();
        self::assertSame([], $GLOBALS['pb_test_redirects']);
    }

    public function testDefaultEnvironmentRunsOnPlainFrontEnd(): void
    {
        $settings = self::settings();
        $d = new Dispatcher($settings, static function (): void {}, static function (): void {});
        $c = new FrontController(static fn(): array => [self::rule()], $settings, $d, null, static fn(): Request => Request::fromUrl('/old'));
        self::assertSame(!(defined('WP_CLI') && constant('WP_CLI')), $c->runs());
    }

    public function testExclusionFilterApplies(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_redirects_excluded'][] = static fn(bool $x, Request $r) => true;
        $c = $this->controller([self::rule()]);
        $c->onInit();
        self::assertSame([], $GLOBALS['pb_test_redirects']);
    }

    public function testCanonicalRunsFirst(): void
    {
        $GLOBALS['pb_test_home_url'] = 'https://example.test';
        $c = $this->controller([self::rule()], 'http://example.test/old?x=1', ['canonical' => ['https' => true]]);
        $c->onInit();
        self::assertCount(1, $GLOBALS['pb_test_redirects']);
        self::assertSame('https://example.test/old?x=1', $GLOBALS['pb_test_redirects'][0]['location']);
        self::assertSame(301, $GLOBALS['pb_test_redirects'][0]['status']);
        self::assertTrue($c->handled());
    }

    public function testNotFoundStageRuleAtTemplateRedirect(): void
    {
        $GLOBALS['pb_test_query']['is_404'] = true;
        $c = $this->controller([self::rule(['from' => null, 'when' => ['type' => '404'], 'to' => '/'])], '/missing');
        $c->onInit();
        self::assertSame([], $GLOBALS['pb_test_redirects'], '404 rules never fire at init');
        $c->onTemplateRedirect();
        self::assertSame('/', $GLOBALS['pb_test_redirects'][0]['location']);
        self::assertSame([], $this->log->notFound, 'a handled 404 is not logged');
    }

    public function testUnhandled404IsLogged(): void
    {
        $GLOBALS['pb_test_query']['is_404'] = true;
        $c = $this->controller([self::rule(['from' => '/other'])], '/missing');
        $c->onInit();
        $c->onTemplateRedirect();
        self::assertSame(['/missing'], $this->log->notFound);
    }

    public function testNot404DoesNotLog(): void
    {
        $c = $this->controller([], '/page');
        $c->onTemplateRedirect();
        self::assertSame([], $this->log->notFound);
    }

    public function testLog404FilterCanVeto(): void
    {
        $GLOBALS['pb_test_query']['is_404'] = true;
        $GLOBALS['pb_test_filters']['proto_blocks_redirects_log_404'][] = static fn(bool $log, Request $r) => $r->path !== '/favicon.ico';
        $c = $this->controller([], '/favicon.ico');
        $c->onTemplateRedirect();
        self::assertSame([], $this->log->notFound);
    }

    public function testNothingSuppresses404LogAndCanonical(): void
    {
        $GLOBALS['pb_test_query']['is_404'] = true;
        $c = $this->controller([self::rule(['from' => '/ignore-me', 'action' => 'nothing', 'to' => null, 'code' => null])], '/ignore-me');
        $c->onInit();
        $c->onTemplateRedirect();
        self::assertSame([], $this->log->notFound);
        self::assertSame([], $GLOBALS['pb_test_redirects']);
        self::assertFalse($c->onRedirectCanonical('https://example.test/ignore-me/'));
        self::assertCount(1, $GLOBALS['pb_test_actions']['proto_blocks_redirects_nothing']);
    }

    public function testPassSuppressesCanonical(): void
    {
        $c = $this->controller([self::rule(['from' => '/old-api', 'action' => 'pass', 'to' => '/inner', 'code' => null])], '/old-api');
        $c->onInit();
        self::assertSame('/inner', $_SERVER['REQUEST_URI']);
        self::assertFalse($c->onRedirectCanonical('https://example.test/inner/'));
    }

    public function testErrorAt410ResendsStatusAtSendHeadersAndSkips404Log(): void
    {
        $c = $this->controller([self::rule(['from' => '/gone', 'action' => 'error', 'code' => 410, 'to' => null])], '/gone');
        $c->onInit();
        self::assertSame(410, $GLOBALS['pb_test_status']);
        $GLOBALS['pb_test_status'] = 200;
        $c->onSendHeaders();
        self::assertSame(410, $GLOBALS['pb_test_status']);
        $GLOBALS['pb_test_query']['is_404'] = true;
        $c->onTemplateRedirect();
        self::assertSame([], $this->log->notFound);
    }

    public function testSendHeadersSendsSiteHeaders(): void
    {
        $c = $this->controller([], '/page', ['headers' => ['site' => ['X-Content-Type-Options' => 'nosniff']]]);
        $c->onSendHeaders();
        self::assertSame([['X-Content-Type-Options', 'nosniff']], $this->headers);
    }

    public function testUnsafeTargetReportedOnce(): void
    {
        $c = $this->controller([self::rule(['from' => '^/r/(.*)$', 'regex' => true, 'to' => '$1'])], '/r/javascript:alert(1)');
        $c->onInit();
        self::assertSame([], $GLOBALS['pb_test_redirects']);
        self::assertCount(1, $this->log->entries);
        self::assertSame(0, $this->log->entries[0]['code']);
    }
}
