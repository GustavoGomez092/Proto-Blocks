/**
 * Proto-Blocks: `react-jsx-runtime` for WordPress < 6.6.
 *
 * Bundles built by @wordpress/scripts import the automatic JSX runtime from
 * the `ReactJSXRuntime` global, which core only registers from 6.6. This
 * rebuilds it on top of window.React when core does not provide it.
 */
(function (win) {
	if (win.ReactJSXRuntime || !win.React) {
		return;
	}
	var React = win.React;
	var hasOwn = Object.prototype.hasOwnProperty;

	function copy(props) {
		var out = {};
		for (var name in props) {
			if (hasOwn.call(props, name)) {
				out[name] = props[name];
			}
		}
		return out;
	}

	function jsx(type, props, key) {
		var config = copy(props);
		if (key !== undefined) {
			config.key = key;
		}
		return React.createElement(type, config);
	}

	// Static children arrive as an array; pass them as arguments so React
	// does not ask for keys on them.
	function jsxs(type, props, key) {
		var config = copy(props);
		var children = config.children;
		delete config.children;
		if (key !== undefined) {
			config.key = key;
		}
		return React.createElement.apply(React, [type, config].concat(children));
	}

	win.ReactJSXRuntime = { jsx: jsx, jsxs: jsxs, Fragment: React.Fragment };
})(window);
