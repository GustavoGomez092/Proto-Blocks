import { mockApi, mockBootstrap } from '../helpers';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';
import { parse } from 'jsonc-parser';
import { AdminApp } from '@/admin/app';
import { parseJsonc } from '@/admin/content-model/jsonc';
import type { FieldsListResult, GroupRow } from '@/admin/screens/fields/GroupList';

const ROOT = 'https://example.test/wp-json/proto-blocks/v1/';

function parseJsoncValue(text: string): unknown {
	return parse(text, [], { allowTrailingComma: true });
}

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
	jest.clearAllMocks();
});

function row(overrides: Partial<GroupRow> = {}): GroupRow {
	return {
		key: 'group_events',
		title: 'Event details',
		origin: 'file',
		file: { kind: 'field-group', slug: 'events', path: 'proto-fields/events/group.jsonc' },
		source: 'proto-fields/events/group.jsonc',
		location: 'post_type == event',
		fieldCount: 7,
		position: 'normal',
		status: 'active',
		issues: [],
		doubleDeclared: false,
		editable: true,
		readOnlyReason: null,
		...overrides,
	};
}

function list(overrides: Partial<FieldsListResult> = {}): FieldsListResult {
	return {
		moduleEnabled: true,
		acfActive: true,
		createTarget: { path: 'demo/proto-fields', writable: true, reason: null },
		groups: [
			row(),
			row({
				key: 'group_talk',
				title: 'Talk details',
				origin: 'type-file',
				file: { kind: 'type', slug: 'talk', index: 1, path: 'proto-types/talk/type.jsonc' },
				source: 'proto-types/talk/type.jsonc',
				location: 'post_type == talk',
			}),
			row({
				key: 'group_product',
				title: 'Product',
				origin: 'php',
				file: null,
				source: 'functions.php:12',
				doubleDeclared: true,
				editable: false,
				readOnlyReason: 'inline',
			}),
			row({
				key: 'group_promo',
				title: 'Promo',
				file: { kind: 'field-group', slug: 'promo', path: 'proto-fields/promo/group.jsonc' },
				source: 'proto-fields/promo/group.jsonc',
				location: '',
				status: 'unrenderable',
				issues: [
					{
						level: 'warning',
						code: 'unsupported_location_rule',
						path: 'proto-fields/promo/group.jsonc',
						message: '/location/0/0/param: Location rule "post_format" is not supported by Proto-Blocks; this group will not be shown anywhere.',
					},
				],
			}),
			row({
				key: 'group_seo',
				title: 'SEO extras',
				origin: 'acf',
				file: null,
				source: 'proto-fields/seo/group.jsonc',
				location: 'post_type == post',
				fieldCount: 3,
				status: 'skipped-acf',
				editable: false,
				readOnlyReason: 'acf',
			}),
		],
		issues: [],
		...overrides,
	};
}

function renderScreen(query = '', bootstrap = {}) {
	window.history.replaceState(null, '', `/wp-admin/admin.php?page=proto-blocks-fields${query}`);
	const boot = mockBootstrap({ screen: 'fields', ...bootstrap });
	render(<AdminApp bootstrap={boot} />);
	return boot;
}

function calls(): { url: string; method: string; data?: unknown }[] {
	return (apiFetch as unknown as jest.Mock).mock.calls.map(([options]) => ({
		url: String(options.url),
		method: options.method ?? 'GET',
		...(options.data === undefined ? {} : { data: options.data }),
	}));
}

function rowOf(title: string): HTMLElement {
	const cell = screen.getByText(title, { selector: 'td *' });
	const tr = cell.closest('tr');
	if (!tr) {
		throw new Error('no row for ' + title);
	}
	return tr;
}

