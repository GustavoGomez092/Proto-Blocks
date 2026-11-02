<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Security;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Security\AttributeSanitizer;

require_once __DIR__ . '/wp-stubs.php';

final class PostTrustTest extends TestCase
{
    protected function setUp(): void
    {
        // wp-stubs.php's user_can() reads this registry: user 1 is an admin.
        $GLOBALS['pb_test_caps'] = [1 => ['unfiltered_html'], 2 => []];
    }

    public function test_recorded_trust_wins_over_author(): void
    {
        // Admin (id 1) saved a contributor's post: trusted.
        self::assertTrue(AttributeSanitizer::isPostTrusted('1', 2));
        // Contributor (id 2) re-saved an admin's post: untrusted.
        self::assertFalse(AttributeSanitizer::isPostTrusted('0', 1));
    }

    public function test_legacy_post_falls_back_to_author_capability(): void
    {
        self::assertTrue(AttributeSanitizer::isPostTrusted('', 1));
        self::assertFalse(AttributeSanitizer::isPostTrusted('', 2));
        self::assertFalse(AttributeSanitizer::isPostTrusted('', 0));
    }
}
