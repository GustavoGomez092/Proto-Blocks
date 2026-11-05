/**
 * Labels for the redirect vocabulary (spec §1–§3): HTTP codes with their
 * meaning, actions, conditions and query modes.
 */
import { __ } from '@wordpress/i18n';
import type { ConditionType, QueryMode, RuleAction } from './types';

export const REDIRECT_CODES = [301, 302, 303, 307, 308] as const;
export const ERROR_CODES = [400, 401, 403, 404, 410, 418, 451, 500, 501, 502, 503, 504] as const;

/** Codes each action may carry; pass and nothing carry none. */
export function codesFor(action: RuleAction): readonly number[] {
	switch (action) {
		case 'url':
			return REDIRECT_CODES;
		case 'error':
			return ERROR_CODES;
		default:
			return [];
	}
}

/** The code written when none is given (spec §1 `code` default). */
export function defaultCode(action: RuleAction): number | null {
	return action === 'url' ? 301 : action === 'error' ? 404 : null;
}

export function codeMeaning(code: number): string {
	switch (code) {
		case 301:
			return __('Moved permanently', 'proto-blocks');
		case 302:
			return __('Found (temporary)', 'proto-blocks');
		case 303:
			return __('See other', 'proto-blocks');
		case 307:
			return __('Temporary redirect, keeps the method', 'proto-blocks');
		case 308:
			return __('Permanent redirect, keeps the method', 'proto-blocks');
		case 400:
			return __('Bad request', 'proto-blocks');
		case 401:
			return __('Unauthorized', 'proto-blocks');
		case 403:
			return __('Forbidden', 'proto-blocks');
		case 404:
			return __('Not found', 'proto-blocks');
		case 410:
			return __('Gone', 'proto-blocks');
		case 418:
			return __("I'm a teapot", 'proto-blocks');
		case 451:
			return __('Unavailable for legal reasons', 'proto-blocks');
		case 500:
			return __('Internal server error', 'proto-blocks');
		case 501:
			return __('Not implemented', 'proto-blocks');
		case 502:
			return __('Bad gateway', 'proto-blocks');
		case 503:
			return __('Service unavailable', 'proto-blocks');
		case 504:
			return __('Gateway timeout', 'proto-blocks');
		default:
			return __('Not a valid code', 'proto-blocks');
	}
}

export const ACTIONS: RuleAction[] = ['url', 'error', 'pass', 'nothing'];

export function actionLabel(action: RuleAction): string {
	switch (action) {
		case 'url':
			return __('Redirect to URL', 'proto-blocks');
		case 'error':
			return __('Error page', 'proto-blocks');
		case 'pass':
			return __('Pass through (internal rewrite)', 'proto-blocks');
		default:
			return __('Do nothing', 'proto-blocks');
	}
}

export const CONDITIONS: ConditionType[] = ['login', 'role', 'referrer', 'agent', 'header', 'cookie', 'ip', 'language', 'server', 'filter', '404'];

export function conditionLabel(type: ConditionType | 'none'): string {
	switch (type) {
		case 'none':
			return __('Always', 'proto-blocks');
		case 'login':
			return __('Logged in', 'proto-blocks');
		case 'role':
			return __('Role or capability', 'proto-blocks');
		case 'referrer':
			return __('Referrer', 'proto-blocks');
		case 'agent':
			return __('User agent', 'proto-blocks');
		case 'header':
			return __('Request header', 'proto-blocks');
		case 'cookie':
			return __('Cookie', 'proto-blocks');
		case 'ip':
			return __('IP address', 'proto-blocks');
		case 'language':
			return __('Browser language', 'proto-blocks');
		case 'server':
			return __('Server (host)', 'proto-blocks');
		case 'filter':
			return __('PHP filter', 'proto-blocks');
		default:
			return __('Page not found (404)', 'proto-blocks');
	}
}

/** Which inputs a condition reveals in the dialog. */
export function conditionFields(type: ConditionType | 'none'): { value: boolean; list: boolean; name: boolean; regex: boolean } {
	switch (type) {
		case 'role':
		case 'server':
		case 'filter':
			return { value: true, list: false, name: false, regex: false };
		case 'referrer':
		case 'agent':
			return { value: true, list: false, name: false, regex: true };
		case 'header':
		case 'cookie':
			return { value: true, list: false, name: true, regex: true };
		case 'ip':
		case 'language':
			return { value: true, list: true, name: false, regex: false };
		default:
			return { value: false, list: false, name: false, regex: false };
	}
}

export const QUERY_MODES: QueryMode[] = ['exact', 'exactOrder', 'ignore', 'pass'];

export function queryLabel(mode: QueryMode): string {
	switch (mode) {
		case 'exact':
			return __('Exact (any order)', 'proto-blocks');
		case 'exactOrder':
			return __('Exact, same order', 'proto-blocks');
		case 'ignore':
			return __('Ignore extra parameters', 'proto-blocks');
		default:
			return __('Ignore and pass to the target', 'proto-blocks');
	}
}

/** The matcher's per-rule reason codes (GET admin/redirects/test trace); an unknown code is shown as is. */
export function traceReasonLabel(reason: string): string {
	switch (reason) {
		case 'matched':
			return __('Matched', 'proto-blocks');
		case 'else':
			return __('Source matched, condition false: the fallback target fires', 'proto-blocks');
		case 'disabled':
			return __('Rule or group disabled', 'proto-blocks');
		case 'no_match':
			return __('Source does not match', 'proto-blocks');
		case 'no_source':
			return __('No source', 'proto-blocks');
		case 'regex_error':
			return __('The regular expression failed', 'proto-blocks');
		case 'condition_false':
			return __('Condition not met', 'proto-blocks');
		case 'unsafe_target':
			return __('Target refused as unsafe', 'proto-blocks');
		case 'unknown_condition':
			return __('Unknown condition', 'proto-blocks');
		case 'unknown_action':
			return __('Unknown action', 'proto-blocks');
		default:
			return reason;
	}
}

/** The evaluation pass of the test route: `init` (every request) or `404` (WordPress found no page). */
export function stageLabel(stage: string): string {
	switch (stage) {
		case 'init':
			return __('On request', 'proto-blocks');
		case '404':
			return __('As a 404', 'proto-blocks');
		default:
			return stage;
	}
}
