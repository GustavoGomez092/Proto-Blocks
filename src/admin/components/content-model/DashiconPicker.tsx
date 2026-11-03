import { useEffect, useMemo, useRef, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { DASHICONS } from '@/admin/content-model/dashicons';
import { Input } from '@/admin/components/ui/input';

const COLUMNS = 8;
const NAV_KEYS = ['ArrowRight', 'ArrowLeft', 'ArrowUp', 'ArrowDown', 'Home', 'End'];

interface Group {
	label: string;
	names: string[];
}

/** Display groups, matched in order by the name after `dashicons-`. */
function groupDefs(): Array<{ label: string; test: RegExp }> {
	return [
		{ label: __('Welcome', 'proto-blocks'), test: /^(welcome|wordpress|pressthis)/ },
		{ label: __('Admin', 'proto-blocks'), test: /^(admin|dashboard|menu|networking|update|screenoptions|rest-api|site|external|plugins|sos|buddicons|hammer|index-card|database|layout)/ },
		{ label: __('Post formats', 'proto-blocks'), test: /^(format|embed|image|align|post-)/ },
		{ label: __('Editor', 'proto-blocks'), test: /^(editor|text|table|insert|code|columns|list|paragraph|textcolor|rtl|ltr|quote)/ },
		{ label: __('Media', 'proto-blocks'), test: /^(media|playlist|video|audio|camera|images)/ },
		{ label: __('Social', 'proto-blocks'), test: /^(facebook|twitter|googleplus|linkedin|instagram|youtube|share|rss|email|reddit|spotify|twitch|pinterest|amazon|whatsapp|xing|megaphone)/ },
	];
}

function groupIcons(names: readonly string[]): Group[] {
	const defs = groupDefs();
	const misc: Group = { label: __('Misc', 'proto-blocks'), names: [] };
	const groups: Group[] = defs.map((d) => ({ label: d.label, names: [] }));
	for (const name of names) {
		const bare = name.replace(/^dashicons-/, '');
		const at = defs.findIndex((d) => d.test.test(bare));
		(at === -1 ? misc : groups[at]).names.push(name);
	}
	return [...groups, misc].filter((g) => g.names.length > 0);
}

/** Search box plus a keyboard-navigable grid of every dashicon. */
export function DashiconPicker({ value, onSelect }: { value: string; onSelect: (name: string) => void }) {
	const [query, setQuery] = useState('');
	const [active, setActive] = useState<string | null>(null);
	const [focusTarget, setFocusTarget] = useState<string | null>(null);
	const gridRef = useRef<HTMLDivElement>(null);

	const groups = useMemo(() => {
		const q = query.trim().toLowerCase().replace(/^dashicons-/, '');
		return groupIcons(q === '' ? DASHICONS : DASHICONS.filter((n) => n.includes(q)));
	}, [query]);
	const flat = useMemo(() => groups.flatMap((g) => g.names), [groups]);
	const tabStop = active !== null && flat.includes(active) ? active : flat.includes(value) ? value : flat[0];

	useEffect(() => {
		if (focusTarget === null) {
			return;
		}
		gridRef.current?.querySelector<HTMLElement>(`[data-icon="${focusTarget}"]`)?.focus();
		setFocusTarget(null);
	}, [focusTarget]);

	const move = (name: string, key: string): void => {
		const gi = groups.findIndex((g) => g.names.includes(name));
		const group = groups[gi];
		const at = group.names.indexOf(name);
		const col = at % COLUMNS;
		let next: string | undefined;
		if (key === 'ArrowRight') {
			next = flat[flat.indexOf(name) + 1];
		} else if (key === 'ArrowLeft') {
			next = flat[flat.indexOf(name) - 1];
		} else if (key === 'ArrowDown') {
			if (at + COLUMNS < group.names.length) {
				next = group.names[at + COLUMNS];
			} else if (groups[gi + 1]) {
				const g = groups[gi + 1];
				next = g.names[Math.min(col, g.names.length - 1)];
			}
		} else if (key === 'ArrowUp') {
			if (at - COLUMNS >= 0) {
				next = group.names[at - COLUMNS];
			} else if (groups[gi - 1]) {
				const g = groups[gi - 1];
				const lastRow = Math.floor((g.names.length - 1) / COLUMNS) * COLUMNS;
				next = g.names[Math.min(lastRow + col, g.names.length - 1)];
			}
		} else if (key === 'Home') {
			next = flat[0];
		} else if (key === 'End') {
			next = flat[flat.length - 1];
		}
		if (next !== undefined) {
			setActive(next);
			setFocusTarget(next);
		}
	};

	return (
		<div className="grid gap-2">
			<Input
				type="search"
				value={query}
				autoComplete="off"
				placeholder={__('Search icons', 'proto-blocks')}
				aria-label={__('Search icons', 'proto-blocks')}
				onChange={(event) => setQuery(event.target.value)}
				onKeyDown={(event) => {
					if (event.key === 'ArrowDown' && flat.length > 0) {
						event.preventDefault();
						setFocusTarget(tabStop);
					}
				}}
			/>
			<p className="text-[13px] text-muted-foreground" aria-live="polite">
				{sprintf(_n('%d icon', '%d icons', flat.length, 'proto-blocks'), flat.length)}
			</p>
			<div ref={gridRef} className="max-h-72 overflow-y-auto pr-1">
				{flat.length === 0 ? (
					<p className="py-6 text-center text-sm text-muted-foreground">{__('No icons match.', 'proto-blocks')}</p>
				) : (
					groups.map((group) => (
						<section key={group.label} aria-label={group.label} className="mb-2">
							<h3 className="mb-1 text-xs font-medium text-muted-foreground">{group.label}</h3>
							<div className="grid grid-cols-8 gap-0.5">
								{group.names.map((name) => (
									<button
										key={name}
										type="button"
										data-icon={name}
										title={name}
										aria-label={name}
										aria-pressed={name === value}
										tabIndex={name === tabStop ? 0 : -1}
										className={`grid size-8 place-items-center rounded-md hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden ${
											name === value ? 'bg-primary text-primary-foreground hover:bg-primary' : ''
										}`}
										onFocus={() => setActive(name)}
										onClick={() => onSelect(name)}
										onKeyDown={(event) => {
											if (NAV_KEYS.includes(event.key)) {
												event.preventDefault();
												move(name, event.key);
											}
										}}
									>
										<span aria-hidden="true" className={`dashicons ${name}`} />
									</button>
								))}
							</div>
						</section>
					))
				)}
			</div>
		</div>
	);
}
