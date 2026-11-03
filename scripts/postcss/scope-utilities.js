/**
 * PostCSS plugin: confines a Tailwind 4 build to its app roots.
 *
 * Stylesheets loaded on shared wp-admin screens (the field boxes on every
 * post edit screen) must not ship global `.hidden`/`.fixed`/`.table`
 * utilities, which would also hit other plugins' markup and core classes.
 * For each configured entry stylesheet this plugin:
 *  - prefixes every rule inside `@layer utilities` and `@layer properties`
 *    (including nested @media/@container/@supports) with `:where(<roots>) `,
 *    so utilities apply only inside the roots and keep their specificity;
 *  - moves the theme variables from `:root, :host` onto the roots;
 *  - renames each `@keyframes x` to `pb-x` (and the animation values that
 *    use it), so it cannot clash with another stylesheet's `spin`.
 *
 * Options: `{ files: { '<entry>': ['selector', ...] } }`, where an entry
 * matches the processed file's basename or the end of its path
 * (`admin.css`, `fields/box/styles.css`). Other files pass through.
 * Runs after @tailwindcss/postcss (see postcss.config.js).
 */
const path = require( 'path' );

const KEYFRAME_PREFIX = 'pb-';

function rootsFor( file, files ) {
	if ( ! file ) {
		return null;
	}
	const normalized = file.split( path.sep ).join( '/' );
	for ( const [ entry, selectors ] of Object.entries( files ) ) {
		if ( normalized === entry || normalized.endsWith( '/' + entry ) ) {
			return selectors;
		}
	}
	return null;
}

function layerName( node ) {
	for ( let p = node.parent; p; p = p.parent ) {
		if ( p.type === 'atrule' && p.name === 'layer' ) {
			return p.params.trim();
		}
	}
	return null;
}

function insideKeyframes( node ) {
	for ( let p = node.parent; p; p = p.parent ) {
		if ( p.type === 'atrule' && /keyframes$/i.test( p.name ) ) {
			return true;
		}
	}
	return false;
}

const plugin = ( opts = {} ) => {
	const files = opts.files || {};
	return {
		postcssPlugin: 'proto-blocks-scope-utilities',
		Once( root ) {
			const roots = rootsFor( root.source && root.source.input && root.source.input.file, files );
			if ( ! roots || roots.length === 0 ) {
				return;
			}
			const scope = `:where(${ roots.join( ', ' ) })`;

			// Keyframes first, so the animation values can be rewritten in the same pass below.
			const renamed = new Map();
			root.walkAtRules( /keyframes$/i, ( rule ) => {
				const name = rule.params.trim();
				if ( ! name.startsWith( KEYFRAME_PREFIX ) ) {
					renamed.set( name, KEYFRAME_PREFIX + name );
					rule.params = KEYFRAME_PREFIX + name;
				}
			} );
			if ( renamed.size ) {
				const names = [ ...renamed.keys() ].map( ( n ) => n.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) );
				const re = new RegExp( `(^|[\\s,])(${ names.join( '|' ) })(?=$|[\\s,;])`, 'g' );
				root.walkDecls( ( decl ) => {
					if ( /^(animation|animation-name|--animate-.*)$/i.test( decl.prop ) ) {
						decl.value = decl.value.replace( re, ( _m, pre, n ) => pre + renamed.get( n ) );
					}
				} );
			}

			root.walkRules( ( rule ) => {
				if ( insideKeyframes( rule ) ) {
					return;
				}
				const layer = layerName( rule );
				if ( layer === 'theme' ) {
					const kept = rule.selectors.filter( ( s ) => ! /^(:root|:host)$/.test( s.trim() ) );
					if ( kept.length !== rule.selectors.length ) {
						rule.selectors = [ ...new Set( [ ...kept, ...roots ] ) ];
					}
					return;
				}
				if ( layer === 'utilities' || layer === 'properties' ) {
					rule.selectors = rule.selectors.map( ( s ) => ( s.startsWith( scope ) ? s : `${ scope } ${ s.trim() }` ) );
				}
			} );
		},
	};
};
plugin.postcss = true;

module.exports = plugin;
