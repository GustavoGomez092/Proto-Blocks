import { useId, useState, type ReactNode } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Trash2Icon } from 'lucide-react';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';
import {
	AlertDialog,
	AlertDialogAction,
	AlertDialogCancel,
	AlertDialogContent,
	AlertDialogDescription,
	AlertDialogFooter,
	AlertDialogHeader,
	AlertDialogTitle,
} from '@/admin/components/ui/alert-dialog';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { interpolate } from '@/admin/lib/interpolate';
import type { Kind } from '@/admin/content-model/types';

const code = (text: ReactNode) => <code className="font-mono text-[12.5px]">{text}</code>;

/**
 * Confirms deleting a declaration file (design guide 2.5). It names the path
 * and says the content stays in the database but becomes inaccessible. With
 * content (`count > 0`) Delete stays disabled until the slug is typed exactly
 * and `onConfirm` receives the slug (the server's `confirm`); without content
 * it is the plain Phase 0 ConfirmDialog and `onConfirm` gets nothing.
 */
export function DeleteDeclarationDialog({
	slug,
	path,
	content,
	onConfirm,
	onCancel = () => {},
	open = true,
	kind = 'type',
	label,
}: {
	slug: string;
	path: string;
	content: { count: number; taxonomies?: Record<string, number> };
	onConfirm: (confirm?: string) => void;
	onCancel?: () => void;
	open?: boolean;
	kind?: Kind;
	/** The declaration's name for the title; defaults to the slug. */
	label?: string;
}) {
	const [typed, setTyped] = useState('');
	const inputId = useId();
	const name = label ?? slug;

	/* translators: %s: name of the post type or taxonomy. */
	const title = sprintf(__('Delete the %s declaration?', 'proto-blocks'), name);
	const body = interpolate(
		/* translators: %s: path of the declaration file or folder. */
		__('This deletes %s. Content is not deleted: it stays in the database but becomes inaccessible until the declaration is back.', 'proto-blocks'),
		code(path)
	);

	if (content.count <= 0) {
		return (
			<ConfirmDialog
				open={open}
				title={title}
				description={body}
				confirmLabel={__('Delete declaration', 'proto-blocks')}
				destructive
				onConfirm={() => onConfirm()}
				onCancel={onCancel}
			/>
		);
	}

	const items =
		kind === 'type'
			? /* translators: %d: number of posts. */
				sprintf(_n('%d item becomes inaccessible.', '%d items become inaccessible.', content.count, 'proto-blocks'), content.count)
			: /* translators: %d: number of terms. */
				sprintf(_n('%d term becomes inaccessible.', '%d terms become inaccessible.', content.count, 'proto-blocks'), content.count);
	const terms = Object.entries(content.taxonomies ?? {}).filter(([, count]) => count > 0);
	const matches = typed === slug;

	return (
		<AlertDialog
			open={open}
			onOpenChange={(next) => {
				if (!next) {
					setTyped('');
					onCancel();
				}
			}}
		>
			<AlertDialogContent>
				<AlertDialogHeader>
					<div className="mb-1 grid size-10 place-items-center rounded-lg bg-destructive-soft text-destructive">
						<Trash2Icon aria-hidden="true" className="size-5" />
					</div>
					<AlertDialogTitle className="text-[17px] text-balance">{title}</AlertDialogTitle>
					<AlertDialogDescription>{body}</AlertDialogDescription>
				</AlertDialogHeader>
				<ul className="list-disc ps-5 text-sm">
					<li>{items}</li>
					{terms.map(([taxonomy, count]) => (
						<li key={taxonomy}>
							{interpolate(
								/* translators: 1: number of terms, 2: taxonomy slug. */
								_n('%1$s term in %2$s stays but loses this link.', '%1$s terms in %2$s stay but lose this link.', count, 'proto-blocks'),
								String(count),
								code(taxonomy)
							)}
						</li>
					))}
				</ul>
				<div className="grid gap-2">
					<Label htmlFor={inputId} className="text-sm font-normal">
						{interpolate(
							/* translators: %s: the slug to type. */
							__('Type %s to confirm', 'proto-blocks'),
							code(slug)
						)}
					</Label>
					<Input
						id={inputId}
						value={typed}
						autoComplete="off"
						spellCheck={false}
						className="font-mono"
						onChange={(event) => setTyped(event.target.value)}
					/>
					<p className="sr-only" aria-live="polite">
						{matches ? __('Matches. You can delete.', 'proto-blocks') : ''}
					</p>
				</div>
				<AlertDialogFooter>
					<AlertDialogCancel>{__('Cancel', 'proto-blocks')}</AlertDialogCancel>
					<AlertDialogAction
						variant="destructive"
						disabled={!matches}
						onClick={(event) => {
							event.preventDefault();
							if (matches) {
								onConfirm(slug);
							}
						}}
					>
						{__('Delete declaration', 'proto-blocks')}
					</AlertDialogAction>
				</AlertDialogFooter>
			</AlertDialogContent>
		</AlertDialog>
	);
}
