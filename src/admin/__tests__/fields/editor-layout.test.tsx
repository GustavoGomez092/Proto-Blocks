import { mockApi, mockBootstrap } from '../helpers';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';
import { readFileSync } from 'fs';
import path from 'path';
import { parse } from 'jsonc-parser';
import { AddFieldMenu, Builder } from '@/admin/fields/builder';

const TEXT = readFileSync(path.join(__dirname, '../../../../tests/fixtures/fields/acf-export-realistic.json'), 'utf8');
const FIXTURE = JSON.parse(TEXT) as Record<string, unknown>[];
const KEY = String(FIXTURE[0].key);
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

function detail() {
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
	};
}

async function open(routes: Record<string, unknown> = {}) {
	mockBootstrap({ screen: 'fields' });
	mockApi({ [`admin/fields/${KEY}`]: detail(), ...routes });
	render(<Builder group={KEY} onBack={() => {}} />);
	await screen.findByRole('textbox', { name: 'Group title' });
}

/** The draft group as written in the file text (the JSONC view), then back to the builder. */
async function writtenGroup(): Promise<Record<string, unknown> & { fields: Record<string, unknown>[] }> {
	await userEvent.click(screen.getByRole('button', { name: 'JSONC' }));
	const text = (screen.getByRole('textbox', { name: 'File text' }) as HTMLTextAreaElement).value;
	await userEvent.click(screen.getByRole('button', { name: 'Builder' }));
	return parse(text)[0];
}

const row = (name: RegExp) => screen.getByRole('button', { name });

describe('Field rows: inline accordion', () => {
	it('shows the header meta, the order column and the row markers', async () => {
		await open();
		expect(screen.getByText('proto-fields/acf-export/group.json')).toBeInTheDocument();
		expect(screen.getByText('Proto-Blocks file')).toBeInTheDocument();
		// Every field at every level except the 3 tabs.
		expect(screen.getByText('21 fields')).toBeInTheDocument();
		expect(screen.getByText('21 fields · 3 tabs · 1 repeater')).toBeInTheDocument();
		expect(row(/, Start date, Date, required$/)).toHaveTextContent('required');
		expect(row(/, Stream URL, URL$/)).toHaveTextContent('shown conditionally');
		expect(row(/, Basics, Tab$/)).toHaveAttribute('aria-expanded', 'false');
	});

	it('opens a row inline, switches tabs, and closes from the row, the Close button and Close field', async () => {
		await open();
		const subtitle = row(/, Subtitle, Text$/);
		await userEvent.click(subtitle);
		expect(subtitle).toHaveAttribute('aria-expanded', 'true');
		const panel = screen.getByRole('region', { name: 'Field settings: Subtitle' });
		expect(subtitle).toHaveAttribute('aria-controls', panel.id);
		expect(within(panel).getByRole('tab', { name: 'General' })).toHaveAttribute('aria-selected', 'true');
		expect(within(panel).getByRole('textbox', { name: 'Field name' })).toHaveValue('subtitle');
		expect(within(panel).getByText("get_field('subtitle')")).toBeInTheDocument();
		expect(within(panel).queryByRole('switch', { name: 'Required' })).toBeNull();

		await userEvent.click(within(panel).getByRole('tab', { name: 'Validation' }));
		expect(within(panel).getByRole('tab', { name: 'Validation' })).toHaveAttribute('aria-selected', 'true');
		expect(within(panel).getByRole('switch', { name: 'Required' })).toBeInTheDocument();
		expect(within(panel).getByRole('textbox', { name: 'Pattern' })).toBeInTheDocument();
		expect(within(panel).queryByRole('textbox', { name: 'Field name' })).toBeNull();

		await userEvent.click(within(panel).getByRole('tab', { name: 'Presentation' }));
		expect(within(panel).getByRole('radiogroup', { name: 'Width' })).toBeInTheDocument();
		expect(within(panel).getByRole('textbox', { name: 'CSS class' })).toBeInTheDocument();

		// One open row at a time.
		await userEvent.click(row(/, Summary, Text area$/));
		expect(screen.queryByRole('region', { name: 'Field settings: Subtitle' })).toBeNull();
		expect(screen.getByRole('region', { name: 'Field settings: Summary' })).toBeInTheDocument();
		await userEvent.click(screen.getByRole('button', { name: 'Close Summary' }));
		expect(screen.queryByRole('region', { name: /^Field settings/ })).toBeNull();

		// Keyboard: Enter on the row opens it, Close field closes it and focus goes back to the row.
		const summary = row(/, Summary, Text area$/);
		summary.focus();
		await userEvent.keyboard('{Enter}');
		expect(summary).toHaveAttribute('aria-expanded', 'true');
		await userEvent.click(screen.getByRole('button', { name: 'Close field' }));
		expect(summary).toHaveAttribute('aria-expanded', 'false');
		await waitFor(() => expect(summary).toHaveFocus());
	});

	it('writes settings from every tab through to the file text', async () => {
		await open();
		await userEvent.click(row(/, Subtitle, Text$/));
		const panel = screen.getByRole('region', { name: 'Field settings: Subtitle' });
		await userEvent.type(within(panel).getByRole('textbox', { name: 'Instructions' }), 'Shown under the title.');
		await userEvent.click(within(panel).getByRole('tab', { name: 'Validation' }));
		await userEvent.click(within(panel).getByRole('switch', { name: 'Required' }));
		await userEvent.click(within(panel).getByRole('tab', { name: 'Presentation' }));
		await userEvent.click(within(panel).getByRole('radio', { name: '50%' }));
		await waitFor(() => expect(screen.getByRole('button', { name: 'Discard changes' })).toBeEnabled());
		await waitFor(() => expect(row(/, Subtitle, Text, required$/)).toBeInTheDocument());

		const field = (await writtenGroup()).fields[1];
		expect(field).toMatchObject({ name: 'subtitle', instructions: 'Shown under the title.', required: 1 });
		expect(field.wrapper).toEqual({ width: '50', class: '', id: '' });
	});

	it('counts the rules on the conditional logic tab', async () => {
		await open();
		await userEvent.click(row(/, Stream URL, URL$/));
		const panel = screen.getByRole('region', { name: 'Field settings: Stream URL' });
		const tab = within(panel).getByRole('tab', { name: /Conditional logic/ });
		expect(tab).toHaveTextContent('1');
		await userEvent.click(tab);
		expect(within(panel).getByRole('switch', { name: 'Conditional logic' })).toBeChecked();
		expect(within(panel).getByRole('group', { name: 'Rule group 1' })).toBeInTheDocument();
	});
});

