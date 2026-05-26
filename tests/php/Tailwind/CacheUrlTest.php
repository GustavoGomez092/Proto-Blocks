<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Tailwind;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Tailwind\Cache;

/**
 * The uploads baseurl (from wp_upload_dir) is http in the test bootstrap, mirroring
 * an SSL-terminating host like WP Engine where is_ssl() is false behind the proxy.
 */
final class CacheUrlTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['pb_test_options']);
    }

    public function test_url_is_forced_to_https_when_site_is_https(): void
    {
        $GLOBALS['pb_test_options']['home'] = 'https://example.test';

        $url = (new Cache())->getUrl();

        $this->assertStringStartsWith('https://', $url);
        $this->assertStringContainsString('tailwind.css', $url);
    }

    public function test_url_left_http_when_site_is_http(): void
    {
        $GLOBALS['pb_test_options']['home'] = 'http://example.test';

        $url = (new Cache())->getUrl();

        $this->assertStringStartsWith('http://', $url);
    }
}
