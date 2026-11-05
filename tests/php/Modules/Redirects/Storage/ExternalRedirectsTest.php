<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Modules\Redirects\Storage;

use ProtoBlocks\Modules\Redirects\Storage\ExternalRedirects;
use ProtoBlocks\Modules\Redirects\Storage\RedirectLog;

/** `log.external`: redirects issued by other code are logged through the x_redirect_by filter. */
final class ExternalRedirectsTest extends StorageTestCase
{
    /** @param array<string, mixed> $log */
    private static function external(array $log = []): ExternalRedirects
    {
        return new ExternalRedirects(new RedirectLog(['log' => $log + ['external' => true]]));
    }

    public function testRegistersOnXRedirectByAtPriority100WithThreeArgs(): void
    {
        $external = self::external();
        $external->register();
        $entry = $GLOBALS['pb_test_hooks']['x_redirect_by'][0];

        self::assertSame([$external, 'onRedirectBy'], $entry['callback']);
        self::assertSame(100, $entry['priority']);
        self::assertSame(3, $entry['args']);
    }

    public function testLogsAnotherAgentsRedirectAndReturnsTheAgentUnchanged(): void
    {
        $_SERVER['REQUEST_URI'] = '/shop/old';
        self::assertSame('WooCommerce', self::external()->onRedirectBy('WooCommerce', 302, 'https://example.test/cart/'));

        $inserts = $this->callsOf('insert');
        self::assertCount(1, $inserts);
        $row = $inserts[0][2];
        self::assertSame('woocommerce', $row['redirect_by']);
        self::assertSame(302, $row['code']);
        self::assertSame('https://example.test/cart/', $row['target']);
        self::assertNull($row['group_slug']);
        self::assertNull($row['rule_id']);
        self::assertSame('/shop/old', $row['url']);
        unset($_SERVER['REQUEST_URI']);
    }

    public function testOwnRedirectsAreNotLoggedTwice(): void
    {
        self::assertSame('proto-blocks', self::external()->onRedirectBy('proto-blocks', 301, '/new'));
        self::assertSame('Proto-Blocks', self::external()->onRedirectBy('Proto-Blocks', 301, '/new'));
        self::assertSame([], $this->callsOf('insert'));
    }

    public function testAgentIsCutAt50Characters(): void
    {
        self::external()->onRedirectBy(str_repeat('A', 80), 301, '/x');
        self::assertSame(str_repeat('a', 50), $this->callsOf('insert')[0][2]['redirect_by']);
    }

    public function testNonStringOrEmptyAgentIsNotLogged(): void
    {
        self::assertFalse(self::external()->onRedirectBy(false, 302, '/x'));
        self::assertSame('  ', self::external()->onRedirectBy('  ', 302, '/x'));
        self::assertSame([], $this->callsOf('insert'));
    }

    public function testHonoursTheRedirectLogRetentionPolicy(): void
    {
        self::assertSame('WordPress', self::external(['redirects' => -1])->onRedirectBy('WordPress', 301, '/x'));
        self::assertSame([], $this->callsOf('insert'));
    }
}
