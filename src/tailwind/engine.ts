/**
 * Browser Tailwind v4 compile engine wrapper.
 *
 * Proto-Blocks normally compiles Tailwind by shelling out to the standalone
 * Tailwind CLI via PHP `exec()`. Managed hosts (e.g. WP Engine) disable all
 * shell functions, so that path is impossible there. The alternative — used by
 * the WindPress plugin — is to compile Tailwind v4 entirely in the browser and
 * persist the resulting CSS server-side via plain PHP.
 *
 * This module is that browser compile engine. It is the ONLY place that knows
 * about the Tailwind v4 engine API. Downstream code depends on the exact
 * signature `compileTailwind(inputCss, content): Promise<string>` and treats
 * everything else here as an implementation detail.
 *
 * Engine wiring:
 *   - `tailwindcss` v4 `compile(css, opts)` produces a builder. We feed it the
 *     user-provided input stylesheet (which uses `@import "tailwindcss/..."`).
 *   - `@import` directives are resolved by `loadStylesheet` against the CSS
 *     files bundled inside the `tailwindcss` package (theme/preflight/utilities/
 *     index). Webpack inlines those files as raw strings (see imports below) so
 *     no filesystem or network access is needed at runtime.
 *   - Utility class candidates are extracted from `content` with a pure-JS
 *     tokenizer (see `extractCandidates`). We deliberately avoid
 *     `@tailwindcss/oxide`'s native Scanner here because it is a Node-native
 *     addon that does not bundle to the browser; the regex tokenizer is the
 *     pragmatic, browser-safe equivalent.
 *   - `builder.build(candidates)` returns the compiled CSS string.
 */

import { compile } from 'tailwindcss-v4';
import initLightning, { transform as lightningTransform, Features } from 'lightningcss-wasm';

// The Tailwind v4 engine is installed under the `tailwindcss-v4` npm alias
// (package.json) so it does not collide with the project's existing Tailwind v3
// PostCSS build. Raw CSS shipped inside that package is imported as source
// strings (webpack `type: 'asset/source'`) and fed to compile() at runtime.
import indexCss from 'tailwindcss-v4/index.css';
import themeCss from 'tailwindcss-v4/theme.css';
import preflightCss from 'tailwindcss-v4/preflight.css';
import utilitiesCss from 'tailwindcss-v4/utilities.css';

/**
 * Map of the bundled Tailwind stylesheet specifiers to their raw contents.
 * Keys cover the forms an input stylesheet may `@import`.
 */
const BUNDLED_STYLESHEETS: Record<string, string> = {
	tailwindcss: indexCss,
	'tailwindcss/index': indexCss,
	'tailwindcss/index.css': indexCss,
	'tailwindcss/theme': themeCss,
	'tailwindcss/theme.css': themeCss,
	'tailwindcss/preflight': preflightCss,
	'tailwindcss/preflight.css': preflightCss,
	'tailwindcss/utilities': utilitiesCss,
	'tailwindcss/utilities.css': utilitiesCss,
};

/**
 * Resolve an `@import` specifier to one of the bundled Tailwind stylesheets.
 * Strips relative prefixes and any leading `./` so both
 * `@import "tailwindcss/utilities.css"` and `@import "./tailwindcss/..."`
 * resolve correctly.
 */
