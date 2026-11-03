import { mockApi, mockBootstrap } from './helpers';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { toast } from 'sonner';
import OptionsPageScreen from '@/admin/screens/OptionsPageScreen';
import type { OptionsPageData } from '@/admin/options-pages/types';
import { normalizeField } from '@/fields/model/normalize';
import type { BoxPayload, FieldDef } from '@/fields/model/types';

// jsdom lacks ResizeObserver; Radix's Switch measures itself with it inside a form.
(globalThis as { ResizeObserver?: unknown }).ResizeObserver ??= class {
	observe() {}
	unobserve() {}
	disconnect() {}
};

const CADCO_GROUP = 'group_cadco_display_settings_1';

const fields = (raw: Record<string, unknown>[]): FieldDef[] => raw.map((f) => normalizeField(f) as FieldDef);

function box(groupKey: string, slug: string, defs: FieldDef[], values: Record<string, unknown>, canUnfiltered = true): BoxPayload {
	return {
		groupKey,
		fields: defs,
		values,
		display: {},
		choices: {},
		errors: {},
		canUnfiltered,
		maxRows: {},
		restRoot: 'https://example.test/wp-json/proto-blocks/v1/',
		restNonce: 'nonce-wp_rest',
		object: `option:${slug}`,
	};
}

function cadco(overrides: Partial<OptionsPageData> = {}): OptionsPageData {
	const defs = fields([
		{ key: 'field_cadco_hide_children', name: 'hide_children', label: 'Hide child products', type: 'true_false' },
		{
			key: 'field_cadco_children_placement',
			name: 'children_placement',
			label: 'Child product placement',
			type: 'radio',
			choices: { related: 'Related products', section: 'Own section' },
			default_value: 'related',
		},
		{
			key: 'field_cadco_children_heading',
			name: 'children_heading',
			label: 'Section heading',
			type: 'text',
			conditional_logic: [[{ field: 'field_cadco_children_placement', operator: '==', value: 'section' }]],
		},
	]);
	return {
		slug: 'cadco-display-settings',
		title: 'Display Settings',
		groups: [
			box(CADCO_GROUP, 'cadco-display-settings', defs, {
				hide_children: false,
				children_placement: 'related',
				children_heading: '',
			}),
		],
		tabs: [],
		trusted: false,
		editUrl: null,
		readOnly: {},
		...overrides,
	};
}

function themeSettings(trusted = true): OptionsPageData {
	const slug = 'theme-settings';
	return {
		slug,
		title: 'Theme Settings',
		groups: [],
		tabs: [
			{ title: 'General', groups: [box('group_theme_settings_1', slug, fields([{ name: 'phone', label: 'Phone', type: 'text' }]), { phone: '555' }, trusted)] },
			{
				title: 'Tracking',
				// Mirrors PageRenderer: for an untrusted user the code field is left
				// out of the form, its value is null and it is listed as hidden.
				groups: [
					trusted
						? box(
								'group_theme_settings_2',
								slug,
								fields([{ name: 'head_scripts', label: 'Head scripts', type: 'code' }]),
								{ head_scripts: '<script>track()</script>' },
								trusted
							)
						: {
								...box('group_theme_settings_2', slug, [], { head_scripts: null }, trusted),
								hidden: [{ name: 'head_scripts', label: 'Head scripts', reason: 'unfiltered_html' }],
							},
				],
			},
		],
		trusted,
		editUrl: null,
		readOnly: trusted ? {} : { head_scripts: 'unfiltered_html' },
	};
}

function renderPage(data: OptionsPageData) {
	mockBootstrap({ screen: 'options-page' });
	window.protoBlocksOptionsPage = data;
	return render(<OptionsPageScreen />);
}

afterEach(() => {
	delete window.protoBlocksAdmin;
	delete window.protoBlocksOptionsPage;
	jest.restoreAllMocks();
});

test('renders the page, its fields, and hides the conditional heading', () => {
	renderPage(cadco());

	expect(screen.getByRole('heading', { level: 1, name: 'Display Settings' })).toBeInTheDocument();
	expect(screen.getByRole('switch', { name: 'Hide child products' })).toBeInTheDocument();
	expect(screen.getByRole('radiogroup', { name: 'Child product placement' })).toBeInTheDocument();
	expect(screen.queryByRole('textbox', { name: 'Section heading' })).not.toBeInTheDocument();
});

