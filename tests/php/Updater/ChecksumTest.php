<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Updater;

require_once __DIR__ . '/stubs.php';

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Updater\GitHubUpdater;

final class ChecksumTest extends TestCase
{
    private const HEX = 'a3f1c2d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    /** @var string[] */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        $this->tmp = [];
        unset($GLOBALS['pb_test_filters']);
    }

    private function tmpFile(string $contents): string
    {
        $f = tempnam(sys_get_temp_dir(), 'pb-zip-');
        file_put_contents($f, $contents);
        $this->tmp[] = $f;
        return $f;
    }

    // ---- extraction -------------------------------------------------------

    public function test_extracts_sha256_line_from_release_body(): void
    {
        $body = "## What's Changed\n- fix: thing by @x in #12\n\n---\nsha256: " . self::HEX . "\n";
        $this->assertSame(self::HEX, GitHubUpdater::extract_sha256($body));
    }

    public function test_extraction_is_case_insensitive_and_lowercases(): void
    {
        $upper = strtoupper(self::HEX);
        $this->assertSame(self::HEX, GitHubUpdater::extract_sha256('SHA256:   ' . $upper));
    }

    public function test_extraction_rejects_missing_or_short_hashes(): void
    {
        $this->assertSame('', GitHubUpdater::extract_sha256('no hash here'));
        $this->assertSame('', GitHubUpdater::extract_sha256('sha256: ' . substr(self::HEX, 0, 63)));
        $this->assertSame('', GitHubUpdater::extract_sha256('sha256: ' . substr(self::HEX, 0, 63) . 'g'));
        $this->assertSame('', GitHubUpdater::extract_sha256(''));
    }

    public function test_extraction_requires_a_label_in_release_body(): void
    {
        // A bare hex blob in prose is not a checksum declaration.
        $this->assertSame('', GitHubUpdater::extract_sha256('commit ' . self::HEX));
    }

    public function test_sidecar_accepts_sha256sum_output_bare_hash_and_label(): void
    {
        $this->assertSame(self::HEX, GitHubUpdater::extract_sha256_from_sidecar(self::HEX . "  proto-blocks-2.15.0.zip\n"));
        $this->assertSame(self::HEX, GitHubUpdater::extract_sha256_from_sidecar(self::HEX));
        $this->assertSame(self::HEX, GitHubUpdater::extract_sha256_from_sidecar('sha256: ' . self::HEX));
        $this->assertSame('', GitHubUpdater::extract_sha256_from_sidecar("<html>Not Found</html>"));
    }

    // ---- verification -----------------------------------------------------

    public function test_matching_checksum_passes(): void
    {
        $file = $this->tmpFile('PK fake zip payload');
        $this->assertTrue(GitHubUpdater::verify_package_file($file, hash_file('sha256', $file), false));
        $this->assertTrue(GitHubUpdater::verify_package_file($file, strtoupper(hash_file('sha256', $file)), true));
    }

    public function test_mismatched_checksum_fails(): void
    {
        $file = $this->tmpFile('PK fake zip payload');
        $r = GitHubUpdater::verify_package_file($file, self::HEX, false);
        $this->assertInstanceOf(\WP_Error::class, $r);
        $this->assertSame('proto_blocks_bad_checksum', $r->get_error_code());
    }

    public function test_malformed_expected_checksum_fails(): void
    {
        $file = $this->tmpFile('x');
        $r = GitHubUpdater::verify_package_file($file, 'not-a-hash', false);
        $this->assertInstanceOf(\WP_Error::class, $r);
        $this->assertSame('proto_blocks_bad_checksum_format', $r->get_error_code());
    }

    public function test_unreadable_file_fails_when_checksum_expected(): void
    {
        $r = GitHubUpdater::verify_package_file(sys_get_temp_dir() . '/pb-does-not-exist-' . uniqid() . '.zip', self::HEX, false);
        $this->assertInstanceOf(\WP_Error::class, $r);
        $this->assertSame('proto_blocks_unreadable_package', $r->get_error_code());
    }

    public function test_missing_checksum_is_allowed_by_default(): void
    {
        $file = $this->tmpFile('older release without checksum');
        $this->assertTrue(GitHubUpdater::verify_package_file($file, '', false));
    }

    public function test_missing_checksum_is_rejected_when_required(): void
    {
        $file = $this->tmpFile('older release without checksum');
        $r = GitHubUpdater::verify_package_file($file, '', true);
        $this->assertInstanceOf(\WP_Error::class, $r);
        $this->assertSame('proto_blocks_no_checksum', $r->get_error_code());
    }

    // ---- filter -----------------------------------------------------------

    public function test_require_checksum_filter_defaults_false_and_can_opt_in(): void
    {
        $this->assertFalse(GitHubUpdater::checksum_required());

        $GLOBALS['pb_test_filters'][GitHubUpdater::REQUIRE_CHECKSUM_FILTER][] = static fn($v) => true;
        $this->assertTrue(GitHubUpdater::checksum_required());
        $this->assertSame('proto_blocks_require_update_checksum', GitHubUpdater::REQUIRE_CHECKSUM_FILTER);
    }
}
