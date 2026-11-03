import { __, _n, sprintf } from '@wordpress/i18n';
import { ChevronDownIcon, PlusIcon, PowerOffIcon, TriangleAlertIcon } from 'lucide-react';
import type { WriteReason } from '@/admin/bootstrap';
import { IssueList } from '@/admin/components/content-model/IssueList';
import { EmptyCallout } from '@/admin/components/content-model/Layout';
import { DoubleBadge, ORIGIN_TONES } from '@/admin/components/content-model/OriginBadge';
import { Panel } from '@/admin/components/shell/Panel';
import { Dot } from '@/admin/components/shell/Signals';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { ReadOnlyBanner, READ_ONLY_BANNER_ID } from '@/admin/components/shell/ReadOnlyBanner';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/admin/components/ui/table';
import type { ExternalRow, OptionsIndex } from './declaration';

function externalLabel(row: ExternalRow): string {
	switch (row.origin) {
		case 'plugin':
		case 'theme':
			// The server sends the full label ("Plugin: woocommerce").
			return row.originLabel;
		case 'core':
			return __('WordPress', 'proto-blocks');
		default:
			return __('Unknown', 'proto-blocks');
	}
}

/** The page a row edits: a child page is edited through its parent's file. */
export function ownerOf(index: OptionsIndex, slug: string): string {
	return index.pages.find((page) => page.children.includes(slug))?.slug ?? slug;
}

