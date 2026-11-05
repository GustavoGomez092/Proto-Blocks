<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Storage;

use ProtoBlocks\Modules\Redirects\Definition\Settings;
use ProtoBlocks\Modules\Redirects\Engine\Dispatcher;
use ProtoBlocks\Modules\Redirects\Engine\HitRecorder;
use ProtoBlocks\Modules\Redirects\Engine\RedirectLogger;
use ProtoBlocks\Modules\Redirects\Engine\Request;
use ProtoBlocks\Modules\Redirects\Storage\Hits;
use ProtoBlocks\Modules\Redirects\Storage\RedirectLog;

/**
 * The engine's Dispatcher against Track C's real storage: the shapes B sends
 * are the shapes C writes, and `log: false` is enforced once, by the Dispatcher.
 */
final class EngineContractTest extends StorageTestCase
{
    private array $serverBackup = [];
    private array $getBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $GLOBALS['pb_test_redirects'] = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        unset($GLOBALS['pb_test_redirects'], $GLOBALS['pb_test_status'], $GLOBALS['pb_test_nocache'], $GLOBALS['pb_test_actions']);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private static function rule(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'r_0000000a', 'title' => '', 'enabled' => true, 'from' => '/old', 'regex' => false, 'to' => '/new',
            'else' => null, 'code' => 301, 'action' => 'url', 'when' => null, 'query' => 'exact', 'ignoreCase' => true,
            'ignoreTrailingSlash' => true, 'log' => true, 'headers' => [],
            'group' => 'legacy', 'origin' => 'file', 'source' => '/x/redirects.jsonc', 'index' => 0,
        ];
    }

    /** @param array<string, mixed> $settings */
    private static function dispatcher(array $settings): Dispatcher
    {
        return new Dispatcher($settings, static fn(string $n, string $v) => null, static fn() => null, new Hits($settings), new RedirectLog($settings));
    }

    private static function request(): Request
    {
        return Request::fromUrl('http://example.test/old?x=1', [
            'REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Agent/1', 'HTTP_REFERER' => 'https://ref.test/', 'REQUEST_METHOD' => 'post',
        ]);
    }

    /** @return list<string> */
    private function hitUpserts(): array
    {
        return array_values(array_filter($this->sql(), static fn(string $q): bool => str_contains($q, 'proto_redirect_hits')));
    }

    public function testStorageImplementsTheEngineInterfaces(): void
    {
        self::assertInstanceOf(HitRecorder::class, new Hits());
        self::assertInstanceOf(RedirectLogger::class, new RedirectLog());
    }

    public function testADispatchedRedirectUpsertsTheHitAndInsertsTheLogRow(): void
    {
        $settings = Settings::merge(['log' => ['ip' => 'full']]);
        self::dispatcher($settings)->dispatch(['rule' => self::rule(), 'target' => '/new', 'action' => 'url', 'code' => 301, 'reason' => 'matched'], self::request());

        $upserts = $this->hitUpserts();
        self::assertCount(1, $upserts);
        self::assertStringContainsString("VALUES ('legacy', 'r_0000000a', 1,", $upserts[0]);

        $inserts = $this->callsOf('insert');
        self::assertCount(1, $inserts);
        self::assertSame('wp_proto_redirect_log', $inserts[0][1]);
        $row = $inserts[0][2];
        self::assertSame('/old?x=1', $row['url']);
        self::assertSame('/new', $row['target']);
        self::assertSame(301, $row['code']);
        self::assertSame('legacy', $row['group_slug']);
        self::assertSame('r_0000000a', $row['rule_id']);
        self::assertSame('proto-blocks', $row['redirect_by']);
        self::assertSame('POST', $row['method']);
        self::assertSame('203.0.113.9', $row['ip'], 'the Request object inside the entry feeds the IP policy');
        self::assertSame('Agent/1', $row['agent']);
        self::assertSame('https://ref.test/', $row['referrer']);
    }

    public function testARuleWithLogFalseIsNeitherCountedNorLogged(): void
    {
        foreach (['url' => '/new', 'error' => null, 'pass' => '/inside', 'nothing' => null] as $action => $target) {
            self::dispatcher(Settings::defaults())->dispatch(
                ['rule' => self::rule(['log' => false, 'action' => $action]), 'target' => $target, 'action' => $action, 'code' => $action === 'error' ? 410 : 301, 'reason' => 'matched'],
                self::request()
            );
        }
        self::assertSame([], $this->callsOf('insert'));
        self::assertSame([], $this->callsOf('query'));
    }

    public function testTheEngineRequestReachesThe404Log(): void
    {
        self::dispatcher(Settings::defaults())->log404(Request::fromUrl('http://example.test/missing', ['HTTP_USER_AGENT' => 'Bot']));
        $inserts = $this->callsOf('insert');
        self::assertCount(1, $inserts);
        self::assertSame('wp_proto_404_log', $inserts[0][1]);
        self::assertSame('/missing', $inserts[0][2]['url']);
        self::assertSame('Bot', $inserts[0][2]['agent']);
    }

    public function testTrackHitsFalseStillLogs(): void
    {
        self::dispatcher(Settings::merge(['trackHits' => false]))->dispatch(['rule' => self::rule(), 'target' => '/new', 'action' => 'url', 'code' => 302, 'reason' => 'matched'], self::request());
        self::assertSame([], $this->hitUpserts());
        self::assertCount(1, $this->callsOf('insert'));
    }
}
