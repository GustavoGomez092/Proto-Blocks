import { ApiError } from '@/admin/api';
import { groupSchema, groupSlugError, isIpOrCidr, isRelocateUrl, pointer, regexError, ruleFieldErrors, ruleProblems, ruleSchema, ruleWarnings, serverFindings, settingsFieldErrors, settingsSchema, slugifyGroup } from '@/admin/redirects/schema';
import { formFromRule, ruleFromForm, SITE_DEFAULTS } from '@/admin/redirects/rule-form';
import type { FileRule } from '@/admin/redirects/types';

const codes = (rule: FileRule, siteHost = 'example.test') => ruleProblems(rule, { siteHost }).map((p) => `${p.path.join('.')}:${p.code}`);

describe('rule checks mirror RuleValidator codes', () => {
	const long = (n: number) => `/${'a'.repeat(n - 1)}`;
	test.each<[string, FileRule, string[]]>([
		['plain redirect', { from: '/a', to: '/b' }, []],
		['absolute target', { from: '/a', to: 'https://other.test/b' }, []],
		['missing source', { to: '/b' }, ['from:missing_source']],
		['404 rule without source', { when: { type: '404' }, to: '/' }, []],
		['404 rule with a value', { when: { type: '404', value: 'x' }, to: '/' }, ['when.value:invalid_condition']],
		['relative source', { from: 'a', to: '/b' }, ['from:invalid_source']],
		['source with a line break', { from: '/a\nb', to: '/b' }, ['from:invalid_source']],
		['other host source', { from: 'https://other.test/a', to: '/b' }, ['from:use_when_server']],
		['source with userinfo', { from: 'https://user@example.test/a', to: '/b' }, ['from:use_when_server']],
		['same host source', { from: 'https://example.test/a', to: '/b' }, []],
		['invalid regex', { from: '^/(a', regex: true, to: '/b' }, ['from:invalid_regex']],
		['regex at 500', { from: `^${'a'.repeat(499)}`, regex: true, to: '/b' }, []],
		['regex over 500', { from: `^/${'a'.repeat(500)}`, regex: true, to: '/b' }, ['from:invalid_regex']],
		['PCRE named group', { from: '^/(?P<slug>[a-z]+)$', regex: true, to: '/b' }, []],
		['empty source is a schema error', { from: '', to: '/b' }, ['from:schema_invalid']],
		['source at 2000', { from: long(2000), to: '/b' }, []],
		['source over 2000', { from: long(2001), to: '/b' }, ['from:schema_invalid']],
		['target over 2000', { from: '/a', to: long(2001) }, ['to:schema_invalid']],
		['fallback over 2000', { from: '/a', to: '/b', when: { type: 'login' }, else: long(2001) }, ['else:schema_invalid']],
		['missing target', { from: '/a' }, ['to:invalid_target']],
		['javascript target', { from: '/a', to: 'javascript:alert(1)' }, ['to:invalid_target']],
		['protocol-relative target', { from: '/a', to: '//evil.test/' }, ['to:invalid_target']],
		['backslash target', { from: '/a', to: '/\\evil.test/' }, ['to:invalid_target']],
		['target with credentials', { from: '/a', to: 'https://u:p@/x' }, ['to:invalid_target']],
		['pass absolute', { from: '/a', action: 'pass', to: 'https://other.test/' }, ['to:invalid_target']],
		['pass without target', { from: '/a', action: 'pass' }, ['to:invalid_target']],
		['pass path', { from: '/a', action: 'pass', to: '/wp-json/v1/x' }, []],
		['error with a target', { from: '/a', action: 'error', to: '/b' }, ['to:invalid_target']],
		['nothing with a code', { from: '/a', action: 'nothing', code: 404 }, ['code:invalid_code']],
		['self redirect', { from: '/a', to: '/a' }, ['to:self_redirect']],
		['self redirect, case and slash folded', { from: '/About', to: '/about/' }, ['to:self_redirect']],
		['case kept when ignoreCase is off', { from: '/About', to: '/about', ignoreCase: false }, []],
		['self redirect on the fallback', { from: '/a', to: '/b', when: { type: 'login' }, else: '/a' }, ['else:self_redirect']],
		['self redirect through a same-host URL', { from: '/a', to: 'https://example.test/a' }, ['to:self_redirect']],
		['regex may target itself', { from: '^/a$', regex: true, to: '/a' }, []],
		['bad redirect code', { from: '/a', to: '/b', code: 404 }, ['code:invalid_code']],
		['code out of range is a schema error', { from: '/a', to: '/b', code: 99 }, ['code:schema_invalid']],
		['bad error code', { from: '/a', action: 'error', code: 301 }, ['code:invalid_code']],
		['error 410', { from: '/a', action: 'error', code: 410 }, []],
		['code on pass', { from: '/a', action: 'pass', to: '/b', code: 301 }, ['code:invalid_code']],
		['else without when', { from: '/a', to: '/b', else: '/c' }, ['else:invalid_else']],
		['else with error', { from: '/a', action: 'error', when: { type: 'login' }, else: '/c' }, ['else:invalid_else']],
		['else not a URL', { from: '/a', to: '/b', when: { type: 'login' }, else: 'nope' }, ['else:invalid_else']],
		['else with when', { from: '/a', to: '/b', when: { type: 'login' }, else: '/c' }, []],
		['role needs value', { from: '/a', to: '/b', when: { type: 'role', value: '' } }, ['when.value:invalid_condition']],
		['role grammar', { from: '/a', to: '/b', when: { type: 'role', value: 'edit posts' } }, ['when.value:invalid_condition']],
		['header needs name', { from: '/a', to: '/b', when: { type: 'header', value: 'x' } }, ['when.name:invalid_condition']],
		['header name grammar', { from: '/a', to: '/b', when: { type: 'header', name: 'X_Beta', value: 'x' } }, ['when.name:invalid_condition']],
		['cookie name grammar', { from: '/a', to: '/b', when: { type: 'cookie', name: 'a=b', value: 'x' } }, ['when.name:invalid_condition']],
		['empty name is a schema error', { from: '/a', to: '/b', when: { type: 'cookie', name: '', value: 'x' } }, ['when.name:schema_invalid']],
		['name over 100', { from: '/a', to: '/b', when: { type: 'header', name: 'X'.repeat(101), value: 'x' } }, ['when.name:schema_invalid']],
		['name on a non-named condition', { from: '/a', to: '/b', when: { type: 'role', name: 'x', value: 'editor' } }, ['when.name:invalid_condition']],
		['regex on a role', { from: '/a', to: '/b', when: { type: 'role', value: 'editor', regex: true } }, ['when.regex:invalid_condition']],
		['bad referrer regex', { from: '/a', to: '/b', when: { type: 'referrer', value: '(', regex: true } }, ['when.value:invalid_regex']],
		['value at 500', { from: '/a', to: '/b', when: { type: 'agent', value: 'a'.repeat(500) } }, []],
		['value over 500', { from: '/a', to: '/b', when: { type: 'agent', value: 'a'.repeat(501) } }, ['when.value:schema_invalid']],
		['ip list', { from: '/a', to: '/b', when: { type: 'ip', value: ['10.0.0.0/8', '::1', '2001:db8::/32'] } }, []],
		['ip as one string', { from: '/a', to: '/b', when: { type: 'ip', value: '10.0.0.1' } }, []],
		['bad ip', { from: '/a', to: '/b', when: { type: 'ip', value: ['10.0.0.1', '10.0.0.300'] } }, ['when.value.1:invalid_condition']],
		['empty ip list is a schema error', { from: '/a', to: '/b', when: { type: 'ip', value: [] } }, ['when.value:schema_invalid']],
		['ip list over 100', { from: '/a', to: '/b', when: { type: 'ip', value: Array(101).fill('10.0.0.1') } }, ['when.value:schema_invalid']],
		['language tags', { from: '/a', to: '/b', when: { type: 'language', value: ['en', 'pt-BR', '*'] } }, []],
		['bad language tag', { from: '/a', to: '/b', when: { type: 'language', value: ['en_US'] } }, ['when.value.0:invalid_condition']],
		['server host', { from: '/a', to: '/b', when: { type: 'server', value: 'Shop.Example.test' } }, []],
		['server with scheme', { from: '/a', to: '/b', when: { type: 'server', value: 'https://shop.test' } }, ['when.value:invalid_condition']],
		['filter name', { from: '/a', to: '/b', when: { type: 'filter', value: 'My Filter' } }, ['when.value:invalid_condition']],
		['unknown condition type', { from: '/a', to: '/b', when: { type: 'moon' as 'login' } }, ['when.type:schema_invalid']],
		['bad header', { from: '/a', to: '/b', headers: { Location: '/x' } }, ['headers.Location:invalid_header']],
		['header name grammar (rule headers)', { from: '/a', to: '/b', headers: { 'X Beta': '1' } }, ['headers.X Beta:invalid_header']],
		['header CRLF', { from: '/a', to: '/b', headers: { 'X-A': 'a\r\nb' } }, ['headers.X-A:invalid_header']],
		['header value over 1000', { from: '/a', to: '/b', headers: { 'X-A': 'a'.repeat(1001) } }, ['headers.X-A:schema_invalid']],
		['title at 200', { from: '/a', to: '/b', title: 't'.repeat(200) }, []],
		['title over 200', { from: '/a', to: '/b', title: 't'.repeat(201) }, ['title:schema_invalid']],
		['title with markup', { from: '/a', to: '/b', title: '<b>' }, ['title:schema_invalid']],
		['bad id', { id: 'r_XYZ', from: '/a', to: '/b' }, ['id:schema_invalid']],
		['unknown action', { from: '/a', to: '/b', action: 'teleport' as 'url' }, ['action:schema_invalid']],
		['schema errors stop the cross checks', { from: '/a', title: '<b>' }, ['title:schema_invalid']],
	])('%s', (_name, rule, expected) => {
		expect(codes(rule)).toEqual(expected);
	});

	test('group defaults decide self_redirect for rules that inherit them', () => {
		const problems = ruleProblems({ from: '/About', to: '/about' }, { siteHost: 'example.test', defaults: { ignoreCase: false, ignoreTrailingSlash: true, query: 'exact' } });
		expect(problems).toEqual([]);
	});

	test('unknown keys are schema errors; codes travel in the issue params', () => {
		const result = ruleSchema().safeParse({ from: '/a', to: '/b', target: '/c' });
		expect(result.success).toBe(false);
		expect((result.error?.issues[0] as unknown as { params: { code: string } }).params.code).toBe('schema_invalid');
		const bad = ruleSchema().safeParse({ from: '/a', to: '/a' });
		expect(bad.success).toBe(false);
		expect((bad.error?.issues[0] as unknown as { params: { code: string } }).params.code).toBe('self_redirect');
	});

	test('unanchored regex is a warning only', () => {
		expect(ruleWarnings({ from: 'blog', regex: true, to: '/b' }).map((w) => w.code)).toEqual(['unanchored_regex']);
		expect(ruleWarnings({ from: '^/blog', regex: true, to: '/b' })).toEqual([]);
		// An escaped anchor is not an anchor (Patterns::anchored); a broken pattern is an error, not a warning.
		expect(ruleWarnings({ from: 'blog\\$', regex: true, to: '/b' }).map((w) => w.code)).toEqual(['unanchored_regex']);
		expect(ruleWarnings({ from: '(blog', regex: true, to: '/b' })).toEqual([]);
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
		expect(groupSchema().safeParse({ title: '' }).success).toBe(false);
		expect(groupSchema().safeParse({ position: 10001 }).success).toBe(false);
		expect(groupSchema().safeParse({ $schema: '../x.json', position: -10000 }).success).toBe(true);
	});

	test('two rules with the same id are duplicate_rule', () => {
		const result = groupSchema().safeParse({ rules: [{ id: 'r_00000001', from: '/a', to: '/b' }, { id: 'r_00000001', from: '/c', to: '/d' }] });
		expect(result.success).toBe(false);
		const issue = result.error?.issues[0] as unknown as { path: (string | number)[]; params: { code: string } };
		expect(issue.path).toEqual(['rules', 1, 'id']);
		expect(issue.params.code).toBe('duplicate_rule');
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

	test('settings: SettingsValidator codes and the schema limits', () => {
		const code = (data: unknown) => {
			const result = settingsSchema.safeParse(data);
			return result.success ? null : ((result.error.issues[0] as unknown as { params?: { code?: string } }).params?.code ?? 'schema_invalid');
		};
		expect(code({ canonical: { aliases: ['old.test:8080'] } })).toBe('invalid_host');
		expect(code({ canonical: { aliases: ['a.test', 'a.test'] } })).toBe('schema_invalid');
		expect(code({ canonical: { relocate: 'https://new.test/path?x=1' } })).toBe('invalid_url');
		expect(code({ canonical: { relocate: 'https://new.test/path' } })).toBeNull();
		expect(code({ log: { trustedProxies: ['10.0.0.300'] } })).toBe('invalid_ip');
		expect(code({ log: { ipHeaders: ['X-Real-IP', 'X-Real-IP'] } })).toBe('schema_invalid');
		expect(code({ headers: { redirect: { 'X-A': 'a\nb' } } })).toBe('invalid_header');
		expect(code({ headers: { site: { 'X-A': 'a'.repeat(1001) } } })).toBe('schema_invalid');
		expect(code({ monitor: { postTypes: ['Post Type'] } })).toBe('schema_invalid');
		expect(code({ monitor: { associated: 'no-slash' } })).toBe('schema_invalid');
		expect(code({ monitor: { associated: '/amp' } })).toBeNull();
		expect(isRelocateUrl('https://user@new.test/')).toBe(false);
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

describe('a refused save maps back onto the fields', () => {
	const refusal = (errors: unknown) => new ApiError('proto_blocks_schema_invalid', 'The declaration does not match the schema.', 400, { errors });

	test('findings: path, code when present, message (generic when empty); other errors have none', () => {
		expect(
			serverFindings(
				refusal([
					{ path: '/rules/2/from', code: 'invalid_source', message: 'The source must be a path.' },
					{ path: '/rules/2/to', message: '' },
					'junk',
				])
			)
		).toEqual([
			{ path: '/rules/2/from', code: 'invalid_source', message: 'The source must be a path.' },
			{ path: '/rules/2/to', code: undefined, message: 'This value is not valid.' },
		]);
		expect(serverFindings(new ApiError('proto_blocks_conflict', 'x', 409, { errors: [{ path: '/a', message: 'b' }] }))).toEqual([]);
		expect(serverFindings(refusal(undefined))).toEqual([]);
	});

	test('JSON pointers unescape ~1 and ~0', () => {
		expect(pointer('/headers/a~1b~0c')).toEqual(['headers', 'a/b~c']);
		expect(pointer('')).toEqual([]);
	});

	test("the saved rule's paths go to its fields; others go to root with their path", () => {
		const findings = serverFindings(
			refusal([
				{ path: '/rules/3/from', message: 'Bad source.' },
				{ path: '/rules/3/when/value/1', message: 'Not an IP.' },
				{ path: '/rules/3/when/name', message: 'Bad name.' },
				{ path: '/rules/3/when/type', message: 'Bad type.' },
				{ path: '/rules/3/headers/X-A', message: 'Bad header.' },
				{ path: '/rules/3/code', message: 'Bad code.' },
				{ path: '/rules/0/to', message: 'Another rule.' },
				{ path: '/title', message: 'Bad title.' },
				{ path: '', message: 'Whole file.' },
			])
		);
		expect(ruleFieldErrors(findings, 3)).toEqual({
			from: 'Bad source.',
			conditionValue: 'Not an IP.',
			conditionName: 'Bad name.',
			code: 'Bad code.',
			root: 'headers.X-A: Bad header. rules.0.to: Another rule. title: Bad title. Whole file.',
		});
		// Position unknown (the write that failed was not the rule's): everything is general.
		expect(ruleFieldErrors(findings.slice(0, 1), null)).toEqual({ root: 'rules.3.from: Bad source.' });
	});

	test('settings findings go to their form fields', () => {
		const findings = serverFindings(
			refusal([
				{ path: '/canonical/aliases/0', code: 'invalid_host', message: 'Alias.' },
				{ path: '/headers/site/Set-Cookie', code: 'invalid_header', message: 'Header.' },
				{ path: '/log/trustedProxies/1', code: 'invalid_ip', message: 'Proxy.' },
				{ path: '/cache', message: 'Cache.' },
				{ path: '/headers', message: 'Section.' },
			])
		);
		expect(settingsFieldErrors(findings)).toEqual({ 'canonical.aliases': 'Alias.', 'headers.site': 'Header.', 'log.trustedProxies': 'Proxy.', cache: 'Cache.', root: 'headers: Section.' });
	});
});
