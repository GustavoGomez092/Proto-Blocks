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
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Card } from '@/admin/components/ui/card';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/admin/components/ui/table';
import { cn } from '@/admin/lib/utils';
import { interpolate } from '@/admin/lib/interpolate';

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

const code = (text: ReactNode) => <code className="font-mono text-[12.5px]">{text}</code>;

/** A labelled card section with the design guide's radius and shadow. */
function SectionCard({ className, ...props }: React.ComponentProps<typeof Card> & { 'aria-labelledby': string }) {
	return <Card role="region" className={cn('gap-0 rounded-lg py-0 shadow-xs', className)} {...props} />;
}

function CardHead({ id, title, description }: { id: string; title: string; description?: ReactNode }) {
	return (
		<div className="grid gap-1 p-5 pb-4">
			<h2 id={id} className="text-[15px] leading-[1.3] font-semibold text-balance">
				{title}
			</h2>
			{description ? <p className="text-[13px] text-muted-foreground">{description}</p> : null}
		</div>
	);
}

function ValueBadge({ value }: { value: boolean }) {
	return value ? (
		<Badge variant="outline" className="rounded-sm border-transparent bg-success-soft font-mono text-success">
			true
		</Badge>
	) : (
		<Badge variant="outline" className="rounded-sm font-mono">
			false
		</Badge>
	);
}

function NotSet() {
	return <span className="text-[13px] text-muted-foreground">{__('Not set', 'proto-blocks')}</span>;
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
		<SectionCard aria-labelledby="pb-system-general">
			<CardHead id="pb-system-general" title={__('General Settings', 'proto-blocks')} />
			<form
				noValidate
				onSubmit={submit}
				className="grid gap-4 px-5 pb-5 md:grid-cols-[200px_minmax(0,1fr)] md:gap-6"
			>
				<div className="grid content-start gap-1">
					<Label htmlFor="pb-category-name" className="text-sm font-medium">
						{__('Block category name', 'proto-blocks')}
					</Label>
					<p className="text-[13px] text-muted-foreground">
						{__('The heading your blocks sit under in the block inserter.', 'proto-blocks')}
					</p>
				</div>
				<div className="grid max-w-[420px] min-w-0 gap-2">
					<Input
						id="pb-category-name"
						placeholder={__('Proto Blocks', 'proto-blocks')}
						disabled={isSubmitting}
						aria-invalid={error ? true : undefined}
						aria-describedby={error ? 'pb-category-name-error pb-category-name-hint' : 'pb-category-name-hint'}
						{...form.register('categoryName')}
					/>
					{error ? (
						<p id="pb-category-name-error" className="text-[13px] text-destructive">
							{error}
						</p>
					) : null}
					<p id="pb-category-name-hint" className="text-[13px] text-muted-foreground">
						{__('Leave empty to use the default name "Proto Blocks".', 'proto-blocks')}
					</p>
					<div className="pt-1">
						<Button type="submit" size="sm" disabled={isSubmitting}>
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
				</div>
			</form>
		</SectionCard>
	);
}

