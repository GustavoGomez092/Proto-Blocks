/**
 * wysiwyg: the stored HTML shown in a sandboxed preview (an iframe with no
 * script permission) with an "Edit" button. The editor (lazy chunk
 * `fields-wysiwyg`, core TinyMCE through wp.editor) loads only on that
 * click. Until then a hidden input carries the value, so the field always
 * submits. The client never sanitises: the server's wp_kses_post on save
 * and read is the boundary.
 */
import { Suspense, useId, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { PencilIcon } from 'lucide-react';
import { useController } from 'react-hook-form';
import { Button } from '@/admin/components/ui/button';
import { Skeleton } from '@/admin/components/ui/skeleton';
import { dottedPath, inputName } from '../names';
import { ChunkBoundary, useBox, useFieldA11y, useRetryableLazy, type FieldInputProps } from '../registry';

const loadEditor = () => import( /* webpackChunkName: "fields-wysiwyg" */ '../pickers/WysiwygEditor' );

const PREVIEW_STYLE =
	'body{margin:0;padding:12px;font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1d2327;word-wrap:break-word}' +
	'img,video,iframe{max-width:100%;height:auto}p:first-child{margin-top:0}p:last-child{margin-bottom:0}';

/** The preview document. No base, no scripts: the iframe sandbox blocks them anyway. */
function previewDoc( html: string ): string {
	return `<!doctype html><html><head><meta charset="utf-8"><style>${ PREVIEW_STYLE }</style></head><body>${ html }</body></html>`;
}

/** Fits the iframe to its content (same-origin is allowed for this; scripts are not). */
function fit( frame: HTMLIFrameElement ): void {
	try {
		const height = frame.contentDocument?.documentElement?.scrollHeight ?? 0;
		frame.style.height = `${ Math.min( Math.max( height, 48 ), 320 ) }px`;
	} catch {
		// Leave the default height.
	}
}

export function WysiwygInput( { field, path }: FieldInputProps ) {
	const { control, groupKey } = useBox();
	const a11y = useFieldA11y();
	const { field: ctl } = useController( { control, name: dottedPath( path ) } );
	const value = typeof ctl.value === 'string' ? ctl.value : '';
	const name = inputName( groupKey, path );
	const [ editing, setEditing ] = useState( false );
	// Unique per mounted input and fixed for its life: a path-based id would repeat after a
	// row is removed and another added, and the editor instance is tied to its textarea id.
	const editorId = `pbf-wysiwyg-${ useId().replace( /[^A-Za-z0-9_-]/g, '' ) }`;
	const [ WysiwygEditor, retry ] = useRetryableLazy( loadEditor );

	const hidden = <input type="hidden" name={ name } value={ value } />;

	if ( editing ) {
		return (
			<ChunkBoundary onRetry={ retry } keep={ hidden }>
				<Suspense
					fallback={
						<>
							{ hidden }
							<Skeleton className="h-40 w-full" />
						</>
					}
				>
					<WysiwygEditor
						id={ editorId }
						name={ name }
						initialValue={ value }
						labelId={ a11y.labelId }
						describedBy={ a11y.describedBy }
						invalid={ a11y.invalid }
						required={ a11y.required }
						onChange={ ( v ) => ctl.onChange( v ) }
						onBlur={ ctl.onBlur }
					/>
				</Suspense>
			</ChunkBoundary>
		);
	}

	const label = field.label || field.name;
	return (
		<div className="flex flex-col gap-2">
			{ hidden }
			<div className="overflow-hidden rounded-md border border-input">
				{ value.trim() !== '' ? (
					<iframe
						sandbox="allow-same-origin"
						srcDoc={ previewDoc( value ) }
						title={ sprintf( /* translators: %s: field label */ __( '%s preview', 'proto-blocks' ), label ) }
						onLoad={ ( e ) => fit( e.currentTarget ) }
						className="block h-24 w-full border-0 bg-background"
					/>
				) : (
					<p className="p-3 text-[13px] text-muted-foreground">{ __( 'No content yet.', 'proto-blocks' ) }</p>
				) }
			</div>
			<Button
				type="button"
				variant="outline"
				size="sm"
				className="w-fit"
				id={ a11y.id }
				aria-labelledby={ `${ a11y.labelId } ${ a11y.id }-text` }
				aria-describedby={ a11y.describedBy }
				aria-invalid={ a11y.invalid || undefined }
				onFocus={ preloadEditor }
				onClick={ () => setEditing( true ) }
			>
				<PencilIcon aria-hidden="true" />
				<span id={ `${ a11y.id }-text` }>{ __( 'Edit', 'proto-blocks' ) }</span>
			</Button>
		</div>
	);
}

/** Warms the editor chunk when Edit takes focus; nothing loads before then. */
function preloadEditor(): void {
	loadEditor().catch( () => {} );
}