describe('Type picker', () => {
	it('finds a type by its ACF name, moves between tiles with the arrows and adds with Enter', async () => {
		const onPick = jest.fn();
		render(<AddFieldMenu open onOpenChange={() => {}} onPick={onPick} target="Adds after Subtitle in Basics" />);
		const dialog = screen.getByRole('dialog', { name: 'Add field' });
		expect(dialog).toHaveTextContent('Adds after Subtitle in Basics');
		const search = within(dialog).getByRole('searchbox', { name: 'Search field types' });
		expect(search).toHaveFocus();

		await userEvent.type(search, 'color_picker');
		expect(within(dialog).getAllByRole('button').map((b) => b.textContent)).toEqual(['ColourHex picker']);
		await userEvent.keyboard('{Enter}');
		expect(onPick).toHaveBeenLastCalledWith('color');

		fireEvent.change(search, { target: { value: '' } });
		search.focus();
		await userEvent.keyboard('{ArrowDown}');
		expect(within(dialog).getByRole('button', { name: 'Text Single line' })).toHaveFocus();
		await userEvent.keyboard('{ArrowRight}');
		expect(within(dialog).getByRole('button', { name: 'Text area Multiple lines' })).toHaveFocus();
		await userEvent.keyboard('{ArrowDown}');
		expect(within(dialog).getByRole('button', { name: 'Password Masked input' })).toHaveFocus();
		await userEvent.keyboard('{Enter}');
		expect(onPick).toHaveBeenLastCalledWith('password');
	});

	it('adds after the open field in its tab, closes on Escape and returns focus', async () => {
		await open();
		await userEvent.click(row(/, Subtitle, Text$/));
		const add = screen.getAllByRole('button', { name: 'Add field' })[0];
		await userEvent.click(add);
		const dialog = screen.getByRole('dialog', { name: 'Add field' });
		expect(dialog).toHaveTextContent('Adds after Subtitle in Basics');
		await userEvent.keyboard('{Escape}');
		expect(screen.queryByRole('dialog')).toBeNull();
		await waitFor(() => expect(add).toHaveFocus());

		await userEvent.click(add);
		await userEvent.click(within(screen.getByRole('dialog', { name: 'Add field' })).getByRole('button', { name: /^Number/ }));
		expect(await screen.findByRole('region', { name: 'Field settings: New field' })).toBeInTheDocument();
		const fields = (await writtenGroup()).fields;
		expect(fields[1].name).toBe('subtitle');
		expect(fields[2]).toMatchObject({ label: 'New field', type: 'number' });
	});

	it('without an open field adds at the end of the list', async () => {
		await open();
		await userEvent.click(screen.getAllByRole('button', { name: 'Add field' })[1]);
		expect(screen.getByRole('dialog', { name: 'Add field' })).toHaveTextContent('Adds after Sections in Program');
	});
});

