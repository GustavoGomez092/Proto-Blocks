import { mockApi, mockBootstrap } from '../helpers';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';
import { readFileSync } from 'fs';
import path from 'path';
import { parse } from 'jsonc-parser';
import { Builder } from '@/admin/fields/builder';

const ROOT = 'https://example.test/wp-json/proto-blocks/v1/';
const TEXT = readFileSync(path.join(__dirname, '../../../../tests/fixtures/fields/acf-export-realistic.json'), 'utf8');
const FIXTURE = JSON.parse(TEXT) as Record<string, unknown>[];
const CHOICES = { postTypes: [{ slug: 'event', label: 'Events' }], taxonomies: [], roles: [], optionsPages: [], pageTemplates: [] };

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
	jest.clearAllMocks();
});

function detail(overrides: Record<string, unknown> = {}) {
	return {
		group: FIXTURE[0],
		issues: [],
		file: {
			exists: true,
			path: 'proto-fields/acf-export/group.json',
			text: TEXT,
			hash: 'hash-acf',
			data: {},
			writable: true,
			reason: null,
			kind: 'field-group',
			slug: 'acf-export',
			index: 0,
		},
		...CHOICES,
		...overrides,
	};
}

function requests() {
	return (apiFetch as unknown as jest.Mock).mock.calls.map(([options]) => ({ url: options.url, method: options.method ?? 'GET', data: options.data }));
}

const KEY = String(FIXTURE[0].key);
const saveButton = () => screen.getByRole('button', { name: 'Save to file' });

async function open(routes: Record<string, unknown> = {}) {
	mockApi({ [`admin/fields/${KEY}`]: detail(), ...routes });
	render(<Builder group={KEY} onBack={() => {}} />);
	return screen.findByRole('textbox', { name: 'Group title' });
}

