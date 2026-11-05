import '../helpers';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { readRules } from '@/admin/redirects/edits';
import { matchExamples } from '@/admin/redirects/RuleDialog';
import { renderScreen, routes, rowOf, setup, writes } from './fixtures';

setup();

const text = (node: unknown) => {
	const { container } = render(<p>{node as never}</p>);
	const out = container.textContent;
	container.remove();
	return out;
};

describe('edit redirect dialog (redesign)', () => {
	test('names the file it writes to, explains the code and saves Enabled from the footer switch', async () => {
		routes();
		const user = userEvent.setup();
		renderScreen();
		await user.click(within(await rowOf('/about-us')).getByRole('button', { name: 'Edit /about-us' }));
		const dialog = await screen.findByRole('dialog', { name: 'Edit redirect' });
		expect(within(dialog).getByText('proto-redirects/legacy-site/redirects.jsonc')).toBeTruthy();
		expect(within(dialog).getByText('Search engines transfer ranking.')).toBeTruthy();
		expect(within(dialog).getByRole('combobox', { name: 'With status' }).textContent).toBe('301 · Moved permanently');
		expect(within(dialog).getByText('Matching options').closest('details')?.open).toBe(true);
		// The title label reads "Title · optional"; the name stays "Title".
		expect(within(dialog).getByRole('textbox', { name: 'Title' })).toBeTruthy();

		const enabled = within(dialog).getByRole('switch', { name: 'Enabled' });
		expect(enabled.getAttribute('aria-checked')).toBe('true');
		await user.click(enabled);
		await user.click(within(dialog).getByRole('button', { name: 'Save redirect' }));
		await waitFor(() => expect(writes().some((c) => c.method === 'POST')).toBe(true));
		const post = writes().find((c) => c.method === 'POST');
		expect(readRules(post?.data?.text ?? '')[0].enabled).toBe(false);
	});

	test('the source hint shows what a plain path also matches', () => {
		const base = { regex: false, ignoreCase: true, ignoreTrailingSlash: true, query: 'exact' as const };
		expect(text(matchExamples({ ...base, from: '/about-us' }))).toBe('Matches /about-us and /About-Us/ with the options below.');
		expect(text(matchExamples({ ...base, from: '/about-us', query: 'ignore' }))).toBe('Matches /about-us, /About-Us/ and /about-us?utm=… with the options below.');
		expect(text(matchExamples({ ...base, from: '/a', ignoreCase: false }))).toBe('Matches /a and /a/ with the options below.');
		expect(text(matchExamples({ ...base, from: '/a', ignoreCase: false, ignoreTrailingSlash: false }))).toBe('Matches /a exactly, with the options below.');
		expect(matchExamples({ ...base, from: '^/a', regex: true })).toBeNull();
		expect(matchExamples({ ...base, from: '' })).toBeNull();
	});
});
