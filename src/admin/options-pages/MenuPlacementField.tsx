import { __, sprintf } from '@wordpress/i18n';
import { MenuIconField } from '@/admin/components/content-model/MenuIconField';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/admin/components/ui/radio-group';
import type { OptionsPageForm } from './declaration';

type Placement = Pick<OptionsPageForm, 'menu' | 'menu_title' | 'position' | 'icon'>;

/**
 * Where the page sits: a top-level item (icon, position) or under an
 * existing menu chosen from `parents` (GET admin/options-pages/<slug>).
 */
export function MenuPlacementField({
	value,
	parents: allParents,
	exclude = [],
	disabled,
	onChange,
}: {
	value: Placement;
	parents: { slug: string; title: string }[];
	/** The page's own child pages (compound slugs): a page cannot sit under its child. */
	exclude?: string[];
	disabled: boolean;
	onChange(patch: Partial<Placement>): void;
}) {
	const parents = allParents.filter((p) => !exclude.includes(p.slug));
	const top = value.menu === 'top';
	const options = parents.some((p) => p.slug === value.menu) || top
		? parents
		: [...parents, { slug: value.menu, title: sprintf(/* translators: %s: menu slug */ __('%s (missing)', 'proto-blocks'), value.menu) }];

	return (
		<fieldset className="grid gap-3" disabled={disabled}>
			<legend className="mb-2 text-label font-medium">{__('Menu placement', 'proto-blocks')}</legend>
			<RadioGroup
				value={top ? 'top' : 'under'}
				disabled={disabled}
				onValueChange={(next) => onChange({ menu: next === 'top' ? 'top' : (options[0]?.slug ?? 'options-general.php') })}
			>
				<div className="flex items-center gap-2">
					<RadioGroupItem value="top" id="pb-op-menu-top" />
					<Label htmlFor="pb-op-menu-top" className="font-normal">{__('Top-level menu item', 'proto-blocks')}</Label>
				</div>
				<div className="flex items-center gap-2">
					<RadioGroupItem value="under" id="pb-op-menu-under" />
					<Label htmlFor="pb-op-menu-under" className="font-normal">{__('Under an existing menu', 'proto-blocks')}</Label>
				</div>
			</RadioGroup>
			{top ? (
				<div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_140px]">
					<MenuIconField id="pb-op-icon" value={value.icon} disabled={disabled} onChange={(icon) => onChange({ icon })} />
					<div className="grid content-start gap-1.5">
						<Label htmlFor="pb-op-position">{__('Position', 'proto-blocks')}</Label>
						<Input
							id="pb-op-position"
							type="number"
							value={value.position ?? ''}
							onChange={(e) => onChange({ position: e.target.value === '' ? undefined : Math.trunc(Number(e.target.value)) })}
						/>
					</div>
				</div>
			) : (
				<div className="grid gap-1.5">
					<Label htmlFor="pb-op-parent">{__('Parent menu', 'proto-blocks')}</Label>
					<select
						id="pb-op-parent"
						className="h-[38px] rounded-md border border-input bg-background px-3 text-body hover:border-[#b5bfcc] focus-visible:border-primary focus-visible:ring-[3px] focus-visible:ring-brand-soft focus-visible:outline-none disabled:bg-muted disabled:opacity-60"
						value={value.menu}
						onChange={(e) => onChange({ menu: e.target.value })}
					>
						{options.map((p) => (
							<option key={p.slug} value={p.slug}>
								{p.title}
							</option>
						))}
					</select>
				</div>
			)}
			<div className="grid gap-1.5">
				<Label htmlFor="pb-op-menu-title">{__('Menu title', 'proto-blocks')}</Label>
				<Input
					id="pb-op-menu-title"
					value={value.menu_title ?? ''}
					placeholder={__('Same as the page title', 'proto-blocks')}
					onChange={(e) => onChange({ menu_title: e.target.value === '' ? undefined : e.target.value })}
				/>
			</div>
		</fieldset>
	);
}
