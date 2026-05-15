<?php
/**
 * Config Editor - Resolves Tailwind v4 theme tokens from the active theme.
 *
 * The Tailwind theme (an `@theme { … }` block defining colors, fonts, etc.)
 * is read from a CSS file in the active theme. By default that path is:
 *
 *   wp-content/themes/<active>/tailwind-theme.css
 *
 * Sites can override the path via the `proto_blocks_theme_css_path` filter:
 *
 *   add_filter('proto_blocks_theme_css_path', fn() => WP_CONTENT_DIR . '/foo.css');
 *
 * Historically Proto-Blocks stored the theme config as a textarea in the
 * admin UI, persisted to an option in the database. That made tokens
 * volatile (not in version control, easy to clobber on env restore). The
 * file-based flow keeps brand tokens in the theme repo where they belong.
 *
 * @package ProtoBlocks
 */

declare(strict_types=1);

namespace ProtoBlocks\Tailwind;

class ConfigEditor
{
    /**
     * Default theme CSS file name, looked up under the active theme directory.
     */
    public const THEME_FILENAME = 'tailwind-theme.css';

    /**
     * Resolve the path to the theme's tailwind-theme.css.
     */
    public function getThemeCssPath(): string
    {
        $default_path = trailingslashit(\get_stylesheet_directory()) . self::THEME_FILENAME;

        /**
         * Filter the path to the Tailwind theme CSS file.
         *
         * @param string $path Absolute filesystem path to the theme CSS file.
         */
        return (string) \apply_filters('proto_blocks_theme_css_path', $default_path);
    }

    /**
     * Read the contents of the theme CSS file, or '' if missing.
     */
    public function getThemeCss(): string
    {
        $path = $this->getThemeCssPath();
        if (!is_string($path) || $path === '' || !file_exists($path) || !is_readable($path)) {
            return '';
        }

        $contents = file_get_contents($path);
        return is_string($contents) ? $contents : '';
    }

    /**
     * Back-compat shim. Older internal callers used getThemeConfig() to mean
     * "the @theme block that goes into the Tailwind input". It now resolves
     * to the file-based contents.
     *
     * @deprecated Use getThemeCss() instead.
     */
    public function getThemeConfig(): string
    {
        return $this->getThemeCss();
    }

