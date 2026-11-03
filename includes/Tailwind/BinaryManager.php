<?php
/**
 * Binary Manager - Downloads and manages Tailwind CLI binary
 *
 * Integrity model
 * ---------------
 * The binary is a native executable that PHP runs as the web user, and it is
 * stored under wp-content/uploads — the most writable directory on a
 * WordPress host. So:
 *
 *  - A download is only accepted when its SHA-256 matches a known value: the
 *    `digest` GitHub publishes with each release asset, or the pinned
 *    PROTO_BLOCKS_TAILWIND_CLI_SHA256 constant. Without either it fails closed
 *    unless PROTO_BLOCKS_TAILWIND_ALLOW_UNVERIFIED is true.
 *  - The accepted hash is stored (option `cli_sha256`) and the binary is
 *    re-hashed before EVERY execution. A file that no longer matches is
 *    refused and the UI asks for a re-download.
 *  - The bin directory is guarded with index.php + a deny-all .htaccess.
 *
 * Pinning (wp-config.php):
 *   define('PROTO_BLOCKS_TAILWIND_CLI_VERSION', '4.1.11');
 *   define('PROTO_BLOCKS_TAILWIND_CLI_SHA256', '<sha256 of the asset for this platform>');
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Tailwind;

/**
 * Manages the Tailwind CSS standalone CLI binary
 */
class BinaryManager
{
    /**
     * GitHub releases API base
     */
    private const GITHUB_API_BASE = 'https://api.github.com/repos/tailwindlabs/tailwindcss/releases';

    /**
     * Binary download base URL
     */
    private const DOWNLOAD_BASE_URL = 'https://github.com/tailwindlabs/tailwindcss/releases/download';

    /**
     * Seconds the version probe may run before it is killed.
     */
    private const VERSION_TIMEOUT = 20;

    public const TRUST_MISSING = 'missing';
    public const TRUST_TRUSTED = 'trusted';
    public const TRUST_UNVERIFIED = 'unverified';
    public const TRUST_MISMATCH = 'mismatch';

    /**
     * Binary directory
     */
    private string $binDir;

    /**
     * Explicit expected SHA-256 (tests / DI). Null = resolve from constant/option.
     */
    private ?string $expectedSha256;

    /**
     * Per-request memo of the trust check, keyed by file identity.
     *
     * @var array<string, bool>
     */
    private array $trustMemo = [];

    /**
     * Constructor
     */
    public function __construct(?string $binDir = null, ?string $expectedSha256 = null)
    {
        $this->expectedSha256 = $expectedSha256 !== null ? strtolower($expectedSha256) : null;

        if ($binDir !== null) {
            $this->binDir = rtrim($binDir, '/\\') . '/';
            return;
        }

        $uploadDir = \wp_upload_dir();
        $this->binDir = $uploadDir['basedir'] . '/proto-blocks/bin/';
    }

    /**
     * Check if the binary file is present.
     *
     * This only confirms the file exists with the executable bit — NOT that it
     * actually runs or that it is the file we downloaded. Use isFunctional()
     * (which implies isTrusted()) before relying on it.
     */
    public function isInstalled(): bool
    {
        $binaryPath = $this->getBinaryPath();
        return file_exists($binaryPath) && is_executable($binaryPath);
    }

    /**
     * Check if a usable binary is present — it exists, is executable, matches
     * the recorded hash AND its version can actually be read.
     */
    public function isFunctional(): bool
    {
        return $this->isInstalled() && $this->getVersion() !== null;
    }

    /**
     * Whether PHP can execute shell commands. False on managed hosts (e.g.
     * WP Engine) that disable exec/shell_exec — those must use browser compile.
     */
    public function isShellAvailable(): bool
    {
        return function_exists('exec') && $this->isExecAvailable();
    }

    /**
     * Get binary path
     */
    public function getBinaryPath(): string
    {
        return $this->binDir . 'tailwindcss';
    }

    // ------------------------------------------------------------------
    // Integrity
    // ------------------------------------------------------------------

