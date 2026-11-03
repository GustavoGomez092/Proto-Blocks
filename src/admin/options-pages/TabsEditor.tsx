import { useEffect, useRef, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ArrowDownIcon, ArrowUpIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';
import { Button } from '@/admin/components/ui/button';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import type { OptionsPageForm } from './declaration';
import { FieldsSection } from './PageFieldsBuilder';

type Tab = OptionsPageForm['tabs'][number];

export function move<T>(items: T[], from: number, to: number): T[] {
	if (to < 0 || to >= items.length) {
		return items;
	}
	const next = [...items];
	const [item] = next.splice(from, 1);
	next.splice(to, 0, item);
	return next;
}

let nextId = 0;

/**
 * Stable row ids kept beside a list the parent owns (never written to the
 * data): React keys and the expanded row follow an item through reorders and
 * removals. Every change to the list goes through the returned helpers.
 */
export function useStableIds(length: number) {
	const ids = useRef<string[]>([]);
	while (ids.current.length < length) ids.current.push(`pb-row-${++nextId}`);
	if (ids.current.length > length) ids.current = ids.current.slice(0, length);
	return {
		ids: ids.current,
		add: () => ids.current.push(`pb-row-${++nextId}`),
		remove: (index: number) => {
			ids.current = ids.current.filter((_, i) => i !== index);
		},
		move: (from: number, to: number) => {
			ids.current = move(ids.current, from, to);
		},
	};
}

export function fieldCount(fields: unknown[]): string {
	/* translators: %d: number of fields */
	return sprintf(_n('%d field', '%d fields', fields.length, 'proto-blocks'), fields.length);
}

/** Tabs split one page; each holds its own fields. Add, rename, reorder, remove, and edit a tab's fields. */
export function TabsEditor({ tabs, disabled, onChange }: { tabs: Tab[]; disabled: boolean; onChange(next: Tab[]): void }) {
	const rows = useStableIds(tabs.length);
	const [open, setOpen] = useState<string | null>(null);
	const [removing, setRemoving] = useState<number | null>(null);
	const focusNext = useRef<string | null>(null);
	useEffect(() => {
		if (focusNext.current) {
			const [id, dir] = focusNext.current.split('|');
			focusNext.current = null;
			const same = document.getElementById(`${id}-${dir}`) as HTMLButtonElement | null;
			const other = document.getElementById(`${id}-${dir === 'up' ? 'down' : 'up'}`) as HTMLButtonElement | null;
			(same && !same.disabled ? same : other)?.focus();
		}
	});
	const set = (index: number, patch: Partial<Tab>) => onChange(tabs.map((tab, i) => (i === index ? { ...tab, ...patch } : tab)));
	const remove = (index: number) => {
		if (open === rows.ids[index]) setOpen(null);
		rows.remove(index);
		onChange(tabs.filter((_, i) => i !== index));
	};
	const reorder = (index: number, dir: 'up' | 'down') => {
		const to = dir === 'up' ? index - 1 : index + 1;
		focusNext.current = `${rows.ids[index]}|${dir}`;
		rows.move(index, to);
		onChange(move(tabs, index, to));
	};

	return (
		<div className="grid gap-3">
			{tabs.length === 0 ? <p className="text-label text-muted-foreground">{__('No tabs. All fields show on one screen.', 'proto-blocks')}</p> : null}
			<ol className="grid gap-2">
				{tabs.map((tab, index) => {
					const name = tab.title || __('(untitled tab)', 'proto-blocks');
					const id = rows.ids[index];
					return (
						<li key={id} className="grid gap-2 rounded-lg border px-3 py-2">
							<div className="flex flex-wrap items-end gap-2">
								<div className="grid min-w-[200px] flex-1 gap-1">
									<Label htmlFor={`pb-op-tab-${index}`}>{__('Tab title', 'proto-blocks')}</Label>
									<Input id={`pb-op-tab-${index}`} value={tab.title} disabled={disabled} onChange={(e) => set(index, { title: e.target.value })} />
								</div>
								<span className="pb-2 text-caption text-muted-foreground">{fieldCount(tab.fields)}</span>
								<Button type="button" variant="outline" size="sm" onClick={() => setOpen(open === id ? null : id)} aria-expanded={open === id}>
									{/* translators: %s: tab title */}
									<span className="sr-only">{sprintf(__('Edit fields of %s', 'proto-blocks'), name)}</span>
									<span aria-hidden="true">{__('Edit fields', 'proto-blocks')}</span>
								</Button>
								{/* translators: %s: tab title */}
								<Button id={`${id}-up`} type="button" variant="ghost" size="icon" disabled={disabled || index === 0} aria-label={sprintf(__('Move %s up', 'proto-blocks'), name)} onClick={() => reorder(index, 'up')}>
									<ArrowUpIcon aria-hidden="true" />
								</Button>
								{/* translators: %s: tab title */}
								<Button id={`${id}-down`} type="button" variant="ghost" size="icon" disabled={disabled || index === tabs.length - 1} aria-label={sprintf(__('Move %s down', 'proto-blocks'), name)} onClick={() => reorder(index, 'down')}>
									<ArrowDownIcon aria-hidden="true" />
								</Button>
								{/* translators: %s: tab title */}
								<Button type="button" variant="ghost" size="icon" disabled={disabled} aria-label={sprintf(__('Remove %s', 'proto-blocks'), name)} onClick={() => (tab.fields.length > 0 ? setRemoving(index) : remove(index))}>
									<Trash2Icon aria-hidden="true" />
								</Button>
							</div>
							{open === id ? <FieldsSection title={sprintf(/* translators: %s: tab title */ __('Fields in %s', 'proto-blocks'), name)} fields={tab.fields} readOnly={disabled} onChange={(fields) => set(index, { fields })} /> : null}
						</li>
					);
				})}
			</ol>
			{disabled ? null : (
				<Button type="button" variant="outline" className="justify-self-start" onClick={() => {
					rows.add();
					onChange([...tabs, { title: __('New tab', 'proto-blocks'), fields: [] }]);
				}}>
					<PlusIcon aria-hidden="true" />
					{__('Add tab', 'proto-blocks')}
				</Button>
			)}
			<ConfirmDialog
				open={removing !== null}
				/* translators: %s: tab title */
				title={sprintf(__('Remove the tab %s and its fields?', 'proto-blocks'), removing !== null ? tabs[removing]?.title ?? '' : '')}
				description={__('Saved values are not deleted.', 'proto-blocks')}
				confirmLabel={__('Remove tab', 'proto-blocks')}
				destructive
				onCancel={() => setRemoving(null)}
				onConfirm={() => {
					if (removing !== null) {
						remove(removing);
					}
					setRemoving(null);
				}}
			/>
		</div>
	);
}
