import { mockApi, mockBootstrap } from '../helpers';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';
import { toast } from 'sonner';
import { AdminApp } from '@/admin/app';
import { parseJsonc } from '@/admin/content-model/jsonc';
import type { FileResult, ListItem, ListResult } from '@/admin/content-model/types';

const ROOT = 'https://example.test/wp-json/proto-blocks/v1/';

// Radix measures checkboxes and tooltips with ResizeObserver, which jsdom lacks.
beforeAll(() => {
	if (!('ResizeObserver' in window)) {
		(window as unknown as { ResizeObserver: unknown }).ResizeObserver = class {
			observe() {}
			unobserve() {}
			disconnect() {}
		};
	}
});

afterEach(() => {
	delete window.protoBlocksAdmin;
	document.body.innerHTML = '';
	window.history.replaceState(null, '', '/');
	jest.restoreAllMocks();
	jest.clearAllMocks();
});

function item(overrides: Partial<ListItem> = {}): ListItem {
	return {
		slug: 'product',
		label: 'Products',
		origin: 'file',
		originLabel: 'Proto-Blocks file',
		source: 'wp-content/themes/demo/proto-types/product/type.jsonc',
		override: false,
		double: null,
		count: 0,
		editable: true,
		readOnlyReason: null,
		issues: [],
		...overrides,
	};
}

function list(overrides: Partial<ListResult> = {}): ListResult {
	return {
		moduleEnabled: true,
		createTarget: { path: 'wp-content/themes/demo/proto-types', writable: true, reason: null },
		items: [
			item(),
			item({
				slug: 'resource',
				label: 'Resources',
				origin: 'php',
				originLabel: 'Proto-Blocks PHP',
				source: 'wp-content/themes/demo/functions.php',
				double: { file: 'wp-content/themes/demo/proto-types/resource/type.jsonc', php: 'wp-content/themes/demo/functions.php' },
			}),
			item({
				slug: 'post',
				label: 'Posts',
				origin: 'core',
				originLabel: 'Core',
				source: null,
				count: 12,
				editable: false,
				readOnlyReason: 'not_proto',
			}),
		],
		issues: [],
		...overrides,
	};
}

function detail(overrides: Record<string, unknown> = {}, count = 0) {
	return {
		item: item({ count }),
		effective: {
			labels: { name: 'Products', singular_name: 'Product', menu_name: 'Products' },
			public: true,
			show_in_rest: true,
			hierarchical: false,
			has_archive: true,
			rewrite: { slug: 'product', with_front: true },
			supports: ['title', 'editor', 'thumbnail'],
			menu_position: null,
		},
		content: { count, taxonomies: {} },
		blockTheme: true,
		templates: [
			{ name: 'single-product.html', exists: true },
			{ name: 'archive-product.html', exists: false },
		],
		...overrides,
	};
}

function file(overrides: Partial<FileResult> = {}): FileResult {
	const text = overrides.text ?? '{ "name": "Product" }';
	return {
		exists: true,
		path: 'wp-content/themes/demo/proto-types/product/type.jsonc',
		text,
		hash: 'h1',
		data: parseJsonc(text).data,
		writable: true,
		reason: null,
		...overrides,
	};
}

const CORE_POST_SUPPORTS = [
	'title',
	'editor',
	'author',
	'thumbnail',
	'excerpt',
	'trackbacks',
	'custom-fields',
	'comments',
	'revisions',
	'post-formats',
];

const saved = { path: 'wp-content/themes/demo/proto-types/product/type.jsonc', hash: 'h2', created: false, warnings: [] };

function renderScreen(query = '', bootstrap = {}) {
	window.history.replaceState(null, '', `/wp-admin/admin.php?page=proto-blocks-post-types${query}`);
	const boot = mockBootstrap({ screen: 'post-types', ...bootstrap });
	render(<AdminApp bootstrap={boot} />);
	return boot;
}

function calls(): { url: string; method: string; data?: unknown }[] {
	return (apiFetch as unknown as jest.Mock).mock.calls.map(([options]) => ({
		url: String(options.url).replace(ROOT, ''),
		method: options.method ?? 'GET',
		data: options.data,
	}));
}

