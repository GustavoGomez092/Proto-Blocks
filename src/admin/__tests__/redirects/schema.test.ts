import { groupSchema, groupSlugError, isIpOrCidr, regexError, ruleProblems, ruleSchema, ruleWarnings, settingsSchema, slugifyGroup } from '@/admin/redirects/schema';
import { formFromRule, ruleFromForm, SITE_DEFAULTS } from '@/admin/redirects/rule-form';
import type { FileRule } from '@/admin/redirects/types';

const codes = (rule: FileRule, siteHost = 'example.test') => ruleProblems(rule, { siteHost }).map((p) => `${p.path.join('.')}:${p.code}`);

describe('rule checks mirror RuleValidator codes', () => {
	test.each<[string, FileRule, string[]]>([
		['plain redirect', { from: '/a', to: '/b' }, []],
		['absolute target', { from: '/a', to: 'https://other.test/b' }, []],
		['missing source', { to: '/b' }, ['from:missing_from']],
		['404 rule without source', { when: { type: '404' }, to: '/' }, []],
		['relative source', { from: 'a', to: '/b' }, ['from:invalid_from']],
		['other host source', { from: 'https://other.test/a', to: '/b' }, ['from:use_when_server']],
		['same host source', { from: 'https://example.test/a', to: '/b' }, []],
		['invalid regex', { from: '^/(a', regex: true, to: '/b' }, ['from:invalid_regex']],
		['long regex', { from: `^/${'a'.repeat(500)}`, regex: true, to: '/b' }, ['from:regex_too_long']],
		['PCRE named group', { from: '^/(?P<slug>[a-z]+)$', regex: true, to: '/b' }, []],
		['missing target', { from: '/a' }, ['to:missing_target']],
		['javascript target', { from: '/a', to: 'javascript:alert(1)' }, ['to:invalid_target']],
		['pass absolute', { from: '/a', action: 'pass', to: 'https://other.test/' }, ['to:invalid_target']],
		['pass path', { from: '/a', action: 'pass', to: '/wp-json/v1/x' }, []],
		['self redirect', { from: '/a', to: '/a' }, ['to:self_redirect']],
		['regex may target itself', { from: '^/a$', regex: true, to: '/a' }, []],
		['bad redirect code', { from: '/a', to: '/b', code: 404 }, ['code:invalid_code']],
		['bad error code', { from: '/a', action: 'error', code: 301 }, ['code:invalid_code']],
		['error 410', { from: '/a', action: 'error', code: 410 }, []],
		['code on pass', { from: '/a', action: 'pass', to: '/b', code: 301 }, ['code:invalid_code']],
		['else without when', { from: '/a', to: '/b', else: '/c' }, ['else:else_without_when']],
		['else with error', { from: '/a', action: 'error', when: { type: 'login' }, else: '/c' }, ['else:else_not_allowed']],
		['else with when', { from: '/a', to: '/b', when: { type: 'login' }, else: '/c' }, []],
		['role needs value', { from: '/a', to: '/b', when: { type: 'role', value: '' } }, ['when.value:missing_value']],
		['header needs name', { from: '/a', to: '/b', when: { type: 'header', value: 'x' } }, ['when.name:missing_name']],
		['bad referrer regex', { from: '/a', to: '/b', when: { type: 'referrer', value: '(', regex: true } }, ['when.value:invalid_regex']],
		['ip list', { from: '/a', to: '/b', when: { type: 'ip', value: ['10.0.0.0/8', '::1', '2001:db8::/32'] } }, []],
		['bad ip', { from: '/a', to: '/b', when: { type: 'ip', value: ['10.0.0.300'] } }, ['when.value:invalid_ip']],
		['filter name', { from: '/a', to: '/b', when: { type: 'filter', value: 'My Filter' } }, ['when.value:invalid_filter']],
		['bad header', { from: '/a', to: '/b', headers: { Location: '/x' } }, ['headers.Location:invalid_header']],
		['header CRLF', { from: '/a', to: '/b', headers: { 'X-A': 'a\r\nb' } }, ['headers.X-A:invalid_header']],
		['title with markup', { from: '/a', to: '/b', title: '<b>' }, ['title:invalid_label']],
		['bad id', { id: 'r_XYZ', from: '/a', to: '/b' }, ['id:invalid_id']],
	])('%s', (_name, rule, expected) => {
		expect(codes(rule)).toEqual(expected);
	});

	test('unknown keys are schema errors; codes travel in the issue params', () => {
		const result = ruleSchema().safeParse({ from: '/a', to: '/b', target: '/c' });
		expect(result.success).toBe(false);
		const bad = ruleSchema().safeParse({ from: '/a', to: '/a' });
		expect(bad.success).toBe(false);
		expect((bad.error?.issues[0] as unknown as { params: { code: string } }).params.code).toBe('self_redirect');
	});

	test('unanchored regex is a warning only', () => {
		expect(ruleWarnings({ from: 'blog', regex: true, to: '/b' }).map((w) => w.code)).toEqual(['unanchored_regex']);
		expect(ruleWarnings({ from: '^/blog', regex: true, to: '/b' })).toEqual([]);
		expect(codes({ from: 'blog', regex: true, to: '/b' })).toEqual([]);
	});

	test('helpers', () => {
		expect(regexError('^/a$')).toBeNull();
		expect(regexError('a++')).toBeNull();
		expect(isIpOrCidr('192.168.1.1/24')).toBe(true);
		expect(isIpOrCidr('192.168.1.1/33')).toBe(false);
		expect(isIpOrCidr('fe80::1/129')).toBe(false);
		expect(slugifyGroup('Légacy Site!')).toBe('legacy-site');
		expect(groupSlugError('settings')).not.toBeNull();
		expect(groupSlugError('php')).not.toBeNull();
		expect(groupSlugError('legacy', ['legacy'])).not.toBeNull();
		expect(groupSlugError('legacy')).toBeNull();
	});
});

