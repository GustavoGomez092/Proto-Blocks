import { useEffect, useId, useMemo, useRef, useState, type KeyboardEvent, type MouseEvent, type ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { DndContext, PointerSensor, closestCenter, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core';
import { SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { ChevronDownIcon, ChevronRightIcon, CopyIcon, PlusIcon, Trash2Icon, XIcon } from 'lucide-react';
import { cn } from '@/admin/lib/utils';
import { canonicalType, hasSubFields, isFlexible, isObject, layoutList, walkFields, type BuilderAction, type FieldPath, type RawField } from './builder-state';
import { typeLabel } from './field-settings-spec';
import { BTN_ICON, BTN_ICON_DANGER, BTN_LINK } from './pb-ui';

export interface FieldTreeProps {
	/** The group's top-level fields, raw. */
	fields: RawField[];
	/** The selected field's row id (its key); with `renderPanel`, the open row. */
	selectedKey: string | null;
	/** A row (or its Edit / Close button) was activated. With `renderPanel` the caller toggles the open row. */
	onSelect: (path: FieldPath, key: string) => void;
	/** Receives `move`, `duplicate`, `remove` and `addLayout` actions. */
	dispatch: (action: BuilderAction) => void;
	/** "Add field" in a list: `parent` null for the top level; `layout` for a flexible layout. */
	onAddField: (parent: FieldPath | null, layout?: number) => void;
	readOnly?: boolean;
	/**
	 * The open row's settings, shown inline under it (an accordion). Without
	 * it the tree only marks the selected row and the caller shows settings
	 * elsewhere.
	 */
	renderPanel?: (field: RawField, path: FieldPath, key: string) => ReactNode;
}

/** A row's id: the field key, or its path when a field has none. */
export function rowId(field: RawField, path: FieldPath): string {
	return typeof field.key === 'string' && field.key !== '' ? field.key : `path:${path.join('.')}`;
}

/** The id of the row's inline settings panel (aria-controls). */
export const panelId = (key: string) => `pb-field-panel-${key.replace(/[^A-Za-z0-9_-]/g, '-')}`;

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

/** Nesting depth of a path: 0 at the top level; a layout's fields sit one level under its header. */
function depthOf(path: FieldPath): number {
	return path.length - 1 - path.filter((seg) => seg === 'layouts').length;
}

/** The design's five columns: order, label, name, type, actions. */
export const ROW_GRID = 'grid grid-cols-[56px_minmax(0,1.3fr)_minmax(0,1fr)_150px_150px] items-center px-5';
const INDENT = 28;

interface TreeContext extends FieldTreeProps {
	lifted: string | null;
	setLifted: (id: string | null) => void;
	announce: (message: string) => void;
	collapsed: Set<string>;
	toggle: (id: string) => void;
	instructionsId: string;
	/** Field lists by owner path, for keyboard moves. */
	all: RawField[];
	/** The order number shown for each top-level row (tabs have none). */
	order: Map<string, string>;
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

const stop = (event: MouseEvent) => event.stopPropagation();

/** A full-width row under a container: "Add field to …", a layout header, "Add layout". */
function SubRow({ depth, children, className }: { depth: number; children: ReactNode; className?: string }) {
	return (
		<li className={cn('flex min-h-10 items-center gap-2 border-0 border-b border-solid border-divider bg-background py-1 pr-5', className)} style={{ paddingLeft: 20 + 56 + depth * INDENT }}>
			{children}
		</li>
	);
}

function Row({ field, path, count, ctx }: { field: RawField; path: FieldPath; count: number; ctx: TreeContext }) {
	const id = rowId(field, path);
	const sortable = useSortable({ id, disabled: ctx.readOnly });
	const label = title(field);
	const container = hasSubFields(field) || isFlexible(field);
	const expanded = !ctx.collapsed.has(id);
	const selected = ctx.selectedKey === id;
	const accordion = ctx.renderPanel !== undefined;
	const open = accordion && selected;
	const isLifted = ctx.lifted === id;
	const required = Boolean(field.required) && field.required !== '0';
	const hasLogic = Array.isArray(field.conditional_logic) && field.conditional_logic.length > 0;
	const type = canonicalType(field.type);
	const isTab = type === 'tab';
	const depth = depthOf(path);
	const handleRef = useRef<HTMLButtonElement | null>(null);

	useEffect(() => {
		if (isLifted && document.activeElement !== handleRef.current) {
			handleRef.current?.focus();
		}
	});

	const activate = () => ctx.onSelect(path, id);

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
		// 1-based position and list size after the move, for the announcement.
		let position = 0;
		let size = 0;
		if (event.key === 'ArrowUp' && index > 0) {
			to = [...parentPath, index - 1];
			[position, size] = [index, siblings.length];
		} else if (event.key === 'ArrowDown' && index < siblings.length - 1) {
			to = [...parentPath, index + 1];
			[position, size] = [index + 2, siblings.length];
		} else if (event.key === 'ArrowRight' && index > 0 && hasSubFields(siblings[index - 1])) {
			const into = fieldsOf(siblings[index - 1]).length;
			to = [...parentPath, index - 1, into];
			[position, size] = [into + 1, into + 1];
		} else if (event.key === 'ArrowLeft' && parent !== null) {
			const at = parent[parent.length - 1] as number;
			const grand = parentFieldPath(parent);
			const grandLayout = parent.length >= 3 && parent[parent.length - 3] === 'layouts' ? (parent[parent.length - 2] as number) : undefined;
			to = [...parent.slice(0, -1), at + 1];
			[position, size] = [at + 2, fieldListAt(ctx.all, grand, grandLayout).length + 1];
		}
		if (event.key.startsWith('Arrow')) {
			event.preventDefault();
		}
		if (to) {
			ctx.dispatch({ type: 'move', from: path, to });
			ctx.announce(
				sprintf(
					/* translators: 1: field label, 2: new position, 3: number of fields in that list */
					__('Moved %1$s to position %2$d of %3$d.', 'proto-blocks'),
					label,
					position,
					size
				)
			);
		}
	};

	const order = depth > 0 ? '·' : (ctx.order.get(id) ?? '');

	return (
		<li
			ref={sortable.setNodeRef}
			style={{ transform: CSS.Transform.toString(sortable.transform), transition: sortable.transition }}
			className={cn('relative', sortable.isDragging && 'z-10 opacity-70 shadow-md')}
		>
			{/* The whole row toggles on click; the keyboard target is the role=button cell group. */}
			<div
				onClick={activate}
				className={cn(
					ROW_GRID,
					'min-h-12 cursor-pointer border-0 border-b border-solid border-divider transition-colors',
					selected ? 'bg-code' : 'bg-background hover:bg-subtle',
					isLifted && 'outline-2 -outline-offset-2 outline-ring'
				)}
			>
				<span className="flex items-center gap-2 text-faint">
					{ctx.readOnly ? (
						<span className="size-3.5" />
					) : (
						<button
							type="button"
							ref={(node) => {
								handleRef.current = node;
								sortable.setActivatorNodeRef(node);
							}}
							{...sortable.attributes}
							{...sortable.listeners}
							onClick={stop}
							aria-roledescription={__('sortable', 'proto-blocks')}
							aria-label={sprintf(/* translators: %s: field label */ __('Reorder %s', 'proto-blocks'), label)}
							aria-describedby={ctx.instructionsId}
							aria-pressed={isLifted}
							onKeyDown={onHandleKey}
							className="-ml-1 grid size-6 cursor-grab place-items-center rounded-sm border-0 bg-transparent p-0 text-faint outline-none hover:text-muted-foreground focus-visible:outline-2 focus-visible:outline-ring active:cursor-grabbing"
						>
							<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
								<circle cx="9" cy="6" r="1.6" />
								<circle cx="15" cy="6" r="1.6" />
								<circle cx="9" cy="12" r="1.6" />
								<circle cx="15" cy="12" r="1.6" />
								<circle cx="9" cy="18" r="1.6" />
								<circle cx="15" cy="18" r="1.6" />
							</svg>
						</button>
					)}
					<span aria-hidden="true" className="text-micro tabular-nums">
						{order}
					</span>
				</span>
				<div
					role="button"
					tabIndex={0}
					id={`pb-row-${id}`}
					aria-expanded={accordion ? open : undefined}
					aria-controls={open ? panelId(id) : undefined}
					aria-current={!accordion && selected ? 'true' : undefined}
					aria-label={
						sprintf(
							/* translators: 1: position, 2: number of fields in the list, 3: field label, 4: field type */
							__('Field %1$d of %2$d, %3$s, %4$s', 'proto-blocks'),
							(path[path.length - 1] as number) + 1,
							count,
							label,
							typeLabel(field.type)
						) + (required ? __(', required', 'proto-blocks') : '')
					}
					onKeyDown={(event) => {
						if (event.key === 'Enter' || event.key === ' ') {
							event.preventDefault();
							activate();
						}
					}}
					onClick={(event) => {
						event.stopPropagation();
						activate();
					}}
					className="col-span-3 grid min-h-12 grid-cols-subgrid items-center rounded-sm outline-none focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
				>
					<span className="flex min-w-0 items-center gap-2 pr-3" style={{ paddingLeft: depth * INDENT }}>
						{isTab ? <span aria-hidden="true" className="h-[18px] w-[3px] flex-none rounded-xs bg-brand" /> : null}
						<span className="min-w-0 flex-auto truncate font-medium text-foreground">{label}</span>
						{required ? (
							<span className="text-destructive">
								<span aria-hidden="true">*</span>
								<span className="sr-only">{__('required', 'proto-blocks')}</span>
							</span>
						) : null}
						{hasLogic ? (
							<span className="inline-flex items-center rounded-full bg-code px-1.5 text-eyebrow leading-[18px] font-medium text-link">
								<span aria-hidden="true">{__('if', 'proto-blocks')}</span>
								<span className="sr-only">{__('shown conditionally', 'proto-blocks')}</span>
							</span>
						) : null}
					</span>
					<code className="min-w-0 truncate pr-3 font-mono text-caption text-muted-foreground">{field.name ? String(field.name) : ''}</code>
					<span className={cn('truncate text-label', isTab ? 'text-brand-ink' : type === 'repeater' ? 'text-link' : 'text-muted-foreground')}>{typeLabel(field.type)}</span>
				</div>
				<span className="flex items-center justify-end gap-0.5 text-muted-foreground">
					{container ? (
						<button
							type="button"
							onClick={(event) => {
								event.stopPropagation();
								ctx.toggle(id);
							}}
							aria-expanded={expanded}
							aria-label={sprintf(
								expanded ? /* translators: %s: field label */ __('Collapse %s', 'proto-blocks') : /* translators: %s: field label */ __('Expand %s', 'proto-blocks'),
								label
							)}
							className={BTN_ICON}
						>
							{expanded ? <ChevronDownIcon aria-hidden="true" className="size-3.5" /> : <ChevronRightIcon aria-hidden="true" className="size-3.5" />}
						</button>
					) : null}
					{accordion ? (
						<button
							type="button"
							tabIndex={-1}
							onClick={(event) => {
								event.stopPropagation();
								activate();
							}}
							aria-label={sprintf(open ? /* translators: %s: field label */ __('Close %s', 'proto-blocks') : /* translators: %s: field label */ __('Edit %s', 'proto-blocks'), label)}
							className={cn(BTN_ICON, 'w-auto px-2 text-caption text-link')}
						>
							{open ? __('Close', 'proto-blocks') : __('Edit', 'proto-blocks')}
						</button>
					) : null}
					{ctx.readOnly ? null : (
						<>
							<button
								type="button"
								className={BTN_ICON}
								onClick={(event) => {
									event.stopPropagation();
									ctx.dispatch({ type: 'duplicate', path });
								}}
								aria-label={sprintf(/* translators: %s: field label */ __('Duplicate %s', 'proto-blocks'), label)}
							>
								<CopyIcon aria-hidden="true" className="size-3.5" />
							</button>
							<button
								type="button"
								className={BTN_ICON_DANGER}
								onClick={(event) => {
									event.stopPropagation();
									ctx.dispatch({ type: 'remove', path });
								}}
								aria-label={sprintf(/* translators: %s: field label */ __('Remove %s', 'proto-blocks'), label)}
							>
								<Trash2Icon aria-hidden="true" className="size-3.5" />
							</button>
						</>
					)}
				</span>
			</div>
			{open && ctx.renderPanel ? (
				<section
					id={panelId(id)}
					aria-label={sprintf(/* translators: %s: field label */ __('Field settings: %s', 'proto-blocks'), label)}
					className="border-0 border-b border-solid border-border bg-subtle pr-5 pb-5"
					style={{ paddingLeft: 20 + 56 + depth * INDENT }}
				>
					{ctx.renderPanel(field, path, id)}
				</section>
			) : null}
			{container && expanded ? (
				hasSubFields(field) ? (
					<FieldList
						fields={fieldsOf(field)}
						prefix={path}
						ctx={ctx}
						depth={depth + 1}
						label={sprintf(/* translators: %s: field label */ __('Sub-fields of %s', 'proto-blocks'), label)}
						addLabel={sprintf(/* translators: %s: field label */ __('Add field to %s', 'proto-blocks'), label)}
						onAdd={() => ctx.onAddField(path, undefined)}
					/>
				) : (
					<ul className="m-0 list-none p-0">
						{layoutList(field).map((layout, l) => {
							const layoutLabel = String(layout.label || layout.name || '');
							return (
								<li key={String(layout.key ?? l)} className="m-0">
									<ul className="m-0 list-none p-0">
										<SubRow depth={depth + 1} className="bg-subtle">
											<span className="font-mono text-eyebrow font-medium tracking-[.08em] text-muted-foreground uppercase">{__('Layout', 'proto-blocks')}</span>
											<span className="text-label font-medium">{layoutLabel}</span>
											<code className="font-mono text-caption text-muted-foreground">{String(layout.name ?? '')}</code>
											{ctx.readOnly ? null : (
												<button
													type="button"
													className={cn(BTN_ICON_DANGER, 'ml-auto')}
													onClick={() => ctx.dispatch({ type: 'removeLayout', path, layout: l })}
													aria-label={sprintf(/* translators: %s: layout label */ __('Remove layout %s', 'proto-blocks'), layoutLabel)}
												>
													<XIcon aria-hidden="true" className="size-3.5" />
												</button>
											)}
										</SubRow>
									</ul>
									<FieldList
										fields={fieldsOf(layout)}
										prefix={[...path, 'layouts', l]}
										ctx={ctx}
										depth={depth + 2}
										label={sprintf(/* translators: %s: layout label */ __('Fields of layout %s', 'proto-blocks'), layoutLabel)}
										addLabel={sprintf(/* translators: %s: layout label */ __('Add field to %s', 'proto-blocks'), layoutLabel)}
										onAdd={() => ctx.onAddField(path, l)}
									/>
								</li>
							);
						})}
						{ctx.readOnly ? null : (
							<SubRow depth={depth + 1}>
								<button
									type="button"
									className={BTN_LINK}
									onClick={() => ctx.dispatch({ type: 'addLayout', path })}
									aria-label={sprintf(/* translators: %s: field label */ __('Add layout to %s', 'proto-blocks'), label)}
								>
									<PlusIcon aria-hidden="true" className="size-[13px]" strokeWidth={2.5} />
									{__('Add layout', 'proto-blocks')}
								</button>
							</SubRow>
						)}
					</ul>
				)
			) : null}
		</li>
	);
}

function FieldList({
	fields,
	prefix,
	ctx,
	depth,
	label,
	addLabel,
	onAdd,
}: {
	fields: RawField[];
	prefix: FieldPath;
	ctx: TreeContext;
	depth: number;
	label: string;
	addLabel?: string;
	onAdd?: () => void;
}) {
	const ids = fields.map((f, i) => rowId(f, [...prefix, i]));
	return (
		<>
			<SortableContext items={ids} strategy={verticalListSortingStrategy}>
				<ul aria-label={label} className="m-0 list-none p-0">
					{fields.map((f, i) => (
						<Row key={ids[i]} field={f} path={[...prefix, i]} count={fields.length} ctx={ctx} />
					))}
				</ul>
			</SortableContext>
			{onAdd && !ctx.readOnly ? (
				<ul className="m-0 list-none p-0">
					<SubRow depth={depth}>
						<button type="button" className={BTN_LINK} onClick={onAdd} aria-label={addLabel}>
							<PlusIcon aria-hidden="true" className="size-[13px]" strokeWidth={2.5} />
							{__('Add field', 'proto-blocks')}
						</button>
					</SubRow>
				</ul>
			) : null}
		</>
	);
}

/**
 * The group's fields as a nested, reorderable table of rows (order, label,
 * name, type, actions). Drag a row by its grip (pointer), or focus the grip
 * and press Space to pick it up, ArrowUp / ArrowDown to move it, ArrowRight
 * to move it into the repeater or group above it, ArrowLeft to move it out
 * of its parent, and Space to drop it. Every move is a `move` action; the
 * tree itself is stateless apart from collapse state. With `renderPanel`,
 * the selected row opens inline as an accordion.
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

	const order = useMemo(() => {
		const map = new Map<string, string>();
		let n = 0;
		fields.forEach((f, i) => {
			if (canonicalType(f.type) !== 'tab') {
				n++;
				map.set(rowId(f, [i]), String(n));
			}
		});
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
		order,
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
		<div className="text-body text-foreground">
			<p id={instructionsId} className="sr-only">
				{__('Drag to reorder, or focus a grip and press Space, then the arrow keys (Right moves into the repeater above, Left moves out), then Space.', 'proto-blocks')}
			</p>
			<div aria-hidden="true" className={cn(ROW_GRID, 'h-[34px] border-0 border-b border-solid border-border bg-subtle text-micro font-medium text-muted-foreground')}>
				<span>{__('Order', 'proto-blocks')}</span>
				<span>{__('Label', 'proto-blocks')}</span>
				<span>{__('Name', 'proto-blocks')}</span>
				<span>{__('Type', 'proto-blocks')}</span>
				<span />
			</div>
			<DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
				<FieldList fields={fields} prefix={[]} ctx={ctx} depth={0} label={__('Fields', 'proto-blocks')} />
			</DndContext>
			<div role="status" aria-live="polite" className="sr-only">
				{message}
			</div>
		</div>
	);
}
