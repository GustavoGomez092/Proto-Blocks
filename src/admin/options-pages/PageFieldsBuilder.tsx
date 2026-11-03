import { useMemo, useRef, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { PlusIcon } from 'lucide-react';
import { toast } from 'sonner';
import { cn } from '@/admin/lib/utils';
import { BTN_PRIMARY, BTN_SECONDARY } from '@/admin/screens/fields/pb-ui';
import {
	AddFieldMenu,
	FieldSettings,
	FieldTree,
	reduceWithNotices,
	rowId,
	type BuilderAction,
	type FieldPath,
	type RawField,
	type RawGroup,
} from '@/admin/fields/builder';
import { fieldAt, isObject, siblingsAt, walkFields } from '@/admin/screens/fields/builder-state';

function fieldCountLabel(n: number): string {
	/* translators: %d: number of fields */
	return sprintf(_n('%d field', '%d fields', n, 'proto-blocks'), n);
}

function pathOf(group: RawGroup, key: string | null): FieldPath | null {
	let found: FieldPath | null = null;
	if (key !== null) {
		walkFields(group.fields, (field, path) => {
			if (found === null && rowId(field, path) === key) {
				found = path;
			}
		});
	}
	return found;
}

function names(group: RawGroup): Map<string, string> {
	const out = new Map<string, string>();
	walkFields(group.fields, (field) => {
		if (typeof field.key === 'string' && typeof field.name === 'string') {
			out.set(field.key, field.name);
		}
	});
	return out;
}

/**
 * The field builder for one group of an options page: Phase 2's FieldTree,
 * FieldSettings (with its ConditionBuilder) and AddFieldMenu over
 * builder-state's reducer. The location is the page itself, so there is no
 * location editor. Controlled: every action calls `onChange` with the next group.
 */
export function PageFieldsBuilder({ group, onChange, readOnly, title, hint }: { group: RawGroup; onChange(next: RawGroup): void; readOnly: boolean; title: string; hint?: string }) {
	const [selected, setSelected] = useState<string | null>(null);
	const [adding, setAdding] = useState<{ parent: FieldPath | null; layout?: number; label: string } | null>(null);
	const groupRef = useRef(group);
	groupRef.current = group;
	// Names as loaded: renaming one of these shows FieldSettings' stored-value warning.
	const [savedNames] = useState(() => names(group));

	const dispatch = (action: BuilderAction): RawGroup | null => {
		if (readOnly) {
			return null;
		}
		const { group: next, notices } = reduceWithNotices(groupRef.current, action);
		if (next === groupRef.current) {
			return null;
		}
		groupRef.current = next;
		onChange(next);
		for (const notice of notices) {
			/* translators: %s: field label */
			toast(sprintf(__('Removed the rules on "%s" that read the deleted field.', 'proto-blocks'), notice.label));
		}
		return next;
	};

	const fields = (Array.isArray(group.fields) ? group.fields : []).filter(isObject);
	const selectedPath = pathOf(group, selected);
	const selectedField = selectedPath ? fieldAt(group, selectedPath) : null;
	const siblings = useMemo(() => {
		if (!selectedPath) {
			return [];
		}
		const index = selectedPath[selectedPath.length - 1];
		return siblingsAt(group, selectedPath).filter((_, i) => i !== index);
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [group, selected]);

	const openAdd = (parent: FieldPath | null, layout?: number) => {
		let label: string = __('Top level', 'proto-blocks');
		if (parent) {
			const owner = fieldAt(group, parent);
			label = String(owner?.label || owner?.name || '');
		}
		setAdding({ parent, layout, label });
	};

	const addField = (type: string) => {
		const target = adding;
		setAdding(null);
		if (!target) {
			return;
		}
		const before = new Set<string>();
		walkFields(groupRef.current.fields, (f) => typeof f.key === 'string' && before.add(f.key));
		const next = dispatch({ type: 'add', parent: target.parent, layout: target.layout, fieldType: type });
		if (next) {
			walkFields(next.fields, (f) => {
				if (typeof f.key === 'string' && !before.has(f.key)) {
					setSelected(f.key);
				}
			});
		}
	};

	const atSelected = (): FieldPath | null => pathOf(groupRef.current, selected);
	const addHere = () => openAdd(null);

	return (
		<section aria-label={title} className="overflow-hidden rounded-xl border border-solid border-border bg-card">
			<div className="flex flex-wrap items-center justify-between gap-4 border-0 border-b border-solid border-border px-5 py-3.5">
				<div className="flex min-w-0 flex-wrap items-baseline gap-x-2.5 gap-y-1">
					<h3 className="text-section font-semibold">{title}</h3>
					{hint ? <span className="text-label text-muted-foreground">{hint}</span> : null}
				</div>
				<button type="button" className={BTN_SECONDARY} disabled={readOnly} onClick={addHere}>
					<PlusIcon aria-hidden="true" className="size-3.5" strokeWidth={2.5} />
					{__('Add field', 'proto-blocks')}
				</button>
			</div>
			{fields.length === 0 ? <p className="border-0 border-b border-solid border-divider px-5 py-6 text-label text-muted-foreground">{__('No fields yet.', 'proto-blocks')}</p> : null}
			<FieldTree
				fields={fields as RawField[]}
				selectedKey={selected}
				onSelect={(_, key) => setSelected((current) => (current === key ? null : key))}
				dispatch={dispatch}
				onAddField={openAdd}
				readOnly={readOnly}
				renderPanel={(field, _path, key) => (
					<>
						<FieldSettings
							key={key}
							field={field}
							siblings={siblings as RawField[]}
							savedName={savedNames.get(key)}
							readOnly={readOnly}
							idPrefix={`pb-option-field-${key}`}
							onChange={(patch) => {
								const path = pathOf(groupRef.current, key);
								if (path) {
									dispatch({ type: 'update', path, patch });
								}
							}}
							onLayout={(action) => {
								const path = pathOf(groupRef.current, key);
								if (path) {
									dispatch({ ...action, path });
								}
							}}
						/>
						<div className="mt-5 flex justify-end border-0 border-t border-solid border-border pt-3.5">
							<button type="button" className={BTN_SECONDARY} onClick={() => setSelected(null)}>
								{__('Close field', 'proto-blocks')}
							</button>
						</div>
					</>
				)}
			/>
			<div className="flex flex-wrap items-center justify-between gap-3 bg-subtle px-5 py-3.5">
				<span className="text-caption text-muted-foreground">{fieldCountLabel(fields.length)}</span>
				<button type="button" className={cn(BTN_PRIMARY, 'pl-3')} disabled={readOnly} onClick={addHere}>
					<PlusIcon aria-hidden="true" className="size-4" />
					{__('Add field', 'proto-blocks')}
				</button>
			</div>
			<AddFieldMenu
				open={adding !== null}
				onOpenChange={(open) => !open && setAdding(null)}
				onPick={addField}
				/* translators: %s: where the field is added */
				target={adding ? sprintf(__('Adds to: %s', 'proto-blocks'), adding.label) : undefined}
			/>
		</section>
	);
}

/** An item of a page's `fields` is a group when it has its own `fields` list and no field type. */
export function isGroupList(fields: unknown[]): boolean {
	return fields.length > 0 && fields.every((item) => isObject(item) && Array.isArray(item.fields) && item.type === undefined);
}

/**
 * The page's `fields` array in builders: bare fields are one implicit group
 * written back as bare fields; an array of groups gets one builder per group.
 */
export function FieldsSection({ fields, onChange, readOnly, title = __('Fields', 'proto-blocks'), hint }: { fields: unknown[]; onChange(next: unknown[]): void; readOnly: boolean; title?: string; hint?: string }) {
	if (isGroupList(fields)) {
		return (
			<div className="grid gap-6">
				{fields.map((item, index) => {
					const group = item as RawGroup;
					return (
						<PageFieldsBuilder
							key={typeof group.key === 'string' ? group.key : index}
							title={String(group.title || sprintf(/* translators: %d: group number */ __('Group %d', 'proto-blocks'), index + 1))}
							group={group}
							readOnly={readOnly}
							onChange={(next) => onChange(fields.map((g, i) => (i === index ? next : g)))}
						/>
					);
				})}
			</div>
		);
	}
	return <PageFieldsBuilder title={title} hint={hint} group={{ fields }} readOnly={readOnly} onChange={(next) => onChange(Array.isArray(next.fields) ? next.fields : [])} />;
}
