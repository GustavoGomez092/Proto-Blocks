import { useId, useMemo, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { Controller, useForm, type Control } from 'react-hook-form';
import { LoaderCircleIcon, TriangleAlertIcon } from 'lucide-react';
import { getBootstrap } from '@/admin/bootstrap';
import { Button } from '@/admin/components/ui/button';
import { Checkbox } from '@/admin/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/admin/components/ui/dialog';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/admin/components/ui/select';
import { ACTIONS, actionLabel, codeMeaning, codesFor, conditionFields, conditionLabel, CONDITIONS, defaultCode, QUERY_MODES, queryLabel } from './codes';
import { newRuleId } from './edits';
import { effectiveDefaults, formFromRule, NEW_GROUP, ruleFromForm, ruleResolver, type RuleFormValues } from './rule-form';
import { groupSlugError, ruleWarnings, slugifyGroup } from './schema';
import { writableGroups } from './shared';
import type { FileRule, RedirectsIndex, Rule, RuleAction } from './types';

function siteHost(): string | undefined {
	try {
		return new URL(getBootstrap().adminUrl).hostname.toLowerCase();
	} catch {
		return undefined;
	}
}

function FieldError({ id, message }: { id: string; message?: string }) {
	return message ? (
		<p id={id} className="text-label text-destructive">
			{message}
		</p>
	) : null;
}

function Field({ id, label, error, hint, children }: { id: string; label: string; error?: string; hint?: ReactNode; children: ReactNode }) {
	return (
		<div className="grid gap-1.5">
			<Label htmlFor={id}>{label}</Label>
			{children}
			{hint ? (
				<p id={`${id}-hint`} className="text-caption text-muted-foreground">
					{hint}
				</p>
			) : null}
			<FieldError id={`${id}-error`} message={error} />
		</div>
	);
}

function describedBy(id: string, error?: string, hint = false): string | undefined {
	const ids = [hint ? `${id}-hint` : '', error ? `${id}-error` : ''].filter(Boolean);
	return ids.length > 0 ? ids.join(' ') : undefined;
}

function Check({ control, name, label }: { control: Control<RuleFormValues>; name: 'regex' | 'conditionRegex' | 'ignoreCase' | 'ignoreTrailingSlash' | 'log' | 'enabled'; label: string }) {
	const id = useId();
	return (
		<Controller
			control={control}
			name={name}
			render={({ field }) => (
				<div className="flex items-center gap-2">
					<Checkbox id={id} checked={field.value} onCheckedChange={(checked) => field.onChange(checked === true)} />
					<Label htmlFor={id} className="font-normal">
						{label}
					</Label>
				</div>
			)}
		/>
	);
}

function valueLabel(type: RuleFormValues['condition']): string {
	switch (type) {
		case 'role':
			return __('Role or capability', 'proto-blocks');
		case 'referrer':
			return __('Referrer', 'proto-blocks');
		case 'agent':
			return __('User agent', 'proto-blocks');
		case 'header':
			return __('Header value', 'proto-blocks');
		case 'cookie':
			return __('Cookie value', 'proto-blocks');
		case 'ip':
			return __('IP addresses or ranges', 'proto-blocks');
		case 'language':
			return __('Language tags', 'proto-blocks');
		case 'server':
			return __('Host', 'proto-blocks');
		case 'filter':
			return __('Filter hook name', 'proto-blocks');
		default:
			return __('Value', 'proto-blocks');
	}
}

/**
 * Add/edit dialog (spec §6): react-hook-form over the rule the form writes,
 * checked with the zod mirror of the schema so the messages match the
 * server's error codes. The condition select reveals its own fields; the code
 * select lists only the codes the action supports, with their meaning.
 */
export function RuleDialog({
	open,
	onOpenChange,
	index,
	rule,
	prefill,
	group,
	title,
	onSubmit,
}: {
	open: boolean;
	onOpenChange: (open: boolean) => void;
	index: RedirectsIndex;
	/** The rule being edited; null adds one. */
	rule: Rule | null;
	/** Values for a new rule (404 "Add redirect"). */
	prefill?: FileRule;
	/** Group to select; defaults to the rule's own (file) group or the first writable one. */
	group: string;
	title?: string;
	/** Resolves true when the rule was saved; the dialog then closes. */
	onSubmit: (values: RuleFormValues, rule: FileRule) => Promise<boolean>;
}) {
	const groups = writableGroups(index);
	const taken = index.groups.map((g) => g.slug);
	const defaultsFor = useMemo(
		() => (slug: string) => effectiveDefaults(index.settings?.defaults, index.groups.find((g) => g.slug === slug)?.defaults),
		[index]
	);
	const resolver = useMemo(
		() =>
			ruleResolver(defaultsFor, {
				siteHost: siteHost(),
				groupError: (values) => {
					if (values.group === NEW_GROUP) {
						return values.newGroupTitle.trim() === '' ? __('Enter a name for the new group.', 'proto-blocks') : groupSlugError(slugifyGroup(values.newGroupTitle), taken);
					}
					return values.group === '' ? __('Choose a group.', 'proto-blocks') : null;
				},
			}),
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[defaultsFor, taken.join(',')]
	);

	// Mounted per opening (the parents render it only while open), so the defaults are read once.
	const form = useForm<RuleFormValues>({ resolver, defaultValues: formFromRule(rule ?? prefill ?? null, defaultsFor(group), group) });
	const { errors, isSubmitting } = form.formState;

	const values = form.watch();
	const fields = conditionFields(values.condition);
	const codes = codesFor(values.action);
	const currentCode = values.code;
	const codeOptions = currentCode !== '' && !codes.includes(Number(currentCode)) ? [...codes, Number(currentCode)] : codes;
	const showTarget = values.action === 'url' || values.action === 'pass';
	const showElse = values.action === 'url' && (values.condition !== 'none' || values.else !== '');
	const warnings = ruleWarnings({ from: values.from, regex: values.regex });

	const ids = {
		from: useId(),
		to: useId(),
		else: useId(),
		code: useId(),
		action: useId(),
		condition: useId(),
		value: useId(),
		name: useId(),
		query: useId(),
		title: useId(),
		group: useId(),
		newGroup: useId(),
	};

	const submit = form.handleSubmit(async (submitted) => {
		const id = rule?.id ?? newRuleId(index.groups.flatMap((g) => g.rules.map((r) => r.id)));
		const slug = submitted.group === NEW_GROUP ? slugifyGroup(submitted.newGroupTitle) : submitted.group;
		const next = ruleFromForm(submitted, defaultsFor(slug), id, { headers: rule?.headers });
		if (await onSubmit(submitted, next)) {
			onOpenChange(false);
		}
	});

	const changeAction = (action: RuleAction) => {
		form.setValue('action', action);
		if (!codesFor(action).includes(Number(form.getValues('code')))) {
			const code = defaultCode(action);
			form.setValue('code', code === null ? '' : String(code));
		}
	};

	return (
		<Dialog open={open} onOpenChange={onOpenChange}>
			<DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-[640px]">
				<form onSubmit={submit} noValidate className="grid gap-5">
					<DialogHeader>
						<DialogTitle>{title ?? (rule ? __('Edit redirect', 'proto-blocks') : __('Add redirect', 'proto-blocks'))}</DialogTitle>
						<DialogDescription>
							{rule?.origin === 'php'
								? __('This rule is declared in PHP. Saving writes it into the chosen group file, which then wins; delete the PHP line afterwards.', 'proto-blocks')
								: __('Saved into the group file in the theme, so it is tracked in git.', 'proto-blocks')}
						</DialogDescription>
					</DialogHeader>

					<div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
						<Field id={ids.from} label={__('Source', 'proto-blocks')} error={errors.from?.message} hint={__('A path such as /old-page, or a regular expression.', 'proto-blocks')}>
							<Input id={ids.from} autoComplete="off" className="font-mono" aria-invalid={errors.from ? true : undefined} aria-describedby={describedBy(ids.from, errors.from?.message, true)} {...form.register('from')} />
						</Field>
						<div className="sm:pt-7">
							<Check control={form.control} name="regex" label={__('Regex', 'proto-blocks')} />
						</div>
					</div>
					{warnings.map((warning) => (
						<p key={warning.code} role="status" className="-mt-2 flex items-center gap-1.5 text-label text-warning">
							<TriangleAlertIcon aria-hidden="true" className="size-4" />
							{warning.message}
						</p>
					))}

					<div className="grid gap-4 sm:grid-cols-2">
						<Field id={ids.condition} label={__('Match condition', 'proto-blocks')}>
							<Controller
								control={form.control}
								name="condition"
								render={({ field }) => (
									<Select value={field.value} onValueChange={field.onChange}>
										<SelectTrigger id={ids.condition}>
											<SelectValue />
										</SelectTrigger>
										<SelectContent>
											<SelectItem value="none">{conditionLabel('none')}</SelectItem>
											{CONDITIONS.map((type) => (
												<SelectItem key={type} value={type}>
													{conditionLabel(type)}
												</SelectItem>
											))}
										</SelectContent>
									</Select>
								)}
							/>
						</Field>
						<Field id={ids.action} label={__('Action', 'proto-blocks')}>
							<Controller
								control={form.control}
								name="action"
								render={({ field }) => (
									<Select value={field.value} onValueChange={(value) => changeAction(value as RuleAction)}>
										<SelectTrigger id={ids.action}>
											<SelectValue />
										</SelectTrigger>
										<SelectContent>
											{ACTIONS.map((action) => (
												<SelectItem key={action} value={action}>
													{actionLabel(action)}
												</SelectItem>
											))}
										</SelectContent>
									</Select>
								)}
							/>
						</Field>
					</div>

					{fields.value || fields.name ? (
						<div className="grid gap-4 rounded-lg bg-muted px-4 py-3.5 sm:grid-cols-2">
							{fields.name ? (
								<Field id={ids.name} label={values.condition === 'cookie' ? __('Cookie name', 'proto-blocks') : __('Header name', 'proto-blocks')} error={errors.conditionName?.message}>
									<Input id={ids.name} autoComplete="off" aria-invalid={errors.conditionName ? true : undefined} aria-describedby={describedBy(ids.name, errors.conditionName?.message)} {...form.register('conditionName')} />
								</Field>
							) : null}
							{fields.value ? (
								<Field
									id={ids.value}
									label={valueLabel(values.condition)}
									error={errors.conditionValue?.message}
									hint={fields.list ? __('Separate entries with commas.', 'proto-blocks') : undefined}
								>
									<Input id={ids.value} autoComplete="off" aria-invalid={errors.conditionValue ? true : undefined} aria-describedby={describedBy(ids.value, errors.conditionValue?.message, fields.list)} {...form.register('conditionValue')} />
								</Field>
							) : null}
							{fields.regex ? (
								<div className="sm:col-span-2">
									<Check control={form.control} name="conditionRegex" label={__('Value is a regular expression', 'proto-blocks')} />
								</div>
							) : null}
						</div>
					) : null}

					{showTarget ? (
						<Field id={ids.to} label={__('Target', 'proto-blocks')} error={errors.to?.message} hint={values.action === 'pass' ? __('A path on this site; the request is rewritten internally.', 'proto-blocks') : __('A path or a full URL. $1…$9 insert regex captures.', 'proto-blocks')}>
							<Input id={ids.to} autoComplete="off" className="font-mono" aria-invalid={errors.to ? true : undefined} aria-describedby={describedBy(ids.to, errors.to?.message, true)} {...form.register('to')} />
						</Field>
					) : null}
					{showElse ? (
						<Field id={ids.else} label={__('Fallback target', 'proto-blocks')} error={errors.else?.message} hint={__('Used when the condition does not match. Leave empty to fall through.', 'proto-blocks')}>
							<Input id={ids.else} autoComplete="off" className="font-mono" aria-invalid={errors.else ? true : undefined} aria-describedby={describedBy(ids.else, errors.else?.message, true)} {...form.register('else')} />
						</Field>
					) : null}

					<div className="grid gap-4 sm:grid-cols-2">
						{codeOptions.length > 0 ? (
							<Field id={ids.code} label={__('Code', 'proto-blocks')} error={errors.code?.message}>
								<Controller
									control={form.control}
									name="code"
									render={({ field }) => (
										<Select value={field.value} onValueChange={field.onChange}>
											<SelectTrigger id={ids.code} aria-invalid={errors.code ? true : undefined} aria-describedby={describedBy(ids.code, errors.code?.message)}>
												<SelectValue />
											</SelectTrigger>
											<SelectContent>
												{codeOptions.map((code) => (
													<SelectItem key={code} value={String(code)}>
														{`${code} ${codeMeaning(code)}`}
													</SelectItem>
												))}
											</SelectContent>
										</Select>
									)}
								/>
							</Field>
						) : null}
						<Field id={ids.query} label={__('Query string', 'proto-blocks')}>
							<Controller
								control={form.control}
								name="query"
								render={({ field }) => (
									<Select value={field.value} onValueChange={field.onChange}>
										<SelectTrigger id={ids.query}>
											<SelectValue />
										</SelectTrigger>
										<SelectContent>
											{QUERY_MODES.map((mode) => (
												<SelectItem key={mode} value={mode}>
													{queryLabel(mode)}
												</SelectItem>
											))}
										</SelectContent>
									</Select>
								)}
							/>
						</Field>
					</div>

					<fieldset className="flex flex-wrap gap-x-6 gap-y-2">
						<legend className="sr-only">{__('Options', 'proto-blocks')}</legend>
						<Check control={form.control} name="ignoreCase" label={__('Ignore case', 'proto-blocks')} />
						<Check control={form.control} name="ignoreTrailingSlash" label={__('Ignore trailing slash', 'proto-blocks')} />
						<Check control={form.control} name="log" label={__('Log hits', 'proto-blocks')} />
						<Check control={form.control} name="enabled" label={__('Enabled', 'proto-blocks')} />
					</fieldset>

					<div className="grid gap-4 sm:grid-cols-2">
						<Field id={ids.title} label={__('Title', 'proto-blocks')} error={errors.title?.message}>
							<Input id={ids.title} autoComplete="off" maxLength={200} aria-invalid={errors.title ? true : undefined} aria-describedby={describedBy(ids.title, errors.title?.message)} {...form.register('title')} />
						</Field>
						<Field id={ids.group} label={__('Group', 'proto-blocks')} error={errors.group?.message}>
							<Controller
								control={form.control}
								name="group"
								render={({ field }) => (
									<Select value={field.value} onValueChange={field.onChange}>
										<SelectTrigger id={ids.group} aria-invalid={errors.group ? true : undefined}>
											<SelectValue placeholder={__('Choose a group', 'proto-blocks')} />
										</SelectTrigger>
										<SelectContent>
											{groups.map((g) => (
												<SelectItem key={g.slug} value={g.slug}>
													{g.title}
												</SelectItem>
											))}
											<SelectItem value={NEW_GROUP}>{__('New group…', 'proto-blocks')}</SelectItem>
										</SelectContent>
									</Select>
								)}
							/>
						</Field>
						{values.group === NEW_GROUP ? (
							<Field id={ids.newGroup} label={__('New group name', 'proto-blocks')} error={errors.newGroupTitle?.message}>
								<Input id={ids.newGroup} autoComplete="off" aria-invalid={errors.newGroupTitle ? true : undefined} aria-describedby={describedBy(ids.newGroup, errors.newGroupTitle?.message)} {...form.register('newGroupTitle')} />
							</Field>
						) : null}
					</div>
					{errors.root?.message ? (
						<p role="alert" className="text-label text-destructive">
							{errors.root.message}
						</p>
					) : null}

					<DialogFooter>
						<Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
							{__('Cancel', 'proto-blocks')}
						</Button>
						<Button type="submit" disabled={isSubmitting}>
							{isSubmitting ? <LoaderCircleIcon aria-hidden="true" className="animate-spin motion-reduce:animate-none" /> : null}
							{__('Save redirect', 'proto-blocks')}
						</Button>
					</DialogFooter>
				</form>
			</DialogContent>
		</Dialog>
	);
}
