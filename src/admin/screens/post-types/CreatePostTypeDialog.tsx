import { useEffect, useId, useState, type FormEvent } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { toast } from 'sonner';
import { adminGet, adminPost, ApiError } from '@/admin/api';
import { Button } from '@/admin/components/ui/button';
import {
	Dialog,
	DialogContent,
	DialogDescription,
	DialogFooter,
	DialogHeader,
	DialogTitle,
} from '@/admin/components/ui/dialog';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';
import { interpolate } from '@/admin/lib/interpolate';
import type { FileResult } from '@/admin/content-model/types';

const SLUG = /^[a-z][a-z0-9_-]{0,19}$/;

/** "Case Study" → "case-study": what the folder (and so the post type) is called. */
export function slugify(name: string): string {
	return name
		.normalize('NFKD')
		.replace(/[̀-ͯ]/g, '')
		.toLowerCase()
		.replace(/[^a-z0-9_]+/g, '-')
		.replace(/^[^a-z]+/, '')
		.slice(0, 20)
		.replace(/-+$/, '');
}

const UNCOUNTABLE = ['media', 'news', 'series', 'staff', 'sheep', 'fish', 'equipment', 'information', 'species', 'money', 'rice'];
const IRREGULAR: Record<string, string> = {
	person: 'people',
	child: 'children',
	man: 'men',
	woman: 'women',
	foot: 'feet',
	tooth: 'teeth',
	mouse: 'mice',
	goose: 'geese',
	ox: 'oxen',
};
const F_WORDS = ['leaf', 'knife', 'life', 'wife', 'half', 'shelf', 'wolf', 'calf', 'loaf', 'thief'];
const O_WORDS = ['hero', 'potato', 'tomato', 'echo', 'veto'];

/** English plural of the last word, as includes/ContentModel/Inflector.php makes it (a preview only). */
export function pluralize(singular: string): string {
	const text = singular.trim();
	const match = /^(.*?)(\S+)$/su.exec(text);
	if (!match) {
		return text;
	}
	const [, head, word] = match;
	const lower = word.toLowerCase();
	const upper = word.length > 1 && word === word.toUpperCase();
	const suffix = (s: string) => (upper ? s.toUpperCase() : s);
	let plural: string;
	if (UNCOUNTABLE.includes(lower)) {
		plural = word;
	} else if (IRREGULAR[lower]) {
		const irregular = IRREGULAR[lower];
		if (upper) {
			plural = irregular.toUpperCase();
		} else {
			plural = word[0] === word[0].toUpperCase() ? irregular[0].toUpperCase() + irregular.slice(1) : irregular;
		}
	} else if (F_WORDS.includes(lower)) {
		plural = word.replace(/fe?$/i, '') + suffix('ves');
	} else if (/[^aeiou]y$/i.test(word)) {
		plural = word.slice(0, -1) + suffix('ies');
	} else if (/(s|x|z|ch|sh)$/i.test(word) || O_WORDS.includes(lower)) {
		plural = word + suffix('es');
	} else {
		plural = word + suffix('s');
	}
	return head + plural;
}

const code = (text: string) => <code className="font-mono text-[12.5px] break-all">{text}</code>;

/**
 * A1 "Add post type": singular name → live plural, slug and target file.
 * Create GETs the template for the name, writes it unchanged (hash null, so
 * an existing file is never overwritten) and hands the slug to onCreated.
 */
