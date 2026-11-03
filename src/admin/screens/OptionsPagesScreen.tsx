import { useCallback, useEffect, useState } from 'react';
import { adminGet } from '@/admin/api';
import { getWriteReason } from '@/admin/bootstrap';
import type { OptionsIndex } from '@/admin/options-pages/declaration';
import { NewOptionsPage, OptionsPageEditor } from '@/admin/options-pages/OptionsPageEditor';
import { OptionsPageList } from '@/admin/options-pages/OptionsPageList';

interface View {
	slug: string | null;
	creating: boolean;
}

function readView(): View {
	const params = new URLSearchParams(window.location.search);
	return { slug: params.get('slug') || null, creating: params.get('new') === '1' };
}

/** `?slug=<slug>` → editor, `?new=1` → create, else the list; replaceState keeps the URL shareable. */
function writeView(view: View): void {
	const url = new URL(window.location.href);
	url.searchParams.delete('slug');
	url.searchParams.delete('new');
	if (view.slug) {
		url.searchParams.set('slug', view.slug);
	} else if (view.creating) {
		url.searchParams.set('new', '1');
	}
	window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
}

/** Options Pages (spec "Settings pages"): the list, the create flow and the declaration editor. */
export default function OptionsPagesScreen() {
	const [view, setView] = useState<View>(readView);
	const [result, setResult] = useState<OptionsIndex | null>(null);
	const [error, setError] = useState<unknown>(null);
	const [loading, setLoading] = useState(true);
	const writeReason = getWriteReason();

	const go = useCallback((next: View) => {
		writeView(next);
		setView(next);
	}, []);

	const load = useCallback(async () => {
		setLoading(true);
		try {
			setResult(await adminGet<OptionsIndex>('admin/options-pages'));
			setError(null);
		} catch (e) {
			setError(e);
		} finally {
			setLoading(false);
		}
	}, []);

	useEffect(() => {
		if (!view.slug) {
			void load();
		}
	}, [view.slug, load]);

	useEffect(() => {
		const onPop = () => setView(readView());
		window.addEventListener('popstate', onPop);
		return () => window.removeEventListener('popstate', onPop);
	}, []);

	const back = () => go({ slug: null, creating: false });

	if (view.slug) {
		return <OptionsPageEditor key={view.slug} slug={view.slug} onBack={back} />;
	}
	if (view.creating && writeReason === null) {
		return <NewOptionsPage taken={result?.pages.map((page) => page.slug) ?? []} capabilities={result?.capabilities ?? []} onBack={back} onCreated={(slug) => go({ slug, creating: false })} />;
	}
	return (
		<OptionsPageList
			result={result}
			loading={loading}
			error={error}
			readOnly={writeReason}
			onRetry={() => void load()}
			onEdit={(slug) => go({ slug, creating: false })}
			onCreate={() => go({ slug: null, creating: true })}
		/>
	);
}
