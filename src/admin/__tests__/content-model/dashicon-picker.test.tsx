import { mockBootstrap } from '../helpers';
import { useState } from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MenuIconField } from '@/admin/components/content-model/MenuIconField';
import { DASHICONS } from '@/admin/content-model/dashicons';

beforeEach(() => {
	mockBootstrap({ fileModsConstant: 'DISALLOW_FILE_MODS', fileModsAllowed: false });
});

function Harness({ initial }: { initial?: string }) {
	const [value, setValue] = useState<string | undefined>(initial);
	return <MenuIconField id="icon" value={value} onChange={setValue} />;
}

const cells = () => document.querySelectorAll('[data-icon]');

test('the generated list is complete and well formed', () => {
	expect(DASHICONS.length).toBeGreaterThanOrEqual(300);
	for (const name of DASHICONS) {
		expect(name).toMatch(/^dashicons-[a-z0-9-]+$/);
	}
	expect(new Set(DASHICONS).size).toBe(DASHICONS.length);
	expect(DASHICONS).not.toContain('dashicons-before');
});

test('opening the picker renders every icon, search filters, click selects and closes', async () => {
	const user = userEvent.setup();
	render(<Harness initial="dashicons-cart" />);

	await user.click(screen.getByRole('button', { name: 'Choose icon' }));
	expect(cells()).toHaveLength(DASHICONS.length);
	expect(screen.getByRole('button', { name: 'dashicons-cart' })).toHaveAttribute('aria-pressed', 'true');

	await user.type(screen.getByRole('searchbox', { name: 'Search icons' }), 'calendar');
	expect(cells().length).toBeGreaterThan(0);
	expect(cells().length).toBeLessThan(DASHICONS.length);

	await user.click(screen.getByRole('button', { name: 'dashicons-calendar-alt' }));
	expect(screen.getByRole('textbox', { name: /Menu icon/ })).toHaveValue('dashicons-calendar-alt');
	expect(cells()).toHaveLength(0);
	expect(document.querySelector('.dashicons-calendar-alt')).not.toBeNull();
});

test('keyboard: arrows move, Enter selects, Escape closes and returns focus', async () => {
	const user = userEvent.setup();
	render(<Harness />);
	const trigger = screen.getByRole('button', { name: 'Choose icon' });

	await user.click(trigger);
	await user.keyboard('{ArrowDown}');
	const first = document.activeElement as HTMLElement;
	expect(first.getAttribute('data-icon')).toBe(cells()[0].getAttribute('data-icon'));

	await user.keyboard('{ArrowRight}');
	expect((document.activeElement as HTMLElement).getAttribute('data-icon')).toBe(cells()[1].getAttribute('data-icon'));
	await user.keyboard('{ArrowDown}');
	expect((document.activeElement as HTMLElement).getAttribute('data-icon')).toBe(cells()[9].getAttribute('data-icon'));

	const target = (document.activeElement as HTMLElement).getAttribute('data-icon');
	await user.keyboard('{Enter}');
	expect(screen.getByRole('textbox', { name: /Menu icon/ })).toHaveValue(target);
	expect(cells()).toHaveLength(0);

	await user.click(trigger);
	await user.keyboard('{Escape}');
	expect(cells()).toHaveLength(0);
	expect(trigger).toHaveFocus();
});

test('the text input still accepts a theme path', async () => {
	const user = userEvent.setup();
	render(<Harness />);
	const input = screen.getByRole('textbox', { name: /Menu icon/ });
	await user.type(input, 'assets/icon.svg');
	expect(input).toHaveValue('assets/icon.svg');
});
