import { useCallback, useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { adminGet } from '@/admin/api';
import { getWriteReason } from '@/admin/bootstrap';
import type { ListResult } from '@/admin/content-model/types';
import { PostTypeList } from './post-types/PostTypeList';
import { PostTypeEdit } from './post-types/PostTypeForm';

interface View {
	type: string | null;
	creating: boolean;
}

function readView(): View {
	const params = new URLSearchParams(window.location.search);
	return { type: params.get('type') || null, creating: params.get('new') === '1' };
}

/** Writes the view into the query (`&type=<slug>`, `&new=1`) so the URL can be shared and reloaded. */
function writeView(view: View): void {
	const url = new URL(window.location.href);
	url.searchParams.delete('type');
	url.searchParams.delete('new');
	if (view.type) {
		url.searchParams.set('type', view.type);
	} else if (view.creating) {
		url.searchParams.set('new', '1');
	}
	window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
}

/**
 * Post Types (spec section 4): the list of every registered post type, the
 * create dialog and the declaration edit form. Which one shows lives in the
 * URL query.
 */
export default function PostTypesScreen() {
	const [view, setView] = useState<View>(readView);
	const [result, setResult] = useState<ListResult | null>(null);
	const [error, setError] = useState<unknown>(null);
	const [loading, setLoading] = useState(true);
	const readOnly = getWriteReason();

	const go = useCallback((next: View) => {
		writeView(next);
		setView(next);
	}, []);

	const load = useCallback(async () => {
		setLoading(true);
		try {
			setResult(await adminGet<ListResult>('admin/types'));
			setError(null);
		} catch (e) {
			setError(e);
		} finally {
			setLoading(false);
		}
	}, []);

	useEffect(() => {
		if (!view.type) {
			void load();
		}
	}, [view.type, load]);

	useEffect(() => {
		const onPop = () => setView(readView());
		window.addEventListener('popstate', onPop);
		return () => window.removeEventListener('popstate', onPop);
	}, []);

	if (view.type) {
		return (
			<PostTypeEdit
				key={view.type}
				slug={view.type}
				readOnly={readOnly !== null}
				onBack={() => go({ type: null, creating: false })}
			/>
		);
	}

	return (
		<PostTypeList
			result={result}
			loading={loading}
			error={error}
			readOnly={readOnly}
			creating={view.creating}
			onRetry={load}
			onEdit={(slug) => go({ type: slug, creating: false })}
			onCreateOpen={(open) => go({ type: null, creating: open })}
			onCreated={(slug) => go({ type: slug, creating: false })}
			title={__('Post Types', 'proto-blocks')}
		/>
	);
}