function posted(path: string): { text: string; hash: string | null; acknowledge: string[] }[] {
	return calls()
		.filter((call) => call.method === 'POST' && call.url === path)
		.map((call) => call.data as { text: string; hash: string | null; acknowledge: string[] });
}

async function openEdit(routes: Record<string, unknown>, bootstrap = {}) {
	mockApi({ 'admin/types': list(), ...routes });
	renderScreen('&type=product', bootstrap);
	return screen.findByRole('button', { name: 'Save to file' });
}

test('the list shows names, origins and the declared-twice badge', async () => {
	mockApi({ 'admin/types': list() });
	renderScreen();

	const product = (await screen.findByText('Products')).closest('tr') as HTMLElement;
	expect(within(product).getByText('Proto-Blocks file')).toBeInTheDocument();
	expect(within(product).getByRole('button', { name: 'Edit Products' })).toBeInTheDocument();

	const resource = screen.getByText('Resources').closest('tr') as HTMLElement;
	expect(within(resource).getByText('Proto-Blocks PHP')).toBeInTheDocument();
	expect(within(resource).getByText('Declared twice')).toBeInTheDocument();

	const posts = screen.getByRole('cell', { name: 'Posts' }).closest('tr') as HTMLElement;
	expect(within(posts).getByText('Core')).toBeInTheDocument();
	expect(within(posts).getByText('12')).toBeInTheDocument();
	expect(within(posts).getByRole('button', { name: 'Override Posts' })).toBeInTheDocument();
});

test('list issues show above the table', async () => {
	mockApi({
		'admin/types': list({
			issues: [{ level: 'error', code: 'parse', message: 'Broken event file.', source: 'proto-types/event/type.jsonc' }],
		}),
	});
	renderScreen();
	expect(await screen.findByText('Broken event file.')).toBeInTheDocument();
});

test('a disabled module shows the card and no actions', async () => {
	mockApi({ 'admin/types': list({ moduleEnabled: false }) });
	renderScreen();

	expect(await screen.findByText('The Post Types module is disabled by the proto_blocks_modules filter.')).toBeInTheDocument();
	expect(screen.queryByRole('button', { name: 'Add post type' })).not.toBeInTheDocument();
	expect(screen.queryByRole('button', { name: /^Edit/ })).not.toBeInTheDocument();
});

test('Edit puts the type in the URL', async () => {
	const user = userEvent.setup();
	mockApi({ 'admin/types': list(), 'admin/types/product': detail(), 'schema-files/type/product': file() });
	renderScreen();

	const product = (await screen.findByText('Products')).closest('tr') as HTMLElement;
	await user.click(within(product).getByRole('button', { name: 'Edit Products' }));

	expect(await screen.findByRole('button', { name: 'Save to file' })).toBeInTheDocument();
	expect(window.location.search).toContain('type=product');
});

test('a save writes only the fields the user changed', async () => {
	const user = userEvent.setup();
	await openEdit({
		'admin/types/product': detail(),
		'schema-files/type/product': file(),
		'POST schema-files/type/product': () => saved,
	});

	const position = screen.getByLabelText('Menu position');
	await user.clear(position);
	await user.type(position, '30');
	await user.click(screen.getByRole('button', { name: 'Save to file' }));

	await waitFor(() => expect(posted('schema-files/type/product')).toHaveLength(1));
	const [body] = posted('schema-files/type/product');
	expect(parseJsonc(body.text).data).toEqual({ name: 'Product', menu_position: 30 });
	expect(body.hash).toBe('h1');
	expect(body.acknowledge).toEqual([]);
});

test('an edit keeps the developer comment', async () => {
	const user = userEvent.setup();
	await openEdit({
		'admin/types/product': detail(),
		'schema-files/type/product': file({ text: '// keep me\n{ "name": "Product" }' }),
		'POST schema-files/type/product': () => saved,
	});

	await user.click(screen.getByRole('radio', { name: /No archive/ }));
	await user.click(screen.getByRole('button', { name: 'Save to file' }));

	await waitFor(() => expect(posted('schema-files/type/product')).toHaveLength(1));
	const [body] = posted('schema-files/type/product');
	expect(body.text).toContain('// keep me');
	expect(body.text).toContain('"has_archive": false');
});

