import { useId } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Input } from '@/admin/components/ui/input';

/**
 * Label overrides (`labels` in a declaration). Each row shows the label
 * WordPress would generate as the placeholder; typing overrides it, emptying
 * the field (or Reset) goes back to the generated one. `onChange` receives
 * only the overridden keys, or undefined when none are left, so the file
 * keeps no generated labels.
 */
export function LabelsEditor({
	value,
	generated,
	onChange,
	disabled = false,
}: {
	value: Record<string, string> | undefined;
	/** Generated labels by key, from the effective settings. */
	generated: Record<string, string>;
	onChange: (next: Record<string, string> | undefined) => void;
	disabled?: boolean;
}) {
	const prefix = useId();
	const overrides = value ?? {};
	const keys = [...Object.keys(generated), ...Object.keys(overrides).filter((key) => !(key in generated))];
	const custom = keys.filter((key) => typeof overrides[key] === 'string' && overrides[key] !== '').length;
	const auto = keys.length - custom;

	const set = (key: string, text: string) => {
		const next = { ...overrides };
		if (text === '') {
			delete next[key];
		} else {
			next[key] = text;
		}
		onChange(Object.keys(next).length > 0 ? next : undefined);
	};

	return (
		<div className="grid gap-3">
			<div className="flex flex-wrap gap-1.5">
				<Badge variant="outline">
					{/* translators: %d: number of labels WordPress generates. */}
					{sprintf(_n('%d auto', '%d auto', auto, 'proto-blocks'), auto)}
				</Badge>
				<Badge variant="outline" className="border-transparent bg-brand-soft text-[#0b5f5a]">
					{/* translators: %d: number of labels the developer overrides. */}
					{sprintf(_n('%d customised', '%d customised', custom, 'proto-blocks'), custom)}
				</Badge>
			</div>
			<div className="grid gap-2">
				{keys.map((key) => {
					const id = `${prefix}-${key}`;
					const overridden = typeof overrides[key] === 'string' && overrides[key] !== '';
					return (
						<div key={key} className="grid items-center gap-1 sm:grid-cols-[200px_minmax(0,1fr)_auto] sm:gap-3">
							<label htmlFor={id} className="font-mono text-[13px] text-muted-foreground">
								{key}
							</label>
							<Input
								id={id}
								value={overrides[key] ?? ''}
								placeholder={generated[key] ?? ''}
								disabled={disabled}
								onChange={(event) => set(key, event.target.value)}
							/>
							{overridden && !disabled ? (
								<Button
									type="button"
									variant="link"
									size="sm"
									className="h-auto justify-self-start px-0"
									/* translators: %s: label key such as menu_name. */
									aria-label={sprintf(__('Reset %s', 'proto-blocks'), key)}
									onClick={() => set(key, '')}
								>
									{__('Reset', 'proto-blocks')}
								</Button>
							) : (
								<span className="hidden sm:block" />
							)}
						</div>
					);
				})}
			</div>
		</div>
	);
}
