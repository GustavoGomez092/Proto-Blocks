/**
 * The gallery's "Add images" picker (lazy chunk `fields-picker-gallery`):
 * the core media frame in multi-select mode. The frame is WordPress's own
 * modal dialog (focus trap, Escape, focus return), so this renders nothing.
 */
import { useEffect, useRef } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { frameSelection, wpMedia, type Attachment } from '../inputs/MediaInputs';

export type GalleryPickerProps = {
	/** The field label, for the frame title. */
	title: string;
	onSelect: ( attachments: Attachment[] ) => void;
	onClose: () => void;
};

export function GalleryPicker( { title, onSelect, onClose }: GalleryPickerProps ) {
	const callbacks = useRef( { onSelect, onClose } );
	callbacks.current = { onSelect, onClose };

	useEffect( () => {
		const media = wpMedia();
		if ( ! media ) {
			callbacks.current.onClose();
			return undefined;
		}
		let live = true;
		let picked = false;
		const frame = media( {
			/* translators: %s: field label */
			title: sprintf( __( 'Add images to %s', 'proto-blocks' ), title ),
			button: { text: __( 'Add to gallery', 'proto-blocks' ) },
			library: { type: 'image' },
			multiple: true,
		} );
		frame.on( 'select', () => {
			picked = true;
			if ( live ) {
				callbacks.current.onSelect( frameSelection( frame, true ) );
			}
		} );
		frame.on( 'close', () => {
			// The frame closes after a select too; only a cancel needs reporting.
			window.setTimeout( () => {
				if ( live && ! picked ) {
					callbacks.current.onClose();
				}
			}, 0 );
		} );
		frame.open();
		return () => {
			live = false;
		};
		// The frame opens once per mount; the parent remounts the picker for each "Add images".
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return null;
}

export default GalleryPicker;
