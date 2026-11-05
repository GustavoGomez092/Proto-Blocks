import { lazy, Suspense, useCallback, useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { PauseCircleIcon, PowerOffIcon } from 'lucide-react';
import { getWriteReason } from '@/admin/bootstrap';
import { IssueList } from '@/admin/components/content-model/IssueList';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { Panel } from '@/admin/components/shell/Panel';
import { ReadOnlyBanner } from '@/admin/components/shell/ReadOnlyBanner';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Alert, AlertDescription, AlertTitle } from '@/admin/components/ui/alert';
import { Button } from '@/admin/components/ui/button';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/admin/components/ui/tabs';
import { useRedirectsIndex } from '@/admin/redirects/api';
import { RedirectsTab } from '@/admin/redirects/RedirectsTab';

// The default tab ships with the screen; the others load when opened.
const NotFoundTab = lazy(() => import('@/admin/redirects/NotFoundTab'));
const LogTab = lazy(() => import('@/admin/redirects/LogTab'));
const ImportExportTab = lazy(() => import('@/admin/redirects/ImportExportTab'));
const SettingsTab = lazy(() => import('@/admin/redirects/SettingsTab'));

export const TABS = ['redirects', '404s', 'log', 'import', 'settings'] as const;
export type Tab = (typeof TABS)[number];

interface View {
	tab: Tab;
	slug: string | null;
}

function readView(): View {
	const params = new URLSearchParams(window.location.search);
	const tab = params.get('tab');
	return { tab: (TABS as readonly string[]).includes(tab ?? '') ? (tab as Tab) : 'redirects', slug: params.get('slug') || null };
}

/** `?tab=<tab>&slug=<group>`; replaceState keeps the URL shareable, like the other screens. */
function writeView(view: View): void {
	const url = new URL(window.location.href);
	url.searchParams.delete('tab');
	url.searchParams.delete('slug');
	if (view.tab !== 'redirects') {
		url.searchParams.set('tab', view.tab);
	}
	if (view.slug && view.tab === 'redirects') {
		url.searchParams.set('slug', view.slug);
	}
	window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
}

function TabFallback() {
	return (
		<Panel className="grid gap-3 p-4" aria-busy="true">
			{[0, 1, 2].map((i) => (
				<Skeleton key={i} className="h-8 w-full" />
			))}
		</Panel>
	);
}

/** Redirects (spec §6): rules, 404s, the redirect log, import/export and settings, all file-backed except the logs. */
export default function RedirectsScreen() {
	const [view, setView] = useState<View>(readView);
	const [editRequest, setEditRequest] = useState<{ group: string; id: string } | null>(null);
	const { index, error, reload } = useRedirectsIndex();
	const writeReason = getWriteReason();
	const title = __('Redirects', 'proto-blocks');

	const go = useCallback((next: View) => {
		writeView(next);
		setView(next);
	}, []);

	useEffect(() => {
		const onPop = () => setView(readView());
		window.addEventListener('popstate', onPop);
		return () => window.removeEventListener('popstate', onPop);
	}, []);

	const onEditHandled = useCallback(() => setEditRequest(null), []);

	const header = (
		<PageHeader
			title={title}
			eyebrow={__('Content model', 'proto-blocks')}
			description={__('Redirect rules live in proto-redirects/ in the theme, so git tracks them. Hits and logs stay in the database.', 'proto-blocks')}
		/>
	);

	if (error && !index) {
		return (
			<div className="grid gap-6 [&>header]:mb-0">
				{header}
				<ScreenError screen={title} error={error} onRetry={() => void reload()} />
			</div>
		);
	}
	if (!index) {
		return (
			<div className="grid gap-6 [&>header]:mb-0" aria-busy="true">
				{header}
				<TabFallback />
			</div>
		);
	}
	if (!index.moduleEnabled) {
		return (
			<div className="grid gap-6 [&>header]:mb-0">
				{header}
				<Panel className="flex items-center gap-3 p-6">
					<PowerOffIcon aria-hidden="true" className="size-5 flex-none text-muted-foreground" />
					<p className="text-body">{__('The Redirects module is disabled by the proto_blocks_modules filter.', 'proto-blocks')}</p>
				</Panel>
			</div>
		);
	}

	const readOnly = writeReason !== null || index.createTarget.writable === false;
	const props = { index, reload, readOnly };

	return (
		<div className="grid gap-6 [&>header]:mb-0">
			{header}
			{writeReason !== null ? (
				<ReadOnlyBanner reason={writeReason} description={__('Rules and settings cannot be changed here. The 404s and the redirect log still work; they are stored in the database.', 'proto-blocks')} />
			) : index.createTarget.writable === false ? (
				<ReadOnlyBanner reason="not_writable" path={index.createTarget.path} description={__('The 404s and the redirect log still work; they are stored in the database.', 'proto-blocks')} />
			) : null}
			{index.paused ? (
				<Alert variant="warning" role="status">
					<PauseCircleIcon aria-hidden="true" />
					<AlertTitle className="line-clamp-none">{__('Redirection is active, Proto-Blocks redirects are paused.', 'proto-blocks')}</AlertTitle>
					<AlertDescription>
						<p>{__('Import your rules, then deactivate Redirection.', 'proto-blocks')}</p>
						<Button size="sm" variant="outline" className="mt-2" onClick={() => go({ tab: 'import', slug: null })}>
							{__('Import', 'proto-blocks')}
						</Button>
					</AlertDescription>
				</Alert>
			) : null}
			<IssueList issues={index.issues} />
			<Tabs value={view.tab} onValueChange={(tab) => go({ tab: tab as Tab, slug: view.slug })}>
				<TabsList variant="line" aria-label={__('Redirects sections', 'proto-blocks')}>
					<TabsTrigger value="redirects">{__('Redirects', 'proto-blocks')}</TabsTrigger>
					<TabsTrigger value="404s">{__('404s', 'proto-blocks')}</TabsTrigger>
					<TabsTrigger value="log">{__('Log', 'proto-blocks')}</TabsTrigger>
					<TabsTrigger value="import">{__('Import / Export', 'proto-blocks')}</TabsTrigger>
					<TabsTrigger value="settings">{__('Settings', 'proto-blocks')}</TabsTrigger>
				</TabsList>
				<TabsContent value="redirects" className="pt-4">
					<RedirectsTab {...props} slug={view.slug} onSlug={(slug) => go({ tab: 'redirects', slug })} editRequest={editRequest} onEditHandled={onEditHandled} />
				</TabsContent>
				{view.tab !== 'redirects' ? (
					<TabsContent value={view.tab} className="pt-4">
						<Suspense fallback={<TabFallback />}>
							{view.tab === '404s' ? <NotFoundTab {...props} /> : null}
							{view.tab === 'log' ? (
								<LogTab
									{...props}
									onEditRule={(group, id) => {
										setEditRequest({ group, id });
										go({ tab: 'redirects', slug: null });
									}}
								/>
							) : null}
							{view.tab === 'import' ? <ImportExportTab {...props} /> : null}
							{view.tab === 'settings' ? <SettingsTab {...props} /> : null}
						</Suspense>
					</TabsContent>
				) : null}
			</Tabs>
		</div>
	);
}
