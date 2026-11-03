/**
 * text, email, url, number, password and textarea. Uncontrolled: typing never
 * re-renders React. Inputs are type="text" with an inputMode instead of
 * email/url/number so the browser's own constraint validation can never block
 * the post form; validation is the box's job.
 */
import { AtSignIcon } from 'lucide-react';
import { Input } from '@/admin/components/ui/input';
import { cn } from '@/admin/lib/utils';
import { useA11yProps, useRegisteredInput, type FieldInputProps } from '../registry';

/** The Input's look (navy border and teal wash on focus), for native textarea and select. */
export const CONTROL_CLASS = cn(
	'w-full min-w-0 rounded-md border border-input bg-background px-3 py-[9px] text-body text-foreground shadow-none transition-[color,box-shadow,border-color] outline-none placeholder:text-faint hover:border-[#b5bfcc]',
	'focus-visible:border-primary focus-visible:ring-[3px] focus-visible:ring-brand-soft focus-visible:outline-none',
	'aria-invalid:border-destructive aria-invalid:ring-[3px] aria-invalid:ring-destructive-soft disabled:cursor-not-allowed disabled:bg-muted disabled:opacity-60'
);

const INPUT_MODES: Record< string, 'email' | 'url' | 'decimal' | undefined > = {
	email: 'email',
	url: 'url',
	number: 'decimal',
};

function text( v: unknown ): string {
	return typeof v === 'string' || typeof v === 'number' ? String( v ) : '';
}

function Affix( { children, side }: { children: string; side: 'left' | 'right' } ) {
	return (
		<span
			className={ cn(
				'flex items-center border border-input bg-subtle px-3 text-label text-muted-foreground',
				side === 'left' ? 'rounded-l-md border-r-0' : 'rounded-r-md border-l-0'
			) }
		>
			{ children }
		</span>
	);
}

export function TextInput( { field, path }: FieldInputProps ) {
	const reg = useRegisteredInput( path );
	const a11y = useA11yProps();
	const prepend = text( field.settings?.prepend );
	const append = text( field.settings?.append );
	const isEmail = field.type === 'email';

	const input = (
		<Input
			{ ...reg }
			{ ...a11y }
			type={ field.type === 'password' ? 'password' : 'text' }
			inputMode={ INPUT_MODES[ field.type ] }
			autoComplete={ field.type === 'password' ? 'new-password' : 'off' }
			spellCheck={ field.type === 'text' ? undefined : false }
			placeholder={ field.placeholder || undefined }
			className={ cn(
				isEmail && 'pl-9',
				field.type === 'number' && 'tabular-nums',
				prepend && 'rounded-l-none',
				append && 'rounded-r-none'
			) }
		/>
	);

	if ( ! prepend && ! append && ! isEmail ) {
		return input;
	}
	return (
		<div className="relative flex">
			{ prepend && <Affix side="left">{ prepend }</Affix> }
			{ isEmail && (
				<AtSignIcon aria-hidden="true" className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-faint" />
			) }
			{ input }
			{ append && <Affix side="right">{ append }</Affix> }
		</div>
	);
}

export function TextareaInput( { field, path }: FieldInputProps ) {
	const reg = useRegisteredInput( path );
	const a11y = useA11yProps();
	const rows = Number( field.settings?.rows ) > 0 ? Number( field.settings?.rows ) : 4;
	return (
		<textarea
			{ ...reg }
			{ ...a11y }
			rows={ rows }
			placeholder={ field.placeholder || undefined }
			className={ cn( CONTROL_CLASS, 'min-h-[88px] resize-y' ) }
		/>
	);
}
