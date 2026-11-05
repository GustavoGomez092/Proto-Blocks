<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects;

use ProtoBlocks\Modules\Redirects\Coexistence;
use ProtoBlocks\Modules\Redirects\Engine\FrontController;
use ProtoBlocks\Modules\Redirects\RedirectsModule;
use ProtoBlocks\Modules\Redirects\Storage\Installer;
use ProtoBlocks\Modules\Redirects\Storage\Retention;

/**
 * End to end: a theme with `proto-redirects/legacy/redirects.jsonc` and
 * `settings.jsonc`, the module booted through Modules\Manager, and the
 * registered `init` / `template_redirect` callbacks run for real request
 * URIs. Asserts what the browser would see (wp_redirect, status) and what
 * the database gets (hit upsert, redirect log, 404 log).
 */
final class IntegrationTest extends ManagerBootTestCase
{
    private const GROUP = <<<'JSONC'
// The legacy site's URLs (hand comment).
{
  "title": "Legacy site",
  "rules": [
    { "id": "r_00000001", "from": "/old-page", "to": "/new-page" },
    { "id": "r_00000002", "from": "^/blog/(\\d{4})/(.*)$", "regex": true, "to": "/news/$1/$2", "code": 302 },
    { "id": "r_00000003", "from": "/members", "to": "/dashboard", "when": { "type": "login" }, "else": "/login", "code": 307 },
    { "id": "r_00000004", "from": "/retired", "action": "error", "code": 410 },
    { "id": "r_00000005", "from": "/inside", "to": "/real-page?x=1", "action": "pass" },
    { "id": "r_00000006", "from": "/quiet", "action": "nothing" },
    { "id": "r_00000007", "from": "/untracked", "to": "/new-page", "log": false },
    { "id": "r_00000008", "from": "/disabled", "to": "/nowhere", "enabled": false }
  ]
}
JSONC;

    private const SETTINGS = <<<'JSONC'
{
  // Full IPs so the log row can be checked; no cache headers.
  "log": { "ip": "full", "redirects": 7, "notFound": 7 },
  "cache": 0
}
JSONC;

