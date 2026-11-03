import type { BoxPayload } from '@/fields/model/types';

/** A field this user may neither see nor edit; its value is never sent to the page. */
export interface HiddenField {
	name: string;
	label: string;
	/** Why, e.g. 'unfiltered_html'. */
	reason: string;
}

/** One field group: a BoxPayload plus the fields left out of it for this user. */
export type OptionsGroupPayload = BoxPayload & { hidden?: HiddenField[] };

/**
 * The rendered settings page's data, printed by includes/OptionsPages/PageRenderer.php
 * as JSON in <script type="application/json" id="proto-blocks-options-page-data">.
 */
export interface OptionsPageData {
	slug: string;
	title: string;
	/** Untabbed page groups, then standalone groups targeting the page. */
	groups: OptionsGroupPayload[];
	tabs: { title: string; groups: OptionsGroupPayload[] }[];
	/** False when the user lacks unfiltered_html (values are sanitised on save). */
	trusted: boolean;
	/** The declaration editor, for manage_options users only. */
	editUrl: string | null;
	/** Field name => why this user cannot change it (e.g. 'unfiltered_html'). */
	readOnly?: Record<string, string>;
}

/** POST options/<slug> success body. */
export interface OptionsSaveResponse {
	values: Record<string, unknown>;
	notices?: { field: string | null; message: string }[];
}

declare global {
	interface Window {
		/** Test and fallback source; PHP prints the JSON element instead. */
		protoBlocksOptionsPage?: OptionsPageData;
	}
}
