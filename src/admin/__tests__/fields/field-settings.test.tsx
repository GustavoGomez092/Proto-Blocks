import { mockApi, mockBootstrap } from '../helpers';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';
import { parse } from 'jsonc-parser';
import { AddFieldMenu, Builder, ConditionBuilder, FieldSettings } from '@/admin/fields/builder';
import type { RawField } from '@/admin/screens/fields/builder-state';

const ROOT = 'https://example.test/wp-json/proto-blocks/v1/';

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

function settings(field: RawField, overrides: Partial<Parameters<typeof FieldSettings>[0]> = {}) {
	const onChange = jest.fn();
	render(<FieldSettings field={field} siblings={[{ key: 'field_o', name: 'taken', type: 'text' }]} onChange={onChange} {...overrides} />);
	return onChange;
}

describe('FieldSettings', () => {
	it('edits select choices as value : label lines', async () => {
		const onChange = settings({ key: 'field_s', label: 'Colour', name: 'colour', type: 'select' });
		const choices = screen.getByRole('textbox', { name: 'Choices' });
		await userEvent.type(choices, 'red : Red{Enter}blue');
		await waitFor(() => expect(onChange).toHaveBeenLastCalledWith({ choices: { red: 'Red', blue: 'blue' } }));
		expect(screen.getByRole('switch', { name: 'Allow multiple' })).toBeInTheDocument();
	});

	it('rejects a name outside the schema grammar and a duplicate name', async () => {
		settings({ key: 'field_s', label: 'Colour', name: 'colour', type: 'text' });
		const name = screen.getByRole('textbox', { name: 'Field name' });
		await userEvent.clear(name);
		await userEvent.type(name, '_x');
		const error = await screen.findByRole('alert');
		expect(error).toHaveTextContent('Field name "_x" must start with a letter and contain only letters, digits, "_" or "-" (64 characters at most).');
		expect(name).toHaveAttribute('aria-invalid', 'true');
		expect(name.getAttribute('aria-describedby')).toContain(error.id);

		await userEvent.clear(name);
		await userEvent.type(name, 'taken');
		expect(await screen.findByRole('alert')).toHaveTextContent('The name "taken" is used by more than one field at this level.');
	});

	it('follows the label with the name until the name is edited, for a new field', async () => {
		const onChange = settings({ key: 'field_n', label: 'New field', name: 'new_field', type: 'text' });
		const label = screen.getByRole('textbox', { name: 'Label' });
		await userEvent.clear(label);
		await userEvent.type(label, 'Start date');
		await waitFor(() => expect(onChange).toHaveBeenLastCalledWith({ label: 'Start date', name: 'start_date' }));
		await userEvent.type(screen.getByRole('textbox', { name: 'Field name' }), 's');
		await waitFor(() => expect(onChange).toHaveBeenLastCalledWith({ name: 'start_dates' }));
		await userEvent.type(label, '!');
		await waitFor(() => expect(onChange).toHaveBeenLastCalledWith({ label: 'Start date!' }));
	});

	it('warns when a saved field is renamed', async () => {
		settings({ key: 'field_s', label: 'Colour', name: 'colour', type: 'text' }, { savedName: 'colour' });
		const label = screen.getByRole('textbox', { name: 'Label' });
		await userEvent.type(label, 's');
		// A saved field's name does not follow the label.
		expect(screen.getByRole('textbox', { name: 'Field name' })).toHaveValue('colour');
		await userEvent.type(screen.getByRole('textbox', { name: 'Field name' }), '_new');
		expect(screen.getByText('Stored values stay under the old meta key "colour".')).toBeInTheDocument();
	});

	it('lists post types and taxonomies from the GET payload for a relationship', async () => {
		const onChange = settings(
			{ key: 'field_r', label: 'Related', name: 'related', type: 'post_object' },
			{ choices: { postTypes: [{ slug: 'post', label: 'Posts' }, { slug: 'event', label: 'Events' }], taxonomies: [{ slug: 'category', label: 'Categories' }] } }
		);
		const postTypes = screen.getByRole('group', { name: 'Post types' });
		await userEvent.click(within(postTypes).getByRole('checkbox', { name: 'Events' }));
		await waitFor(() => expect(onChange).toHaveBeenLastCalledWith({ post_type: ['event'] }));
		expect(within(screen.getByRole('group', { name: 'Taxonomies' })).getByRole('checkbox', { name: 'Categories' })).toBeInTheDocument();
	});

	it('writes numbers as numbers and removes them when cleared', async () => {
		const onChange = settings({ key: 'field_n', label: 'Count', name: 'count', type: 'number', min: 1 });
		expect(screen.queryByRole('textbox', { name: 'Minimum' })).toBeNull();
		await userEvent.click(screen.getByRole('tab', { name: 'Validation' }));
		const min = screen.getByRole('textbox', { name: 'Minimum' });
		await userEvent.clear(min);
		await waitFor(() => expect(onChange).toHaveBeenLastCalledWith({ min: undefined }));
		await userEvent.type(min, '5');
		await waitFor(() => expect(onChange).toHaveBeenLastCalledWith({ min: 5 }));
	});
});

