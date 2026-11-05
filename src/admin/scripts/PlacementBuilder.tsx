import { useId, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { PlusIcon, XIcon } from 'lucide-react';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Checkbox } from '@/admin/components/ui/checkbox';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/admin/components/ui/radio-group';
import { buildTarget, TARGET_KINDS, targetError } from './schema';
import type { Where } from './types';

/** Chips for one target list plus a "kind + value + Add" row. */
function TargetList({
	label,
	help,
	targets,
	onChange,
	readOnly,
	emptyText,
}: {
	label: string;
	help: string;
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
		<fieldset className="grid gap-2" aria-describedby={`${id}-help`}>
			<legend className="text-label font-medium">{label}</legend>
			<p id={`${id}-help`} className="text-caption text-muted-foreground">
				{help}
			</p>
			<ul className="flex flex-wrap gap-1.5" aria-label={label}>
				{targets.length === 0 ? <li className="text-caption text-muted-foreground">{emptyText}</li> : null}
				{targets.map((target) => (
					<li key={target}>
						<Badge variant="secondary" className="gap-1 font-mono">
							{target}
							{readOnly ? null : (
								<button
									type="button"
									className="cursor-pointer rounded-full"
									/* translators: %s: a placement target, e.g. page:about */
									aria-label={sprintf(__('Remove %s', 'proto-blocks'), target)}
									onClick={() => onChange(targets.filter((t) => t !== target))}
								>
									<XIcon aria-hidden="true" className="size-3" />
								</button>
							)}
						</Badge>
					</li>
				))}
			</ul>
			{readOnly ? null : (
				<div className="grid gap-1.5">
					<div className="flex flex-wrap items-center gap-2">
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
							className="h-[38px] rounded-md border border-input bg-background px-2 text-body"
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
								<Input
									id={`${id}-value`}
									value={value}
									placeholder={current.placeholder}
									className="w-56 font-mono"
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
						<Button type="button" variant="outline" size="sm" onClick={add}>
							<PlusIcon aria-hidden="true" />
							{/* translators: %s: Include or Exclude */}
							{sprintf(__('Add to %s', 'proto-blocks'), label.toLowerCase())}
						</Button>
					</div>
					{error ? (
						<p role="alert" className="text-caption text-destructive">
							{error}
						</p>
					) : null}
				</div>
			)}
		</fieldset>
	);
}

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
			<legend className="text-label font-medium">{label}</legend>
			<div className="flex flex-wrap gap-x-4 gap-y-2">
				{all.map((role) => (
					<div key={role.slug} className="flex items-center gap-2">
						<Checkbox
							id={`${id}-${role.slug}`}
							checked={selected.includes(role.slug)}
							disabled={readOnly}
							onCheckedChange={(checked) =>
								onChange(checked === true ? [...selected, role.slug] : selected.filter((slug) => slug !== role.slug))
							}
						/>
						<Label htmlFor={`${id}-${role.slug}`} className="font-normal">
							{role.name}
						</Label>
					</div>
				))}
			</div>
		</fieldset>
	);
}

/**
 * Spec §2 placement: include / exclude targets, logged-in state and roles.
 * A page is in scope when it matches any include entry and no exclude entry.
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
	const id = useId();
	const login = where.loggedIn === null ? 'any' : where.loggedIn ? 'in' : 'out';
	const conflict = where.loggedIn === false && where.roles.length > 0;

	return (
		<div className="grid gap-5">
			<TargetList
				label={__('Include', 'proto-blocks')}
				help={__('The script runs on pages that match any of these.', 'proto-blocks')}
				targets={where.include}
				emptyText={__('Nowhere: add a target or the script never runs.', 'proto-blocks')}
				readOnly={readOnly}
				onChange={(include) => onChange({ ...where, include })}
			/>
			<TargetList
				label={__('Exclude', 'proto-blocks')}
				help={__('…but never on pages that match any of these.', 'proto-blocks')}
				targets={where.exclude}
				emptyText={__('No exclusions.', 'proto-blocks')}
				readOnly={readOnly}
				onChange={(exclude) => onChange({ ...where, exclude })}
			/>
			<fieldset className="grid gap-2">
				<legend className="text-label font-medium">{__('Visitors', 'proto-blocks')}</legend>
				<RadioGroup
					value={login}
					disabled={readOnly}
					className="flex flex-wrap gap-4"
					onValueChange={(value) => onChange({ ...where, loggedIn: value === 'any' ? null : value === 'in' })}
				>
					{[
						['any', __('Anyone', 'proto-blocks')],
						['in', __('Logged in only', 'proto-blocks')],
						['out', __('Logged out only', 'proto-blocks')],
					].map(([value, text]) => (
						<div key={value} className="flex items-center gap-2">
							<RadioGroupItem id={`${id}-${value}`} value={value} />
							<Label htmlFor={`${id}-${value}`} className="font-normal">
								{text}
							</Label>
						</div>
					))}
				</RadioGroup>
			</fieldset>
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
					{__('"Only these roles" matches logged-in users, but "Logged out only" is chosen: the script would never run.', 'proto-blocks')}
				</p>
			) : null}
		</div>
	);
}
