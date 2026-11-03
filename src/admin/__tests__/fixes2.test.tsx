import { mockBootstrap } from './helpers';
import { act, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import userEvent from '@testing-library/user-event';
import { AdminApp } from '@/admin/app';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';

afterEach(() => {
	delete window.protoBlocksAdmin;
	document.body.innerHTML = '';
});

test('AdminApp root carries the 20px right margin that matches .wrap', async () => {
	const { container } = render(<AdminApp bootstrap={mockBootstrap({ screen: 'system' })} />);
	await screen.findByRole('heading', { level: 1, name: 'System status' });

	expect(container.querySelector('.max-w-\\[1200px\\]')).toHaveClass('mr-5');
});

function DisabledOpenerHarness({ reenableAfter }: { reenableAfter: number | null }) {
	const [open, setOpen] = useState(false);
	const [busy, setBusy] = useState(false);
	return (
		<>
			<h1>Screen heading</h1>
			<button type="button" disabled={busy} onClick={() => setOpen(true)}>
				Clear cache
			</button>
			<ConfirmDialog
				open={open}
				title="Clear?"
				confirmLabel="Confirm clear"
				onConfirm={() => {
					setBusy(true);
					setOpen(false);
					if (reenableAfter !== null) {
						setTimeout(() => setBusy(false), reenableAfter);
					}
				}}
				onCancel={() => setOpen(false)}
			/>
		</>
	);
}

async function openAndConfirm() {
	const opener = screen.getByRole('button', { name: 'Clear cache' });
	opener.focus();
	await userEvent.keyboard('{Enter}');
	await screen.findByRole('alertdialog');
	await userEvent.click(screen.getByRole('button', { name: 'Confirm clear' }));
	await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument());
	return opener;
}

test.each([['opener disabled at close, re-enabled later', 150]])('ConfirmDialog focus: %s -> focused', async (_label, ms) => {
	render(<DisabledOpenerHarness reenableAfter={ms} />);
	const opener = await openAndConfirm();

	await waitFor(() => expect(opener).toBeEnabled());
	await waitFor(() => expect(opener).toHaveFocus());
});

test('ConfirmDialog focus falls back to the h1 when the opener never re-enables', async () => {
	jest.useFakeTimers({ advanceTimers: true });
	try {
		render(<DisabledOpenerHarness reenableAfter={null} />);
		await openAndConfirm();

		await act(async () => {
			jest.advanceTimersByTime(5100);
		});
		expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
	} finally {
		jest.useRealTimers();
	}
});

test('ConfirmDialog does not steal focus the user moved elsewhere before the opener re-enabled', async () => {
	render(
		<>
			<input aria-label="Elsewhere" />
			<DisabledOpenerHarness reenableAfter={200} />
		</>
	);
	const opener = await openAndConfirm();
	const elsewhere = screen.getByLabelText('Elsewhere');
	elsewhere.focus();

	await waitFor(() => expect(opener).toBeEnabled());
	await act(async () => {
		await new Promise((resolve) => setTimeout(resolve, 50));
	});
	expect(elsewhere).toHaveFocus();
});

test('unmounting cancels the pending wait (observer disconnected, timer cleared)', async () => {
	const disconnect = jest.spyOn(MutationObserver.prototype, 'disconnect');
	const clear = jest.spyOn(window, 'clearTimeout');
	const view = render(<DisabledOpenerHarness reenableAfter={null} />);
	await openAndConfirm();
	disconnect.mockClear();
	clear.mockClear();

	view.unmount();

	expect(disconnect).toHaveBeenCalled();
	expect(clear).toHaveBeenCalled();
	disconnect.mockRestore();
	clear.mockRestore();
});

test('the h1 fallback removes the tabindex it added once focus leaves', async () => {
	jest.useFakeTimers({ advanceTimers: true });
	try {
		render(<DisabledOpenerHarness reenableAfter={null} />);
		await openAndConfirm();
		await act(async () => {
			jest.advanceTimersByTime(5100);
		});
		const heading = screen.getByRole('heading', { level: 1 });
		expect(heading).toHaveAttribute('tabindex', '-1');

		act(() => heading.blur());
		expect(heading).not.toHaveAttribute('tabindex');
	} finally {
		jest.useRealTimers();
	}
});
