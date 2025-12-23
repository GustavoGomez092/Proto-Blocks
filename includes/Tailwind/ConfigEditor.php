<?php
/**
 * Config Editor - Manages Tailwind v4 theme configuration
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Tailwind;

/**
 * Handles Tailwind v4 CSS-based theme configuration
 */
class ConfigEditor
{
    /**
     * Default theme configuration for Tailwind v4
     * Note: For utility classes like bg-primary-500, you must define --color-primary-500
     */
    private const DEFAULT_THEME = <<<'CSS'
@theme {
  /* Primary color scale */
  --color-primary-50: #eff6ff;
  --color-primary-100: #dbeafe;
  --color-primary-200: #bfdbfe;
  --color-primary-300: #93c5fd;
  --color-primary-400: #60a5fa;
  --color-primary-500: #3b82f6;
  --color-primary-600: #2563eb;
  --color-primary-700: #1d4ed8;
  --color-primary-800: #1e40af;
  --color-primary-900: #1e3a8a;

  /* Secondary color scale */
  --color-secondary-50: #ecfdf5;
  --color-secondary-100: #d1fae5;
  --color-secondary-200: #a7f3d0;
  --color-secondary-300: #6ee7b7;
  --color-secondary-400: #34d399;
  --color-secondary-500: #10b981;
  --color-secondary-600: #059669;
  --color-secondary-700: #047857;
  --color-secondary-800: #065f46;
  --color-secondary-900: #064e3b;

  /* Accent color scale */
  --color-accent-50: #f5f3ff;
  --color-accent-100: #ede9fe;
  --color-accent-200: #ddd6fe;
  --color-accent-300: #c4b5fd;
  --color-accent-400: #a78bfa;
  --color-accent-500: #8b5cf6;
  --color-accent-600: #7c3aed;
  --color-accent-700: #6d28d9;
  --color-accent-800: #5b21b6;
  --color-accent-900: #4c1d95;
}
CSS;

    /**
     * Example presets with full color scales
     * In Tailwind v4, utility classes like bg-primary-500 require --color-primary-500
     */
    private const PRESETS = [
        'default' => [
            'name' => 'Default (Blue)',
            'description' => 'Blue primary, green secondary, purple accent',
            'config' => self::DEFAULT_THEME,
        ],
        'minimal' => [
            'name' => 'Minimal (Zinc)',
            'description' => 'Clean, monochromatic zinc design',
            'config' => <<<'CSS'
@theme {
  /* Primary - Zinc */
  --color-primary-50: #fafafa;
  --color-primary-100: #f4f4f5;
  --color-primary-200: #e4e4e7;
  --color-primary-300: #d4d4d8;
  --color-primary-400: #a1a1aa;
  --color-primary-500: #71717a;
  --color-primary-600: #52525b;
  --color-primary-700: #3f3f46;
  --color-primary-800: #27272a;
  --color-primary-900: #18181b;

  /* Secondary - Slate */
  --color-secondary-400: #94a3b8;
  --color-secondary-500: #64748b;
  --color-secondary-600: #475569;

  /* Accent - Stone */
  --color-accent-400: #a8a29e;
  --color-accent-500: #78716c;
  --color-accent-600: #57534e;
}
CSS,
        ],
        'vibrant' => [
            'name' => 'Vibrant',
            'description' => 'Red primary, blue secondary, green accent',
            'config' => <<<'CSS'
@theme {
  /* Primary - Red */
  --color-primary-400: #f87171;
  --color-primary-500: #ef4444;
  --color-primary-600: #dc2626;

  /* Secondary - Blue */
  --color-secondary-400: #60a5fa;
  --color-secondary-500: #3b82f6;
  --color-secondary-600: #2563eb;

  /* Accent - Green */
  --color-accent-400: #4ade80;
  --color-accent-500: #22c55e;
  --color-accent-600: #16a34a;
}
CSS,
        ],
        'ocean' => [
            'name' => 'Ocean',
            'description' => 'Cool, calming blue and cyan tones',
            'config' => <<<'CSS'
@theme {
  /* Primary - Sky */
  --color-primary-50: #f0f9ff;
  --color-primary-100: #e0f2fe;
  --color-primary-200: #bae6fd;
  --color-primary-300: #7dd3fc;
  --color-primary-400: #38bdf8;
  --color-primary-500: #0ea5e9;
  --color-primary-600: #0284c7;
  --color-primary-700: #0369a1;
  --color-primary-800: #075985;
  --color-primary-900: #0c4a6e;

  /* Secondary - Cyan */
  --color-secondary-400: #22d3ee;
  --color-secondary-500: #06b6d4;
  --color-secondary-600: #0891b2;

  /* Accent - Teal */
  --color-accent-400: #2dd4bf;
  --color-accent-500: #14b8a6;
  --color-accent-600: #0d9488;
}
CSS,
        ],
        'sunset' => [
            'name' => 'Sunset',
            'description' => 'Warm orange and purple tones',
            'config' => <<<'CSS'
@theme {
  /* Primary - Orange */
  --color-primary-400: #fb923c;
  --color-primary-500: #f97316;
  --color-primary-600: #ea580c;

  /* Secondary - Fuchsia */
  --color-secondary-400: #e879f9;
  --color-secondary-500: #d946ef;
  --color-secondary-600: #c026d3;

  /* Accent - Amber */
  --color-accent-400: #fbbf24;
  --color-accent-500: #f59e0b;
  --color-accent-600: #d97706;
}
CSS,
        ],
    ];

