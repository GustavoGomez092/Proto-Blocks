import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { GitCompareIcon } from 'lucide-react';
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
import { Button } from '@/admin/components/ui/button';
import { interpolate } from '@/admin/lib/interpolate';

const code = (text: ReactNode) => <code className="font-mono text-[12.5px] break-all">{text}</code>;

/**
 * Shown when a save resolves `conflict`: the file changed on disk since it
 * was loaded, so nothing was written. Reload file (discarding the unsaved
 * edits) or, when `onCompare` is given, Compare. Cancel keeps editing.
 */
export function ConflictDialog({
	path,
	onReload,
	onCompare,
	onCancel = () => {},
	open = true,
}: {
	path: string;
	onReload: () => void;
	onCompare?: () => void;
	onCancel?: () => void;
	open?: boolean;
}) {
	return (
		<AlertDialog
			open={open}
			onOpenChange={(next) => {
				if (!next) {
					onCancel();
				}
			}}
		>
			<AlertDialogContent>
				<AlertDialogHeader>
					<div className="mb-1 grid size-10 place-items-center rounded-lg bg-warning-soft text-warning">
						<GitCompareIcon aria-hidden="true" className="size-5" />
					</div>
					<AlertDialogTitle className="text-[17px] text-balance">{__('The file changed on disk.', 'proto-blocks')}</AlertDialogTitle>
					<AlertDialogDescription>
						{interpolate(
							/* translators: %s: path of the declaration file. */
							__('%s was changed after you opened it, so nothing was saved. Reload the file to see the changes; your unsaved edits will be lost.', 'proto-blocks'),
							code(path)
						)}
					</AlertDialogDescription>
				</AlertDialogHeader>
				<AlertDialogFooter>
					<AlertDialogCancel>{__('Keep editing', 'proto-blocks')}</AlertDialogCancel>
					{onCompare ? (
						<Button variant="outline" onClick={onCompare}>
							{__('Compare', 'proto-blocks')}
						</Button>
					) : null}
					<AlertDialogAction
						onClick={(event) => {
							event.preventDefault();
							onReload();
						}}
					>
						{__('Reload file', 'proto-blocks')}
					</AlertDialogAction>
				</AlertDialogFooter>
			</AlertDialogContent>
		</AlertDialog>
	);
}