describe('Builder save flow', () => {
	it('writes the edited ACF export with the GET hash and keeps every ACF-only key', async () => {
		mockBootstrap({ screen: 'fields' });
		const saved = jest.fn(() => ({ path: 'proto-fields/acf-export/group.json', hash: 'hash-2', created: false }));
		const title = await open({ 'POST admin/schema-files/field-group/acf-export': saved });
		await userEvent.type(title, ' 2');
		await userEvent.click(saveButton());
		await waitFor(() => expect(saved).toHaveBeenCalled());

		const post = requests().find((r) => r.method === 'POST');
		expect(requests()[0].url).toBe(`${ROOT}admin/fields/${KEY}`);
		expect(post?.url).toBe(`${ROOT}admin/schema-files/field-group/acf-export`);
		const body = post?.data as { text: string; hash: string; acknowledge: string[] };
		expect(body.hash).toBe('hash-acf');
		expect(body.acknowledge).toEqual([]);
		expect(JSON.parse(body.text)).toEqual([{ ...FIXTURE[0], title: 'Event details 2' }]);
		await waitFor(() => expect(screen.getByRole('button', { name: 'Discard changes' })).toBeDisabled());
	});

	it('edits the file as text: a syntax error disables Save and shows its line and column', async () => {
		mockBootstrap({ screen: 'fields' });
		const validate = jest.fn(() => {
			throw { code: 'proto_blocks_invalid_jsonc', message: 'Invalid JSONC at line 3, column 5: x', data: { status: 400, line: 3, column: 5 } };
		});
		await open({ 'POST admin/fields/validate': validate });
		await userEvent.click(screen.getByRole('button', { name: 'JSONC' }));
		const pane = screen.getByRole('textbox', { name: 'File text' });
		expect((pane as HTMLTextAreaElement).value).toBe(TEXT);

		fireEvent.change(pane, { target: { value: '[\n  {\n    ]\n]' } });
		expect(screen.getByText(/^Line 3, column 5/)).toBeInTheDocument();
		expect(saveButton()).toBeDisabled();
		expect(screen.getByRole('button', { name: 'Builder' })).toBeDisabled();
		await waitFor(() => expect(validate).toHaveBeenCalledWith({ text: '[\n  {\n    ]\n]', kind: 'field-group' }));
		expect(screen.getByText(/^Line 3, column 5/)).toBeInTheDocument();
	});

	it('applies valid text to the builder', async () => {
		mockBootstrap({ screen: 'fields' });
		await open({ 'POST admin/fields/validate': { errors: [], warnings: [] } });
		await userEvent.click(screen.getByRole('button', { name: 'JSONC' }));
		fireEvent.change(screen.getByRole('textbox', { name: 'File text' }), { target: { value: TEXT.replace('"Event details"', '"Talks"') } });
		expect(await screen.findByText('Valid')).toBeInTheDocument();
		await userEvent.click(screen.getByRole('button', { name: 'Builder' }));
		expect(screen.getByRole('textbox', { name: 'Group title' })).toHaveValue('Talks');
	});

	it('reports a group missing from valid text, blocks Save and the builder, and drops the stale group', async () => {
		mockBootstrap({ screen: 'fields' });
		await open({ 'POST admin/fields/validate': { errors: [], warnings: [] } });
		await userEvent.click(screen.getByRole('button', { name: 'JSONC' }));
		fireEvent.change(screen.getByRole('textbox', { name: 'File text' }), { target: { value: '[]\n' } });
		expect(screen.getByText('Group not found at [0]')).toBeInTheDocument();
		expect(saveButton()).toBeDisabled();
		expect(screen.getByRole('button', { name: 'Builder' })).toBeDisabled();
		expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Untitled field group');

		fireEvent.change(screen.getByRole('textbox', { name: 'File text' }), { target: { value: TEXT } });
		expect(screen.queryByText('Group not found at [0]')).toBeNull();
		expect(screen.getByRole('button', { name: 'Builder' })).toBeEnabled();
	});

	it('stays in the JSONC view when the save is refused', async () => {
		mockBootstrap({ screen: 'fields' });
		const saved = jest.fn(() => {
			throw { code: 'proto_blocks_invalid_declaration', message: 'Invalid.', data: { status: 400, errors: [{ path: '[0].fields[1].name', message: 'Bad name.' }] } };
		});
		await open({ 'POST admin/schema-files/field-group/acf-export': saved, 'POST admin/fields/validate': { errors: [], warnings: [] } });
		await userEvent.click(screen.getByRole('button', { name: 'JSONC' }));
		await userEvent.click(saveButton());
		expect(await screen.findByText('Bad name.')).toBeInTheDocument();
		expect(screen.getByRole('textbox', { name: 'File text' })).toBeInTheDocument();
	});

	it('lists the PUT errors with their paths and keeps the unsaved state', async () => {
		mockBootstrap({ screen: 'fields' });
		const saved = jest.fn(() => {
			throw {
				code: 'proto_blocks_invalid_declaration',
				message: 'Invalid.',
				data: { status: 400, errors: [{ path: 'fields[0].name', message: 'Bad name.' }] },
			};
		});
		const title = await open({ 'POST admin/schema-files/field-group/acf-export': saved });
		await userEvent.type(title, ' 2');
		await userEvent.click(saveButton());
		const alert = await screen.findByRole('alert');
		expect(within(alert).getByText('fields[0].name')).toBeInTheDocument();
		expect(alert).toHaveTextContent('Bad name.');
		expect(screen.getByRole('textbox', { name: 'Group title' })).toHaveValue('Event details 2');
		expect(screen.getByRole('button', { name: 'Discard changes' })).toBeEnabled();
		await userEvent.click(within(alert).getByRole('button', { name: 'Dismiss' }));
		expect(screen.queryByText('Bad name.')).toBeNull();
	});

	it('opens the conflict dialog on a 409 and does not retry', async () => {
		mockBootstrap({ screen: 'fields' });
		const saved = jest.fn(() => {
			throw { code: 'proto_blocks_conflict', message: 'Changed.', data: { status: 409 } };
		});
		const title = await open({ 'POST admin/schema-files/field-group/acf-export': saved });
		await userEvent.type(title, ' 2');
		await userEvent.click(saveButton());
		expect(await screen.findByRole('button', { name: 'Reload file' })).toBeInTheDocument();
		expect(saved).toHaveBeenCalledTimes(1);
	});

	it('confirms guardrails and saves again with the acknowledged codes', async () => {
		mockBootstrap({ screen: 'fields' });
		const bodies: { acknowledge: string[] }[] = [];
		const saved = jest.fn((body: unknown) => {
			bodies.push(body as { acknowledge: string[] });
			if (bodies.length === 1) {
				throw {
					code: 'proto_blocks_needs_confirmation',
					message: 'Confirm.',
					data: { status: 409, guardrails: [{ code: 'field_removed', message: 'Removes "subtitle" from 3 posts.', count: 3, detail: [] }] },
				};
			}
			return { path: 'proto-fields/acf-export/group.json', hash: 'hash-2', created: false };
		});
		const title = await open({ 'POST admin/schema-files/field-group/acf-export': saved });
		await userEvent.type(title, ' 2');
		await userEvent.click(saveButton());
		expect(await screen.findByText('Removes "subtitle" from 3 posts.')).toBeInTheDocument();
		await userEvent.click(screen.getByRole('button', { name: 'Save anyway' }));
		await waitFor(() => expect(bodies.map((b) => b.acknowledge)).toEqual([[], ['field_removed']]));
	});

	it('asks before leaving with unsaved changes', async () => {
		mockBootstrap({ screen: 'fields' });
		const onBack = jest.fn();
		mockApi({ [`admin/fields/${KEY}`]: detail() });
		render(<Builder group={KEY} onBack={onBack} />);
		await userEvent.type(await screen.findByRole('textbox', { name: 'Group title' }), ' 2');
		await userEvent.click(screen.getByRole('button', { name: 'Back to field groups' }));
		await userEvent.click(await screen.findByRole('button', { name: 'Leave' }));
		expect(onBack).toHaveBeenCalledTimes(1);
	});

	it('is read-only without file mods: JSONC view available, Save and Add disabled, banner shown', async () => {
		mockBootstrap({ screen: 'fields', fileModsAllowed: false });
		await open();
		expect(screen.getByText('File modifications are disabled on this site')).toBeInTheDocument();
		expect(saveButton()).toBeDisabled();
		expect(screen.getByRole('button', { name: 'Add field' })).toBeDisabled();
		await userEvent.click(screen.getByRole('button', { name: 'JSONC' }));
		expect(screen.getByRole('textbox', { name: 'File text' })).toHaveAttribute('readonly');
	});

	it('opens an ACF-registered group read-only', async () => {
		mockBootstrap({ screen: 'fields' });
		mockApi({ 'admin/fields/group_acf': { group: { key: 'group_acf', title: 'From ACF' }, issues: [], file: null, ...CHOICES } });
		render(<Builder group="group_acf" onBack={() => {}} />);
		expect(await screen.findByText('This group is registered by Advanced Custom Fields.')).toBeInTheDocument();
		expect(saveButton()).toBeDisabled();
	});
});

