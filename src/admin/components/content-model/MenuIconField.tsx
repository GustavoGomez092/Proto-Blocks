import { forwardRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ImageIcon, SmilePlusIcon, TriangleAlertIcon } from 'lucide-react';
import { DashiconPicker } from '@/admin/components/content-model/DashiconPicker';
import { Popover, PopoverContent, PopoverTrigger } from '@/admin/components/ui/popover';
import { Button } from '@/admin/components/ui/button';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';

const DASHICON = /^dashicons-[a-z0-9-]+$/;

/** Longest value the schema accepts for `menu_icon`. */
const MAX_ICON = 2048;

/** A registered icon short enough to type or edit: a dashicon name or an http(s) URL. */
export function isPrefillableIcon(icon: string): boolean {
	return icon !== '' && icon.length <= MAX_ICON && (DASHICON.test(icon) || /^https?:\/\//i.test(icon));
}

/** "inline SVG (3.3 KB)" for a data: URI, else the path itself. */
function describeIcon(icon: string): string {
	if (/^data:/i.test(icon)) {
		const kb = Math.max(0.1, Math.round((icon.length / 1024) * 10) / 10);
		return sprintf(__('inline SVG (%s KB)', 'proto-blocks'), String(kb));
	}
	return icon;
}

/**
 * `menu_icon`: a dashicon name (picked from the visual picker or typed), a file in
 * the theme, or an https URL, with a preview. A plain http URL gets a
 * warning: an https admin would block it. Emptying it gives undefined. The
 * ref reaches the input, so react-hook-form's setFocus works.
 */
export const MenuIconField = forwardRef<
	HTMLInputElement,
	{
		id: string;
		value: string | undefined;
		/** The icon WordPress registered, when it is not prefilled into the input. */
		registered?: string;
		onChange: (next: string | undefined) => void;
		onBlur?: () => void;
		disabled?: boolean;
	}
>(function MenuIconField({ id, value, registered, onChange, onBlur, disabled = false }, ref) {
	const text = value ?? '';
	const [replacing, setReplacing] = useState(false);
	const summary = !replacing && text === '' && registered && !isPrefillableIcon(registered) ? describeIcon(registered) : null;
	const insecure = /^http:\/\//i.test(text);
	const [open, setOpen] = useState(false);
	const hintId = `${id}-hint`;
	const warningId = `${id}-warning`;

	let preview = null;
	if (DASHICON.test(text)) {
		preview = <span aria-hidden="true" className={`dashicons ${text}`} />;
	} else if (/^https:\/\//i.test(text)) {
		preview = <img src={text} alt="" className="size-5 object-contain" />;
	} else if (text !== '') {
		preview = <ImageIcon aria-hidden="true" className="size-4" />;
	}

	return (
		<div className="grid min-w-0 gap-2 [overflow-wrap:anywhere]">
			<Label htmlFor={id}>
				{__('Menu icon', 'proto-blocks')}
			</Label>
			<div className="flex min-w-0 items-center gap-2">
				<div
					className="grid size-9 flex-none place-items-center rounded-md bg-[#1d2327] text-[#f0f0f1]"
					title={__('Menu preview', 'proto-blocks')}
				>
					{preview}
				</div>
				{summary !== null ? (
					<div className="flex min-w-0 flex-1 items-center gap-2">
						<p id={hintId} className="min-w-0 flex-1 break-all text-body text-muted-foreground">
							{sprintf(__('Registered icon: %s', 'proto-blocks'), summary)}
						</p>
						<Button
							ref={ref as never}
							type="button"
							variant="outline"
							size="sm"
							disabled={disabled}
							onClick={() => setReplacing(true)}
						>
							{__('Replace', 'proto-blocks')}
						</Button>
					</div>
				) : (
				<Input
					ref={ref}
					id={id}
					value={text}
					disabled={disabled}
					className="min-w-0 font-mono"
					placeholder="dashicons-admin-post"
					autoComplete="off"
					spellCheck={false}
					aria-describedby={insecure ? `${hintId} ${warningId}` : hintId}
					onBlur={onBlur}
					onChange={(event) => onChange(event.target.value === '' ? undefined : event.target.value)}
				/>
				)}
				{summary === null ? (
					<Popover open={open} onOpenChange={setOpen}>
						<PopoverTrigger asChild>
							<Button type="button" variant="outline" size="sm" disabled={disabled} className="flex-none">
								<SmilePlusIcon aria-hidden="true" />
								{__('Choose icon', 'proto-blocks')}
							</Button>
						</PopoverTrigger>
						<PopoverContent className="w-[19.5rem]">
							<DashiconPicker
								value={text}
								onSelect={(name) => {
									onChange(name);
									setOpen(false);
								}}
							/>
						</PopoverContent>
					</Popover>
				) : null}
			</div>
			<p id={summary !== null ? `${hintId}-help` : hintId} className="text-label text-muted-foreground">
				{__(
					'A dashicon name, a file in your theme (assets/icon.svg) or an https URL. SVGs are cleaned of scripts and tinted to match the admin colour scheme.',
					'proto-blocks'
				)}
			</p>
			{insecure ? (
				<p id={warningId} className="flex items-start gap-1.5 text-label text-foreground">
					<TriangleAlertIcon aria-hidden="true" className="mt-0.5 size-4 flex-none text-warning" />
					{__("This URL isn't https, so browsers block it on an https admin. Use an https URL or a theme file.", 'proto-blocks')}
				</p>
			) : null}
		</div>
	);
});
