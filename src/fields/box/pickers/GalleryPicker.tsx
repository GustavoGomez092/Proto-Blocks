/**
 * The gallery's "Add images" picker (lazy chunk `fields-picker-gallery`):
 * the core media frame in multi-select mode. The frame is WordPress's own
 * modal dialog (focus trap, Escape, focus return), so this renders nothing.
 *
 * One frame per gallery input: it is created on the first open, kept in the
 * input's holder and reused (its selection cleared) on every later open;
 * the input disposes it on unmount.
 */
import { useEffect } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { frameSelection, wpMedia, type Attachment, type GalleryFrameHolder } from '../inputs/MediaInputs';

export type GalleryPickerProps = {
	holder: GalleryFrameHolder;
	/** The field label, for the frame title. */
	title: string;
	onSelect: ( attachments: Attachment[] ) => void;
	onClose: () => void;
};

export function GalleryPicker( { holder, title, onSelect, onClose }: GalleryPickerProps ) {
	// The frame's handlers call whichever picker is mounted now.
	holder.onSelect = onSelect;
	holder.onClose = onClose;

	useEffect( () => {
		const media = wpMedia();
		if ( ! media ) {
			holder.onClose();
			return;
		}
		if ( ! holder.frame ) {
			const frame = media( {
				/* translators: %s: field label */
				title: sprintf( __( 'Add images to %s', 'proto-blocks' ), title ),
				button: { text: __( 'Add to gallery', 'proto-blocks' ) },
				library: { type: 'image' },
				multiple: true,
			} );
			frame.on( 'select', () => {
				holder.picked = true;
				holder.onSelect( frameSelection( frame, true ) );
			} );
			frame.on( 'close', () => {
				// The frame closes after a select too; only a cancel needs reporting.
				window.setTimeout( () => {
					if ( ! holder.picked ) {
						holder.onClose();
					}
				}, 0 );
			} );
			holder.frame = frame;
		} else {
			holder.frame.state().get( 'selection' ).reset?.();
		}
		holder.picked = false;
		holder.frame.open();
		// The frame opens once per mount; the input remounts the picker for each "Add images".
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return null;
}

export default GalleryPicker;