describe('ConditionBuilder', () => {
	const fields: RawField[] = [
		{ key: 'field_b', label: 'Online', name: 'is_online', type: 'true_false' },
		{ key: 'field_s', label: 'Format', name: 'format', type: 'select', choices: { pdf: 'PDF guide', video: 'Video' } },
		{ key: 'field_t', label: 'Agenda', name: 'agenda', type: 'repeater', sub_fields: [] },
	];

	it('offers only sibling value fields and emits the server’s rule shapes', async () => {
		const onChange = jest.fn();
		const { rerender } = render(<ConditionBuilder fields={fields} value={0} onChange={onChange} />);
		await userEvent.click(screen.getByRole('button', { name: 'Add rule' }));
		const value = [[{ field: 'field_b', operator: '==', value: '1' }]];
		expect(onChange).toHaveBeenLastCalledWith(value);

		rerender(<ConditionBuilder fields={fields} value={value} onChange={onChange} />);
		const group = screen.getByRole('group', { name: 'Rule group 1' });
		const field = within(group).getByRole('combobox', { name: 'Field' });
		expect(within(field).getAllByRole('option').map((o) => o.textContent)).toEqual(['Online', 'Format']);
		expect(within(within(group).getByRole('combobox', { name: 'Operator' })).getAllByRole('option')).toHaveLength(2);

		await userEvent.selectOptions(field, 'field_s');
		expect(onChange).toHaveBeenLastCalledWith([[{ field: 'field_s', operator: '==', value: 'pdf' }]]);
		const next = [[{ field: 'field_s', operator: '==', value: 'pdf' }]];
		rerender(<ConditionBuilder fields={fields} value={next} onChange={onChange} />);
		await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Operator' }), '!=empty');
		expect(onChange).toHaveBeenLastCalledWith([[{ field: 'field_s', operator: '!=empty' }]]);

		await userEvent.click(screen.getByRole('button', { name: 'or, another rule group' }));
		expect(onChange).toHaveBeenLastCalledWith([...next, [{ field: 'field_b', operator: '==', value: '1' }]]);
		await userEvent.click(screen.getByRole('button', { name: 'Remove all rules' }));
		expect(onChange).toHaveBeenLastCalledWith(0);
	});
});

describe('AddFieldMenu', () => {
	it('lists the type families by category with their ACF aliases and filters', async () => {
		const onPick = jest.fn();
		render(<AddFieldMenu open onOpenChange={() => {}} onPick={onPick} />);
		const dialog = screen.getByRole('dialog', { name: 'Add field' });
		for (const heading of ['Basic', 'Content', 'Choice', 'Relational', 'Layout']) {
			expect(within(dialog).getByRole('group', { name: heading })).toBeInTheDocument();
		}
		expect(within(dialog).getByRole('button', { name: 'Date ISO stored' })).toBeInTheDocument();
		expect(dialog).toHaveTextContent('Also accepts ACF type names');
		const search = within(dialog).getByRole('searchbox', { name: 'Search field types' });
		// The ACF alias finds the canonical type.
		await userEvent.type(search, 'date_picker');
		expect(within(dialog).getAllByRole('button').map((b) => b.textContent)).toEqual(['DateISO stored']);
		await userEvent.clear(search);
		await userEvent.type(search, 'repeat');
		expect(within(dialog).getAllByRole('button', { name: /Repeater/ })).toHaveLength(1);
		expect(within(dialog).queryByRole('button', { name: /^Text\b/ })).not.toBeInTheDocument();
		await userEvent.click(within(dialog).getByRole('button', { name: /Repeater/ }));
		expect(onPick).toHaveBeenCalledWith('repeater');
	});
});