test('removing a support with content confirms, then resends with acknowledge', async () => {
	const user = userEvent.setup();
	const post = jest.fn((body: { acknowledge: string[] }) => {
		if (!body.acknowledge.includes('supports_removed')) {
			throw {
				code: 'proto_blocks_needs_confirmation',
				message: 'Confirm.',
				data: {
					status: 409,
					guardrails: [{ code: 'supports_removed', message: '3 products lose the editor box.', count: 3, detail: ['editor'] }],
				},
			};
		}
		return saved;
	});
	await openEdit({
		'admin/types/product': detail({}, 3),
		'schema-files/type/product': file(),
		'POST schema-files/type/product': post,
	});

	await user.click(screen.getByRole('checkbox', { name: 'Editor' }));
	await user.click(screen.getByRole('button', { name: 'Save to file' }));

	const dialog = await screen.findByRole('alertdialog');
	expect(within(dialog).getByText(/\b3\b/)).toBeInTheDocument();
	await user.click(within(dialog).getByRole('button', { name: 'Save anyway' }));

	await waitFor(() => expect(post).toHaveBeenCalledTimes(2));
	const bodies = posted('schema-files/type/product');
	expect(bodies[1].acknowledge).toEqual(['supports_removed']);
	expect(parseJsonc(bodies[1].text).data.supports).toEqual(['title', 'thumbnail']);
});

test('a conflict opens the dialog and Reload refetches the file', async () => {
	const user = userEvent.setup();
	await openEdit({
		'admin/types/product': detail(),
		'schema-files/type/product': file(),
		'POST schema-files/type/product': () => {
			throw { code: 'proto_blocks_conflict', message: 'Changed.', data: { status: 409, hash: 'h9' } };
		},
	});

	await user.click(screen.getByRole('checkbox', { name: 'Excerpt' }));
	await user.click(screen.getByRole('button', { name: 'Save to file' }));

	const dialog = await screen.findByRole('alertdialog');
	expect(within(dialog).getByText('The file changed on disk.')).toBeInTheDocument();
	const before = calls().filter((call) => call.method === 'GET' && call.url === 'schema-files/type/product').length;
	await user.click(within(dialog).getByRole('button', { name: 'Reload file' }));

	await waitFor(() =>
		expect(calls().filter((call) => call.method === 'GET' && call.url === 'schema-files/type/product').length).toBe(before + 1)
	);
	await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument());
});

test('the slug is read-only with the rename command when the type has posts', async () => {
	await openEdit({ 'admin/types/product': detail({}, 46), 'schema-files/type/product': file() });

	const slug = screen.getByLabelText(/^Slug/);
	expect(slug).toHaveValue('product');
	expect(slug).toHaveAttribute('readonly');
	expect(screen.getByText(/This type has 46 posts\./)).toBeInTheDocument();
	expect(screen.getByText('wp proto-blocks type rename product <new> --migrate')).toBeInTheDocument();
});

test('read-only mode shows the banner and disables writes', async () => {
	await openEdit(
		{ 'admin/types/product': detail(), 'schema-files/type/product': file() },
		{ fileModsAllowed: false, fileModsConstant: 'DISALLOW_FILE_MODS' }
	);

	expect(screen.getByText('File modifications are disabled on this site')).toBeInTheDocument();
	expect(screen.getByRole('button', { name: 'Save to file' })).toBeDisabled();
	expect(screen.getByRole('button', { name: 'Delete…' })).toBeDisabled();
	expect(screen.getByRole('button', { name: 'Scaffold templates' })).toBeDisabled();
	expect(screen.getByLabelText('Menu position')).toBeDisabled();
	expect(screen.getByRole('checkbox', { name: 'Editor' })).toBeDisabled();
});

