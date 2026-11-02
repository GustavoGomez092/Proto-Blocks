<?php
/**
 * Compiler - Executes Tailwind CSS compilation
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Tailwind;

/**
 * Handles Tailwind CSS compilation using the standalone CLI
 */
class Compiler
{
    /**
     * Exact @tailwindcss/cli version used by the (opt-in) npx fallback.
     * Never a dist-tag: `next`/`latest` are mutable and would run whatever
     * the registry serves at that moment.
     */
    public const NPX_CLI_VERSION = '4.1.11';

    /**
     * Default wall-clock limit for a compile (seconds).
     */
    public const DEFAULT_TIMEOUT = 120;

    /**
     * Services
     */
    private BinaryManager $binaryManager;
    private Scanner $scanner;
    private Cache $cache;
    private Scoper $scoper;
    private ConfigEditor $configEditor;

    /**
     * Constructor
     */
    public function __construct(
        BinaryManager $binaryManager,
        Scanner $scanner,
        Cache $cache,
        Scoper $scoper,
        ConfigEditor $configEditor
    ) {
        $this->binaryManager = $binaryManager;
        $this->scanner = $scanner;
        $this->cache = $cache;
        $this->scoper = $scoper;
        $this->configEditor = $configEditor;
    }

    /**
     * Compile Tailwind CSS
     *
     * @return array{success: bool, message: string, output?: string, css_size?: int}
     */
    public function compile(): array
    {
        // Check if CLI is installed
        if (!$this->binaryManager->isInstalled()) {
            // Try to download it
            $downloadResult = $this->binaryManager->download();
            if (!$downloadResult['success']) {
                return [
                    'success' => false,
                    'message' => \__('Tailwind CLI is not installed. ', 'proto-blocks') . $downloadResult['message'],
                ];
            }
        }

        // Never run a binary that does not match the hash recorded at download.
        if (!$this->binaryManager->isTrusted()) {
            return [
                'success' => false,
                'message' => self::untrustedBinaryMessage($this->binaryManager->getTrustState()),
            ];
        }

        // Ensure output + work directories exist (and are guarded)
        if (!$this->cache->ensureDirectory() || !$this->cache->ensureWorkDirectory()) {
            return [
                'success' => false,
                'message' => \__('Could not create cache directory.', 'proto-blocks'),
            ];
        }

        try {
            return $this->runPipeline();
        } finally {
            // The content file is the verbatim PHP source of every block
            // template; it must not outlive the compile, whatever happened.
            $this->cache->removeCompileInputs();
        }
    }

    /**
     * The compile steps proper; compile() wraps this in the cleanup.
     *
     * @return array{success: bool, message: string, output?: string, css_size?: int}
     */
    private function runPipeline(): array
    {
        // Step 1: Generate content file from scanner
        $contentPath = $this->cache->getContentPath();
        if (!$this->scanner->writeContentFile($contentPath)) {
            return [
                'success' => false,
                'message' => \__('Could not write content file.', 'proto-blocks'),
            ];
        }

        // Step 2: Generate input.css with user configuration
        $inputPath = $this->cache->getInputPath();
        $inputCss = $this->configEditor->generateInputCss(basename($contentPath));
        if (!$this->cache->saveInput($inputCss)) {
            return [
                'success' => false,
                'message' => \__('Could not write input CSS file.', 'proto-blocks'),
            ];
        }

        // Step 3: Execute Tailwind CLI
        $result = $this->executeCompilation($inputPath);
        if (!$result['success']) {
            return $result;
        }

        // Step 4: Post-process - Apply CSS scoping
        $outputPath = $this->cache->getCssPath();
        $css = file_get_contents($outputPath);
        if ($css === false) {
            return [
                'success' => false,
                'message' => \__('Could not read compiled CSS.', 'proto-blocks'),
            ];
        }

        try {
            $scopedCss = $this->scoper->scopeCompiledCss($css);
        } catch (\RuntimeException $e) {
            // Do not leave the unscoped CLI output in place as the live stylesheet.
            @unlink($outputPath);
            return [
                'success' => false,
                'message' => \__('Could not scope compiled CSS: ', 'proto-blocks') . $e->getMessage(),
            ];
        }

        if (!$this->cache->saveContent($scopedCss)) {
            return [
                'success' => false,
                'message' => \__('Could not save scoped CSS.', 'proto-blocks'),
            ];
        }

        // Step 5: Save content hash for invalidation
        $contentHash = $this->scanner->getContentHash();
        $this->cache->saveHash($contentHash);

        return [
            'success' => true,
            'message' => \__('Tailwind CSS compiled successfully.', 'proto-blocks'),
            'output' => $result['output'] ?? '',
            'css_size' => strlen($scopedCss),
        ];
    }

