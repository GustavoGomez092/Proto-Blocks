import { __, sprintf } from '@wordpress/i18n';
import { Label } from '@/admin/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/admin/components/ui/select';
import { CAPABILITY_PATTERN } from './declaration';

/** Why `value` cannot be saved, or null. */
export function capabilityError(value: string): string | null {
	return CAPABILITY_PATTERN.test(value) ? null : __('Use lowercase letters, digits and underscores.', 'proto-blocks');
}

const COMMON = ['manage_options', 'edit_posts', 'edit_pages', 'publish_posts', 'edit_theme_options', 'manage_categories', 'upload_files', 'read'];
/** Radix Select forbids an empty item value; this stands for "inherit". */
const INHERIT = '__inherit__';

/** The common capabilities first (in that order), then the rest alphabetically. */
export function sortCapabilities(capabilities: string[]): string[] {
	const all = Array.from(new Set(capabilities));
	const common = COMMON.filter((cap) => all.includes(cap));
	const rest = all.filter((cap) => !COMMON.includes(cap)).sort((a, b) => a.localeCompare(b));
	return [...common, ...rest];
}

/** A dropdown over every capability a role has; no free text. */
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
	const options = sortCapabilities(capabilities);
	const unknown = value !== '' && !options.includes(value);

	return (
		<div className="grid content-start gap-1.5">
			<Label htmlFor={id}>{label}</Label>
			<Select value={value === '' ? (optional ? INHERIT : '') : value} disabled={disabled} onValueChange={(next) => onChange(next === INHERIT ? '' : next)}>
				<SelectTrigger
					id={id}
					className="font-mono"
					aria-invalid={error ? true : undefined}
					aria-describedby={hint ? `${id}-hint` : undefined}
				>
					<SelectValue placeholder={__('Choose a capability', 'proto-blocks')} />
				</SelectTrigger>
				<SelectContent>
					{optional ? <SelectItem value={INHERIT}>{__('Inherit from page', 'proto-blocks')}</SelectItem> : null}
					{unknown ? (
						<SelectItem value={value} className="font-mono">
							{/* translators: %s: a capability name */}
							{sprintf(__('%s (not granted to any role)', 'proto-blocks'), value)}
						</SelectItem>
					) : null}
					{options.map((cap) => (
						<SelectItem key={cap} value={cap} className="font-mono">
							{cap}
						</SelectItem>
					))}
				</SelectContent>
			</Select>
			{hint ? (
				<p id={`${id}-hint`} role={error ? 'alert' : 'status'} className={error ? 'text-caption text-destructive' : 'text-caption text-warning'}>
					{hint}
				</p>
			) : null}
		</div>
	);
}
