<?php

declare(strict_types=1);

namespace ProtoBlocks\Tests\Tailwind;

use PHPUnit\Framework\TestCase;
use ProtoBlocks\Tailwind\BinaryManager;
use ProtoBlocks\Tailwind\DirectoryGuard;

/**
 * The CLI binary is a native executable stored under uploads. It must only
 * ever run when its SHA-256 matches the value recorded at download time.
 */
final class BinaryIntegrityTest extends TestCase
{
    private string $binDir;

    protected function setUp(): void
    {
        $this->binDir = sys_get_temp_dir() . '/pb-integrity-' . uniqid() . '/';
        mkdir($this->binDir, 0777, true);
        $GLOBALS['pb_test_options'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['pb_test_options']);
        $this->removeTree($this->binDir);
    }

    private function removeTree(string $dir): void
    {
        $dir = rtrim($dir, DIRECTORY_SEPARATOR);
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) && !is_link($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function writeBinary(string $contents = "#!/bin/sh\necho \"~ tailwindcss v4.1.0\"\n"): string
    {
        $path = $this->binDir . 'tailwindcss';
        file_put_contents($path, $contents);
        chmod($path, 0755);

        return (string) hash_file('sha256', $path);
    }

    // --- verifyDownload() -------------------------------------------------

    public function test_verify_download_accepts_matching_hash(): void
    {
        $file = $this->binDir . 'dl';
        file_put_contents($file, 'binary-bytes');
        $sha = hash('sha256', 'binary-bytes');

        $result = BinaryManager::verifyDownload($file, strtoupper($sha));

        $this->assertTrue($result['ok']);
        $this->assertSame($sha, $result['sha256']);
    }

    public function test_verify_download_rejects_mismatching_hash(): void
    {
        $file = $this->binDir . 'dl';
        file_put_contents($file, 'binary-bytes');

        $result = BinaryManager::verifyDownload($file, str_repeat('0', 64));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('mismatch', $result['message']);
    }

    public function test_verify_download_fails_closed_without_a_known_hash(): void
    {
        $file = $this->binDir . 'dl';
        file_put_contents($file, 'binary-bytes');

        $this->assertFalse(BinaryManager::verifyDownload($file, null)['ok']);
        $this->assertFalse(BinaryManager::verifyDownload($file, '')['ok']);
    }

    public function test_verify_download_accepts_unknown_hash_only_when_explicitly_allowed(): void
    {
        $file = $this->binDir . 'dl';
        file_put_contents($file, 'binary-bytes');

        $result = BinaryManager::verifyDownload($file, null, true);

        $this->assertTrue($result['ok']);
        $this->assertSame(hash('sha256', 'binary-bytes'), $result['sha256']);
    }

    public function test_verify_download_rejects_missing_file(): void
    {
        $this->assertFalse(BinaryManager::verifyDownload($this->binDir . 'nope', str_repeat('a', 64))['ok']);
    }

    public function test_parse_digest_reads_github_asset_digest(): void
    {
        $hex = str_repeat('ab', 32);

        $this->assertSame($hex, BinaryManager::parseDigest('sha256:' . strtoupper($hex)));
        $this->assertNull(BinaryManager::parseDigest('md5:' . $hex));
        $this->assertNull(BinaryManager::parseDigest('sha256:short'));
        $this->assertNull(BinaryManager::parseDigest(null));
    }

    // --- trust before exec ------------------------------------------------

    public function test_binary_without_recorded_hash_is_unverified_and_never_run(): void
    {
        $this->writeBinary();
        $bm = new BinaryManager($this->binDir); // no injected / stored hash

        $this->assertTrue($bm->isInstalled());
        $this->assertSame(BinaryManager::TRUST_UNVERIFIED, $bm->getTrustState());
        $this->assertFalse($bm->isTrusted());
        $this->assertNull($bm->getVersion(), 'an unverified binary must not be executed');
        $this->assertFalse($bm->isFunctional());
    }

    public function test_binary_matching_recorded_hash_is_trusted_and_runs(): void
    {
        $sha = $this->writeBinary();
        $bm = new BinaryManager($this->binDir, $sha);

        $this->assertSame(BinaryManager::TRUST_TRUSTED, $bm->getTrustState());
        $this->assertSame('4.1.0', $bm->getVersion());
    }

    public function test_tampered_binary_is_refused(): void
    {
        $sha = $this->writeBinary();
        $bm = new BinaryManager($this->binDir, $sha);
        $this->assertTrue($bm->isTrusted());

        // Replace the file the way an uploads-write attacker would. Bump the
        // mtime so the per-request memo (size|mtime|inode) cannot mask it.
        $path = $this->binDir . 'tailwindcss';
        file_put_contents($path, "#!/bin/sh\necho \"~ tailwindcss v4.1.0\"\ntouch " . escapeshellarg($this->binDir . 'pwned') . "\n");
        touch($path, time() + 5);

        $this->assertSame(BinaryManager::TRUST_MISMATCH, $bm->getTrustState());
        $this->assertNull($bm->getVersion());
        $this->assertFileDoesNotExist($this->binDir . 'pwned', 'the tampered binary must not have been executed');
    }

    public function test_recorded_hash_is_read_from_the_settings_option(): void
    {
        $sha = $this->writeBinary();
        $GLOBALS['pb_test_options']['proto_blocks_tailwind'] = ['cli_sha256' => $sha];

        $bm = new BinaryManager($this->binDir);

        $this->assertSame($sha, $bm->getExpectedSha256());
        $this->assertTrue($bm->isTrusted());
    }

    public function test_missing_binary_reports_missing(): void
    {
        $bm = new BinaryManager($this->binDir, str_repeat('a', 64));

        $this->assertSame(BinaryManager::TRUST_MISSING, $bm->getTrustState());
        $this->assertFalse($bm->isTrusted());
    }

    // --- directory guard --------------------------------------------------

    public function test_deny_all_guard_writes_index_and_htaccess(): void
    {
        DirectoryGuard::denyAll($this->binDir);

        $this->assertFileExists($this->binDir . 'index.php');
        $this->assertFileExists($this->binDir . '.htaccess');
        $htaccess = (string) file_get_contents($this->binDir . '.htaccess');
        $this->assertStringContainsString('Require all denied', $htaccess);
        $this->assertStringContainsString('Deny from all', $htaccess);
        $this->assertStringNotContainsString('granted', $htaccess);
    }
}
