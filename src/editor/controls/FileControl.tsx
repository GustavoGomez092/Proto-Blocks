/**
 * File control
 *
 * Picks any attachment from the media library, not only an image or a video.
 * `image` and `video` filter the library to their own kind, which leaves no way
 * to choose a CSV, a PDF, a font or a caption track from a control.
 *
 * Stores { id, url, filename, mime } so a template can both link to the file and
 * read it from disk by its id.
 */

import React from 'react';
import { Button, BaseControl } from '@wordpress/components';
import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

export interface FileValue {
	id?: number;
	url?: string;
	filename?: string;
	mime?: string;
}

interface MediaItem {
	id: number;
	url: string;
	filename?: string;
	mime?: string;
	subtype?: string;
	type?: string;
	title?: string;
}

interface FileControlProps {
	label?: string;
	help?: string;
	/** Media library types to offer, e.g. [ 'text/csv' ] or [ 'application' ]. */
	allowedTypes?: string[];
	value?: FileValue;
	onChange: (value: FileValue) => void;
}

export function FileControl({
	label,
	help,
	allowedTypes,
	value,
	onChange,
}: FileControlProps): JSX.Element {
	const current = value && value.url ? value : undefined;

	/* The attachment's own filename where it has one, so a row reads as the file
	   rather than as a URL; the last path segment otherwise. */
	const name =
		current?.filename ||
		(current?.url ? decodeURIComponent(current.url.split('/').pop() || '') : '');

	return (
		<BaseControl __nextHasNoMarginBottom label={label} help={help}>
			<div className="proto-blocks-file-control">
				{current && (
					<p className="proto-blocks-file-control__current">
						<span className="proto-blocks-file-control__name">{name}</span>
						{current.mime && (
							<span className="proto-blocks-file-control__mime">{current.mime}</span>
						)}
					</p>
				)}

				<MediaUploadCheck>
					<MediaUpload
						allowedTypes={allowedTypes}
						value={current?.id}
						onSelect={(media: MediaItem) =>
							onChange({
								id: media.id,
								url: media.url,
								filename: media.filename || media.title,
								mime: media.mime,
							})
						}
						render={({ open }: { open: () => void }) => (
							<Button variant="secondary" onClick={open}>
								{current
									? __('Replace file', 'proto-blocks')
									: __('Select file', 'proto-blocks')}
							</Button>
						)}
					/>
				</MediaUploadCheck>

				{current && (
					<Button
						variant="tertiary"
						isDestructive
						onClick={() => onChange({})}
					>
						{__('Remove', 'proto-blocks')}
					</Button>
				)}
			</div>
		</BaseControl>
	);
}
