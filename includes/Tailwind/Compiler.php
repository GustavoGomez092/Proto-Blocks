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

        // Ensure cache directory exists
        if (!$this->cache->ensureDirectory()) {
            return [
                'success' => false,
                'message' => \__('Could not create cache directory.', 'proto-blocks'),
            ];
        }

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

        $scopedCss = $this->scoper->scopeCompiledCss($css);
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
        $cwd = $this->cache->getCacheDir();

        // Build command
        $cmd = sprintf(
            '%s --input %s --output %s --cwd %s --minify 2>&1',
            escapeshellarg($binaryPath),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath),
            escapeshellarg($cwd)
        );

        // Execute
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        $outputStr = implode("\n", $output);

        if ($exitCode !== 0) {
            // Try fallback to npx if available
            $fallbackResult = $this->tryNpxFallback($inputPath);
            if ($fallbackResult !== null) {
                return $fallbackResult;
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
     * Try compilation using npx as fallback
     *
     * @return array{success: bool, message: string, output?: string}|null
     */
    private function tryNpxFallback(string $inputPath): ?array
    {
        if (!$this->binaryManager->isNpxAvailable()) {
            return null;
        }

        $npxPath = $this->binaryManager->getNpxPath();
        if (!$npxPath) {
            return null;
        }

        $outputPath = $this->cache->getCssPath();
        $cwd = $this->cache->getCacheDir();

        // Build npx command (using @tailwindcss/cli for v4)
        $cmd = sprintf(
            '%s @tailwindcss/cli@next --input %s --output %s --cwd %s --minify 2>&1',
            escapeshellarg($npxPath),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath),
            escapeshellarg($cwd)
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        $outputStr = implode("\n", $output);

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
        if (!$this->binaryManager->isInstalled() && !$this->binaryManager->isNpxAvailable()) {
            return [
                'possible' => false,
                'reason' => \__('Neither Tailwind CLI binary nor npx is available.', 'proto-blocks'),
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
            'binary_version' => $this->binaryManager->getVersion(),
            'npx_available' => $this->binaryManager->isNpxAvailable(),
            'cache_writable' => $this->cache->isWritable(),
            'cache_exists' => $this->cache->exists(),
        ];
    }
}