function Configuration({ config, adminUrl }: { config: SystemData['config']; adminUrl: string }) {
	const rows: { name: string; help: ReactNode; value: ReactNode }[] = [
		{
			name: 'PROTO_BLOCKS_DEBUG',
			help: __('Detailed error messages in block output.', 'proto-blocks'),
			value: <ValueBadge value={config.debug} />,
		},
		{
			name: 'PROTO_BLOCKS_CACHE_ENABLED',
			help: __('Template caching for faster renders.', 'proto-blocks'),
			value: <ValueBadge value={config.cacheEnabled} />,
		},
		{
			name: 'PROTO_BLOCKS_EXAMPLE_BLOCKS',
			help: config.exampleBlocksFromConfig ? (
				__('Locks the Example Blocks switch.', 'proto-blocks')
			) : (
				<>
					{__('Locks the Example Blocks switch.', 'proto-blocks')}{' '}
					<a className="text-primary underline" href={`${adminUrl}?page=proto-blocks`}>
						{__('Managed from the Blocks page', 'proto-blocks')}
					</a>
				</>
			),
			value: config.exampleBlocksFromConfig ? <ValueBadge value={config.exampleBlocks} /> : <NotSet />,
		},
	];

	return (
		<SectionCard aria-labelledby="pb-system-config">
			<CardHead
				id="pb-system-config"
				title={__('Configuration', 'proto-blocks')}
				description={interpolate(
					/* translators: %s: wp-config.php */
					__('Constants set in %s. Read-only here.', 'proto-blocks'),
					code('wp-config.php')
				)}
			/>
			<div className="border-t">
				<Table>
					<TableHeader>
						<TableRow>
							<TableHead scope="col" className="px-5 text-[13px] text-muted-foreground">
								{__('Constant', 'proto-blocks')}
							</TableHead>
							<TableHead scope="col" className="px-5 text-[13px] text-muted-foreground">
								{__('Value', 'proto-blocks')}
							</TableHead>
						</TableRow>
					</TableHeader>
					<TableBody>
						{rows.map((row) => (
							<TableRow key={row.name}>
								<TableCell className="px-5 py-3 whitespace-normal">
									{code(row.name)}
									<p className="mt-1 text-[13px] text-muted-foreground">{row.help}</p>
								</TableCell>
								<TableCell className="px-5 py-3">{row.value}</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			</div>
		</SectionCard>
	);
}

function SystemInformation({ system }: { system: SystemData['system'] }) {
	const rowHead = 'h-auto w-44 px-5 py-3 align-top font-medium';

	return (
		<SectionCard aria-labelledby="pb-system-info">
			<CardHead id="pb-system-info" title={__('System Information', 'proto-blocks')} />
			<div className="border-t">
				<Table>
					<TableBody>
						<TableRow>
							<TableHead scope="row" className={rowHead}>
								{__('Plugin Version', 'proto-blocks')}
							</TableHead>
							<TableCell className="px-5 py-3 tabular-nums">{system.pluginVersion}</TableCell>
						</TableRow>
						<TableRow>
							<TableHead scope="row" className={rowHead}>
								{__('WordPress Version', 'proto-blocks')}
							</TableHead>
							<TableCell className="px-5 py-3 tabular-nums">{system.wpVersion}</TableCell>
						</TableRow>
						<TableRow>
							<TableHead scope="row" className={rowHead}>
								{__('PHP Version', 'proto-blocks')}
							</TableHead>
							<TableCell className="px-5 py-3 tabular-nums">{system.phpVersion}</TableCell>
						</TableRow>
						<TableRow>
							<TableHead scope="row" className={rowHead}>
								{__('Cache Directory', 'proto-blocks')}
							</TableHead>
							<TableCell className="px-5 py-3 whitespace-normal">
								<div className="flex flex-wrap items-center gap-2">
									<code className="font-mono text-[12.5px] break-all">{system.cacheDir}</code>
									{system.cacheDirWritable ? (
										<Badge
											variant="outline"
											className="rounded-sm border-transparent bg-success-soft text-success"
										>
											<CheckIcon aria-hidden="true" />
											{__('Writable', 'proto-blocks')}
										</Badge>
									) : (
										<Badge
											variant="outline"
											className="rounded-sm border-transparent bg-destructive-soft text-destructive"
										>
											<XIcon aria-hidden="true" />
											{__('Not writable', 'proto-blocks')}
										</Badge>
									)}
								</div>
								{system.cacheDirWritable ? null : (
									<p className="mt-1 text-[13px] text-muted-foreground">
										{__('Templates render uncached until this folder is writable by the web server.', 'proto-blocks')}
									</p>
								)}
							</TableCell>
						</TableRow>
					</TableBody>
				</Table>
			</div>
		</SectionCard>
	);
}

function LoadingState() {
	return (
		<div aria-busy="true" className="grid gap-6">
			<Card className="grid gap-3 rounded-lg p-5 shadow-xs">
				<Skeleton className="h-4 w-40" />
				<Skeleton className="h-9 max-w-[420px]" />
				<Skeleton className="h-8 w-20" />
			</Card>
			<div className="grid items-start gap-6 lg:grid-cols-2">
				<Skeleton className="h-56 rounded-lg" />
				<Skeleton className="h-56 rounded-lg" />
			</div>
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
			<div className="grid gap-6">
				<GeneralSettings categoryName={state.data.categoryName} onSaved={onSaved} />
				<div className="grid items-start gap-6 lg:grid-cols-2">
					<div className="min-w-0">
						<Configuration config={state.data.config} adminUrl={adminUrl} />
					</div>
					<div className="min-w-0">
						<SystemInformation system={state.data.system} />
					</div>
				</div>
			</div>
		);
	}

	return (
		<>
			<PageHeader
				title={__('System Status', 'proto-blocks')}
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
