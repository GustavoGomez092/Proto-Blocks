import { useId, useState, type ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { PlusIcon, XIcon } from 'lucide-react';
import { Checkbox } from '@/admin/components/ui/checkbox';
import { RadioGroup, SegmentedRadioItem, segmentedGroupClassName } from '@/admin/components/ui/radio-group';
import { cn } from '@/admin/lib/utils';
import { buildTarget, TARGET_KINDS, targetError, targetLabel } from './schema';
import type { Where } from './types';

/** Two-column row of the "Where it runs" card: title and help on the left (200px), controls on the right. */
function PlacementRow({ title, help, children }: { title: string; help: ReactNode; children: ReactNode }) {
	const id = useId();
	return (
		<div role="group" aria-labelledby={id} className="grid gap-3 border-b border-divider p-5 last:border-b-0 md:grid-cols-[200px_minmax(0,1fr)] md:gap-6">
			<div className="grid content-start gap-1">
				<span id={id} className="text-label font-medium">
					{title}
				</span>
				<p className="text-label text-muted-foreground">{help}</p>
			</div>
			<div className="grid min-w-0 content-start gap-3.5">{children}</div>
		</div>
	);
}

const compact = 'h-7 rounded-md border border-input bg-background px-2 text-label';

/** One chip row: the label, a chip per target (with remove), then a compact "kind + value + Add" control. */
function TargetList({
	label,
	tone,
	targets,
	onChange,
	readOnly,
	emptyText,
}: {
	label: string;
	tone: 'include' | 'exclude';
	targets: string[];
	onChange: (next: string[]) => void;
	readOnly: boolean;
	emptyText: string;
}) {
	const id = useId();
	const [kind, setKind] = useState(TARGET_KINDS[0].kind);
	const [value, setValue] = useState('');
	const [error, setError] = useState<string | null>(null);
	const current = TARGET_KINDS.find((k) => k.kind === kind) ?? TARGET_KINDS[0];

	const add = () => {
		const target = buildTarget(kind, value);
		const problem = targets.includes(target) ? __('This target is already in the list.', 'proto-blocks') : targetError(target);
		if (problem) {
			setError(problem);
			return;
		}
		onChange([...targets, target]);
		setValue('');
		setError(null);
	};

	return (
		<div className="grid gap-1.5">
			<div className="flex flex-wrap items-center gap-2">
				<span id={`${id}-label`} className="w-16 flex-none text-caption text-muted-foreground">
					{label}
				</span>
				<ul className="flex flex-wrap items-center gap-1.5" aria-labelledby={`${id}-label`}>
					{targets.length === 0 ? <li className="text-caption text-muted-foreground">{emptyText}</li> : null}
					{targets.map((target) => (
						<li
							key={target}
							title={target}
							className={cn(
								'inline-flex h-7 items-center gap-1.5 rounded-md pl-2.5 text-label font-medium',
								readOnly ? 'pr-2.5' : 'pr-1.5',
								tone === 'include' ? 'bg-brand-soft text-brand-ink' : 'bg-destructive-soft text-destructive'
							)}
						>
							{targetLabel(target)}
							{readOnly ? null : (
								<button
									type="button"
									className="grid size-[18px] cursor-pointer place-items-center rounded-sm hover:bg-background/60"
									/* translators: %s: a placement target, e.g. page:about */
									aria-label={sprintf(__('Remove %s', 'proto-blocks'), target)}
									onClick={() => onChange(targets.filter((t) => t !== target))}
								>
									<XIcon aria-hidden="true" className="size-[11px]" />
								</button>
							)}
						</li>
					))}
				</ul>
				{readOnly ? null : (
					<span className="inline-flex flex-wrap items-center gap-1.5">
						<label className="sr-only" htmlFor={`${id}-kind`}>
							{/* translators: %s: Include or Exclude */}
							{sprintf(__('%s target kind', 'proto-blocks'), label)}
						</label>
						<select
							id={`${id}-kind`}
							value={kind}
							onChange={(event) => {
								setKind(event.target.value);
								setError(null);
							}}
							className={cn(compact, 'max-w-[220px]')}
						>
							{TARGET_KINDS.map((k) => (
								<option key={k.kind} value={k.kind}>
									{k.label}
								</option>
							))}
						</select>
						{current.placeholder !== null ? (
							<>
								<label className="sr-only" htmlFor={`${id}-value`}>
									{/* translators: %s: Include or Exclude */}
									{sprintf(__('%s target value', 'proto-blocks'), label)}
								</label>
								<input
									id={`${id}-value`}
									value={value}
									placeholder={current.placeholder}
									className={cn(compact, 'w-40 font-mono', error && 'border-destructive')}
									aria-invalid={error ? true : undefined}
									onChange={(event) => setValue(event.target.value)}
									onKeyDown={(event) => {
										if (event.key === 'Enter') {
											event.preventDefault();
											add();
										}
									}}
								/>
							</>
						) : null}
						<button
							type="button"
							/* translators: %s: Include or Exclude */
							aria-label={sprintf(__('Add to %s', 'proto-blocks'), label.toLowerCase())}
							className="inline-flex h-7 cursor-pointer items-center gap-1.5 rounded-md border border-dashed border-input px-2.5 text-caption font-medium text-muted-foreground hover:border-primary hover:text-primary"
							onClick={add}
						>
							<PlusIcon aria-hidden="true" className="size-3" />
							{__('Add', 'proto-blocks')}
						</button>
					</span>
				)}
			</div>
			{error ? (
				<p role="alert" className="text-caption text-destructive md:pl-[72px]">
					{error}
				</p>
			) : null}
		</div>
	);
}

/** Role chip-checkboxes under a caption ("Never for these roles"). */
function RoleChecks({
	label,
	roles,
	selected,
	onChange,
	readOnly,
}: {
	label: string;
	roles: { slug: string; name: string }[];
	selected: string[];
	onChange: (next: string[]) => void;
	readOnly: boolean;
}) {
	const id = useId();
	const known = new Set(roles.map((r) => r.slug));
	const all = [...roles, ...selected.filter((slug) => !known.has(slug)).map((slug) => ({ slug, name: slug }))];
	return (
		<fieldset className="grid gap-2">
			<legend className="mb-2 text-caption text-muted-foreground">{label}</legend>
			<div className="flex flex-wrap gap-2">
				{all.map((role) => {
					const on = selected.includes(role.slug);
					return (
						<label
							key={role.slug}
							htmlFor={`${id}-${role.slug}`}
							className={cn(
								'inline-flex h-8 cursor-pointer items-center gap-2 rounded-md border px-2.5 text-label',
								on ? 'border-primary bg-code font-medium' : 'border-border bg-card',
								readOnly && 'cursor-not-allowed'
							)}
						>
							<Checkbox
								id={`${id}-${role.slug}`}
								checked={on}
								disabled={readOnly}
								className="size-3.5"
								onCheckedChange={(checked) =>
									onChange(checked === true ? [...selected, role.slug] : selected.filter((slug) => slug !== role.slug))
								}
							/>
							{role.name}
						</label>
					);
				})}
			</div>
		</fieldset>
	);
}

/**
 * Spec §2 placement: include / exclude targets, logged-in state and roles.
 * A page is in scope when it matches any include entry and no exclude entry.
 * Renders the two rows of the editor's "Where it runs" card: Pages, Visitors.
 */
export function PlacementBuilder({
	where,
	roles,
	onChange,
	readOnly = false,
}: {
	where: Where;
	roles: { slug: string; name: string }[];
	onChange: (next: Where) => void;
	readOnly?: boolean;
}) {
	const login = where.loggedIn === null ? 'any' : where.loggedIn ? 'in' : 'out';
	const conflict = where.loggedIn === false && where.roles.length > 0;

	return (
		<>
			<PlacementRow
				title={__('Pages', 'proto-blocks')}
				help={__('Runs where any include matches, unless an exclude matches.', 'proto-blocks')}
			>
				<TargetList
					label={__('Include', 'proto-blocks')}
					tone="include"
					targets={where.include}
					emptyText={__('Nowhere: add a target or the script never runs.', 'proto-blocks')}
					readOnly={readOnly}
					onChange={(include) => onChange({ ...where, include })}
				/>
				<TargetList
					label={__('Exclude', 'proto-blocks')}
					tone="exclude"
					targets={where.exclude}
					emptyText={__('No exclusions.', 'proto-blocks')}
					readOnly={readOnly}
					onChange={(exclude) => onChange({ ...where, exclude })}
				/>
			</PlacementRow>
			<PlacementRow title={__('Visitors', 'proto-blocks')} help={__('Role filters imply a logged-in visitor.', 'proto-blocks')}>
				<RadioGroup
					value={login}
					disabled={readOnly}
					aria-label={__('Logged-in state', 'proto-blocks')}
					className={segmentedGroupClassName}
					onValueChange={(value) => onChange({ ...where, loggedIn: value === 'any' ? null : value === 'in' })}
				>
					<SegmentedRadioItem value="any">{__('Anyone', 'proto-blocks')}</SegmentedRadioItem>
					<SegmentedRadioItem value="in">{__('Logged in', 'proto-blocks')}</SegmentedRadioItem>
					<SegmentedRadioItem value="out">{__('Logged out', 'proto-blocks')}</SegmentedRadioItem>
				</RadioGroup>
				<RoleChecks
					label={__('Only these roles (implies logged in)', 'proto-blocks')}
					roles={roles}
					selected={where.roles}
					readOnly={readOnly}
					onChange={(next) => onChange({ ...where, roles: next })}
				/>
				<RoleChecks
					label={__('Never for these roles', 'proto-blocks')}
					roles={roles}
					selected={where.excludeRoles}
					readOnly={readOnly}
					onChange={(next) => onChange({ ...where, excludeRoles: next })}
				/>
				{conflict ? (
					<p role="alert" className="text-caption text-destructive">
						{__('"Only these roles" matches logged-in users, but "Logged out" is chosen: the script would never run.', 'proto-blocks')}
					</p>
				) : null}
			</PlacementRow>
		</>
	);
}