describe('Builder', () => {
	const text = `{
  "key": "group_events",
  "title": "Event details",
  // Keep in box order.
  "fields": [
    { "key": "field_a", "label": "Colour", "name": "colour", "type": "select", "choices": {}, "aria-label": "" }
  ],
  "location": [[{ "param": "post_type", "operator": "==", "value": "event" }]]
}
`;

	function detail() {
		return {
			group: parse(text),
			issues: [],
			file: {
				exists: true,
				path: 'proto-fields/events/group.jsonc',
				text,
				hash: 'hash-1',
				data: parse(text),
				writable: true,
				reason: null,
				kind: 'field-group',
				slug: 'events',
				index: null,
			},
			postTypes: [{ slug: 'event', label: 'Events' }],
			taxonomies: [],
			roles: [],
			optionsPages: [],
			pageTemplates: [],
		};
	}

	function requests() {
		return (apiFetch as unknown as jest.Mock).mock.calls.map(([options]) => ({ url: options.url, method: options.method, data: options.data }));
	}

	/** The draft's file text, read in the JSONC view (then back to the builder). */
	async function fileText(): Promise<string> {
		await userEvent.click(screen.getByRole('button', { name: 'JSONC' }));
		const text = (screen.getByRole('textbox', { name: 'File text' }) as HTMLTextAreaElement).value;
		await userEvent.click(screen.getByRole('button', { name: 'Builder' }));
		return text;
	}

	const edited = () => waitFor(() => expect(screen.getByRole('button', { name: 'Discard changes' })).toBeEnabled());

	it('loads the group, writes edits into the JSONC text and saves exactly the previewed text with the loaded hash', async () => {
		mockBootstrap({ screen: 'fields' });
		const saved = jest.fn(() => ({ path: 'proto-fields/events/group.jsonc', hash: 'hash-2', created: false }));
		mockApi({ 'admin/fields/group_events': detail(), 'POST admin/schema-files/field-group/events': saved });
		render(<Builder group="group_events" onBack={() => {}} />);

		await userEvent.click(await screen.findByRole('button', { name: 'Field 1 of 1, Colour, Select' }));
		await userEvent.type(screen.getByRole('textbox', { name: 'Choices' }), 'red : Red');
		await edited();
		const preview = await fileText();
		expect(preview).toContain('"red": "Red"');
		expect(preview).toContain('// Keep in box order.');

		await userEvent.click(screen.getByRole('button', { name: 'Save to file' }));
		await waitFor(() => expect(saved).toHaveBeenCalled());
		const post = requests().find((r) => r.method === 'POST');
		expect(requests()[0]).toEqual({ url: `${ROOT}admin/fields/group_events`, method: 'GET', data: undefined });
		expect(post?.url).toBe(`${ROOT}admin/schema-files/field-group/events`);
		expect(post?.data).toEqual({ text: preview, hash: 'hash-1', acknowledge: [] });
		const written = parse((post?.data as { text: string }).text);
		expect(written.fields[0]).toEqual({ key: 'field_a', label: 'Colour', name: 'colour', type: 'select', choices: { red: 'Red' }, 'aria-label': '' });
	});

	it('refuses to save an invalid name and moves focus to it', async () => {
		mockBootstrap({ screen: 'fields' });
		mockApi({ 'admin/fields/group_events': detail() });
		render(<Builder group="group_events" onBack={() => {}} />);
		await userEvent.click(await screen.findByRole('button', { name: 'Field 1 of 1, Colour, Select' }));
		const name = screen.getByRole('textbox', { name: 'Field name' });
		await userEvent.clear(name);
		await userEvent.type(name, '9lives');
		await userEvent.click(screen.getByRole('button', { name: 'Save to file' }));
		await waitFor(() => expect(screen.getByRole('textbox', { name: 'Field name' })).toHaveFocus());
		expect(screen.getAllByRole('alert').map((a) => a.textContent).join(' ')).toContain('Field name "9lives" must start with a letter');
		expect(requests().some((r) => r.method === 'POST')).toBe(false);
	});

	it('opens the conflict dialog on a 409 and reloads without overwriting', async () => {
		mockBootstrap({ screen: 'fields' });
		const saved = jest.fn(() => {
			throw { code: 'proto_blocks_conflict', message: 'Changed.', data: { status: 409 } };
		});
		mockApi({ 'admin/fields/group_events': detail(), 'POST admin/schema-files/field-group/events': saved });
		render(<Builder group="group_events" onBack={() => {}} />);
		await userEvent.click(await screen.findByRole('button', { name: 'Field 1 of 1, Colour, Select' }));
		await userEvent.type(screen.getByRole('textbox', { name: 'Label' }), 's');
		await edited();
		expect(await fileText()).toContain('"Colours"');
		await userEvent.click(screen.getByRole('button', { name: 'Save to file' }));
		await userEvent.click(await screen.findByRole('button', { name: 'Reload file' }));
		await waitFor(() => expect(screen.getByRole('button', { name: 'Discard changes' })).toBeDisabled());
		expect(await fileText()).toBe(text);
		expect(saved).toHaveBeenCalledTimes(1);
		expect(requests().filter((r) => r.method === 'GET')).toHaveLength(2);
	});

	it('keeps edits made while a save is in flight and sends the new hash next time', async () => {
		mockBootstrap({ screen: 'fields' });
		let finish: (v: unknown) => void = () => {};
		const hashes: unknown[] = [];
		const saved = jest.fn((body: unknown) => {
			hashes.push((body as { hash: string }).hash);
			return hashes.length === 1
				? new Promise((resolve) => {
						finish = resolve;
					})
				: { path: 'proto-fields/events/group.jsonc', hash: 'hash-3', created: false };
		});
		mockApi({ 'admin/fields/group_events': detail(), 'POST admin/schema-files/field-group/events': saved });
		render(<Builder group="group_events" onBack={() => {}} />);
		await userEvent.click(await screen.findByRole('button', { name: 'Field 1 of 1, Colour, Select' }));
		await userEvent.click(screen.getByRole('button', { name: 'Save to file' }));
		await userEvent.type(screen.getByRole('textbox', { name: 'Label' }), 's');
		// The row shows the draft: the edit is in it while the save is still in flight.
		expect(await screen.findByRole('button', { name: 'Field 1 of 1, Colours, Select' })).toBeInTheDocument();
		finish({ path: 'proto-fields/events/group.jsonc', hash: 'hash-2', created: false });
		await waitFor(() => expect(screen.getByRole('button', { name: 'Save to file' })).toBeEnabled());
		expect(await fileText()).toContain('"Colours"');
		await userEvent.click(screen.getByRole('button', { name: 'Save to file' }));
		await waitFor(() => expect(hashes).toEqual(['hash-1', 'hash-2']));
		expect(parse(requests().filter((r) => r.method === 'POST')[1].data.text).fields[0].label).toBe('Colours');
	});

	it('refuses a new group file name outside the slug grammar', async () => {
		mockBootstrap({ screen: 'fields' });
		mockApi({});
		render(<Builder group={null} onBack={() => {}} />);
		await userEvent.type(screen.getByRole('textbox', { name: 'Title' }), 'New field group');
		const slug = screen.getByRole('textbox', { name: 'File name' });
		expect(slug).toHaveValue('new-field-group');
		await userEvent.clear(slug);
		await userEvent.type(slug, 'Bad Slug');
		await userEvent.click(screen.getByRole('button', { name: 'Create field group' }));
		expect(slug).toHaveFocus();
		expect(slug).toHaveAttribute('aria-invalid', 'true');
		expect(requests()).toHaveLength(0);
	});

	it('adds a field from the menu and selects it', async () => {
		mockBootstrap({ screen: 'fields' });
		mockApi({ 'admin/fields/group_events': detail() });
		render(<Builder group="group_events" onBack={() => {}} />);
		await screen.findByRole('textbox', { name: 'Group title' });
		await userEvent.click(screen.getAllByRole('button', { name: 'Add field' })[0]);
		await userEvent.click(within(screen.getByRole('dialog', { name: 'Add field' })).getByRole('button', { name: /^Number/ }));
		expect(await screen.findByRole('region', { name: 'Field settings: New field' })).toBeInTheDocument();
		expect(screen.getByRole('button', { name: 'Field 2 of 2, New field, Number' })).toHaveAttribute('aria-expanded', 'true');
		expect(screen.getByRole('textbox', { name: 'Label' })).toHaveFocus();
		const written = parse(await fileText());
		expect(written.fields[1]).toMatchObject({ label: 'New field', name: 'new_field', type: 'number' });
	});
});