    /**
     * Execute the Tailwind CLI compilation
     *
     * @return array{success: bool, message: string, output?: string}
     */
    private function executeCompilation(string $inputPath): array
    {
        $binaryPath = $this->binaryManager->getBinaryPath();
        $outputPath = $this->cache->getCssPath();
        $cwd = $this->cache->getWorkDir();

        // Build command
        $cmd = sprintf(
            '%s --input %s --output %s --cwd %s --minify',
            escapeshellarg($binaryPath),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath),
            escapeshellarg($cwd)
        );

        $run = ProcessRunner::run($cmd, self::getTimeout(), $cwd);
        $exitCode = $run['exit'];
        $outputStr = $run['output'];

        if ($exitCode !== 0) {
            // Only fall back to npx when the binary itself cannot run (wrong
            // arch, missing, not executable) — never on a Tailwind/CSS error,
            // which npx would just reproduce after a network install.
            if (self::shouldTryNpxFallback($exitCode, $outputStr)) {
                $fallbackResult = $this->tryNpxFallback($inputPath);
                if ($fallbackResult !== null) {
                    return $fallbackResult;
                }
            }

            return [
                'success' => false,
                'message' => sprintf(
                    \__('Tailwind compilation failed (exit code %d): %s', 'proto-blocks'),
                    $exitCode,
                    $outputStr
                ),
                'output' => $outputStr,
            ];
        }