describe('Group settings card', () => {
	it('switches panes from the nav and shows the location, presentation and file details', async () => {
		await open();
		const nav = screen.getByRole('navigation', { name: 'Group settings' });
		const location = within(nav).getByRole('button', { name: /Location rules/ });
		expect(location).toHaveAttribute('aria-current', 'true');
		expect(location).toHaveTextContent('1');
		expect(screen.getByRole('heading', { name: 'Show this field group when…' })).toBeInTheDocument();
		expect(screen.getByText(/Currently shows on the/)).toHaveTextContent('Currently shows on the Events edit screen — open one to preview.');
		expect(screen.getByRole('link', { name: 'open one' })).toHaveAttribute('href', 'edit.php?post_type=event');

		await userEvent.click(within(nav).getByRole('button', { name: 'Presentation' }));
		expect(within(nav).getByRole('button', { name: 'Presentation' })).toHaveAttribute('aria-current', 'true');
		expect(location).not.toHaveAttribute('aria-current');
		await userEvent.click(screen.getByRole('radio', { name: 'After title' }));
		await userEvent.click(screen.getByRole('radio', { name: 'Seamless' }));
		const group = await writtenGroup();
		expect(group).toMatchObject({ position: 'acf_after_title', style: 'seamless' });

		await userEvent.click(within(screen.getByRole('navigation', { name: 'Group settings' })).getByRole('button', { name: 'File & key' }));
		expect(screen.getByText(KEY)).toBeInTheDocument();
		expect(screen.getByText('ACF export (list root) — other groups in this file are kept on save')).toBeInTheDocument();
		expect(screen.getByRole('button', { name: 'Delete field group…' })).toBeInTheDocument();
		await userEvent.click(screen.getByRole('button', { name: 'Open JSONC view' }));
		expect(screen.getByRole('textbox', { name: 'File text' })).toBeInTheDocument();
		expect(screen.getByRole('button', { name: 'JSONC' })).toHaveAttribute('aria-pressed', 'true');
	});

	it('opens the delete confirmation for the group from File & key', async () => {
		await open({ 'admin/schema-files/field-group/acf-export': { exists: true, path: 'proto-fields/acf-export/group.json', text: TEXT, hash: 'hash-acf', data: {}, writable: true, reason: null } });
		await userEvent.click(within(screen.getByRole('navigation', { name: 'Group settings' })).getByRole('button', { name: 'File & key' }));
		await userEvent.click(screen.getByRole('button', { name: 'Delete field group…' }));
		expect(await screen.findByRole('alertdialog', { name: 'Delete the Event details field group?' })).toBeInTheDocument();
	});
});

describe('Header', () => {
	it('edits the title inline and saves with Cmd+S', async () => {
		const saved = jest.fn(() => ({ path: 'proto-fields/acf-export/group.json', hash: 'hash-2', created: false }));
		await open({ 'POST admin/schema-files/field-group/acf-export': saved });
		const title = screen.getByRole('textbox', { name: 'Group title' });
		await userEvent.clear(title);
		await userEvent.type(title, 'Talks');
		expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Talks');
		await userEvent.keyboard('{Meta>}s{/Meta}');
		await waitFor(() => expect(saved).toHaveBeenCalledTimes(1));
		const body = (apiFetch as unknown as jest.Mock).mock.calls.find(([o]) => o.method === 'POST')?.[0].data as { text: string };
		expect(parse(body.text)[0].title).toBe('Talks');
	});
});
