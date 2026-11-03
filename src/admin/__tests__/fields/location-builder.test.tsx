import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { GroupSettings, LocationBuilder } from '@/admin/fields/builder';

const CHOICES = {
	postTypes: [
		{ slug: 'post', label: 'Posts' },
		{ slug: 'event', label: 'Events' },
	],
	taxonomies: [
		{ slug: 'category', label: 'Categories' },
		{ slug: 'topic', label: 'Topics' },
	],
	roles: [{ slug: 'editor', label: 'Editor' }],
	optionsPages: [{ slug: 'theme-settings', label: 'Theme settings' }],
	pageTemplates: [],
};

function values(select: HTMLElement): string[] {
	return within(select)
		.getAllByRole('option')
		.map((o) => (o as HTMLOptionElement).value);
}

describe('LocationBuilder', () => {
	it('locks an unsupported rule with the exact text and lets it be removed', async () => {
		const onChange = jest.fn();
		render(<LocationBuilder value={[[{ param: 'post_format', operator: '==', value: 'aside' }]]} choices={CHOICES} onChange={onChange} />);
		expect(screen.getByText('Not supported by Proto-Blocks: post_format. This group will not be shown.')).toBeInTheDocument();
		expect(screen.queryByRole('combobox', { name: 'Rule' })).toBeNull();
		await userEvent.click(screen.getByRole('button', { name: 'Remove unsupported rule' }));
		expect(onChange).toHaveBeenLastCalledWith([]);
	});

	it('offers the nine supported params and swaps the value list with the param', async () => {
		const onChange = jest.fn();
		const { rerender } = render(<LocationBuilder value={[[{ param: 'post_type', operator: '==', value: 'event' }]]} choices={CHOICES} onChange={onChange} />);
		expect(values(screen.getByRole('combobox', { name: 'Rule' }))).toEqual([
			'post_type',
			'post_status',
			'post_taxonomy',
			'page_template',
			'post',
			'taxonomy',
			'user_role',
			'user_form',
			'options_page',
		]);
		expect(values(screen.getByRole('combobox', { name: 'Value' }))).toEqual(['post', 'event']);
		expect(values(screen.getByRole('combobox', { name: 'Operator' }))).toEqual(['==', '!=']);

		await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Rule' }), 'taxonomy');
		expect(onChange).toHaveBeenLastCalledWith([[{ param: 'taxonomy', operator: '==', value: 'all' }]]);
		rerender(<LocationBuilder value={onChange.mock.lastCall[0]} choices={CHOICES} onChange={onChange} />);
		expect(values(screen.getByRole('combobox', { name: 'Value' }))).toEqual(['all', 'category', 'topic']);

		await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Rule' }), 'user_form');
		rerender(<LocationBuilder value={onChange.mock.lastCall[0]} choices={CHOICES} onChange={onChange} />);
		expect(values(screen.getByRole('combobox', { name: 'Value' }))).toEqual(['all', 'add', 'edit', 'register']);
	});

	it('writes post_taxonomy values as plain "taxonomy:term" strings', async () => {
		const onChange = jest.fn();
		render(<LocationBuilder value={[[{ param: 'post_taxonomy', operator: '==', value: 'category:news' }]]} choices={CHOICES} onChange={onChange} />);
		expect(screen.getByRole('combobox', { name: 'Taxonomy' })).toHaveValue('category');
		const term = screen.getByRole('textbox', { name: 'Term slug' });
		expect(term).toHaveValue('news');
		await userEvent.type(term, 's');
		expect(onChange).toHaveBeenLastCalledWith([[{ param: 'post_taxonomy', operator: '==', value: 'category:newss' }]]);
	});

	it('adds a first rule and flags a group that mixes object kinds', async () => {
		const onChange = jest.fn();
		const { rerender } = render(<LocationBuilder value={[]} choices={CHOICES} onChange={onChange} />);
		await userEvent.click(screen.getByRole('button', { name: 'Add rule' }));
		expect(onChange).toHaveBeenLastCalledWith([[{ param: 'post_type', operator: '==', value: 'post' }]]);
		rerender(
			<LocationBuilder
				value={[
					[
						{ param: 'post_type', operator: '==', value: 'post' },
						{ param: 'user_role', operator: '==', value: 'all' },
					],
				]}
				choices={CHOICES}
				onChange={onChange}
			/>
		);
		expect(screen.getByText('Rules for different screens in one group never match together. Put them in separate "or" groups.')).toBeInTheDocument();
	});

	it('shows the implied location instead of rows', () => {
		render(<LocationBuilder value={[]} choices={CHOICES} onChange={jest.fn()} implied="Events" />);
		expect(screen.getByText('Shown on Events — set by the file it lives in')).toBeInTheDocument();
		expect(screen.queryByRole('combobox')).toBeNull();
		expect(screen.queryByRole('button', { name: 'Add rule' })).toBeNull();
	});
});

describe('GroupSettings', () => {
	it('writes position, style, menu order and the options autoload switch', async () => {
		const dispatch = jest.fn();
		const group = {
			key: 'group_x',
			title: 'X',
			fields: [],
			location: [[{ param: 'options_page', operator: '==', value: 'theme-settings' }]],
		};
		render(<GroupSettings group={group} dispatch={dispatch} />);
		await userEvent.click(screen.getByRole('radio', { name: 'Sidebar' }));
		expect(dispatch).toHaveBeenLastCalledWith({
			type: 'group',
			patch: { position: 'side' },
		});
		await userEvent.click(screen.getByRole('radio', { name: 'Seamless' }));
		expect(dispatch).toHaveBeenLastCalledWith({
			type: 'group',
			patch: { style: 'seamless' },
		});
		await userEvent.type(screen.getByRole('spinbutton', { name: 'Order' }), '3');
		expect(dispatch).toHaveBeenLastCalledWith({
			type: 'group',
			patch: { menu_order: 3 },
		});
		await userEvent.click(screen.getByRole('switch', { name: 'Autoload options' }));
		expect(dispatch).toHaveBeenLastCalledWith({
			type: 'group',
			patch: { autoload: false },
		});
	});

	it('hides the autoload switch outside options pages', () => {
		render(<GroupSettings group={{ key: 'g', title: 'G', location: [] }} dispatch={jest.fn()} />);
		expect(screen.queryByRole('switch', { name: 'Autoload options' })).toBeNull();
	});
});
