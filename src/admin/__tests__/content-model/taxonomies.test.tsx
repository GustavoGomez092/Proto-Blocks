import { mockApi, mockBootstrap } from '../helpers';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { TooltipProvider } from '@/admin/components/ui/tooltip';
import { parseJsonc } from '@/admin/content-model/jsonc';
import TaxonomiesScreen from '@/admin/screens/TaxonomiesScreen';

const BASE = '/wp-admin/admin.php?page=proto-blocks-taxonomies';

const item = (over: Record<string, unknown>) => ({
	slug: 'x',
	label: 'X',
	origin: 'file',
	originLabel: 'Proto-Blocks file',
	source: null,
	override: false,
	double: null,
	count: 0,
	editable: true,
	readOnlyReason: null,
	issues: [],
	...over,
});

const genre = item({ slug: 'genre', label: 'Genre', source: 'wp-content/themes/x/proto-types/book/type.jsonc', count: 2 });
const mediaType = (count: number) =>
	item({ slug: 'media-type', label: 'Media Type', source: 'wp-content/themes/x/proto-taxonomies/media-type/taxonomy.jsonc', count });

const FILE_PATH = 'wp-content/themes/x/proto-taxonomies/media-type/taxonomy.jsonc';
const FILE_TEXT = '// keep me\n{ "name": "Media Type" }';

function routes(count = 5, extra: Record<string, unknown> = {}) {
	return {
		'admin/taxonomies': {
			moduleEnabled: true,
			createTarget: { path: 'wp-content/themes/x/proto-taxonomies', writable: true, reason: null },
			items: [genre, mediaType(count)],
			issues: [],
		},
		'admin/taxonomies/genre': { item: genre, effective: {}, objectTypes: ['book'], content: { count: 2 } },
		'admin/taxonomies/media-type': {
			item: mediaType(count),
			effective: { hierarchical: false, public: true, show_in_rest: true, show_admin_column: false, labels: { menu_name: 'Media Types' } },
			objectTypes: ['resource'],
			content: { count },
		},
		'admin/types': {
			moduleEnabled: true,
			createTarget: { path: 'x', writable: true, reason: null },
			items: [item({ slug: 'book', label: 'Book' }), item({ slug: 'resource', label: 'Resource' })],
			issues: [],
		},
		'schema-files/taxonomy/media-type': {
			exists: true,
			path: FILE_PATH,
			text: FILE_TEXT,
			hash: 'h1',
			data: { name: 'Media Type' },
			writable: true,
			reason: null,
		},
		...extra,
	};
}

function open(search: string) {
	window.history.replaceState(null, '', BASE + search);
	return render(
		<TooltipProvider>
			<TaxonomiesScreen />
		</TooltipProvider>
	);
}

type Call = { url: string; method?: string; data?: { text: string; acknowledge: string[]; hash: string | null } };
const calls = (mock: jest.Mock) => mock.mock.calls.map(([o]) => o as Call);
const posted = (mock: jest.Mock, url: string) => calls(mock).filter((o) => o.url.endsWith(url) && o.method === 'POST');

beforeAll(() => {
	if (!('ResizeObserver' in window)) {
		(window as unknown as { ResizeObserver: unknown }).ResizeObserver = class {
			observe() {}
			unobserve() {}
			disconnect() {}
		};
	}
});

beforeEach(() => {
	mockBootstrap({ screen: 'taxonomies' });
});

afterEach(() => {
	delete window.protoBlocksAdmin;
	document.body.innerHTML = '';
	jest.clearAllMocks();
});

test('the list shows an inline taxonomy attached to its type with an Edit in post type link', async () => {
	mockApi(routes());
	open('');

	const row = (await screen.findByText('Genre')).closest('tr') as HTMLElement;
	expect(await within(row).findByText('Book')).toBeInTheDocument();
	const link = within(row).getByRole('link', { name: 'Edit in post type' });
	expect(link.getAttribute('href')).toContain('?page=proto-blocks-post-types&type=book');
	expect(within(row).queryByRole('link', { name: /^Edit$/ })).not.toBeInTheDocument();

	const other = screen.getByText('Media Type').closest('tr') as HTMLElement;
	expect(await within(other).findByText('Resource')).toBeInTheDocument();
	expect(within(other).getByText('5')).toBeInTheDocument();
});

test('creating Media Type posts the file with name and post_types', async () => {
	const user = userEvent.setup();
	const api = mockApi(
		routes(0, {
			'schema-files/taxonomy/media-type?name=Media%20Type': {
				exists: false,
				path: FILE_PATH,
				text: '{\n  "name": "Media Type"\n}\n',
				hash: null,
				data: { name: 'Media Type' },
				writable: true,
				reason: null,
			},
			'POST schema-files/taxonomy/media-type': { path: FILE_PATH, hash: 'h2', created: true, warnings: [] },
		})
	);
	open('&new=1');

	await user.type(await screen.findByLabelText('Name'), 'Media Type');
	expect(screen.getByLabelText('Slug')).toHaveValue('media-type');
	await user.click(await screen.findByRole('checkbox', { name: /Resource/ }));
	await user.click(screen.getByRole('button', { name: 'Save to file' }));

	await waitFor(() => expect(posted(api, 'schema-files/taxonomy/media-type')).toHaveLength(1));
	const body = posted(api, 'schema-files/taxonomy/media-type')[0].data!;
	expect(body.hash).toBeNull();
	expect(parseJsonc(body.text).data).toEqual({ name: 'Media Type', post_types: ['resource'] });
	expect(body.text).toContain('"name": "Media Type"');
	expect(body.acknowledge).toEqual([]);
	await waitFor(() => expect(window.location.search).toContain('taxonomy=media-type'));
});

