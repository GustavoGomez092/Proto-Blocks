/**
 * PostCSS plugin: scopes the admin stylesheet's Tailwind output to the app.
 *
 * Tailwind emits utilities as global `!important` class rules (`.hidden`,
 * `.fixed`, `.table`, `.border`, ...). The admin CSS only loads on
 * Proto-Blocks screens, but those screens still render core and third-party
 * markup (notices, the admin menu), which the utilities would hit. This
 * plugin runs after @tailwindcss/postcss and:
 *
 * - prefixes every rule selector inside `@layer utilities` with
 *   `:where(#proto-blocks-admin, #proto-blocks-admin-portal) `. `:where()`
 *   adds no specificity, so the utilities behave exactly as before inside
 *   the app and match nothing outside it;
 * - renames every `@keyframes <name>` to `pb-<name>` and rewrites the
 *   animation references, so wp-admin's own `spin`/`pulse` keyframes are
 *   neither overridden nor overriding.
 *
 * Only the admin entry stylesheet is touched; other project CSS passes
 * through unchanged.
 */
const SCOPE = ':where(#proto-blocks-admin, #proto-blocks-admin-portal)';
const KEYFRAME_PREFIX = 'pb-';
const DEFAULT_FILE_PATTERN = /[\\/]src[\\/]admin[\\/]styles[\\/]admin\.css$/;

function hasAncestor(node, test) {
	for (let parent = node.parent; parent; parent = parent.parent) {
		if (test(parent)) {
			return true;
		}
	}
	return false;
}

const isKeyframes = (node) => node.type === 'atrule' && /keyframes$/i.test(node.name);
const isRule = (node) => node.type === 'rule';

function scopeSelector(selector) {
	const trimmed = selector.trim();
	return trimmed.startsWith(SCOPE) ? trimmed : `${SCOPE} ${trimmed}`;
}

function escapeRegExp(value) {
	return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * @param {{ filePattern?: RegExp|null }} [options] filePattern limits the
 *   plugin to matching input files; null applies it to every file (tests).
 */
module.exports = (options = {}) => {
	const filePattern = options.filePattern === undefined ? DEFAULT_FILE_PATTERN : options.filePattern;

	return {
		postcssPlugin: 'proto-blocks-scope-admin-utilities',
		OnceExit(root) {
			const file = root.source && root.source.input && root.source.input.file;
			if (filePattern && !(file && filePattern.test(file))) {
				return;
			}

			// 1. Scope utilities. Nested rules are relative to an already
			// scoped parent, so only the outermost rules get the prefix.
			root.walkAtRules('layer', (layer) => {
				if (!layer.nodes || !layer.params.split(',').some((p) => p.trim() === 'utilities')) {
					return;
				}
				layer.walkRules((rule) => {
					if (hasAncestor(rule, isKeyframes) || hasAncestor(rule, isRule)) {
						return;
					}
					rule.selectors = rule.selectors.map(scopeSelector);
				});
			});

			// 2. Namespace keyframes and their references.
			const renamed = new Map();
			root.walkAtRules(/keyframes$/i, (atRule) => {
				const name = atRule.params.trim();
				if (!name.startsWith(KEYFRAME_PREFIX)) {
					renamed.set(name, KEYFRAME_PREFIX + name);
					atRule.params = KEYFRAME_PREFIX + name;
				}
			});
			if (renamed.size === 0) {
				return;
			}
			const names = [...renamed.keys()].map(escapeRegExp).join('|');
			const pattern = new RegExp(`(^|[\\s,])(${names})(?=$|[\\s,])`, 'g');
			root.walkDecls(/^(-webkit-)?animation(-name)?$|^--animate-/i, (decl) => {
				decl.value = decl.value.replace(pattern, (match, lead, name) => lead + renamed.get(name));
			});
		},
	};
};

module.exports.postcss = true;
module.exports.SCOPE = SCOPE;
