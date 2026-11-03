import { mockApi, mockBootstrap } from './helpers';
import { readFileSync } from 'fs';
import { join } from 'path';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';
import { AdminApp } from '@/admin/app';
import { parseJsonc } from '@/admin/content-model/jsonc';
import type { FileResult } from '@/admin/content-model/types';
import type { OptionsDetail, OptionsIndex, OptionsPageRow } from '@/admin/options-pages/declaration';

const treeProps: { fields: unknown[]; readOnly?: boolean }[] = [];

let mockRealTree = false;
jest.mock('@/admin/fields/builder', () => {
	const actual = jest.requireActual('@/admin/fields/builder');
	return {
		...actual,
		FieldTree: (props: { fields: { name?: string }[]; readOnly?: boolean }) => {
			if (mockRealTree) { const Real = actual.FieldTree; return <Real {...props} />; }
			treeProps.push(props);
			return <div data-testid="field-tree">{props.fields.map((f) => f.name).join(',')}</div>;
		},
		FieldSettings: () => <div data-testid="field-settings" />,
		AddFieldMenu: () => null,
	};
});

const ROOT = 'https://example.test/wp-json/proto-blocks/v1/';
const SLUG = 'cadco-display-settings';
const cadcoText = readFileSync(join(__dirname, '../../../tests/fixtures/options-pages/cadco-display-settings/page.jsonc'), 'utf8');
const cadcoData = parseJsonc(cadcoText).data;

// The screens are React.lazy() dynamic imports. The first test in this file
// would otherwise pay the cold transform of the whole screen module graph
// inside findBy*'s 1 s window, which a starved CI runner or a busy worker
// pool overruns. Load the modules up front, under the hook's own timeout.
beforeAll(async () => {
	await import('@/admin/screens/OptionsPagesScreen');
	await import('@/admin/screens/OptionsPageScreen');
}, 60000);

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
	treeProps.length = 0;
	jest.clearAllMocks();
});

function row(overrides: Partial<OptionsPageRow> = {}): OptionsPageRow {
	return {
		slug: SLUG,
		title: 'Display Settings',
		menu: 'edit.php?post_type=product',
		menuLabel: 'Under Products',
		capability: 'manage_woocommerce',
		storage: 'Array: cadco_display_settings',
		origin: 'file',
		originLabel: 'Proto-Blocks file',
		double: null,
		source: `wp-content/themes/demo/proto-options/${SLUG}/page.jsonc`,
		editable: true,
		readOnlyReason: null,
		children: [],
		warnings: [],
		url: `https://example.test/wp-admin/admin.php?page=${SLUG}`,
		...overrides,
	};
}

const phpRow = row({
	slug: 'announcement',
	title: 'Announcement Bar',
	menu: 'options-general.php',
	menuLabel: 'Under Settings',
	capability: 'edit_posts',
	storage: 'Per field',
	origin: 'php',
	originLabel: 'Proto-Blocks PHP',
	double: { file: 'wp-content/themes/demo/proto-options/announcement/page.jsonc', php: 'functions.php:40' },
	source: 'functions.php:40',
	editable: false,
	url: 'https://example.test/wp-admin/admin.php?page=announcement',
});

function index(overrides: Partial<OptionsIndex> = {}): OptionsIndex {
	return {
		moduleEnabled: true,
		createTarget: { path: 'wp-content/themes/demo/proto-options', writable: true, reason: null },
		pages: [row(), phpRow],
		external: [{ slug: 'wc-settings', title: 'WooCommerce Settings', parent: 'woocommerce', origin: 'plugin', originLabel: 'Plugin: woocommerce' }],
		capabilities: ['edit_posts', 'manage_options', 'read'],
		warnings: [],
		...overrides,
	};
}

const detail: OptionsDetail = {
	page: row(),
	declaration: cadcoData,
	parents: [
		{ slug: 'edit.php?post_type=product', title: 'Products' },
		{ slug: 'options-general.php', title: 'Settings' },
	],
	capabilities: ['edit_posts', 'manage_options', 'manage_woocommerce', 'read'],
};

function file(overrides: Partial<FileResult> = {}): FileResult {
	const text = overrides.text ?? cadcoText;
	return {
		exists: true,
		path: `wp-content/themes/demo/proto-options/${SLUG}/page.jsonc`,
		text,
		hash: 'h1',
		data: parseJsonc(text).data,
		writable: true,
		reason: null,
		...overrides,
	};
}