function resolveStylesheet(id: string): string | null {
	const normalized = id.replace(/^\.\//, '');
	if (normalized in BUNDLED_STYLESHEETS) {
		return BUNDLED_STYLESHEETS[normalized];
	}
	// Tolerate a trailing/missing `.css` mismatch.
	const withoutExt = normalized.replace(/\.css$/, '');
	if (withoutExt in BUNDLED_STYLESHEETS) {
		return BUNDLED_STYLESHEETS[withoutExt];
	}
	return null;
}

/**
 * Extract utility-class candidate tokens from arbitrary markup.
 *
 * Tailwind's own Oxide scanner is permissive: it treats almost any
 * whitespace/quote/bracket-delimited token as a potential candidate and lets
 * the engine decide which ones are real utilities. We mirror that with a broad
 * tokenizer rather than parsing `class="..."` only, so arbitrary values,
 * variants (`md:`, `hover:`), and class usage outside of `class` attributes are
 * all captured.
 */
function extractCandidates(content: string): string[] {
	const candidates = new Set<string>();
	// Tokens: word chars plus the punctuation Tailwind utilities use
	// (-, :, /, ., %, brackets, parens, commas, #, etc. for arbitrary values).
	const tokenRegex = /[^\s"'`<>=]+/g;
	let match: RegExpExecArray | null;
	while ((match = tokenRegex.exec(content)) !== null) {
		const token = match[0];
		// Skip obvious non-candidates (pure punctuation, attribute fragments).
		if (token.length === 0) {
			continue;
		}
		candidates.add(token);
	}
	return Array.from(candidates);
}

/**
 * Compile a Tailwind v4 input stylesheet against a body of markup, returning
 * the generated (unscoped) CSS.
 *
 * @param inputCss A Tailwind v4 input stylesheet, e.g. `@import "tailwindcss/utilities.css";`.
 * @param content  Aggregated HTML/markup to scan for utility class candidates.
 * @returns        The compiled CSS string.
 */
export async function compileTailwind(
	inputCss: string,
	content: string
): Promise<string> {
	const compiler = await compile(inputCss, {
		base: '/',
		async loadStylesheet(id: string, base: string) {
			const resolved = resolveStylesheet(id);
			if (resolved === null) {
				throw new Error(
					`[proto-blocks] Cannot resolve Tailwind stylesheet import: "${id}"`
				);
			}
			return {
				path: id,
				base,
				content: resolved,
			};
		},
		async loadModule() {
			// Browser engine does not support JS config/plugin modules.
			throw new Error(
				'[proto-blocks] JS module imports (@plugin / @config) are not supported in the browser engine.'
			);
		},
	});

	const candidates = extractCandidates(content);
	const rawCss = compiler.build(candidates);

	// Match the standalone CLI byte-for-byte. The CLI compiles with `--minify`,
	// which runs the output through Lightning CSS (Tailwind's `optimizeCss`).
	// That pass FLATTENS Tailwind v4's native nested CSS (e.g. a responsive
	// variant emits `.lg\:ml-0 { @media (width>=64rem) { … } }`) into the
	// classic flat form `@media (min-width:64rem) { .lg\:ml-0 { … } }` and
	// minifies. The server-side scoper only understands that flat form, so the
	// browser engine MUST apply the same pass — otherwise nested variants are
	// mangled (the bug this fixes). We replicate Tailwind v4.3.0's exact
	// `optimizeCss` options so both engines produce identical CSS.
	return optimizeCss(rawCss);
}

/**
 * Lazily initialise the Lightning CSS WebAssembly module (once).
 */
let lightningReady: Promise<unknown> | null = null;
function ensureLightning(): Promise<unknown> {
	if (lightningReady === null) {
		lightningReady = initLightning();
	}
	return lightningReady;
}

/**
 * Run CSS through Lightning CSS with the EXACT options Tailwind v4.3.0's
 * `@tailwindcss/node` `optimizeCss` uses (see the v4.3.0 source). Critically:
 *   - `include: Nesting | MediaQueries` lowers native nesting + range media
 *     queries to the classic flat form the scoper expects.
 *   - run twice, so adjacent rules merge after nesting is applied.
 *   - `minify: true` to match the CLI's `--minify`.
 * lightningcss-wasm is pinned to 1.32.0 to match the `lightningcss` the
 * Tailwind v4.3.0 npm engine (and the standalone CLI binary) bundle.
 */
async function optimizeCss(css: string): Promise<string> {
	await ensureLightning();

	const options = {
		filename: 'input.css',
		minify: true,
		sourceMap: false,
		drafts: { customMedia: true },
		nonStandard: { deepSelectorCombinator: true },
		include: Features.Nesting | Features.MediaQueries,
		exclude: Features.LogicalProperties | Features.DirSelector | Features.LightDark,
		targets: {
			safari: (16 << 16) | (4 << 8),
			ios_saf: (16 << 16) | (4 << 8),
			firefox: 128 << 16,
			chrome: 111 << 16,
		},
		errorRecovery: true,
	} as const;

	const encoder = new TextEncoder();
	const decoder = new TextDecoder();

	// First pass applies nesting; second pass optimizes the merged rules.
	let code = lightningTransform({ ...options, code: encoder.encode(css) }).code;
	code = lightningTransform({ ...options, code }).code;

	return decoder.decode(code);
}