    /**
     * The SHA-256 the on-disk binary must match, or null when none is known.
     *
     * Resolution order: constructor injection, PROTO_BLOCKS_TAILWIND_CLI_SHA256
     * constant, the `cli_sha256` recorded at download time.
     */
    public function getExpectedSha256(): ?string
    {
        if ($this->expectedSha256 !== null) {
            return $this->expectedSha256;
        }

        $pinned = self::getPinnedSha256();
        if ($pinned !== null) {
            return $pinned;
        }

        $stored = Manager::getInstance()->getSettings()['cli_sha256'] ?? null;
        if (is_string($stored) && preg_match('/^[a-f0-9]{64}$/i', $stored)) {
            return strtolower($stored);
        }

        return null;
    }

    /**
     * Whether the on-disk binary matches the expected hash.
     *
     * Called before every exec. Memoised per request on (size, mtime, inode)
     * so a settings-page render does not hash a 100 MB file several times.
     */
    public function isTrusted(): bool
    {
        return $this->getTrustState() === self::TRUST_TRUSTED;
    }

    /**
     * One of the TRUST_* constants.
     */
    public function getTrustState(): string
    {
        $path = $this->getBinaryPath();
        if (!is_file($path)) {
            return self::TRUST_MISSING;
        }

        $expected = $this->getExpectedSha256();
        if ($expected === null) {
            return self::TRUST_UNVERIFIED;
        }

        $stat = @stat($path);
        $key = $expected . '|' . ($stat ? $stat['size'] . '|' . $stat['mtime'] . '|' . $stat['ino'] : 'nostat');

        if (!array_key_exists($key, $this->trustMemo)) {
            $actual = @hash_file('sha256', $path);
            $this->trustMemo = [$key => is_string($actual) && hash_equals($expected, strtolower($actual))];
        }

        return $this->trustMemo[$key] ? self::TRUST_TRUSTED : self::TRUST_MISMATCH;
    }

    /**
     * Verify a downloaded file against an expected SHA-256.
     *
     * Pure function (no WP calls) so it can be unit-tested with a temp file.
     *
     * @param string|null $expectedSha256  Known-good hash, or null when the
     *                                     release metadata had none.
     * @param bool        $allowUnverified Accept the file when no hash is known.
     * @return array{ok: bool, sha256: string|null, message: string}
     */
    public static function verifyDownload(string $file, ?string $expectedSha256, bool $allowUnverified = false): array
    {
        if (!is_file($file)) {
            return ['ok' => false, 'sha256' => null, 'message' => 'Downloaded file is missing.'];
        }

        $actual = @hash_file('sha256', $file);
        if (!is_string($actual)) {
            return ['ok' => false, 'sha256' => null, 'message' => 'Could not hash the downloaded file.'];
        }
        $actual = strtolower($actual);

        if ($expectedSha256 === null || $expectedSha256 === '') {
            if ($allowUnverified) {
                return ['ok' => true, 'sha256' => $actual, 'message' => 'Accepted without verification (PROTO_BLOCKS_TAILWIND_ALLOW_UNVERIFIED).'];
            }

            return [
                'ok' => false,
                'sha256' => $actual,
                'message' => 'The release did not publish a checksum for this asset, so the download was refused. '
                    . 'Pin PROTO_BLOCKS_TAILWIND_CLI_VERSION + PROTO_BLOCKS_TAILWIND_CLI_SHA256, '
                    . 'or set PROTO_BLOCKS_TAILWIND_ALLOW_UNVERIFIED to true to accept it anyway.',
            ];
        }

        if (!hash_equals(strtolower($expectedSha256), $actual)) {
            return [
                'ok' => false,
                'sha256' => $actual,
                'message' => sprintf('Checksum mismatch: expected %s, got %s.', $expectedSha256, $actual),
            ];
        }

        return ['ok' => true, 'sha256' => $actual, 'message' => 'Checksum verified.'];
    }

    /**
     * Extract the hex digest from a GitHub asset `digest` ("sha256:<hex>").
     */
    public static function parseDigest($digest): ?string
    {
        if (!is_string($digest) || !preg_match('/^sha256:([a-f0-9]{64})$/i', trim($digest), $m)) {
            return null;
        }

        return strtolower($m[1]);
    }

    /**
     * Pinned CLI version from wp-config, normalised without the leading "v".
     */
    public static function getPinnedVersion(): ?string
    {
        if (!defined('PROTO_BLOCKS_TAILWIND_CLI_VERSION')) {
            return null;
        }
        $v = ltrim(trim((string) \PROTO_BLOCKS_TAILWIND_CLI_VERSION), 'v');

        return preg_match('/^\d+\.\d+\.\d+/', $v) ? $v : null;
    }