const saved = { path: `wp-content/themes/demo/proto-options/${SLUG}/page.jsonc`, hash: 'h2', created: false, warnings: [] };

function routes(extra: Record<string, unknown> = {}) {
	return mockApi({
		'admin/options-pages': index(),
		[`admin/options-pages/${SLUG}`]: detail,
		[`admin/schema-files/options/${SLUG}`]: file(),
		...extra,
	});
}

function renderScreen(query = '', bootstrap = {}) {
	window.history.replaceState(null, '', `/wp-admin/admin.php?page=proto-blocks-options-pages${query}`);
	const boot = mockBootstrap({ screen: 'options-pages', ...bootstrap });
	render(<AdminApp bootstrap={boot} />);
}

function calls(): { url: string; method: string; data?: { text?: string; hash?: string | null; acknowledge?: string[] } }[] {
	return (apiFetch as unknown as jest.Mock).mock.calls.map(([options]) => ({
		url: String(options.url).replace(ROOT, ''),
		method: options.method ?? 'GET',
		data: options.data,
	}));
}

const posts = () => calls().filter((c) => c.method === 'POST');

async function openCadco() {
	renderScreen(`&slug=${SLUG}`);
	return screen.findByRole('textbox', { name: 'Title' });
}

describe('list', () => {
	test('shows the pages, their menu, capability, storage and origin, and other plugins separately', async () => {
		routes();
		renderScreen();
		const table = (await screen.findByText('Display Settings')).closest('table') as HTMLElement;
		const cadco = within(table).getByText('Display Settings').closest('tr') as HTMLElement;
		expect(within(cadco).getByText('Under Products')).toBeTruthy();
		expect(within(cadco).getByText('manage_woocommerce')).toBeTruthy();
		expect(within(cadco).getByText('Array: cadco_display_settings')).toBeTruthy();
		expect(within(cadco).getByText('Proto-Blocks file')).toBeTruthy();
		const php = within(table).getByText('Announcement Bar').closest('tr') as HTMLElement;
		expect(within(php).getByText('Declared twice')).toBeTruthy();

		const external = screen.getByRole('region', { name: 'Other settings pages' });
		expect(within(external).getByText('WooCommerce Settings')).toBeTruthy();
		expect(within(external).getByText('Plugin: woocommerce')).toBeTruthy();
		expect(within(external).queryByText(/Plugin: Plugin:/)).toBeNull();
		expect(within(external).queryByRole('button')).toBeNull();
		expect(within(table).queryByText('WooCommerce Settings')).toBeNull();
		expect(calls()[0].url).toBe('admin/options-pages');
	});

	test('file mods off: read-only banner, New options page and Save disabled', async () => {
		routes();
		renderScreen('', { fileModsAllowed: false });
		expect(await screen.findByText('File modifications are disabled on this site')).toBeTruthy();
		expect((screen.getByRole('button', { name: 'New options page' }) as HTMLButtonElement).disabled).toBe(true);

		document.body.innerHTML = '';
		renderScreen(`&slug=${SLUG}`, { fileModsAllowed: false });
		await screen.findByRole('textbox', { name: 'Title' });
		expect(screen.getByText('File modifications are disabled on this site')).toBeTruthy();
		for (const button of screen.getAllByRole('button', { name: 'Save' })) {
			expect((button as HTMLButtonElement).disabled).toBe(true);
		}
		expect(screen.queryByRole('button', { name: 'Delete' })).toBeNull();
	});
});

