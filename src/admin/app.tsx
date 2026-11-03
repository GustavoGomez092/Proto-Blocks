import { Component, Suspense, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import type { AdminBootstrap } from '@/admin/bootstrap';
import { PortalProvider } from '@/admin/components/shell/PortalProvider';
import { ScreenError } from '@/admin/components/shell/ScreenError';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { Toaster } from '@/admin/components/ui/sonner';
import { TooltipProvider } from '@/admin/components/ui/tooltip';
import { SCREENS, screenLabel } from '@/admin/screens';

/** Placeholder while a screen's chunk loads: header plus three stat tiles. */
function ScreenSkeleton() {
	return (
		<div aria-busy="true" className="grid gap-6">
			<div className="flex items-center gap-3 pt-3 pb-6">
				<Skeleton className="size-9" />
				<Skeleton className="h-6 w-48" />
			</div>
			<div className="grid gap-4 sm:grid-cols-3">
				<Skeleton className="h-28" />
				<Skeleton className="h-28" />
				<Skeleton className="h-28" />
			</div>
		</div>
	);
}

/** Catches render errors (including a chunk that fails to load) and shows ScreenError. */
class ScreenBoundary extends Component<{ screen: string; children: ReactNode }, { error: unknown }> {
	state = { error: null as unknown };

	static getDerivedStateFromError(error: unknown) {
		return { error };
	}

	render() {
		if (this.state.error) {
			return (
				<ScreenError
					screen={screenLabel(this.props.screen)}
					error={this.state.error}
					onRetry={() => window.location.reload()}
				/>
			);
		}
		return this.props.children;
	}
}

export function AdminApp({ bootstrap }: { bootstrap: AdminBootstrap }) {
	const Screen = SCREENS[bootstrap.screen];

	return (
		<PortalProvider>
			<TooltipProvider>
				<div className="mr-5 max-w-[1200px] pb-10">
					{Screen ? (
						<ScreenBoundary screen={bootstrap.screen}>
							<Suspense fallback={<ScreenSkeleton />}>
								<Screen />
							</Suspense>
						</ScreenBoundary>
					) : (
						<ScreenError
							screen={screenLabel(bootstrap.screen)}
							error={new Error(__('This screen is not available in this version.', 'proto-blocks'))}
						/>
					)}
				</div>
				<Toaster />
			</TooltipProvider>
		</PortalProvider>
	);
}
