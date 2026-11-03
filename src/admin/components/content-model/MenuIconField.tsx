import { forwardRef } from 'react';
import { __ } from '@wordpress/i18n';
import { ImageIcon, TriangleAlertIcon } from 'lucide-react';
import { Input } from '@/admin/components/ui/input';
import { Label } from '@/admin/components/ui/label';

/** Suggestions for the picker; any `dashicons-*` name is accepted. */
const DASHICONS = [
	'admin-post',
	'admin-page',
	'admin-media',
	'admin-links',
	'admin-users',
	'admin-tools',
	'admin-home',
	'archive',
	'awards',
	'book',
	'building',
	'businessman',
	'calendar-alt',
	'camera',
	'cart',
	'clipboard',
	'database',
	'download',
	'edit',
	'email',
	'format-gallery',
	'format-quote',
	'format-video',
	'groups',
	'heart',
	'id',
	'location',
	'lock',
	'megaphone',
	'microphone',
	'money-alt',
	'products',
	'portfolio',
	'star-filled',
	'store',
	'tag',
	'testimonial',
	'tickets-alt',
	'video-alt3',
].map((name) => `dashicons-${name}`);

const DASHICON = /^dashicons-[a-z0-9-]+$/;

/**
 * `menu_icon`: a dashicon name (picked from suggestions or typed), a file in
 * the theme, or an https URL, with a preview. A plain http URL gets a
 * warning: an https admin would block it. Emptying it gives undefined. The
 * ref reaches the input, so react-hook-form's setFocus works.
 */
export const MenuIconField = forwardRef<
	HTMLInputElement,
	{
		id: string;
		value: string | undefined;
		onChange: (next: string | undefined) => void;
		onBlur?: () => void;
		disabled?: boolean;
	}
>(function MenuIconField({ id, value, onChange, onBlur, disabled = false }, ref) {
	const text = value ?? '';
	const insecure = /^http:\/\//i.test(text);
	const listId = `${id}-dashicons`;
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
		<div className="grid gap-2">
			<Label htmlFor={id} className="text-sm font-medium">
				{__('Menu icon', 'proto-blocks')}
			</Label>
			<div className="flex items-center gap-2">
				<div
					className="grid size-9 flex-none place-items-center rounded-md bg-[#1d2327] text-[#f0f0f1]"
					title={__('Menu preview', 'proto-blocks')}
				>
					{preview}
				</div>
				<Input
					ref={ref}
					id={id}
					list={listId}
					value={text}
					disabled={disabled}
					className="font-mono"
					placeholder="dashicons-admin-post"
					autoComplete="off"
					spellCheck={false}
					aria-describedby={insecure ? `${hintId} ${warningId}` : hintId}
					onBlur={onBlur}
					onChange={(event) => onChange(event.target.value === '' ? undefined : event.target.value)}
				/>
				<datalist id={listId}>
					{DASHICONS.map((name) => (
						<option key={name} value={name} />
					))}
				</datalist>
			</div>
			<p id={hintId} className="text-[13px] text-muted-foreground">
				{__(
					'A dashicon name, a file in your theme (assets/icon.svg) or an https URL. SVGs are cleaned of scripts and tinted to match the admin colour scheme.',
					'proto-blocks'
				)}
			</p>
			{insecure ? (
				<p id={warningId} className="flex items-start gap-1.5 text-[13px] text-foreground">
					<TriangleAlertIcon aria-hidden="true" className="mt-0.5 size-4 flex-none text-warning" />
					{__("This URL isn't https, so browsers block it on an https admin. Use an https URL or a theme file.", 'proto-blocks')}
				</p>
			) : null}
		</div>
	);
});