    /**
     * Get the current theme configuration
     */
    public function getThemeConfig(): string
    {
        $settings = \get_option(Manager::OPTION_NAME, []);
        $config = $settings['theme_config'] ?? '';

        if (empty($config)) {
            return self::DEFAULT_THEME;
        }

        return $config;
    }

    /**
     * Save theme configuration
     */
    public function saveThemeConfig(string $config): bool
    {
        // Basic validation
        $validation = $this->validateConfig($config);
        if (!$validation['valid']) {
            return false;
        }

        // Update settings
        return Manager::getInstance()->updateSettings([
            'theme_config' => $config,
        ]);
    }

    /**
     * Validate theme configuration
     *
     * @return array{valid: bool, errors: array<string>}
     */
    public function validateConfig(string $config): array
    {
        $errors = [];

        // Check for obviously dangerous content
        $dangerousPatterns = [
            '/expression\s*\(/i',
            '/javascript\s*:/i',
            '/behavior\s*:/i',
            '/-moz-binding/i',
            '/url\s*\(\s*["\']?data:/i',
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $config)) {
                $errors[] = \__('Configuration contains potentially dangerous content.', 'proto-blocks');
                break;
            }
        }

        // Check for balanced braces
        $openBraces = substr_count($config, '{');
        $closeBraces = substr_count($config, '}');
        if ($openBraces !== $closeBraces) {
            $errors[] = \__('Configuration has unbalanced braces.', 'proto-blocks');
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Get available presets
     *
     * @return array<string, array<string, string>>
     */
    public function getPresets(): array
    {
        return self::PRESETS;
    }

    /**
     * Load a preset configuration
     */
    public function loadPreset(string $presetName): ?string
    {
        if (!isset(self::PRESETS[$presetName])) {
            return null;
        }

        return self::PRESETS[$presetName]['config'];
    }

    /**
     * Reset to default configuration
     */
    public function resetToDefault(): bool
    {
        return $this->saveThemeConfig(self::DEFAULT_THEME);
    }

    /**
     * Get default theme configuration
     */
    public function getDefaultTheme(): string
    {
        return self::DEFAULT_THEME;
    }

    /**
     * Generate the complete input.css for Tailwind v4
     */
    public function generateInputCss(string $contentPath): string
    {
        $themeConfig = $this->getThemeConfig();

        return <<<CSS
/* Proto Blocks Tailwind v4 - Auto-generated input.css */
@import "tailwindcss";

/* Content source for JIT compilation */
@source "{$contentPath}";

/* Disable preflight to avoid CSS reset conflicts */
@layer base {
  /* Preflight disabled - Proto Blocks uses WordPress styles */
}

/* User theme customizations */
{$themeConfig}
CSS;
    }

    /**
     * Check if current config differs from default
     */
    public function isModified(): bool
    {
        $current = $this->getThemeConfig();
        return trim($current) !== trim(self::DEFAULT_THEME);
    }

    /**
     * Get config as JSON for JavaScript
     *
     * @return array<string, mixed>
     */
    public function getConfigData(): array
    {
        return [
            'current' => $this->getThemeConfig(),
            'default' => self::DEFAULT_THEME,
            'presets' => $this->getPresets(),
            'isModified' => $this->isModified(),
        ];
    }
}
