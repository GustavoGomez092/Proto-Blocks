import { parse } from 'jsonc-parser';
import {
	newFieldKey,
	parseErrorPath,
	reduce,
	reduceWithNotices,
	slugifyName,
	toFileText,
	validateGroup,
	type RawGroup,
} from '@/admin/screens/fields/builder-state';

function group(): RawGroup {
	return {
		key: 'group_events',
		title: 'Event details',
		fields: [
			{ key: 'field_a', label: 'Start date', name: 'start_date', type: 'date_picker', wrapper: { width: '50', class: '', id: '' }, 'aria-label': '' },
			{ key: 'field_b', label: 'Online', name: 'is_online', type: 'true_false' },
			{ key: 'field_c', label: 'Stream URL', name: 'stream_url', type: 'url', conditional_logic: [[{ field: 'field_b', operator: '==', value: '1' }]] },
			{ key: 'field_d', label: 'Venue', name: 'venue', type: 'text' },
			{ key: 'field_e', label: 'Colour', name: 'colour', type: 'select', choices: {} },
			{ key: 'field_f', label: 'Agenda', name: 'agenda', type: 'repeater', sub_fields: [{ key: 'field_g', label: 'Title', name: 'title', type: 'text' }] },
		],
		location: [[{ param: 'post_type', operator: '==', value: 'event' }]],
		menu_order: 0,
		hide_on_screen: '',
	};
}

const names = (g: RawGroup) => (g.fields as { name: string }[]).map((f) => f.name);

describe('reduce', () => {
	it('adds a text field at the root with a unique field_ key and a name from the label', () => {
		const next = reduce(group(), { type: 'add', parent: null, fieldType: 'text', label: 'Start date' });
		const added = (next.fields as Record<string, unknown>[])[6];
		expect(added.key).toMatch(/^field_[0-9a-f]{13}$/);
		expect(added.type).toBe('text');
		expect(added.label).toBe('Start date');
		// start_date is taken at this level.
		expect(added.name).toBe('start_date_2');
		expect(newFieldKey()).not.toBe(newFieldKey());
	});

	it('moves a field into a repeater and back', () => {
		const into = reduce(group(), { type: 'move', from: [2], to: [5, 0] });
		expect(names(into)).toEqual(['start_date', 'is_online', 'venue', 'colour', 'agenda']);
		const agenda = (into.fields as Record<string, unknown>[])[4];
		expect((agenda.sub_fields as { name: string }[]).map((f) => f.name)).toEqual(['stream_url', 'title']);

		const back = reduce(into, { type: 'move', from: [4, 0], to: [2] });
		expect(back).toEqual(group());
	});

	it('reorders within a list to the final index', () => {
		expect(names(reduce(group(), { type: 'move', from: [0], to: [1] }))).toEqual(['is_online', 'start_date', 'stream_url', 'venue', 'colour', 'agenda']);
	});

	it('does nothing when a repeater is moved into its own sub-fields', () => {
		const g = group();
		expect(reduce(g, { type: 'move', from: [5], to: [5, 0] })).toBe(g);
	});

	it('keeps ACF-only keys on update', () => {
		const next = reduce(group(), { type: 'update', path: [0], patch: { label: 'Starts' } });
		expect((next.fields as Record<string, unknown>[])[0]).toEqual({
			key: 'field_a',
			label: 'Starts',
			name: 'start_date',
			type: 'date_picker',
			wrapper: { width: '50', class: '', id: '' },
			'aria-label': '',
		});
	});

	it('duplicates with new keys throughout and a _copy name', () => {
		const next = reduce(group(), { type: 'duplicate', path: [5] });
		const [orig, copy] = (next.fields as Record<string, unknown>[]).slice(5);
		expect(copy.name).toBe('agenda_copy');
		expect(copy.key).toMatch(/^field_[0-9a-f]{13}$/);
		expect(copy.key).not.toBe(orig.key);
		const sub = (copy.sub_fields as Record<string, unknown>[])[0];
		expect(sub.key).not.toBe('field_g');
		expect(sub.name).toBe('title');
	});

	it('removes the rules that read a removed field and reports them', () => {
		const { group: next, notices } = reduceWithNotices(group(), { type: 'remove', path: [1] });
		expect(names(next)).not.toContain('is_online');
		expect((next.fields as Record<string, unknown>[])[1].conditional_logic).toBe(0);
		expect(notices).toEqual([{ key: 'field_c', label: 'Stream URL' }]);
	});

	it('adds, renames and removes flexible content layouts', () => {
		let g: RawGroup = { key: 'g', title: 'G', fields: [] };
		g = reduce(g, { type: 'add', parent: null, fieldType: 'flexible_content', label: 'Sections' });
		g = reduce(g, { type: 'addLayout', path: [0] });
		g = reduce(g, { type: 'updateLayout', path: [0], layout: 1, patch: { label: 'Quote', name: 'quote' } });
		g = reduce(g, { type: 'add', parent: [0], layout: 1, fieldType: 'textarea', label: 'Text' });
		const flex = (g.fields as Record<string, unknown>[])[0];
		const layouts = flex.layouts as Record<string, unknown>[];
		expect(layouts.map((l) => l.name)).toEqual(['layout', 'quote']);
		expect((layouts[1].sub_fields as { name: string }[])[0].name).toBe('text');
		g = reduce(g, { type: 'removeLayout', path: [0], layout: 0 });
		expect(((g.fields as Record<string, unknown>[])[0].layouts as unknown[]).length).toBe(1);
	});
});

