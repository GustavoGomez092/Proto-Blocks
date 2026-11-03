import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { readFileSync } from 'fs';
import path from 'path';
import { ConditionBuilder, FieldSettings } from '@/admin/fields/builder';
import type { ConditionRule } from '@/fields/model/types';
import type { RawField } from '@/admin/screens/fields/builder-state';

const EXPORT = JSON.parse(readFileSync(path.join(__dirname, '../../../../tests/fixtures/fields/acf-export-realistic.json'), 'utf8')) as {
	fields: RawField[];
}[];
const FIELDS = EXPORT[0].fields;
const STREAM = FIELDS.find((f) => f.name === 'stream_url') as RawField;
const ONLINE = FIELDS.find((f) => f.name === 'is_online') as RawField;

beforeAll(() => {
	if (!('ResizeObserver' in window)) {
		(window as unknown as { ResizeObserver: unknown }).ResizeObserver = class {
			observe() {}
			unobserve() {}
			disconnect() {}
		};
	}
});

function options(select: HTMLElement): string[] {
	return within(select)
		.getAllByRole('option')
		.map((o) => o.textContent ?? '');
}

describe('ConditionBuilder', () => {
	it('builds OR groups of AND rules over the same-level fields and writes 0 when every rule is removed', async () => {
		const onChange = jest.fn();
		const view = (value: ConditionRule[][] | 0) => <ConditionBuilder fields={FIELDS} selfKey={String(STREAM.key)} value={value} onChange={onChange} />;
		const { rerender } = render(view(0));

		await userEvent.click(screen.getByRole('button', { name: 'Add rule' }));
		rerender(view(onChange.mock.lastCall[0]));

		const field = screen.getByRole('combobox', { name: 'Field' });
		expect(options(field)).toContain('Online event');
		expect(options(field)).not.toContain('Stream URL');
		// Layout types and containers never control a condition.
		expect(options(field)).not.toContain('Basics');
		expect(options(field)).not.toContain('Agenda');

		await userEvent.selectOptions(field, String(ONLINE.key));
		const one = onChange.mock.lastCall[0];
		expect(one).toEqual([[{ field: ONLINE.key, operator: '==', value: '1' }]]);
		rerender(view(one));
		expect(options(screen.getByRole('combobox', { name: 'Operator' }))).toEqual(['is', 'is not']);
		expect(screen.getByRole('switch', { name: 'Value' })).toBeChecked();

		await userEvent.click(screen.getByRole('button', { name: 'or, another rule group' }));
		const two = onChange.mock.lastCall[0];
		expect(two).toHaveLength(2);
		rerender(view(two));
		expect(screen.getAllByRole('group', { name: /^Rule group/ })).toHaveLength(2);

		await userEvent.click(screen.getByRole('button', { name: 'Remove all rules' }));
		expect(onChange).toHaveBeenLastCalledWith(0);
	});

	it('offers ==, !=, ==empty and !=empty with a choice select for a select field', () => {
		const audience = FIELDS.find((f) => f.name === 'audience') as RawField;
		render(
			<ConditionBuilder
				fields={FIELDS}
				selfKey={String(STREAM.key)}
				value={[[{ field: String(audience.key), operator: '==', value: 'x' }]]}
				onChange={jest.fn()}
			/>
		);
		expect(options(screen.getByRole('combobox', { name: 'Operator' }))).toEqual(['is', 'is not', 'is empty', 'is not empty']);
		expect(screen.getByRole('combobox', { name: 'Value' })).toBeInTheDocument();
	});
});

describe('FieldSettings conditional logic tab', () => {
	it('mounts the ConditionBuilder in its own tab and writes conditional_logic', async () => {
		const onChange = jest.fn();
		render(<FieldSettings field={STREAM} siblings={FIELDS.filter((f) => f !== STREAM)} onChange={onChange} />);
		expect(screen.queryByRole('button', { name: 'Remove all rules' })).toBeNull();
		await userEvent.click(screen.getByRole('tab', { name: /Conditional logic/ }));
		await userEvent.click(screen.getByRole('button', { name: 'Remove all rules' }));
		await waitFor(() => expect(onChange).toHaveBeenLastCalledWith({ conditional_logic: 0 }));
	});
});
