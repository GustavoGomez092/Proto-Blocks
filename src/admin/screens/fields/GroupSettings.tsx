import { useId } from 'react';
import { __ } from '@wordpress/i18n';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/admin/components/ui/radio-group';
import { Switch } from '@/admin/components/ui/switch';
import type { BuilderAction, RawGroup } from './builder-state';
import { readLocation } from './LocationBuilder';

export interface GroupSettingsProps {
	group: RawGroup;
	dispatch: (action: BuilderAction) => unknown;
	readOnly?: boolean;
}

/** The schema's `position` enum (schemas/field-group.schema.json). */
const POSITIONS: { value: string; label: string }[] = [
	{ value: 'normal', label: __('Below editor', 'proto-blocks') },
	{ value: 'side', label: __('Sidebar', 'proto-blocks') },
	{ value: 'acf_after_title', label: __('After title', 'proto-blocks') },
];

const STYLES: { value: string; label: string }[] = [
	{ value: 'default', label: __('Box', 'proto-blocks') },
	{ value: 'seamless', label: __('Seamless', 'proto-blocks') },
];

/**
 * The group's own settings: title, box position, style, menu order and, for
 * a group shown on a settings page, whether its options autoload. Each
 * change is one `{type: 'group', patch}` action; `undefined` removes a key.
 */
export function GroupSettings({ group, dispatch, readOnly = false }: GroupSettingsProps) {
	const id = useId();
	const title = typeof group.title === 'string' ? group.title : '';
	const position = typeof group.position === 'string' ? group.position : 'normal';
	const style = typeof group.style === 'string' ? group.style : 'default';
	const order = typeof group.menu_order === 'number' ? String(group.menu_order) : '';
	const onOptionsPage = readLocation(group.location).some((branch) => branch.some((rule) => rule.param === 'options_page'));
	const patch = (p: Record<string, unknown>) => dispatch({ type: 'group', patch: p });

	return (
		<div className="grid gap-4">
			<div className="grid gap-1.5">
				<Label htmlFor="pb-group-title">{__('Group title', 'proto-blocks')}</Label>
				<Input id="pb-group-title" value={title} disabled={readOnly} onChange={(e) => patch({ title: e.target.value })} />
			</div>
			<fieldset className="grid gap-2">
				<legend className="mb-1 text-sm font-medium">{__('Position on the edit screen', 'proto-blocks')}</legend>
				<RadioGroup className="flex flex-wrap gap-4" value={position} disabled={readOnly} onValueChange={(v) => patch({ position: v })}>
					{POSITIONS.map((p) => (
						<div key={p.value} className="flex items-center gap-1.5">
							<RadioGroupItem id={`${id}-pos-${p.value}`} value={p.value} />
							<Label htmlFor={`${id}-pos-${p.value}`} className="font-normal">
								{p.label}
							</Label>
						</div>
					))}
				</RadioGroup>
			</fieldset>
			<fieldset className="grid gap-2">
				<legend className="mb-1 text-sm font-medium">{__('Style', 'proto-blocks')}</legend>
				<RadioGroup
					className="flex flex-wrap gap-4"
					value={style}
					disabled={readOnly}
					aria-describedby={`${id}-style-help`}
					onValueChange={(v) => patch({ style: v })}
				>
					{STYLES.map((s) => (
						<div key={s.value} className="flex items-center gap-1.5">
							<RadioGroupItem id={`${id}-style-${s.value}`} value={s.value} />
							<Label htmlFor={`${id}-style-${s.value}`} className="font-normal">
								{s.label}
							</Label>
						</div>
					))}
				</RadioGroup>
				<p id={`${id}-style-help`} className="text-xs text-muted-foreground">
					{__('Seamless drops the box frame and title.', 'proto-blocks')}
				</p>
			</fieldset>
			<div className="grid max-w-40 gap-1.5">
				<Label htmlFor={`${id}-order`}>{__('Order', 'proto-blocks')}</Label>
				<Input
					id={`${id}-order`}
					type="number"
					step={1}
					value={order}
					disabled={readOnly}
					aria-describedby={`${id}-order-help`}
					onChange={(e) => {
						const n = Number.parseInt(e.target.value, 10);
						patch({ menu_order: Number.isFinite(n) ? n : undefined });
					}}
				/>
				<p id={`${id}-order-help`} className="text-xs text-muted-foreground">
					{__('Lower shows first.', 'proto-blocks')}
				</p>
			</div>
			{onOptionsPage ? (
				<div className="flex items-center gap-2">
					<Switch
						id={`${id}-autoload`}
						checked={group.autoload !== false}
						disabled={readOnly}
						aria-describedby={`${id}-autoload-help`}
						onCheckedChange={(on) => patch({ autoload: on ? undefined : false })}
					/>
					<Label htmlFor={`${id}-autoload`}>{__('Autoload options', 'proto-blocks')}</Label>
					<p id={`${id}-autoload-help`} className="text-xs text-muted-foreground">
						{__('Off stores this page’s options with autoload off; use it for large values read on few requests.', 'proto-blocks')}
					</p>
				</div>
			) : null}
		</div>
	);
}
