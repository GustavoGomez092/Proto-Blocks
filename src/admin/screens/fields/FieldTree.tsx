import { useEffect, useId, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { DndContext, PointerSensor, closestCenter, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core';
import { SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { ChevronDownIcon, ChevronRightIcon, CopyIcon, GripVerticalIcon, PlusIcon, Trash2Icon, XIcon } from 'lucide-react';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { cn } from '@/admin/lib/utils';
import { hasSubFields, isFlexible, isObject, layoutList, walkFields, type BuilderAction, type FieldPath, type RawField } from './builder-state';
import { typeLabel } from './field-settings-spec';

export interface FieldTreeProps {
	/** The group's top-level fields, raw. */
	fields: RawField[];
	/** The selected field's row id (its key). */
	selectedKey: string | null;
	onSelect: (path: FieldPath, key: string) => void;
	/** Receives `move`, `duplicate`, `remove` and `addLayout` actions. */
	dispatch: (action: BuilderAction) => void;
	/** "Add field" in a list: `parent` null for the top level; `layout` for a flexible layout. */
	onAddField: (parent: FieldPath | null, layout?: number) => void;
	readOnly?: boolean;
}

/** A row's id: the field key, or its path when a field has none. */
export function rowId(field: RawField, path: FieldPath): string {
	return typeof field.key === 'string' && field.key !== '' ? field.key : `path:${path.join('.')}`;
}

function title(field: RawField): string {
	return String(field.label || field.name || __('(no label)', 'proto-blocks'));
}

const fieldsOf = (owner: RawField): RawField[] => (Array.isArray(owner.sub_fields) ? owner.sub_fields : []).filter(isObject);

/** Parent field path of `path` (dropping a trailing layout address), or null at the top level. */
function parentFieldPath(path: FieldPath): FieldPath | null {
	if (path.length < 2) {
		return null;
	}
	const parent = path.slice(0, -1);
	return parent[parent.length - 2] === 'layouts' ? parent.slice(0, -2) : parent;
}

interface TreeContext extends FieldTreeProps {
	lifted: string | null;
	setLifted: (id: string | null) => void;
	announce: (message: string) => void;
	collapsed: Set<string>;
	toggle: (id: string) => void;
	instructionsId: string;
	/** Field lists by owner path, for keyboard moves. */
	all: RawField[];
}

function fieldListAt(fields: RawField[], parent: FieldPath | null, layout?: number): RawField[] {
	let list = fields;
	if (parent === null) {
		return list;
	}
	let owner: RawField | undefined;
	for (let i = 0; i < parent.length; i++) {
		const seg = parent[i];
		if (seg === 'layouts') {
			list = fieldsOf(layoutList(owner as RawField)[parent[i + 1] as number] ?? {});
			i++;
			continue;
		}
		owner = list[seg];
		if (!owner) {
			return [];
		}
		list = fieldsOf(owner);
	}
	return layout !== undefined && owner ? fieldsOf(layoutList(owner)[layout] ?? {}) : list;
}

function Row({ field, path, ctx }: { field: RawField; path: FieldPath; ctx: TreeContext }) {
	const id = rowId(field, path);
	const sortable = useSortable({ id, disabled: ctx.readOnly });
	const label = title(field);
	const container = hasSubFields(field) || isFlexible(field);
	const open = !ctx.collapsed.has(id);
	const selected = ctx.selectedKey === id;
	const isLifted = ctx.lifted === id;
	const handleRef = useRef<HTMLButtonElement | null>(null);

	useEffect(() => {
		if (isLifted && document.activeElement !== handleRef.current) {
			handleRef.current?.focus();
		}
	});

	const onHandleKey = (event: KeyboardEvent<HTMLButtonElement>) => {
		const index = path[path.length - 1] as number;
		const parentPath = path.slice(0, -1);
		if (event.key === ' ' || event.key === 'Enter') {
			event.preventDefault();
			if (isLifted) {
				ctx.setLifted(null);
				/* translators: %s: field label */
				ctx.announce(sprintf(__('Dropped %s.', 'proto-blocks'), label));
			} else {
				ctx.setLifted(id);
				/* translators: %s: field label */
				ctx.announce(sprintf(__('Picked up %s. Use the arrow keys to move it, Space to drop it.', 'proto-blocks'), label));
			}
			return;
		}
		if (!isLifted) {
			return;
		}
		if (event.key === 'Escape') {
			event.preventDefault();
			ctx.setLifted(null);
			/* translators: %s: field label */
			ctx.announce(sprintf(__('Dropped %s.', 'proto-blocks'), label));
			return;
		}
		const parent = parentFieldPath(path);
		const layoutIndex = path.length >= 3 && path[path.length - 3] === 'layouts' ? (path[path.length - 2] as number) : undefined;
		const siblings = fieldListAt(ctx.all, parent, layoutIndex);
		let to: FieldPath | null = null;
		if (event.key === 'ArrowUp' && index > 0) {
			to = [...parentPath, index - 1];
		} else if (event.key === 'ArrowDown' && index < siblings.length - 1) {
			to = [...parentPath, index + 1];
		} else if (event.key === 'ArrowRight' && index > 0 && hasSubFields(siblings[index - 1])) {
			to = [...parentPath, index - 1, fieldsOf(siblings[index - 1]).length];
		} else if (event.key === 'ArrowLeft' && parent !== null) {
			to = [...parent.slice(0, -1), (parent[parent.length - 1] as number) + 1];
		}
		if (event.key.startsWith('Arrow')) {
			event.preventDefault();
		}
		if (to) {
			ctx.dispatch({ type: 'move', from: path, to });
			/* translators: %s: field label */
			ctx.announce(sprintf(__('Moved %s.', 'proto-blocks'), label));
		}
	};

	return (
		<li
			ref={sortable.setNodeRef}
			style={{ transform: CSS.Transform.toString(sortable.transform), transition: sortable.transition }}
			className={cn('grid gap-1', sortable.isDragging && 'opacity-60')}
		>
			<div
				className={cn(
					'flex items-center gap-1 rounded-md border bg-card px-1.5 py-1 shadow-xs',
					selected && 'border-primary ring-2 ring-primary/20',
					isLifted && 'ring-2 ring-ring'
				)}
			>
				{ctx.readOnly ? null : (
					<button
						type="button"
						ref={(node) => {
							handleRef.current = node;
							sortable.setActivatorNodeRef(node);
						}}
						{...sortable.attributes}
						{...sortable.listeners}
						aria-roledescription={__('sortable', 'proto-blocks')}
						aria-label={sprintf(/* translators: %s: field label */ __('Reorder %s', 'proto-blocks'), label)}
						aria-describedby={ctx.instructionsId}
						aria-pressed={isLifted}
						onKeyDown={onHandleKey}
						className="grid size-7 shrink-0 cursor-grab place-items-center rounded text-muted-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
					>
						<GripVerticalIcon aria-hidden="true" className="size-4" />
					</button>
				)}
				{container ? (
					<button
						type="button"
						onClick={() => ctx.toggle(id)}
						aria-expanded={open}
						aria-label={sprintf(open ? /* translators: %s: field label */ __('Collapse %s', 'proto-blocks') : /* translators: %s: field label */ __('Expand %s', 'proto-blocks'), label)}
						className="grid size-7 shrink-0 place-items-center rounded text-muted-foreground hover:bg-muted"
					>
						{open ? <ChevronDownIcon aria-hidden="true" className="size-4" /> : <ChevronRightIcon aria-hidden="true" className="size-4" />}
					</button>
				) : null}
				<button
					type="button"
					id={`pb-row-${id}`}
					onClick={() => ctx.onSelect(path, id)}
					aria-current={selected ? 'true' : undefined}
					className="flex min-w-0 flex-1 items-center gap-2 rounded px-1.5 py-1 text-left text-sm hover:bg-muted/60"
				>
					<span className="truncate font-medium">{label}</span>
					{field.required && field.required !== '0' ? (
						<span className="text-destructive">
							<span aria-hidden="true">*</span>
							<span className="sr-only">{__('required', 'proto-blocks')}</span>
						</span>
					) : null}
					{field.name ? <span className="truncate font-mono text-xs text-muted-foreground">{String(field.name)}</span> : null}
					<Badge variant="secondary" className="ml-auto shrink-0">
						{typeLabel(field.type)}
					</Badge>
				</button>
				{ctx.readOnly ? null : (
					<>
						<Button
							type="button"
							variant="ghost"
							size="icon"
							className="size-7"
							onClick={() => ctx.dispatch({ type: 'duplicate', path })}
							aria-label={sprintf(/* translators: %s: field label */ __('Duplicate %s', 'proto-blocks'), label)}
						>
							<CopyIcon aria-hidden="true" />
						</Button>
						<Button
							type="button"
							variant="ghost"
							size="icon"
							className="size-7"
							onClick={() => ctx.dispatch({ type: 'remove', path })}
							aria-label={sprintf(/* translators: %s: field label */ __('Remove %s', 'proto-blocks'), label)}
						>
							<Trash2Icon aria-hidden="true" />
						</Button>
					</>
				)}
			</div>
			{container && open ? (
				<div className="ml-6 border-l pl-3">
					{hasSubFields(field) ? (
						<FieldList
							fields={fieldsOf(field)}
							prefix={path}
							ctx={ctx}
							label={sprintf(/* translators: %s: field label */ __('Sub-fields of %s', 'proto-blocks'), label)}
							addLabel={sprintf(/* translators: %s: field label */ __('Add field to %s', 'proto-blocks'), label)}
							onAdd={() => ctx.onAddField(path, undefined)}
						/>
					) : (
						<div className="grid gap-2">
							{layoutList(field).map((layout, l) => {
								const layoutLabel = String(layout.label || layout.name || '');
								return (
									<div key={String(layout.key ?? l)} className="grid gap-1 rounded-md border border-dashed p-2">
										<div className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
											<span>{layoutLabel}</span>
											<span className="font-mono">{String(layout.name ?? '')}</span>
											{ctx.readOnly ? null : (
												<Button
													type="button"
													variant="ghost"
													size="icon"
													className="ml-auto size-6"
													onClick={() => ctx.dispatch({ type: 'removeLayout', path, layout: l })}
													aria-label={sprintf(/* translators: %s: layout label */ __('Remove layout %s', 'proto-blocks'), layoutLabel)}
												>
													<XIcon aria-hidden="true" />
												</Button>
											)}
										</div>
										<FieldList
											fields={fieldsOf(layout)}
											prefix={[...path, 'layouts', l]}
											ctx={ctx}
											label={sprintf(/* translators: %s: layout label */ __('Fields of layout %s', 'proto-blocks'), layoutLabel)}
											addLabel={sprintf(/* translators: %s: layout label */ __('Add field to %s', 'proto-blocks'), layoutLabel)}
											onAdd={() => ctx.onAddField(path, l)}
										/>
									</div>
								);
							})}
							{ctx.readOnly ? null : (
								<Button
									type="button"
									variant="outline"
									size="sm"
									className="justify-self-start"
									onClick={() => ctx.dispatch({ type: 'addLayout', path })}
									aria-label={sprintf(/* translators: %s: field label */ __('Add layout to %s', 'proto-blocks'), label)}
								>
									<PlusIcon aria-hidden="true" />
									{__('Add layout', 'proto-blocks')}
								</Button>
							)}
						</div>
					)}
				</div>
			) : null}
		</li>
	);
}

function FieldList({
	fields,
	prefix,
	ctx,
	label,
	addLabel,
	onAdd,
}: {
	fields: RawField[];
	prefix: FieldPath;
	ctx: TreeContext;
	label: string;
	addLabel?: string;
	onAdd?: () => void;
}) {
	const ids = fields.map((f, i) => rowId(f, [...prefix, i]));
	return (
		<div className="grid gap-1">
			<SortableContext items={ids} strategy={verticalListSortingStrategy}>
				<ul aria-label={label} className="grid gap-1">
					{fields.map((f, i) => (
						<Row key={ids[i]} field={f} path={[...prefix, i]} ctx={ctx} />
					))}
				</ul>
			</SortableContext>
			{onAdd && !ctx.readOnly ? (
				<Button type="button" variant="ghost" size="sm" className="justify-self-start text-muted-foreground" onClick={onAdd} aria-label={addLabel}>
					<PlusIcon aria-hidden="true" />
					{__('Add field', 'proto-blocks')}
				</Button>
			) : null}
		</div>
	);
}

/**
 * The group's fields as a nested, reorderable list. Drag a row by its grip
 * (pointer), or focus the grip and press Space to pick it up, ArrowUp /
 * ArrowDown to move it, ArrowRight to move it into the repeater or group
 * above it, ArrowLeft to move it out of its parent, and Space to drop it.
 * Every move is a `move` action; the tree itself is stateless apart from
 * collapse state.
 */
export function FieldTree(props: FieldTreeProps) {
	const { fields, dispatch } = props;
	const [lifted, setLifted] = useState<string | null>(null);
	const [message, setMessage] = useState('');
	const [collapsed, setCollapsed] = useState<Set<string>>(() => new Set());
	const instructionsId = useId();
	const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }));

	const paths = useMemo(() => {
		const map = new Map<string, FieldPath>();
		walkFields(fields, (f, path) => map.set(rowId(f, path), path));
		return map;
	}, [fields]);

	const ctx: TreeContext = {
		...props,
		lifted,
		setLifted,
		announce: setMessage,
		collapsed,
		toggle: (id) =>
			setCollapsed((prev) => {
				const next = new Set(prev);
				if (next.has(id)) {
					next.delete(id);
				} else {
					next.add(id);
				}
				return next;
			}),
		instructionsId,
		all: fields,
	};

	const onDragEnd = ({ active, over }: DragEndEvent) => {
		if (!over || active.id === over.id) {
			return;
		}
		const from = paths.get(String(active.id));
		const to = paths.get(String(over.id));
		if (from && to) {
			dispatch({ type: 'move', from, to });
		}
	};

	return (
		<div className="grid gap-2">
			<p id={instructionsId} className="text-xs text-muted-foreground">
				{__('Drag to reorder, or focus a grip and press Space, then the arrow keys (Right moves into the repeater above, Left moves out), then Space.', 'proto-blocks')}
			</p>
			<DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
				<FieldList
					fields={fields}
					prefix={[]}
					ctx={ctx}
					label={__('Fields', 'proto-blocks')}
				/>
			</DndContext>
			<div role="status" aria-live="polite" className="sr-only">
				{message}
			</div>
		</div>
	);
}