test('read-only list disables Add post type', async () => {
	mockApi({ 'admin/types': list() });
	renderScreen('', { fileModsAllowed: false, fileModsConstant: 'DISALLOW_FILE_MODS' });
	expect(await screen.findByRole('button', { name: 'Add post type' })).toBeDisabled();
});

test('Scaffold is disabled on a classic theme', async () => {
	await openEdit({ 'admin/types/product': detail({ blockTheme: false }), 'schema-files/type/product': file() });

	expect(screen.getByRole('button', { name: 'Scaffold templates' })).toBeDisabled();
	expect(screen.getAllByText('Classic theme: build templates in your theme').length).toBeGreaterThan(0);
});

test('Scaffold posts the taxonomy choice and toasts the result', async () => {
	const user = userEvent.setup();
	const success = jest.spyOn(toast, 'success');
	const scaffold = jest.fn(() => ({ written: ['single-product.html', 'archive-product.html'], skipped: ['taxonomy-x.html'] }));
	await openEdit({
		'admin/types/product': detail(),
		'schema-files/type/product': file(),
		'POST admin/types/product/scaffold': scaffold,
	});

	expect(screen.getByText('single-product.html')).toBeInTheDocument();
	expect(screen.getByText(/never overwritten/)).toBeInTheDocument();
	await user.click(screen.getByRole('checkbox', { name: 'Also taxonomy templates' }));
	await user.click(screen.getByRole('button', { name: 'Scaffold templates' }));

	await waitFor(() => expect(scaffold).toHaveBeenCalledWith({ taxonomies: true }));
	await waitFor(() => expect(success).toHaveBeenCalledWith('Wrote 2 templates, skipped 1.'));
});

test('deleting a type with posts needs the typed slug and sends confirm', async () => {
	const user = userEvent.setup();
	const remove = jest.fn(() => ({ deleted: true, kept: [] }));
	await openEdit({
		'admin/types/product': detail({}, 4),
		'schema-files/type/product': file(),
		'DELETE schema-files/type/product?confirm=product': remove,
	});

	await user.click(screen.getByRole('button', { name: 'Delete…' }));
	const dialog = await screen.findByRole('alertdialog');
	const confirm = within(dialog).getByRole('button', { name: 'Delete declaration' });
	expect(confirm).toBeDisabled();
	await user.type(within(dialog).getByLabelText(/Type .* to confirm/), 'product');
	await user.click(confirm);

	await waitFor(() => expect(remove).toHaveBeenCalled());
	await waitFor(() => expect(window.location.search).not.toContain('type='));
});

test('a PHP-only type explains where to remove it and offers no delete', async () => {
	mockApi({
		'admin/types': list(),
		'admin/types/resource': detail({
			item: item({ slug: 'resource', label: 'Resources', origin: 'php', originLabel: 'Proto-Blocks PHP', source: 'wp-content/themes/demo/functions.php:14' }),
		}),
		'schema-files/type/resource?name=Product': file({ exists: false, hash: null }),
	});
	renderScreen('&type=resource');

	expect(await screen.findByRole('button', { name: 'Create file & save' })).toBeInTheDocument();
	expect(screen.getByText(/remove the line from your theme/)).toBeInTheDocument();
	expect(screen.getByText('wp-content/themes/demo/functions.php:14')).toBeInTheDocument();
	expect(screen.queryByRole('button', { name: 'Delete…' })).not.toBeInTheDocument();
});