    /**
     * Pinned CLI SHA-256 from wp-config.
     */
    public static function getPinnedSha256(): ?string
    {
        if (!defined('PROTO_BLOCKS_TAILWIND_CLI_SHA256')) {
            return null;
        }
        $h = strtolower(trim((string) \PROTO_BLOCKS_TAILWIND_CLI_SHA256));

        return preg_match('/^[a-f0-9]{64}$/', $h) ? $h : null;
    }

    /**
     * Whether unverified downloads are accepted (opt-in constant).
     */
    public static function allowsUnverifiedDownloads(): bool
    {
        return defined('PROTO_BLOCKS_TAILWIND_ALLOW_UNVERIFIED') && (bool) \PROTO_BLOCKS_TAILWIND_ALLOW_UNVERIFIED;
    }

    // ------------------------------------------------------------------
    // Version probe
    // ------------------------------------------------------------------

    /**
     * Get installed version (null when missing, untrusted, or not runnable).
     */
    public function getVersion(): ?string
    {
        if (!$this->isInstalled() || !$this->isTrusted()) {
            return null;
        }

        $binaryPath = $this->getBinaryPath();

        // Tailwind v4's CLI has no `--version` flag. Passing one makes it fall
        // through to a full build, scanning every file under the current working
        // directory for class names. When PHP's cwd is a site root or home
        // directory, that walk can pull tens of gigabytes into memory and wedge
        // the machine. `--help` prints the version on its first line
        // ("~ tailwindcss v4.3.3") and exits immediately.
        //
        // Running from an empty scratch directory is belt-and-braces: if a future
        // CLI ever scans again, it finds nothing instead of walking the disk.
        $scratch = $this->getVersionScratchDir();

        $result = ProcessRunner::run(
            escapeshellarg($binaryPath) . ' --help',
            self::VERSION_TIMEOUT,
            $scratch
        );

        foreach (explode("\n", $result['output']) as $line) {
            if (preg_match('/v?(\d+\.\d+\.\d+)/', $line, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * An empty directory to run the version check from.
     *
     * Tailwind's CLI scans its working directory, so pointing it at an empty
     * scratch dir keeps a stray scan from walking the whole filesystem.
     */
    private function getVersionScratchDir(): string
    {
        $dir = $this->binDir . '.version-check';

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return is_dir($dir) ? $dir : sys_get_temp_dir();
    }

    // ------------------------------------------------------------------
    // Release lookup
    // ------------------------------------------------------------------

    /**
     * Resolve which release to install and its asset checksum.
     *
     * With both pin constants set no network lookup is needed. With only the
     * version pinned, the tag's release is queried for the asset digest.
     * Otherwise `releases/latest` is used.
     *
     * @return array{version: string|null, download_url: string|null, sha256: string|null, error: string|null}
     */
    public function getReleaseInfo(): array
    {
        $binaryName = $this->getBinaryNameForPlatform();
        if (!$binaryName) {
            return ['version' => null, 'download_url' => null, 'sha256' => null, 'error' => \__('Unsupported platform', 'proto-blocks')];
        }

        $pinnedVersion = self::getPinnedVersion();
        $pinnedSha = self::getPinnedSha256();

        if ($pinnedVersion !== null && $pinnedSha !== null) {
            return [
                'version' => $pinnedVersion,
                'download_url' => self::DOWNLOAD_BASE_URL . '/v' . $pinnedVersion . '/' . $binaryName,
                'sha256' => $pinnedSha,
                'error' => null,
            ];
        }

        $endpoint = $pinnedVersion !== null
            ? self::GITHUB_API_BASE . '/tags/v' . rawurlencode($pinnedVersion)
            : self::GITHUB_API_BASE . '/latest';

        $response = \wp_remote_get($endpoint, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Proto-Blocks-WordPress-Plugin',
            ],
        ]);

        if (\is_wp_error($response)) {
            return ['version' => null, 'download_url' => null, 'sha256' => null, 'error' => $response->get_error_message()];
        }

        $statusCode = \wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            return [
                'version' => null,
                'download_url' => null,
                'sha256' => null,
                'error' => sprintf(\__('GitHub API returned status %d', 'proto-blocks'), $statusCode),
            ];
        }

        $data = json_decode((string) \wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['tag_name']) || !is_string($data['tag_name'])) {
            return ['version' => null, 'download_url' => null, 'sha256' => null, 'error' => \__('Invalid response from GitHub API', 'proto-blocks')];
        }

        $tag = $data['tag_name'];
        $version = ltrim($tag, 'v');
        $downloadUrl = self::DOWNLOAD_BASE_URL . '/' . rawurlencode($tag) . '/' . $binaryName;
        $sha256 = $pinnedSha;

        foreach ((array) ($data['assets'] ?? []) as $asset) {
            if (!is_array($asset) || ($asset['name'] ?? null) !== $binaryName) {
                continue;
            }
            if ($sha256 === null) {
                $sha256 = self::parseDigest($asset['digest'] ?? null);
            }
            // Only trust a download URL that stays on github.com over https.
            $url = $asset['browser_download_url'] ?? null;
            if (is_string($url) && preg_match('#^https://github\.com/tailwindlabs/tailwindcss/releases/download/#', $url)) {
                $downloadUrl = $url;
            }
            break;
        }

        return ['version' => $version, 'download_url' => $downloadUrl, 'sha256' => $sha256, 'error' => null];
    }

