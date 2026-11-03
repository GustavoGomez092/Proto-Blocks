import { mockBootstrap } from '../helpers';
import { createRef, useState } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { TooltipProvider } from '@/admin/components/ui/tooltip';
import { OriginBadge } from '@/admin/components/content-model/OriginBadge';
import { IssueList } from '@/admin/components/content-model/IssueList';
import { DeclarationShell } from '@/admin/components/content-model/DeclarationShell';
import { DeleteDeclarationDialog } from '@/admin/components/content-model/DeleteDeclarationDialog';
import { GuardrailDialog } from '@/admin/components/content-model/GuardrailDialog';
import { ConflictDialog } from '@/admin/components/content-model/ConflictDialog';
import { LabelsEditor } from '@/admin/components/content-model/LabelsEditor';
import { MenuIconField } from '@/admin/components/content-model/MenuIconField';

// Radix positions an open tooltip with ResizeObserver, which jsdom lacks.
beforeAll(() => {
	if (!('ResizeObserver' in window)) {
		(window as unknown as { ResizeObserver: unknown }).ResizeObserver = class {
			observe() {}
			unobserve() {}
			disconnect() {}
		};
	}
});

beforeEach(() => {
	mockBootstrap({ fileModsConstant: 'DISALLOW_FILE_MODS', fileModsAllowed: false });
});

afterEach(() => {
	delete window.protoBlocksAdmin;
	document.body.innerHTML = '';
	jest.clearAllMocks();
});

test('OriginBadge shows the origin and Declared twice with both locations', async () => {
	const user = userEvent.setup();
	render(
		<TooltipProvider>
			<OriginBadge
				origin="file"
				label="Proto-Blocks file"
				double={{ file: 'themes/x/proto-types/product/type.jsonc', php: 'themes/x/functions.php' }}
			/>
		</TooltipProvider>
	);

	expect(screen.getByText('Proto-Blocks file')).toBeInTheDocument();
	const twice = screen.getByText('Declared twice');
	await user.hover(twice);
	expect((await screen.findAllByText(/functions\.php/)).length).toBeGreaterThan(0);
	expect(screen.getAllByText(/proto-types\/product\/type\.jsonc/).length).toBeGreaterThan(0);
});

test('OriginBadge without double shows one badge', () => {
	render(<OriginBadge origin="core" label="Core" double={null} />);
	expect(screen.getByText('Core')).toBeInTheDocument();
	expect(screen.queryByText('Declared twice')).not.toBeInTheDocument();
});

test('IssueList lists errors and warnings', () => {
	render(
		<IssueList
			issues={[
				{ level: 'error', code: 'parse', message: 'Broken file.', source: 'proto-types/event/type.jsonc' },
				{ level: 'warning', code: 'double', message: 'Declared twice.', source: 'functions.php' },
			]}
		/>
	);
	expect(screen.getByText('Broken file.')).toBeInTheDocument();
	expect(screen.getByText('Declared twice.')).toBeInTheDocument();
	expect(screen.getByText('proto-types/event/type.jsonc')).toBeInTheDocument();
});

test('IssueList renders nothing without issues', () => {
	const { container } = render(<IssueList issues={[]} />);
	expect(container).toBeEmptyDOMElement();
});

test('DeclarationShell with file_mods renders the Phase 0 banner', () => {
	render(
		<DeclarationShell title="Products" readOnlyReason="file_mods" path="/srv/themes/x/proto-types/product/type.jsonc">
			<p>Form</p>
		</DeclarationShell>
	);
	expect(screen.getByRole('heading', { level: 1, name: 'Products' })).toBeInTheDocument();
	expect(screen.getByText('File modifications are disabled on this site')).toBeInTheDocument();
	expect(screen.getByText('Form')).toBeInTheDocument();
});

test('DeclarationShell with not_writable names the path', () => {
	render(
		<DeclarationShell title="Products" readOnlyReason="not_writable" path="/srv/themes/x">
			<p>Form</p>
		</DeclarationShell>
	);
	const banner = screen.getByRole('status');
	expect(within(banner).getByText('The theme folder is not writable')).toBeInTheDocument();
	expect(within(banner).getByText('/srv/themes/x')).toBeInTheDocument();
});

test('DeclarationShell without a reason shows no banner', () => {
	render(
		<DeclarationShell title="Products" readOnlyReason={null} path="x">
			<p>Form</p>
		</DeclarationShell>
	);
	expect(screen.queryByRole('status')).not.toBeInTheDocument();
});