test('a taxonomy with terms locks the slug and shows the count', async () => {
	mockApi(routes(5));
	open('&taxonomy=media-type');

	const slug = await screen.findByLabelText(/Slug/);
	expect(slug).toHaveAttribute('readonly');
	expect(slug).toHaveValue('media-type');
	expect(screen.getByText(/This taxonomy has 5 terms\./)).toBeInTheDocument();
	expect(screen.getByText(/wp proto-blocks taxonomy rename media-type <new> --migrate/)).toBeInTheDocument();
});

test('deleting a taxonomy with terms needs the slug typed, then sends confirm', async () => {
	const user = userEvent.setup();
	const api = mockApi(routes(5, { 'DELETE schema-files/taxonomy/media-type?confirm=media-type': { deleted: true } }));
	open('&taxonomy=media-type');

	await user.click(await screen.findByRole('button', { name: /^Delete/ }));
	const confirm = await screen.findByRole('button', { name: 'Delete declaration' });
	expect(confirm).toBeDisabled();
	await user.type(screen.getByRole('textbox', { name: /Type media-type to confirm/ }), 'media-type');
	expect(confirm).toBeEnabled();
	await user.click(confirm);

	await waitFor(() =>
		expect(calls(api).some((o) => o.method === 'DELETE' && o.url.endsWith('schema-files/taxonomy/media-type?confirm=media-type'))).toBe(true)
	);
});

test('without file modifications the screen is read-only', async () => {
	mockBootstrap({ screen: 'taxonomies', fileModsAllowed: false, fileModsConstant: 'DISALLOW_FILE_MODS' });
	mockApi(routes(5));
	const view = open('&taxonomy=media-type');

	expect(await screen.findByText('File modifications are disabled on this site')).toBeInTheDocument();
	expect(screen.getByRole('button', { name: 'Save to file' })).toBeDisabled();
	expect(screen.getByRole('button', { name: /^Delete/ })).toBeDisabled();
	expect(screen.getByLabelText('Name')).toBeDisabled();
	view.unmount();

	mockApi(routes(5));
	open('');
	await screen.findByText('Genre');
	expect(screen.getByRole('button', { name: 'Add taxonomy' })).toBeDisabled();
});

test('a 409 conflict opens the conflict dialog and Reload refetches the file', async () => {
	const user = userEvent.setup();
	const api = mockApi(
		routes(0, {
			'POST schema-files/taxonomy/media-type': () => {
				throw { code: 'proto_blocks_conflict', message: 'changed', data: { status: 409 } };
			},
		})
	);
	open('&taxonomy=media-type');

	await user.click(await screen.findByLabelText('Show as a column in admin lists'));
	await user.click(screen.getByRole('button', { name: 'Save to file' }));

	expect(await screen.findByText('The file changed on disk.')).toBeInTheDocument();
	const loads = () => calls(api).filter((o) => o.method !== 'POST' && o.url.endsWith('schema-files/taxonomy/media-type')).length;
	const before = loads();
	await user.click(screen.getByRole('button', { name: 'Reload file' }));
	await waitFor(() => expect(loads()).toBe(before + 1));
});

test('saving writes only the changed keys and keeps comments', async () => {
	const user = userEvent.setup();
	const api = mockApi(routes(0, { 'POST schema-files/taxonomy/media-type': { path: FILE_PATH, hash: 'h2', created: false, warnings: [] } }));
	open('&taxonomy=media-type');

	await user.click(await screen.findByLabelText('Show as a column in admin lists'));
	await user.click(screen.getByRole('button', { name: 'Save to file' }));

	await waitFor(() => expect(posted(api, 'schema-files/taxonomy/media-type')).toHaveLength(1));
	const body = posted(api, 'schema-files/taxonomy/media-type')[0].data!;
	expect(body.text).toContain('// keep me');
	expect(parseJsonc(body.text).data).toEqual({ name: 'Media Type', show_admin_column: true });
	expect(body.hash).toBe('h1');
});

test('a guardrail confirmation resends with the codes acknowledged', async () => {
	const user = userEvent.setup();
	let attempts = 0;
	const api = mockApi(
		routes(0, {
			'POST schema-files/taxonomy/media-type': (body: unknown) => {
				attempts += 1;
				if ((body as { acknowledge: string[] }).acknowledge.length === 0) {
					throw {
						code: 'proto_blocks_needs_confirmation',
						message: 'confirm',
						data: { status: 409, guardrails: [{ code: 'taxonomy_removed', message: 'Terms stay.', count: 1, detail: [] }] },
					};
				}
				return { path: FILE_PATH, hash: 'h2', created: false, warnings: [] };
			},
		})
	);
	open('&taxonomy=media-type');

	await user.click(await screen.findByLabelText('Show as a column in admin lists'));
	await user.click(screen.getByRole('button', { name: 'Save to file' }));
	await user.click(await screen.findByRole('button', { name: 'Save anyway' }));

	await waitFor(() => expect(attempts).toBe(2));
	expect(posted(api, 'schema-files/taxonomy/media-type')[1].data!.acknowledge).toEqual(['taxonomy_removed']);
});

test('opening an inline taxonomy by URL links to its post type instead of an editor', async () => {
	mockApi(routes());
	open('&taxonomy=genre');

	const link = await screen.findByRole('link', { name: 'Edit in post type' });
	expect(link.getAttribute('href')).toContain('?page=proto-blocks-post-types&type=book');
	expect(screen.queryByRole('button', { name: 'Save to file' })).not.toBeInTheDocument();
});