/** E1: every Proto-Blocks options page, then other plugins' settings pages (read-only). */
export function OptionsPageList({
	result,
	loading,
	error,
	readOnly,
	onRetry,
	onEdit,
	onCreate,
}: {
	result: OptionsIndex | null;
	loading: boolean;
	error: unknown;
	readOnly: WriteReason;
	onRetry: () => void;
	onEdit: (slug: string) => void;
	onCreate: () => void;
}) {
	const title = __('Options pages', 'proto-blocks');
	const enabled = result?.moduleEnabled !== false;
	const createReadOnly = readOnly !== null || result?.createTarget.writable === false;
	const header = (
		<PageHeader
			title={title}
			eyebrow={__('Content model', 'proto-blocks')}
			description={__('Site-wide settings screens — contact details, tracking, footer content.', 'proto-blocks')}
			actions={
				result && enabled ? (
					<Button onClick={onCreate} disabled={createReadOnly} aria-describedby={createReadOnly ? READ_ONLY_BANNER_ID : undefined}>
						<PlusIcon aria-hidden="true" />
						{__('New options page', 'proto-blocks')}
					</Button>
				) : null
			}
		/>
	);

	if (error && !result) {
		return (
			<div className="grid gap-6 [&>header]:mb-0">
				{header}
				<ScreenError screen={title} error={error} onRetry={onRetry} />
			</div>
		);
	}
	if (!result) {
		return (
			<div className="grid gap-6 [&>header]:mb-0" aria-busy="true">
				{header}
				<Panel className="grid gap-3 p-4">
					{[0, 1, 2].map((i) => (
						<Skeleton key={i} className="h-8 w-full" />
					))}
				</Panel>
			</div>
		);
	}
	if (!enabled) {
		return (
			<div className="grid gap-6 [&>header]:mb-0">
				{header}
				<Panel className="flex items-center gap-3 p-6">
					<PowerOffIcon aria-hidden="true" className="size-5 flex-none text-muted-foreground" />
					<p className="text-body">{__('The Options Pages module is disabled by the proto_blocks_modules filter.', 'proto-blocks')}</p>
				</Panel>
			</div>
		);
	}

	const issues = result.warnings.map((w) => ({ level: 'warning' as const, code: 'options_warning', message: w.message, source: w.source ?? '', slug: w.slug }));

	return (
		<div className="grid gap-6 [&>header]:mb-0">
			{header}
			{readOnly !== null ? (
				<ReadOnlyBanner
					reason={readOnly}
					description={__("You can't change how these pages are set up. Editors can still fill in and save their settings; those are stored in the database, not in files.", 'proto-blocks')}
				/>
			) : result.createTarget.writable === false ? (
				<ReadOnlyBanner reason="not_writable" path={result.createTarget.path} />
			) : null}
			<IssueList issues={issues} />
			{result.pages.length === 0 ? (
				<EmptyCallout
					icon={<PlusIcon />}
					title={__('No options pages yet', 'proto-blocks')}
					action={
						createReadOnly ? null : (
							<Button variant="outline" size="sm" onClick={onCreate}>
								{__('New options page', 'proto-blocks')}
							</Button>
						)
					}
				>
					{__('Give editors one place for phone numbers, social links and tracking codes.', 'proto-blocks')}{' '}
					<code className="font-mono text-micro text-link">proto_options_page('Theme Settings');</code>
				</EmptyCallout>
			) : (
				<Panel className="overflow-hidden" aria-busy={loading}>
					<Table>
						<TableHeader>
							<TableRow>
								<TableHead>{__('Page', 'proto-blocks')}</TableHead>
								<TableHead>{__('Menu placement', 'proto-blocks')}</TableHead>
								<TableHead>{__('Capability', 'proto-blocks')}</TableHead>
								<TableHead>{__('Storage', 'proto-blocks')}</TableHead>
								<TableHead>
									<span className="sr-only">{__('Actions', 'proto-blocks')}</span>
								</TableHead>
							</TableRow>
						</TableHeader>
						<TableBody>
							{result.pages.map((page) => {
								const edit = readOnly !== null || !page.editable ? __('View', 'proto-blocks') : __('Edit', 'proto-blocks');
								return (
									<TableRow key={page.slug}>
										<TableCell className="whitespace-normal">
											<div className="grid gap-0.5">
												<div className="flex flex-wrap items-center gap-x-2 gap-y-1">
													<Dot tone={ORIGIN_TONES[page.origin] ?? 'neutral'} className="text-body font-medium">
														{page.title}
													</Dot>
													<span className="sr-only">{page.originLabel}</span>
													{page.double ? (
														<DoubleBadge double={page.double} />
													) : null}
													{page.warnings.length > 0 ? (
														<Badge variant="warning" title={page.warnings.join(' ')}>
															<TriangleAlertIcon aria-hidden="true" />
															{__('Warning', 'proto-blocks')}
														</Badge>
													) : null}
												</div>
												<code className="ms-4 font-mono text-micro text-muted-foreground">{page.slug}</code>
											</div>
										</TableCell>
										<TableCell className="text-label">{page.menuLabel}</TableCell>
										<TableCell>
											<code className="font-mono text-micro text-link">{page.capability}</code>
										</TableCell>
										<TableCell className="text-label">{page.storage}</TableCell>
										<TableCell className="text-right whitespace-nowrap">
											<span className="inline-flex items-center justify-end gap-1.5">
												<Button variant="ghost" size="sm" asChild>
													<a href={page.url}>{__('Open page', 'proto-blocks')}</a>
												</Button>
												{/* translators: 1: Edit or View, 2: page title */}
												<Button variant="outline" size="sm" aria-label={sprintf(__('%1$s %2$s', 'proto-blocks'), edit, page.title)} onClick={() => onEdit(ownerOf(result, page.slug))}>
													{edit}
												</Button>
											</span>
										</TableCell>
									</TableRow>
								);
							})}
						</TableBody>
					</Table>
				</Panel>
			)}
			{result.external.length > 0 ? (
				<section aria-labelledby="pb-op-external">
					<details className="group rounded-xl border bg-card">
						<summary className="flex cursor-pointer list-none items-center justify-between gap-4 rounded-xl px-6 py-3.5 [&::-webkit-details-marker]:hidden">
							<span className="flex flex-wrap items-baseline gap-x-2.5">
								<h2 id="pb-op-external" className="text-body font-medium">
									{__('Other settings pages', 'proto-blocks')}
								</h2>
								<span className="text-label text-muted-foreground">
									{sprintf(
										/* translators: %d: number of settings pages added by WordPress, plugins or the theme. */
										_n('%d from WordPress, plugins and the theme · reference only', '%d from WordPress, plugins and the theme · reference only', result.external.length, 'proto-blocks'),
										result.external.length
									)}
								</span>
							</span>
							<ChevronDownIcon aria-hidden="true" className="size-4 flex-none text-muted-foreground transition-transform group-open:rotate-180" />
						</summary>
						<div className="border-t">
							<Table>
								<TableHeader>
									<TableRow>
										<TableHead>{__('Page', 'proto-blocks')}</TableHead>
										<TableHead>{__('Slug', 'proto-blocks')}</TableHead>
										<TableHead>{__('Added by', 'proto-blocks')}</TableHead>
									</TableRow>
								</TableHeader>
								<TableBody>
									{result.external.map((row) => (
										<TableRow key={`${row.parent}:${row.slug}`}>
											<TableCell className="font-medium">{row.title}</TableCell>
											<TableCell>
												<code className="font-mono text-micro text-muted-foreground">{row.slug}</code>
											</TableCell>
											<TableCell className="text-label">{externalLabel(row)}</TableCell>
										</TableRow>
									))}
								</TableBody>
							</Table>
						</div>
					</details>
				</section>
			) : null}
		</div>
	);
}
