/**
 * Pieces shared by the Redirects screen's tabs: the write flow (conflict and
 * guardrail dialogs), group helpers, the rule writes behind the dialog, a
 * pager and date formatting.
 */
import { useCallback, useState, type ReactNode } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { toast } from 'sonner';
import { ConflictDialog } from '@/admin/components/content-model/ConflictDialog';
import { GuardrailDialog } from '@/admin/components/content-model/GuardrailDialog';
import { READ_ONLY_BANNER_ID } from '@/admin/components/shell/ReadOnlyBanner';
import { Button } from '@/admin/components/ui/button';
import type { Guardrail } from '@/admin/content-model/types';
import type { ApiError } from '@/admin/api';
import { asApiError, writeInOrder, type GroupWrite, type WriteOutcome } from './api';
import { addRule, readRules, removeRule, updateRule } from './edits';
import { NEW_GROUP, type RuleFormValues } from './rule-form';
import { ruleFieldErrors, serverFindings, slugifyGroup, type RuleField } from './schema';
import type { FileRule, Group, GroupReadOnlyReason, RedirectsIndex, Rule } from './types';

export interface TabProps {
	index: RedirectsIndex;
	reload: () => Promise<void> | void;
	/** File writes are off (file mods, multisite, unwritable theme). */
	readOnly: boolean;
}

/** Props that disable a write control and point it at the read-only banner. */
export function writeGuard(readOnly: boolean, disabled = false): { disabled: boolean; 'aria-describedby'?: string } {
	return readOnly ? { disabled: true, 'aria-describedby': READ_ONLY_BANNER_ID } : { disabled };
}

/** Groups in evaluation order: position, then slug (spec §3). */
export function sortedGroups(index: RedirectsIndex): Group[] {
	return [...index.groups].sort((a, b) => a.position - b.position || a.slug.localeCompare(b.slug));
}

/** Whether the screen can save the group's file (a file group, writable, no read-only reason). */
export function groupEditable(group: Group): boolean {
	return group.origin === 'file' && group.writable && !group.readOnlyReason;
}

/** Why a group cannot be saved here (`readOnlyReason`, inferred for an older response). Null when it can. */
export function groupReadOnlyReason(group: Group): GroupReadOnlyReason | null {
	if (group.readOnlyReason) {
		return group.readOnlyReason;
	}
	if (group.origin === 'php') {
		return 'php';
	}
	return group.writable ? null : 'not_writable';
}

/** A short reason for the group cell and the group menu; null for an editable group. */
export function readOnlyReasonLabel(group: Group): string | null {
	switch (groupReadOnlyReason(group)) {
		case null:
			return null;
		case 'php':
			return __('Declared in PHP; edit copies a rule into a file group', 'proto-blocks');
		case 'read_only':
			return __('Read-only: the file is outside the theme the screen writes to (a parent theme or a filtered folder)', 'proto-blocks');
		case 'file_mods':
			return __('Read-only: file modifications are disabled', 'proto-blocks');
		case 'multisite':
			return __('Read-only: only a network admin can change theme files', 'proto-blocks');
		default:
			return __('Read-only: the file is not writable', 'proto-blocks');
	}
}

/** File groups the UI can write. */
export function writableGroups(index: RedirectsIndex): Group[] {
	return sortedGroups(index).filter(groupEditable);
}

/** Where a new rule goes by default: the first writable file group, else a new group. */
export function defaultGroup(index: RedirectsIndex): string {
	return writableGroups(index)[0]?.slug ?? NEW_GROUP;
}

export function targetSlug(values: Pick<RuleFormValues, 'group' | 'newGroupTitle'>): string {
	return values.group === NEW_GROUP ? slugifyGroup(values.newGroupTitle) : values.group;
}

/** Where the saved rule ended up in the text that was posted (for mapping a refusal's `/rules/<i>` paths). */
export interface RuleLocation {
	index: number | null;
}

function located(text: string, id: string | undefined, at?: RuleLocation): string {
	if (at) {
		const found = readRules(text).findIndex((raw) => raw.id === id);
		at.index = found === -1 ? null : found;
	}
	return text;
}

/**
 * The file writes that save the dialog: an edit in place, a move (delete in
 * the old file, then insert in the new one, each with its own hash), or an
 * insert (new rule, or a PHP rule written into a file group). `at` receives
 * the rule's position in the text of the write that holds it.
 */
export function ruleWrites(index: RedirectsIndex, original: Rule | null, values: RuleFormValues, rule: FileRule, at?: RuleLocation): GroupWrite[] {
	const slug = targetSlug(values);
	const target = index.groups.find((group) => group.slug === slug && group.origin === 'file');
	const insert: GroupWrite = {
		slug,
		expectedHash: target ? target.hash : undefined,
		title: values.group === NEW_GROUP ? values.newGroupTitle.trim() : undefined,
		edit: (text) => located(addRule(text, rule), rule.id, at),
	};
	if (!original || original.origin !== 'file') {
		return [insert];
	}
	const source = index.groups.find((group) => group.slug === original.group && group.origin === 'file');
	const ref = { id: original.id, index: original.index };
	if (original.group === slug) {
		return [{ slug, expectedHash: source?.hash, edit: (text) => located(updateRule(text, ref, rule), rule.id, at) }];
	}
	return [{ slug: original.group, expectedHash: source?.hash, edit: (text) => removeRule(text, ref) }, insert];
}