test('Override opens a create form without a name and saves an override file', async () => {
	const user = userEvent.setup();
	const post = jest.fn(() => saved);
	mockApi({
		'admin/types': list(),
		'admin/types/post': detail({
			item: item({ slug: 'post', label: 'Posts', origin: 'core', originLabel: 'Core', source: null, editable: false, readOnlyReason: 'not_proto' }),
			// As PostTypesRoutes sends it: array_keys(get_all_post_type_supports('post')).
			effective: { ...detail().effective, supports: CORE_POST_SUPPORTS },
		}),
		'schema-files/type/post': file({
			exists: false,
			hash: null,
			path: 'wp-content/themes/demo/proto-types/post/type.jsonc',
			text: '{\n  "name": "Post",\n  "fields": []\n}\n',
		}),
		'POST schema-files/type/post': post,
	});
	renderScreen();

	const posts = (await screen.findByRole('cell', { name: 'Posts' })).closest('tr') as HTMLElement;
	await user.click(within(posts).getByRole('button', { name: 'Override Posts' }));
	const submit = await screen.findByRole('button', { name: 'Create override file' });
	expect(submit).toBeEnabled();

	for (const name of ['Title', 'Editor', 'Featured image', 'Excerpt', 'Revisions', 'Custom fields', 'Comments', 'Author']) {
		expect(screen.getByRole('checkbox', { name })).toBeChecked();
	}
	expect(screen.getByRole('checkbox', { name: 'Page attributes' })).not.toBeChecked();
	await user.click(screen.getByRole('checkbox', { name: 'Page attributes' }));
	await user.click(submit);

	await waitFor(() => expect(post).toHaveBeenCalled());
	const [body] = posted('schema-files/type/post');
	expect(body.hash).toBeNull();
	const data = parseJsonc(body.text).data;
	expect(data.name).toBeUndefined();
	expect(data.supports).toEqual([...CORE_POST_SUPPORTS, 'page-attributes']);
});

test('a Proto-Blocks type with no supports anywhere shows the plugin defaults and keeps them on save', async () => {
	const user = userEvent.setup();
	const { supports: _supports, ...effective } = detail().effective;
	await openEdit({
		'admin/types/product': detail({ effective }),
		'schema-files/type/product': file(),
		'POST schema-files/type/product': () => saved,
	});

	for (const name of ['Title', 'Editor', 'Featured image', 'Excerpt', 'Revisions']) {
		expect(screen.getByRole('checkbox', { name })).toBeChecked();
	}
	await user.click(screen.getByRole('checkbox', { name: 'Comments' }));
	await user.click(screen.getByRole('button', { name: 'Save to file' }));

	await waitFor(() => expect(posted('schema-files/type/product')).toHaveLength(1));
	expect(parseJsonc(posted('schema-files/type/product')[0].text).data.supports).toEqual([
		'title',
		'editor',
		'thumbnail',
		'excerpt',
		'revisions',
		'comments',
	]);
});

test('rewrite: false keeps the URL controls off and is never re-enabled by a save', async () => {
	const user = userEvent.setup();
	await openEdit({
		'admin/types/product': detail(),
		'schema-files/type/product': file({ text: '{ "name": "Product", "rewrite": false }' }),
		'POST schema-files/type/product': () => saved,
	});

	expect(screen.getByLabelText('URL slug')).toBeDisabled();
	expect(screen.getByText(/Pretty URLs are off for this type/)).toBeInTheDocument();
	expect(screen.queryByRole('switch', { name: 'Prefix with permalink base' })).not.toBeInTheDocument();

	await user.click(screen.getByRole('checkbox', { name: 'Comments' }));
	await user.click(screen.getByRole('button', { name: 'Save to file' }));

	await waitFor(() => expect(posted('schema-files/type/product')).toHaveLength(1));
	expect(parseJsonc(posted('schema-files/type/product')[0].text).data.rewrite).toBe(false);
});

test('removing a taxonomy row keeps the next row and its typed value', async () => {
	const user = userEvent.setup();
	await openEdit({
		'admin/types/product': detail(),
		'schema-files/type/product': file({ text: '{ "name": "Product", "taxonomies": [{ "name": "Brand" }, { "name": "Colour" }] }' }),
	});

	const names = screen.getAllByLabelText('Taxonomy name');
	expect(names.map((input) => (input as HTMLInputElement).value)).toEqual(['Brand', 'Colour']);
	const colour = names[1];
	await user.click(screen.getByRole('button', { name: 'Remove Brand' }));

	const after = screen.getAllByLabelText('Taxonomy name');
	expect(after).toHaveLength(1);
	expect(after[0]).toBe(colour);
	expect(after[0]).toHaveValue('Colour');
});

test('form sections are labelled regions', async () => {
	await openEdit({ 'admin/types/product': detail(), 'schema-files/type/product': file() });
	expect(screen.getByRole('region', { name: 'Editor' })).toBeInTheDocument();
	expect(screen.getByRole('region', { name: 'Archive and URLs' })).toBeInTheDocument();
});

