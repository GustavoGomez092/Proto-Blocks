import { __, sprintf } from '@wordpress/i18n';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/admin/components/ui/radio-group';
import { Switch } from '@/admin/components/ui/switch';
import { OPTION_PATTERN, type OptionsPageForm } from './declaration';

export function optionError(storage: OptionsPageForm['storage']): string | null {
	return storage.mode === 'array' && !OPTION_PATTERN.test(storage.option) ? __('Use letters, digits, underscores and hyphens (191 characters at most).', 'proto-blocks') : null;
}

/**
 * How values are saved: one option per field (`<slug>_<field>`) or one
 * array option. `saved` is the storage in the file as loaded; switching away
 * from it says that stored values are not moved.
 */
export function StorageField({
	slug,
	storage,
	saved,
	autoload,
	firstField,
	disabled,
	onChange,
}: {
	slug: string;
	/** The first field's name, for a concrete example of the option name. */
	firstField?: string;
	storage: OptionsPageForm['storage'];
	saved: OptionsPageForm['storage'] | null;
	autoload: boolean;
	disabled: boolean;
	onChange(patch: Partial<Pick<OptionsPageForm, 'storage' | 'autoload'>>): void;
}) {
	const error = optionError(storage);
	let switched: string | null = null;
	if (saved && saved.mode === 'array' && storage.mode === 'fields') {
		switched = sprintf(
			/* translators: %s: option name */
			__('Stored values stay in %s; the page will read new per-field options.', 'proto-blocks'),
			saved.option
		);
	} else if (saved && saved.mode === 'fields' && storage.mode === 'array') {
		switched = __('Values saved per field stay where they are; the page will read only the array option.', 'proto-blocks');
	}

	return (
		<fieldset className="grid gap-3" disabled={disabled}>
			<legend className="mb-2 text-[13px] font-semibold">{__('How values are saved', 'proto-blocks')}</legend>
			<RadioGroup
				value={storage.mode}
				disabled={disabled}
				onValueChange={(mode) =>
					onChange({ storage: mode === 'array' ? { mode: 'array', option: saved?.mode === 'array' ? saved.option : slug.replace(/-/g, '_') } : { mode: 'fields' } })
				}
			>
				<div className="flex items-start gap-2">
					<RadioGroupItem value="fields" id="pb-op-storage-fields" />
					<Label htmlFor="pb-op-storage-fields" className="grid gap-0.5 font-normal">
						<span>{__('One setting per field', 'proto-blocks')}</span>
						<span className="text-[12.5px] text-muted-foreground">
							{sprintf(
								/* translators: %s: example option name, <page slug>_<field name> */
								__('Saved as %s. Recommended.', 'proto-blocks'),
								`${slug || 'page'}_${firstField || 'field'}`
							)}
						</span>
					</Label>
				</div>
				<div className="flex items-start gap-2">
					<RadioGroupItem value="array" id="pb-op-storage-array" />
					<Label htmlFor="pb-op-storage-array" className="grid gap-0.5 font-normal">
						<span>{__('Single array option', 'proto-blocks')}</span>
						<span className="text-[12.5px] text-muted-foreground">{__('For sites that already store these values together.', 'proto-blocks')}</span>
					</Label>
				</div>
			</RadioGroup>
			{storage.mode === 'array' ? (
				<div className="grid gap-1.5">
					<Label htmlFor="pb-op-option">{__('Setting name', 'proto-blocks')}</Label>
					<Input
						id="pb-op-option"
						className="font-mono"
						value={storage.option}
						aria-invalid={error ? true : undefined}
						aria-describedby={error ? 'pb-op-option-error' : undefined}
						onChange={(e) => onChange({ storage: { mode: 'array', option: e.target.value } })}
					/>
					{error ? (
						<p id="pb-op-option-error" role="alert" className="text-[12.5px] text-destructive">
							{error}
						</p>
					) : null}
				</div>
			) : null}
			{switched ? (
				<p role="status" className="rounded-md border border-warning-border bg-warning-soft px-3 py-2 text-[12.5px]">
					{switched}
				</p>
			) : null}
			<div className="flex items-center justify-between gap-3">
				<Label htmlFor="pb-op-autoload" className="grid gap-0.5 font-normal">
					<span>{__('Load on every page', 'proto-blocks')}</span>
					<span className="text-[12.5px] text-muted-foreground">{__('Keep on for values used site-wide (autoload).', 'proto-blocks')}</span>
				</Label>
				<Switch id="pb-op-autoload" checked={autoload} disabled={disabled} onCheckedChange={(checked) => onChange({ autoload: checked === true })} />
			</div>
		</fieldset>
	);
}