/** Field messages of a refused rule save, keyed by dialog field. */
export type RuleRefusal = Partial<Record<RuleField, string>>;

/** The dialog's submit: resolves true when saved; `refused` receives the server's field errors instead of a toast. */
export type RuleSubmit = (values: RuleFormValues, rule: FileRule, refused: (errors: RuleRefusal) => void) => Promise<boolean>;

/**
 * Saves the dialog's rule with `runWrites`. A `proto_blocks_schema_invalid`
 * refusal with findings goes to `refused` (mapped onto the dialog's fields
 * by the rule's position in the posted file) rather than a toast.
 */
export function saveRule(
	runWrites: (writes: GroupWrite[], success?: string, refused?: (error: ApiError) => boolean) => Promise<boolean>,
	index: RedirectsIndex,
	original: Rule | null,
	values: RuleFormValues,
	rule: FileRule,
	success: string,
	refused: (errors: RuleRefusal) => void
): Promise<boolean> {
	const at: RuleLocation = { index: null };
	return runWrites(ruleWrites(index, original, values, rule, at), success, (error) => {
		const findings = serverFindings(error);
		if (findings.length === 0) {
			return false;
		}
		refused(ruleFieldErrors(findings, at.index));
		return true;
	});
}

/**
 * Runs file writes and handles what comes back: saved → toast and reload,
 * conflict → ConflictDialog (reload), confirm → GuardrailDialog (run again
 * with the codes acknowledged), error → `refused` when it handles it, else a
 * toast. Render `dialogs`.
 */
export function useWriteFlow(onSaved: () => unknown) {
	const [conflict, setConflict] = useState<string | null>(null);
	const [confirm, setConfirm] = useState<{ guardrails: Guardrail[]; retry: (acknowledge: string[]) => void } | null>(null);
	const [busy, setBusy] = useState(false);

	const run = useCallback(
		async (op: (acknowledge: string[]) => Promise<WriteOutcome>, success?: string, acknowledge: string[] = [], refused?: (error: ApiError) => boolean): Promise<boolean> => {
			setBusy(true);
			try {
				const outcome = await op(acknowledge);
				if (outcome.status === 'saved') {
					if (success) {
						toast.success(success);
					}
					await onSaved();
					return true;
				}
				if (outcome.status === 'conflict') {
					setConflict(outcome.path);
					return false;
				}
				setConfirm({
					guardrails: outcome.guardrails,
					retry: (codes) => {
						setConfirm(null);
						void run(op, success, codes, refused);
					},
				});
				return false;
			} catch (e) {
				const error = asApiError(e);
				if (refused?.(error)) {
					return false;
				}
				toast.error(__('The change was not saved.', 'proto-blocks'), { description: error.message });
				return false;
			} finally {
				setBusy(false);
			}
		},
		[onSaved]
	);

	const runWrites = useCallback(
		(writes: GroupWrite[], success?: string, refused?: (error: ApiError) => boolean) => run((acknowledge) => writeInOrder(writes, acknowledge), success, [], refused),
		[run]
	);

	const dialogs: ReactNode = (
		<>
			{conflict !== null ? (
				<ConflictDialog
					path={conflict}
					onReload={() => {
						setConflict(null);
						void onSaved();
					}}
					onCancel={() => setConflict(null)}
				/>
			) : null}
			{confirm ? <GuardrailDialog guardrails={confirm.guardrails} onConfirm={confirm.retry} onCancel={() => setConfirm(null)} /> : null}
		</>
	);

	return { run, runWrites, busy, dialogs };
}

/** "Nov 5, 2026, 10:04" in the browser's locale; the raw value when it does not parse. */
export function formatDate(value: string | null | undefined): string {
	if (!value) {
		return '—';
	}
	// The server stores UTC "Y-m-d H:i:s".
	const date = new Date(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value) ? `${value.replace(' ', 'T')}Z` : value);
	if (Number.isNaN(date.getTime())) {
		return value;
	}
	return date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

export function Pager({ page, perPage, total, onPage, label }: { page: number; perPage: number; total: number; onPage: (page: number) => void; label: string }) {
	const pages = Math.max(1, Math.ceil(total / perPage));
	if (total <= perPage) {
		return null;
	}
	return (
		<nav aria-label={label} className="flex items-center justify-end gap-2 px-4 py-3 text-label">
			<Button variant="outline" size="sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>
				{__('Previous', 'proto-blocks')}
			</Button>
			<span className="tabular-nums text-muted-foreground">
				{sprintf(
					/* translators: 1: current page, 2: number of pages. */
					__('Page %1$d of %2$d', 'proto-blocks'),
					page,
					pages
				)}
			</span>
			<Button variant="outline" size="sm" disabled={page >= pages} onClick={() => onPage(page + 1)}>
				{__('Next', 'proto-blocks')}
			</Button>
		</nav>
	);
}