export function CreatePostTypeDialog({
	open,
	taken,
	root = '',
	onOpenChange,
	onCreated,
}: {
	open: boolean;
	/** The folder new declarations go in (createTarget.path), relative to ABSPATH. */
	root?: string;
	/** Slugs already registered. */
	taken: string[];
	onOpenChange: (open: boolean) => void;
	onCreated: (slug: string) => void;
}) {
	const nameId = useId();
	const slugId = useId();
	const slugHelpId = useId();
	const [name, setName] = useState('');
	const [slugText, setSlugText] = useState<string | null>(null);
	const [serverError, setServerError] = useState<{ slug: boolean; message: string } | null>(null);
	const [busy, setBusy] = useState(false);

	useEffect(() => {
		if (open) {
			setName('');
			setSlugText(null);
			setServerError(null);
		}
	}, [open]);

	const slug = slugText ?? slugify(name);
	let slugError: string | null = null;
	if (slug !== '' && !SLUG.test(slug)) {
		slugError = __('Use 1 to 20 lowercase letters, digits, hyphens or underscores, starting with a letter.', 'proto-blocks');
	} else if (taken.includes(slug)) {
		slugError = __('A post type with this slug already exists.', 'proto-blocks');
	} else if (serverError?.slug) {
		slugError = serverError.message;
	}
	const ready = name.trim() !== '' && slug !== '' && slugError === null && !busy;

	const submit = async (event: FormEvent) => {
		event.preventDefault();
		if (!ready) {
			return;
		}
		setBusy(true);
		setServerError(null);
		const path = `admin/schema-files/type/${slug}`;
		try {
			const template = await adminGet<FileResult>(`${path}?name=${encodeURIComponent(name.trim())}`);
			if (template.exists) {
				setServerError({ slug: true, message: __('A declaration file for this slug already exists.', 'proto-blocks') });
				return;
			}
			const result = await adminPost<{ path: string }>(path, { text: template.text, hash: null, acknowledge: [] });
			toast.success(
				sprintf(
					/* translators: %s: path of the declaration file that was written. */
					__('Created %s.', 'proto-blocks'),
					result.path
				)
			);
			onCreated(slug);
		} catch (e) {
			const error = e instanceof ApiError ? e : null;
			const message = error?.message || __('The post type could not be created.', 'proto-blocks');
			setServerError({ slug: /^proto_blocks_(invalid_)?slug/.test(error?.code ?? ''), message });
		} finally {
			setBusy(false);
		}
	};

	return (
		<Dialog open={open} onOpenChange={onOpenChange}>
			<DialogContent>
				<form onSubmit={submit} className="grid gap-4" noValidate>
					<DialogHeader>
						<DialogTitle>{__('Add post type', 'proto-blocks')}</DialogTitle>
						<DialogDescription>
							{__('Type the singular name. The plural, the slug and every admin label are generated from it.', 'proto-blocks')}
						</DialogDescription>
					</DialogHeader>
					<div className="grid gap-2">
						<Label htmlFor={nameId}>{__('Singular name', 'proto-blocks')}</Label>
						<Input
							id={nameId}
							value={name}
							autoComplete="off"
							maxLength={100}
							onChange={(event) => {
								setName(event.target.value);
								setServerError(null);
							}}
						/>
					</div>
					<dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-[13px]">
						<dt className="text-muted-foreground">{__('Plural', 'proto-blocks')}</dt>
						<dd>{name.trim() !== '' ? pluralize(name) : '—'}</dd>
					</dl>
					<div className="grid gap-2">
						<Label htmlFor={slugId}>{__('Slug', 'proto-blocks')}</Label>
						<Input
							id={slugId}
							value={slug}
							className="font-mono"
							autoComplete="off"
							spellCheck={false}
							maxLength={20}
							aria-invalid={slugError ? true : undefined}
							aria-describedby={slugHelpId}
							onChange={(event) => {
								setSlugText(event.target.value);
								setServerError(null);
							}}
						/>
						<div id={slugHelpId} className="grid gap-1 text-[13px]">
							{slugError ? (
								<p className="text-destructive" role="alert">
									{slugError}
								</p>
							) : null}
							{slug !== '' ? (
								<p className="text-muted-foreground">
									{interpolate(
										/* translators: %s: path of the declaration file that will be created. */
										__('Will create %s', 'proto-blocks'),
										code(`${root !== '' ? root.replace(/\/+$/, '') : 'proto-types'}/${slug}/type.jsonc`)
									)}
								</p>
							) : null}
						</div>
					</div>
					{serverError && !serverError.slug ? (
						<p className="text-[13px] text-destructive" role="alert">
							{serverError.message}
						</p>
					) : null}
					<DialogFooter>
						<Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
							{__('Cancel', 'proto-blocks')}
						</Button>
						<Button type="submit" disabled={!ready}>
							{__('Create post type', 'proto-blocks')}
						</Button>
					</DialogFooter>
				</form>
			</DialogContent>
		</Dialog>
	);
}
