/**
 * The admin runs on WordPress's React 18, where a plain function component
 * never receives `ref`. shadcn's current components assume React 19, so the
 * ones that take refs (react-hook-form's register, Radix asChild triggers,
 * Radix portal children) are wrapped in forwardRef. These pin that.
 */
import { createRef } from 'react';
import { render } from '@testing-library/react';
import { Button } from '@/admin/components/ui/button';
import { Input } from '@/admin/components/ui/input';
import { Checkbox } from '@/admin/components/ui/checkbox';
import { Switch } from '@/admin/components/ui/switch';
import { RadioGroup, RadioGroupItem } from '@/admin/components/ui/radio-group';
import { Dialog, DialogContent, DialogTitle } from '@/admin/components/ui/dialog';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';

test('form controls forward refs to their DOM element', () => {
	const button = createRef<HTMLButtonElement>();
	const input = createRef<HTMLInputElement>();
	const checkbox = createRef<HTMLButtonElement>();
	const toggle = createRef<HTMLButtonElement>();
	const radio = createRef<HTMLButtonElement>();

	render(
		<>
			<Button ref={button}>Save</Button>
			<Input ref={input} />
			<Checkbox ref={checkbox} aria-label="Select" />
			<Switch ref={toggle} aria-label="Enable" />
			<RadioGroup>
				<RadioGroupItem ref={radio} value="a" aria-label="A" />
			</RadioGroup>
		</>
	);

	expect(button.current).toBeInstanceOf(HTMLButtonElement);
	expect(input.current).toBeInstanceOf(HTMLInputElement);
	expect(checkbox.current).toBeInstanceOf(HTMLButtonElement);
	expect(toggle.current).toBeInstanceOf(HTMLButtonElement);
	expect(radio.current).toBeInstanceOf(HTMLButtonElement);
});

test('dialogs render without ref warnings', () => {
	const spy = jest.spyOn(console, 'error').mockImplementation(() => {});

	render(
		<>
			<Dialog open>
				<DialogContent aria-describedby={undefined}>
					<DialogTitle>Title</DialogTitle>
				</DialogContent>
			</Dialog>
			<ConfirmDialog open title="Clear?" confirmLabel="Clear" onConfirm={() => {}} onCancel={() => {}} />
		</>
	);

	const refWarnings = spy.mock.calls.filter((args) => String(args[0]).includes('cannot be given refs'));
	spy.mockRestore();
	expect(refWarnings).toEqual([]);
});
