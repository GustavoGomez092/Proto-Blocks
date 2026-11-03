import { __ } from '@wordpress/i18n';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { CAPABILITY_PATTERN } from './declaration';

/** Why `value` cannot be saved, or null. */
export function capabilityError(value: string): string | null {
	return CAPABILITY_PATTERN.test(value) ? null : __('Use lowercase letters, digits and underscores.', 'proto-blocks');
}

/**
 * A combobox over every capability a role has; free text matching the
 * capability grammar is accepted, with a warning when no role has it.
 */
export function CapabilityField({
	id,
	label,
	value,
	capabilities,
	disabled,
	optional = false,
	onChange,
}: {
	id: string;
	label: string;
	value: string;
	capabilities: string[];
	disabled: boolean;
	/** A child page's capability: empty means "same as the parent". */
	optional?: boolean;
	onChange(next: string): void;
}) {
	const error = optional && value === '' ? null : capabilityError(value);
	let warning: string | null = null;
	if (!error && value === 'read') {
		warning = __('Anyone who can log in will be able to change these settings.', 'proto-blocks');
	} else if (!error && value !== '' && capabilities.length > 0 && !capabilities.includes(value)) {
		warning = __('No role has this capability; only super admins will see the page.', 'proto-blocks');
	}
	const hint = error ?? warning;

	return (
		<div className="grid gap-1.5">
			<Label htmlFor={id}>{label}</Label>
			<Input
				id={id}
				role="combobox"
				list={`${id}-list`}
				className="font-mono"
				value={value}
				disabled={disabled}
				placeholder={optional ? __('Same as the parent page', 'proto-blocks') : undefined}
				aria-invalid={error ? true : undefined}
				aria-describedby={hint ? `${id}-hint` : undefined}
				onChange={(e) => onChange(e.target.value)}
			/>
			<datalist id={`${id}-list`}>
				{capabilities.map((cap) => (
					<option key={cap} value={cap} />
				))}
			</datalist>
			{hint ? (
				<p id={`${id}-hint`} role={error ? 'alert' : 'status'} className={error ? 'text-caption text-destructive' : 'text-caption text-warning'}>
					{hint}
				</p>
			) : null}
		</div>
	);
}