test('DeleteDeclarationDialog with content keeps Delete disabled until the slug is typed', async () => {
	const user = userEvent.setup();
	const onConfirm = jest.fn();
	render(
		<DeleteDeclarationDialog
			slug="product"
			path="proto-types/product/"
			content={{ count: 3 }}
			onConfirm={onConfirm}
			onCancel={() => {}}
		/>
	);

	expect(screen.getByText(/proto-types\/product\//)).toBeInTheDocument();
	const del = screen.getByRole('button', { name: /^Delete/ });
	expect(del).toBeDisabled();

	await user.type(screen.getByRole('textbox'), 'produc');
	expect(del).toBeDisabled();
	await user.type(screen.getByRole('textbox'), 't');
	expect(del).toBeEnabled();

	await user.click(del);
	expect(onConfirm).toHaveBeenCalledWith('product');
});

test('DeleteDeclarationDialog clears the typed slug when it reopens', async () => {
	const user = userEvent.setup();
	const props = { slug: 'product', path: 'proto-types/product/', content: { count: 3 }, onConfirm: () => {}, onCancel: () => {} };
	const { rerender } = render(<DeleteDeclarationDialog {...props} open />);
	await user.type(screen.getByRole('textbox'), 'product');

	rerender(<DeleteDeclarationDialog {...props} open={false} />);
	rerender(<DeleteDeclarationDialog {...props} open />);

	expect(await screen.findByRole('textbox')).toHaveValue('');
	expect(screen.getByRole('button', { name: /^Delete/ })).toBeDisabled();
});

test('DeleteDeclarationDialog without content enables Delete', async () => {
	const user = userEvent.setup();
	const onConfirm = jest.fn();
	render(
		<DeleteDeclarationDialog slug="product" path="proto-types/product/" content={{ count: 0 }} onConfirm={onConfirm} onCancel={() => {}} />
	);

	expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
	const del = screen.getByRole('button', { name: /^Delete/ });
	expect(del).toBeEnabled();
	await user.click(del);
	expect(onConfirm).toHaveBeenCalled();
});

test('GuardrailDialog lists the guardrails and confirms with their codes', async () => {
	const user = userEvent.setup();
	const onConfirm = jest.fn();
	const onCancel = jest.fn();
	render(
		<GuardrailDialog
			guardrails={[{ code: 'supports_removed', message: 'Removes Featured image from 2 products.', count: 2, detail: ['thumbnail'] }]}
			onConfirm={onConfirm}
			onCancel={onCancel}
		/>
	);
	expect(screen.getByText('Removes Featured image from 2 products.')).toBeInTheDocument();
	await user.click(screen.getByRole('button', { name: 'Save anyway' }));
	expect(onConfirm).toHaveBeenCalledWith(['supports_removed']);
	await user.click(screen.getByRole('button', { name: 'Go back' }));
	expect(onCancel).toHaveBeenCalled();
});

test('ConflictDialog names the path and reloads', async () => {
	const user = userEvent.setup();
	const onReload = jest.fn();
	render(<ConflictDialog path="proto-types/product/type.jsonc" onReload={onReload} />);
	expect(screen.getByText('The file changed on disk.')).toBeInTheDocument();
	expect(screen.getByText('proto-types/product/type.jsonc')).toBeInTheDocument();
	await user.click(screen.getByRole('button', { name: 'Reload file' }));
	expect(onReload).toHaveBeenCalled();
});

function LabelsHarness({ initial, onValue }: { initial?: Record<string, string>; onValue: (v: unknown) => void }) {
	const [value, setValue] = useState<Record<string, string> | undefined>(initial);
	return (
		<LabelsEditor
			value={value}
			generated={{ menu_name: 'Products', add_new_item: 'Add New Product' }}
			onChange={(next) => {
				setValue(next);
				onValue(next);
			}}
		/>
	);
}

test('LabelsEditor shows generated labels as placeholders and writes only overridden keys', async () => {
	const user = userEvent.setup();
	const onValue = jest.fn();
	render(<LabelsHarness onValue={onValue} />);

	const menu = screen.getByRole('textbox', { name: /menu_name/ });
	expect(menu).toHaveAttribute('placeholder', 'Products');
	expect(menu).toHaveValue('');

	await user.type(menu, 'Shop');
	expect(onValue).toHaveBeenLastCalledWith({ menu_name: 'Shop' });

	await user.clear(menu);
	expect(onValue).toHaveBeenLastCalledWith(undefined);
});

test('MenuIconField forwards its ref to the input', () => {
	const ref = createRef<HTMLInputElement>();
	render(<MenuIconField ref={ref} id="icon" value={undefined} onChange={() => {}} />);
	expect(ref.current).toBe(screen.getByRole('textbox', { name: /Menu icon/ }));
});

test('MenuIconField previews a dashicon and warns about a non-https URL', async () => {
	const user = userEvent.setup();
	function Harness() {
		const [value, setValue] = useState<string | undefined>('dashicons-cart');
		return <MenuIconField id="icon" value={value} onChange={setValue} />;
	}
	render(<Harness />);

	const input = screen.getByRole('textbox', { name: /Menu icon/ });
	expect(input).toHaveValue('dashicons-cart');
	expect(document.querySelector('.dashicons-cart')).not.toBeNull();

	await user.clear(input);
	await user.type(input, 'http://example.com/icon.svg');
	expect(screen.getByText(/isn't https/)).toBeInTheDocument();

	await user.clear(input);
	await user.type(input, 'https://example.com/icon.svg');
	expect(screen.queryByText(/isn't https/)).not.toBeInTheDocument();
});
