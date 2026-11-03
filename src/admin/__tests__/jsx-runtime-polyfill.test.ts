import { readFileSync } from 'fs';
import { resolve } from 'path';

// The polyfill Assets.php registers as `react-jsx-runtime` on WordPress < 6.6.
const source = readFileSync(resolve(__dirname, '../../../assets/admin/react-jsx-runtime-polyfill.js'), 'utf8');

type FakeWindow = { React?: Record<string, unknown>; ReactJSXRuntime?: Record<string, (...a: unknown[]) => unknown> };

function run(win: FakeWindow): FakeWindow {
	// eslint-disable-next-line no-new-func
	new Function('window', source)(win);
	return win;
}

function fakeReact() {
	return {
		Fragment: Symbol('Fragment'),
		createElement: jest.fn((type: unknown, config: unknown, ...children: unknown[]) => ({ type, config, children })),
	};
}

test('defines jsx, jsxs and Fragment from window.React', () => {
	const React = fakeReact();
	const win = run({ React });

	expect(win.ReactJSXRuntime?.Fragment).toBe(React.Fragment);
	expect(win.ReactJSXRuntime?.jsx('div', { id: 'a', children: 'hi' }, 'k')).toEqual({
		type: 'div',
		config: { id: 'a', children: 'hi', key: 'k' },
		children: [],
	});
});

test('jsxs passes static children as arguments', () => {
	const React = fakeReact();
	const win = run({ React });

	expect(win.ReactJSXRuntime?.jsxs('ul', { className: 'x', children: ['a', 'b'] })).toEqual({
		type: 'ul',
		config: { className: 'x' },
		children: ['a', 'b'],
	});
});

test('does not replace an existing runtime or run without React', () => {
	const existing = { jsx: jest.fn() };
	expect(run({ React: fakeReact(), ReactJSXRuntime: existing }).ReactJSXRuntime).toBe(existing);
	expect(run({}).ReactJSXRuntime).toBeUndefined();
});