test('the placement choice toggles the heading', async () => {
	const user = userEvent.setup();
	renderPage(cadco());

	await user.click(screen.getByRole('radio', { name: 'Own section' }));
	expect(await screen.findByRole('textbox', { name: 'Section heading' })).toBeInTheDocument();

	await user.click(screen.getByRole('radio', { name: 'Related products' }));
	await waitFor(() => expect(screen.queryByRole('textbox', { name: 'Section heading' })).not.toBeInTheDocument());
});

test('Save settings posts the whole page and toasts', async () => {
	const user = userEvent.setup();
	const success = jest.spyOn(toast, 'success');
	let body: { fields: Record<string, Record<string, unknown>>; present: Record<string, string[]> } | null = null;
	const api = mockApi({
		'POST options/cadco-display-settings': (b: unknown) => {
			body = b as typeof body;
			return {
				values: { hide_children: false, children_placement: 'section', children_heading: 'More' },
				notices: [],
			};
		},
	});
	renderPage(cadco());

	await user.click(screen.getByRole('radio', { name: 'Own section' }));
	await user.type(await screen.findByRole('textbox', { name: 'Section heading' }), 'More');
	await user.click(screen.getByRole('button', { name: 'Save settings' }));

	await waitFor(() => expect(success).toHaveBeenCalledWith('Settings saved.'));
	expect(api).toHaveBeenCalledWith(
		expect.objectContaining({ method: 'POST', url: 'https://example.test/wp-json/proto-blocks/v1/options/cadco-display-settings' })
	);
	expect(body).not.toBeNull();
	const sent = body as unknown as { fields: Record<string, Record<string, unknown>>; present: Record<string, string[]> };
	expect(sent.fields[CADCO_GROUP]).toEqual({ hide_children: '0', children_placement: 'section', children_heading: 'More' });
	expect([...sent.present[CADCO_GROUP]].sort()).toEqual(['children_heading', 'children_placement', 'hide_children']);
	// The saved value is kept after the form re-mounts.
	expect(await screen.findByRole('textbox', { name: 'Section heading' })).toHaveValue('More');
});

test('a 422 places the error on its field and does not toast success', async () => {
	const user = userEvent.setup();
	const success = jest.spyOn(toast, 'success');
	mockApi({
		'POST options/cadco-display-settings': () => {
			throw {
				code: 'proto_blocks_invalid_values',
				message: 'Some values are not valid. Nothing was saved.',
				data: { status: 422, errors: { children_heading: 'This field is required.' } },
			};
		},
	});
	renderPage(cadco());

	await user.click(screen.getByRole('radio', { name: 'Own section' }));
	await user.click(screen.getByRole('button', { name: 'Save settings' }));

	const input = await screen.findByRole('textbox', { name: 'Section heading' });
	await waitFor(() => expect(input).toHaveAttribute('aria-invalid', 'true'));
	const errorId = (input.getAttribute('aria-describedby') ?? '').split(' ').find((id) => id.endsWith('-error')) ?? '';
	expect(document.getElementById(errorId)).toHaveTextContent('This field is required.');
	expect(document.getElementById(errorId)).toHaveAttribute('role', 'alert');
	await waitFor(() => expect(input).toHaveFocus());
	expect(success).not.toHaveBeenCalled();
	// The unsaved choice survives the re-mount.
	expect(screen.getByRole('radio', { name: 'Own section' })).toBeChecked();
});

test('an error on another tab activates it and counts on its trigger', async () => {
	const user = userEvent.setup();
	mockApi({
		'POST options/theme-settings': () => {
			throw { code: 'proto_blocks_invalid_values', message: 'Nope', data: { status: 422, errors: { head_scripts: 'Too long.' } } };
		},
	});
	renderPage(themeSettings());

	const general = screen.getByRole('tab', { name: /General/ });
	const tracking = screen.getByRole('tab', { name: /Tracking/ });
	expect(general).toHaveAttribute('aria-selected', 'true');

	await user.click(screen.getByRole('button', { name: 'Save settings' }));

	await waitFor(() => expect(tracking).toHaveAttribute('aria-selected', 'true'));
	expect(within(tracking).getByText('1')).toBeInTheDocument();
	expect(within(general).queryByText('1')).not.toBeInTheDocument();
});

