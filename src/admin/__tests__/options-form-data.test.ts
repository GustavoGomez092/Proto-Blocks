import { submissionFromForm } from '@/admin/options-pages/formData';

function form(entries: [string, string][]): HTMLFormElement {
	const el = document.createElement('form');
	for (const [name, value] of entries) {
		const input = document.createElement('input');
		input.type = 'hidden';
		input.name = name;
		input.value = value;
		el.appendChild(input);
	}
	return el;
}

test('nests rows, keeps the last scalar and collects present names', () => {
	const f = form([
		['proto_fields[g][agenda][0][title]', 'A'],
		['proto_fields[g][agenda][1][title]', 'B'],
		['proto_fields[g][on]', '0'],
		['proto_fields[g][on]', '1'],
		['proto_fields_present[g][]', 'agenda'],
		['proto_fields_present[g][]', 'on'],
	]);

	expect(submissionFromForm(f)).toEqual({
		fields: { g: { agenda: [{ title: 'A' }, { title: 'B' }], on: '1' } },
		present: { g: ['agenda', 'on'] },
	});
});

test('collects [] lists, keeps an empty marker and ignores other names', () => {
	const f = form([
		['proto_fields[g][tags][]', 'a'],
		['proto_fields[g][tags][]', 'b'],
		['proto_fields[g][none]', ''],
		['proto_fields[h][x]', '1'],
		['_wpnonce', 'abc'],
		['proto_fields[__proto__][polluted]', '1'],
		['proto_fields[g][__proto__][polluted]', '1'],
	]);

	const out = submissionFromForm(f);
	expect(out.fields).toEqual({ g: { tags: ['a', 'b'], none: '' }, h: { x: '1' } });
	expect(out.present).toEqual({});
	expect(({} as Record<string, unknown>).polluted).toBeUndefined();
});

test('a later row index fills an array in order', () => {
	const f = form([
		['proto_fields[g][rows][0][a]', '1'],
		['proto_fields[g][rows][0][b]', '2'],
		['proto_fields[g][rows][1][a]', '3'],
	]);

	expect(submissionFromForm(f).fields.g.rows).toEqual([{ a: '1', b: '2' }, { a: '3' }]);
});
