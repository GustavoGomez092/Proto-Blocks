import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { FieldTree } from '@/admin/fields/builder';
import type { RawField } from '@/admin/screens/fields/builder-state';

const fields: RawField[] = [
	{ key: 'field_a', label: 'Start date', name: 'start_date', type: 'date_picker', required: 1 },
	{ key: 'field_b', label: 'Venue', name: 'venue', type: 'text' },
	{
		key: 'field_f',
		label: 'Agenda',
		name: 'agenda',
		type: 'repeater',
		sub_fields: [
			{ key: 'field_g', label: 'Title', name: 'title', type: 'text' },
			{ key: 'field_h', label: 'Speaker', name: 'speaker', type: 'text' },
		],
	},
	{
		key: 'field_x',
		label: 'Sections',
		name: 'sections',
		type: 'flexible_content',
		layouts: { layout_q: { key: 'layout_q', name: 'quote', label: 'Quote', sub_fields: [{ key: 'field_q', label: 'Text', name: 'text', type: 'textarea' }] } },
	},
];

function setup(overrides: Partial<Parameters<typeof FieldTree>[0]> = {}) {
	const dispatch = jest.fn();
	const onSelect = jest.fn();
	const onAddField = jest.fn();
	render(<FieldTree fields={fields} selectedKey={null} onSelect={onSelect} dispatch={dispatch} onAddField={onAddField} {...overrides} />);
	return { dispatch, onSelect, onAddField };
}

describe('FieldTree', () => {
	it('shows label, name, type and the required marker per row', () => {
		setup();
		const row = screen.getByRole('button', { name: /^Start date/ });
		expect(row).toHaveTextContent('start_date');
		expect(row).toHaveTextContent('Date');
		expect(row).toHaveTextContent('required');
	});

	it('reorders with the keyboard: Space lifts, ArrowDown moves, Space drops', async () => {
		const { dispatch } = setup();
		const handle = screen.getByRole('button', { name: 'Reorder Start date' });
		handle.focus();
		await userEvent.keyboard(' ');
		expect(screen.getByText(/^Picked up Start date\./)).toBeInTheDocument();
		await userEvent.keyboard('{ArrowDown}');
		expect(dispatch).toHaveBeenCalledWith({ type: 'move', from: [0], to: [1] });
		await userEvent.keyboard(' ');
		expect(screen.getByText('Dropped Start date.')).toBeInTheDocument();
		expect(dispatch).toHaveBeenCalledTimes(1);
	});

	it('moves into the previous repeater and out of it with ArrowRight and ArrowLeft', async () => {
		const { dispatch } = setup();
		const sections = screen.getByRole('button', { name: 'Reorder Sections' });
		sections.focus();
		await userEvent.keyboard(' {ArrowRight}');
		expect(dispatch).toHaveBeenLastCalledWith({ type: 'move', from: [3], to: [2, 2] });

		screen.getByRole('button', { name: 'Reorder Speaker' }).focus();
		await userEvent.keyboard(' {ArrowLeft}');
		expect(dispatch).toHaveBeenLastCalledWith({ type: 'move', from: [2, 1], to: [3] });
	});

	it('renders a repeater’s sub-fields in their own list with their own Add field button', async () => {
		const { onAddField, onSelect } = setup();
		const nested = screen.getByRole('list', { name: 'Sub-fields of Agenda' });
		expect(within(nested).getAllByRole('listitem')).toHaveLength(2);
		await userEvent.click(screen.getByRole('button', { name: 'Add field to Agenda' }));
		expect(onAddField).toHaveBeenCalledWith([2], undefined);

		await userEvent.click(within(nested).getByRole('button', { name: /^Speaker/ }));
		expect(onSelect).toHaveBeenCalledWith([2, 1], 'field_h');
	});

	it('renders flexible content layouts with their fields and an Add layout button', async () => {
		const { onAddField, dispatch } = setup();
		const layout = screen.getByRole('list', { name: 'Fields of layout Quote' });
		expect(within(layout).getByRole('button', { name: /^Text/ })).toBeInTheDocument();
		await userEvent.click(screen.getByRole('button', { name: 'Add field to Quote' }));
		expect(onAddField).toHaveBeenCalledWith([3], 0);
		await userEvent.click(screen.getByRole('button', { name: 'Add layout to Sections' }));
		expect(dispatch).toHaveBeenCalledWith({ type: 'addLayout', path: [3] });
	});

	it('collapses a container and offers duplicate and remove per row', async () => {
		const { dispatch } = setup();
		await userEvent.click(screen.getByRole('button', { name: 'Collapse Agenda' }));
		expect(screen.queryByRole('list', { name: 'Sub-fields of Agenda' })).not.toBeInTheDocument();
		await userEvent.click(screen.getByRole('button', { name: 'Duplicate Venue' }));
		expect(dispatch).toHaveBeenCalledWith({ type: 'duplicate', path: [1] });
		await userEvent.click(screen.getByRole('button', { name: 'Remove Venue' }));
		expect(dispatch).toHaveBeenCalledWith({ type: 'remove', path: [1] });
	});

	it('is inert when read-only', () => {
		setup({ readOnly: true });
		expect(screen.queryByRole('button', { name: 'Reorder Start date' })).not.toBeInTheDocument();
		expect(screen.queryByRole('button', { name: /^Add field/ })).not.toBeInTheDocument();
	});
});