test('tabs are one form: fields on inactive tabs are still submitted', async () => {
	const user = userEvent.setup();
	let body: { fields: Record<string, Record<string, unknown>> } | null = null;
	mockApi({
		'POST options/theme-settings': (b: unknown) => {
			body = b as typeof body;
			return { values: { phone: '555', head_scripts: '<script>track()</script>' }, notices: [] };
		},
	});
	renderPage(themeSettings());

	await user.click(screen.getByRole('button', { name: 'Save settings' }));

	await waitFor(() => expect(body).not.toBeNull());
	const sent = body as unknown as { fields: Record<string, Record<string, unknown>> };
	expect(sent.fields.group_theme_settings_1).toEqual({ phone: '555' });
	expect(sent.fields.group_theme_settings_2).toEqual({ head_scripts: '<script>track()</script>' });
});

test('untrusted users see code as hidden, with no value and no input', async () => {
	const user = userEvent.setup();
	const { container } = renderPage(themeSettings(false));

	await user.click(screen.getByRole('tab', { name: /Tracking/ }));

	expect(screen.getByText('Head scripts')).toBeInTheDocument();
	expect(screen.getByRole('note', { name: 'Head scripts' })).toHaveTextContent(
		'Hidden; only users with unfiltered HTML may view and edit this field.'
	);
	expect(container.textContent).not.toContain('track()');
	expect(container.querySelector('[name*="head_scripts"]')).toBeNull();
});

test('the edit link shows only with an editUrl', () => {
	const { unmount } = renderPage(cadco());
	expect(screen.queryByRole('link', { name: 'Edit declaration' })).not.toBeInTheDocument();
	unmount();

	renderPage(cadco({ editUrl: 'https://example.test/wp-admin/admin.php?page=proto-blocks-options-pages&slug=cadco-display-settings' }));
	expect(screen.getByRole('link', { name: 'Edit declaration' })).toHaveAttribute(
		'href',
		'https://example.test/wp-admin/admin.php?page=proto-blocks-options-pages&slug=cadco-display-settings'
	);
});

test('server notices show on the page', async () => {
	const user = userEvent.setup();
	mockApi({
		'POST options/cadco-display-settings': () => ({
			values: { hide_children: false, children_placement: 'related', children_heading: '' },
			notices: [{ field: null, message: 'The option is taken.' }],
		}),
	});
	renderPage(cadco());

	await user.click(screen.getByRole('button', { name: 'Save settings' }));

	expect(await screen.findByText('The option is taken.')).toBeInTheDocument();
});

test('the save button is disabled while saving', async () => {
	const user = userEvent.setup();
	let release: (v: unknown) => void = () => undefined;
	mockApi({
		'POST options/cadco-display-settings': () =>
			new Promise((resolve) => {
				release = resolve;
			}),
	});
	renderPage(cadco());

	await user.click(screen.getByRole('button', { name: 'Save settings' }));
	expect(screen.getByRole('button', { name: /Sav/ })).toBeDisabled();

	release({ values: {}, notices: [] });
	await waitFor(() => expect(screen.getByRole('button', { name: 'Save settings' })).toBeEnabled());
});

/** Whether leaving the page now would prompt. */
function leavePrompts(): boolean {
	const event = new Event('beforeunload', { cancelable: true });
	window.dispatchEvent(event);
	return event.defaultPrevented;
}

test('a successful save clears the unsaved-changes guard', async () => {
	const user = userEvent.setup();
	mockApi({
		'POST options/cadco-display-settings': () => ({
			values: { hide_children: false, children_placement: 'section', children_heading: '' },
			notices: [],
		}),
	});
	const success = jest.spyOn(toast, 'success');
	renderPage(cadco());

	await user.click(screen.getByRole('radio', { name: 'Own section' }));
	expect(leavePrompts()).toBe(true);
	await user.click(screen.getByRole('button', { name: 'Save settings' }));

	await waitFor(() => expect(success).toHaveBeenCalled());
	expect(leavePrompts()).toBe(false);
});

