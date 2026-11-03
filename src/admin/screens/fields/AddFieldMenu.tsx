import { useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { SearchIcon } from 'lucide-react';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/admin/components/ui/dialog';
import type { FieldType } from '@/fields/model/types';
import { CATEGORY_LABELS, FIELD_TYPES, TYPE_ALIASES, TYPE_META, TYPE_TILE, type TypeCategory } from './field-settings-spec';
import { EYEBROW } from './pb-ui';

export interface AddFieldMenuProps {
	open: boolean;
	onOpenChange: (open: boolean) => void;
	/** The canonical type picked; the caller dispatches `add`. */
	onPick: (type: FieldType) => void;
	/** Where the field goes, e.g. "Adds after Stream URL in Basics"; shown in the footer. */
	target?: ReactNode;
}

const CATEGORIES = Object.keys(CATEGORY_LABELS) as TypeCategory[];
/** Tiles per row: ArrowUp / ArrowDown move by a row. */
const COLUMNS = 4;

/** Matches the type key, its label, its one-line help and every ACF name the server also accepts. */
export function matchesType(type: FieldType, query: string): boolean {
	if (query === '') {
		return true;
	}
	const q = query.toLowerCase();
	return [type, TYPE_META[type].label, TYPE_TILE[type], ...(TYPE_ALIASES[type] ?? [])].some((s) => s.toLowerCase().includes(q));
}

/**
 * The type picker: a 720 px dialog with a search field and every field type
 * the server accepts (TYPE_META) as tiles, grouped Basic / Content / Choice /
 * Relational / Layout. The search also matches the ACF type names
 * (schemas/field-type-aliases.json). Arrow keys move between tiles (Down
 * from the search goes to the first), Enter in the search or on a tile
 * adds, Escape closes and focus returns to the opener.
 */
export function AddFieldMenu({ open, onOpenChange, onPick, target }: AddFieldMenuProps) {
	const [query, setQuery] = useState('');
	const searchRef = useRef<HTMLInputElement | null>(null);
	// The opener, captured as the dialog opens (before it takes focus): Escape returns focus there.
	const openerRef = useRef<HTMLElement | null>(null);
	const wasOpen = useRef(false);
	const picked = useRef(false);
	if (open && !wasOpen.current) {
		openerRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
		picked.current = false;
	}
	wasOpen.current = open;
	const visible = FIELD_TYPES.filter((t) => matchesType(t, query.trim()));

	const pick = (type: FieldType) => {
		picked.current = true;
		onPick(type);
		setQuery('');
	};

	const tiles = (root: HTMLElement) => Array.from(root.querySelectorAll<HTMLButtonElement>('[data-type-option]'));

	const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
		const moves: Record<string, number> = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: COLUMNS, ArrowUp: -COLUMNS };
		const step = moves[event.key];
		if (step === undefined) {
			return;
		}
		const options = tiles(event.currentTarget);
		if (options.length === 0) {
			return;
		}
		const at = options.indexOf(document.activeElement as HTMLButtonElement);
		if (at === -1) {
			// From the search field only Down enters the grid; Left / Right keep moving the caret.
			if (event.key === 'ArrowDown') {
				event.preventDefault();
				options[0].focus();
			}
			return;
		}
		event.preventDefault();
		const next = at + step;
		if (next < 0) {
			searchRef.current?.focus();
			return;
		}
		options[Math.min(next, options.length - 1)].focus();
	};

	return (
		<Dialog
			open={open}
			onOpenChange={(next) => {
				if (!next) {
					setQuery('');
				}
				onOpenChange(next);
			}}
		>
			<DialogContent
				showCloseButton={false}
				className="w-[720px] max-w-[calc(100%-32px)] gap-0 overflow-hidden rounded-2xl border-0 p-0 font-sans shadow-[0_20px_40px_-12px_rgba(15,29,46,.4)] sm:max-w-[720px]"
				onKeyDown={onKeyDown}
				onOpenAutoFocus={(event) => {
					event.preventDefault();
					searchRef.current?.focus();
				}}
				onCloseAutoFocus={(event) => {
					// After a pick the caller focuses the new field; otherwise focus goes back to the opener.
					event.preventDefault();
					if (!picked.current) {
						openerRef.current?.focus();
					}
				}}
			>
				<DialogTitle className="sr-only">{__('Add field', 'proto-blocks')}</DialogTitle>
				<DialogDescription className="sr-only">{__('Pick a field type. Type to filter; arrow keys move between types.', 'proto-blocks')}</DialogDescription>
				<div className="flex items-center gap-3 border-0 border-b border-solid border-border px-5 py-4">
					<SearchIcon aria-hidden="true" className="size-4 flex-none text-muted-foreground" />
					<input
						ref={searchRef}
						type="search"
						aria-label={__('Search field types', 'proto-blocks')}
						placeholder={__('Search field types — text, image, repeater…', 'proto-blocks')}
						className="h-9 min-h-0 flex-1 appearance-none rounded-none border-0 bg-transparent p-0 text-section text-foreground shadow-none outline-none placeholder:text-faint focus:shadow-none [&::-webkit-search-cancel-button]:hidden"
						value={query}
						onChange={(e) => setQuery(e.target.value)}
						onKeyDown={(e) => {
							if (e.key === 'Enter' && visible.length > 0) {
								e.preventDefault();
								pick(visible[0]);
							}
						}}
					/>
					<kbd aria-hidden="true" className="rounded-sm border border-solid border-border px-1.5 py-0.5 font-mono text-eyebrow text-faint">
						esc
					</kbd>
				</div>
				<div className="grid max-h-[460px] gap-[18px] overflow-auto px-5 pt-4 pb-5">
					{CATEGORIES.map((category) => {
						const types = visible.filter((t) => TYPE_META[t].category === category);
						if (types.length === 0) {
							return null;
						}
						const headingId = `pb-add-field-${category}`;
						return (
							<div key={category} role="group" aria-labelledby={headingId} className="grid gap-2">
								<h3 id={headingId} className={EYEBROW}>
									{CATEGORY_LABELS[category]}
								</h3>
								<div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
									{types.map((type) => (
										<button
											key={type}
											type="button"
											data-type-option=""
											onClick={() => pick(type)}
											className="grid cursor-pointer gap-0.5 rounded-lg border border-solid border-border bg-background px-3 py-2.5 text-left outline-none hover:border-primary hover:bg-code focus-visible:border-primary focus-visible:bg-code focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
										>
											<span className="text-body font-medium text-foreground">{TYPE_META[type].label}</span>
											<span className="text-micro text-muted-foreground">{TYPE_TILE[type]}</span>
										</button>
									))}
								</div>
							</div>
						);
					})}
					{visible.length === 0 ? <p className="text-label text-muted-foreground">{__('No field type matches.', 'proto-blocks')}</p> : null}
				</div>
				<div className="flex items-center justify-between gap-4 border-0 border-t border-solid border-divider bg-subtle px-5 py-3 text-caption text-muted-foreground">
					<span>{target ?? __('Pick a field type.', 'proto-blocks')}</span>
					<span>{__('Also accepts ACF type names', 'proto-blocks')}</span>
				</div>
			</DialogContent>
		</Dialog>
	);
}