describe('group and settings schemas', () => {
	test('group', () => {
		expect(groupSchema().safeParse({ title: 'A', enabled: true, position: 5, defaults: { query: 'ignore' }, rules: [{ from: '/a', to: '/b' }] }).success).toBe(true);
		expect(groupSchema().safeParse({ title: 'A', rules: [{ from: '/a' }] }).success).toBe(false);
		expect(groupSchema().safeParse({ name: 'A' }).success).toBe(false);
	});

	test('settings ranges and enums', () => {
		expect(settingsSchema.safeParse({ log: { redirects: 90, notFound: -1, ip: 'masked' }, cache: 24, canonical: { www: 'nowww', aliases: ['old.test'] } }).success).toBe(true);
		expect(settingsSchema.safeParse({ log: { redirects: 91 } }).success).toBe(false);
		expect(settingsSchema.safeParse({ cache: 2 }).success).toBe(false);
		expect(settingsSchema.safeParse({ canonical: { aliases: ['https://old.test'] } }).success).toBe(false);
		expect(settingsSchema.safeParse({ headers: { site: { 'Set-Cookie': 'x' } } }).success).toBe(false);
		expect(settingsSchema.safeParse({ monitor: { group: 'settings' } }).success).toBe(false);
		expect(settingsSchema.safeParse({ extra: true }).success).toBe(false);
	});
});

describe('form mapping', () => {
	test('round trip leaves defaults out', () => {
		const rule: FileRule = { id: 'r_00000001', from: '/members', to: '/dashboard', else: '/login', code: 302, when: { type: 'login' }, query: 'pass', ignoreCase: false };
		const values = formFromRule(rule, SITE_DEFAULTS, 'legacy');
		expect(values).toMatchObject({ condition: 'login', code: '302', query: 'pass', ignoreCase: false, ignoreTrailingSlash: true });
		expect(ruleFromForm(values, SITE_DEFAULTS, 'r_00000001')).toEqual(rule);
		expect(ruleFromForm(formFromRule({ from: '/a', to: '/b' }, SITE_DEFAULTS, 'g'), SITE_DEFAULTS, 'r_00000002')).toEqual({ id: 'r_00000002', from: '/a', to: '/b' });
	});

	test('lists, error actions and kept headers', () => {
		const values = { ...formFromRule(null, SITE_DEFAULTS, 'g'), from: '/a', action: 'error' as const, code: '410', to: '/ignored', condition: 'ip' as const, conditionValue: '10.0.0.1\n 10.0.0.0/8 ,::1' };
		expect(ruleFromForm(values, SITE_DEFAULTS, 'r_00000003', { headers: { 'X-A': '1' } })).toEqual({
			id: 'r_00000003',
			from: '/a',
			code: 410,
			action: 'error',
			when: { type: 'ip', value: ['10.0.0.1', '10.0.0.0/8', '::1'] },
			headers: { 'X-A': '1' },
		});
	});
});
