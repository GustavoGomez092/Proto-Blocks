import { useState } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { PlacementBuilder } from '@/admin/scripts/PlacementBuilder';
import { normalizeWhere } from '@/admin/scripts/edits';
import type { Where } from '@/admin/scripts/types';

const ROLES = [
	{ slug: 'administrator', name: 'Administrator' },
	{ slug: 'editor', name: 'Editor' },
];

let last: Where | null = null;

function Harness({ initial, readOnly = false }: { initial: Where; readOnly?: boolean }) {
	const [where, setWhere] = useState(initial);
	return (
		<PlacementBuilder
			where={where}
			roles={ROLES}
			readOnly={readOnly}
			onChange={(next) => {
				last = next;
				setWhere(next);
			}}
		/>
	);
}

afterEach(() => {
	last = null;
	document.body.innerHTML = '';
});

describe('placement builder', () => {
	test('adds a valued target to include and a simple one to exclude', async () => {
		render(<Harness initial={normalizeWhere({ include: [] })} />);
		expect(screen.getByText('Nowhere: add a target or the script never runs.')).toBeTruthy();

		await userEvent.selectOptions(screen.getByLabelText('Include target kind'), 'page:');
		await userEvent.type(screen.getByLabelText('Include target value'), 'about');
		await userEvent.click(screen.getByRole('button', { name: 'Add to include' }));
		expect(last?.include).toEqual(['page:about']);
		expect(within(screen.getByRole('list', { name: 'Include' })).getByText('page:about')).toBeTruthy();

		await userEvent.selectOptions(screen.getByLabelText('Exclude target kind'), '404');
		expect(screen.queryByLabelText('Exclude target value')).toBeNull();
		await userEvent.click(screen.getByRole('button', { name: 'Add to exclude' }));
		expect(last?.exclude).toEqual(['404']);
	});

	test('a url glob is added with Enter; a bad target shows an error and is not added', async () => {
		render(<Harness initial={normalizeWhere({})} />);
		await userEvent.selectOptions(screen.getByLabelText('Exclude target kind'), 'url:');
		await userEvent.type(screen.getByLabelText('Exclude target value'), 'landing/*');
		await userEvent.click(screen.getByRole('button', { name: 'Add to exclude' }));
		expect(screen.getByRole('alert').textContent).toBe('A URL path starts with "/".');
		expect(last).toBeNull();

		await userEvent.clear(screen.getByLabelText('Exclude target value'));
		await userEvent.type(screen.getByLabelText('Exclude target value'), '/landing/*{enter}');
		expect(last?.exclude).toEqual(['url:/landing/*']);

		await userEvent.selectOptions(screen.getByLabelText('Include target kind'), 'page:');
		await userEvent.type(screen.getByLabelText('Include target value'), 'two words');
		await userEvent.click(screen.getByRole('button', { name: 'Add to include' }));
		expect(screen.getByRole('alert').textContent).toMatch(/not a valid target/);
	});

	test('duplicates are refused and chips can be removed', async () => {
		render(<Harness initial={normalizeWhere({ include: ['all', 'search'] })} />);
		await userEvent.click(screen.getByRole('button', { name: 'Add to include' }));
		expect(screen.getByRole('alert').textContent).toBe('This target is already in the list.');
		await userEvent.click(screen.getByRole('button', { name: 'Remove search' }));
		expect(last?.include).toEqual(['all']);
	});

	test('logged-in radio and role checkboxes', async () => {
		render(<Harness initial={normalizeWhere({})} />);
		expect(screen.getByRole('radio', { name: 'Anyone' }).getAttribute('aria-checked')).toBe('true');
		await userEvent.click(screen.getByRole('radio', { name: 'Logged in only' }));
		expect(last?.loggedIn).toBe(true);
		await userEvent.click(screen.getByRole('radio', { name: 'Anyone' }));
		expect(last?.loggedIn).toBeNull();

		const only = screen.getByRole('group', { name: 'Only these roles (implies logged in)' });
		await userEvent.click(within(only).getByRole('checkbox', { name: 'Editor' }));
		expect(last?.roles).toEqual(['editor']);
		const never = screen.getByRole('group', { name: 'Never for these roles' });
		await userEvent.click(within(never).getByRole('checkbox', { name: 'Administrator' }));
		expect(last?.excludeRoles).toEqual(['administrator']);

		await userEvent.click(screen.getByRole('radio', { name: 'Logged out only' }));
		expect(screen.getByRole('alert').textContent).toMatch(/would never run/);
	});

	test('a role the site no longer has stays visible', () => {
		render(<Harness initial={normalizeWhere({ roles: ['shop_manager'] })} />);
		expect(screen.getByRole('checkbox', { name: 'shop_manager' }).getAttribute('aria-checked')).toBe('true');
	});

	test('read-only: no add rows or remove buttons, controls disabled', () => {
		render(<Harness readOnly initial={normalizeWhere({ exclude: ['404'] })} />);
		expect(screen.queryByRole('button', { name: /Add to/ })).toBeNull();
		expect(screen.queryByRole('button', { name: 'Remove 404' })).toBeNull();
		expect((screen.getByRole('radio', { name: 'Anyone' }) as HTMLButtonElement).disabled).toBe(true);
		expect((screen.getAllByRole('checkbox')[0] as HTMLButtonElement).disabled).toBe(true);
	});
});
