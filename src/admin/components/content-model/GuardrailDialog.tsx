import { __ } from '@wordpress/i18n';
import { TriangleAlertIcon } from 'lucide-react';
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
import type { Guardrail } from '@/admin/content-model/types';

/**
 * Shown when a save resolves `confirm` (design guide 2.5): lists what the
 * change does to existing content. "Save anyway" passes the guardrail codes
 * back, to save again with them in `acknowledge`; "Go back" (initial focus)
 * cancels.
 */
export function GuardrailDialog({
	guardrails,
	onConfirm,
	onCancel,
	open = true,
}: {
	guardrails: Guardrail[];
	onConfirm: (acknowledge: string[]) => void;
	onCancel: () => void;
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
						<TriangleAlertIcon aria-hidden="true" className="size-5" />
					</div>
					<AlertDialogTitle className="text-[17px] text-balance">
						{__('Save changes that affect existing content?', 'proto-blocks')}
					</AlertDialogTitle>
					<AlertDialogDescription>
						{__('Nothing is deleted, but existing content changes how it shows in the editor.', 'proto-blocks')}
					</AlertDialogDescription>
				</AlertDialogHeader>
				<ul className="list-disc ps-5 text-sm">
					{guardrails.map((guardrail, i) => (
						<li key={`${guardrail.code}-${i}`}>{guardrail.message}</li>
					))}
				</ul>
				<AlertDialogFooter>
					<AlertDialogCancel>{__('Go back', 'proto-blocks')}</AlertDialogCancel>
					<AlertDialogAction
						onClick={(event) => {
							event.preventDefault();
							onConfirm([...new Set(guardrails.map((guardrail) => guardrail.code))]);
						}}
					>
						{__('Save anyway', 'proto-blocks')}
					</AlertDialogAction>
				</AlertDialogFooter>
			</AlertDialogContent>
		</AlertDialog>
	);
}
