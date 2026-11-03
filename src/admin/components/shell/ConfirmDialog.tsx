import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
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

/**
 * Confirmation for a destructive or irreversible action. The title is a
 * question naming the count, the confirm label repeats the verb and count
 * ("Remove 4 blocks"). Cancel gets initial focus (Radix AlertDialog).
 * Controlled: Cancel and Escape call onCancel (an overlay click does nothing,
 * as Radix AlertDialog does not dismiss on outside clicks); Confirm calls
 * only onConfirm, and the parent sets `open` false when it is done.
 */
export function ConfirmDialog({
	open,
	title,
	description,
	confirmLabel,
	destructive = false,
	onConfirm,
	onCancel,
	children,
}: {
	open: boolean;
	title: string;
	description?: ReactNode;
	confirmLabel: string;
	destructive?: boolean;
	onConfirm: () => void;
	onCancel: () => void;
	/** Extra content under the description (a list of names, a warning). */
	children?: ReactNode;
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
					<AlertDialogTitle className="text-[17px] text-balance">{title}</AlertDialogTitle>
					{description ? <AlertDialogDescription>{description}</AlertDialogDescription> : null}
				</AlertDialogHeader>
				{children}
				<AlertDialogFooter>
					<AlertDialogCancel>{__('Cancel', 'proto-blocks')}</AlertDialogCancel>
					<AlertDialogAction
						variant={destructive ? 'destructive' : 'default'}
						onClick={(event) => {
							// Keep Radix from closing (and reporting a cancel): the
							// parent closes it, e.g. once an async action finishes.
							event.preventDefault();
							onConfirm();
						}}
					>
						{confirmLabel}
					</AlertDialogAction>
				</AlertDialogFooter>
			</AlertDialogContent>
		</AlertDialog>
	);
}