describe('Fields list', () => {
	it('loads admin/fields and shows the three origin badges', async () => {
		mockApi({ 'admin/fields': list() });
		renderScreen();

		await screen.findByText('Event details');
		expect(calls()).toEqual([{ url: ROOT + 'admin/fields', method: 'GET' }]);
		expect(within(rowOf('Event details')).getByText('Proto-Blocks file')).toBeInTheDocument();
		expect(within(rowOf('Talk details')).getByText('Proto-Blocks file')).toBeInTheDocument();
		expect(within(rowOf('Product')).getByText('Proto-Blocks PHP')).toBeInTheDocument();
		expect(within(rowOf('SEO extras')).getByText('Plugin: Advanced Custom Fields')).toBeInTheDocument();
		expect(within(rowOf('Product')).getByText('Declared twice')).toBeInTheDocument();
		expect(within(rowOf('Event details')).getByText('group_events')).toBeInTheDocument();
		expect(within(rowOf('Event details')).getByTitle('post_type == event')).toHaveTextContent('Post typeevent');
	});

	it('marks a group skipped because ACF registers its key', async () => {
		mockApi({ 'admin/fields': list() });
		renderScreen();

		await screen.findByText('SEO extras');
		const seo = rowOf('SEO extras');
		expect(within(seo).getByText('Skipped: ACF registers this key')).toBeInTheDocument();
		expect(within(seo).queryByRole('button', { name: /^Delete/ })).not.toBeInTheDocument();
		expect(within(seo).getByRole('button', { name: /Advanced Custom Fields owns this key/ })).toBeInTheDocument();
	});

	it('explains an unrenderable group with the warning naming the rule', async () => {
		mockApi({ 'admin/fields': list() });
		renderScreen();

		await screen.findByText('Promo');
		const promo = rowOf('Promo');
		expect(within(promo).getByText('Not shown')).toBeInTheDocument();
		expect(within(promo).getByText(/Location rule "post_format" is not supported/)).toBeInTheDocument();
		expect(within(promo).getByText('1 issue')).toBeInTheDocument();
	});

	it('PHP groups have no delete, only an explanation', async () => {
		mockApi({ 'admin/fields': list() });
		renderScreen();

		await screen.findByText('Product');
		const product = rowOf('Product');
		expect(within(product).queryByRole('button', { name: /^Delete/ })).not.toBeInTheDocument();
		expect(within(product).getByRole('button', { name: /declared in PHP/ })).toBeInTheDocument();
	});

	it('is read-only when file modifications are off', async () => {
		mockApi({ 'admin/fields': list() });
		renderScreen('', { fileModsAllowed: false, fileModsConstant: 'DISALLOW_FILE_MODS' });

		await screen.findByText('Event details');
		expect(screen.getByText('File modifications are disabled on this site')).toBeInTheDocument();
		expect(screen.getByRole('button', { name: 'Add field group' })).toBeDisabled();
		expect(within(rowOf('Event details')).getByRole('button', { name: 'Delete Event details' })).toBeDisabled();
		expect(within(rowOf('Talk details')).getByRole('button', { name: 'Delete Talk details' })).toBeDisabled();
		expect(within(rowOf('Event details')).getByRole('button', { name: 'View Event details' })).toBeEnabled();
	});

	it('deletes a proto-fields group through deleteSchemaFile after the slug is typed', async () => {
		const user = userEvent.setup();
		let groups = list();
		mockApi({
			'admin/fields': () => groups,
			'DELETE admin/schema-files/field-group/events?confirm=events': () => {
				groups = list({ groups: list().groups.slice(1) });
				return { deleted: 'demo/proto-fields/events/group.jsonc', kept: [] };
			},
		});
		renderScreen();

		await screen.findByText('Event details');
		await user.click(within(rowOf('Event details')).getByRole('button', { name: 'Delete Event details' }));

		const dialog = await screen.findByRole('alertdialog');
		expect(dialog).toHaveTextContent('proto-fields/events/group.jsonc');
		const confirm = within(dialog).getByRole('button', { name: 'Delete field group' });
		expect(confirm).toBeDisabled();
		await user.type(within(dialog).getByRole('textbox'), 'events');
		expect(confirm).toBeEnabled();
		await user.click(confirm);

		await waitFor(() => expect(screen.queryByText('Event details')).not.toBeInTheDocument());
		expect(calls()).toEqual([
			{ url: ROOT + 'admin/fields', method: 'GET' },
			{ url: ROOT + 'admin/schema-files/field-group/events?confirm=events', method: 'DELETE' },
			{ url: ROOT + 'admin/fields', method: 'GET' },
		]);
	});

	it('deletes a group inside a type file by rewriting that file', async () => {
		const user = userEvent.setup();
		const text = '{\n  // Talks\n  "name": "Talk",\n  "fields": [\n    { "key": "group_a", "title": "A", "fields": [] },\n    { "key": "group_talk", "title": "Talk details", "fields": [] }\n  ]\n}\n';
		let body: { text: string; hash: string | null; acknowledge: string[] } | null = null;
		mockApi({
			'admin/fields': list(),
			'admin/schema-files/type/talk': { exists: true, path: 'demo/proto-types/talk/type.jsonc', text, hash: 'h1', data: parseJsonc(text).data, writable: true, reason: null },
			'POST admin/schema-files/type/talk': (data: unknown) => {
				body = data as typeof body;
				return { path: 'demo/proto-types/talk/type.jsonc', hash: 'h2', created: false, warnings: [] };
			},
		});
		renderScreen();

		await screen.findByText('Talk details');
		await user.click(within(rowOf('Talk details')).getByRole('button', { name: 'Delete Talk details' }));
		const dialog = await screen.findByRole('alertdialog');
		expect(dialog).toHaveTextContent('proto-types/talk/type.jsonc');
		await user.click(within(dialog).getByRole('button', { name: 'Remove field group' }));

		await waitFor(() => expect(body).not.toBeNull());
		expect(body!.hash).toBe('h1');
		expect(body!.acknowledge).toEqual([]);
		expect(parseJsonc(body!.text).data).toEqual({ name: 'Talk', fields: [{ key: 'group_a', title: 'A', fields: [] }] });
		expect(body!.text).toContain('// Talks');
		expect(calls().map((c) => `${c.method} ${c.url}`)).toEqual([
			`GET ${ROOT}admin/fields`,
			`GET ${ROOT}admin/schema-files/type/talk`,
			`POST ${ROOT}admin/schema-files/type/talk`,
			`GET ${ROOT}admin/fields`,
		]);
	});

	it('removes only one group from a multi-group (ACF export) file and names the others', async () => {
		const user = userEvent.setup();
		const text =
			'[\n  // exported from ACF\n  { "key": "group_events", "title": "Event details", "fields": [] },\n  { "key": "group_speaker", "title": "Speaker", "fields": [] }\n]\n';
		let body: { text: string; hash: string | null } | null = null;
		const exported = row({ key: 'group_speaker', title: 'Speaker', file: { kind: 'field-group', slug: 'events', index: 1, path: 'proto-fields/events/group.jsonc' } });
		mockApi({
			'admin/fields': list({ groups: [row({ file: { kind: 'field-group', slug: 'events', index: 0, path: 'proto-fields/events/group.jsonc' } }), exported] }),
			'admin/schema-files/field-group/events': { exists: true, path: 'demo/proto-fields/events/group.jsonc', text, hash: 'h1', data: {}, writable: true, reason: null },
			'POST admin/schema-files/field-group/events': (data: unknown) => {
				body = data as typeof body;
				return { path: 'demo/proto-fields/events/group.jsonc', hash: 'h2', created: false, warnings: [] };
			},
		});
		renderScreen();

		await screen.findByText('Speaker');
		await user.click(within(rowOf('Speaker')).getByRole('button', { name: 'Delete Speaker' }));
		const dialog = await screen.findByRole('alertdialog');
		await within(dialog).findByText('The file also holds Event details; those groups are kept.');
		expect(within(dialog).queryByRole('textbox')).not.toBeInTheDocument();
		await user.click(within(dialog).getByRole('button', { name: 'Remove field group' }));

		await waitFor(() => expect(body).not.toBeNull());
		expect(body!.hash).toBe('h1');
		expect(body!.text).toContain('// exported from ACF');
		expect(parseJsoncValue(body!.text)).toEqual([{ key: 'group_events', title: 'Event details', fields: [] }]);
		expect(calls().map((c) => `${c.method} ${c.url}`)).not.toContain(`DELETE ${ROOT}admin/schema-files/field-group/events?confirm=events`);
	});

	it('deletes a list file that holds only this group as a whole file', async () => {
		const user = userEvent.setup();
		const text = '[ { "key": "group_events", "title": "Event details", "fields": [] } ]';
		mockApi({
			'admin/fields': list({ groups: [row({ file: { kind: 'field-group', slug: 'events', index: 0, path: 'proto-fields/events/group.jsonc' } })] }),
			'admin/schema-files/field-group/events': { exists: true, path: 'demo/proto-fields/events/group.jsonc', text, hash: 'h1', data: {}, writable: true, reason: null },
			'DELETE admin/schema-files/field-group/events?confirm=events': { deleted: 'demo/proto-fields/events/group.jsonc', kept: [] },
		});
		renderScreen();

		await screen.findByText('Event details');
		await user.click(within(rowOf('Event details')).getByRole('button', { name: 'Delete Event details' }));
		const dialog = await screen.findByRole('alertdialog');
		await user.type(await within(dialog).findByRole('textbox'), 'events');
		await user.click(within(dialog).getByRole('button', { name: 'Delete field group' }));

		await waitFor(() => expect(calls().map((c) => `${c.method} ${c.url}`)).toContain(`DELETE ${ROOT}admin/schema-files/field-group/events?confirm=events`));
		expect(calls().some((c) => c.method === 'POST')).toBe(false);
	});

	it('explains rows that cannot be written for their own reason', async () => {
		mockApi({
			'admin/fields': list({
				groups: [
					row({ title: 'Filtered', key: 'group_filtered', editable: false, readOnlyReason: 'not_writable', source: 'proto-fields/filtered/group.jsonc' }),
					row({ title: 'Genre extras', key: 'group_genre', origin: 'taxonomy-file', file: null, editable: false, readOnlyReason: 'inline' }),
				],
			}),
		});
		renderScreen();

		await screen.findByText('Filtered');
		expect(within(rowOf('Filtered')).queryByRole('button', { name: /^Delete/ })).not.toBeInTheDocument();
		expect(within(rowOf('Filtered')).getByRole('button', { name: /proto-fields\/filtered\/group\.jsonc is outside the theme folders/ })).toBeInTheDocument();
		expect(within(rowOf('Genre extras')).getByRole('button', { name: /taxonomy declared inside a post type file/ })).toBeInTheDocument();
	});

	it('asks again when removing a group from a type file hits a guardrail', async () => {
		const user = userEvent.setup();
		const text = '{ "name": "Talk", "fields": [ { "key": "group_a", "title": "A", "fields": [] }, { "key": "group_talk", "title": "Talk details", "fields": [] } ] }';
		const bodies: { acknowledge: string[] }[] = [];
		mockApi({
			'admin/fields': list(),
			'admin/schema-files/type/talk': { exists: true, path: 'demo/proto-types/talk/type.jsonc', text, hash: 'h1', data: parseJsonc(text).data, writable: true, reason: null },
			'POST admin/schema-files/type/talk': (data: unknown) => {
				bodies.push(data as { acknowledge: string[] });
				if (bodies.length === 1) {
					throw {
						code: 'proto_blocks_needs_confirmation',
						message: 'This change affects existing content.',
						data: { status: 409, guardrails: [{ code: 'field_removed', message: 'Stored values stay under the old meta key room.', count: 3, detail: ['room'] }] },
					};
				}
				return { path: 'demo/proto-types/talk/type.jsonc', hash: 'h2', created: false, warnings: [] };
			},
		});
		renderScreen();

		await screen.findByText('Talk details');
		await user.click(within(rowOf('Talk details')).getByRole('button', { name: 'Delete Talk details' }));
		await user.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Remove field group' }));

		const dialog = await screen.findByRole('alertdialog');
		await within(dialog).findByText('Stored values stay under the old meta key room.');
		await user.click(within(dialog).getByRole('button', { name: 'Remove anyway' }));
		await waitFor(() => expect(bodies).toHaveLength(2));
		expect(bodies[1].acknowledge).toEqual(['field_removed']);
	});

	it('shows the module-off notice and no write controls', async () => {
		mockApi({ 'admin/fields': list({ moduleEnabled: false, groups: [] }) });
		renderScreen();

		expect(await screen.findByText('The Fields module is turned off by the proto_blocks_modules filter.')).toBeInTheDocument();
		expect(screen.queryByRole('button', { name: 'Add field group' })).not.toBeInTheDocument();
		expect(screen.queryByRole('table')).not.toBeInTheDocument();
	});

	it('routes to the builder with pushState, and back', async () => {
		const user = userEvent.setup();
		mockApi({ 'admin/fields': list() });
		renderScreen();
		const push = jest.spyOn(window.history, 'pushState');

		await screen.findByText('Event details');
		await user.click(within(rowOf('Event details')).getByRole('button', { name: 'Edit Event details' }));
		expect(window.location.search).toBe('?page=proto-blocks-fields&group=group_events');
		expect(push).toHaveBeenCalledTimes(1);
		expect(screen.queryByRole('table')).not.toBeInTheDocument();

		await user.click(screen.getByRole('button', { name: 'Back to field groups' }));
		expect(window.location.search).toBe('?page=proto-blocks-fields');
		await screen.findByText('Event details');

		await user.click(screen.getByRole('button', { name: 'Add field group' }));
		expect(window.location.search).toBe('?page=proto-blocks-fields&new=1');
		push.mockRestore();
	});
});