    /**
     * Validate CSS-ish contents we're about to feed Tailwind.
     *
     * @return array{valid: bool, errors: array<string>}
     */
    public function validateConfig(string $config): array
    {
        $errors = [];

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

        $openBraces  = substr_count($config, '{');
        $closeBraces = substr_count($config, '}');
        if ($openBraces !== $closeBraces) {
            $errors[] = \__('Configuration has unbalanced braces.', 'proto-blocks');
        }

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Generate the complete input.css for the Tailwind CLI.
     */
    public function generateInputCss(string $contentPath): string
    {
        $themeCss       = $this->getThemeCss();
        $themePath      = $this->getThemeCssPath();
        $preflightLayer = $this->getPreflightLayer();

        if ($themeCss === '') {
            $themeBlock = "/* No Tailwind theme file found at: {$themePath}\n" .
                          "   Create one with `@theme { --color-…: …; }` to expose brand tokens. */";
        } else {
            $themeBlock = "/* Theme tokens loaded from: {$themePath} */\n{$themeCss}";
        }

        // Tailwind v4's `@import "tailwindcss"` shorthand pulls in theme + preflight
        // + utilities, but its bundled preflight resets html/body/* globally which
        // conflicts with WordPress themes. Use the modular form so we can swap
        // preflight for our scoped variant (see getPreflightLayer()).
        //
        // Note: utilities are imported UNLAYERED, not into @layer utilities.
        // Block themes' theme.json emits unlayered rules like
        //   a:where(:not(.wp-element-button)) { text-decoration: underline }
        // and unlayered rules always beat layered ones regardless of specificity.
        // Keeping our utilities + scoped preflight unlayered lets specificity
        // (which the Scoper boosts via the scope class) decide the cascade.
        return <<<CSS
/* Proto Blocks Tailwind v4 - Auto-generated input.css */
@layer theme;

@import "tailwindcss/theme.css" layer(theme);
@import "tailwindcss/utilities.css";

/* Content source for JIT compilation */
@source "{$contentPath}";

{$preflightLayer}

{$themeBlock}
CSS;
    }

    /**
     * Build the @layer base block.
     *
     * Tailwind v4's bundled preflight resets html/body/* globally, which
     * conflicts with WordPress themes. Instead we emit a preflight scoped to
     * the Proto-Blocks scope class so it only resets browser defaults
     * *inside* blocks. Site owners can opt out (back to the historical "no
     * preflight at all" behavior) via:
     *
     *   add_filter('proto_blocks_preflight', '__return_false');
     */
    private function getPreflightLayer(): string
    {
        $enabled = (bool) apply_filters('proto_blocks_preflight', true);

        if (! $enabled) {
            return "/* Preflight disabled via proto_blocks_preflight filter */";
        }

        $scope = Scoper::SCOPE_CLASS;

        return <<<CSS
/* Scoped preflight: resets browser defaults inside :where(.{$scope}) only.
   Emitted unlayered so its specificity beats WordPress' unlayered global
   styles (eg. theme.json link decoration). */
:where(.{$scope}),
:where(.{$scope}) *,
:where(.{$scope}) *::before,
:where(.{$scope}) *::after {
  box-sizing: border-box;
  border: 0 solid;
}

:where(.{$scope}) h1,
:where(.{$scope}) h2,
:where(.{$scope}) h3,
:where(.{$scope}) h4,
:where(.{$scope}) h5,
:where(.{$scope}) h6 {
  font-size: inherit;
  font-weight: inherit;
}

:where(.{$scope}) a {
  color: inherit;
  text-decoration: inherit;
}

:where(.{$scope}) b,
:where(.{$scope}) strong {
  font-weight: bolder;
}

:where(.{$scope}) code,
:where(.{$scope}) kbd,
:where(.{$scope}) samp,
:where(.{$scope}) pre {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
  font-size: 1em;
}

:where(.{$scope}) small {
  font-size: 80%;
}

:where(.{$scope}) sub,
:where(.{$scope}) sup {
  font-size: 75%;
  line-height: 0;
  position: relative;
  vertical-align: baseline;
}
:where(.{$scope}) sub { bottom: -0.25em; }
:where(.{$scope}) sup { top: -0.5em; }

:where(.{$scope}) table {
  border-collapse: collapse;
  border-color: inherit;
  text-indent: 0;
}

:where(.{$scope}) button,
:where(.{$scope}) input,
:where(.{$scope}) optgroup,
:where(.{$scope}) select,
:where(.{$scope}) textarea {
  font: inherit;
  font-feature-settings: inherit;
  font-variation-settings: inherit;
  color: inherit;
  margin: 0;
  padding: 0;
}

:where(.{$scope}) button,
:where(.{$scope}) select {
  text-transform: none;
}

:where(.{$scope}) button,
:where(.{$scope}) input:where([type="button"], [type="reset"], [type="submit"]) {
  -webkit-appearance: button;
  background-color: transparent;
  background-image: none;
  border: 0;
  cursor: pointer;
}

:where(.{$scope}) ol,
:where(.{$scope}) ul,
:where(.{$scope}) menu {
  list-style: none;
  margin: 0;
  padding: 0;
}

:where(.{$scope}) img,
:where(.{$scope}) svg,
:where(.{$scope}) video,
:where(.{$scope}) canvas,
:where(.{$scope}) audio,
:where(.{$scope}) iframe,
:where(.{$scope}) embed,
:where(.{$scope}) object {
  display: block;
  vertical-align: middle;
}
:where(.{$scope}) img,
:where(.{$scope}) video {
  max-width: 100%;
  height: auto;
}

:where(.{$scope}) [hidden] {
  display: none;
}
CSS;
    }

    /**
     * Whether a user-supplied theme file exists.
     */
    public function isModified(): bool
    {
        return $this->getThemeCss() !== '';
    }

    /**
     * Data for JavaScript / admin tooling. The textarea editor that used to
     * consume this has been removed; the page now shows the resolved path
     * and the file's contents as a read-only preview.
     *
     * @return array<string, mixed>
     */
    public function getConfigData(): array
    {
        $path     = $this->getThemeCssPath();
        $contents = $this->getThemeCss();

        return [
            'path'     => $path,
            'exists'   => $contents !== '',
            'editable' => false,
            'preview'  => $contents,
        ];
    }
}
