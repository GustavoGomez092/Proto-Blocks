import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toast } from 'sonner';
import { CheckIcon, LoaderCircleIcon, XIcon } from 'lucide-react';
import { adminGet, adminPost, ApiError } from '@/admin/api';
import { getBootstrap } from '@/admin/bootstrap';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { ReadOnlyBanner } from '@/admin/components/shell/ReadOnlyBanner';
import { Panel } from '@/admin/components/shell/Panel';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { cn } from '@/admin/lib/utils';

/** GET/POST admin/system (includes/API/Admin/SystemRoutes.php). */
export interface SystemData {
	categoryName: string;
	config: {
		debug: boolean;
		cacheEnabled: boolean;
		exampleBlocks: boolean;
		/** True when PROTO_BLOCKS_EXAMPLE_BLOCKS is defined in wp-config.php. */
		exampleBlocksFromConfig: boolean;
	};
	system: {
		pluginVersion: string;
		wpVersion: string;
		phpVersion: string;
		/** Relative to the WordPress root when inside it. */
		cacheDir: string;
		cacheDirWritable: boolean;
	};
}

const CATEGORY_NAME_MAX = 100;


function ValueChip({ value }: { value: boolean }) {
	return (
		<code
			className={cn(
				'rounded-sm px-2 py-0.5 font-mono text-micro',
				value ? 'bg-success-soft text-success' : 'bg-muted text-muted-foreground'
			)}
		>
			{value ? 'true' : 'false'}
		</code>
	);
}

function NotSet() {
	return <span className="text-label text-muted-foreground">{__('Not set', 'proto-blocks')}</span>;
}

function GeneralSettings({ categoryName, onSaved }: { categoryName: string; onSaved: (data: SystemData) => void }) {
	const schema = z.object({
		categoryName: z.string().max(CATEGORY_NAME_MAX, __('Use 100 characters or fewer.', 'proto-blocks')),
	});
	type Values = z.infer<typeof schema>;

	const form = useForm<Values>({
		resolver: zodResolver(schema),
		defaultValues: { categoryName },
	});
	const { errors, isSubmitting } = form.formState;

	const submit = form.handleSubmit(async (values) => {
		try {
			const data = await adminPost<SystemData>('admin/system', { categoryName: values.categoryName });
			form.reset({ categoryName: data.categoryName });
			onSaved(data);
			toast.success(__('Category name saved.', 'proto-blocks'));
		} catch (error) {
			toast.error(__("Couldn't save the category name.", 'proto-blocks'), {
				description: error instanceof Error ? error.message : undefined,
			});
		}
	});

	const error = errors.categoryName?.message;

	return (
		<Panel aria-labelledby="pb-system-general" className="grid gap-4 p-6">
			<h2 id="pb-system-general" className="text-section font-semibold">
				{__('General', 'proto-blocks')}
			</h2>
			<form noValidate onSubmit={submit} className="grid gap-1.5">
				<Label htmlFor="pb-category-name">{__('Block category name', 'proto-blocks')}</Label>
				<div className="flex gap-2">
					<Input
						id="pb-category-name"
						className="flex-1"
						placeholder={__('Proto Blocks', 'proto-blocks')}
						disabled={isSubmitting}
						aria-invalid={error ? true : undefined}
						aria-describedby={error ? 'pb-category-name-error pb-category-name-hint' : 'pb-category-name-hint'}
						{...form.register('categoryName')}
					/>
					<Button type="submit" className="h-[38px] px-4" disabled={isSubmitting}>
						{isSubmitting ? (
							<>
								<LoaderCircleIcon aria-hidden="true" className="animate-spin motion-reduce:animate-none" />
								{__('Saving…', 'proto-blocks')}
							</>
						) : (
							__('Save', 'proto-blocks')
						)}
					</Button>
				</div>
				{error ? (
					<p id="pb-category-name-error" className="text-label text-destructive">
						{error}
					</p>
				) : null}
				<p id="pb-category-name-hint" className="text-label text-muted-foreground">
					{__('The heading your blocks sit under in the inserter. Leave empty for “Proto Blocks”.', 'proto-blocks')}
				</p>
			</form>
		</Panel>
	);
}

function Configuration({ config, adminUrl }: { config: SystemData['config']; adminUrl: string }) {
	const rows: { name: string; help: ReactNode; value: ReactNode }[] = [
		{
			name: 'PROTO_BLOCKS_DEBUG',
			help: __('Detailed error messages in block output.', 'proto-blocks'),
			value: <ValueChip value={config.debug} />,
		},
		{
			name: 'PROTO_BLOCKS_CACHE_ENABLED',
			help: __('Template caching for faster renders.', 'proto-blocks'),
			value: <ValueChip value={config.cacheEnabled} />,
		},
		{
			name: 'PROTO_BLOCKS_EXAMPLE_BLOCKS',
			help: config.exampleBlocksFromConfig ? (
				__('Locks the Example Blocks switch.', 'proto-blocks')
			) : (
				<>
					{__('Locks the Example Blocks switch.', 'proto-blocks')}{' '}
					<a href={`${adminUrl}?page=proto-blocks`}>{__('Managed from the Blocks page', 'proto-blocks')}</a>
				</>
			),
			value: config.exampleBlocksFromConfig ? <ValueChip value={config.exampleBlocks} /> : <NotSet />,
		},
	];

	return (
		<Panel aria-labelledby="pb-system-config">
			<div className="flex items-baseline justify-between gap-4 px-6 pt-5 pb-3">
				<h2 id="pb-system-config" className="text-section font-semibold">
					{__('wp-config constants', 'proto-blocks')}
				</h2>
				<span className="text-caption text-muted-foreground">{__('Read-only here', 'proto-blocks')}</span>
			</div>
			<dl>
				{rows.map((row) => (
					<div
						key={row.name}
						data-constant={row.name}
						className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-1 border-t border-divider px-6 py-3.5"
					>
						<dt>
							<code className="font-mono text-caption break-all text-link">{row.name}</code>
						</dt>
						<dd className="row-span-2 self-center">{row.value}</dd>
						<p className="text-label text-muted-foreground">{row.help}</p>
					</div>
				))}
			</dl>
		</Panel>
	);
}