describe('helpers', () => {
	it('slugifies labels into valid names', () => {
		expect(slugifyName('Start date')).toBe('start_date');
		expect(slugifyName('Café — Ünïcode!')).toBe('cafe_unicode');
		expect(slugifyName('2nd line')).toBe('field_2nd_line');
		expect(slugifyName('')).toBe('field');
	});

	it('validates names and empty containers like the server', () => {
		const g = reduce(group(), { type: 'update', path: [3], patch: { name: '_x' } });
		const errors = validateGroup(reduce(g, { type: 'add', parent: null, fieldType: 'repeater', label: 'Links' }));
		expect(errors).toEqual([
			{ path: [3], setting: 'name', message: 'Field name "_x" must start with a letter and contain only letters, digits, "_" or "-" (64 characters at most).' },
			{ path: [6], setting: 'sub_fields', message: 'A repeater field needs at least one sub-field.' },
		]);
	});

	it('refuses duplicate layout names', () => {
		const g: RawGroup = {
			key: 'g',
			title: 'G',
			fields: [{ key: 'f', name: 'sections', type: 'flexible_content', layouts: [
				{ key: 'l1', name: 'quote', sub_fields: [] },
				{ key: 'l2', name: 'quote', sub_fields: [] },
			] }],
		};
		expect(validateGroup(g)).toEqual([{ path: [0], setting: 'layouts', message: 'The layout name "quote" is used more than once.' }]);
	});

	it('maps server error paths to field paths', () => {
		expect(parseErrorPath('fields[5].sub_fields[0].name', [])).toEqual({ path: [5, 0], setting: 'name' });
		expect(parseErrorPath('fields[1].fields[0].layouts[2].sub_fields[1].type', ['fields', 1])).toEqual({ path: [0, 'layouts', 2, 1], setting: 'type' });
		expect(parseErrorPath('location', [])).toBeNull();
	});
});

describe('toFileText', () => {
	const original = `{
  "key": "group_events",
  "title": "Event details",
  // The fields, in box order.
  "fields": [
    // When it starts.
    { "key": "field_a", "label": "Start date", "name": "start_date", "type": "date_picker" },
    { "key": "field_b", "label": "Online", "name": "is_online", "type": "true_false" }
  ],
  "location": [] // set later
}
`;

	it('keeps comments when a field is updated', () => {
		const g = parse(original) as RawGroup;
		const next = reduce(g, { type: 'update', path: [1], patch: { label: 'Is online' } });
		const text = toFileText(original, next, []);
		expect(text).toContain('// The fields, in box order.');
		expect(text).toContain('// When it starts.');
		expect(text).toContain('// set later');
		expect(parse(text)).toEqual(next);
	});

	it('keeps comments when a field is added or removed', () => {
		const g = parse(original) as RawGroup;
		const added = reduce(g, { type: 'add', parent: null, fieldType: 'text', label: 'Venue', at: 1 });
		const text = toFileText(original, added, []);
		expect(text).toContain('// The fields, in box order.');
		expect(text).toContain('// When it starts.');
		expect(parse(text)).toEqual(added);

		const removed = reduce(added, { type: 'remove', path: [2] });
		const text2 = toFileText(text, removed, []);
		expect(text2).toContain('// When it starts.');
		expect(parse(text2)).toEqual(removed);
	});

	it('edits the group at its pointer inside a type file', () => {
		const typeFile = `{
  "slug": "talk", // the type
  "fields": [ { "key": "group_x", "title": "X", "fields": [] } ]
}`;
		const g = parse(typeFile).fields[0] as RawGroup;
		const text = toFileText(typeFile, reduce(g, { type: 'group', patch: { title: 'Talk' } }), ['fields', 0]);
		expect(text).toContain('// the type');
		expect(parse(text).fields[0].title).toBe('Talk');
	});
});
