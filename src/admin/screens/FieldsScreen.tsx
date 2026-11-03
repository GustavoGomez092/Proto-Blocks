import { useCallback, useEffect, useState } from 'react';
import { adminGet } from '@/admin/api';
import { getWriteReason } from '@/admin/bootstrap';
import { Builder } from './fields/Builder';
import { GroupList, type FieldsListResult } from './fields/GroupList';

interface View {
	group: string | null;
	creating: boolean;
}

function readView(): View {
	const params = new URLSearchParams(window.location.search);
	const group = params.get('group');
	return { group: group || null, creating: !group && params.get('new') === '1' };
}

/** Puts the view in the query (`&group=<key>`, `&new=1`) with pushState, so Back and reload work. */
function writeView(view: View): void {
	const url = new URL(window.location.href);
	url.searchParams.delete('group');
	url.searchParams.delete('new');
	if (view.group) {
		url.searchParams.set('group', view.group);
	} else if (view.creating) {
		url.searchParams.set('new', '1');
	}
	window.history.pushState(window.history.state, '', url.pathname + url.search + url.hash);
}

/**
 * Fields (spec "Fields"): the list of field groups and, by `?group=<key>`
 * or `?new=1`, the builder. Navigating pushes history; Back returns.
 */
export default function FieldsScreen() {
	const [view, setView] = useState<View>(readView);
	const [result, setResult] = useState<FieldsListResult | null>(null);
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
			setResult(await adminGet<FieldsListResult>('admin/fields'));
			setError(null);
		} catch (e) {
			setError(e);
		} finally {
			setLoading(false);
		}
	}, []);

	const inList = !view.group && !view.creating;
	useEffect(() => {
		if (inList) {
			void load();
		}
	}, [inList, load]);

	useEffect(() => {
		const onPop = () => setView(readView());
		window.addEventListener('popstate', onPop);
		return () => window.removeEventListener('popstate', onPop);
	}, []);

	if (!inList) {
		return (
			<Builder
				key={view.group ?? 'new'}
				group={view.group}
				onBack={() => go({ group: null, creating: false })}
				onCreated={(key) => go({ group: key, creating: false })}
			/>
		);
	}

	return (
		<GroupList
			result={result}
			loading={loading}
			error={error}
			readOnly={readOnly}
			onRetry={load}
			onEdit={(key) => go({ group: key, creating: false })}
			onCreate={() => go({ group: null, creating: true })}
			onDeleted={load}
		/>
	);
}