        return [
            'success' => true,
            'message' => \__('Compilation successful.', 'proto-blocks'),
            'output' => $outputStr,
        ];
    }

    /**
     * Whether the npx fallback is enabled (opt-in via wp-config constant).
     */
    public static function isNpxFallbackAllowed(): bool
    {
        return defined('PROTO_BLOCKS_TAILWIND_ALLOW_NPX') && (bool) \PROTO_BLOCKS_TAILWIND_ALLOW_NPX;
    }

    /**
     * Whether a failed binary run looks like "the binary cannot execute" rather
     * than "Tailwind reported an error". Pure, for unit tests.
     */
    public static function shouldTryNpxFallback(int $exitCode, string $output): bool
    {
        return self::isNpxFallbackAllowed() && self::looksLikeUnrunnableBinary($exitCode, $output);
    }

    /**
     * Classify a failed run: binary cannot execute (true) vs Tailwind error (false).
     */
    public static function looksLikeUnrunnableBinary(int $exitCode, string $output): bool
    {
        // 126 = found but not executable, 127 = not found (POSIX shell).
        if ($exitCode === 126 || $exitCode === 127) {
            return true;
        }

        return (bool) preg_match(
            '/exec format error|cannot execute binary|bad cpu type|no such file or directory|permission denied|could not start process/i',
            $output
        );
    }

    /**
     * Compile wall-clock limit in seconds.
     */
    public static function getTimeout(): int
    {
        $timeout = self::DEFAULT_TIMEOUT;
        if (function_exists('apply_filters')) {
            /**
             * Filter the maximum number of seconds a Tailwind compile may run.
             *
             * @param int $timeout Default 120.
             */
            $timeout = (int) \apply_filters('proto_blocks_tailwind_exec_timeout', $timeout);
        }

        return max(1, $timeout);
    }

    /**
     * Try compilation using npx as fallback (opt-in, pinned, no auto-install).
     *
     * @return array{success: bool, message: string, output?: string}|null
     */
    private function tryNpxFallback(string $inputPath): ?array
    {
        if (!self::isNpxFallbackAllowed()) {
            return null;
        }

        $npxPath = $this->binaryManager->getNpxPath();
        if (!$npxPath) {
            return null;
        }

        $outputPath = $this->cache->getCssPath();
        $cwd = $this->cache->getWorkDir();

        // --no-install: only run a package that is already present on the host;
        // never pull @tailwindcss/cli from the registry at request time.
        $cmd = sprintf(
            '%s --no-install %s --input %s --output %s --cwd %s --minify',
            escapeshellarg($npxPath),
            escapeshellarg('@tailwindcss/cli@' . self::NPX_CLI_VERSION),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath),
            escapeshellarg($cwd)
        );

        $run = ProcessRunner::run($cmd, self::getTimeout(), $cwd);
        $exitCode = $run['exit'];
        $outputStr = $run['output'];

        if ($exitCode !== 0) {
            return [
                'success' => false,
                'message' => sprintf(
                    \__('Tailwind compilation failed with both binary and npx (exit code %d): %s', 'proto-blocks'),
                    $exitCode,
                    $outputStr
                ),
                'output' => $outputStr,
            ];
        }

        return [
            'success' => true,
            'message' => \__('Compilation successful (via npx fallback).', 'proto-blocks'),
            'output' => $outputStr,
        ];
    }

    /**
     * Human-readable reason a binary was refused.
     */
    public static function untrustedBinaryMessage(string $trustState): string
    {
        switch ($trustState) {
            case BinaryManager::TRUST_MISMATCH:
                return \__('The Tailwind CLI binary on disk does not match the checksum recorded when it was downloaded, so it was not run. Re-download it from the Tailwind settings.', 'proto-blocks');
            case BinaryManager::TRUST_UNVERIFIED:
                return \__('The Tailwind CLI binary has no recorded checksum (it predates integrity checks), so it was not run. Re-download it from the Tailwind settings.', 'proto-blocks');
            case BinaryManager::TRUST_MISSING:
                return \__('The Tailwind CLI binary is missing.', 'proto-blocks');
            default:
                return \__('The Tailwind CLI binary could not be verified.', 'proto-blocks');
        }
    }

    /**
     * Check if compilation is possible
     *
     * @return array{possible: bool, reason?: string}
     */
    public function canCompile(): array
    {
        // Check if exec is available
        if (!function_exists('exec')) {
            return [
                'possible' => false,
                'reason' => \__('PHP exec() function is not available.', 'proto-blocks'),
            ];
        }

        // Check if binary is installed or can be installed
        if (!$this->binaryManager->isInstalled()
            && !(self::isNpxFallbackAllowed() && $this->binaryManager->isNpxAvailable())
        ) {
            return [
                'possible' => false,
                'reason' => \__('Tailwind CLI binary is not available.', 'proto-blocks'),
            ];
        }

        if ($this->binaryManager->isInstalled() && !$this->binaryManager->isTrusted()) {
            return [
                'possible' => false,
                'reason' => self::untrustedBinaryMessage($this->binaryManager->getTrustState()),
            ];
        }

        // Check if cache directory is writable
        if (!$this->cache->isWritable()) {
            return [
                'possible' => false,
                'reason' => \__('Cache directory is not writable.', 'proto-blocks'),
            ];
        }

        return [
            'possible' => true,
        ];
    }

    /**
     * Get compilation status
     *
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        return [
            'can_compile' => $this->canCompile(),
            'binary_installed' => $this->binaryManager->isInstalled(),
            'binary_trusted' => $this->binaryManager->isTrusted(),
            'binary_version' => $this->binaryManager->getVersion(),
            'npx_available' => self::isNpxFallbackAllowed() && $this->binaryManager->isNpxAvailable(),
            'cache_writable' => $this->cache->isWritable(),
            'cache_exists' => $this->cache->exists(),
        ];
    }
}
