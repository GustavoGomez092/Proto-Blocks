<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects;

use ProtoBlocks\ContentModel\FileLoader;
use ProtoBlocks\ContentModel\Issue;
use ProtoBlocks\ContentModel\Registry as ContentModelRegistry;
use ProtoBlocks\Modules\Redirects\Coexistence;
use ProtoBlocks\Modules\Redirects\Engine\Conditions\Ip;
use ProtoBlocks\Modules\Redirects\Engine\FrontController;
use ProtoBlocks\Modules\Redirects\Engine\Request;
use ProtoBlocks\Modules\Redirects\Monitor;
use ProtoBlocks\Modules\Redirects\RedirectsModule;
use ProtoBlocks\Modules\Redirects\Storage\ExternalRedirects;
use ProtoBlocks\Modules\Redirects\Storage\Installer;
use ProtoBlocks\Modules\Redirects\Storage\Retention;

/**
 * RedirectsModule::boot() wiring: tables, retention cron, IP resolver,
 * front controller (or not, under coexistence), monitor, issues, and the
 * activation/deactivation statics.
 */
final class ModuleBootTest extends RedirectsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb'] = new \wpdb();
        $GLOBALS['pb_test_options'] = [];
        $GLOBALS['pb_test_cron'] = [];
        $GLOBALS['pb_test_actions'] = [];
        $GLOBALS['pb_test_dbdelta'] = [];
        Coexistence::setDetector(static fn(): bool => false);
    }

    protected function tearDown(): void
    {
        Coexistence::setDetector(null);
        Ip::setResolver(null);
        $GLOBALS['wpdb'] = new \wpdb();
        $GLOBALS['pb_test_options'] = [];
        $GLOBALS['pb_test_cron'] = [];
        $GLOBALS['pb_test_actions'] = [];
        unset($GLOBALS['pb_test_dbdelta']);
        parent::tearDown();
    }

    private function settingsFile(array $settings): void
    {
        $this->put($this->child, 'proto-redirects/settings.jsonc', (string) json_encode($settings, JSON_UNESCAPED_SLASHES));
    }

    private static function booted(): RedirectsModule
    {
        $module = new RedirectsModule(new ContentModelRegistry(new FileLoader(), static fn(): array => []));
        $module->boot();

        return $module;
    }

    /** @return list<mixed> callbacks hooked to $hook */
    private static function callbacks(string $hook): array
    {
        return array_column($GLOBALS['pb_test_hooks'][$hook] ?? [], 'callback');
    }

    private static function hookedTo(string $hook, string $class): array
    {
        return array_values(array_filter(self::callbacks($hook), static fn($cb): bool => is_array($cb) && $cb[0] instanceof $class));
    }

    public function testTablesWaitForPluginsLoaded(): void
    {
        self::booted();
        $hooked = self::hookedTo('plugins_loaded', Installer::class);
        self::assertCount(1, $hooked);
        self::assertSame('ensure', $hooked[0][1]);
        self::assertSame([], $GLOBALS['pb_test_dbdelta']);
    }

    public function testTablesAreEnsuredAtOnceWhenPluginsLoadedAlreadyRan(): void
    {
        $GLOBALS['pb_test_actions']['plugins_loaded'] = [[]];
        self::booted();
        self::assertSame([], self::hookedTo('plugins_loaded', Installer::class));
        self::assertNotSame([], $GLOBALS['pb_test_dbdelta'], 'dbDelta ran');
    }

    public function testRetentionIsHookedAndScheduledOnce(): void
    {
        self::booted();
        self::booted();
        self::assertCount(2, self::hookedTo(Retention::HOOK, Retention::class));
        self::assertCount(1, $GLOBALS['pb_test_cron'][Retention::HOOK]);
        self::assertSame('daily', $GLOBALS['pb_test_cron'][Retention::HOOK][0]['recurrence']);
    }

    public function testFrontControllerHooksAreRegistered(): void
    {
        $module = self::booted();
        self::assertInstanceOf(FrontController::class, $module->frontController());
        foreach (['init', 'template_redirect', 'send_headers', 'redirect_canonical'] as $hook) {
            self::assertCount(1, self::hookedTo($hook, FrontController::class), $hook);
        }
    }

    public function testCoexistencePausesTheFrontEndOnly(): void
    {
        Coexistence::setDetector(static fn(): bool => true);
        $this->settingsFile(['monitor' => ['postTypes' => ['post']]]);
        $module = self::booted();

        self::assertTrue($module->paused());
        self::assertNull($module->frontController());
        self::assertSame([], self::hookedTo('init', FrontController::class));
        self::assertSame([], self::hookedTo('template_redirect', FrontController::class));
        self::assertInstanceOf(Monitor::class, $module->monitor(), 'the monitor writes files, it does not redirect');
        self::assertCount(1, self::hookedTo(Retention::HOOK, Retention::class));
    }

    public function testExternalRedirectLoggingIsHookedOnlyWhenLogExternalIsOn(): void
    {
        self::booted();
        self::assertSame([], self::hookedTo('x_redirect_by', ExternalRedirects::class));

        $this->settingsFile(['log' => ['external' => true]]);
        self::booted();
        self::assertCount(1, self::hookedTo('x_redirect_by', ExternalRedirects::class));
        self::assertSame(100, $GLOBALS['pb_test_hooks']['x_redirect_by'][0]['priority']);
    }

    public function testExternalRedirectLoggingStaysOffWhileCoexistencePauses(): void
    {
        Coexistence::setDetector(static fn(): bool => true);
        $this->settingsFile(['log' => ['external' => true]]);
        self::booted();
        self::assertSame([], self::hookedTo('x_redirect_by', ExternalRedirects::class));
    }

    public function testCoexistTrueKeepsTheFrontEndWithRedirectionActive(): void
    {
        Coexistence::setDetector(static fn(): bool => true);
        $this->settingsFile(['coexist' => true]);
        self::assertInstanceOf(FrontController::class, self::booted()->frontController());
    }

    public function testIpConditionResolvesThroughTrustedProxies(): void
    {
        $this->settingsFile(['log' => ['trustedProxies' => ['10.0.0.0/8'], 'ipHeaders' => ['x-forwarded-for']]]);
        self::booted();

        $proxied = Request::fromUrl('http://example.test/a', ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1, 203.0.113.7']);
        self::assertSame('203.0.113.7', Ip::clientIp($proxied));
        $spoofed = Request::fromUrl('http://example.test/a', ['REMOTE_ADDR' => '192.0.2.50', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7']);
        self::assertSame('192.0.2.50', Ip::clientIp($spoofed), 'an untrusted address cannot claim another client IP');
    }

    public function testMonitorIsWiredOnlyWithWatchedPostTypes(): void
    {
        self::assertNull(self::booted()->monitor());
        self::assertSame([], self::hookedTo('post_updated', Monitor::class));

        $GLOBALS['pb_test_hooks'] = [];
        $this->settingsFile(['monitor' => ['postTypes' => ['post', 'page']]]);
        $module = self::booted();
        self::assertInstanceOf(Monitor::class, $module->monitor());
        self::assertCount(1, self::hookedTo('post_updated', Monitor::class));
    }

    public function testIssuesMergeRegistryAndMonitorFindings(): void
    {
        $this->settingsFile(['monitor' => ['postTypes' => ['post']]]);
        $this->put($this->child, 'proto-redirects/legacy/redirects.jsonc', self::groupText([
            ['from' => '/a', 'to' => '/b'],
            ['from' => '/b', 'to' => '/c'],
        ]));
        $GLOBALS['pb_test_options'][Monitor::ISSUES_OPTION] = [
            ['level' => Issue::WARNING, 'code' => 'monitor_unwritable', 'message' => 'Not writable', 'source' => '', 'slug' => 'modified-posts'],
        ];

        $codes = self::issueCodes(self::booted()->issues());
        self::assertContains('warning:redirect_chain', $codes);
        self::assertContains('warning:monitor_unwritable', $codes);
    }

    public function testMonitorNoticesAreNotReportedWhileTheMonitorIsOff(): void
    {
        $GLOBALS['pb_test_options'][Monitor::ISSUES_OPTION] = [
            ['level' => Issue::WARNING, 'code' => 'monitor_unwritable', 'message' => 'Old', 'source' => '', 'slug' => 'modified-posts'],
        ];
        self::assertNotContains('warning:monitor_unwritable', self::issueCodes(self::booted()->issues()));
    }

    public function testPhpDeclarationsAfterBootRebuildTheRegistryAtTheSeal(): void
    {
        $module = self::booted();
        self::assertSame([], $module->registry()->enabledRules());

        proto_redirect('/late', '/there');
        \ProtoBlocks\Modules\Redirects\Sources\PhpDeclarations::seal();
        $module->onSealed();

        self::assertSame(['/late'], array_column($module->registry()->enabledRules(), 'from'));
    }

    public function testTheSealKeepsTheBuildWhenNothingWasDeclaredLate(): void
    {
        proto_redirect('/early', '/there');
        $module = self::booted();
        $before = $module->registry()->enabledRules();
        $built = new \ReflectionProperty(\ProtoBlocks\Modules\Redirects\Registry::class, 'built');
        $module->onSealed();
        self::assertNotNull($built->getValue($module->registry()));
        self::assertSame($before, $module->registry()->enabledRules());
    }

    public function testTheSealHookRunsAfterPhpDeclarationsAreSealed(): void
    {
        $module = self::booted();
        $init = array_values(array_filter($GLOBALS['pb_test_hooks']['init'], static fn(array $e): bool => $e['priority'] === RedirectsModule::SEAL_PRIORITY));
        self::assertSame([\ProtoBlocks\Modules\Redirects\Sources\PhpDeclarations::class, 'seal'], $init[0]['callback']);
        self::assertSame([$module, 'onSealed'], $init[1]['callback']);
    }

    public function testBootOnAnAdminRequestReadsOnlyTheSettingsFile(): void
    {
        $this->settingsFile(['log' => ['ip' => 'full']]);
        for ($i = 0; $i < 50; $i++) {
            $this->put($this->child, 'proto-redirects/g' . $i . '/redirects.jsonc', self::groupText([['from' => '/old-' . $i, 'to' => '/new-' . $i]]));
        }
        $GLOBALS['pb_test_is_admin'] = true;
        try {
            $module = self::booted();
        } finally {
            unset($GLOBALS['pb_test_is_admin']);
        }

        self::assertSame('full', $module->settings()['log']['ip'], 'the settings were read');
        self::assertSame(0, $module->registry()->buildCount(), 'no group was built');
        $files = (new \ReflectionProperty(\ProtoBlocks\Modules\Redirects\Registry::class, 'files'))->getValue($module->registry());
        self::assertNull((new \ReflectionProperty(\ProtoBlocks\Modules\Redirects\Sources\GroupFiles::class, 'files'))->getValue($files), 'no group file was opened');
    }

    public function testTheFrontControllersFirstMatchBuildsTheRegistryOnce(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->put($this->child, 'proto-redirects/g' . $i . '/redirects.jsonc', self::groupText([['from' => '/old-' . $i, 'to' => '/new-' . $i]]));
        }
        $server = $_SERVER;
        $_SERVER = ['REQUEST_URI' => '/nothing-here', 'HTTP_HOST' => 'example.test', 'REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.5'];
        try {
            $module = self::booted();
            self::assertSame(0, $module->registry()->buildCount());

            $module->frontController()?->onInit();
            self::assertSame(1, $module->registry()->buildCount());

            $GLOBALS['pb_test_query']['is_404'] = true;
            $module->frontController()?->onTemplateRedirect();
            $module->issues();
            $module->settings();
            self::assertSame(1, $module->registry()->buildCount(), 'one build per request');
        } finally {
            $_SERVER = $server;
            unset($GLOBALS['pb_test_query']['is_404']);
        }
    }

    public function testActivateEnsuresTheTables(): void
    {
        RedirectsModule::activate();
        self::assertNotSame([], $GLOBALS['pb_test_dbdelta']);
    }

    public function testDeactivateUnschedulesTheRetentionCron(): void
    {
        self::booted();
        $GLOBALS['pb_test_cron'][Retention::HOOK][] = ['timestamp' => 1, 'recurrence' => null, 'args' => Retention::FOLLOW_UP_ARGS];
        RedirectsModule::deactivate();
        self::assertArrayNotHasKey(Retention::HOOK, $GLOBALS['pb_test_cron']);
    }
}