    /** @var list<array{0: string, 1: string}> */
    private array $headers = [];
    private int $exits = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->putGroup('legacy', self::GROUP);
        $this->putSettings(self::SETTINGS);
        RedirectsModule::setResponseHandlers(
            function (string $name, string $value): void {
                $this->headers[] = [$name, $value];
            },
            function (): void {
                $this->exits++;
            }
        );
    }

    /** Boots a fresh Manager for $uri and runs the engine's `init` callback; returns the front controller. */
    private function get(string $uri, array $server = []): ?FrontController
    {
        $_SERVER = $server + [
            'REQUEST_URI' => $uri,
            'HTTP_HOST' => 'example.test',
            'REQUEST_METHOD' => 'GET',
            'REMOTE_ADDR' => '203.0.113.5',
            'HTTP_USER_AGENT' => 'Browser/1.0',
        ];
        $GLOBALS['pb_test_redirects'] = [];
        unset($GLOBALS['pb_test_status']);
        $GLOBALS['wpdb'] = new \wpdb();
        $this->headers = [];
        $this->exits = 0;

        $this->bootManager();
        $front = self::frontController();
        if ($front !== null) {
            foreach ($GLOBALS['pb_test_hooks']['init'] as $entry) {
                if ($entry['callback'] === [$front, 'onInit']) {
                    ($entry['callback'])();
                }
            }
        }

        return $front;
    }

    /** Runs the registered template_redirect callback with WordPress having resolved a 404. */
    private static function templateRedirect404(FrontController $front): void
    {
        $GLOBALS['pb_test_query']['is_404'] = true;
        foreach ($GLOBALS['pb_test_hooks']['template_redirect'] as $entry) {
            if ($entry['callback'] === [$front, 'onTemplateRedirect']) {
                ($entry['callback'])();
            }
        }
    }

    private static function frontController(): ?FrontController
    {
        foreach ($GLOBALS['pb_test_hooks']['init'] ?? [] as $entry) {
            if (is_array($entry['callback']) && $entry['callback'][0] instanceof FrontController) {
                return $entry['callback'][0];
            }
        }

        return null;
    }

    /** @return list<string> SQL that touched the hits table */
    private static function hitUpserts(): array
    {
        $out = [];
        foreach ($GLOBALS['wpdb']->calls as $call) {
            if ($call[0] === 'query' && str_contains((string) $call[1], 'proto_redirect_hits')) {
                $out[] = (string) $call[1];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> rows inserted into $table */
    private static function inserts(string $table): array
    {
        $out = [];
        foreach ($GLOBALS['wpdb']->calls as $call) {
            if ($call[0] === 'insert' && $call[1] === $table) {
                $out[] = $call[2];
            }
        }

        return $out;
    }

    private static function redirect(): ?array
    {
        return $GLOBALS['pb_test_redirects'][0] ?? null;
    }

    // ------------------------------------------------------------------ url and regex

    public function testUrlRuleRedirectsCountsAndLogs(): void
    {
        $this->get('/old-page');

        self::assertCount(1, $GLOBALS['pb_test_redirects']);
        self::assertStringEndsWith('/new-page', self::redirect()['location']);
        self::assertSame(301, self::redirect()['status']);
        self::assertSame('proto-blocks', self::redirect()['by']);
        self::assertSame(301, $GLOBALS['pb_test_status']);
        self::assertSame(1, $this->exits);

        $upserts = self::hitUpserts();
        self::assertCount(1, $upserts);
        self::assertStringContainsString("('legacy', 'r_00000001', 1,", $upserts[0]);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $upserts[0]);

        $rows = self::inserts('wp_proto_redirect_log');
        self::assertCount(1, $rows);
        self::assertSame('/old-page', $rows[0]['url']);
        self::assertSame(301, $rows[0]['code']);
        self::assertSame('legacy', $rows[0]['group_slug']);
        self::assertSame('r_00000001', $rows[0]['rule_id']);
        self::assertSame('203.0.113.5', $rows[0]['ip']);
        self::assertSame('Browser/1.0', $rows[0]['agent']);
    }

    public function testUrlRuleHonoursIgnoreCaseAndTrailingSlash(): void
    {
        $this->get('/Old-Page/');
        self::assertStringEndsWith('/new-page', self::redirect()['location']);
    }

    public function testRegexRuleSubstitutesCaptures(): void
    {
        $this->get('/blog/2019/hello-world');
        self::assertStringEndsWith('/news/2019/hello-world', self::redirect()['location']);
        self::assertSame(302, self::redirect()['status']);
        self::assertStringContainsString("'r_00000002'", self::hitUpserts()[0]);
    }

    public function testUnmatchedRequestDoesNothingAtInit(): void
    {
        $this->get('/somewhere-else');
        self::assertSame([], $GLOBALS['pb_test_redirects']);
        self::assertSame([], self::hitUpserts());
        self::assertSame([], self::inserts('wp_proto_redirect_log'));
        self::assertSame(0, $this->exits);
    }

    public function testDisabledRuleNeverFires(): void
    {
        $this->get('/disabled');
        self::assertSame([], $GLOBALS['pb_test_redirects']);
    }

    // ------------------------------------------------------------------ conditions

    public function testLoginConditionPicksTheMatchedOrElseTarget(): void
    {
        $this->get('/members');
        self::assertStringEndsWith('/login', self::redirect()['location'], 'logged out → else');
        self::assertSame(307, self::redirect()['status']);
        self::assertSame('/login', self::inserts('wp_proto_redirect_log')[0]['target']);

        $GLOBALS['pb_test_current_user'] = 7;
        $this->get('/members');
        self::assertStringEndsWith('/dashboard', self::redirect()['location'], 'logged in → to');
    }

    // ------------------------------------------------------------------ error, pass, nothing

    public function testErrorActionSends410AndLogsIt(): void
    {
        $front = $this->get('/retired');

        self::assertSame([], $GLOBALS['pb_test_redirects']);
        self::assertSame(410, $GLOBALS['pb_test_status']);
        self::assertContains(['X-Redirect-By', 'proto-blocks'], $this->headers);
        self::assertSame(0, $this->exits, 'the theme renders its 404 template');
        self::assertCount(1, self::hitUpserts());
        $rows = self::inserts('wp_proto_redirect_log');
        self::assertCount(1, $rows);
        self::assertSame(410, $rows[0]['code']);
        self::assertNull($rows[0]['target']);

        // WordPress then resolves a 404: the intentional 410 is not a missing page.
        self::templateRedirect404($front);
        self::assertSame([], self::inserts('wp_proto_404_log'));
    }

    public function testPassRewritesTheRequestInternally(): void
    {
        $this->get('/inside');

        self::assertSame([], $GLOBALS['pb_test_redirects']);
        self::assertSame('/real-page?x=1', $_SERVER['REQUEST_URI']);
        self::assertSame('x=1', $_SERVER['QUERY_STRING']);
        self::assertSame(['x' => '1'], $_GET);
        self::assertSame(200, self::inserts('wp_proto_redirect_log')[0]['code']);
    }

    public function testNothingStopsProcessingAnd404Logging(): void
    {
        $front = $this->get('/quiet');

        self::assertSame([], $GLOBALS['pb_test_redirects']);
        self::assertCount(1, $GLOBALS['pb_test_actions']['proto_blocks_redirects_nothing'] ?? []);
        self::assertSame([], self::inserts('wp_proto_redirect_log'), 'nothing is never logged');
        self::templateRedirect404($front);
        self::assertSame([], self::inserts('wp_proto_404_log'));
    }

    public function testRuleWithLogFalseRedirectsWithoutCountingOrLogging(): void
    {
        $this->get('/untracked');
        self::assertStringEndsWith('/new-page', self::redirect()['location']);
        self::assertSame([], self::hitUpserts());
        self::assertSame([], self::inserts('wp_proto_redirect_log'));
    }

    // ------------------------------------------------------------------ 404s and exclusions

    public function testUnhandled404IsLoggedAtTemplateRedirect(): void
    {
        $front = $this->get('/no-such-page', ['HTTP_REFERER' => 'https://ref.test/page']);
        self::assertNotNull($front);
        self::templateRedirect404($front);

        $rows = self::inserts('wp_proto_404_log');
        self::assertCount(1, $rows);
        self::assertSame('/no-such-page', $rows[0]['url']);
        self::assertSame('203.0.113.5', $rows[0]['ip']);
        self::assertSame('https://ref.test/page', $rows[0]['referrer']);
    }

    public function testRestRequestsAreExcludedEntirely(): void
    {
        $this->putGroup('api', self::groupText([['from' => '/wp-json/wp/v2/posts', 'to' => '/new-page']]));
        $front = $this->get('/wp-json/wp/v2/posts');
        self::assertNotNull($front);

        self::assertSame([], $GLOBALS['pb_test_redirects']);
        self::templateRedirect404($front);
        self::assertSame([], $GLOBALS['wpdb']->calls, 'never matched, counted or 404-logged');
    }

    public function testAdminRequestsAreNotRedirected(): void
    {
        $GLOBALS['pb_test_is_admin'] = true;
        $this->get('/old-page');
        self::assertSame([], $GLOBALS['pb_test_redirects']);
    }

    // ------------------------------------------------------------------ coexistence and the module switch

    public function testRedirectionActivePausesTheEngine(): void
    {
        Coexistence::setDetector(static fn(): bool => true);
        $front = $this->get('/old-page');

        self::assertNull($front);
        self::assertSame([], $GLOBALS['pb_test_redirects']);
        self::assertSame([], self::hitUpserts());
        // Other modules (scripts) hook template_redirect too; only the engine must stay off.
        foreach ($GLOBALS['pb_test_hooks']['template_redirect'] ?? [] as $entry) {
            $callback = $entry['callback'];
            self::assertFalse(
                is_array($callback) && ($callback[0] ?? null) instanceof \ProtoBlocks\Modules\Redirects\Engine\FrontController,
                'the front controller must not register while paused'
            );
        }
        self::assertTrue(RedirectsModule::instance()?->paused());
    }

    public function testCoexistTrueRunsAlongsideRedirection(): void
    {
        Coexistence::setDetector(static fn(): bool => true);
        $this->putSettings('{ "coexist": true }');
        $this->get('/old-page');
        self::assertStringEndsWith('/new-page', self::redirect()['location']);
    }

    public function testDisabledModuleRegistersNothing(): void
    {
        $GLOBALS['pb_test_filters']['proto_blocks_modules'][] = static fn(array $states): array => ['redirects' => false] + $states;
        $manager = $this->bootManager();

        self::assertNull($manager->redirects());
        self::assertNull(RedirectsModule::instance());
        self::assertFalse($manager->isEnabled('redirects'));
        foreach ($GLOBALS['pb_test_hooks'] as $hook => $entries) {
            foreach ($entries as $entry) {
                $callback = $entry['callback'];
                $owner = is_array($callback) ? (is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0]) : '';
                self::assertStringNotContainsString('\\Redirects\\', $owner, "hook {$hook} belongs to the redirects module");
            }
        }
        self::assertArrayNotHasKey(Retention::HOOK, $GLOBALS['pb_test_cron']);
        self::assertSame([], $GLOBALS['pb_test_dbdelta']);
        self::assertArrayNotHasKey(Installer::OPTION, $GLOBALS['pb_test_options']);
    }
}