    /**
     * Back-compat wrapper around getReleaseInfo().
     *
     * @return array{version: string|null, download_url: string|null, error: string|null}
     */
    public function getLatestVersion(): array
    {
        $info = $this->getReleaseInfo();

        return ['version' => $info['version'], 'download_url' => $info['download_url'], 'error' => $info['error']];
    }

    // ------------------------------------------------------------------
    // Download
    // ------------------------------------------------------------------

    /**
     * Download, verify and install the CLI
     *
     * @return array{success: bool, message: string, version?: string}
     */
    public function download(bool $force = false): array
    {
        // Installing an executable is exactly what DISALLOW_FILE_MODS /
        // DISALLOW_FILE_EDIT forbid. Checked here so every path that can
        // trigger a download (REST compile, auto-compile) honours it.
        if (!\ProtoBlocks\Admin\AdminPage::fileModsAllowed()) {
            return [
                'success' => false,
                'message' => \__('File modifications are disabled on this site, so the Tailwind CLI cannot be downloaded.', 'proto-blocks'),
            ];
        }

        // Check if exec is available
        if (!function_exists('exec') || !$this->isExecAvailable()) {
            return [
                'success' => false,
                'message' => \__('PHP exec() function is not available. Shell access is required for Tailwind compilation.', 'proto-blocks'),
            ];
        }

        // Check platform support
        $binaryName = $this->getBinaryNameForPlatform();
        if (!$binaryName) {
            return [
                'success' => false,
                'message' => \__('Your platform is not supported. Tailwind CLI requires macOS or Linux.', 'proto-blocks'),
            ];
        }

        // Skip if already installed and trusted (unless forced)
        if (!$force && $this->isInstalled() && $this->isTrusted()) {
            return [
                'success' => true,
                'message' => \__('Tailwind CLI is already installed.', 'proto-blocks'),
                'version' => $this->getVersion(),
            ];
        }

        // Resolve release + checksum
        $info = $this->getReleaseInfo();
        if ($info['error']) {
            return ['success' => false, 'message' => $info['error']];
        }

        $downloadUrl = $info['download_url'];
        if (!$downloadUrl) {
            return ['success' => false, 'message' => \__('Could not determine download URL.', 'proto-blocks')];
        }

        // Ensure bin directory exists (and is guarded)
        if (!$this->ensureBinDirectory()) {
            return ['success' => false, 'message' => \__('Could not create binary directory.', 'proto-blocks')];
        }

        // Download binary
        // Use PHP's native tempnam() since wp_tempnam() is only available in admin
        $tempFile = tempnam(sys_get_temp_dir(), 'tailwindcss');
        if ($tempFile === false) {
            return ['success' => false, 'message' => \__('Could not create temporary file.', 'proto-blocks')];
        }

        $response = \wp_remote_get($downloadUrl, [
            'timeout' => 300, // 5 minutes for large binary
            'stream' => true,
            'filename' => $tempFile,
            'headers' => [
                'User-Agent' => 'Proto-Blocks-WordPress-Plugin',
            ],
        ]);

        if (\is_wp_error($response)) {
            @unlink($tempFile);
            return ['success' => false, 'message' => $response->get_error_message()];
        }

        $statusCode = \wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            @unlink($tempFile);
            return [
                'success' => false,
                'message' => sprintf(\__('Download failed with status %d', 'proto-blocks'), $statusCode),
            ];
        }

