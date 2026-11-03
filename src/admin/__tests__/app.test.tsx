import { mockBootstrap } from './helpers';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AdminApp } from '@/admin/app';
import { getBootstrap } from '@/admin/bootstrap';
import { ReadOnlyBanner } from '@/admin/components/shell/ReadOnlyBanner';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';
import { StatCard } from '@/admin/components/shell/StatCard';
import { PortalProvider } from '@/admin/components/shell/PortalProvider';

afterEach(() => {
	delete window.protoBlocksAdmin;
	document.body.innerHTML = '';
});

test('AdminApp renders the System Status screen', async () => {
	render(<AdminApp bootstrap={mockBootstrap({ screen: 'system' })} />);

	expect(await screen.findByRole('heading', { level: 1, name: 'System Status' })).toBeInTheDocument();
});

test('AdminApp shows ScreenError for a screen with no component', async () => {
	render(<AdminApp bootstrap={mockBootstrap({ screen: 'no-such-screen' as never })} />);

	expect(await screen.findByRole('alert')).toHaveTextContent("Couldn't load");
});

test('getBootstrap throws when the global is missing', () => {
	delete window.protoBlocksAdmin;

	expect(() => getBootstrap()).toThrow('protoBlocksAdmin bootstrap missing');
});

test('getBootstrap returns the global', () => {
	const bootstrap = mockBootstrap({ screen: 'dashboard' });

	expect(getBootstrap()).toBe(bootstrap);
});

test('ReadOnlyBanner explains disabled file modifications', () => {
	render(<ReadOnlyBanner reason="file_mods" />);

	expect(screen.getByRole('status')).toHaveTextContent('File modifications are disabled on this site');
});

test('ReadOnlyBanner names DISALLOW_FILE_EDIT when the bootstrap says so', () => {
	mockBootstrap({ fileModsAllowed: false, fileModsConstant: 'DISALLOW_FILE_EDIT' });
	render(<ReadOnlyBanner reason="file_mods" />);

	const banner = screen.getByRole('status');
	expect(banner).toHaveTextContent('DISALLOW_FILE_EDIT is set in wp-config.php');
	expect(banner).not.toHaveTextContent('DISALLOW_FILE_MODS');
});

test('ReadOnlyBanner takes the constant as a prop', () => {
	render(<ReadOnlyBanner reason="file_mods" constant="DISALLOW_FILE_EDIT" />);

	expect(screen.getByRole('status')).toHaveTextContent('DISALLOW_FILE_EDIT is set in wp-config.php');
});

test('ReadOnlyBanner names the unwritable path', () => {
	render(<ReadOnlyBanner reason="not_writable" path="/x" />);

	expect(screen.getByRole('status')).toHaveTextContent('/x');
});

test('StatCard shows label, value and hint', () => {
	render(<StatCard label="Registered Blocks" value={12} hint="3 from examples" />);

	expect(screen.getByText('Registered Blocks')).toBeInTheDocument();
	expect(screen.getByText('12')).toBeInTheDocument();
	expect(screen.getByText('3 from examples')).toBeInTheDocument();
});

test('ConfirmDialog renders into the admin portal, focuses Cancel and confirms', async () => {
	const portal = document.createElement('div');
	portal.id = 'proto-blocks-admin-portal';
	document.body.appendChild(portal);
	const onConfirm = jest.fn();
	const onCancel = jest.fn();

	render(
		<PortalProvider>
			<ConfirmDialog
				open
				title="Remove 4 demo blocks?"
				description="They are deleted from your theme."
				confirmLabel="Remove 4 blocks"
				destructive
				onConfirm={onConfirm}
				onCancel={onCancel}
			/>
		</PortalProvider>
	);

	const dialog = await screen.findByRole('alertdialog');
	expect(portal).toContainElement(dialog);
	expect(screen.getByRole('button', { name: 'Cancel' })).toHaveFocus();

	await userEvent.click(screen.getByRole('button', { name: 'Remove 4 blocks' }));
	expect(onConfirm).toHaveBeenCalledTimes(1);
	// The parent owns `open`; confirming must not also report a cancel.
	expect(onCancel).not.toHaveBeenCalled();
});

test('ConfirmDialog reports Cancel as onCancel', async () => {
	const onCancel = jest.fn();
	render(<ConfirmDialog open title="Clear the Tailwind cache?" confirmLabel="Clear cache" onConfirm={() => {}} onCancel={onCancel} />);

	await userEvent.click(await screen.findByRole('button', { name: 'Cancel' }));
	expect(onCancel).toHaveBeenCalledTimes(1);
});