function SystemInformation({ system, fileModsAllowed }: { system: SystemData['system']; fileModsAllowed: boolean }) {
	const row = 'flex items-baseline justify-between gap-4 border-t border-divider px-6 py-[13px]';
	const term = 'text-label text-muted-foreground';

	return (
		<Panel aria-labelledby="pb-system-info">
			<div className="px-6 pt-5 pb-3">
				<h2 id="pb-system-info" className="text-section font-semibold">
					{__('Environment', 'proto-blocks')}
				</h2>
			</div>
			<dl>
				<div className={row}>
					<dt className={term}>{__('Plugin', 'proto-blocks')}</dt>
					<dd className="tabular-nums">{system.pluginVersion}</dd>
				</div>
				<div className={row}>
					<dt className={term}>{__('WordPress', 'proto-blocks')}</dt>
					<dd className="tabular-nums">{system.wpVersion}</dd>
				</div>
				<div className={row}>
					<dt className={term}>{__('PHP', 'proto-blocks')}</dt>
					<dd className="tabular-nums">{system.phpVersion}</dd>
				</div>
				<div className="grid gap-2 border-t border-divider px-6 py-[13px]">
					<div className="flex items-center justify-between gap-4">
						<dt className={term}>{__('Cache directory', 'proto-blocks')}</dt>
						<dd>
							{system.cacheDirWritable ? (
								<Badge variant="success">
									<CheckIcon aria-hidden="true" strokeWidth={2.5} />
									{__('Writable', 'proto-blocks')}
								</Badge>
							) : (
								<Badge variant="danger">
									<XIcon aria-hidden="true" strokeWidth={2.5} />
									{__('Not writable', 'proto-blocks')}
								</Badge>
							)}
						</dd>
					</div>
					<code className="font-mono text-caption break-all text-link">{system.cacheDir}</code>
					{system.cacheDirWritable ? null : (
						<p className="text-label text-muted-foreground">
							{__('Templates render uncached until this folder is writable by the web server.', 'proto-blocks')}
						</p>
					)}
				</div>
				<div className={row}>
					<dt className={term}>{__('File modifications', 'proto-blocks')}</dt>
					<dd className="text-label">
						{fileModsAllowed ? __('Allowed', 'proto-blocks') : __('Disallowed', 'proto-blocks')}
					</dd>
				</div>
			</dl>
		</Panel>
	);
}

function LoadingState() {
	return (
		<div aria-busy="true" className="grid items-start gap-6 lg:grid-cols-2">
			<div className="grid gap-6">
				<Skeleton className="h-40 rounded-xl" />
				<Skeleton className="h-56 rounded-xl" />
			</div>
			<Skeleton className="h-80 rounded-xl" />
		</div>
	);
}

type LoadState = { status: 'loading' } | { status: 'error'; error: unknown } | { status: 'ready'; data: SystemData };

export default function SystemScreen() {
	const { adminUrl, fileModsAllowed } = getBootstrap();
	const [state, setState] = useState<LoadState>({ status: 'loading' });

	const load = useCallback(() => {
		setState({ status: 'loading' });
		adminGet<SystemData>('admin/system').then(
			(data) => {
				if (data) {
					setState({ status: 'ready', data });
				}
			},
			(error: unknown) => setState({ status: 'error', error })
		);
	}, []);

	useEffect(load, [load]);

	const onSaved = useCallback((data: SystemData) => setState({ status: 'ready', data }), []);

	let body: ReactNode;
	if (state.status === 'loading') {
		body = <LoadingState />;
	} else if (state.status === 'error') {
		body = (
			<ScreenError
				screen={__('System Status', 'proto-blocks')}
				error={state.error instanceof ApiError ? state.error : undefined}
				onRetry={load}
			/>
		);
	} else {
		body = (
			<div className="grid items-start gap-6 lg:grid-cols-2">
				<div className="grid min-w-0 gap-6">
					<GeneralSettings categoryName={state.data.categoryName} onSaved={onSaved} />
					<Configuration config={state.data.config} adminUrl={adminUrl} />
				</div>
				<SystemInformation system={state.data.system} fileModsAllowed={fileModsAllowed} />
			</div>
		);
	}

	return (
		<>
			<PageHeader
				eyebrow={__('Diagnostics', 'proto-blocks')}
				title={__('System status', 'proto-blocks')}
				description={__('Plugin settings, wp-config constants and server details.', 'proto-blocks')}
			/>
			{fileModsAllowed ? null : (
				<div className="pb-6">
					<ReadOnlyBanner
						reason="file_mods"
						description={__('Nothing on this screen writes files, so all of it keeps working.', 'proto-blocks')}
					/>
				</div>
			)}
			{body}
		</>
	);
}
