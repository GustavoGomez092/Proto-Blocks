import { useCallback, useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { toast } from 'sonner';
import { adminGet, adminPost, ApiError } from '@/admin/api';
import { setEnabledText } from '@/admin/scripts/edits';
import { ScriptEditor } from '@/admin/scripts/ScriptEditor';
import { ScriptList } from '@/admin/scripts/ScriptList';
import type { ScriptDetail, ScriptsIndex } from '@/admin/scripts/types';

interface View {
	slug: string | null;
	creating: boolean;
	/** Empty-state template the create form starts from (`?template=<id>`). */
	template?: string | null;
}

function readView(): View {
	const params = new URLSearchParams(window.location.search);
	return { slug: params.get('slug') || null, creating: params.get('new') === '1', template: params.get('template') || null };
}

/** `?slug=<slug>` → editor, `?new=1[&template=<id>]` → create, else the list; replaceState keeps the URL shareable. */
function writeView(view: View): void {
	const url = new URL(window.location.href);
	url.searchParams.delete('slug');
	url.searchParams.delete('new');
	url.searchParams.delete('template');
	if (view.slug) {
		url.searchParams.set('slug', view.slug);
	} else if (view.creating) {
		url.searchParams.set('new', '1');
		if (view.template) {
			url.searchParams.set('template', view.template);
		}
	}
	window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
}

/** Scripts (spec docs/superpowers/specs/2026-11-05-scripts-design.md §6): list, create and edit. */
export default function ScriptsScreen() {
	const [view, setView] = useState<View>(readView);
	const [result, setResult] = useState<ScriptsIndex | null>(null);
	const [error, setError] = useState<unknown>(null);
	const [loading, setLoading] = useState(true);
	const [busy, setBusy] = useState<string | null>(null);

	const go = useCallback((next: View) => {
		writeView(next);
		setView(next);
	}, []);

	const load = useCallback(async () => {
		setLoading(true);
		try {
			setResult(await adminGet<ScriptsIndex>('admin/scripts'));
			setError(null);
		} catch (e) {
			setError(e);
		} finally {
			setLoading(false);
		}
	}, []);

	useEffect(() => {
		void load();
	}, [view.slug, view.creating, load]);

	useEffect(() => {
		const onPop = () => setView(readView());
		window.addEventListener('popstate', onPop);
		return () => window.removeEventListener('popstate', onPop);
	}, []);

	const toggle = async (slug: string, enabled: boolean) => {
		setBusy(slug);
		try {
			const file = await adminGet<ScriptDetail>(`admin/scripts/${slug}`);
			await adminPost(`admin/scripts/${slug}`, { text: setEnabledText(file.text, enabled), hash: file.hash });
			await load();
		} catch (e) {
			toast.error(e instanceof ApiError ? e.message : __('The script could not be saved.', 'proto-blocks'));
		} finally {
			setBusy(null);
		}
	};

	const back = () => go({ slug: null, creating: false });
	const roles = result?.roles ?? [];
	const items = result?.items ?? [];
	const writeReason = result?.writeReason ?? null;

	if (view.slug) {
		return <ScriptEditor key={view.slug} slug={view.slug} roles={roles} items={items} writeReason={writeReason} onBack={back} onSaved={(slug) => go({ slug, creating: false })} />;
	}
	if (view.creating && result && result.canWrite) {
		return <ScriptEditor key={`new-${view.template ?? ''}`} slug={null} template={view.template ?? null} roles={roles} items={items} writeReason={writeReason} onBack={back} onSaved={(slug) => go({ slug, creating: false })} />;
	}
	return (
		<ScriptList
			result={result}
			loading={loading}
			error={error}
			busy={busy}
			onRetry={() => void load()}
			onEdit={(slug) => go({ slug, creating: false })}
			onCreate={(template) => go({ slug: null, creating: true, template })}
			onToggle={(slug, enabled) => void toggle(slug, enabled)}
		/>
	);
}
