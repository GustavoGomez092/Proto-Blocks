import { __, sprintf } from '@wordpress/i18n';
import { PlusIcon, PowerOffIcon } from 'lucide-react';
import type { WriteReason } from '@/admin/bootstrap';
import { IssueList } from '@/admin/components/content-model/IssueList';
import { OriginBadge } from '@/admin/components/content-model/OriginBadge';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { ReadOnlyBanner, READ_ONLY_BANNER_ID } from '@/admin/components/shell/ReadOnlyBanner';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Badge } from '@/admin/components/ui/badge';
import { Button } from '@/admin/components/ui/button';
import { Card } from '@/admin/components/ui/card';
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
	const title = __('Options Pages', 'proto-blocks');
	const enabled = result?.moduleEnabled !== false;
	const createReadOnly = readOnly !== null || result?.createTarget.writable === false;
	const header = (
		<PageHeader
			title={title}
			description={__('Site-wide settings screens for things like contact details, tracking and footer content.', 'proto-blocks')}
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
			<div className="grid gap-6">
				{header}
				<ScreenError screen={title} error={error} onRetry={onRetry} />
			</div>
		);
	}
	if (!result) {
		return (
			<div className="grid gap-6" aria-busy="true">
				{header}
				<Card className="grid gap-3 p-4 shadow-xs">
					{[0, 1, 2].map((i) => (
						<Skeleton key={i} className="h-8 w-full" />
					))}
				</Card>
			</div>
		);
	}
	if (!enabled) {
		return (
			<div className="grid gap-6">
				{header}
				<Card className="flex flex-row items-center gap-3 px-6 py-6 shadow-xs">
					<PowerOffIcon aria-hidden="true" className="size-5 flex-none text-muted-foreground" />
					<p className="text-sm">{__('The Options Pages module is disabled by the proto_blocks_modules filter.', 'proto-blocks')}</p>
				</Card>
			</div>
		);
	}

	const issues = result.warnings.map((w) => ({ level: 'warning' as const, code: 'options_warning', message: w.message, source: w.source ?? '', slug: w.slug }));

	return (
		<div className="grid gap-6">
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
				<Card className="grid justify-items-center gap-2 px-6 py-8 text-center shadow-xs">
					<h2 className="text-[15px] font-semibold">{__('No options pages yet', 'proto-blocks')}</h2>
					<p className="text-[13px] text-muted-foreground">{__('Give editors one place for phone numbers, social links and tracking codes.', 'proto-blocks')}</p>
					<code className="rounded bg-muted px-2 py-1 font-mono text-[12.5px]">proto_options_page('Theme Settings');</code>
				</Card>
			) : (
				<Card className="py-0 shadow-xs" aria-busy={loading}>
					<Table>
						<TableHeader>
							<TableRow>
								<TableHead>{__('Page', 'proto-blocks')}</TableHead>
								<TableHead>{__('Menu', 'proto-blocks')}</TableHead>
								<TableHead>{__('Who can use it', 'proto-blocks')}</TableHead>
								<TableHead>{__('Storage', 'proto-blocks')}</TableHead>
								<TableHead>{__('Origin', 'proto-blocks')}</TableHead>
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
										<TableCell>
											<span className="grid">
												<span className="font-medium">{page.title}</span>
												<code className="font-mono text-[12px] text-muted-foreground">{page.slug}</code>
											</span>
										</TableCell>
										<TableCell>{page.menuLabel}</TableCell>
										<TableCell>
											<code className="font-mono text-[12.5px]">{page.capability}</code>
										</TableCell>
										<TableCell className="text-[13px]">{page.storage}</TableCell>
										<TableCell>
											<span className="inline-flex flex-wrap items-center gap-1.5">
												<OriginBadge origin={page.origin} label={page.originLabel} double={page.double} />
												{page.warnings.length > 0 ? (
													<Badge variant="outline" className="border-warning-border bg-warning-soft" title={page.warnings.join(' ')}>
														{__('Warning', 'proto-blocks')}
													</Badge>
												) : null}
											</span>
										</TableCell>
										<TableCell className="text-right whitespace-nowrap">
											{/* translators: 1: Edit or View, 2: page title */}
											<Button variant="ghost" size="sm" aria-label={sprintf(__('%1$s %2$s', 'proto-blocks'), edit, page.title)} onClick={() => onEdit(ownerOf(result, page.slug))}>
												{edit}
											</Button>
											<Button variant="ghost" size="sm" asChild>
												<a href={page.url}>{__('Open page', 'proto-blocks')}</a>
											</Button>
										</TableCell>
									</TableRow>
								);
							})}
						</TableBody>
					</Table>
				</Card>
			)}
			{result.external.length > 0 ? (
				<section aria-labelledby="pb-op-external" className="grid gap-3">
					<div>
						<h2 id="pb-op-external" className="text-[15px] font-semibold">
							{__('Other settings pages', 'proto-blocks')}
						</h2>
						<p className="text-[13px] text-muted-foreground">{__('Added by plugins, the theme or WordPress. Listed for reference; they are not edited here.', 'proto-blocks')}</p>
					</div>
					<Card className="py-0 shadow-xs">
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
											<code className="font-mono text-[12.5px]">{row.slug}</code>
										</TableCell>
										<TableCell>{externalLabel(row)}</TableCell>
									</TableRow>
								))}
							</TableBody>
						</Table>
					</Card>
				</section>
			) : null}
		</div>
	);
}