        // Verify BEFORE anything is made executable
        $verify = self::verifyDownload($tempFile, $info['sha256'], self::allowsUnverifiedDownloads());
        if (!$verify['ok']) {
            @unlink($tempFile);
            return [
                'success' => false,
                'message' => \__('Downloaded binary failed integrity verification. ', 'proto-blocks') . $verify['message'],
            ];
        }

        // Move to final location (rename() cannot cross mounts; copy as fallback)
        $binaryPath = $this->getBinaryPath();
        if (is_link($binaryPath)) {
            @unlink($binaryPath);
        }
        if (!@rename($tempFile, $binaryPath)) {
            if (!@copy($tempFile, $binaryPath)) {
                @unlink($tempFile);
                return ['success' => false, 'message' => \__('Could not move binary to destination.', 'proto-blocks')];
            }
            @unlink($tempFile);
        }

        // Make executable
        if (!chmod($binaryPath, 0755)) {
            @unlink($binaryPath);
            return ['success' => false, 'message' => \__('Could not set executable permissions.', 'proto-blocks')];
        }

        // Record the verified hash so every later exec can re-check the file.
        $this->trustMemo = [];
        Manager::getInstance()->updateSettings(['cli_sha256' => $verify['sha256']]);

        // Verify it runs
        $version = $this->getVersion();
        if (!$version) {
            @unlink($binaryPath);
            Manager::getInstance()->updateSettings(['cli_sha256' => null, 'cli_version' => null]);
            return ['success' => false, 'message' => \__('Binary verification failed.', 'proto-blocks')];
        }

        // Update stored version in settings
        Manager::getInstance()->updateSettings(['cli_version' => $version]);

        return [
            'success' => true,
            'message' => sprintf(\__('Tailwind CSS v%s installed successfully.', 'proto-blocks'), $version),
            'version' => $version,
        ];
    }

    /**
     * Uninstall the CLI
     */
    public function uninstall(): bool
    {
        $this->trustMemo = [];
        $binaryPath = $this->getBinaryPath();
        if (file_exists($binaryPath)) {
            return unlink($binaryPath);
        }
        return true;
    }

    /**
     * Get binary name for current platform
     */
    private function getBinaryNameForPlatform(): ?string
    {
        $os = PHP_OS_FAMILY;
        $arch = php_uname('m');

        // Normalize architecture
        $archMap = [
            'x86_64' => 'x64',
            'amd64' => 'x64',
            'arm64' => 'arm64',
            'aarch64' => 'arm64',
        ];
        $normalizedArch = $archMap[$arch] ?? $arch;

        if ($os === 'Darwin') {
            // macOS
            return 'tailwindcss-macos-' . $normalizedArch;
        }

        if ($os === 'Linux') {
            // Linux
            return 'tailwindcss-linux-' . $normalizedArch;
        }

        // Windows not supported
        return null;
    }

    /**
     * Ensure bin directory exists and is not web-readable.
     */
    private function ensureBinDirectory(): bool
    {
        if (!is_dir($this->binDir) && !\wp_mkdir_p($this->binDir)) {
            return false;
        }

        DirectoryGuard::denyAll($this->binDir);

        return true;
    }

    /**
     * Check if exec() is available and not disabled
     */
    private function isExecAvailable(): bool
    {
        // Check if exec is disabled
        $disabledFunctions = explode(',', ini_get('disable_functions') ?: '');
        $disabledFunctions = array_map('trim', $disabledFunctions);

        if (in_array('exec', $disabledFunctions, true)) {
            return false;
        }

        // Try to execute a simple command
        $output = [];
        $exitCode = 0;
        @exec('echo "test"', $output, $exitCode);

        return $exitCode === 0 && !empty($output);
    }

    /**
     * Check if fallback to npx is available
     */
    public function isNpxAvailable(): bool
    {
        return $this->getNpxPath() !== null;
    }

    /**
     * Get npx command path
     */
    public function getNpxPath(): ?string
    {
        $output = [];
        $exitCode = 0;
        @exec('command -v npx 2>/dev/null', $output, $exitCode);

        if ($exitCode === 0 && !empty($output[0])) {
            return trim($output[0]);
        }

        return null;
    }

    /**
     * Get bin directory
     */
    public function getBinDir(): string
    {
        return $this->binDir;
    }
}
