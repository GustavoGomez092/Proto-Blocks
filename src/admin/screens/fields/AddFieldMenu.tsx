import { useState, type KeyboardEvent } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { SearchIcon } from 'lucide-react';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/admin/components/ui/dialog';
import { Input } from '@/admin/components/ui/input';
import type { FieldType } from '@/fields/model/types';
import { CATEGORY_LABELS, FIELD_TYPES, TYPE_ALIASES, TYPE_META, type TypeCategory } from './field-settings-spec';

export interface AddFieldMenuProps {
	open: boolean;
	onOpenChange: (open: boolean) => void;
	/** The canonical type picked; the caller dispatches `add`. */
	onPick: (type: FieldType) => void;
	/** Where the field goes, e.g. "Sub-fields of Agenda"; shown under the title. */
	target?: string;
}

const CATEGORIES = Object.keys(CATEGORY_LABELS) as TypeCategory[];

function matches(type: FieldType, query: string): boolean {
	if (query === '') {
		return true;
	}
	const q = query.toLowerCase();
	return [type, TYPE_META[type].label, ...(TYPE_ALIASES[type] ?? [])].some((s) => s.toLowerCase().includes(q));
}

/**
 * Command-palette style picker of every field type the server accepts
 * (TYPE_META), grouped Basic / Content / Choice / Relational / Layout, with
 * the ACF names each also accepts. Type to filter; arrow keys move between
 * types; Enter or click picks one.
 */
export function AddFieldMenu({ open, onOpenChange, onPick, target }: AddFieldMenuProps) {
	const [query, setQuery] = useState('');

	const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
		if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
			return;
		}
		const options = Array.from(event.currentTarget.querySelectorAll<HTMLButtonElement>('[data-type-option]'));
		if (options.length === 0) {
			return;
		}
		event.preventDefault();
		const at = options.indexOf(document.activeElement as HTMLButtonElement);
		const next = event.key === 'ArrowDown' ? (at + 1) % options.length : at <= 0 ? options.length - 1 : at - 1;
		options[next].focus();
	};

	const visible = FIELD_TYPES.filter((t) => matches(t, query.trim()));

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
			<DialogContent className="sm:max-w-xl" onKeyDown={onKeyDown}>
				<DialogHeader>
					<DialogTitle>{__('Add field', 'proto-blocks')}</DialogTitle>
					<DialogDescription>{target ?? __('Pick a field type.', 'proto-blocks')}</DialogDescription>
				</DialogHeader>
				<div className="relative">
					<SearchIcon aria-hidden="true" className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
					<Input type="search" aria-label={__('Search field types', 'proto-blocks')} className="pl-8" value={query} onChange={(e) => setQuery(e.target.value)} autoFocus />
				</div>
				<div className="grid max-h-[60vh] gap-3 overflow-y-auto">
					{CATEGORIES.map((category) => {
						const types = visible.filter((t) => TYPE_META[t].category === category);
						if (types.length === 0) {
							return null;
						}
						const headingId = `pb-add-field-${category}`;
						return (
							<div key={category} role="group" aria-labelledby={headingId}>
								<h3 id={headingId} className="mb-1 text-xs font-medium text-muted-foreground uppercase">
									{CATEGORY_LABELS[category]}
								</h3>
								<div className="grid grid-cols-2 gap-1">
									{types.map((type) => {
										const aliases = TYPE_ALIASES[type] ?? [];
										return (
											<button
												key={type}
												type="button"
												data-type-option=""
												onClick={() => {
													onPick(type);
													setQuery('');
												}}
												className="grid rounded-md px-2 py-1.5 text-left text-sm hover:bg-muted focus-visible:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
											>
												<span className="font-medium">{TYPE_META[type].label}</span>
												<span className="font-mono text-xs text-muted-foreground">{type}</span>
												{aliases.length > 0 ? (
													<span className="text-xs text-muted-foreground">
														{sprintf(/* translators: %s: comma-separated ACF type names */ __('Also accepts %s', 'proto-blocks'), aliases.join(', '))}
													</span>
												) : null}
											</button>
										);
									})}
								</div>
							</div>
						);
					})}
					{visible.length === 0 ? <p className="text-sm text-muted-foreground">{__('No field type matches.', 'proto-blocks')}</p> : null}
				</div>
			</DialogContent>
		</Dialog>
	);
}
