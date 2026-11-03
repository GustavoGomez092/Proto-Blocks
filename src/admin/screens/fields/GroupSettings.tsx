import { useId } from 'react';
import { __ } from '@wordpress/i18n';
import { cn } from '@/admin/lib/utils';
import type { BuilderAction, RawGroup } from './builder-state';
import { readLocation } from './LocationBuilder';
import { HELP, INPUT, LABEL, PbSwitch, Segmented } from './pb-ui';

export interface GroupSettingsProps {
	group: RawGroup;
	dispatch: (action: BuilderAction) => unknown;
	readOnly?: boolean;
}

/** The schema's `position` enum (schemas/field-group.schema.json). */
const POSITIONS: { value: string; label: string; help: string }[] = [
	{ value: 'normal', label: __('Below editor', 'proto-blocks'), help: __('Default meta-box position', 'proto-blocks') },
	{ value: 'side', label: __('Sidebar', 'proto-blocks'), help: __('Narrow; fields stack in one column', 'proto-blocks') },
	{ value: 'acf_after_title', label: __('After title', 'proto-blocks'), help: __('Above the content area', 'proto-blocks') },
];

const STYLES: { value: string; label: string }[] = [
	{ value: 'default', label: __('Box', 'proto-blocks') },
	{ value: 'seamless', label: __('Seamless', 'proto-blocks') },
];

/**
 * The group's presentation: box position (radio cards), style (segmented),
 * menu order and, for a group shown on a settings page, whether its options
 * autoload. The title is edited in the builder header. Each change is one
 * `{type: 'group', patch}` action; `undefined` removes a key.
 */
export function GroupSettings({ group, dispatch, readOnly = false }: GroupSettingsProps) {
	const id = useId();
	const position = typeof group.position === 'string' ? group.position : 'normal';
	const style = typeof group.style === 'string' ? group.style : 'default';
	const order = typeof group.menu_order === 'number' ? String(group.menu_order) : '';
	const onOptionsPage = readLocation(group.location).some((branch) => branch.some((rule) => rule.param === 'options_page'));
	const patch = (p: Record<string, unknown>) => dispatch({ type: 'group', patch: p });

	return (
		<div className="grid max-w-[760px] grid-cols-1 gap-x-6 gap-y-5 md:grid-cols-2">
			<fieldset className="m-0 grid content-start gap-2 border-0 p-0">
				<legend className={cn(LABEL, 'mb-2 p-0')}>{__('Position on the edit screen', 'proto-blocks')}</legend>
				<div className="grid gap-1.5">
					{POSITIONS.map((p) => {
						const on = position === p.value;
						const inputId = `${id}-pos-${p.value}`;
						return (
							<label
								key={p.value}
								htmlFor={inputId}
								className={cn(
									'flex cursor-pointer items-center gap-2.5 rounded-lg border border-solid px-3 py-2.5 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-ring',
									on ? 'border-primary bg-code' : 'border-border bg-background hover:bg-subtle',
									readOnly && 'cursor-not-allowed opacity-60'
								)}
							>
								<input
									id={inputId}
									type="radio"
									name={`${id}-position`}
									value={p.value}
									checked={on}
									disabled={readOnly}
									aria-labelledby={`${inputId}-label`}
									aria-describedby={`${inputId}-help`}
									onChange={() => patch({ position: p.value })}
									className="sr-only"
								/>
								<span
									aria-hidden="true"
									className={cn('box-border size-4 flex-none rounded-full border border-solid bg-background', on ? 'border-[5px] border-primary' : 'border-input')}
								/>
								<span className="grid">
									<span id={`${inputId}-label`} className="font-medium">
										{p.label}
									</span>
									<span id={`${inputId}-help`} className={HELP}>
										{p.help}
									</span>
								</span>
							</label>
						);
					})}
				</div>
			</fieldset>
			<div className="grid content-start gap-5">
				<div className="grid gap-2">
					<span id={`${id}-style`} className={LABEL}>
						{__('Style', 'proto-blocks')}
					</span>
					<Segmented
						value={style}
						options={STYLES}
						labelledBy={`${id}-style`}
						describedBy={`${id}-style-help`}
						disabled={readOnly}
						onChange={(v) => patch({ style: v })}
					/>
					<p id={`${id}-style-help`} className={HELP}>
						{__('Seamless drops the box frame and title.', 'proto-blocks')}
					</p>
				</div>
				<div className="grid max-w-40 gap-1.5">
					<label htmlFor={`${id}-order`} className={LABEL}>
						{__('Order', 'proto-blocks')}
					</label>
					<input
						id={`${id}-order`}
						type="number"
						step={1}
						value={order}
						placeholder="0"
						disabled={readOnly}
						aria-describedby={`${id}-order-help`}
						className={cn(INPUT, 'tabular-nums')}
						onChange={(e) => {
							const n = Number.parseInt(e.target.value, 10);
							patch({ menu_order: Number.isFinite(n) ? n : undefined });
						}}
					/>
					<p id={`${id}-order-help`} className={HELP}>
						{__('Lower shows first.', 'proto-blocks')}
					</p>
				</div>
				{onOptionsPage ? (
					<div className="flex items-start gap-2.5">
						<PbSwitch
							id={`${id}-autoload`}
							checked={group.autoload !== false}
							disabled={readOnly}
							aria-describedby={`${id}-autoload-help`}
							onCheckedChange={(on) => patch({ autoload: on ? undefined : false })}
						/>
						<div className="grid gap-0.5">
							<label htmlFor={`${id}-autoload`} className={LABEL}>
								{__('Autoload options', 'proto-blocks')}
							</label>
							<p id={`${id}-autoload-help`} className={HELP}>
								{__('Off stores this page’s options with autoload off; use it for large values read on few requests.', 'proto-blocks')}
							</p>
						</div>
					</div>
				) : null}
			</div>
		</div>
	);
}
