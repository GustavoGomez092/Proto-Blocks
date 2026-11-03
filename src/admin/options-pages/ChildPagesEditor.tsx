import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { PlusIcon, Trash2Icon } from 'lucide-react';
import { Button } from '@/admin/components/ui/button';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { CapabilityField, capabilityError } from './CapabilityField';
import { CHILD_SLUG_PATTERN, type OptionsPageForm } from './declaration';
import { FieldsSection } from './PageFieldsBuilder';
import { fieldCount, useStableIds } from './TabsEditor';

type Child = OptionsPageForm['children'][number];

export function childSlugError(slug: string): string | null {
	return CHILD_SLUG_PATTERN.test(slug) ? null : __('Use lowercase letters, digits and hyphens.', 'proto-blocks');
}

/** Problems that block saving the children. */
export function childProblems(children: Child[]): string[] {
	const out: string[] = [];
	children.forEach((child) => {
		const slug = childSlugError(child.slug);
		if (slug) out.push(slug);
		if (child.title.trim() === '') out.push(__('Every child page needs a title.', 'proto-blocks'));
		if (child.capability && capabilityError(child.capability)) out.push(capabilityError(child.capability) as string);
	});
	return out;
}

/** Separate submenu pages under the page: slug, title, optional capability and their own fields. */
export function ChildPagesEditor({
	parentSlug,
	children,
	capabilities,
	disabled,
	errors,
	onChange,
}: {
	parentSlug: string;
	children: Child[];
	capabilities: string[];
	disabled: boolean;
	/** Server errors by child index (e.g. a reserved compound slug). */
	errors?: Record<number, string>;
	onChange(next: Child[]): void;
}) {
	const rows = useStableIds(children.length);
	const [open, setOpen] = useState<string | null>(null);
	const set = (index: number, patch: Partial<Child>) => onChange(children.map((child, i) => (i === index ? { ...child, ...patch } : child)));

	return (
		<div className="grid gap-3">
			{children.length === 0 ? <p className="text-[13px] text-muted-foreground">{__('No child pages.', 'proto-blocks')}</p> : null}
			<ul className="grid gap-2">
				{children.map((child, index) => {
					const slugError = childSlugError(child.slug) ?? errors?.[index] ?? null;
					const name = child.title || child.slug || __('(untitled page)', 'proto-blocks');
					const id = rows.ids[index];
					return (
						<li key={id} className="grid gap-3 rounded-lg border px-3 py-3">
							<div className="grid gap-3 sm:grid-cols-3">
								<div className="grid gap-1.5">
									<Label htmlFor={`pb-op-child-${index}-title`}>{__('Child page title', 'proto-blocks')}</Label>
									<Input id={`pb-op-child-${index}-title`} value={child.title} disabled={disabled} onChange={(e) => set(index, { title: e.target.value })} />
								</div>
								<div className="grid gap-1.5">
									<Label htmlFor={`pb-op-child-${index}-slug`}>{__('Child page slug', 'proto-blocks')}</Label>
									<Input
										id={`pb-op-child-${index}-slug`}
										className="font-mono"
										value={child.slug}
										disabled={disabled}
										aria-invalid={slugError ? true : undefined}
										aria-describedby={`pb-op-child-${index}-slug-hint`}
										onChange={(e) => set(index, { slug: e.target.value })}
									/>
									<p id={`pb-op-child-${index}-slug-hint`} role={slugError ? 'alert' : undefined} className={slugError ? 'text-[12.5px] text-destructive' : 'font-mono text-[12.5px] text-muted-foreground'}>
										{slugError ?? `${parentSlug}-${child.slug}`}
									</p>
								</div>
								<CapabilityField
									id={`pb-op-child-${index}-capability`}
									label={__('Capability', 'proto-blocks')}
									value={child.capability ?? ''}
									capabilities={capabilities}
									disabled={disabled}
									optional
									onChange={(capability) => set(index, { capability: capability === '' ? undefined : capability })}
								/>
							</div>
							<div className="flex items-center gap-2">
								<span className="text-[12.5px] text-muted-foreground">{fieldCount(child.fields)}</span>
								<Button type="button" variant="outline" size="sm" aria-expanded={open === id} onClick={() => setOpen(open === id ? null : id)}>
									{/* translators: %s: child page title */}
									<span className="sr-only">{sprintf(__('Edit fields of %s', 'proto-blocks'), name)}</span>
									<span aria-hidden="true">{__('Edit fields', 'proto-blocks')}</span>
								</Button>
								{disabled ? null : (
									/* translators: %s: child page title */
									<Button type="button" variant="ghost" size="icon" aria-label={sprintf(__('Remove %s', 'proto-blocks'), name)} onClick={() => {
										rows.remove(index);
										onChange(children.filter((_, i) => i !== index));
									}}>
										<Trash2Icon aria-hidden="true" />
									</Button>
								)}
							</div>
							{open === id ? <FieldsSection fields={child.fields} readOnly={disabled} onChange={(fields) => set(index, { fields })} /> : null}
						</li>
					);
				})}
			</ul>
			{disabled ? null : (
				<Button type="button" variant="outline" className="justify-self-start" onClick={() => {
					rows.add();
					onChange([...children, { slug: '', title: '', fields: [] }]);
				}}>
					<PlusIcon aria-hidden="true" />
					{__('Add child page', 'proto-blocks')}
				</Button>
			)}
		</div>
	);
}
