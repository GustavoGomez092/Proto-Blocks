import { useEffect, useId, useState, type FormEvent } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { FolderCogIcon, Trash2Icon } from 'lucide-react';
import { DoubleBadge } from '@/admin/components/content-model/OriginBadge';
import { ConfirmDialog } from '@/admin/components/shell/ConfirmDialog';
import { Button } from '@/admin/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/admin/components/ui/dialog';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { Switch } from '@/admin/components/ui/switch';
import { deleteGroupFile, type GroupWrite, type WriteOutcome } from './api';
import { setGroupField } from './edits';
import { groupSlugError, slugifyGroup } from './schema';
import { groupEditable, groupReadOnlyReason, readOnlyReasonLabel, sortedGroups, writeGuard } from './shared';
import type { Group, RedirectsIndex } from './types';

function GroupRow({
	group,
	readOnly,
	busy,
	onWrite,
	onDelete,
}: {
	group: Group;
	readOnly: boolean;
	busy: boolean;
	onWrite: (write: GroupWrite, success: string) => void;
	onDelete: (group: Group) => void;
}) {
	const titleId = useId();
	const positionId = useId();
	const [title, setTitle] = useState(group.title);
	const [position, setPosition] = useState(String(group.position));
	const editable = groupEditable(group);
	const reason = readOnlyReasonLabel(group);
	const guard = writeGuard(readOnly, !editable || busy);

	useEffect(() => {
		setTitle(group.title);
		setPosition(String(group.position));
	}, [group.title, group.position]);

	const positionNumber = Number(position);
	const positionValid = position.trim() !== '' && Number.isInteger(positionNumber);
	const titleValid = title.trim() !== '' && !/[<>]/.test(title);
	const dirty = title.trim() !== group.title || (positionValid && positionNumber !== group.position);

	const save = (event: FormEvent) => {
		event.preventDefault();
		if (!dirty || !positionValid || !titleValid) {
			return;
		}
		onWrite(
			{
				slug: group.slug,
				expectedHash: group.hash,
				edit: (text) => {
					let out = text;
					if (title.trim() !== group.title) {
						out = setGroupField(out, 'title', title.trim());
					}
					if (positionNumber !== group.position) {
						out = setGroupField(out, 'position', positionNumber);
					}
					return out;
				},
			},
			sprintf(
				/* translators: %s: group title. */
				__('Saved group %s.', 'proto-blocks'),
				title.trim()
			)
		);
	};

	return (
		<li className="grid gap-2 rounded-lg border px-4 py-3">
			<div className="flex flex-wrap items-center justify-between gap-2">
				<code className="font-mono text-micro text-muted-foreground">{group.slug}</code>
				<div className="flex items-center gap-2">
					{group.double ? <DoubleBadge double={group.double} /> : null}
					{groupReadOnlyReason(group) === 'php' ? <span className="text-micro text-muted-foreground">{__('Declared in PHP', 'proto-blocks')}</span> : null}
					<Switch
						checked={group.enabled}
						aria-label={sprintf(
							/* translators: %s: group title. */
							__('Enable group %s', 'proto-blocks'),
							group.title
						)}
						{...guard}
						onCheckedChange={(checked) =>
							onWrite(
								{ slug: group.slug, expectedHash: group.hash, edit: (text) => setGroupField(text, 'enabled', checked) },
								checked ? __('Group enabled.', 'proto-blocks') : __('Group disabled.', 'proto-blocks')
							)
						}
					/>
				</div>
			</div>
			{reason && groupReadOnlyReason(group) !== 'php' ? <p className="text-caption text-muted-foreground">{reason}</p> : null}
			<form onSubmit={save} className="flex flex-wrap items-end gap-2">
				<div className="grid min-w-[180px] flex-1 gap-1">
					<Label htmlFor={titleId} className="text-caption">
						{__('Title', 'proto-blocks')}
					</Label>
					<Input id={titleId} value={title} maxLength={200} aria-invalid={titleValid ? undefined : true} {...guard} onChange={(event) => setTitle(event.target.value)} />
				</div>
				<div className="grid w-24 gap-1">
					<Label htmlFor={positionId} className="text-caption">
						{__('Position', 'proto-blocks')}
					</Label>
					<Input id={positionId} type="number" step={1} value={position} aria-invalid={positionValid ? undefined : true} {...guard} onChange={(event) => setPosition(event.target.value)} />
				</div>
				<Button type="submit" variant="outline" size="sm" {...writeGuard(readOnly, !editable || busy || !dirty || !positionValid || !titleValid)}>
					{__('Save', 'proto-blocks')}
				</Button>
				<Button
					type="button"
					variant="ghost"
					size="icon-sm"
					aria-label={sprintf(
						/* translators: %s: group title. */
						__('Delete group %s', 'proto-blocks'),
						group.title
					)}
					{...guard}
					onClick={() => onDelete(group)}
				>
					<Trash2Icon aria-hidden="true" />
				</Button>
			</form>
		</li>
	);
}

