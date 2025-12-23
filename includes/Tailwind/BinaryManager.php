<?php
/**
 * Binary Manager - Downloads and manages Tailwind CLI binary
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
     * GitHub releases URL for Tailwind CSS
     */
    private const GITHUB_RELEASES_URL = 'https://api.github.com/repos/tailwindlabs/tailwindcss/releases/latest';

    /**
     * Binary download base URL
     */
    private const DOWNLOAD_BASE_URL = 'https://github.com/tailwindlabs/tailwindcss/releases/download';

    /**
     * Binary directory
     */
    private string $binDir;

    /**
     * Constructor
     */
    public function __construct(?string $binDir = null)
    {
        $uploadDir = \wp_upload_dir();
        $this->binDir = $binDir ?? $uploadDir['basedir'] . '/proto-blocks/bin/';
    }

    /**
     * Check if CLI is installed
     */
    public function isInstalled(): bool
    {
        $binaryPath = $this->getBinaryPath();
        return file_exists($binaryPath) && is_executable($binaryPath);
    }

    /**
     * Get binary path
     */
    public function getBinaryPath(): string
    {
        return $this->binDir . 'tailwindcss';
    }

    /**
     * Get installed version
     */
    public function getVersion(): ?string
    {
        if (!$this->isInstalled()) {
            return null;
        }

        $binaryPath = $this->getBinaryPath();
        $output = [];
        $exitCode = 0;

        exec(escapeshellarg($binaryPath) . ' --version 2>&1', $output, $exitCode);

        if ($exitCode === 0 && !empty($output[0])) {
            // Output is typically "tailwindcss v4.x.x"
            $version = trim($output[0]);
            if (preg_match('/v?(\d+\.\d+\.\d+)/', $version, $matches)) {
                return $matches[1];
            }
            return $version;
        }

        return null;
    }

    /**
     * Get latest version from GitHub
     *
     * @return array{version: string|null, download_url: string|null, error: string|null}
     */
    public function getLatestVersion(): array
    {
        $response = \wp_remote_get(self::GITHUB_RELEASES_URL, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'Proto-Blocks-WordPress-Plugin',
            ],
        ]);

        if (\is_wp_error($response)) {
            return [
                'version' => null,
                'download_url' => null,
                'error' => $response->get_error_message(),
            ];
        }

        $statusCode = \wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            return [
                'version' => null,
                'download_url' => null,
                'error' => sprintf(\__('GitHub API returned status %d', 'proto-blocks'), $statusCode),
            ];
        }

        $body = \wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['tag_name'])) {
            return [
                'version' => null,
                'download_url' => null,
                'error' => \__('Invalid response from GitHub API', 'proto-blocks'),
            ];
        }

        $version = ltrim($data['tag_name'], 'v');
        $binaryName = $this->getBinaryNameForPlatform();

        return [
            'version' => $version,
            'download_url' => $binaryName
                ? self::DOWNLOAD_BASE_URL . '/' . $data['tag_name'] . '/' . $binaryName
                : null,
            'error' => $binaryName ? null : \__('Unsupported platform', 'proto-blocks'),
        ];
    }

    /**
     * Download and install the CLI
     *
     * @return array{success: bool, message: string, version?: string}
     */
    public function download(bool $force = false): array
    {
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

        // Skip if already installed (unless forced)
        if (!$force && $this->isInstalled()) {
            return [
                'success' => true,
                'message' => \__('Tailwind CLI is already installed.', 'proto-blocks'),
                'version' => $this->getVersion(),
            ];
        }

        // Get latest version info
        $latestInfo = $this->getLatestVersion();
        if ($latestInfo['error']) {
            return [
                'success' => false,
                'message' => $latestInfo['error'],
            ];
        }

        $downloadUrl = $latestInfo['download_url'];
        if (!$downloadUrl) {
            return [
                'success' => false,
                'message' => \__('Could not determine download URL.', 'proto-blocks'),
            ];
        }

        // Ensure bin directory exists
        if (!$this->ensureBinDirectory()) {
            return [
                'success' => false,
                'message' => \__('Could not create binary directory.', 'proto-blocks'),
            ];
        }

        // Download binary
        // Use PHP's native tempnam() since wp_tempnam() is only available in admin
        $tempFile = tempnam(sys_get_temp_dir(), 'tailwindcss');
        if ($tempFile === false) {
            return [
                'success' => false,
                'message' => \__('Could not create temporary file.', 'proto-blocks'),
            ];
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
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $statusCode = \wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            @unlink($tempFile);
            return [
                'success' => false,
                'message' => sprintf(\__('Download failed with status %d', 'proto-blocks'), $statusCode),
            ];
        }

        // Move to final location
        $binaryPath = $this->getBinaryPath();
        if (!rename($tempFile, $binaryPath)) {
            @unlink($tempFile);
            return [
                'success' => false,
                'message' => \__('Could not move binary to destination.', 'proto-blocks'),
            ];
        }

        // Make executable
        if (!chmod($binaryPath, 0755)) {
            @unlink($binaryPath);
            return [
                'success' => false,
                'message' => \__('Could not set executable permissions.', 'proto-blocks'),
            ];
        }

        // Verify installation
        $version = $this->getVersion();
        if (!$version) {
            @unlink($binaryPath);
            return [
                'success' => false,
                'message' => \__('Binary verification failed.', 'proto-blocks'),
            ];
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
     * Ensure bin directory exists
     */
    private function ensureBinDirectory(): bool
    {
        if (is_dir($this->binDir)) {
            return true;
        }

        return \wp_mkdir_p($this->binDir);
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
        $output = [];
        $exitCode = 0;
        @exec('which npx 2>/dev/null', $output, $exitCode);

        return $exitCode === 0 && !empty($output);
    }

    /**
     * Get npx command path
     */
    public function getNpxPath(): ?string
    {
        $output = [];
        $exitCode = 0;
        @exec('which npx 2>/dev/null', $output, $exitCode);

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
