/**
 * Starting points offered by the empty Scripts screen. Client-side presets
 * only: they prefill the create form, and the server validates the result
 * like any other new script.
 */
import { __ } from '@wordpress/i18n';
import { normalizeWhere, type ScriptForm } from './edits';

export interface ScriptTemplate {
	id: string;
	title: string;
	description: string;
	form: () => ScriptForm;
}

const GTM_HEAD = `<!-- Google Tag Manager -->
<script>
(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});
var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;
j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-XXXX');
</script>
`;

const GTM_BODY = `<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-XXXX" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
`;

const base = (title: string, patch: Partial<ScriptForm>): ScriptForm => ({
	title,
	enabled: true,
	priority: 10,
	where: normalizeWhere({}),
	snippets: { head: '', bodyOpen: '', bodyClose: '' },
	...patch,
});

export const SCRIPT_TEMPLATES: ScriptTemplate[] = [
	{
		id: 'gtm',
		title: __('Google Tag Manager', 'proto-blocks'),
		description: __('head + body snippets, replace GTM-XXXX with the container ID', 'proto-blocks'),
		form: () => base(__('Google Tag Manager', 'proto-blocks'), { snippets: { head: GTM_HEAD, bodyOpen: GTM_BODY, bodyClose: '' } }),
	},
	{
		id: 'verification',
		title: __('Site verification meta', 'proto-blocks'),
		description: __('head only, every page', 'proto-blocks'),
		form: () =>
			base(__('Site verification meta', 'proto-blocks'), {
				priority: 20,
				snippets: { head: '<meta name="google-site-verification" content="" />\n', bodyOpen: '', bodyClose: '' },
			}),
	},
	{
		id: 'chat',
		title: __('Chat widget', 'proto-blocks'),
		description: __('footer, logged-out visitors', 'proto-blocks'),
		form: () =>
			base(__('Chat widget', 'proto-blocks'), {
				priority: 50,
				where: normalizeWhere({ loggedIn: false }),
				snippets: { head: '', bodyOpen: '', bodyClose: '<!-- Paste the chat widget embed code here. -->\n' },
			}),
	},
];

/** The template with this id, or null (blank script). */
export function findTemplate(id: string | null): ScriptTemplate | null {
	return SCRIPT_TEMPLATES.find((t) => t.id === id) ?? null;
}
