/**
 * The rich text editor (lazy chunk `fields-wysiwyg`): a textarea carrying
 * the field's real name, turned into the core editor (TinyMCE + Quicktags +
 * Add Media) through wp.editor when the screen enqueued it
 * (wp_enqueue_editor). Without wp.editor it stays a plain textarea.
 *
 * TinyMCE writes its content back to the textarea on change and on form
 * submit, so the post form saves it; changes are also reported to the box
 * form for validation. The instance is removed on unmount.
 */
import { useEffect, useRef } from 'react';
import { cn } from '@/admin/lib/utils';
import { CONTROL_CLASS } from '../inputs/TextInputs';

type TinyEditor = {
	id: string;
	on: ( events: string, cb: () => void ) => unknown;
	getContent: () => string;
	save?: () => unknown;
	focus?: () => unknown;
};
type TinyManager = {
	get: ( id: string ) => TinyEditor | null | undefined;
	on?: ( event: string, cb: ( e: { editor?: TinyEditor } ) => void ) => unknown;
	off?: ( event: string, cb: ( e: { editor?: TinyEditor } ) => void ) => unknown;
};
type WpEditor = {
	initialize: ( id: string, settings: Record< string, unknown > ) => unknown;
	remove: ( id: string ) => unknown;
	getContent?: ( id: string ) => string;
};

/** The settings the editor is initialised with. */
export const EDITOR_SETTINGS = { tinymce: { wpautop: true }, quicktags: true, mediaButtons: true };

function wpEditor(): WpEditor | undefined {
	const editor = ( window as unknown as { wp?: { editor?: WpEditor } } ).wp?.editor;
	return editor && typeof editor.initialize === 'function' ? editor : undefined;
}

function tinyManager(): TinyManager | undefined {
	const mce = ( window as unknown as { tinymce?: TinyManager } ).tinymce;
	return mce && typeof mce.get === 'function' ? mce : undefined;
}

export type WysiwygEditorProps = {
	id: string;
	name: string;
	initialValue: string;
	labelId: string;
	describedBy: string | undefined;
	invalid: boolean;
	required: boolean;
	onChange: ( value: string ) => void;
	onBlur: () => void;
};

export function WysiwygEditor( { id, name, initialValue, labelId, describedBy, invalid, required, onChange, onBlur }: WysiwygEditorProps ) {
	const area = useRef< HTMLTextAreaElement >( null );
	const handlers = useRef( { onChange, onBlur } );
	handlers.current = { onChange, onBlur };

	useEffect( () => {
		const editor = wpEditor();
		if ( ! editor ) {
			area.current?.focus();
			return undefined;
		}
		editor.initialize( id, EDITOR_SETTINGS );

		const mce = tinyManager();
		const bind = ( ed: TinyEditor ) => {
			ed.on( 'change input undo redo keyup', () => {
				ed.save?.();
				handlers.current.onChange( ed.getContent() );
			} );
			ed.on( 'blur', () => handlers.current.onBlur() );
			ed.focus?.();
		};
		const onAdd = ( e: { editor?: TinyEditor } ) => {
			if ( e.editor?.id === id ) {
				bind( e.editor );
			}
		};
		const existing = mce?.get( id );
		if ( existing ) {
			bind( existing );
		} else if ( mce?.on ) {
			mce.on( 'AddEditor', onAdd );
		} else {
			area.current?.focus();
		}

		return () => {
			mce?.off?.( 'AddEditor', onAdd );
			editor.remove( id );
		};
		// One editor per mount; the id is fixed by the parent.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// wp.editor wraps the textarea in its own markup; the div keeps that inside a node React owns.
	return (
		<div>
			<textarea
				ref={ area }
				id={ id }
				name={ name }
				defaultValue={ initialValue }
				rows={ 10 }
				aria-labelledby={ labelId }
				aria-describedby={ describedBy }
				aria-invalid={ invalid || undefined }
				aria-required={ required || undefined }
				// The text (Quicktags) tab and the fallback edit the textarea itself.
				onChange={ ( e ) => handlers.current.onChange( e.target.value ) }
				onBlur={ () => handlers.current.onBlur() }
				className={ cn( CONTROL_CLASS, 'min-h-40 resize-y' ) }
			/>
		</div>
	);
}

export default WysiwygEditor;
