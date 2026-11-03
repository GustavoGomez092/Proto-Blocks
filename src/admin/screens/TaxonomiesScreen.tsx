import { useCallback, useEffect, useState } from 'react';
import { adminGet } from '@/admin/api';
import type { ListItem, ListResult } from '@/admin/content-model/types';
import { TaxonomyForm } from '@/admin/screens/taxonomies/TaxonomyForm';
import { TaxonomyList } from '@/admin/screens/taxonomies/TaxonomyList';

type View = { mode: 'list' } | { mode: 'new' } | { mode: 'edit'; slug: string };

/** View state lives in the query (`&taxonomy=<slug>`, `&new=1`) so links are shareable and Back works. */
function readView(): View {
	const params = new URLSearchParams(window.location.search);
	const slug = params.get('taxonomy');
	if (slug) {
		return { mode: 'edit', slug };
	}
	return params.get('new') === '1' ? { mode: 'new' } : { mode: 'list' };
}

export default function TaxonomiesScreen() {
	const [view, setView] = useState<View>(readView);
	const [types, setTypes] = useState<ListItem[]>([]);

	useEffect(() => {
		const onPop = () => setView(readView());
		window.addEventListener('popstate', onPop);
		return () => window.removeEventListener('popstate', onPop);
	}, []);

	useEffect(() => {
		let live = true;
		adminGet<ListResult>('admin/types')
			.then((result) => live && setTypes(result.items))
			.catch(() => {});
		return () => {
			live = false;
		};
	}, []);

	const go = useCallback((next: View) => {
		const url = new URL(window.location.href);
		url.searchParams.delete('taxonomy');
		url.searchParams.delete('new');
		if (next.mode === 'edit') {
			url.searchParams.set('taxonomy', next.slug);
		} else if (next.mode === 'new') {
			url.searchParams.set('new', '1');
		}
		window.history.pushState(null, '', url);
		setView(next);
	}, []);

	if (view.mode === 'list') {
		return <TaxonomyList types={types} onOpen={(slug) => go({ mode: 'edit', slug })} onNew={() => go({ mode: 'new' })} />;
	}
	return (
		<TaxonomyForm
			slug={view.mode === 'edit' ? view.slug : null}
			types={types}
			onBack={() => go({ mode: 'list' })}
			onOpen={(slug) => go({ mode: 'edit', slug })}
		/>
	);
}