describe('editor', () => {
	test('the JSONC toggle shows the file text full width and hides the form, and back', async () => {
		const user = userEvent.setup();
		routes();
		await openCadco();
		const toggle = screen.getByRole('button', { name: 'JSONC' });
		expect(toggle.getAttribute('aria-pressed')).toBe('false');
		await user.click(toggle);
		expect(toggle.getAttribute('aria-pressed')).toBe('true');
		const region = screen.getByRole('region', { name: 'JSONC' });
		expect(within(region).getByLabelText('File text that Save writes').textContent).toContain('cadco_display_settings');
		expect(screen.queryByRole('textbox', { name: 'Title' })).toBeNull();
		await user.click(screen.getByRole('button', { name: 'Editor' }));
		expect(screen.queryByRole('region', { name: 'JSONC' })).toBeNull();
		expect(screen.getByRole('textbox', { name: 'Title' })).toBeTruthy();
	});

	test('the fields card shows the column headers and opens a row inline', async () => {
		mockRealTree = true;
		const reset = () => { mockRealTree = false; };
		const user = userEvent.setup();
		routes();
		await openCadco();
		const card = screen.getByRole('region', { name: 'Fields' });
		for (const heading of ['Order', 'Label', 'Name', 'Type']) {
			expect(within(card).getByText(heading)).toBeTruthy();
		}
		expect(within(card).getByText('Fields shown on the page, above any tabs.')).toBeTruthy();
		expect(screen.queryByText('Select a field to edit its settings.')).toBeNull();
		await user.click(within(card).getByText('Hide child products'));
		expect(within(card).getByRole('button', { name: 'Close field' })).toBeTruthy();
		reset();
	});

	test('shows menu placement and storage from the file', async () => {
		routes();
		await openCadco();
		expect(screen.getByRole('radio', { name: 'Under an existing menu' }).getAttribute('aria-checked')).toBe('true');
		const parent = screen.getByRole('combobox', { name: 'Parent menu' }) as HTMLSelectElement;
		expect(parent.value).toBe('edit.php?post_type=product');
		expect(parent.selectedOptions[0].textContent).toBe('Products');
		expect(screen.getByRole('radio', { name: /Single array option/ }).getAttribute('aria-checked')).toBe('true');
		expect((screen.getByRole('textbox', { name: 'Setting name' }) as HTMLInputElement).value).toBe('cadco_display_settings');
		// The icon picker is only for top-level pages.
		expect(screen.queryByLabelText('Position')).toBeNull();
	});

	test('capability dropdown: payload options, common first, a pick is written to the text', async () => {
		const user = userEvent.setup();
		routes({
			[`POST admin/schema-files/options/${SLUG}`]: saved,
			[`admin/options-pages/${SLUG}`]: { ...detail, capabilities: ['zeta_cap', 'read', 'alpha_cap', 'manage_woocommerce', 'edit_posts', 'manage_options'] },
		});
		await openCadco();
		const capability = screen.getByRole('combobox', { name: 'Who can use this page' });
		const save = screen.getByRole('button', { name: 'Save' }) as HTMLButtonElement;
		expect(capability.textContent).toContain('manage_woocommerce');
		await user.click(capability);
		const names = screen.getAllByRole('option').map((o) => o.textContent);
		expect(names).toEqual(['manage_options', 'edit_posts', 'read', 'alpha_cap', 'manage_woocommerce', 'zeta_cap']);
		await user.click(screen.getByRole('option', { name: 'edit_posts' }));
		expect(save.disabled).toBe(false);
		await user.click(save);

		await waitFor(() => expect(posts()).toHaveLength(1));
		const [post] = posts();
		expect(post.url).toBe(`admin/schema-files/options/${SLUG}`);
		expect(post.data?.hash).toBe('h1');
		expect(post.data?.acknowledge).toEqual([]);
		expect(post.data?.text).toBe(cadcoText.replace('"capability": "manage_woocommerce"', '"capability": "edit_posts"'));
		expect(post.data?.text).toContain('// CADCO product display settings');
	});

	test('an unknown current capability is kept as a selected option with a warning; read warns too', async () => {
		const user = userEvent.setup();
		routes({ [`admin/options-pages/${SLUG}`]: { ...detail, capabilities: ['edit_posts', 'read'] } });
		await openCadco();
		expect(screen.getByText('No role has this capability; only super admins will see the page.')).toBeTruthy();
		const capability = screen.getByRole('combobox', { name: 'Who can use this page' });
		expect(capability.textContent).toContain('manage_woocommerce');
		await user.click(capability);
		expect(screen.getByRole('option', { name: 'manage_woocommerce (not granted to any role)' })).toBeTruthy();
		await user.click(screen.getByRole('option', { name: 'read' }));
		expect(screen.getByText('Anyone who can log in will be able to change these settings.')).toBeTruthy();
		expect(screen.queryByText('No role has this capability; only super admins will see the page.')).toBeNull();
	});

	test('a 409 conflict opens the ConflictDialog', async () => {
		const user = userEvent.setup();
		routes({
			[`POST admin/schema-files/options/${SLUG}`]: () => {
				throw { code: 'proto_blocks_conflict', message: 'Changed.', data: { status: 409 } };
			},
		});
		await openCadco();
		await user.type(screen.getByRole('textbox', { name: 'Title' }), '!');
		await user.click(screen.getByRole('button', { name: 'Save' }));
		expect(await screen.findByText('The file changed on disk.')).toBeTruthy();
		expect(screen.getByRole('button', { name: 'Reload file' })).toBeTruthy();
	});

	test('a server 400 (reserved child slug) shows inline', async () => {
		const user = userEvent.setup();
		routes({
			[`POST admin/schema-files/options/${SLUG}`]: () => {
				throw {
					code: 'proto_blocks_schema_invalid',
					message: 'The declaration does not match the schema.',
					data: { status: 400, errors: [{ path: '/children/0/slug', message: 'This slug is reserved.' }] },
				};
			},
		});
		await openCadco();
		await user.click(screen.getByRole('button', { name: 'Add child page' }));
		await user.type(screen.getByRole('textbox', { name: 'Child page slug' }), 'blocks');
		await user.type(screen.getByRole('textbox', { name: 'Child page title' }), 'Blocks');
		await user.click(screen.getByRole('button', { name: 'Save' }));
		expect(await screen.findByText('Nothing was saved.')).toBeTruthy();
		expect(screen.getAllByText('This slug is reserved.')).toHaveLength(2);
		await waitFor(() => expect(document.activeElement).toBe(screen.getByRole('textbox', { name: 'Child page slug' })));
	});

	test('a failed save not tied to a child focuses the error summary', async () => {
		const user = userEvent.setup();
		routes({
			[`POST admin/schema-files/options/${SLUG}`]: () => {
				throw { code: 'proto_blocks_schema_invalid', message: 'Bad.', data: { status: 400, errors: [{ path: '/menu', message: 'Unknown menu.' }] } };
			},
		});
		await openCadco();
		await user.type(screen.getByRole('textbox', { name: 'Title' }), '!');
		await user.click(screen.getByRole('button', { name: 'Save' }));
		const summary = (await screen.findByText('Nothing was saved.')).closest('[role="alert"]');
		await waitFor(() => expect(document.activeElement).toBe(summary));
	});

	test('the parent menu list leaves out the page\'s own children', async () => {
		routes({
			[`admin/options-pages/${SLUG}`]: {
				...detail,
				page: row({ children: [`${SLUG}-footer`] }),
				parents: [...detail.parents, { slug: `${SLUG}-footer`, title: 'Footer' }],
			},
		});
		await openCadco();
		const parent = screen.getByRole('combobox', { name: 'Parent menu' }) as HTMLSelectElement;
		expect(Array.from(parent.options).map((o) => o.value)).toEqual(['edit.php?post_type=product', 'options-general.php']);
	});

	test('the per-field storage hint names a real option', async () => {
		routes();
		await openCadco();
		expect(screen.getByText(`Saved as ${SLUG}_hide_children. Recommended.`)).toBeTruthy();
	});

	test('tabs: add, rename and reorder', async () => {
		const user = userEvent.setup();
		routes({ [`POST admin/schema-files/options/${SLUG}`]: saved });
		await openCadco();
		await user.click(screen.getByRole('button', { name: 'Add tab' }));
		const first = screen.getByRole('textbox', { name: 'Tab title' });
		await user.clear(first);
		await user.type(first, 'Tracking');
		await user.click(screen.getByRole('button', { name: 'Add tab' }));
		await user.click(screen.getByRole('button', { name: 'Move New tab up' }));
		const titles = () => screen.getAllByRole('textbox', { name: 'Tab title' }).map((input) => (input as HTMLInputElement).value);
		expect(titles()).toEqual(['New tab', 'Tracking']);
		const tracking = screen.getAllByRole('textbox', { name: 'Tab title' })[1];
		await user.type(tracking, ' codes');
		expect(titles()).toEqual(['New tab', 'Tracking codes']);

		await user.click(screen.getByRole('button', { name: 'Save' }));
		await waitFor(() => expect(posts()).toHaveLength(1));
		expect(parseJsonc(posts()[0].data?.text ?? '').data.tabs).toEqual([
			{ title: 'New tab', fields: [] },
			{ title: 'Tracking codes', fields: [] },
		]);
	});

	test('a reorder keeps the same tab expanded and focus on its Move button', async () => {
		const user = userEvent.setup();
		routes();
		await openCadco();
		await user.click(screen.getByRole('button', { name: 'Add tab' }));
		const first = screen.getByRole('textbox', { name: 'Tab title' });
		await user.clear(first);
		await user.type(first, 'General');
		await user.click(screen.getByRole('button', { name: 'Add tab' }));
		await user.click(screen.getByRole('button', { name: 'Edit fields of New tab' }));
		await user.click(screen.getByRole('button', { name: 'Move New tab up' }));
		expect(screen.getByRole('button', { name: 'Edit fields of New tab' }).getAttribute('aria-expanded')).toBe('true');
		expect(screen.getByRole('button', { name: 'Edit fields of General' }).getAttribute('aria-expanded')).toBe('false');
		// Now first, so Up is disabled: focus lands on its Down button.
		await waitFor(() => expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Move New tab down' })));
	});

	test('removing an earlier child keeps the right row open', async () => {
		const user = userEvent.setup();
		routes();
		await openCadco();
		await user.click(screen.getByRole('button', { name: 'Add child page' }));
		await user.click(screen.getByRole('button', { name: 'Add child page' }));
		const titles = screen.getAllByRole('textbox', { name: 'Child page title' });
		await user.type(titles[0], 'One');
		await user.type(titles[1], 'Two');
		await user.click(screen.getByRole('button', { name: 'Edit fields of Two' }));
		await user.click(screen.getByRole('button', { name: 'Remove One' }));
		expect(screen.getByRole('button', { name: 'Edit fields of Two' }).getAttribute('aria-expanded')).toBe('true');
	});

	test('child pages: add one, reject a bad slug', async () => {
		const user = userEvent.setup();
		routes({ [`POST admin/schema-files/options/${SLUG}`]: saved });
		await openCadco();
		await user.click(screen.getByRole('button', { name: 'Add child page' }));
		const slug = screen.getByRole('textbox', { name: 'Child page slug' });
		await user.type(slug, 'Footer!');
		expect(screen.getAllByText('Use lowercase letters, digits and hyphens.').length).toBeGreaterThan(0);
		expect((screen.getByRole('button', { name: 'Save' }) as HTMLButtonElement).disabled).toBe(true);
		await user.clear(slug);
		await user.type(slug, 'footer');
		await user.type(screen.getByRole('textbox', { name: 'Child page title' }), 'Footer');
		expect(screen.getByText(`${SLUG}-footer`)).toBeTruthy();
		await user.click(screen.getByRole('button', { name: 'Save' }));
		await waitFor(() => expect(posts()).toHaveLength(1));
		expect(parseJsonc(posts()[0].data?.text ?? '').data.children).toEqual([{ slug: 'footer', title: 'Footer', fields: [] }]);
	});

	test('a child page capability starts as Inherit from page and can be set and cleared', async () => {
		const user = userEvent.setup();
		routes({ [`POST admin/schema-files/options/${SLUG}`]: saved });
		await openCadco();
		await user.click(screen.getByRole('button', { name: 'Add child page' }));
		await user.type(screen.getByRole('textbox', { name: 'Child page slug' }), 'footer');
		await user.type(screen.getByRole('textbox', { name: 'Child page title' }), 'Footer');
		const child = screen.getByRole('combobox', { name: 'Capability' });
		expect(child.textContent).toContain('Inherit from page');
		await user.click(child);
		await user.click(screen.getByRole('option', { name: 'read' }));
		await user.click(screen.getByRole('combobox', { name: 'Capability' }));
		await user.click(screen.getByRole('option', { name: 'Inherit from page' }));
		await user.click(screen.getByRole('button', { name: 'Save' }));
		await waitFor(() => expect(posts()).toHaveLength(1));
		expect(parseJsonc(posts()[0].data?.text ?? '').data.children).toEqual([{ slug: 'footer', title: 'Footer', fields: [] }]);
	});

	test('switching array storage to per field warns before saving', async () => {
		const user = userEvent.setup();
		routes();
		await openCadco();
		await user.click(screen.getByRole('radio', { name: /One setting per field/ }));
		expect(screen.getByText('Stored values stay in cadco_display_settings; the page will read new per-field options.')).toBeTruthy();
		expect(posts()).toHaveLength(0);
	});

	test('the fields section renders the builder with the implicit group', async () => {
		routes();
		await openCadco();
		expect(screen.getByTestId('field-tree').textContent).toBe('hide_children,children_placement,children_heading');
		const last = treeProps[treeProps.length - 1];
		expect(last.fields).toEqual(cadcoData.fields);
		expect(last.readOnly).toBe(false);
	});

	test('Delete confirms and sends the DELETE', async () => {
		const user = userEvent.setup();
		routes({ [`DELETE admin/schema-files/options/${SLUG}`]: { deleted: true } });
		await openCadco();
		await user.click(screen.getByRole('button', { name: 'Delete' }));
		const dialog = await screen.findByRole('alertdialog');
		expect(within(dialog).getByText(/proto-options\/cadco-display-settings\//)).toBeTruthy();
		expect(within(dialog).getByText(/Saved values are not deleted\./)).toBeTruthy();
		await user.click(within(dialog).getByRole('button', { name: 'Delete' }));
		await waitFor(() => expect(calls().some((c) => c.method === 'DELETE' && c.url === `admin/schema-files/options/${SLUG}`)).toBe(true));
		await screen.findByRole('button', { name: 'New options page' });
	});

	test('a PHP-only page is read-only and Create file saves its declaration', async () => {
		const user = userEvent.setup();
		const template = '{\n  // Settings page for Announcement Bar.\n  "title": "Announcement Bar"\n}\n';
		const declaration = { title: 'Announcement Bar', menu: 'options-general.php', capability: 'edit_posts' };
		routes({
			'admin/options-pages/announcement': { ...detail, page: { ...phpRow, double: null }, declaration },
			'admin/schema-files/options/announcement?name=Announcement%20Bar': file({ exists: false, hash: null, text: template, path: 'wp-content/themes/demo/proto-options/announcement/page.jsonc' }),
			'POST admin/schema-files/options/announcement': { ...saved, created: true },
		});
		renderScreen('&slug=announcement');
		expect(await screen.findByText('Declared in PHP at functions.php:40. Saving creates a file that takes over.')).toBeTruthy();
		expect((screen.getByRole('textbox', { name: 'Title' }) as HTMLInputElement).disabled).toBe(true);
		expect((screen.getByRole('button', { name: 'Save' }) as HTMLButtonElement).disabled).toBe(true);
		await user.click(screen.getByRole('button', { name: 'Create file' }));
		await waitFor(() => expect(posts()).toHaveLength(1));
		const [post] = posts();
		expect(post.url).toBe('admin/schema-files/options/announcement');
		expect(post.data?.hash).toBeNull();
		expect(post.data?.text).toContain('// Settings page for Announcement Bar.');
		expect(parseJsonc(post.data?.text ?? '').data).toEqual(declaration);
	});

	test('?new=1 proposes the slug and creates from the server template', async () => {
		const user = userEvent.setup();
		const template = '{\n  // Shop Notices settings page.\n  "title": "Shop Notices"\n}\n';
		routes({
			'admin/options-pages': index({ capabilities: ['edit_posts', 'read'] }),
			'admin/options-pages/shop-notices': { ...detail, page: row({ slug: 'shop-notices', title: 'Shop Notices' }), capabilities: [] },
			'admin/schema-files/options/shop-notices?name=Shop%20Notices': file({ exists: false, hash: null, text: template, path: 'wp-content/themes/demo/proto-options/shop-notices/page.jsonc' }),
			'POST admin/schema-files/options/shop-notices': { ...saved, created: true },
			'admin/options-pages/shop-notices': { ...detail, page: row({ slug: 'shop-notices', title: 'Shop Notices' }) },
		});
		renderScreen('&new=1');
		await user.type(await screen.findByRole('textbox', { name: 'Page title' }), 'Shop Notices');
		expect((screen.getByRole('textbox', { name: 'Slug' }) as HTMLInputElement).value).toBe('shop-notices');
		await user.click(screen.getByRole('button', { name: 'Continue' }));
		await user.click(await screen.findByRole('combobox', { name: 'Who can use this page' }));
		await user.click(screen.getByRole('option', { name: 'edit_posts' }));
		await user.click(screen.getByRole('button', { name: 'Save' }));
		await waitFor(() => expect(posts()).toHaveLength(1));
		const [post] = posts();
		expect(post.url).toBe('admin/schema-files/options/shop-notices');
		expect(post.data?.text).toBe('{\n  // Shop Notices settings page.\n  "title": "Shop Notices",\n  "capability": "edit_posts"\n}\n');
		await waitFor(() => expect(window.location.search).toBe('?page=proto-blocks-options-pages&slug=shop-notices'));
	});
});
