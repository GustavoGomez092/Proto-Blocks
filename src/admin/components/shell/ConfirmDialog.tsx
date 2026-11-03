import { useEffect, useLayoutEffect, useRef, type ReactNode } from 'react';
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

/** How long to wait for a disabled opener to come back before giving up. */
export const OPENER_WAIT_MS = 5000;

/** Focus is free to move when nothing meaningful holds it: the page body or what is left of the dialog. */
function focusIsFree() {
	const active = document.activeElement;
	return !active || active === document.body || active.closest('[role="alertdialog"]') !== null;
}

function focusHeading() {
	const heading = document.querySelector<HTMLElement>('#proto-blocks-admin h1') ?? document.querySelector<HTMLElement>('h1');
	if (!heading) {
		return;
	}
	if (!heading.hasAttribute('tabindex')) {
		// Only needed to receive programmatic focus; remove it again once focus leaves.
		heading.setAttribute('tabindex', '-1');
		heading.addEventListener('blur', () => heading.removeAttribute('tabindex'), { once: true });
	}
	heading.focus();
}

/**
 * Focus `target`. A disabled button cannot take focus, and the opener is
 * disabled while the confirmed request runs, so wait for it to be enabled
 * again (up to OPENER_WAIT_MS) and fall back to the screen heading. Focus only
 * moves if the user has not put it somewhere else in the meantime.
 *
 * Returns a cancel function that disconnects the observer and clears the timer.
 */
export function focusWhenEnabled(target: HTMLElement): () => void {
	const isDisabled = () => target.hasAttribute('disabled');
	if (!isDisabled()) {
		target.focus();
		return () => undefined;
	}
	let observer: MutationObserver | null = null;
	let timer = 0;
	const cancel = () => {
		observer?.disconnect();
		observer = null;
		window.clearTimeout(timer);
	};
	const finish = (timedOut: boolean) => {
		cancel();
		if (!focusIsFree()) {
			return;
		}
		if (!target.isConnected || (timedOut && isDisabled())) {
			focusHeading();
		} else {
			target.focus();
		}
	};
	observer = new MutationObserver(() => {
		if (!isDisabled() || !target.isConnected) {
			finish(false);
		}
	});
	observer.observe(target, { attributes: true, attributeFilter: ['disabled'] });
	timer = window.setTimeout(() => finish(true), OPENER_WAIT_MS);
	return cancel;
}

/**
 * Confirmation for a destructive or irreversible action. The title is a
 * question naming the count, the confirm label repeats the verb and count
 * ("Remove 4 blocks"). Cancel gets initial focus (Radix AlertDialog).
 * Controlled: Cancel and Escape call onCancel (an overlay click does nothing,
 * as Radix AlertDialog does not dismiss on outside clicks); Confirm calls
 * only onConfirm, and the parent sets `open` false when it is done.
 *
 * The screens open it from state, not through an AlertDialogTrigger, so Radix
 * has no trigger to return focus to. The element focused when it opened (the
 * button that asked for confirmation) is remembered and refocused on close.
 */
export function ConfirmDialog({
	open,
	title,
	description,
	confirmLabel,
	destructive = false,
	confirmDisabled = false,
	onConfirm,
	onCancel,
	children,
}: {
	open: boolean;
	title: string;
	description?: ReactNode;
	confirmLabel: string;
	destructive?: boolean;
	/** Disables the confirm button (nothing to confirm). */
	confirmDisabled?: boolean;
	onConfirm: () => void;
	onCancel: () => void;
	/** Extra content under the description (a list of names, a warning). */
	children?: ReactNode;
}) {
	const opener = useRef<HTMLElement | null>(null);
	const cancelWait = useRef<() => void>(() => undefined);

	// Unmounting abandons any pending wait for the opener.
	useEffect(() => () => cancelWait.current(), []);

	// Layout effect: runs before Radix moves focus into the dialog.
	useLayoutEffect(() => {
		if (open) {
			cancelWait.current();
			const active = document.activeElement;
			opener.current = active instanceof HTMLElement && active !== document.body ? active : null;
		}
	}, [open]);

	return (
		<AlertDialog
			open={open}
			onOpenChange={(next) => {
				if (!next) {
					onCancel();
				}
			}}
		>
			<AlertDialogContent
				onCloseAutoFocus={(event) => {
					const target = opener.current;
					opener.current = null;
					if (target && target.isConnected) {
						event.preventDefault();
						cancelWait.current = focusWhenEnabled(target);
					} else if (target) {
						// The opener left the page (e.g. a list re-render): land on the screen heading.
						event.preventDefault();
						if (focusIsFree()) {
							focusHeading();
						}
					}
				}}
			>
				<AlertDialogHeader>
					<AlertDialogTitle className="text-[17px] text-balance">{title}</AlertDialogTitle>
					{description ? <AlertDialogDescription>{description}</AlertDialogDescription> : null}
				</AlertDialogHeader>
				{children}
				<AlertDialogFooter>
					<AlertDialogCancel>{__('Cancel', 'proto-blocks')}</AlertDialogCancel>
					<AlertDialogAction
						variant={destructive ? 'destructive' : 'default'}
						disabled={confirmDisabled}
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