test('a 422 keeps the unsaved-changes guard', async () => {
	const user = userEvent.setup();
	mockApi({
		'POST options/cadco-display-settings': () => {
			throw { code: 'proto_blocks_invalid_values', message: 'No', data: { status: 422, errors: { children_heading: 'This field is required.' } } };
		},
	});
	const error = jest.spyOn(toast, 'error');
	renderPage(cadco());

	await user.click(screen.getByRole('radio', { name: 'Own section' }));
	await user.click(screen.getByRole('button', { name: 'Save settings' }));

	await waitFor(() => expect(error).toHaveBeenCalledWith('Nothing was saved: 1 field needs attention.'));
	expect(leavePrompts()).toBe(true);
});

test('a network error keeps every edit and the guard', async () => {
	const user = userEvent.setup();
	mockApi({
		'POST options/cadco-display-settings': () => {
			throw { code: 'fetch_error', message: 'You are probably offline.' };
		},
	});
	const error = jest.spyOn(toast, 'error');
	renderPage(cadco());

	await user.click(screen.getByRole('radio', { name: 'Own section' }));
	const heading = await screen.findByRole('textbox', { name: 'Section heading' });
	await user.type(heading, 'Kept');
	await user.click(screen.getByRole('button', { name: 'Save settings' }));

	await waitFor(() =>
		expect(error).toHaveBeenCalledWith("Couldn't reach the server.", { description: 'Your changes are still here.' })
	);
	// Same element (no re-mount) with the typed value.
	expect(screen.getByRole('textbox', { name: 'Section heading' })).toBe(heading);
	expect(heading).toHaveValue('Kept');
	expect(screen.getByRole('radio', { name: 'Own section' })).toBeChecked();
	expect(leavePrompts()).toBe(true);
});

test('focusing fields without changing them is not an unsaved change', async () => {
	const user = userEvent.setup();
	renderPage(cadco());

	await user.tab(); // the switch
	expect(screen.getByRole('switch', { name: 'Hide child products' })).toHaveFocus();
	await user.tab(); // the radio group
	await user.tab();

	expect(leavePrompts()).toBe(false);
});

test('toggling a switch counts as an unsaved change', async () => {
	const user = userEvent.setup();
	renderPage(cadco());

	await user.click(screen.getByRole('switch', { name: 'Hide child products' }));

	expect(leavePrompts()).toBe(true);
});

test('a 422 path no field owns is listed in the footer', async () => {
	const user = userEvent.setup();
	mockApi({
		'POST options/cadco-display-settings': () => {
			throw { code: 'proto_blocks_invalid_values', message: 'No', data: { status: 422, errors: { ghost_field: 'Not allowed.' } } };
		},
	});
	renderPage(cadco());

	await user.click(screen.getByRole('button', { name: 'Save settings' }));

	const item = (await screen.findByText(/Not allowed\./)).closest('li');
	expect(item).toHaveTextContent('ghost_field: Not allowed.');
	expect(item?.closest('[role="alert"]')).toHaveTextContent('Nothing was saved.');
});

test('leaving with unsaved changes asks first', async () => {
	const user = userEvent.setup();
	renderPage(cadco());

	const clean = new Event('beforeunload', { cancelable: true });
	window.dispatchEvent(clean);
	expect(clean.defaultPrevented).toBe(false);

	await user.click(screen.getByRole('radio', { name: 'Own section' }));
	const dirty = new Event('beforeunload', { cancelable: true });
	window.dispatchEvent(dirty);
	expect(dirty.defaultPrevented).toBe(true);
});

test('reads the JSON data element printed by PHP', () => {
	mockBootstrap({ screen: 'options-page' });
	const script = document.createElement('script');
	script.type = 'application/json';
	script.id = 'proto-blocks-options-page-data';
	script.textContent = JSON.stringify(cadco());
	document.body.appendChild(script);

	render(<OptionsPageScreen />);
	expect(screen.getByRole('heading', { level: 1, name: 'Display Settings' })).toBeInTheDocument();
	script.remove();
});
