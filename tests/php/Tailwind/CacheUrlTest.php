<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Tailwind;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Tailwind\Cache;

/**
 * The uploads baseurl (from wp_upload_dir) is http in the test bootstrap, mirroring
 * an SSL-terminating host like WP Engine where WordPress reports an http scheme even
 * though the site is served over https. getUrl() must not emit that http scheme.
 */
final class CacheUrlTest extends TestCase
{
    public function test_url_is_protocol_relative(): void
    {
        $url = (new Cache())->getUrl();

        // Protocol-relative: the browser uses the page's scheme, so it can never
        // be blocked as mixed content on an https page.
        $this->assertStringStartsWith('//', $url);
        $this->assertStringNotContainsString('http://', $url);
        $this->assertStringNotContainsString('https://', $url);
        $this->assertStringContainsString('tailwind.css', $url);
    }
}