describe('New field group dialog', () => {
	it('creates its own file with the scaffold header after the server says the slug is free', async () => {
		mockBootstrap({ screen: 'fields' });
		const onCreated = jest.fn();
		const saved = jest.fn(() => ({ path: 'proto-fields/speaker/group.jsonc', hash: 'h', created: true }));
		mockApi({
			'admin/schema-files/field-group/speaker': {
				exists: false,
				path: 'proto-fields/speaker/group.jsonc',
				text: '{}',
				hash: null,
				data: {},
				writable: true,
				reason: null,
			},
			'POST admin/schema-files/field-group/speaker': saved,
		});
		render(<Builder group={null} onBack={() => {}} onCreated={onCreated} />);
		await userEvent.type(screen.getByRole('textbox', { name: 'Title' }), 'Speaker');
		expect(screen.getByText('proto-fields/speaker/group.jsonc')).toBeInTheDocument();
		await userEvent.click(screen.getByRole('button', { name: 'Create field group' }));
		await waitFor(() => expect(onCreated).toHaveBeenCalled());

		expect(requests().map((r) => `${r.method} ${r.url}`)).toEqual([
			`GET ${ROOT}admin/schema-files/field-group/speaker`,
			`POST ${ROOT}admin/schema-files/field-group/speaker`,
		]);
		const body = requests()[1].data as { text: string; hash: null };
		expect(body.hash).toBeNull();
		expect(body.text.startsWith('// Field group: Speaker. Edited by the Proto-Blocks Fields screen; comments are kept.\n')).toBe(true);
		const group = parse(body.text);
		expect(group).toEqual({ key: onCreated.mock.calls[0][0], title: 'Speaker', fields: [], location: [] });
		expect(group.key).toMatch(/^group_[a-z0-9]+$/);
	});

	it('refuses a taken slug and never writes', async () => {
		mockBootstrap({ screen: 'fields' });
		mockApi({
			'admin/schema-files/field-group/speaker': {
				exists: true,
				path: 'proto-fields/speaker/group.jsonc',
				text: '{}',
				hash: 'x',
				data: {},
				writable: true,
				reason: null,
			},
		});
		render(<Builder group={null} onBack={() => {}} />);
		await userEvent.type(screen.getByRole('textbox', { name: 'Title' }), 'Speaker');
		await userEvent.click(screen.getByRole('button', { name: 'Create field group' }));
		const taken = await screen.findByText('proto-fields/speaker/group.jsonc already exists. Pick another file name.');
		const slug = screen.getByRole('textbox', { name: 'File name' });
		expect(slug).toHaveAttribute('aria-invalid', 'true');
		expect(slug.getAttribute('aria-describedby')).toContain(taken.id);
		expect(requests().some((r) => r.method === 'POST')).toBe(false);
	});

	// A text input drops line breaks itself; commentSafe() also folds any that reach it.
	it('keeps the title inside the header comment', async () => {
		mockBootstrap({ screen: 'fields' });
		const saved = jest.fn(() => ({ path: 'proto-fields/x/group.jsonc', hash: 'h', created: true }));
		mockApi({
			'admin/schema-files/field-group/x': { exists: false, path: 'proto-fields/x/group.jsonc', text: '{}', hash: null, data: {}, writable: true, reason: null },
			'POST admin/schema-files/field-group/x': saved,
		});
		render(<Builder group={null} onBack={() => {}} onCreated={() => {}} />);
		fireEvent.change(screen.getByRole('textbox', { name: 'Title' }), { target: { value: 'A\n"x": 1, //' } });
		fireEvent.change(screen.getByRole('textbox', { name: 'File name' }), { target: { value: 'x' } });
		await userEvent.click(screen.getByRole('button', { name: 'Create field group' }));
		await waitFor(() => expect(saved).toHaveBeenCalled());
		const text = (requests()[1].data as { text: string }).text;
		expect(text.split('\n')[0]).toBe('// Field group: A"x": 1, //. Edited by the Proto-Blocks Fields screen; comments are kept.');
		expect(parse(text).x).toBeUndefined();
	});

	it('appends the group to a post type file with that file’s hash', async () => {
		mockBootstrap({ screen: 'fields' });
		const typeText = '{\n  // Events.\n  "slug": "event",\n  "fields": []\n}\n';
		const saved = jest.fn(() => ({ path: 'proto-types/event/type.jsonc', hash: 'h2', created: false }));
		mockApi({
			'admin/types': {
				moduleEnabled: true,
				createTarget: { path: '', writable: true, reason: null },
				issues: [],
				items: [
					{ slug: 'event', label: 'Events', origin: 'file', editable: true },
					{ slug: 'post', label: 'Posts', origin: 'core', editable: false },
				],
			},
			'admin/schema-files/type/event': {
				exists: true,
				path: 'proto-types/event/type.jsonc',
				text: typeText,
				hash: 'h1',
				data: {},
				writable: true,
				reason: null,
			},
			'POST admin/schema-files/type/event': saved,
		});
		render(<Builder group={null} onBack={() => {}} onCreated={() => {}} />);
		await userEvent.type(screen.getByRole('textbox', { name: 'Title' }), 'Speaker');
		await userEvent.click(screen.getByRole('radio', { name: 'Inside a post type file' }));
		const select = await screen.findByRole('combobox', { name: 'Post type' });
		await waitFor(() =>
			expect(
				within(select)
					.getAllByRole('option')
					.map((o) => o.textContent)
			).toEqual(['Events'])
		);
		await userEvent.click(screen.getByRole('button', { name: 'Create field group' }));
		await waitFor(() => expect(saved).toHaveBeenCalled());
		const post = requests().find((r) => r.method === 'POST');
		expect(post?.url).toBe(`${ROOT}admin/schema-files/type/event`);
		const body = post?.data as { text: string; hash: string };
		expect(body.hash).toBe('h1');
		expect(body.text).toContain('// Events.');
		expect(parse(body.text).fields).toEqual([{ key: expect.stringMatching(/^group_/), title: 'Speaker', fields: [] }]);
	});
});