/** Group menu (spec §6): create, rename, enable/disable, position, delete. */
export function GroupMenu({
	index,
	readOnly,
	busy,
	runWrites,
	run,
}: {
	index: RedirectsIndex;
	readOnly: boolean;
	busy: boolean;
	runWrites: (writes: GroupWrite[], success?: string) => Promise<boolean>;
	run: (op: () => Promise<WriteOutcome>, success?: string) => Promise<boolean>;
}) {
	const [open, setOpen] = useState(false);
	const [newTitle, setNewTitle] = useState('');
	const [deleting, setDeleting] = useState<Group | null>(null);
	const newId = useId();
	const taken = index.groups.map((g) => g.slug);
	const slug = slugifyGroup(newTitle);
	const slugError = newTitle.trim() === '' ? null : groupSlugError(slug, taken);

	const create = async (event: FormEvent) => {
		event.preventDefault();
		if (newTitle.trim() === '' || slugError !== null) {
			return;
		}
		const title = newTitle.trim();
		const ok = await runWrites(
			[{ slug, title, edit: (text) => text }],
			sprintf(
				/* translators: %s: group title. */
				__('Created group %s.', 'proto-blocks'),
				title
			)
		);
		if (ok) {
			setNewTitle('');
		}
	};

	return (
		<>
			<Button variant="outline" onClick={() => setOpen(true)}>
				<FolderCogIcon aria-hidden="true" />
				{__('Groups', 'proto-blocks')}
			</Button>
			<Dialog open={open} onOpenChange={setOpen}>
				<DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-[620px]">
					<DialogHeader>
						<DialogTitle>{__('Groups', 'proto-blocks')}</DialogTitle>
						<DialogDescription>{__('Groups run in ascending position, then by slug. A disabled group turns off every rule in it.', 'proto-blocks')}</DialogDescription>
					</DialogHeader>
					<ul className="grid gap-2" aria-label={__('Redirect groups', 'proto-blocks')}>
						{sortedGroups(index).map((group) => (
							<GroupRow key={group.slug} group={group} readOnly={readOnly} busy={busy} onWrite={(write, success) => void runWrites([write], success)} onDelete={setDeleting} />
						))}
					</ul>
					<form onSubmit={create} className="grid gap-1.5 border-t pt-4">
						<Label htmlFor={newId}>{__('New group', 'proto-blocks')}</Label>
						<div className="flex gap-2">
							<Input id={newId} value={newTitle} maxLength={200} placeholder={__('Legacy site', 'proto-blocks')} aria-invalid={slugError ? true : undefined} aria-describedby={`${newId}-slug`} {...writeGuard(readOnly)} disabled={readOnly} onChange={(event) => setNewTitle(event.target.value)} />
							<Button type="submit" {...writeGuard(readOnly, busy || newTitle.trim() === '' || slugError !== null)}>
								{__('Create group', 'proto-blocks')}
							</Button>
						</div>
						<p id={`${newId}-slug`} className={slugError ? 'text-label text-destructive' : 'text-caption text-muted-foreground'}>
							{slugError ?? (slug !== '' ? `proto-redirects/${slug}/redirects.jsonc` : ' ')}
						</p>
					</form>
				</DialogContent>
			</Dialog>
			<ConfirmDialog
				open={deleting !== null}
				destructive
				title={
					deleting
						? sprintf(
								/* translators: 1: group title, 2: number of rules. */
								__('Delete the group %1$s and its %2$d rules?', 'proto-blocks'),
								deleting.title,
								deleting.rules.length
							)
						: ''
				}
				description={deleting ? sprintf(
					/* translators: %s: group slug. */
					__('This deletes proto-redirects/%s/redirects.jsonc from the theme. Git still has it.', 'proto-blocks'),
					deleting.slug
				) : undefined}
				confirmLabel={__('Delete group', 'proto-blocks')}
				onCancel={() => setDeleting(null)}
				onConfirm={() => {
					const group = deleting;
					setDeleting(null);
					if (group) {
						void run(async () => {
							await deleteGroupFile(group.slug);
							return { status: 'saved', path: '', hash: '' };
						}, __('Group deleted.', 'proto-blocks'));
					}
				}}
			/>
		</>
	);
}
