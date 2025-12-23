<?php
/**
 * Scanner - Scans proto-blocks for Tailwind classes
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Tailwind;

use ProtoBlocks\Blocks\Discovery;

/**
 * Scans all proto-blocks for Tailwind CSS classes
 */
class Scanner
{
    /**
     * Block discovery instance
     */
    private ?Discovery $discovery;

    /**
     * Cached content
     */
    private ?string $cachedContent = null;

    /**
     * Cached hash
     */
    private ?string $cachedHash = null;

    /**
     * Constructor
     */
    public function __construct(?Discovery $discovery = null)
    {
        $this->discovery = $discovery;
    }

    /**
     * Scan all blocks and return aggregated content
     */
    public function scanAllBlocks(): string
    {
        if ($this->cachedContent !== null) {
            return $this->cachedContent;
        }

        $content = '';
        $blocks = $this->getDiscovery()->discover();

        foreach ($blocks as $blockName => $blockPath) {
            $blockContent = $this->scanBlock($blockPath, $blockName);
            if ($blockContent) {
                $content .= "<!-- Block: {$blockName} -->\n";
                $content .= $blockContent . "\n\n";
            }
        }

        $this->cachedContent = $content;
        return $content;
    }

    /**
     * Scan a single block directory
     */
    public function scanBlock(string $blockPath, string $blockName): string
    {
        $content = '';

        // Scan template.php
        $templatePath = $blockPath . '/template.php';
        if (file_exists($templatePath)) {
            $templateContent = file_get_contents($templatePath);
            if ($templateContent !== false) {
                $content .= $templateContent . "\n";
            }
        }

        // Also try {block-name}.php for backwards compatibility
        $altTemplatePath = $blockPath . '/' . $blockName . '.php';
        if ($altTemplatePath !== $templatePath && file_exists($altTemplatePath)) {
            $altContent = file_get_contents($altTemplatePath);
            if ($altContent !== false) {
                $content .= $altContent . "\n";
            }
        }

        // Scan style.css for @apply directives
        $stylePath = $blockPath . '/style.css';
        if (file_exists($stylePath)) {
            $styleContent = file_get_contents($stylePath);
            if ($styleContent !== false) {
                $content .= $this->extractApplyClasses($styleContent);
            }
        }

        // Also try {block-name}.css
        $altStylePath = $blockPath . '/' . $blockName . '.css';
        if ($altStylePath !== $stylePath && file_exists($altStylePath)) {
            $altStyleContent = file_get_contents($altStylePath);
            if ($altStyleContent !== false) {
                $content .= $this->extractApplyClasses($altStyleContent);
            }
        }

        return $content;
    }

    /**
     * Extract classes from @apply directives
     */
    private function extractApplyClasses(string $cssContent): string
    {
        $classes = '';

        // Match @apply directives: @apply class1 class2 class3;
        if (preg_match_all('/@apply\s+([^;]+);/', $cssContent, $matches)) {
            foreach ($matches[1] as $applyClasses) {
                // Create dummy HTML elements with these classes for Tailwind to detect
                $classList = trim($applyClasses);
                $classes .= "<div class=\"{$classList}\"></div>\n";
            }
        }

        return $classes;
    }

    /**
     * Get content hash for cache invalidation
     */
    public function getContentHash(): string
    {
        if ($this->cachedHash !== null) {
            return $this->cachedHash;
        }

        $content = $this->scanAllBlocks();

        // Also include block file modification times in hash
        $blocks = $this->getDiscovery()->discover();
        $mtimes = [];

        foreach ($blocks as $blockName => $blockPath) {
            $templatePath = $blockPath . '/template.php';
            if (file_exists($templatePath)) {
                $mtimes[] = filemtime($templatePath);
            }

            $stylePath = $blockPath . '/style.css';
            if (file_exists($stylePath)) {
                $mtimes[] = filemtime($stylePath);
            }
        }

        // Include theme config in hash
        $themeConfig = Manager::getInstance()->getConfigEditor()->getThemeConfig();

        $hashContent = $content . implode('|', $mtimes) . $themeConfig;
        $this->cachedHash = md5($hashContent);

        return $this->cachedHash;
    }

    /**
     * Write content to a file for Tailwind to scan
     */
    public function writeContentFile(string $outputPath): bool
    {
        $content = $this->scanAllBlocks();

        // Wrap content in HTML structure
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Proto Blocks Tailwind Content</title>
</head>
<body>
{$content}
</body>
</html>
HTML;

        return file_put_contents($outputPath, $html) !== false;
    }

    /**
     * Get list of scanned files for debugging
     *
     * @return array<string, array<string>>
     */
    public function getScannedFiles(): array
    {
        $files = [];
        $blocks = $this->getDiscovery()->discover();

        foreach ($blocks as $blockName => $blockPath) {
            $blockFiles = [];

            $templatePath = $blockPath . '/template.php';
            if (file_exists($templatePath)) {
                $blockFiles[] = $templatePath;
            }

            $altTemplatePath = $blockPath . '/' . $blockName . '.php';
            if ($altTemplatePath !== $templatePath && file_exists($altTemplatePath)) {
                $blockFiles[] = $altTemplatePath;
            }

            $stylePath = $blockPath . '/style.css';
            if (file_exists($stylePath)) {
                $blockFiles[] = $stylePath;
            }

            $altStylePath = $blockPath . '/' . $blockName . '.css';
            if ($altStylePath !== $stylePath && file_exists($altStylePath)) {
                $blockFiles[] = $altStylePath;
            }

            if (!empty($blockFiles)) {
                $files[$blockName] = $blockFiles;
            }
        }

        return $files;
    }

    /**
     * Refresh cached content
     */
    public function refresh(): void
    {
        $this->cachedContent = null;
        $this->cachedHash = null;
    }

    /**
     * Get discovery instance
     */
    private function getDiscovery(): Discovery
    {
        if ($this->discovery === null) {
            $this->discovery = new Discovery();
        }
        return $this->discovery;
    }

    /**
     * Set discovery instance
     */
    public function setDiscovery(Discovery $discovery): void
    {
        $this->discovery = $discovery;
        $this->refresh();
    }
}
