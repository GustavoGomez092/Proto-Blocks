import { useId, useState, type FormEvent } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { CircleCheckIcon, CircleSlashIcon, LoaderCircleIcon } from 'lucide-react';
import { adminGet } from '@/admin/api';
import { Panel, PanelHeader } from '@/admin/components/shell/Panel';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Checkbox } from '@/admin/components/ui/checkbox';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/admin/components/ui/table';
import { asApiError, query } from './api';
import { actionLabel, stageLabel, traceReasonLabel } from './codes';
import type { TestResult } from './types';

/**
 * Live "which rule fires for this URL" check (GET admin/redirects/test?url=),
 * with the evaluation trace. "Test as a visitor" passes loggedIn=0 so login
 * and role conditions see a signed-out request.
 */
export function TestBox() {
	const inputId = useId();
	const visitorId = useId();
	const [url, setUrl] = useState('');
	const [visitor, setVisitor] = useState(false);
	const [result, setResult] = useState<TestResult | null>(null);
	const [error, setError] = useState<string | null>(null);
	const [busy, setBusy] = useState(false);

	const submit = async (event: FormEvent) => {
		event.preventDefault();
		if (url.trim() === '') {
			return;
		}
		setBusy(true);
		setError(null);
		try {
			setResult(await adminGet<TestResult>(`admin/redirects/test${query({ url: url.trim(), loggedIn: visitor ? '0' : null })}`));
		} catch (e) {
			setResult(null);
			setError(asApiError(e).message);
		} finally {
			setBusy(false);
		}
	};

	return (
		<Panel aria-labelledby="pb-redirects-test">
			<PanelHeader id="pb-redirects-test" title={__('Test a URL', 'proto-blocks')} description={__('See which rule fires, without redirecting.', 'proto-blocks')} />
			<form onSubmit={submit} className="flex flex-wrap items-end gap-2 px-6 py-4">
				<div className="grid min-w-[240px] flex-1 gap-1.5">
					<Label htmlFor={inputId}>{__('URL to test', 'proto-blocks')}</Label>
					<Input id={inputId} value={url} placeholder="/old-page?utm=1" className="font-mono" onChange={(event) => setUrl(event.target.value)} />
				</div>
				<div className="flex h-9 items-center gap-2">
					<Checkbox id={visitorId} checked={visitor} onCheckedChange={(checked) => setVisitor(checked === true)} />
					<Label htmlFor={visitorId} className="font-normal">
						{__('Test as a visitor (signed out)', 'proto-blocks')}
					</Label>
				</div>
				<Button type="submit" variant="outline" disabled={busy || url.trim() === ''}>
					{busy ? <LoaderCircleIcon aria-hidden="true" className="animate-spin motion-reduce:animate-none" /> : null}
					{__('Test', 'proto-blocks')}
				</Button>
			</form>
			<div aria-live="polite">
				{error ? <p className="px-6 pb-4 text-label text-destructive">{error}</p> : null}
				{result ? (
					<div className="grid gap-3 border-t px-6 py-4">
						{result.matched ? (
							<p className="flex flex-wrap items-center gap-2 text-body">
								<CircleCheckIcon aria-hidden="true" className="size-4 text-success" />
								{sprintf(
									/* translators: 1: rule id, 2: group slug, 3: action, 4: HTTP code or "—", 5: target or "—". */
									__('Rule %1$s in %2$s fires: %3$s %4$s → %5$s', 'proto-blocks'),
									result.ruleId ?? '',
									result.group ?? '',
									result.action ? actionLabel(result.action) : '',
									result.code !== null ? String(result.code) : '—',
									result.target ?? '—'
								)}
							</p>
						) : (
							<p className="flex items-center gap-2 text-body">
								<CircleSlashIcon aria-hidden="true" className="size-4 text-muted-foreground" />
								{__('No rule fires for this URL.', 'proto-blocks')}
							</p>
						)}
						{result.matched && (result.stage || result.reason) ? (
							<p className="flex flex-wrap items-center gap-2 text-label">
								{result.stage ? <Badge variant="secondary">{stageLabel(result.stage)}</Badge> : null}
								{result.reason ? <span>{traceReasonLabel(result.reason)}</span> : null}
							</p>
						) : null}
						{typeof result.loggedIn === 'boolean' ? (
							<p className="text-label text-muted-foreground">
								{result.loggedIn ? __('Login and role conditions were checked as you (signed in).', 'proto-blocks') : __('Login and role conditions were checked as a signed-out visitor.', 'proto-blocks')}
							</p>
						) : null}
						{result.note ? <p className="text-label text-muted-foreground">{result.note}</p> : null}
						{result.trace.length > 0 ? (
							<Table aria-label={__('Evaluation trace', 'proto-blocks')}>
								<TableHeader>
									<TableRow>
										<TableHead>{__('Stage', 'proto-blocks')}</TableHead>
										<TableHead>{__('Group', 'proto-blocks')}</TableHead>
										<TableHead>{__('Rule', 'proto-blocks')}</TableHead>
										<TableHead>{__('Result', 'proto-blocks')}</TableHead>
										<TableHead>{__('Reason', 'proto-blocks')}</TableHead>
									</TableRow>
								</TableHeader>
								<TableBody>
									{result.trace.map((step, i) => (
										<TableRow key={`${step.stage ?? ''}-${step.group}-${step.ruleId}-${i}`}>
											<TableCell className="text-label whitespace-nowrap">{step.stage ? stageLabel(step.stage) : '—'}</TableCell>
											<TableCell className="font-mono text-micro">{step.group}</TableCell>
											<TableCell className="font-mono text-micro">{step.ruleId}</TableCell>
											<TableCell>
												<Badge variant={step.result === 'matched' ? 'success' : 'secondary'}>{step.result === 'matched' ? __('Matched', 'proto-blocks') : __('Skipped', 'proto-blocks')}</Badge>
											</TableCell>
											<TableCell className="whitespace-normal text-label">{traceReasonLabel(step.reason)}</TableCell>
										</TableRow>
									))}
								</TableBody>
							</Table>
						) : null}
					</div>
				) : null}
			</div>
		</Panel>
	);
}