test('creating a post type fetches the template, posts it and opens the edit form', async () => {
	const user = userEvent.setup();
	const template = '// Proto-Blocks post type declaration.\n{\n  "name": "Case Study",\n  "fields": []\n}\n';
	const post = jest.fn(() => ({ ...saved, path: 'wp-content/themes/demo/proto-types/case-study/type.jsonc', created: true }));
	mockApi({
		'admin/types': list(),
		'schema-files/type/case-study?name=Case%20Study': file({ exists: false, hash: null, text: template }),
		'POST schema-files/type/case-study': post,
	});
	renderScreen();

	await user.click(await screen.findByRole('button', { name: 'Add post type' }));
	const dialog = await screen.findByRole('dialog');
	await user.type(within(dialog).getByLabelText('Singular name'), 'Case Study');

	expect(within(dialog).getByLabelText('Slug')).toHaveValue('case-study');
	expect(within(dialog).getByText('Case Studies')).toBeInTheDocument();
	expect(within(dialog).getByText('wp-content/themes/demo/proto-types/case-study/type.jsonc')).toBeInTheDocument();

	await user.click(within(dialog).getByRole('button', { name: 'Create post type' }));

	await waitFor(() => expect(post).toHaveBeenCalled());
	const get = calls().find((call) => call.url === 'schema-files/type/case-study?name=Case%20Study');
	expect(get?.method).toBe('GET');
	const [body] = posted('schema-files/type/case-study');
	expect(body).toEqual({ text: expect.stringContaining('"name": "Case Study"'), hash: null, acknowledge: [] });
	await waitFor(() => expect(window.location.search).toContain('type=case-study'));
});

test('the create dialog rejects a taken slug and shows server slug errors', async () => {
	const user = userEvent.setup();
	mockApi({
		'admin/types': list(),
		'schema-files/type/order?name=Order': () => {
			throw { code: 'proto_blocks_invalid_slug', message: 'This slug is reserved by WordPress.', data: { status: 400 } };
		},
	});
	renderScreen('&new=1');

	const dialog = await screen.findByRole('dialog');
	await user.type(within(dialog).getByLabelText('Singular name'), 'Product');
	expect(within(dialog).getByText('A post type with this slug already exists.')).toBeInTheDocument();
	expect(within(dialog).getByRole('button', { name: 'Create post type' })).toBeDisabled();

	await user.clear(within(dialog).getByLabelText('Singular name'));
	await user.type(within(dialog).getByLabelText('Singular name'), 'Order');
	await user.click(within(dialog).getByRole('button', { name: 'Create post type' }));
	expect(await within(dialog).findByText('This slug is reserved by WordPress.')).toBeInTheDocument();
});

test('a value the schema rejects blocks the save and is shown at the field', async () => {
	const user = userEvent.setup();
	const post = jest.fn(() => saved);
	await openEdit({
		'admin/types/product': detail(),
		'schema-files/type/product': file(),
		'POST schema-files/type/product': post,
	});

	const position = screen.getByLabelText('Menu position');
	await user.clear(position);
	await user.type(position, '20000');
	await user.click(screen.getByRole('button', { name: 'Save to file' }));

	expect(await screen.findByText('Must be at most 10000.')).toBeInTheDocument();
	expect(post).not.toHaveBeenCalled();
});

test('the File panel previews the text Save would write', async () => {
	const user = userEvent.setup();
	await openEdit({
		'admin/types/product': detail(),
		'schema-files/type/product': file({ text: '// keep me\n{ "name": "Product" }' }),
	});

	const toggle = screen.getByRole('button', { name: 'File' });
	await user.click(toggle);
	expect(toggle).toHaveAttribute('aria-pressed', 'true');
	await user.click(screen.getByRole('radio', { name: /No archive/ }));

	const preview = screen.getByLabelText('File text that Save to file writes');
	expect(preview.textContent).toContain('// keep me');
	expect(preview.textContent).toContain('"has_archive": false');
});
