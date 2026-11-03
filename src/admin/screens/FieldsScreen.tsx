import { useCallback, useEffect, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowLeftIcon } from 'lucide-react';
import { adminGet } from '@/admin/api';
import { getWriteReason } from '@/admin/bootstrap';
import { PageHeader } from '@/admin/components/shell/PageHeader';
import { Button } from '@/admin/components/ui/button';
import { Card } from '@/admin/components/ui/card';
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
 * Stand-in for the visual builder (Phase 2 Task 16 replaces it): the
 * routing, the URL and the way back are final.
 */
function Builder({ group, onBack }: { group: string | null; onBack: () => void }) {
	return (
		<div className="grid gap-6">
			<PageHeader
				title={group ?? __('New field group', 'proto-blocks')}
				actions={
					<Button variant="outline" onClick={onBack}>
						<ArrowLeftIcon aria-hidden="true" />
						{__('Back to field groups', 'proto-blocks')}
					</Button>
				}
			/>
			<Card className="px-6 py-6 text-sm shadow-xs">
				{group
					? sprintf(
							/* translators: %s: field group key. */
							__('The visual builder for %s is not available in this version yet. Edit the group in its file.', 'proto-blocks'),
							group
						)
					: __('The visual builder is not available in this version yet. Add the group as a file in your theme.', 'proto-blocks')}
			</Card>
		</div>
	);
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
		return <Builder key={view.group ?? 'new'} group={view.group} onBack={() => go({ group: null, creating: false })} />;
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
