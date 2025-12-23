<?php
/**
 * Scoper - Handles CSS scoping to prevent conflicts
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Tailwind;

/**
 * Post-processes compiled CSS to add scope selectors
 */
class Scoper
{
    /**
     * Scope class name
     */
    public const SCOPE_CLASS = 'proto-blocks-scope';

    /**
     * Selectors to skip scoping (CSS resets, root variables, etc.)
     */
    private const SKIP_SELECTORS = [
        ':root',
        ':host',
        'html',
        'body',
        '*',
    ];

    /**
     * At-rules that contain scopeable content
     */
    private const SCOPEABLE_AT_RULES = [
        '@media',
        '@supports',
        '@layer',
    ];

    /**
     * Scope compiled CSS by wrapping selectors with scope class
     */
    public function scopeCompiledCss(string $css): string
    {
        // Add header comment
        $header = "/* Proto Blocks Tailwind CSS - Scoped to ." . self::SCOPE_CLASS . " */\n";

        // Process the CSS
        $scopedCss = $this->processCSS($css);

        return $header . $scopedCss;
    }

    /**
     * Process CSS and scope all selectors
     */
    private function processCSS(string $css): string
    {
        // Remove existing comments to avoid issues
        $css = preg_replace('/\/\*[\s\S]*?\*\//', '', $css);

        // Handle at-rules recursively
        $css = $this->processAtRules($css);

        // Process remaining top-level rules
        $css = $this->processRules($css);

        return $css;
    }

    /**
     * Process at-rules (@media, @supports, @layer, etc.)
     */
    private function processAtRules(string $css): string
    {
        // Match @media, @supports, @layer with their content
        $pattern = '/(@(?:media|supports|layer)[^{]*)\{((?:[^{}]|(?:\{[^{}]*\}))*)\}/s';

        return preg_replace_callback($pattern, function ($matches) {
            $atRule = $matches[1];
            $content = $matches[2];

            // Recursively process content inside at-rule
            $processedContent = $this->processRules($content);

            return $atRule . '{' . $processedContent . '}';
        }, $css) ?? $css;
    }

    /**
     * Process CSS rules and scope selectors
     */
    private function processRules(string $css): string
    {
        // Match CSS rules: selector { declarations }
        $pattern = '/([^{}@]+)\{([^{}]*)\}/s';

        return preg_replace_callback($pattern, function ($matches) {
            $selectors = trim($matches[1]);
            $declarations = $matches[2];

            // Skip keyframes
            if (strpos($selectors, '@keyframes') !== false || strpos($selectors, '@-webkit-keyframes') !== false) {
                return $matches[0];
            }

            // Skip font-face
            if (strpos($selectors, '@font-face') !== false) {
                return $matches[0];
            }

            // Skip @property declarations (CSS Houdini)
            // The @ might be separated by the regex, so check for "property" at start
            if (strpos($selectors, '@property') !== false || preg_match('/^\s*property\s+--/', $selectors)) {
                return $matches[0];
            }

            // Skip @counter-style
            if (strpos($selectors, '@counter-style') !== false || preg_match('/^\s*counter-style\s/', $selectors)) {
                return $matches[0];
            }

            // Scope the selectors
            $scopedSelectors = $this->scopeSelectors($selectors);

            return $scopedSelectors . '{' . $declarations . '}';
        }, $css) ?? $css;
    }

    /**
     * Scope a selector string (may contain multiple comma-separated selectors)
     */
    private function scopeSelectors(string $selectorsString): string
    {
        // Split by comma to handle multiple selectors
        $selectors = array_map('trim', explode(',', $selectorsString));
        $scopedSelectors = [];

        foreach ($selectors as $selector) {
            $scopedSelectors[] = $this->scopeSelector($selector);
        }

        return implode(',', $scopedSelectors);
    }

    /**
     * Scope a single selector
     */
    private function scopeSelector(string $selector): string
    {
        $selector = trim($selector);

        // Skip empty selectors
        if (empty($selector)) {
            return $selector;
        }

        // Check if selector should be skipped
        foreach (self::SKIP_SELECTORS as $skipSelector) {
            if ($selector === $skipSelector || strpos($selector, $skipSelector . ' ') === 0) {
                return $selector;
            }
        }

        // Handle pseudo-element and pseudo-class selectors that start with `::`
        if (strpos($selector, '::') === 0) {
            return $selector;
        }

        // Handle CSS custom property selectors (--variable)
        if (strpos($selector, '--') === 0) {
            return $selector;
        }

        // Check if selector already contains scope class
        if (strpos($selector, '.' . self::SCOPE_CLASS) !== false) {
            return $selector;
        }

        // For class selectors (utility classes), create both:
        // 1. .proto-blocks-scope.utility - for when utility is on wrapper itself
        // 2. .proto-blocks-scope .utility - for when utility is on descendants
        if (strpos($selector, '.') === 0 && strpos($selector, ' ') === false && strpos($selector, ':') === false) {
            // Simple class selector like .rounded-full - match both wrapper and descendants
            return '.' . self::SCOPE_CLASS . $selector . ',.' . self::SCOPE_CLASS . ' ' . $selector;
        }

        // Prepend scope class for other selectors (descendants only)
        return '.' . self::SCOPE_CLASS . ' ' . $selector;
    }

    /**
     * Get the scope class name
     */
    public function getScopeClass(): string
    {
        return self::SCOPE_CLASS;
    }

    /**
     * Check if a CSS string is already scoped
     */
    public function isScoped(string $css): bool
    {
        // Check for scope header comment
        if (strpos($css, 'Scoped to .' . self::SCOPE_CLASS) !== false) {
            return true;
        }

        // Check if first few selectors contain scope class
        if (preg_match('/\.' . self::SCOPE_CLASS . '\s/', $css)) {
            return true;
        }

        return false;
    }

    /**
     * Remove scoping from CSS (for debugging/testing)
     */
    public function unscopeCompiledCss(string $css): string
    {
        // Remove scope class prefix from all selectors
        $pattern = '/\.' . self::SCOPE_CLASS . '\s+/';
        $css = preg_replace($pattern, '', $css) ?? $css;

        // Remove header comment
        $css = preg_replace('/\/\* Proto Blocks Tailwind CSS[^*]*\*\/\s*/', '', $css) ?? $css;

        return $css;
    }

    /**
     * Generate CSS to add scope class to editor wrapper
     */
    public function getEditorScopeCss(): string
    {
        return <<<CSS
/* Proto Blocks Tailwind - Editor Scope */
.editor-styles-wrapper .{$this->getScopeClass()} {
    /* Ensure scope class works in editor context */
}
CSS;
    }
}
